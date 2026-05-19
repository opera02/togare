<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Health;

use Espo\Modules\TogareCore\Contracts\HealthCheckProviderContract;
use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;
use Throwable;

/**
 * Health do TPU — catálogo de assuntos/classes (Story 10.2, FR41 + NFR17 + W2).
 *
 * Só é instanciado quando `togare-tpu` está presente (premium opcional);
 * ausente → tile cinza "Não instalado" (AC1).
 *
 * **NFR17 (vinculante):** o TPU é servido do cache local (Redis TTL 30d); o
 * lookup NUNCA pode depender de chamada síncrona ao PDPJ. Logo este probe
 * **não faz rede**: lê, best-effort, o state-file do circuit breaker do
 * `PdpjAdapter` (quando um caminho compartilhado é configurado via
 * `TOGARE_TPU_CB_STATE_PATH`) para refletir o sinal `tpu.adapter.unavailable`
 * (deferred-work.md:268 — W2, escopo explícito de 10.2). Sem state-file legível
 * o tile é "Catálogo em cache" (saudável) — coerente com NFR17 (cache local é
 * o estado normal de operação). Nunca lança.
 */
final class TpuHealthProvider implements HealthCheckProviderContract
{
    public function __construct(private readonly ?string $cbStatePath)
    {
    }

    public function name(): string
    {
        return 'tpu';
    }

    public function check(): HealthCheckResult
    {
        try {
            $path = $this->cbStatePath;
            if ($path === null || $path === '' || ! \is_file($path)) {
                // Sem sinal de indisponibilidade legível: TPU opera do cache
                // local (NFR17) — estado normal e saudável.
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    'Catálogo em cache',
                );
            }

            $raw = @\file_get_contents($path);
            $state = \is_string($raw) ? \json_decode($raw, true) : null;
            if (! \is_array($state)) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_HEALTHY,
                    'Catálogo em cache',
                );
            }

            // Estado do CB do PdpjAdapter: 'open' quando o adapter está
            // indisponível (tpu.adapter.unavailable). O lookup do usuário
            // segue do cache — por isso degradado (amarelo), não vermelho:
            // sincronização do catálogo está instável, consulta ainda funciona.
            $cbState = (string) ($state['state'] ?? '');
            $failures = (int) ($state['failure_count'] ?? $state['failures'] ?? 0);

            if ($cbState === 'open' || $failures > 0) {
                return new HealthCheckResult(
                    HealthCheckResult::STATUS_DEGRADED,
                    'Sincronização instável — consultas usam o cache',
                    ['cbState' => $cbState, 'failures' => $failures],
                );
            }

            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                'Catálogo em cache',
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                HealthCheckResult::STATUS_HEALTHY,
                'Catálogo em cache',
                ['cause' => $e->getMessage()],
            );
        }
    }
}
