<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Exception;

use RuntimeException;

/**
 * Defesa contra request manipulado: `chosenProcessoId` não está em
 * `candidatos[].processoId` da PublicacaoAmbigua, OU `processoId` vazio
 * em `bulkIgnoreProcesso` (Story 4b.1b — AC3 / AC5 / Dev Note #7).
 *
 * Lançada por `AmbiguityResolverService::resolve()` antes de criar Prazo.
 * Capturada pelo Controller `PublicacaoAmbigua` e convertida para HTTP
 * **400 Bad Request** com mensagem pt-BR.
 *
 * Causa raiz típica: cliente JS desatualizado (cache stale) ou request
 * forjado. NUNCA é falha do backend — `candidatos` é snapshot imutável
 * gravado pela 4b.1a; mudança no Processo posterior não muda o snapshot.
 */
final class InvalidCandidateException extends RuntimeException
{
    public function __construct(string $message = 'O processo escolhido não está na lista de candidatos desta publicação.')
    {
        parent::__construct($message);
    }
}
