<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

/**
 * Corrige roles já existentes de instalações 0.6.2-.
 *
 * O seed é idempotente e preserva customizações do admin; por isso o rename
 * "Process" -> "Processo" precisa acontecer em migration separada.
 */
final class V002__rename_process_scope_to_processo implements MigrationInterface
{
    private const TOGARE_ROLE_NAMES = [
        'Sócio/Admin',
        'Advogado',
        'Assistente/Estagiário',
        'Secretária',
        'Financeiro',
        'Marketing',
        'RH-lite',
        'Cliente-portal',
    ];

    public function version(): string
    {
        return 'V002__rename_process_scope_to_processo';
    }

    public function up(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, data FROM role WHERE name = :name AND deleted = 0',
        );
        $update = $pdo->prepare(
            'UPDATE role SET data = :data, modified_at = :modified_at WHERE id = :id',
        );
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach (self::TOGARE_ROLE_NAMES as $name) {
            $stmt->execute(['name' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (! \is_array($row)) {
                continue;
            }

            $data = \json_decode((string) ($row['data'] ?? '{}'), true);
            if (! \is_array($data)) {
                continue;
            }

            $patched = $this->patchData($data);
            if ($patched === $data) {
                continue;
            }

            $update->execute([
                'id' => (string) $row['id'],
                'data' => \json_encode($patched, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'modified_at' => $now,
            ]);
        }
    }

    public function down(PDO $pdo): void
    {
        // No-op intencional: reverter para "Process" quebraria a entidade real.
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function patchData(array $data): array
    {
        if (isset($data['scopeList']) && \is_array($data['scopeList'])) {
            $scopeList = [];
            foreach ($data['scopeList'] as $scope) {
                $scopeList[] = $scope === 'Process' ? 'Processo' : $scope;
            }
            $data['scopeList'] = \array_values(\array_unique($scopeList));
        }

        if (isset($data['scopeLevel']) && \is_array($data['scopeLevel']) && \array_key_exists('Process', $data['scopeLevel'])) {
            if (! \array_key_exists('Processo', $data['scopeLevel'])) {
                $data['scopeLevel']['Processo'] = $data['scopeLevel']['Process'];
            }
            unset($data['scopeLevel']['Process']);
        }

        return $data;
    }
}
