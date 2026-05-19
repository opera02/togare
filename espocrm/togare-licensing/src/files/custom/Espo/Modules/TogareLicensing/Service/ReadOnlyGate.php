<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

use Espo\ORM\EntityManager;
use PDO;

/**
 * Serviço standalone que diz se um módulo está em modo read-only.
 *
 * Lê tabela canônica `module_status` (ORM EspoCRM) via PDO obtido do
 * EntityManager — simples, fácil de mockar em testes, mas DI idiomática.
 * O hook EspoCRM (Hooks/Common/ReadOnlyGate.php) DELEGA pra esta classe.
 *
 * Story 1b.1.1.2-followup: tabela canônica passou de `togare_module_status`
 * (PDO direto, deprecated) → `module_status` (ORM EspoCRM, com soft-delete).
 */
final class ReadOnlyGate
{
    private readonly PDO $pdo;

    public function __construct(EntityManager $entityManager)
    {
        $this->pdo = $entityManager->getPDO();
    }

    public function isBlocked(string $module): bool
    {
        $info = $this->getStatus($module);

        return $info !== null && $info['status'] === 'read_only';
    }

    /**
     * @return array{status: string, expiresAt: ?string}|null
     */
    public function getStatus(string $module): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, expires_at FROM module_status'
            . ' WHERE module_name = :m AND deleted = 0 LIMIT 1',
        );
        $stmt->execute([':m' => $module]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'status' => (string) $row['status'],
            'expiresAt' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
        ];
    }
}
