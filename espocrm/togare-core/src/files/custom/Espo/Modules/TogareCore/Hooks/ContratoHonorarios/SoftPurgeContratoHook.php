<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\ContratoLogicalPathBuilder;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;

/**
 * Soft-purge do binário antes do delete do ContratoHonorarios (Story 6.1 — Decisão #5).
 *
 * Order = 20 (antes de Audit=50). Se softPurge falhar, throw Conflict aborta
 * o delete — preserva integridade ContratoHonorarios↔storage.
 *
 * Persiste o tombstoneId em `togare_contrato_honorarios_log` (Migration V020)
 * via PDO direto (append-only — pattern V018 togare_documento_log).
 *
 * Pattern literal de SoftPurgeDocumentoHook, com 2 diferenças:
 *  - Tabela auxiliar `togare_contrato_honorarios_log` (não `togare_documento_log`)
 *  - Extrai logical path via ContratoLogicalPathBuilder (tolera ambos
 *    `nextcloud://` e `local://` — Decisão #2 da spec)
 *
 * @implements BeforeRemove<ContratoHonorarios>
 * @implements AfterRemove<ContratoHonorarios>
 */
final class SoftPurgeContratoHook implements BeforeRemove, AfterRemove
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
        if (! $entity instanceof ContratoHonorarios) {
            return;
        }

        $contratoId = (string) ($entity->getId() ?? '');
        $uri = (string) ($entity->get('fileStorageUri') ?? '');

        if ($uri === '') {
            TogareLogger::event(
                'warning',
                'contrato.soft_purge_missing_uri',
                'SoftPurgeContratoHook: ContratoHonorarios sem fileStorageUri — abortando delete',
                ['contratoId' => $contratoId],
            );
            throw new Conflict('Contrato sem URI de arquivo. Remoção bloqueada para evitar arquivo órfão.');
        }

        try {
            $parsed = ContratoLogicalPathBuilder::parseFromUri($uri);
            if (($parsed['contratoId'] ?? '') !== $contratoId) {
                throw new \RuntimeException('URI aponta para outro ContratoHonorarios.');
            }
            $scheme = (string) $parsed['scheme'];
            $logicalPath = ContratoLogicalPathBuilder::extractLogicalPath($uri);
            $this->assertStorageMatchesPersistedScheme($scheme, $logicalPath);
        } catch (Conflict $e) {
            throw $e;
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'contrato.soft_purge_uri_malformed',
                'SoftPurgeContratoHook: URI malformada — abortando delete',
                ['contratoId' => $contratoId, 'uri' => $uri, 'error' => $e->getMessage()],
            );
            throw new Conflict('URI do storage do Contrato inválida. Remoção bloqueada para evitar purge incorreto.');
        }

        $retention = new \DateInterval('P' . self::RETENTION_DAYS . 'D');

        try {
            $tombstoneId = $this->purgeStorage->softPurge($logicalPath, $retention);
            $this->registerPurgeCompensation($contratoId, $tombstoneId, $logicalPath);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'contrato.soft_purge_failed',
                'SoftPurgeContratoHook: softPurge falhou — abortando delete',
                [
                    'contratoId' => $contratoId,
                    'logicalPath' => $logicalPath,
                    'error' => $e->getMessage(),
                ],
            );
            throw new Conflict('Não foi possível mover o arquivo do contrato para a lixeira agora. Tente novamente em alguns minutos.');
        }

        try {
            $this->writeTombstoneLog($contratoId, $tombstoneId, $logicalPath);
        } catch (\Throwable $e) {
            self::compensatePurgeNow($contratoId);
            TogareLogger::event(
                'error',
                'contrato.tombstone_log_failed',
                'SoftPurgeContratoHook: falha ao gravar tombstone; purge compensado e delete abortado',
                ['contratoId' => $contratoId, 'tombstoneId' => $tombstoneId, 'error' => $e->getMessage()],
            );
            throw new Conflict('Não foi possível registrar a lixeira do contrato. Remoção cancelada.');
        }

        TogareLogger::event(
            'info',
            'contrato.soft_purged',
            'SoftPurgeContratoHook: Contrato movido para tombstone',
            [
                'contratoId' => $contratoId,
                'tombstoneId' => $tombstoneId,
                'logicalPath' => $logicalPath,
                'retentionDays' => self::RETENTION_DAYS,
            ],
        );
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        if (! $entity instanceof ContratoHonorarios) {
            return;
        }

        $contratoId = (string) ($entity->getId() ?? '');
        if ($contratoId !== '') {
            unset(self::$pendingPurges[$contratoId]);
        }
    }

    private function registerPurgeCompensation(string $contratoId, string $tombstoneId, string $logicalPath): void
    {
        self::$pendingPurges[$contratoId] = [
            'storage' => $this->purgeStorage,
            'tombstoneId' => $tombstoneId,
            'logicalPath' => $logicalPath,
        ];

        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            foreach (self::$pendingPurges as $contratoId => $pending) {
                try {
                    $pending['storage']->restoreFromTombstone($pending['tombstoneId']);
                    TogareLogger::event(
                        'warning',
                        'contrato.soft_purge_compensated',
                        'SoftPurgeContratoHook: tombstone restaurado por delete incompleto',
                        [
                            'contratoId' => $contratoId,
                            'tombstoneId' => $pending['tombstoneId'],
                            'logicalPath' => $pending['logicalPath'],
                        ],
                    );
                } catch (\Throwable $e) {
                    TogareLogger::event(
                        'error',
                        'contrato.soft_purge_compensation_failed',
                        'SoftPurgeContratoHook: falha ao restaurar tombstone de delete incompleto',
                        [
                            'contratoId' => $contratoId,
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

    private static function compensatePurgeNow(string $contratoId): void
    {
        $pending = self::$pendingPurges[$contratoId] ?? null;
        unset(self::$pendingPurges[$contratoId]);

        if ($pending === null) {
            return;
        }

        try {
            $pending['storage']->restoreFromTombstone($pending['tombstoneId']);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'contrato.soft_purge_compensation_failed',
                'SoftPurgeContratoHook: falha ao restaurar tombstone após erro',
                [
                    'contratoId' => $contratoId,
                    'tombstoneId' => $pending['tombstoneId'],
                    'logicalPath' => $pending['logicalPath'],
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    private function assertStorageMatchesPersistedScheme(string $scheme, string $logicalPath): void
    {
        $actualUri = $this->purgeStorage->buildUri($logicalPath);
        if (\str_starts_with($actualUri, $scheme . '://')) {
            return;
        }

        TogareLogger::event(
            'error',
            'contrato.soft_purge_scheme_mismatch',
            'SoftPurgeContratoHook: storage atual não corresponde ao scheme persistido',
            [
                'expectedScheme' => $scheme,
                'actualUri' => $actualUri,
                'logicalPath' => $logicalPath,
            ],
        );
        throw new Conflict('Storage atual não corresponde ao arquivo persistido. Remoção bloqueada.');
    }

    /**
     * Insere row append-only em `togare_contrato_honorarios_log` (Migration V020).
     * Falha de log bloqueia delete: sem tombstone persistido, o hard-delete/restore
     * futuro perde o signal append-only.
     */
    private function writeTombstoneLog(string $contratoId, string $tombstoneId, string $logicalPath): void
    {
        $pdo = $this->entityManager->getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO togare_contrato_honorarios_log (id, event, contrato_id, user_id, payload, created_at) '
            . 'VALUES (:id, :event, :contrato_id, :user_id, :payload, :created_at)',
        );
        if ($stmt === false) {
            throw new \RuntimeException('PDO::prepare retornou false ao gravar tombstone.');
        }

        $hardDeleteAt = (new \DateTimeImmutable())->add(new \DateInterval('P' . self::RETENTION_DAYS . 'D'));
        $payload = json_encode([
            'tombstoneId' => $tombstoneId,
            'logicalPath' => $logicalPath,
            'hardDeleteAt' => $hardDeleteAt->format(\DateTimeInterface::ATOM),
            'retentionDays' => self::RETENTION_DAYS,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        $ok = $stmt->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':event' => 'contrato.soft_purged',
            ':contrato_id' => $contratoId,
            ':user_id' => null,
            ':payload' => $payload,
            ':created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        if ($ok === false) {
            throw new \RuntimeException('PDOStatement::execute retornou false ao gravar tombstone.');
        }
    }
}
