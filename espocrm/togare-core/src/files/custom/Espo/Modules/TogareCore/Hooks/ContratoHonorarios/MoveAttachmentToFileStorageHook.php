<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\ContratoLogicalPathBuilder;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Move bytes do Attachment temporário para o storage via
 * `FileStorageContract::put()` + persiste URI via `buildUri()` AGNÓSTICO
 * (Story 6.1 — Decisão #2).
 *
 * **Esta é a primeira entity Togare a consumir `buildUri()` desde D0**:
 *  - bridge instalado → `nextcloud://clientes/<id>/contratos/<contratoId>-<file>`
 *  - bridge ausente   → `local://clientes/<id>/contratos/<contratoId>-<file>`
 *
 * Pegadinha B-NEW-A1 (Story 5.2): EspoCRM 9.x AfterSave roda APÓS commit —
 * exception em AfterSave NÃO faz rollback. Por isso este hook usa BeforeSave
 * order=30: se put() falhar, throw aborta save naturalmente, ContratoHonorarios
 * não é persistido, e o Attachment temporário fica para cleanup job futuro.
 *
 * Order = 30 (depois de Validate=10 e DefaultUploadedBy=15, antes do commit).
 *
 * Cleanup do Attachment fica em MoveAttachmentCleanupHook AfterSave order=10 —
 * só roda se save ContratoHonorarios OK.
 *
 * @implements BeforeSave<ContratoHonorarios>
 */
final class MoveAttachmentToFileStorageHook implements BeforeSave
{
    public static int $order = 30;

    /** @var array<string, array{storage: FileStorageContract, path: string}> */
    private static array $pendingUploads = [];

    private static bool $shutdownRegistered = false;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly FileStorageContract $fileStorage,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof ContratoHonorarios) {
            return;
        }

        if (! $entity->isNew()) {
            return;
        }

        $attachmentId = (string) ($entity->get('uploadedAttachmentId') ?? '');
        if ($attachmentId === '') {
            // ValidateContratoFieldsHook order=10 já bloqueou — defesa em profundidade.
            return;
        }

        $attachment = $this->entityManager->getEntityById('Attachment', $attachmentId);
        if ($attachment === null) {
            throw new Error('Anexo temporário não encontrado. Faça o upload novamente.');
        }

        // Garante ID do ContratoHonorarios (Espo gera automaticamente em alguns paths,
        // mas defensivo aqui — ContratoLogicalPathBuilder exige).
        $contratoId = (string) ($entity->getId() ?? '');
        if ($contratoId === '') {
            $contratoId = self::generateEspoId();
            $entity->set('id', $contratoId);
        }

        $start = microtime(true);
        $originalFilename = (string) $attachment->get('name');
        $logicalPath = ContratoLogicalPathBuilder::build($entity, $originalFilename);

        $bytes = $this->loadAttachmentBytes($attachment);
        $realSize = strlen($bytes);
        $this->assertMeasuredSizeAllowed($realSize, $attachmentId);
        $entity->set('sizeBytes', $realSize);

        try {
            $this->registerUploadCompensation($contratoId, $logicalPath);
            $this->fileStorage->put($logicalPath, $bytes);
        } catch (\Throwable $e) {
            self::compensateUploadNow($contratoId);
            TogareLogger::event(
                'error',
                'contrato.upload_failed',
                'MoveAttachmentToFileStorageHook: falha ao gravar binário no storage',
                [
                    'contratoId' => $contratoId,
                    'logicalPath' => $logicalPath,
                    'attachmentId' => $attachmentId,
                    'error' => $e->getMessage(),
                ],
            );
            throw new Error('Não foi possível enviar o arquivo do contrato agora. Tente novamente em alguns minutos.');
        }

        // **Decisão #2 da Story 6.1**: URI persistida via buildUri() agnóstico.
        // - bridge instalado → 'nextcloud://...'
        // - bridge ausente   → 'local://...'
        // Caller (SoftPurgeContratoHook, Controller download) usa
        // ContratoLogicalPathBuilder::extractLogicalPath que tolera ambos.
        $entity->set('fileStorageUri', $this->fileStorage->buildUri($logicalPath));

        TogareLogger::event(
            'info',
            'contrato.created',
            'MoveAttachmentToFileStorageHook: bytes gravados no storage',
            [
                'contratoId' => $contratoId,
                'clienteId' => (string) ($entity->get('clienteId') ?? ''),
                'logicalPath' => $logicalPath,
                'sizeBytes' => $realSize,
                'mimeType' => (string) $entity->get('mimeType'),
                'modalidade' => (string) $entity->get('modalidade'),
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ],
        );
    }

    public static function markUploadCommitted(string $contratoId): void
    {
        unset(self::$pendingUploads[$contratoId]);
    }

    private static function compensateUploadNow(string $contratoId): void
    {
        $pending = self::$pendingUploads[$contratoId] ?? null;
        unset(self::$pendingUploads[$contratoId]);

        if ($pending === null) {
            return;
        }

        try {
            $pending['storage']->delete($pending['path']);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'contrato.upload_compensation_failed',
                'MoveAttachmentToFileStorageHook: falha ao remover upload parcial',
                ['contratoId' => $contratoId, 'logicalPath' => $pending['path'], 'error' => $e->getMessage()],
            );
        }
    }

    private function registerUploadCompensation(string $contratoId, string $logicalPath): void
    {
        self::$pendingUploads[$contratoId] = [
            'storage' => $this->fileStorage,
            'path' => $logicalPath,
        ];

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$pendingUploads as $contratoId => $pending) {
                try {
                    $pending['storage']->delete($pending['path']);
                    TogareLogger::event(
                        'warning',
                        'contrato.upload_compensated',
                        'MoveAttachmentToFileStorageHook: upload removido por save incompleto',
                        ['contratoId' => $contratoId, 'logicalPath' => $pending['path']],
                    );
                } catch (\Throwable $e) {
                    TogareLogger::event(
                        'error',
                        'contrato.upload_compensation_failed',
                        'MoveAttachmentToFileStorageHook: falha ao compensar upload incompleto',
                        ['contratoId' => $contratoId, 'logicalPath' => $pending['path'], 'error' => $e->getMessage()],
                    );
                }
            }
            self::$pendingUploads = [];
        });
    }

    private function assertMeasuredSizeAllowed(int $realSize, string $attachmentId): void
    {
        if ($realSize <= 0) {
            throw BadRequest::createWithBody(
                'Arquivo vazio ou com tamanho inválido. Faça o upload novamente.',
                json_encode([
                    'reason' => 'size_invalid',
                    'message' => 'Arquivo vazio ou com tamanho inválido. Faça o upload novamente.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        $maxMb = $this->resolveMaxSizeMb();
        $maxBytes = $maxMb * 1024 * 1024;
        if ($realSize <= $maxBytes) {
            return;
        }

        $sizeMb = (int) round($realSize / (1024 * 1024));
        TogareLogger::event(
            'warning',
            'contrato.size_exceeded',
            'MoveAttachmentToFileStorageHook: tamanho real excedido',
            [
                'sizeBytes' => $realSize,
                'sizeMb' => $sizeMb,
                'limitMb' => $maxMb,
                'attachmentId' => $attachmentId,
            ],
        );
        throw BadRequest::createWithBody(
            sprintf('Arquivo muito grande: %dMB. Limite: %dMB.', $sizeMb, $maxMb),
            json_encode([
                'reason' => 'size_exceeded',
                'sizeMb' => $sizeMb,
                'limitMb' => $maxMb,
                'message' => sprintf('Arquivo muito grande: %dMB. Limite: %dMB.', $sizeMb, $maxMb),
            ], JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    private function resolveMaxSizeMb(): int
    {
        $envVar = getenv('TOGARE_MAX_DOCUMENT_SIZE_MB');
        if ($envVar === false || $envVar === '') {
            return 200;
        }
        $parsed = (int) $envVar;
        return $parsed > 0 ? $parsed : 200;
    }

    private function loadAttachmentBytes(Entity $attachment): string
    {
        try {
            $repo = $this->entityManager->getRepository('Attachment');
            if (method_exists($repo, 'getContents')) {
                /** @phpstan-ignore-next-line — Espo 9.x adiciona getContents em runtime. */
                $contents = $repo->getContents($attachment);
                if (is_string($contents) && $contents !== '') {
                    return $contents;
                }
            }
        } catch (\Throwable $e) {
            TogareLogger::event(
                'debug',
                'contrato.repo_get_contents_fallback',
                'MoveAttachmentToFileStorageHook: getContents falhou; fallback file_get_contents',
                ['error' => $e->getMessage()],
            );
        }

        $attachmentId = (string) $attachment->getId();
        $candidates = [
            'data/upload/' . $attachmentId,
            '/var/www/html/data/upload/' . $attachmentId,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if ($contents !== false) {
                    return $contents;
                }
            }
        }

        throw new Error('Não foi possível ler o conteúdo do anexo temporário.');
    }

    /**
     * Gera ID 17 chars alfanuméricos (formato Espo 9.x ORM Util::generateId).
     */
    private static function generateEspoId(): string
    {
        return substr(bin2hex(random_bytes(9)), 0, 17);
    }
}
