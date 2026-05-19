<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

/**
 * Constantes do rate-limit DJEN (Story 4a.6).
 *
 * Contrato vinculante (AC1 do epics):
 *   - LIMIT = 30 requests / WINDOW_SECONDS = 60s contra Comunica API.
 *   - Sleep cap = 90s (1.5× janela — permite ≥1 reset completo durante espera).
 *
 * Pattern espelhado em AuthRateLimitConfig (togare-rbac, Story 2.5):
 * classe `final` privada de constantes evita drift de valores entre múltiplos
 * consumers (ex.: DjenAdapter agora; DjenWindowEnqueuer no futuro se quiser
 * peek() para health check sem incrementar contador).
 *
 * Chave global por módulo (NÃO por advogado): o limite é da Comunica contra
 * o IP do Togare; todos os advogados compartilham a mesma cota.
 */
final class DjenRateLimitConfig
{
    public const RATE_KEY = 'djen:comunica-api';
    public const LIMIT = 30;
    public const WINDOW_SECONDS = 60;
    public const CAP_SECONDS = 90;

    private function __construct()
    {
    }
}
