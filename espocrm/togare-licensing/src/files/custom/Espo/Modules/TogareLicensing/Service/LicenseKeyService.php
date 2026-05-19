<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareLicensing\Events\LicenseStatusChangedEvent;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Orquestrador de ativação de licença.
 *
 * Fluxo:
 *   1. JwtValidator::validate(key) → JwtValidationResult.
 *   2. Se inválido, retorna LicenseActivationResult::invalid (atomic fail —
 *      nenhuma linha persistida).
 *   3. Se válido, faz UPSERT por module_name em module_status (1 linha
 *      por módulo declarado em claims.mod). Transita status para 'active'.
 *   4. Dispara LicenseStatusChangedEvent para cada módulo que mudou de status.
 *
 * Story 1b.1.1.2-followup: tabela canônica `module_status` (criada pelo ORM
 * EspoCRM no rebuild). DI via EntityManager (idiomática) com extração do PDO
 * no boot — mesmo padrão da 1b.1.1.1-followup (QueueService/RateLimiter).
 * Filtros incluem `deleted = 0` para respeitar soft-delete EspoCRM.
 */
final class LicenseKeyService
{
    private readonly PDO $pdo;

    public function __construct(
        private readonly JwtValidator $jwtValidator,
        EntityManager $entityManager,
        private readonly EventBusContract $eventBus,
    ) {
        $this->pdo = $entityManager->getPDO();
    }

    public function activate(string $key): LicenseActivationResult
    {
        $result = $this->jwtValidator->validate($key);

        if (! $result->isValid) {
            return LicenseActivationResult::invalid(
                $result->reason ?? JwtValidationResult::REASON_MALFORMED,
                $result->errorMessage ?? 'Chave JWT inválida',
            );
        }

        $claims = $result->claims;
        $now = new DateTimeImmutable();
        $expiresAt = new DateTimeImmutable('@' . (int) $claims['exp']);
        $modules = \array_values(\array_unique($claims['mod']));

        $activated = [];

        foreach ($modules as $module) {
            $existing = $this->findByModule($module);
            $oldStatus = $existing['status'] ?? 'never_activated';

            $this->upsertActive(
                module: $module,
                installationId: (string) ($claims['sub'] ?? ''),
                keyJti: (string) ($claims['jti'] ?? ''),
                expiresAt: $expiresAt,
                now: $now,
                existingId: $existing['id'] ?? null,
            );

            // Emite evento apenas em mudança real de status (active → active não emite).
            if ($oldStatus !== 'active') {
                $this->eventBus->dispatch(new LicenseStatusChangedEvent(
                    module: $module,
                    oldStatus: $oldStatus,
                    newStatus: 'active',
                    reason: $oldStatus === 'never_activated' ? 'key_activated' : 'key_refreshed',
                    occurredAt: $now,
                ));
            }

            $activated[] = $module;
        }

        TogareLogger::event('info', 'licensing.key.activated', 'Chave JWT ativada com sucesso', [
            'installation_id' => $claims['sub'] ?? null,
            'jti_prefix' => isset($claims['jti']) ? \substr((string) $claims['jti'], 0, 8) : null,
            'modules' => $activated,
            'expires_at' => $expiresAt->format(\DATE_ATOM),
        ]);

        return LicenseActivationResult::success($activated, $expiresAt);
    }

    /**
     * @return array{id: string, status: string}|null
     */
    private function findByModule(string $module): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status FROM module_status WHERE module_name = :m AND deleted = 0 LIMIT 1',
        );
        $stmt->execute([':m' => $module]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (string) $row['id'], 'status' => (string) $row['status']];
    }

    private function upsertActive(
        string $module,
        string $installationId,
        string $keyJti,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
        ?string $existingId,
    ): void {
        $nowSql = $now->format('Y-m-d H:i:s');
        $expSql = $expiresAt->format('Y-m-d H:i:s');

        if ($existingId !== null) {
            $stmt = $this->pdo->prepare('
                UPDATE module_status
                SET status = :status,
                    installation_id = :inst,
                    key_jti = :jti,
                    expires_at = :exp,
                    last_validated_at = :now,
                    last_validation_outcome = :outcome,
                    activated_at = COALESCE(activated_at, :now2)
                WHERE id = :id
            ');
            $stmt->execute([
                ':status' => 'active',
                ':inst' => $installationId,
                ':jti' => $keyJti,
                ':exp' => $expSql,
                ':now' => $nowSql,
                ':now2' => $nowSql,
                ':outcome' => 'success',
                ':id' => $existingId,
            ]);

            return;
        }

        // ID 17-char alphanumeric — formato EspoCRM ORM (Util::generateId-like).
        $id = \substr(\bin2hex(\random_bytes(9)), 0, 17);
        $stmt = $this->pdo->prepare('
            INSERT INTO module_status
                (id, deleted, module_name, status, installation_id, key_jti, expires_at,
                 last_validated_at, last_validation_outcome, activated_at)
            VALUES
                (:id, 0, :module, :status, :inst, :jti, :exp, :now, :outcome, :now2)
        ');
        $stmt->execute([
            ':id' => $id,
            ':module' => $module,
            ':status' => 'active',
            ':inst' => $installationId,
            ':jti' => $keyJti,
            ':exp' => $expSql,
            ':now' => $nowSql,
            ':outcome' => 'success',
            ':now2' => $nowSql,
        ]);
    }
}
