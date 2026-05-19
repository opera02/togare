<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Story 3.5 — força `Advogado.Processo.read = 'own'` (ACL by-assignment FR11).
 *
 * Em instalações que rodaram togare-rbac 0.6.3 (Story 3.4), a role Advogado
 * tem `Processo.read = 'team'` (visibilidade ampla). Esta migration corrige
 * para `'own'` — que combinado com `scopes.collaborators=true` + aclDefs
 * (entregues em togare-core 0.11.0) faz o EspoCRM emitir o WHERE
 * `assigned_user_id = :user OR EXISTS (SELECT 1 FROM processo_collaborator ...)`
 * automaticamente.
 *
 * **Escopo:** APENAS a row `Advogado` é tocada. As outras 7 roles preservam
 * configuração existente (incluindo customizações que o admin tenha feito
 * via UI em Admin → Roles).
 *
 * **Idempotente:**
 *  - Se `Processo.read` já é `'own'` → no-op (skip update).
 *  - Se `Processo` está como string `'team'` (forma legacy) → upgrade para
 *    granular `{read: own, edit: own, create: team, delete: no}` (defensivo).
 *  - Se `Processo` ausente do scopeLevel → no-op (admin removeu — não
 *    impomos sem permissão).
 *
 * **down() é no-op intencional** — voltar para `'team'` reabriria a brecha
 * de FR11 (Advogado vendo processo de outro Advogado). Se o operador
 * precisar reverter, faz manualmente em Admin → Roles.
 *
 * Espelha o padrão da V002 (rename Process→Processo) para consistência de
 * código e para o teste reaproveitar boilerplate.
 */
final class V003__patch_advogado_processo_to_own implements MigrationInterface
{
    private const ROLE_NAME = 'Advogado';

    private const TARGET_GRANULAR = [
        'read' => 'own',
        'edit' => 'own',
        'create' => 'team',
        'delete' => 'no',
    ];

    public function version(): string
    {
        return 'V003__patch_advogado_processo_to_own';
    }

    public function up(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, data FROM role WHERE name = :name AND deleted = 0',
        );
        $stmt->execute(['name' => self::ROLE_NAME]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! \is_array($row)) {
            return;
        }

        $data = \json_decode((string) ($row['data'] ?? '{}'), true);
        if (! \is_array($data)) {
            return;
        }

        $patched = $this->patchData($data);
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

    public function down(PDO $pdo): void
    {
        // No-op intencional: voltar Processo.read para 'team' reabriria a
        // brecha de FR11. Reversão exige decisão consciente do operador.
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function patchData(array $data): array
    {
        if (! isset($data['scopeLevel']) || ! \is_array($data['scopeLevel'])) {
            return $data;
        }

        if (! \array_key_exists('Processo', $data['scopeLevel'])) {
            return $data;
        }

        $current = $data['scopeLevel']['Processo'];

        // Caminho 1: forma string legacy (ex.: "team", "all", "own").
        if (\is_string($current)) {
            if ($current === 'own') {
                return $data;
            }

            // Upgrade para granular alvo. Preservar 'all' seria contraditório
            // (Advogado nunca tem 'all' em FR11) — mas se o admin editou
            // manualmente para 'all', não rebaixamos: fail-closed é só pra
            // 'team' (que é o estado pré-3.5 esperado).
            if ($current !== 'team') {
                return $data;
            }

            $data['scopeLevel']['Processo'] = self::TARGET_GRANULAR;
            return $data;
        }

        // Caminho 2: forma granular (esperado pós-Story 3.4).
        if (! \is_array($current)) {
            return $data;
        }

        if (($current['read'] ?? null) === 'own') {
            return $data;
        }

        if (($current['read'] ?? null) !== 'team') {
            // Estado inesperado (ex.: read='all' ou 'no'). Não tocamos —
            // pode ser customização legítima do admin.
            return $data;
        }

        $current['read'] = 'own';
        $data['scopeLevel']['Processo'] = $current;

        return $data;
    }
}
