<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge\Exception;

use RuntimeException;
use Throwable;

/**
 * Sinaliza que o Nextcloud está indisponível para operações WebDAV/OCS —
 * adapter esgotou os retries OU circuit breaker está aberto.
 *
 * `getMessage()` retorna SEMPRE pt-BR fixo, seguro para mostrar a usuário
 * final (AC11 da Story 5.1). Detalhes técnicos para log via
 * `getDetailedMessage()`.
 */
final class NextcloudUnavailableException extends RuntimeException
{
    /** @var array<string, mixed> */
    private readonly array $context;

    /**
     * @param array{cause?: string, attempt?: int, cbOpenUntil?: int, statusCode?: int} $context
     */
    public function __construct(array $context = [], ?Throwable $previous = null)
    {
        $this->context = $context;
        parent::__construct(
            'Nextcloud indisponível. Tente novamente em alguns minutos.',
            0,
            $previous,
        );
    }

    /**
     * Mensagem detalhada para logs estruturados — inclui causa, tentativa
     * atual e tempo até CB fechar.
     */
    public function getDetailedMessage(): string
    {
        $parts = ['Nextcloud unavailable'];
        if (isset($this->context['cause'])) {
            $parts[] = 'cause=' . $this->context['cause'];
        }
        if (isset($this->context['attempt'])) {
            $parts[] = 'attempt=' . $this->context['attempt'];
        }
        if (isset($this->context['statusCode'])) {
            $parts[] = 'status=' . $this->context['statusCode'];
        }
        if (isset($this->context['cbOpenUntil']) && $this->context['cbOpenUntil'] > 0) {
            $parts[] = 'cb_open_until=' . \date('c', (int) $this->context['cbOpenUntil']);
        }

        return implode(' | ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
