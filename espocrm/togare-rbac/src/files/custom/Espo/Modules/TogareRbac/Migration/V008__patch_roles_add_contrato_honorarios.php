<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 6.1 — adiciona scope `ContratoHonorarios` nas 8 roles operacionais.
 *
 * RoleSeeder preserva roles existentes, então alterar só seed JSON não
 * atualiza instalações pré-existentes. Esta migration garante AC7 da Story
 * 6.1 em upgrades, preservando customizações do admin caso `ContratoHonorarios`
 * já esteja declarada.
 *
 * Política (Decisão #10 da Story 6.1; PRD L702 — Financeiro vê honorários;
 * Secretária não vê honorários; Marketing/RH-lite/Cliente-portal sem acesso):
 *  - Sócio/Admin: all
 *  - Financeiro: all (FR22, jornada Simone — Financeiro cria contratos)
 *  - Advogado: read own (assignedUser = current; populado pelo DefaultUploadedByHook
 *    via cliente.assignedUserId — advogado vê contratos dos clientes que atende)
 *  - Assistente/Estagiário: read own (idem advogado, leitura restrita)
 *  - Secretária: no
 *  - Marketing: no
 *  - RH-lite: no
 *  - Cliente-portal: no (scope.aclPortal=false; nem vê)
 *
 * Pattern: clone literal de V007 (Documento) ajustando scope + politica.
 *
 * Decisão dev D10.1.1 (simplificação da Decisão #10 da spec): em vez de Classes/
 * Select/ContratoHonorarios/OwnByClienteAssignment custom, usamos Espo native
 * `own` level (assignedUser = current user). O DefaultUploadedByHook popula
 * assignedUser do contrato com cliente.assignedUserId, fazendo Advogado/
 * Assistente atribuídos ao cliente verem naturalmente o contrato. Mais simples,
 * mais idiomático, zero classe custom.
 */
final class V008__patch_roles_add_contrato_honorarios implements MigrationInterface
{
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
     * Estados legados conhecidos de workdirs/instalações pré-review da 6.1.
     *
     * A migration continua preservando customizações do admin, mas corrige
     * somente políticas canônicas antigas que já tinham a chave
     * `ContratoHonorarios` com valor errado.
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
        return 'V008__patch_roles_add_contrato_honorarios';
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

        $patched = $this->patchData($data, $roleName, $level);
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
    private function patchData(array $data, string $roleName, array|string $level): array
    {
        $scopeList = $data['scopeList'] ?? [];
        if (! \is_array($scopeList)) {
            return $data;
        }
        if (! \in_array('ContratoHonorarios', $scopeList, true)) {
            $scopeList[] = 'ContratoHonorarios';
            $data['scopeList'] = $scopeList;
        }

        $scopeLevel = $data['scopeLevel'] ?? [];
        if (! \is_array($scopeLevel)) {
            return $data;
        }

        if (
            ! \array_key_exists('ContratoHonorarios', $scopeLevel)
            || $this->isLegacyPolicy($roleName, $scopeLevel['ContratoHonorarios'])
        ) {
            $scopeLevel['ContratoHonorarios'] = $level;
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
