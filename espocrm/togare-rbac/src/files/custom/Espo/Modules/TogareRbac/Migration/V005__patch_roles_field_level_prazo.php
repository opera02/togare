<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 4a.3.1 — adiciona `data.fieldLevel.Prazo` nas 4 roles operacionais
 * com a política definida em Dev Notes §3 da story:
 *
 *   Sócio/Admin    → yes em todos os 6 campos novos (descricao, prioridade,
 *                    tipoPrazo, motivoReagendamento, cliente, parteContraria)
 *   Advogado       → yes em todos os 6 (advogado opera os Prazos)
 *   Assistente     → yes em todos os 6 (suporte ao advogado)
 *   Secretária     → read em todos os 6 (read-only alinhado scope.no/team)
 *   Financeiro / Marketing / RH-lite / Cliente-portal → ausente
 *                    (scope.Prazo='no' já bloqueia)
 *
 * **Por que esta migration existe** (mesmo com seeds JSON já atualizados):
 * Em instalações onde togare-rbac 0.8.x já rodou, os 8 seeds foram INSERTados
 * sem `fieldLevel.Prazo`. RoleSeeder do AfterInstall.php é idempotente
 * (`SELECT id WHERE name=:name AND deleted=0` → skip se existe), portanto
 * NÃO atualiza `fieldLevel` das roles existentes ao re-instalar 0.9.0. Esta
 * migration garante o patch no upgrade.
 *
 * **Idempotente:**
 *  - Se `fieldLevel.Prazo.<campo>` já existe → preserva (admin pode ter
 *    customizado em Admin → Roles; fail-closed mais seguro).
 *  - Se `fieldLevel.Prazo` não existe ou está vazio → adiciona conforme
 *    política da role.
 *
 * **down() é no-op intencional** — pattern V003/V004.
 *
 * Pattern espelha V004__patch_roles_add_prazo (Story 4a.3) — idêntica
 * estrutura de `patchRole()` + `patchData()`, mas tocando `data.fieldLevel`
 * em vez de `data.scopeLevel/scopeList`.
 */
final class V005__patch_roles_field_level_prazo implements MigrationInterface
{
    /** @var array<string, array<string, string>|null> */
    private const POLICY = [
        'Sócio/Admin' => [
            'descricao' => 'yes',
            'prioridade' => 'yes',
            'tipoPrazo' => 'yes',
            'motivoReagendamento' => 'yes',
            'cliente' => 'yes',
            'parteContraria' => 'yes',
        ],
        'Advogado' => [
            'descricao' => 'yes',
            'prioridade' => 'yes',
            'tipoPrazo' => 'yes',
            'motivoReagendamento' => 'yes',
            'cliente' => 'yes',
            'parteContraria' => 'yes',
        ],
        'Assistente/Estagiário' => [
            'descricao' => 'yes',
            'prioridade' => 'yes',
            'tipoPrazo' => 'yes',
            'motivoReagendamento' => 'yes',
            'cliente' => 'yes',
            'parteContraria' => 'yes',
        ],
        'Secretária' => [
            'descricao' => 'read',
            'prioridade' => 'read',
            'tipoPrazo' => 'read',
            'motivoReagendamento' => 'read',
            'cliente' => 'read',
            'parteContraria' => 'read',
        ],
        // Financeiro / Marketing / RH-lite / Cliente-portal → null (scope.no já bloqueia)
        'Financeiro' => null,
        'Marketing' => null,
        'RH-lite' => null,
        'Cliente-portal' => null,
    ];

    public function version(): string
    {
        return 'V005__patch_roles_field_level_prazo';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::POLICY as $roleName => $fieldLevelPolicy) {
            if ($fieldLevelPolicy === null) {
                continue;
            }
            $this->patchRole($pdo, $roleName, $fieldLevelPolicy);
        }
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional — pattern V003/V004.
    }

    /**
     * @param array<string, string> $fieldLevelPolicy
     */
    private function patchRole(PDO $pdo, string $roleName, array $fieldLevelPolicy): void
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

        $patched = $this->patchData($data, $fieldLevelPolicy);
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
     * @param array<string, string> $fieldLevelPolicy
     * @return array<string, mixed>
     */
    private function patchData(array $data, array $fieldLevelPolicy): array
    {
        $fieldLevel = $data['fieldLevel'] ?? [];
        if (! \is_array($fieldLevel)) {
            return $data;
        }

        $prazoFieldLevel = $fieldLevel['Prazo'] ?? [];
        if (! \is_array($prazoFieldLevel)) {
            return $data;
        }

        // Preserva customizações já existentes; só adiciona campos ausentes.
        foreach ($fieldLevelPolicy as $fieldName => $level) {
            if (! \array_key_exists($fieldName, $prazoFieldLevel)) {
                $prazoFieldLevel[$fieldName] = $level;
            }
        }

        $fieldLevel['Prazo'] = $prazoFieldLevel;
        $data['fieldLevel'] = $fieldLevel;

        return $data;
    }
}
