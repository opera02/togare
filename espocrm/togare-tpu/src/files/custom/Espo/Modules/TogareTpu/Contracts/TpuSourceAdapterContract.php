<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Contracts;

/**
 * Contrato de fonte de dados TPU (origem dos catálogos CNJ).
 *
 * Implementação default: `Services\PdpjAdapter` (consome
 * gateway.cloud.pje.jus.br/tpu via REST). Adapter pluggable permite trocar
 * fonte sem refatorar (Decisão #5 — `SgtAdapter` pode entrar em Growth se
 * gateway sair do ar).
 *
 * Uso de `iterable` permite implementação via generator (memória constante
 * para datasets grandes — adapter PDPJ pode paginar a API e fazer yield row a
 * row sem materializar tudo).
 */
interface TpuSourceAdapterContract
{
    /**
     * @return iterable<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}>
     */
    public function fetchClasses(): iterable;

    /**
     * @return iterable<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}>
     */
    public function fetchAssuntos(): iterable;

    /**
     * @return iterable<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}>
     */
    public function fetchMovimentos(): iterable;
}
