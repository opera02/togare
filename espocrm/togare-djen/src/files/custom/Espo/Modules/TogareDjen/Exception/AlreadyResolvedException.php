<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Exception;

use DateTimeImmutable;
use RuntimeException;

/**
 * Race condition: PublicacaoAmbigua já foi resolvida por outro advogado quando
 * a 2ª request de resolve/ignore chegou (Story 4b.1b — AC3 / AC4 / AC12 mãe).
 *
 * Lançada por `AmbiguityResolverService` no início da transação se
 * `status != pendente_revisao`. Capturada pelo Controller `PublicacaoAmbigua`
 * e convertida para HTTP **409 Conflict** com mensagem pt-BR contendo o
 * timestamp de quem resolveu primeiro.
 *
 * Mensagem deliberadamente em pt-BR (UX flow F3 jornada Beatriz). i18n key
 * `messages.alreadyResolved` em `Resources/i18n/pt_BR/PublicacaoAmbigua.json`
 * é a fonte canônica — esta mensagem é fallback para logs e testes.
 */
final class AlreadyResolvedException extends RuntimeException
{
    public function __construct(
        public readonly DateTimeImmutable $decidedAt,
    ) {
        parent::__construct(
            'Esta publicação já foi resolvida por outro advogado em '
            . $decidedAt->format('d/m/Y H:i:s')
            . '. Sua tela vai atualizar.',
        );
    }
}
