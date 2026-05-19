<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Service;

use Espo\Modules\TogareCore\Services\TogareLogger;
use PDO;
use PDOException;
use Throwable;

/**
 * Seed idempotente dos roles Togare na tabela ORM core `role` do EspoCRM.
 *
 * Lê arquivos JSON do diretório informado, faz INSERT por linha apenas quando
 * o role com o mesmo `name` ainda não existe. Logs estruturados via
 * TogareLogger para cada caminho (seeded, skipped, invalid_json, error).
 *
 * Vinculante (Story 2.1):
 *  - NUNCA sobrescreve role já existente (preserva customização do admin).
 *  - JSON inválido isolado não aborta o seed inteiro — outros JSONs continuam.
 *  - Falha SQL relança a exceção (aborta install) após emitir log error.
 */
final class RoleSeeder
{
    private const COLUMNS = [
        'name',
        'assignmentPermission',
        'userPermission',
        'messagePermission',
        'portalPermission',
        'exportPermission',
        'massUpdatePermission',
        'auditPermission',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly TogareLogger $logger,
    ) {
    }

    /**
     * @return array{seeded: int, skipped: int, invalid: int}
     */
    public function seedFromDir(string $dir): array
    {
        $files = $this->discoverJsonFiles($dir);

        $summary = ['seeded' => 0, 'skipped' => 0, 'invalid' => 0];

        foreach ($files as $file) {
            $outcome = $this->processFile($file);
            $summary[$outcome]++;
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    private function discoverJsonFiles(string $dir): array
    {
        $files = \glob(\rtrim($dir, '/\\') . '/*.json') ?: [];
        \sort($files);

        return \array_values($files);
    }

    /**
     * @return 'seeded'|'skipped'|'invalid'
     */
    private function processFile(string $file): string
    {
        $raw = @\file_get_contents($file);
        if ($raw === false) {
            TogareLogger::event(
                'warning',
                'rbac.seed.invalid_json',
                \sprintf("Não foi possível ler '%s'.", \basename($file)),
                ['file' => $file],
            );

            return 'invalid';
        }

        try {
            $decoded = \json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'rbac.seed.invalid_json',
                \sprintf("JSON inválido em '%s': %s", \basename($file), $e->getMessage()),
                ['file' => $file, 'error' => $e->getMessage()],
            );

            return 'invalid';
        }

        if (! \is_array($decoded) || ! isset($decoded['name']) || ! \is_string($decoded['name']) || $decoded['name'] === '') {
            TogareLogger::event(
                'warning',
                'rbac.seed.invalid_json',
                \sprintf("Estrutura inválida em '%s' — campo 'name' ausente ou vazio.", \basename($file)),
                ['file' => $file],
            );

            return 'invalid';
        }

        $name = $decoded['name'];

        if ($this->roleExists($name)) {
            TogareLogger::event(
                'info',
                'rbac.role.skipped',
                \sprintf("Role '%s' já existe — skip preservando customização do admin.", $name),
                ['role' => $name, 'reason' => 'already_exists'],
            );

            return 'skipped';
        }

        try {
            $id = $this->insertRole($decoded);
        } catch (\JsonException $e) {
            TogareLogger::event(
                'warning',
                'rbac.seed.invalid_json',
                \sprintf("Serialização JSON falhou para role '%s': %s", $name, $e->getMessage()),
                ['role' => $name, 'error' => $e->getMessage()],
            );

            return 'invalid';
        } catch (PDOException $e) {
            TogareLogger::event(
                'error',
                'rbac.seed.error',
                \sprintf("Falha SQL no seed do role '%s': %s", $name, $e->getMessage()),
                ['role' => $name, 'sqlstate' => $e->getCode()],
            );

            throw $e;
        }

        TogareLogger::event(
            'info',
            'rbac.role.seeded',
            \sprintf("Role '%s' seedada com sucesso.", $name),
            ['role' => $name, 'role_id' => $id],
        );

        return 'seeded';
    }

    private function roleExists(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM role WHERE name = :name LIMIT 1',
        );
        $stmt->execute(['name' => $name]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function insertRole(array $decoded): string
    {
        $id = $this->generateEspoId();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $sql = '
            INSERT INTO role
                (id, name, assignment_permission, user_permission, message_permission,
                 portal_permission, export_permission, mass_update_permission, audit_permission,
                 data, field_data, deleted, created_at, modified_at)
            VALUES
                (:id, :name, :ap, :up, :mp, :pp, :ep, :mup, :aup, :data, :field_data, 0, :now1, :now2)
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'name' => (string) $decoded['name'],
            'ap' => $this->stringField($decoded, 'assignmentPermission', 'no'),
            'up' => $this->stringField($decoded, 'userPermission', 'no'),
            'mp' => $this->stringField($decoded, 'messagePermission', 'no'),
            'pp' => $this->stringField($decoded, 'portalPermission', 'no'),
            'ep' => $this->stringField($decoded, 'exportPermission', 'no'),
            'mup' => $this->stringField($decoded, 'massUpdatePermission', 'no'),
            'aup' => $this->stringField($decoded, 'auditPermission', 'no'),
            'data' => \json_encode($decoded['data'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'field_data' => \json_encode($decoded['fieldData'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'now1' => $now,
            'now2' => $now,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function stringField(array $decoded, string $key, string $default): string
    {
        $value = $decoded[$key] ?? $default;
        if (! \is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * EspoCRM Util::generateId equivalente: 13 chars uniqid + 4 chars md5 = 17 chars.
     */
    private function generateEspoId(): string
    {
        return \uniqid() . \substr(\md5((string) \random_int(0, PHP_INT_MAX)), 0, 4);
    }
}
