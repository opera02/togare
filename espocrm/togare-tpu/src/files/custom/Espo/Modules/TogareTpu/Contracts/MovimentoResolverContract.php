<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Contracts;

/**
 * Contrato público de lookup de Movimento processual CNJ.
 *
 * Análogo a `ClasseResolverContract` — ver doc lá.
 */
interface MovimentoResolverContract
{
    /**
     * @return array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}|null
     */
    public function resolveMovimento(int $codigo): ?array;
}
