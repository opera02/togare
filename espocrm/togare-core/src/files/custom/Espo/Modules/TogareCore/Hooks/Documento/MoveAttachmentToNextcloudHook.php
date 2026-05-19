<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\DocumentoLogicalPathBuilder;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Move bytes do Attachment temporário pro Nextcloud via FileStorageContract::put().
 *
 * Pegadinha B-NEW-A1 (Story 5.2): EspoCRM 9.x AfterSave roda APÓS commit —
 * exception em AfterSave NÃO faz rollback. Por isso este hook usa BeforeSave
 * order=30: se put() falhar, throw aborta save naturalmente, Documento não é
 * persistido, e o Attachment temporário fica para cleanup job futuro (filtro
 * por role Documento::ATTACHMENT_ROLE ('Attachment')).
 *
 * Order = 30 (depois de Validate=10 e DefaultUploadedBy=15, antes do commit).
 *
 * O cleanup do Attachment (hard-delete da row + unlink do binário em
 * data/upload/) fica em MoveAttachmentToNextcloudHookCleanup AfterSave order=10
 * — só roda se save Documento OK.
 *
 * @implements BeforeSave<Documento>
 */
final class MoveAttachmentToNextcloudHook implements BeforeSave
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
        if (! $entity instanceof Documento) {
            return;
        }

        if (! $entity->isNew()) {
            return;
        }

        $attachmentId = (string) ($entity->get('uploadedAttachmentId') ?? '');
        if ($attachmentId === '') {
            // ValidateDocumentoFieldsHook order=10 já bloqueou — defesa em profundidade.
            return;
        }

        $attachment = $this->entityManager->getEntityById('Attachment', $attachmentId);
        if ($attachment === null) {
            throw new Error('Anexo temporário não encontrado. Faça o upload novamente.');
        }

        // Garante ID do Documento (Espo gera automaticamente em alguns paths,
        // mas defensivo aqui — DocumentoLogicalPathBuilder exige).
        $documentoId = (string) ($entity->getId() ?? '');
        if ($documentoId === '') {
            $documentoId = self::generateEspoId();
            $entity->set('id', $documentoId);
        }

        $start = microtime(true);
        $originalFilename = (string) $attachment->get('name');
        $logicalPath = DocumentoLogicalPathBuilder::build($entity, $originalFilename);

        $bytes = $this->loadAttachmentBytes($attachment);
        $realSize = strlen($bytes);
        $this->assertMeasuredSizeAllowed($realSize, $attachmentId);
        $entity->set('sizeBytes', $realSize);

        try {
            $this->registerUploadCompensation($documentoId, $logicalPath);
            $this->fileStorage->put($logicalPath, $bytes);
        } catch (\Throwable $e) {
            self::compensateUploadNow($documentoId);
            TogareLogger::event(
                'error',
                'documento.upload_failed',
                'MoveAttachmentToNextcloudHook: falha ao gravar binário no Nextcloud',
                [
                    'documentoId' => $documentoId,
                    'logicalPath' => $logicalPath,
                    'attachmentId' => $attachmentId,
                    'error' => $e->getMessage(),
                ],
            );
            // Mensagem pt-BR fixa para o usuário (NextcloudUnavailableException já é pt-BR
            // mas re-lançamos como Error pra Espo retornar 500 com message acionável).
            throw new Error('Não foi possível enviar o arquivo para o Nextcloud agora. Tente novamente em alguns minutos.');
        }

        $entity->set('nextcloudUri', Documento::URI_SCHEME . $logicalPath);

        TogareLogger::event(
            'info',
            'documento.created',
            'MoveAttachmentToNextcloudHook: bytes gravados no Nextcloud',
            [
                'documentoId' => $documentoId,
                'processoId' => (string) ($entity->get('processoId') ?? ''),
                'clienteId' => (string) ($entity->get('clienteId') ?? ''),
                'logicalPath' => $logicalPath,
                'sizeBytes' => $realSize,
                'mimeType' => (string) $entity->get('mimeType'),
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
            ],
        );
    }

    public static function markUploadCommitted(string $documentoId): void
    {
        unset(self::$pendingUploads[$documentoId]);
    }

    private static function compensateUploadNow(string $documentoId): void
    {
        $pending = self::$pendingUploads[$documentoId] ?? null;
        unset(self::$pendingUploads[$documentoId]);

        if ($pending === null) {
            return;
        }

        try {
            $pending['storage']->delete($pending['path']);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'documento.upload_compensation_failed',
                'MoveAttachmentToNextcloudHook: falha ao remover upload parcial',
                ['documentoId' => $documentoId, 'logicalPath' => $pending['path'], 'error' => $e->getMessage()],
            );
        }
    }

    private function registerUploadCompensation(string $documentoId, string $logicalPath): void
    {
        self::$pendingUploads[$documentoId] = [
            'storage' => $this->fileStorage,
            'path' => $logicalPath,
        ];

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$pendingUploads as $documentoId => $pending) {
                try {
                    $pending['storage']->delete($pending['path']);
                    TogareLogger::event(
                        'warning',
                        'documento.upload_compensated',
                        'MoveAttachmentToNextcloudHook: upload removido por save incompleto',
                        ['documentoId' => $documentoId, 'logicalPath' => $pending['path']],
                    );
                } catch (\Throwable $e) {
                    TogareLogger::event(
                        'error',
                        'documento.upload_compensation_failed',
                        'MoveAttachmentToNextcloudHook: falha ao compensar upload incompleto',
                        ['documentoId' => $documentoId, 'logicalPath' => $pending['path'], 'error' => $e->getMessage()],
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
            'documento.size_exceeded',
            'MoveAttachmentToNextcloudHook: tamanho real excedido',
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
        // Tenta via repositório nativo primeiro (forma idiomática Espo 9.x).
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
                'documento.repo_get_contents_fallback',
                'MoveAttachmentToNextcloudHook: getContents falhou; fallback file_get_contents',
                ['error' => $e->getMessage()],
            );
        }

        // Fallback: ler direto do filesystem via convention data/upload/<id>.
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
