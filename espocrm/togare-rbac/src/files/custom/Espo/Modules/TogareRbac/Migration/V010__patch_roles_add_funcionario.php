<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 6.5 — adiciona o scope `Funcionario` (RH-lite, FR32) nas 8 roles
 * operacionais.
 *
 * Pattern: clone literal de V009 (Fatura/LancamentoFinanceiro) com 1 scope.
 *
 * Política FR32 (PRD L965 — Sócio/Admin + RH-lite cadastram funcionários;
 * L512/L704 — RH-lite NÃO vê processos e ninguém mais vê funcionários;
 * blindagem cruzada):
 *  - Sócio/Admin: all  (administra equipe)
 *  - RH-lite: all       (papel dedicado ao cadastro básico de equipe)
 *  - Advogado: no
 *  - Assistente/Estagiário: no
 *  - Secretária: no
 *  - Financeiro: no
 *  - Marketing: no
 *  - Cliente-portal: no  (scope.aclPortal=false; nem vê)
 *
 * Seeds JSON cobrem instalação NOVA (os 8 já contêm `Funcionario` coerente).
 * Esta migration patcha instalações EXISTENTES (togare-rbac < 0.14.0) de
 * forma idempotente, sem sobrescrever customização manual do admin.
 *
 * LEGACY_POLICY preventivo (regra A1 do Grupo 2 da review da 6.1): patcha
 * apenas valores não-canônicos conhecidos que um seed/dev pré-release possa
 * ter deixado para `Funcionario` nas roles restritas (ex.: `all` vazado),
 * preservando customização deliberada do admin.
 *
 * Sem mudança em entityDefs/scopes/hooks; só patch declarativo dos 8 roles.
 */
final class V010__patch_roles_add_funcionario implements MigrationInterface
{
    private const SCOPES = ['Funcionario'];

    /** @var array<string, mixed> */
    private const POLICY = [
        'Sócio/Admin' => 'all',
        'RH-lite' => 'all',
        'Advogado' => 'no',
        'Assistente/Estagiário' => 'no',
        'Secretária' => 'no',
        'Financeiro' => 'no',
        'Marketing' => 'no',
        'Cliente-portal' => 'no',
    ];

    /**
     * Estados legados conhecidos pré-canônicos. A migration patcha apenas
     * valores legados específicos; preserva customizações do admin.
     *
     * Funcionario é scope NOVO — em instalações legadas o caminho principal
     * é "scope ausente → adiciona canônico". Estas entradas cobrem o caso
     * de um seed/dev pré-release ter vazado `all` (ou variantes de leitura)
     * para roles que devem ser `no`.
     *
     * @var array<string, list<array<string, mixed>|string>>
     */
    private const LEGACY_POLICY = [
        'Advogado' => [
            'all',
            ['read' => 'all', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
        ],
        'Assistente/Estagiário' => [
            'all',
            ['read' => 'all', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'],
        ],
        'Secretária' => [
            'all',
        ],
        'Financeiro' => [
            'all',
        ],
        'Marketing' => [
            'all',
        ],
        'Cliente-portal' => [
            'all',
        ],
    ];

    public function version(): string
    {
        return 'V010__patch_roles_add_funcionario';
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
            $scopeList = [];
        }
        if (! \in_array($scope, $scopeList, true)) {
            $scopeList[] = $scope;
            $data['scopeList'] = $scopeList;
        }

        $scopeLevel = $data['scopeLevel'] ?? [];
        if (! \is_array($scopeLevel)) {
            $scopeLevel = [];
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
