<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios as ContratoHonorariosEntity;
use Espo\Modules\TogareCore\Services\AuditLogService;
use Espo\Modules\TogareCore\Services\ContratoLogicalPathBuilder;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use RuntimeException;
use Throwable;

/**
 * Stock record controller for ContratoHonorarios + download proxy (Story 6.1 — T6).
 *
 * Espo `Record` cobre CRUD vanilla (POST/GET/PUT/DELETE /api/v1/ContratoHonorarios).
 *
 * Action customizada `getActionDownload`:
 *   GET /api/v1/ContratoHonorarios/{id}/action/download
 *   OU GET /api/v1/ContratoHonorarios/action/download?id=<id>
 *
 * Dev Decision D6.1.1 (deviation da Decisão #6 da spec): a spec assumiu existência
 * de `DocumentoDownloadService` para reusar via composição. Realidade: a lógica
 * de download da Story 5.3 está INLINE em `Controllers/Documento.php` (não em
 * service separado). Refactor pra extrair seria alto risco em código maduro.
 *
 * Pragmatic adoption: este Controller DUPLICA a lógica de X-Accel-Redirect do
 * Documento Controller (~50 linhas) + adiciona branch novo para LocalDisk
 * (`local://` scheme via `readfile()` direto do TOGARE_LOCAL_STORAGE_ROOT).
 *
 * Extração futura para trait/service se uma 3ª entity precisar de download
 * (Story 6.3 Fatura pode reusar pattern via composição se necessário).
 *
 * Branches de download (detectados pelo scheme da URI persistida em fileStorageUri):
 *  - `nextcloud://...` (bridge instalado, piloto m4-m6 default) → X-Accel-Redirect
 *    via Caddy mount `/internal-nextcloud` (pattern Documento 5.3).
 *  - `local://...` (bridge ausente, fallback) → `readfile()` direto do
 *    `$TOGARE_LOCAL_STORAGE_ROOT/<logicalPath>` (cheap p/ PDFs <200MB).
 */
class ContratoHonorarios extends Record
{
    /**
     * Prefixo do path interno servido pelo Caddy via X-Accel-Redirect.
     * Lockstep com `docker/caddy/Caddyfile` + `Controllers/Documento.php::X_ACCEL_PREFIX`.
     */
    public const X_ACCEL_PREFIX = '/internal-nextcloud';

    /** Truncamento do User-Agent no audit log. */
    private const USER_AGENT_AUDIT_MAX = 200;

    /** Truncamento da URI/path em logs de erro. */
    private const URI_LOG_MAX = 200;

    /**
     * POST /api/v1/ContratoHonorarios/action/hasContratoVigente
     * Body JSON: { "clienteId": "<id>", "processoId": "<id>" (opcional) }
     * Response: { "hasContratoVigente": bool }
     *
     * Usado pelo GateBanner (Story 6.2) para verificar, no frontend, se o
     * Cliente selecionado no form "Emitir Fatura" possui contrato vigente
     * antes de habilitar o submit. Fail-open: erro → frontend omite banner.
     * Backend enforça via ValidateFaturaFieldsHook (defesa em profundidade).
     *
     * ACL: qualquer role autenticada com scope read em ContratoHonorarios
     * pode chamar (EspoCRM aplica scope ACL antes do action).
     *
     * @return array{hasContratoVigente: bool}
     * @throws \Espo\Core\Exceptions\BadRequest quando clienteId ausente/vazio
     */
    public function postActionHasContratoVigente(Request $request, Response $response): array
    {
        $body = $request->getParsedBody();

        $clienteId = '';
        $processoId = null;

        if (\is_object($body)) {
            $clienteId = isset($body->clienteId) ? (string) $body->clienteId : '';
            $processoId = isset($body->processoId) && $body->processoId !== '' && $body->processoId !== null
                ? (string) $body->processoId
                : null;
        } elseif (\is_array($body)) {
            $clienteId = isset($body['clienteId']) ? (string) $body['clienteId'] : '';
            $processoId = isset($body['processoId']) && $body['processoId'] !== '' && $body['processoId'] !== null
                ? (string) $body['processoId']
                : null;
        }

        if ($clienteId === '') {
            throw new \Espo\Core\Exceptions\BadRequest('clienteId é obrigatório.');
        }

        $lookup = $this->injectableFactory->create(
            \Espo\Modules\TogareCore\Services\ContratoHonorariosLookupService::class,
        );

        return ['hasContratoVigente' => $lookup->hasContratoVigente($clienteId, $processoId)];
    }

    /**
     * @throws NotFound
     * @throws Forbidden
     * @throws Error
     * @throws ServiceUnavailable
     */
    public function getActionDownload(Request $request, Response $response): void
    {
        $contratoId = (string) ($request->getRouteParam('id') ?? '');
        if ($contratoId === '' && \method_exists($request, 'getQueryParam')) {
            $contratoId = (string) ($request->getQueryParam('id') ?? '');
        }
        if ($contratoId === '') {
            throw new NotFound('Contrato não informado.');
        }

        $entityManager = $this->resolveEntityManager();
        $entity = $entityManager->getEntityById('ContratoHonorarios', $contratoId);
        if (! $entity instanceof ContratoHonorariosEntity) {
            throw new NotFound('Contrato não encontrado.');
        }

        $acl = $this->resolveAcl();
        $userId = $this->resolveUserId($acl);
        if (! $acl->checkEntity($entity, 'read')) {
            $this->auditDownloadDenied($contratoId, $userId);
            throw new Forbidden('Sem permissão para baixar este Contrato.');
        }

        $uri = (string) ($entity->get('fileStorageUri') ?? '');
        try {
            $parsed = $this->resolveValidatedUri($uri, $contratoId);
        } catch (RuntimeException $e) {
            $this->auditDownloadFailed($contratoId, $userId, 'uri_invalid', $uri, $e);
            throw new Error('Não foi possível localizar o arquivo deste Contrato. A equipe técnica foi notificada.');
        }

        [$scheme, $logicalPath] = $parsed;

        $this->auditDownloaded(
            $entity,
            $scheme,
            $userId,
            $this->resolveCorrelationId($request),
            $this->resolveUserAgent($request),
        );

        if ($scheme === 'nextcloud') {
            $this->emitStreamXAccel($entity, $logicalPath, $response);
            return;
        }

        // local:// scheme → readfile() direto do volume LocalDisk.
        $this->emitStreamLocalDisk($entity, $logicalPath, $contratoId, $userId);
    }

    /** @internal protected para permitir override em tests. */
    protected function emitStreamXAccel(ContratoHonorariosEntity $entity, string $logicalPath, Response $response): void
    {
        $user = $this->resolveNextcloudUser();
        $xAccelPath = self::X_ACCEL_PREFIX
            . '/data/' . \rawurlencode($user)
            . '/files/togare/' . $this->encodeInternalPath($logicalPath);

        $filename = $this->safeFilename((string) ($entity->get('filename') ?? ''));
        $mimeType = (string) ($entity->get('mimeType') ?? 'application/pdf');

        $response->setStatus(200);
        $response->setHeader('X-Accel-Redirect', $xAccelPath);
        $response->setHeader('Content-Type', $mimeType);
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        // Body intencionalmente vazio — Caddy substitui pelo file_server local.
    }

    /** @internal protected para permitir override em tests. */
    protected function emitStreamLocalDisk(
        ContratoHonorariosEntity $entity,
        string $logicalPath,
        string $contratoId,
        ?string $userId,
    ): void {
        $rootPath = $this->resolveLocalStorageRoot();
        $absolutePath = $rootPath . '/' . $logicalPath;

        if (! \is_file($absolutePath)) {
            $this->auditDownloadFailed(
                $contratoId,
                $userId,
                'file_missing',
                $logicalPath,
                new RuntimeException('Arquivo não encontrado em ' . $absolutePath),
            );
            throw new NotFound('Arquivo do contrato não encontrado.');
        }

        // Defesa contra path traversal: confirma que realpath está dentro do root.
        $realPath = \realpath($absolutePath);
        $realRoot = \realpath($rootPath);
        if ($realPath === false || $realRoot === false || ! \str_starts_with($realPath, $realRoot . \DIRECTORY_SEPARATOR)) {
            $this->auditDownloadFailed(
                $contratoId,
                $userId,
                'path_traversal_blocked',
                $logicalPath,
                new RuntimeException('Realpath inválido: ' . ($realPath ?: 'false')),
            );
            throw new Error('Arquivo inacessível por restrição de segurança.');
        }

        $filename = $this->safeFilename((string) ($entity->get('filename') ?? ''));
        $mimeType = (string) ($entity->get('mimeType') ?? 'application/pdf');
        $sizeBytes = (int) ($entity->get('sizeBytes') ?? \filesize($realPath) ?: 0);

        if (\function_exists('ob_get_level') && \ob_get_level() > 0) {
            @\ob_end_clean();
        }
        @\set_time_limit(0);
        \header('Content-Type: ' . $mimeType);
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Content-Length: ' . $sizeBytes);
        \header('Cache-Control: private, max-age=0, must-revalidate');
        \header('X-Content-Type-Options: nosniff');
        \header('X-Accel-Buffering: no');
        \readfile($realPath);

        $this->terminateAfterStream();
    }

    /** @internal protected para permitir override em tests. */
    protected function terminateAfterStream(): void
    {
        exit;
    }

    /** @internal protected — factory mockável em tests. */
    protected function resolveEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /** @internal protected — factory mockável em tests. */
    protected function resolveAcl(): Acl
    {
        return $this->acl;
    }

    /** @internal protected — factory mockável em tests. */
    protected function resolveAuditLog(): AuditLogContract
    {
        return $this->injectableFactory->create(AuditLogService::class);
    }

    /**
     * Resolve URI + validate. Retorna [scheme, logicalPath].
     *
     * @return array{0: string, 1: string}
     */
    private function resolveValidatedUri(string $uri, string $contratoId): array
    {
        if ($uri === '') {
            throw new RuntimeException('URI vazia.');
        }

        $parsed = ContratoLogicalPathBuilder::parseFromUri($uri);
        if ($parsed['contratoId'] !== $contratoId) {
            throw new RuntimeException('URI aponta para outro Contrato.');
        }

        $logicalPath = \trim(ContratoLogicalPathBuilder::extractLogicalPath($uri), '/');
        if ($logicalPath === '') {
            throw new RuntimeException('Logical path vazio.');
        }

        foreach (\explode('/', $logicalPath) as $segment) {
            $this->assertSafeLogicalPathSegment($segment, $logicalPath);
        }

        $scheme = $parsed['scheme'];
        if ($scheme !== 'nextcloud' && $scheme !== 'local') {
            throw new RuntimeException('Scheme inválido: ' . $scheme);
        }

        return [$scheme, $logicalPath];
    }

    private function assertSafeLogicalPathSegment(string $segment, string $logicalPath): void
    {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('Logical path contém segmento inseguro: ' . $logicalPath);
        }

        if (\preg_match('/[\x00-\x1F\x7F\\\\%]/', $segment) === 1) {
            throw new RuntimeException('Logical path contém caractere inseguro: ' . $logicalPath);
        }

        if (\preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
            throw new RuntimeException('Logical path contém caractere fora da allowlist: ' . $logicalPath);
        }
    }

    private function encodeInternalPath(string $logicalPath): string
    {
        $segments = \explode('/', $logicalPath);
        $encoded = \array_map(
            static fn (string $seg): string => \rawurlencode($seg),
            $segments,
        );
        return \implode('/', $encoded);
    }

    private function safeFilename(string $filename): string
    {
        if ($filename === '') {
            return ContratoHonorariosEntity::FILENAME_FALLBACK;
        }
        return \str_replace(['"', "\r", "\n"], '_', $filename);
    }

    private function resolveNextcloudUser(): string
    {
        $env = \getenv('TOGARE_NEXTCLOUD_USER');
        if (\is_string($env) && $env !== '') {
            return $env;
        }
        $env = \getenv('NEXTCLOUD_ADMIN_USER');
        if (\is_string($env) && $env !== '') {
            return $env;
        }
        throw new Error(
            'Configuração ausente: TOGARE_NEXTCLOUD_USER ou NEXTCLOUD_ADMIN_USER deve estar definida.',
        );
    }

    private function resolveLocalStorageRoot(): string
    {
        $env = \getenv('TOGARE_LOCAL_STORAGE_ROOT');
        if (\is_string($env) && $env !== '') {
            return $env;
        }
        return '/var/togare/local-storage';
    }

    private function resolveUserId(Acl $acl): ?string
    {
        if (! \method_exists($acl, 'getUser')) {
            return null;
        }
        try {
            $user = $acl->getUser();
        } catch (Throwable) {
            return null;
        }
        if ($user === null) {
            return null;
        }
        $id = $user->getId();
        return ($id === null || $id === '') ? null : (string) $id;
    }

    private function resolveCorrelationId(Request $request): ?string
    {
        if (! \method_exists($request, 'getHeader')) {
            return null;
        }
        try {
            $h = $request->getHeader('X-Togare-Correlation-Id');
        } catch (Throwable) {
            return null;
        }
        if (! \is_string($h) || $h === '') {
            return null;
        }
        return \mb_substr($h, 0, 64);
    }

    private function resolveUserAgent(Request $request): ?string
    {
        if (! \method_exists($request, 'getHeader')) {
            return null;
        }
        try {
            $h = $request->getHeader('User-Agent');
        } catch (Throwable) {
            return null;
        }
        if (! \is_string($h) || $h === '') {
            return null;
        }
        return \mb_substr($h, 0, self::USER_AGENT_AUDIT_MAX);
    }

    private function auditDownloaded(
        ContratoHonorariosEntity $entity,
        string $branch,
        ?string $userId,
        ?string $correlationId,
        ?string $userAgent,
    ): void {
        $contratoId = (string) ($entity->getId() ?? '');
        $context = [
            'contratoId' => $contratoId,
            'clienteId' => $entity->get('clienteId'),
            'sizeBytes' => $entity->get('sizeBytes'),
            'mimeType' => $entity->get('mimeType'),
            'modalidade' => $entity->get('modalidade'),
            'branch' => $branch,
            'userId' => $userId,
            'correlationId' => $correlationId,
            'userAgent' => $userAgent,
        ];
        try {
            TogareLogger::event(
                'info',
                'contrato.downloaded',
                'ContratoHonorarios::getActionDownload OK (' . $branch . ')',
                $context,
            );
        } catch (Throwable) {
        }
        $this->safeAudit('contrato.downloaded', $contratoId, $context);
    }

    private function auditDownloadDenied(string $contratoId, ?string $userId): void
    {
        $context = [
            'contratoId' => $contratoId,
            'userId' => $userId,
            'reason' => 'acl_denied',
        ];
        try {
            TogareLogger::event(
                'warning',
                'contrato.download_denied',
                'ContratoHonorarios::getActionDownload ACL denied',
                $context,
            );
        } catch (Throwable) {
        }
        $this->safeAudit('contrato.download_denied', $contratoId, $context);
    }

    private function auditDownloadFailed(
        string $contratoId,
        ?string $userId,
        string $reason,
        ?string $uri,
        ?Throwable $cause,
    ): void {
        $context = [
            'contratoId' => $contratoId,
            'userId' => $userId,
            'reason' => $reason,
            'uri' => $uri !== null ? \mb_substr($uri, 0, self::URI_LOG_MAX) : null,
            'cause' => $cause === null ? null : \mb_substr($cause->getMessage(), 0, 500),
        ];
        try {
            TogareLogger::event(
                'error',
                'contrato.download_failed',
                'ContratoHonorarios::getActionDownload failed: ' . $reason,
                $context,
            );
        } catch (Throwable) {
        }
        $this->safeAudit('contrato.download_failed', $contratoId, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function safeAudit(string $event, string $entityId, array $context): void
    {
        try {
            $audit = $this->resolveAuditLog();
            $audit->log($event, 'ContratoHonorarios', $entityId, $context);
        } catch (Throwable) {
            // Audit nunca pode bloquear o flow (regra FR37).
        }
    }
}
