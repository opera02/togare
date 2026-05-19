<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\DocumentoLogicalPathBuilder;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use PDO;

/**
 * Soft-purge do binário antes do delete do Documento (Decisão #10 Story 5.2).
 *
 * Order = 20 (antes de Audit=50). Se softPurge falhar, throw Conflict aborta
 * o delete — preserva integridade Documento↔Nextcloud.
 *
 * Persiste o tombstoneId em `togare_documento_log` via PDO direto
 * (append-only — pattern V014 PublicacaoAmbigua).
 *
 * @implements BeforeRemove<Documento>
 * @implements AfterRemove<Documento>
 */
final class SoftPurgeDocumentoHook implements BeforeRemove, AfterRemove
{
    public static int $order = 20;

    private const RETENTION_DAYS = 30;

    /** @var array<string, array{storage: PurgeableStorageContract, tombstoneId: string, logicalPath: string}> */
    private static array $pendingPurges = [];

    private static bool $shutdownRegistered = false;

    public function __construct(
        private readonly PurgeableStorageContract $purgeStorage,
        private readonly EntityManager $entityManager,
    ) {
    }

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof Documento) {
            return;
        }

        $documentoId = (string) ($entity->getId() ?? '');
        $uri = (string) ($entity->get('nextcloudUri') ?? '');

        if ($uri === '') {
            TogareLogger::event(
                'warning',
                'documento.soft_purge_missing_uri',
                'SoftPurgeDocumentoHook: Documento sem nextcloudUri — abortando delete',
                ['documentoId' => $documentoId],
            );
            throw new Conflict('Documento sem URI do Nextcloud. Remoção bloqueada para evitar arquivo órfão.');
        }

        try {
            $logicalPath = DocumentoLogicalPathBuilder::extractLogicalPath($uri);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'documento.soft_purge_uri_malformed',
                'SoftPurgeDocumentoHook: URI malformada — abortando delete',
                ['documentoId' => $documentoId, 'uri' => $uri, 'error' => $e->getMessage()],
            );
            throw new \RuntimeException('URI Nextcloud do Documento malformada: ' . $uri);
        }

        $retention = new \DateInterval('P' . self::RETENTION_DAYS . 'D');

        try {
            $tombstoneId = $this->purgeStorage->softPurge($logicalPath, $retention);
            $this->registerPurgeCompensation($documentoId, $tombstoneId, $logicalPath);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'documento.soft_purge_failed',
                'SoftPurgeDocumentoHook: softPurge falhou — abortando delete',
                [
                    'documentoId' => $documentoId,
                    'logicalPath' => $logicalPath,
                    'error' => $e->getMessage(),
                ],
            );
            throw new Conflict('Não foi possível mover o arquivo para a lixeira do Nextcloud agora. Tente novamente em alguns minutos.');
        }

        $this->writeTombstoneLog($documentoId, $tombstoneId, $logicalPath);

        TogareLogger::event(
            'info',
            'documento.soft_purged',
            'SoftPurgeDocumentoHook: Documento movido para tombstone',
            [
                'documentoId' => $documentoId,
                'tombstoneId' => $tombstoneId,
                'logicalPath' => $logicalPath,
                'retentionDays' => self::RETENTION_DAYS,
            ],
        );
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof Documento) {
            return;
        }

        $documentoId = (string) ($entity->getId() ?? '');
        if ($documentoId !== '') {
            unset(self::$pendingPurges[$documentoId]);
        }
    }

    private function registerPurgeCompensation(string $documentoId, string $tombstoneId, string $logicalPath): void
    {
        self::$pendingPurges[$documentoId] = [
            'storage' => $this->purgeStorage,
            'tombstoneId' => $tombstoneId,
            'logicalPath' => $logicalPath,
        ];

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$pendingPurges as $documentoId => $pending) {
                try {
                    $pending['storage']->restoreFromTombstone($pending['tombstoneId']);
                    TogareLogger::event(
                        'warning',
                        'documento.soft_purge_compensated',
                        'SoftPurgeDocumentoHook: tombstone restaurado por delete incompleto',
                        [
                            'documentoId' => $documentoId,
                            'tombstoneId' => $pending['tombstoneId'],
                            'logicalPath' => $pending['logicalPath'],
                        ],
                    );
                } catch (\Throwable $e) {
                    TogareLogger::event(
                        'error',
                        'documento.soft_purge_compensation_failed',
                        'SoftPurgeDocumentoHook: falha ao restaurar tombstone de delete incompleto',
                        [
                            'documentoId' => $documentoId,
                            'tombstoneId' => $pending['tombstoneId'],
                            'logicalPath' => $pending['logicalPath'],
                            'error' => $e->getMessage(),
                        ],
                    );
                }
            }
            self::$pendingPurges = [];
        });
    }

    /**
     * Insere row append-only em `togare_documento_log` (Migration V018).
     * Defensivo \Throwable — falha de log não bloqueia delete (já chegamos aqui
     * com softPurge OK; rollback do delete via throw aqui é desproporcional).
     */
    private function writeTombstoneLog(string $documentoId, string $tombstoneId, string $logicalPath): void
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $stmt = $pdo->prepare(
                'INSERT INTO togare_documento_log (id, event, documento_id, user_id, payload, created_at) '
                . 'VALUES (:id, :event, :documento_id, :user_id, :payload, :created_at)',
            );
            $hardDeleteAt = (new \DateTimeImmutable())->add(new \DateInterval('P' . self::RETENTION_DAYS . 'D'));
            $payload = json_encode([
                'tombstoneId' => $tombstoneId,
                'logicalPath' => $logicalPath,
                'hardDeleteAt' => $hardDeleteAt->format(\DateTimeInterface::ATOM),
                'retentionDays' => self::RETENTION_DAYS,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

            $stmt->execute([
                ':id' => bin2hex(random_bytes(16)),
                ':event' => 'documento.soft_purged',
                ':documento_id' => $documentoId,
                ':user_id' => null,
                ':payload' => $payload,
                ':created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'documento.tombstone_log_failed',
                'SoftPurgeDocumentoHook: falha ao gravar togare_documento_log (não-bloqueante)',
                ['documentoId' => $documentoId, 'tombstoneId' => $tombstoneId, 'error' => $e->getMessage()],
            );
        }
    }
}
