<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 4b.1a - adiciona scope `PublicacaoAmbigua` nas roles operacionais.
 *
 * O RoleSeeder preserva roles existentes, entao alterar apenas os seed JSON
 * nao atualiza instalacoes que ja rodaram togare-rbac. Esta migration garante
 * o contrato AC5 em upgrades, preservando qualquer customizacao pre-existente
 * do admin caso `PublicacaoAmbigua` ja esteja declarada.
 *
 * Politica:
 *  - Socio/Admin: all
 *  - Advogado: read/edit team, create/delete no
 *  - Assistente/Estagiario: read team, edit/create/delete no
 *  - Secretaria: ausente (= bloqueio implicito)
 */
final class V006__patch_roles_add_publicacao_ambigua implements MigrationInterface
{
    /** @var array<string, mixed> */
    private const POLICY = [
        'Sócio/Admin' => 'all',
        'Advogado' => ['read' => 'team', 'edit' => 'team', 'create' => 'no', 'delete' => 'no'],
        'Assistente/Estagiário' => ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
    ];

    public function version(): string
    {
        return 'V006__patch_roles_add_publicacao_ambigua';
    }

    public function up(PDO $pdo): void
    {
        foreach (self::POLICY as $roleName => $level) {
            $this->patchRole($pdo, $roleName, $level);
        }
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional: remover scope de role existente pode quebrar ACL em producao.
    }

    /**
     * @param array<string, mixed>|string $level
     */
    private function patchRole(PDO $pdo, string $roleName, array|string $level): void
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

        $patched = $this->patchData($data, $level);
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
    private function patchData(array $data, array|string $level): array
    {
        $scopeList = $data['scopeList'] ?? [];
        if (\is_array($scopeList) && ! \in_array('PublicacaoAmbigua', $scopeList, true)) {
            $scopeList[] = 'PublicacaoAmbigua';
            $data['scopeList'] = $scopeList;
        }

        $scopeLevel = $data['scopeLevel'] ?? [];
        if (! \is_array($scopeLevel)) {
            return $data;
        }

        if (! \array_key_exists('PublicacaoAmbigua', $scopeLevel)) {
            $scopeLevel['PublicacaoAmbigua'] = $level;
            $data['scopeLevel'] = $scopeLevel;
        }

        return $data;
    }
}
