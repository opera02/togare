<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Health;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\TogareCore\Contracts\HealthCheckProviderContract;
use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Throwable;

/**
 * Health do último backup (Story 10.2, AC2 — FR41 + NFR21/NFR22).
 *
 * Lê a sentinela `/var/backups/togare/last-success.json` (escrita atômica pelo
 * `docker/backup/backup.sh` SÓ em sucesso total — não há campo de status, logo
 * "falha" é inferida por staleness). O container `espocrm` passa a montar esse
 * caminho read-only (Decisão A2.1 — bind `:ro` no docker-compose.yml).
 *
 * Limiar = 26h (espelha `docker/backup/healthcheck.sh::THRESHOLD_HOURS`, NÃO
 * reinventa). Mapa de estado:
 *   - sentinela ausente → DEGRADED "Backup ainda não rodou. Ver log."
 *       (instalação nova: o compose dá 25h de start_period; NÃO é vermelho).
 *   - idade > 26h       → UNHEALTHY "Backup atrasado — último há {N}h. Ver log."
 *   - idade ≤ 26h       → HEALTHY  "Último backup há {N}h" / "há {N} min".
 *
 * Nunca lança (contrato). detailLink resolvido pelo HealthCheckService.
 */
final class BackupHealthProvider implements HealthCheckProviderContract
{
    public const THRESHOLD_HOURS = 26;

    public function __construct(private readonly string $sentinelPath)
    {
    }

    public function name(): string
    {
        return 'backup';
    }

    public function check(): HealthCheckResult
    {
        try {
            if (! \is_file($this->sentinelPath)) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    'Backup ainda não rodou. Ver log.',
                    ['sentinel' => 'absent'],
                );
            }

            $raw = @\file_get_contents($this->sentinelPath);
            // P9: arquivo vazio = escrita incompleta/falha de backup, não "ainda não rodou".
            if ($raw === '') {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    'Backup ainda não rodou. Ver log.',
                    ['sentinel' => 'empty'],
                );
            }
            $data = \is_string($raw) ? \json_decode($raw, true) : null;
            $timestamp = \is_array($data) ? ($data['timestamp'] ?? null) : null;

            if (! \is_string($timestamp) || $timestamp === '') {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    'Backup ainda não rodou. Ver log.',
                    ['sentinel' => 'invalid'],
                );
            }

            $iso = \str_replace('Z', '+00:00', $timestamp);
            $last = new DateTimeImmutable($iso);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $ageSeconds = $now->getTimestamp() - $last->getTimestamp();
            if ($ageSeconds < 0) {
                // Clock-skew: sentinel timestamp está à frente do relógio PHP.
                try {
                    TogareLogger::event(
                        'warning',
                        'health.backup.clock_skew',
                        'Sentinela de backup tem timestamp futuro — possível clock-skew entre containers.',
                        ['sentinelTimestamp' => $timestamp, 'phpNow' => $now->format('c')]
                    );
                } catch (Throwable) {
                }
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    'Backup ainda não rodou. Ver log.',
                    ['sentinel' => 'clock_skew', 'timestamp' => $timestamp],
                );
            }
            $ageHours = (int) \floor($ageSeconds / 3600);

            if ($ageSeconds > self::THRESHOLD_HOURS * 3600) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_UNHEALTHY,
                    \sprintf('Backup atrasado — último há %dh. Ver log.', $ageHours),
                    ['ageHours' => $ageHours, 'timestamp' => $timestamp],
                );
            }

            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                $this->formatFreshMessage($ageSeconds),
                ['ageSeconds' => $ageSeconds, 'timestamp' => $timestamp],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_UNHEALTHY,
                'Backup atrasado — Ver log.',
                ['cause' => $e->getMessage()],
            );
        }
    }

    private function formatFreshMessage(int $ageSeconds): string
    {
        if ($ageSeconds < 3600) {
            $minutes = (int) \floor($ageSeconds / 60);
            return \sprintf('Último backup há %d min', \max(1, $minutes));
        }
        $hours = (int) \floor($ageSeconds / 3600);
        return \sprintf('Último backup há %dh', $hours);
    }
}
