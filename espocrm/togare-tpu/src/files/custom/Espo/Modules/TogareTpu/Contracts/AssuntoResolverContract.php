<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Contracts;

/**
 * Contrato público de lookup de Assunto processual CNJ.
 *
 * Análogo a `ClasseResolverContract` — ver doc lá. Consumido por Story 3.4.
 */
interface AssuntoResolverContract
{
    /**
     * @return array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}|null
     */
    public function resolveAssunto(int $codigo): ?array;
}
