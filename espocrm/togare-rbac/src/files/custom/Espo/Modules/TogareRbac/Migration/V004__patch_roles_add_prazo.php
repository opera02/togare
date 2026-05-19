<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 4a.3 — adiciona scope `Prazo` nas 8 roles canônicas com política
 * Sócio/Admin=all, Advogado={read:own,edit:own,create:team,delete:no},
 * Assistente={read:team,edit:team,create:team,delete:no},
 * Secretária={read:team,edit:no,create:no,delete:no},
 * Financeiro/Marketing/RH-lite/Cliente-portal=no.
 *
 * **Por que esta migration existe** (mesmo com seeds JSON já atualizados):
 * Em instalações onde togare-rbac 0.7.x já rodou, os 8 seeds foram
 * INSERTados sem Prazo. RoleSeeder do AfterInstall.php é idempotente
 * (`SELECT id WHERE name=:name AND deleted=0` → skip se existe), portanto
 * NÃO atualiza scopeList/scopeLevel das roles existentes ao re-instalar
 * 0.8.0. Esta migration garante o patch.
 *
 * **Idempotente:**
 *  - Se Prazo já está em scopeList E scopeLevel com a configuração canônica
 *    desta role → no-op.
 *  - Se Prazo não está → adiciona scopeList + scopeLevel.
 *  - Se Prazo está mas scopeLevel diverge da canônica → preserva (admin
 *    pode ter customizado em Admin → Roles; fail-closed mais seguro).
 *
 * **down() é no-op intencional** — remover Prazo da role com instalação
 * em produção quebraria ACL by-assignment para Advogados que já estão
 * com prazos atribuídos. Reversão = decisão manual do operador.
 *
 * Pattern espelha V003__patch_advogado_processo_to_own (Story 3.5).
 */
final class V004__patch_roles_add_prazo implements MigrationInterface
{
    /** @var array<string, mixed> */
    private const POLICY = [
        'Sócio/Admin' => 'all',
        'Advogado' => ['read' => 'own', 'edit' => 'own', 'create' => 'team', 'delete' => 'no'],
        'Assistente/Estagiário' => ['read' => 'team', 'edit' => 'team', 'create' => 'team', 'delete' => 'no'],
        'Secretária' => ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
        'Financeiro' => 'no',
        'Marketing' => 'no',
        'RH-lite' => 'no',
        'Cliente-portal' => 'no',
    ];

    public function version(): string
    {
        return 'V004__patch_roles_add_prazo';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::POLICY as $roleName => $prazoLevel) {
            $this->patchRole($pdo, $roleName, $prazoLevel);
        }
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional — ver docblock da classe.
    }

    /**
     * @param array<string, mixed>|string $prazoLevel
     */
    private function patchRole(PDO $pdo, string $roleName, array|string $prazoLevel): void
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

        $patched = $this->patchData($data, $prazoLevel);
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
     * @param array<string, mixed>|string $prazoLevel
     * @return array<string, mixed>
     */
    private function patchData(array $data, array|string $prazoLevel): array
    {
        $changed = false;

        // 1) Garante scopeList contém "Prazo"
        $scopeList = $data['scopeList'] ?? [];
        if (\is_array($scopeList) && ! \in_array('Prazo', $scopeList, true)) {
            $scopeList[] = 'Prazo';
            $data['scopeList'] = $scopeList;
            $changed = true;
        }

        // 2) Garante scopeLevel.Prazo existe (preserva se já existir — admin)
        $scopeLevel = $data['scopeLevel'] ?? [];
        if (! \is_array($scopeLevel)) {
            return $data;
        }

        if (! \array_key_exists('Prazo', $scopeLevel)) {
            $scopeLevel['Prazo'] = $prazoLevel;
            $data['scopeLevel'] = $scopeLevel;
            $changed = true;
        }

        return $data;
    }
}
