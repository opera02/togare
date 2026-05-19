<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareLicensing\Events\LicenseStatusChangedEvent;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Lógica de revalidação. Recebe EntityManager e cacheia o PDO no boot
 * (Story 1b.1.1.2-followup) — DI idiomática EspoCRM. O RevalidateLicensesJob
 * é um adapter fino que delega aqui.
 *
 * Garantias NFR20:
 *   - Só atualiza module_status (status, last_validated_at, outcome).
 *   - Nenhum DELETE em qualquer tabela.
 *   - Nenhum acesso a tabelas dos módulos premium.
 *   - Falha em 1 módulo não bloqueia os outros.
 *
 * @return list<string> nomes dos módulos transitados
 */
final class LicenseRevalidator
{
    private readonly PDO $pdo;

    public function __construct(
        EntityManager $entityManager,
        private readonly EventBusContract $eventBus,
    ) {
        $this->pdo = $entityManager->getPDO();
    }

    /**
     * @return list<string>
     */
    public function revalidate(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $nowSql = $now->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare("
            SELECT id, module_name
            FROM module_status
            WHERE deleted = 0
              AND status = 'active'
              AND expires_at IS NOT NULL
              AND expires_at < :now
        ");
        $stmt->execute([':now' => $nowSql]);
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($expired === []) {
            TogareLogger::event('debug', 'licensing.revalidate.noop', 'Revalidação diária: nada a fazer', []);

            return [];
        }

        $transitioned = [];

        foreach ($expired as $row) {
            try {
                $this->markReadOnly((string) $row['id'], $nowSql);

                $this->eventBus->dispatch(new LicenseStatusChangedEvent(
                    module: (string) $row['module_name'],
                    oldStatus: 'active',
                    newStatus: 'read_only',
                    reason: 'expired',
                    occurredAt: $now,
                ));

                $transitioned[] = (string) $row['module_name'];
            } catch (Throwable $e) {
                TogareLogger::event('error', 'licensing.revalidate.module_failed', 'Falha ao revalidar módulo', [
                    'module' => (string) $row['module_name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        TogareLogger::event('info', 'licensing.key.expired', 'Módulos transitados para read-only por expiração', [
            'modules' => $transitioned,
            'count' => \count($transitioned),
        ]);

        return $transitioned;
    }

    private function markReadOnly(string $id, string $nowSql): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE module_status
            SET status = :status,
                last_validated_at = :now,
                last_validation_outcome = :outcome
            WHERE id = :id
        ');
        $stmt->execute([
            ':status' => 'read_only',
            ':now' => $nowSql,
            ':outcome' => 'expired',
            ':id' => $id,
        ]);
    }
}
