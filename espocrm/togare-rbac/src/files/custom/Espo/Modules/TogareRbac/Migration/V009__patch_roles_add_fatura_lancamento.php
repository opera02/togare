<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 6.3 — adiciona scopes `Fatura` e `LancamentoFinanceiro` nas 8 roles
 * operacionais.
 *
 * Pattern: clone literal de V008 (ContratoHonorarios) com loop sobre 2 scopes.
 *
 * Política (Decisão #10 da Story 6.3; PRD L702 — Financeiro vê honorários;
 * Secretária NÃO vê honorários; Marketing/RH-lite/Cliente-portal sem acesso):
 *  - Sócio/Admin: all em ambas entities
 *  - Financeiro: all em ambas (FR24, jornada Simone — Financeiro emite faturas
 *    e registra pagamentos)
 *  - Advogado: read own em ambas (assignedUser = current; populado pelos
 *    DefaultAssignmentHooks — advogado vê faturas dos clientes que atende para
 *    transparência operacional)
 *  - Assistente/Estagiário: read own em ambas (idem advogado, leitura restrita)
 *  - Secretária: no em ambas
 *  - Marketing: no em ambas
 *  - RH-lite: no em ambas
 *  - Cliente-portal: no em ambas (scope.aclPortal=false; nem vê)
 *
 * LEGACY_POLICY preventivo conforme regra A1 do Grupo 2 da review da 6.1:
 *  - Patches workdirs/instalações pré-review que possam ter valor não-canônico
 *    para Fatura/LancamentoFinanceiro, sem sobrescrever customização do admin.
 *
 * Sem mudança em entityDefs/scopes/hooks; só patch declarativo dos 8 roles.
 */
final class V009__patch_roles_add_fatura_lancamento implements MigrationInterface
{
    private const SCOPES = ['Fatura', 'LancamentoFinanceiro'];

    /** @var array<string, mixed> */
    private const POLICY = [
        'Sócio/Admin' => 'all',
        'Financeiro' => 'all',
        'Advogado' => ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
        'Assistente/Estagiário' => ['read' => 'own', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
        'Secretária' => 'no',
        'Marketing' => 'no',
        'RH-lite' => 'no',
        'Cliente-portal' => 'no',
    ];

    /**
     * Estados legados conhecidos pré-canônicos. A migration patcha apenas
     * valores legados específicos; preserva customizações do admin.
     *
     * @var array<string, list<array<string, mixed>|string>>
     */
    private const LEGACY_POLICY = [
        'Financeiro' => [
            ['read' => 'all', 'edit' => 'team', 'create' => 'team', 'delete' => 'no'],
        ],
        'Advogado' => [
            'no',
        ],
        'Assistente/Estagiário' => [
            'no',
        ],
    ];

    public function version(): string
    {
        return 'V009__patch_roles_add_fatura_lancamento';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::SCOPES as $scope) {
            foreach (self::POLICY as $roleName => $level) {
                $this->patchRole($pdo, $roleName, $scope, $level);
            }
        }
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional: remover scope de role existente pode quebrar ACL em produção.
    }

    /**
     * @param array<string, mixed>|string $level
     */
    private function patchRole(PDO $pdo, string $roleName, string $scope, array|string $level): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, data FROM role WHERE name = :name AND deleted = 0',
        );
        $stmt->execute(['name' => $roleName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! \is_array($row)) {
            return;
        }

        $data = \json_decode((string) ($row['data'] ?? '{}'), true);
        if (! \is_array($data)) {
            return;
        }

        $patched = $this->patchData($data, $roleName, $scope, $level);
        if ($patched === $data) {
            return;
        }

        $update = $pdo->prepare(
            'UPDATE role SET data = :data, modified_at = :modified_at WHERE id = :id',
        );
        $update->execute([
            'id' => (string) $row['id'],
            'data' => \json_encode($patched, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'modified_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|string $level
     * @return array<string, mixed>
     */
    private function patchData(array $data, string $roleName, string $scope, array|string $level): array
    {
        $scopeList = $data['scopeList'] ?? [];
        if (! \is_array($scopeList)) {
            return $data;
        }
        if (! \in_array($scope, $scopeList, true)) {
            $scopeList[] = $scope;
            $data['scopeList'] = $scopeList;
        }

        $scopeLevel = $data['scopeLevel'] ?? [];
        if (! \is_array($scopeLevel)) {
            return $data;
        }

        if (
            ! \array_key_exists($scope, $scopeLevel)
            || $this->isLegacyPolicy($roleName, $scopeLevel[$scope])
        ) {
            $scopeLevel[$scope] = $level;
            $data['scopeLevel'] = $scopeLevel;
        }

        return $data;
    }

    /**
     * @param mixed $current
     */
    private function isLegacyPolicy(string $roleName, mixed $current): bool
    {
        foreach (self::LEGACY_POLICY[$roleName] ?? [] as $legacy) {
            if ($current === $legacy) {
                return true;
            }
        }

        return false;
    }
}
