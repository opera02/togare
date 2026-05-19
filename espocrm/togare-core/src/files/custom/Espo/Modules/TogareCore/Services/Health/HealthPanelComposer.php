<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;

/**
 * Lógica pura de composição do painel TogareHealth (Story 10.2, FR41).
 *
 * **Por que classe separada (pattern DashboardLayoutSeeder):** permite testar
 * o mapeamento estado→tile e a regra "módulo ausente = cinza, NÃO erro"
 * isoladamente, sem container/PDO/rede.
 *
 * **O problema do 4º estado (Dev Notes da story):** `HealthCheckResult` só tem
 * 3 status (`healthy`/`degraded`/`unhealthy`). A AC1 exige um 4º estado visual
 * "não-instalado" (cinza, NÃO erro). Se módulo ausente virasse
 * `STATUS_UNHEALTHY`, o tile ficaria VERMELHO e violaria a AC1. Por isso o
 * estado `nao_instalado` é decidido AQUI (camada de composição), via
 * `tileForAbsentModule()`, SEM instanciar o provider — `mapStatusToState()`
 * só cobre os 3 status do contrato.
 *
 * Mapa estado→tile (consumido pelo frontend health-panel-renderer):
 *   healthy   → ok       (verde)
 *   degraded  → lento    (amarelo)
 *   unhealthy → offline  (vermelho)
 *   <ausente> → nao_instalado (cinza)  ← nunca vem de HealthCheckResult
 */
final class HealthPanelComposer
{
    public const STATE_OK = 'ok';
    public const STATE_LENTO = 'lento';
    public const STATE_OFFLINE = 'offline';
    public const STATE_NAO_INSTALADO = 'nao_instalado';

    /**
     * Ordem fixa dos 6 tiles do grid 3x2 (UX C15 — DJEN, TPU, MariaDB,
     * Nextcloud, Redis, Backup). Licença é rodapé, não tile.
     *
     * @var array<string, string> key => label pt-BR
     */
    public const TILE_LABELS = [
        'djen' => 'DJEN',
        'tpu' => 'TPU',
        'mariadb' => 'MariaDB',
        'nextcloud' => 'Nextcloud',
        'redis' => 'Redis',
        'backup' => 'Backup',
    ];

    /**
     * Mapeia o status do contrato (3 valores) para o estado visual do tile
     * (3 dos 4 — nunca produz `nao_instalado`, que é exclusivo de módulo
     * ausente e tratado por `tileForAbsentModule()`).
     */
    public static function mapStatusToState(string $status): string
    {
        return match ($status) {
            HealthCheckResult::STATUS_HEALTHY => self::STATE_OK,
            HealthCheckResult::STATUS_DEGRADED => self::STATE_LENTO,
            HealthCheckResult::STATUS_UNHEALTHY => self::STATE_OFFLINE,
            // Defensivo: status fora do enum do contrato nunca pinta verde.
            default => self::STATE_OFFLINE,
        };
    }

    /**
     * Tile de um subsistema cujo provider FOI executado.
     *
     * @return array{key: string, label: string, state: string, message: string, detailLink: ?string}
     */
    public static function tileFromResult(
        string $key,
        HealthCheckResult $result,
        ?string $detailLink = null,
    ): array {
        return [
            'key' => $key,
            'label' => self::TILE_LABELS[$key] ?? $key,
            'state' => self::mapStatusToState($result->status),
            'message' => $result->message,
            'detailLink' => $detailLink,
        ];
    }

    /**
     * Tile de módulo premium ausente — cinza calmo "Não instalado", SEM
     * sinalizar erro (AC1). Nunca chama `check()`.
     *
     * @return array{key: string, label: string, state: string, message: string, detailLink: ?string}
     */
    public static function tileForAbsentModule(string $key): array
    {
        return [
            'key' => $key,
            'label' => self::TILE_LABELS[$key] ?? $key,
            'state' => self::STATE_NAO_INSTALADO,
            'message' => 'Não instalado',
            'detailLink' => null,
        ];
    }

    /**
     * Reordena os tiles na ordem canônica do grid (UX C15). Tiles
     * desconhecidos vão para o fim, preservando ordem de inserção.
     *
     * @param  list<array{key: string, label: string, state: string, message: string, detailLink: ?string}> $tiles
     * @return list<array{key: string, label: string, state: string, message: string, detailLink: ?string}>
     */
    public static function orderTiles(array $tiles): array
    {
        $order = \array_keys(self::TILE_LABELS);
        $byKey = [];
        foreach ($tiles as $tile) {
            $byKey[$tile['key']] = $tile;
        }

        $ordered = [];
        foreach ($order as $key) {
            if (isset($byKey[$key])) {
                $ordered[] = $byKey[$key];
                unset($byKey[$key]);
            }
        }
        // Qualquer tile fora da ordem canônica preserva inserção no fim.
        foreach ($byKey as $tile) {
            $ordered[] = $tile;
        }

        return $ordered;
    }
}
