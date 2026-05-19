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
use Espo\Modules\TogareCore\Entities\Documento as DocumentoEntity;
use Espo\Modules\TogareCore\Services\AuditLogService;
use Espo\Modules\TogareCore\Services\DocumentoLogicalPathBuilder;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;
use Espo\Modules\TogareNextcloudBridge\Services\OcsApiClient;
use Espo\ORM\EntityManager;
use RuntimeException;
use Throwable;

/**
 * Stock record controller for Documento + download proxy real (Story 5.3 — substitui o
 * stub HTTP 501 entregue na Story 5.2 Decisão #9).
 *
 * Espo `Record` cobre CRUD vanilla (POST/GET/PUT/DELETE /api/v1/Documento).
 *
 * Action customizada `getActionDownload`:
 *   GET /api/v1/Documento/{id}/action/download
 *
 * Pipeline canônico (Decisão #5 da Story 5.3 — ACL antes de tudo, depois URI parse):
 *
 *   1. id vazio              → 404 pt-BR.
 *   2. Documento não existe  → 404 pt-BR.
 *   3. ACL nega              → 403 pt-BR + audit `documento.download_denied`.
 *   4. nextcloudUri ausente / malformada → 500 pt-BR genérica + audit `documento.download_failed`.
 *   5. Branch (Decisão #3):
 *      - default (TOGARE_DOWNLOAD_USE_PHP_PROXY=false): `emitStreamXAccel` — PHP responde com
 *        `X-Accel-Redirect: /internal-nextcloud/data/<user>/files/togare/<webdav-encoded-path>` +
 *        Content-Type/Content-Disposition; Caddy intercepta e serve bytes diretos do volume
 *        `nextcloud_data:/internal-nextcloud:ro` (Decisão #1).
 *      - fallback (TOGARE_DOWNLOAD_USE_PHP_PROXY=true): `emitStreamPhpProxy` — cURL `CURLOPT_FILE
 *        = php://output` em chunks de 1 MB via `OcsApiClient::streamWebDav` (Decisão #8).
 *        Encerra com `exit` (Decisão #8 / B-NEW-DL3 — Espo Response não streamea binário grande).
 *   6. Audit ANTES do dispatch (Decisão #7 / B-NEW-DL2) — registra tentativa, não conclusão;
 *      audit também grava `download_denied` e `download_failed` nos caminhos negativos.
 *
 * Métodos `protected` são pontos de extensão para testes (override de `shouldUsePhpProxy`,
 * `emitStreamXAccel`, `emitStreamPhpProxy`, `openOutputStream`, `writeStreamHeaders`,
 * `terminateAfterStream`, factories `resolve*`). PHPUnit subclasse o controller e
 * substitui esses métodos sem precisar de mock complexo do InjectableFactory.
 */
class Documento extends Record
{
    /**
     * Prefixo do path interno servido pelo Caddy via X-Accel-Redirect.
     *
     * Lockstep com `docker/caddy/Caddyfile` — matcher `@hasAccelRedirect header
     * X-Accel-Redirect /internal-nextcloud/*` no bloco {$TOGARE_DOMAIN}. Mudar
     * aqui exige mudar lá (Decisão #2 da Story 5.3).
     */
    public const X_ACCEL_PREFIX = '/internal-nextcloud';

    /** Env var que troca branch X-Accel (default) por PHP-proxy fallback. */
    public const ENV_USE_PHP_PROXY = 'TOGARE_DOWNLOAD_USE_PHP_PROXY';

    /** Truncamento do User-Agent no audit log (preserva legibilidade e cabe em context_json). */
    private const USER_AGENT_AUDIT_MAX = 200;

    /** Truncamento da URI/path em logs de erro (não vaza payload grande em context_json). */
    private const URI_LOG_MAX = 200;

    /**
     * @throws NotFound
     * @throws Forbidden
     * @throws Error
     * @throws ServiceUnavailable
     */
    public function getActionDownload(Request $request, Response $response): void
    {
        // Espo route canônico `/:controller/action/:action` passa o id como
        // **query param**, não como route param — vide
        // application/Espo/Resources/routes.json. Aceitar ambos para tolerar
        // o caso em que o frontend nativo emite `?id=` ou `routeParams.id`.
        $documentoId = (string) ($request->getRouteParam('id') ?? '');
        if ($documentoId === '' && \method_exists($request, 'getQueryParam')) {
            $documentoId = (string) ($request->getQueryParam('id') ?? '');
        }
        if ($documentoId === '') {
            throw new NotFound('Documento não informado.');
        }

        $entityManager = $this->resolveEntityManager();
        $entity = $entityManager->getEntityById('Documento', $documentoId);
        if (! $entity instanceof DocumentoEntity) {
            throw new NotFound('Documento não encontrado.');
        }

        $acl = $this->resolveAcl();
        $userId = $this->resolveUserId($acl);
        if (! $acl->checkEntity($entity, 'read')) {
            $this->auditDownloadDenied($documentoId, $userId);
            throw new Forbidden('Sem permissão para baixar este Documento.');
        }

        $uri = (string) ($entity->get('nextcloudUri') ?? '');
        try {
            $logicalPath = $this->resolveValidatedLogicalPath($uri, $documentoId);
        } catch (RuntimeException $e) {
            $this->auditDownloadFailed($documentoId, $userId, 'uri_invalid', $uri, $e);
            throw new Error('Não foi possível localizar o arquivo deste Documento. A equipe técnica foi notificada.');
        }

        $usePhpProxy = $this->shouldUsePhpProxy();
        $branch = $usePhpProxy ? 'php_proxy' : 'x_accel';

        $this->auditDownloaded(
            $entity,
            $branch,
            $userId,
            $this->resolveCorrelationId($request),
            $this->resolveUserAgent($request),
        );

        if ($usePhpProxy) {
            $this->emitStreamPhpProxy($entity, $logicalPath, $documentoId, $userId);
            return; // terminateAfterStream() normalmente chama exit; tests substituem.
        }
        $this->emitStreamXAccel($entity, $logicalPath, $response);
    }

    /** @internal protected para permitir override em tests. */
    protected function shouldUsePhpProxy(): bool
    {
        $env = \getenv(self::ENV_USE_PHP_PROXY);
        return \is_string($env) && \strtolower(\trim($env)) === 'true';
    }

    /** @internal protected para permitir override em tests. */
    protected function emitStreamXAccel(DocumentoEntity $entity, string $logicalPath, Response $response): void
    {
        $user = $this->resolveNextcloudUser();
        $xAccelPath = self::X_ACCEL_PREFIX
            . '/data/' . \rawurlencode($user)
            . '/files/togare/' . $this->encodeInternalPath($logicalPath);

        $filename = $this->safeFilename((string) ($entity->get('filename') ?? ''));
        $mimeType = (string) ($entity->get('mimeType') ?? 'application/octet-stream');

        $response->setStatus(200);
        $response->setHeader('X-Accel-Redirect', $xAccelPath);
        $response->setHeader('Content-Type', $mimeType);
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        // Body intencionalmente vazio — Caddy substitui pelo file_server local.
    }

    /** @internal protected para permitir override em tests. */
    protected function emitStreamPhpProxy(
        DocumentoEntity $entity,
        string $logicalPath,
        string $documentoId,
        ?string $userId,
    ): void {
        $client = $this->resolveNextcloudClient();

        $filename = $this->safeFilename((string) ($entity->get('filename') ?? ''));
        $mimeType = (string) ($entity->get('mimeType') ?? 'application/octet-stream');
        $sizeBytes = (int) ($entity->get('sizeBytes') ?? 0);

        $out = $this->openOutputStream();
        $closed = false;
        $headersWritten = false;
        try {
            $client->streamWebDav(
                $logicalPath,
                $out,
                function () use ($mimeType, $filename, $sizeBytes, &$headersWritten): void {
                    $this->writeStreamHeaders($mimeType, $filename, $sizeBytes);
                    $headersWritten = true;
                },
            );
        } catch (NextcloudUnavailableException $e) {
            $this->safeCloseStream($out);
            $closed = true;
            $this->auditDownloadFailed($documentoId, $userId, 'nextcloud_unavailable', $logicalPath, $e);
            throw new ServiceUnavailable($e->getMessage());
        } catch (Throwable $e) {
            $this->safeCloseStream($out);
            $closed = true;
            $this->auditDownloadFailed($documentoId, $userId, 'stream_failed', $logicalPath, $e);
            throw new Error('Não foi possível baixar o arquivo deste Documento. Tente novamente em alguns minutos.');
        }
        if (! $headersWritten) {
            $this->writeStreamHeaders($mimeType, $filename, $sizeBytes);
        }
        if (! $closed) {
            $this->safeCloseStream($out);
        }
        $this->terminateAfterStream();
    }

    /**
     * @internal protected para permitir override em tests (evita exit interromper PHPUnit).
     *
     * @return resource
     */
    protected function openOutputStream()
    {
        if (\function_exists('ob_get_level') && \ob_get_level() > 0) {
            @\ob_end_clean();
        }
        @\set_time_limit(0);
        $h = \fopen('php://output', 'wb');
        if ($h === false) {
            throw new Error('Falha ao abrir stream de saída para download.');
        }
        return $h;
    }

    /** @internal protected para permitir override em tests. */
    protected function writeStreamHeaders(string $mimeType, string $filename, int $sizeBytes): void
    {
        \header('Content-Type: ' . $mimeType);
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: private, max-age=0, must-revalidate');
        \header('X-Content-Type-Options: nosniff');
        \header('X-Accel-Buffering: no');
    }

    /** @internal protected para permitir override em tests (evita exit interromper PHPUnit). */
    protected function terminateAfterStream(): void
    {
        exit;
    }

    /**
     * @internal protected — factory mockável em tests.
     *
     * `Record` base controller injeta `protected $entityManager` via setter
     * (Di\EntityManagerSetter). Tentar `injectableFactory->create(EntityManager::class)`
     * quebra com "MetadataDataProvider não existe" — pegadinha descoberta no
     * smoke F1 da Story 5.3.
     */
    protected function resolveEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * @internal protected — factory mockável em tests.
     *
     * `Record` base controller injeta `protected $acl` via setter. Mesma
     * pegadinha do EntityManager — usar a property do parent é o caminho.
     */
    protected function resolveAcl(): Acl
    {
        return $this->acl;
    }

    /**
     * @internal protected — factory mockável em tests.
     *
     * Resolve via classe concreta `OcsApiClient` para evitar a pegadinha do
     * `InjectableFactory::createInternal` com interfaces (vide togare-core
     * `Binding.php` comentário). O retorno é tipado pelo contrato pra permitir
     * mock de `NextcloudClientContract` em tests sem instanciar OcsApiClient.
     */
    protected function resolveNextcloudClient(): NextcloudClientContract
    {
        return $this->injectableFactory->create(OcsApiClient::class);
    }

    /** @internal protected — factory mockável em tests. */
    protected function resolveAuditLog(): AuditLogContract
    {
        return $this->injectableFactory->create(AuditLogService::class);
    }

    /** @param resource|mixed $h */
    private function safeCloseStream($h): void
    {
        if (\is_resource($h)) {
            @\fclose($h);
        }
    }

    private function resolveValidatedLogicalPath(string $uri, string $documentoId): string
    {
        if ($uri === '') {
            throw new RuntimeException('URI vazia.');
        }

        $parsed = DocumentoLogicalPathBuilder::parseFromUri($uri);
        if ($parsed['documentoId'] !== $documentoId) {
            throw new RuntimeException('URI aponta para outro Documento.');
        }

        $logicalPath = \trim(DocumentoLogicalPathBuilder::extractLogicalPath($uri), '/');
        if ($logicalPath === '') {
            throw new RuntimeException('Logical path vazio.');
        }

        foreach (\explode('/', $logicalPath) as $segment) {
            $this->assertSafeLogicalPathSegment($segment, $logicalPath);
        }

        return $logicalPath;
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

    /**
     * Sanitização defensiva do filename antes de inserir em Content-Disposition.
     * Filename já é sanitizada na Story 5.2 (Decisão #7 — só [a-zA-Z0-9._-]) mas
     * defesa em profundidade contra aspas duplas / CR/LF que quebrariam o header.
     */
    private function safeFilename(string $filename): string
    {
        if ($filename === '') {
            return DocumentoEntity::FILENAME_FALLBACK;
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
        DocumentoEntity $entity,
        string $branch,
        ?string $userId,
        ?string $correlationId,
        ?string $userAgent,
    ): void {
        $documentoId = (string) ($entity->getId() ?? '');
        $context = [
            'documentoId' => $documentoId,
            'processoId' => $entity->get('processoId'),
            'clienteId' => $entity->get('clienteId'),
            'prazoId' => $entity->get('prazoId'),
            'sizeBytes' => $entity->get('sizeBytes'),
            'mimeType' => $entity->get('mimeType'),
            'branch' => $branch,
            'userId' => $userId,
            'correlationId' => $correlationId,
            'userAgent' => $userAgent,
        ];
        try {
            TogareLogger::event(
                'info',
                'documento.downloaded',
                'Documento::getActionDownload OK (' . $branch . ')',
                $context,
            );
        } catch (Throwable) {
            // logger nunca pode bloquear download
        }
        $this->safeAudit('documento.downloaded', $documentoId, $context);
    }

    private function auditDownloadDenied(string $documentoId, ?string $userId): void
    {
        $context = [
            'documentoId' => $documentoId,
            'userId' => $userId,
            'reason' => 'acl_denied',
        ];
        try {
            TogareLogger::event(
                'warning',
                'documento.download_denied',
                'Documento::getActionDownload ACL denied',
                $context,
            );
        } catch (Throwable) {
        }
        $this->safeAudit('documento.download_denied', $documentoId, $context);
    }

    private function auditDownloadFailed(
        string $documentoId,
        ?string $userId,
        string $reason,
        ?string $uri,
        ?Throwable $cause,
    ): void {
        $context = [
            'documentoId' => $documentoId,
            'userId' => $userId,
            'reason' => $reason,
            'uri' => $uri !== null ? \mb_substr($uri, 0, self::URI_LOG_MAX) : null,
            'cause' => $cause === null ? null : \mb_substr($cause->getMessage(), 0, 500),
        ];
        try {
            TogareLogger::event(
                'error',
                'documento.download_failed',
                'Documento::getActionDownload failed: ' . $reason,
                $context,
            );
        } catch (Throwable) {
        }
        $this->safeAudit('documento.download_failed', $documentoId, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function safeAudit(string $event, string $entityId, array $context): void
    {
        try {
            $audit = $this->resolveAuditLog();
            $audit->log($event, 'Documento', $entityId, $context);
        } catch (Throwable) {
            // Audit nunca pode bloquear o flow (regra FR37 + design AuditLogService).
        }
    }
}
