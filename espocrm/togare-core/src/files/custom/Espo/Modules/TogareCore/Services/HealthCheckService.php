<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\TogareCore\Contracts\HealthCheckProviderContract;
use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Espo\Modules\TogareCore\Services\Health\BackupHealthProvider;
use Espo\Modules\TogareCore\Services\Health\DjenHealthProvider;
use Espo\Modules\TogareCore\Services\Health\HealthPanelComposer;
use Espo\Modules\TogareCore\Services\Health\MariadbHealthProvider;
use Espo\Modules\TogareCore\Services\Health\NextcloudHealthProvider;
use Espo\Modules\TogareCore\Services\Health\RedisHealthProvider;
use Espo\Modules\TogareCore\Services\Health\TpuHealthProvider;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Orquestrador do painel TogareHealth (Story 10.2, FR41).
 *
 * Monta o payload do `GET /api/v1/TogareHealth/action/data`:
 *   { tiles: [...6...], licenca: {...}|null, historico: [...] }
 *
 * Regras vinculantes:
 *  - **Tolera módulos ausentes (AC1):** DJEN/TPU/Nextcloud só são probados se
 *    o módulo respectivo está instalado (`class_exists`). Ausente → tile cinza
 *    "Não instalado" via `HealthPanelComposer::tileForAbsentModule()`, SEM
 *    instanciar o provider. MariaDB/Redis/Backup são infra sempre presente.
 *  - **Nunca trava o painel (AC5):** cada `check()` é envolto em try/catch
 *    redundante (o contrato já diz "nunca lança", mas o agregador não confia);
 *    falha de uma probe vira tile offline, jamais exceção propagada.
 *  - **Licença = rodapé, não tile (AC3):** best-effort a partir da entidade
 *    `ModuleStatus` do togare-licensing. Ausente/ilegível → `null` (linha some).
 *  - **Histórico via audit log (Decisão A2.2):** sem entidade nova; lê eventos
 *    recentes de saúde/integração/licença de `togare_audit_log`.
 */
final class HealthCheckService
{
    /** Default da sentinela de backup (compose monta `:ro` neste path). */
    private const BACKUP_SENTINEL_DEFAULT = '/var/backups/togare/last-success.json';

    /** Prefixos de eventos relevantes ao histórico de incidentes (LIKE 'X%'). */
    private const HISTORY_EVENT_PREFIXES = [
        'health.',
        'integration.',
        'license.',
        'backup.',
    ];

    /** Nomes exatos de eventos relevantes ao histórico de incidentes (match exato). */
    private const HISTORY_EVENT_EXACT = [
        'djen.adapter.unavailable',
        'tpu.adapter.unavailable',
    ];

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    /**
     * Payload completo do painel.
     *
     * @return array{
     *   tiles: list<array{key:string,label:string,state:string,message:string,detailLink:?string}>,
     *   licenca: ?array{state:string,message:string},
     *   historico: list<array{occurredAt:string,event:string,message:string}>,
     *   historicLink: string,
     *   generatedAt: string
     * }
     */
    public function getPanel(): array
    {
        $tiles = [];

        // --- Infra sempre presente -------------------------------------
        $tiles[] = $this->runProvider('mariadb', fn () =>
            new MariadbHealthProvider($this->entityManager));

        $tiles[] = $this->runProvider('redis', fn () => new RedisHealthProvider(
            (string) (\getenv('TOGARE_REDIS_HOST') ?: 'redis'),
            (int) (\getenv('TOGARE_REDIS_PORT') ?: 6379),
            ($pw = (string) \getenv('TOGARE_REDIS_PASSWORD')) !== '' ? $pw : null,
        ));

        $backupSentinel = (string) (\getenv('TOGARE_BACKUP_SENTINEL_PATH')
            ?: self::BACKUP_SENTINEL_DEFAULT);
        $tiles[] = $this->runProvider(
            'backup',
            fn () => new BackupHealthProvider($backupSentinel),
            '#Admin/jobs', // detailLink: painel de jobs/logs (AC2 "ver log")
        );

        // --- Premium opcional: probe só se o módulo está instalado (AC1) -
        $tiles[] = $this->moduleInstalled('Espo\\Modules\\TogareDjen\\Services\\DjenAdapter')
            ? $this->runProvider('djen', fn () => new DjenHealthProvider(
                (string) (\getenv('TOGARE_DJEN_CB_STATE_PATH')
                    ?: '/var/togare-djen/circuit-breaker.json'),
            ))
            : HealthPanelComposer::tileForAbsentModule('djen');

        $tiles[] = $this->moduleInstalled('Espo\\Modules\\TogareTpu\\Services\\PdpjAdapter')
            ? $this->runProvider('tpu', fn () => new TpuHealthProvider(
                ($p = (string) \getenv('TOGARE_TPU_CB_STATE_PATH')) !== '' ? $p : null,
            ))
            : HealthPanelComposer::tileForAbsentModule('tpu');

        $tiles[] = $this->moduleInstalled('Espo\\Modules\\TogareNextcloudBridge\\Services\\NextcloudFileStorage')
            ? $this->runProvider('nextcloud', fn () => new NextcloudHealthProvider(
                (string) (\getenv('TOGARE_NEXTCLOUD_BASE_URL') ?: 'http://nextcloud:80'),
            ))
            : HealthPanelComposer::tileForAbsentModule('nextcloud');

        return [
            'tiles' => HealthPanelComposer::orderTiles($tiles),
            'licenca' => $this->resolveLicenca(),
            'historico' => $this->resolveHistorico(),
            'historicLink' => '#Admin/TogareAuditLog',
            'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format('c'),
        ];
    }

    /**
     * Executa um provider com guarda redundante. O contrato já garante "nunca
     * lança"; este try/catch é o cinto de segurança do agregador (AC5).
     *
     * @param callable():HealthCheckProviderContract $factory
     * @return array{key:string,label:string,state:string,message:string,detailLink:?string}
     */
    private function runProvider(string $key, callable $factory, ?string $detailLink = null): array
    {
        try {
            $provider = $factory();
            $result = $provider->check();
            if (! $result instanceof HealthCheckResult) {
                $result = new HealthCheckResult(
                    HealthCheckResult::STATUS_UNHEALTHY,
                    'Resultado inválido',
                );
            }
            $tile = HealthPanelComposer::tileFromResult($key, $result, $detailLink);
        } catch (Throwable $e) {
            // Probe estourou apesar do contrato — degrada o tile, não a página.
            try {
                TogareLogger::event(
                    'error',
                    'health.provider.failed',
                    \sprintf("Provider '%s' lançou exceção: %s", $key, $e->getMessage()),
                    ['provider' => $key, 'cause' => $e->getMessage()],
                );
            } catch (Throwable) {
                // logger fora de init (testes) — silencioso.
            }
            $tile = HealthPanelComposer::tileFromResult(
                $key,
                new HealthCheckResult(HealthCheckResult::STATUS_UNHEALTHY, 'Fora do ar'),
                $detailLink,
            );
        }
        return $tile;
    }

    private function moduleInstalled(string $fqcn): bool
    {
        try {
            return \class_exists($fqcn, true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Rodapé de licença (AC3). Best-effort sobre `ModuleStatus`. Nunca quebra
     * o painel: qualquer incerteza → `null` (linha some).
     *
     * @return ?array{state:string,message:string}
     */
    private function resolveLicenca(): ?array
    {
        if (! $this->moduleInstalled('Espo\\Modules\\TogareLicensing\\Service\\JwtValidator')) {
            return null;
        }

        try {
            $record = $this->entityManager
                ->getRDBRepository('ModuleStatus')
                ->order('expiresAt', 'ASC')
                ->findOne();

            if ($record === null) {
                return null;
            }

            $expiresAt = $record->get('expiresAt');
            if (! \is_string($expiresAt) || $expiresAt === '') {
                return null;
            }
            $soonest = \strtotime($expiresAt);
            if ($soonest === false) {
                return null;
            }

            $now = \time();
            $dayDiff = (int) \floor(($soonest - $now) / 86400);

            if ($dayDiff < 0) {
                return [
                    'state' => 'vencida',
                    'message' => \sprintf(
                        'Licença vencida há %d dias — módulos em read-only',
                        \abs($dayDiff),
                    ),
                ];
            }
            if ($dayDiff <= 30) {
                $msg = $dayDiff === 0
                    ? 'Licença expira hoje'
                    : \sprintf('Licença expira em %d dias', $dayDiff);
                return ['state' => 'expirando', 'message' => $msg];
            }
            return [
                'state' => 'valida',
                'message' => \sprintf(
                    'Licença válida até %s',
                    (new DateTimeImmutable('@' . $soonest))
                        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
                        ->format('d/m/Y'),
                ),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Histórico de incidentes via audit log (Decisão A2.2 — sem entidade nova).
     *
     * @return list<array{occurredAt:string,event:string,message:string}>
     */
    private function resolveHistorico(): array
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $like = [];
            $params = [];
            $i = 0;
            foreach (self::HISTORY_EVENT_PREFIXES as $prefix) {
                $like[] = "event LIKE :p{$i}";
                $params[":p{$i}"] = $prefix . '%';
                $i++;
            }
            foreach (self::HISTORY_EVENT_EXACT as $event) {
                $like[] = "event = :p{$i}";
                $params[":p{$i}"] = $event;
                $i++;
            }
            $sql = 'SELECT occurred_at, event, context_json
                    FROM togare_audit_log
                    WHERE ' . \implode(' OR ', $like) . '
                    ORDER BY occurred_at DESC
                    LIMIT 20';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $ctx = \json_decode((string) ($r['context_json'] ?? ''), true);
                // runProvider escreve 'cause'; fallback para 'message' (legado) e depois event.
                $msg = \is_array($ctx) && isset($ctx['cause'])
                    ? (string) $ctx['cause']
                    : (\is_array($ctx) && isset($ctx['message'])
                        ? (string) $ctx['message']
                        : (string) ($r['event'] ?? ''));
                $out[] = [
                    'occurredAt' => (string) ($r['occurred_at'] ?? ''),
                    'event' => (string) ($r['event'] ?? ''),
                    'message' => $msg,
                ];
            }
            return $out;
        } catch (Throwable) {
            // Tabela ausente em testes / sem permissão → histórico vazio.
            return [];
        }
    }
}
