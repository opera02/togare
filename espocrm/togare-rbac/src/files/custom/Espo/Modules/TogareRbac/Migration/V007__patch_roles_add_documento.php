<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 5.2 — adiciona scope `Documento` nas 8 roles operacionais.
 *
 * RoleSeeder preserva roles existentes, então alterar só seed JSON não
 * atualiza instalações pré-existentes. Esta migration garante AC8-11
 * em upgrades, preservando customizações do admin caso `Documento` já esteja
 * declarada.
 *
 * Política (Decisão #11 e Story 5.2 T7.1):
 *  - Sócio/Admin: all
 *  - Advogado: read/edit/create team, delete no
 *  - Secretária: read team, create team, edit/delete no
 *  - Assistente/Estagiário: read team, edit/create/delete no
 *  - Financeiro: read team, edit/create/delete no
 *  - Marketing: no
 *  - RH-lite: no
 *  - Cliente-portal: no (Story 7a abre)
 *
 * Pattern: clone literal de V006 (PublicacaoAmbigua) ajustando scope + politica.
 */
final class V007__patch_roles_add_documento implements MigrationInterface
{
    /** @var array<string, mixed> */
    private const POLICY = [
        'Sócio/Admin' => 'all',
        'Advogado' => ['read' => 'team', 'edit' => 'team', 'create' => 'team', 'delete' => 'no'],
        'Secretária' => ['read' => 'team', 'edit' => 'no', 'create' => 'team', 'delete' => 'no'],
        'Assistente/Estagiário' => ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
        'Financeiro' => ['read' => 'team', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
        'Marketing' => 'no',
        'RH-lite' => 'no',
        'Cliente-portal' => 'no',
    ];

    public function version(): string
    {
        return 'V007__patch_roles_add_documento';
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
        if (! \is_array($scopeList)) {
            return $data;
        }
        if (! \in_array('Documento', $scopeList, true)) {
            $scopeList[] = 'Documento';
            $data['scopeList'] = $scopeList;
        }

        $scopeLevel = $data['scopeLevel'] ?? [];
        if (! \is_array($scopeLevel)) {
            return $data;
        }

        if (! \array_key_exists('Documento', $scopeLevel)) {
            $scopeLevel['Documento'] = $level;
            $data['scopeLevel'] = $scopeLevel;
        }

        return $data;
    }
}
