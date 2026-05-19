<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

use Espo\Modules\TogareCore\Contracts\ValueObject\HealthCheckResult;

/**
 * Provider de health check — cada subsistema (DJEN, TPU, Nextcloud, Backup,
 * Licensing, etc.) registra sua própria implementação.
 *
 * Contrato: check() é síncrono, barato (não bloqueante por mais de ~2s) e
 * nunca lança exceção — falha é representada como HealthCheckResult com
 * status 'unhealthy'. O registry de providers fica em togare-core e é
 * consumido pelo endpoint /api/v1/togare/health (Epic 10).
 */
interface HealthCheckProviderContract
{
    /**
     * Identificador do provider (ex.: 'djen', 'nextcloud', 'backup').
     * Usado como chave no painel administrativo.
     */
    public function name(): string;

    /**
     * Executa o check. Nunca lança; falha vira status='unhealthy' com mensagem
     * pt-BR acionável.
     */
    public function check(): HealthCheckResult;
}
