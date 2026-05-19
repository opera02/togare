<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Contracts;

/**
 * Contrato público de lookup de Classe processual CNJ.
 *
 * Consumido por Story 3.4 (entidade Processo) no `BeforeSave` para validar
 * `classeCodigo` contra catálogo TPU + denormalizar `classeNome`.
 *
 * Implementado por `Services\TpuCacheService` (cache-aside Redis + fallback DB).
 * Lookup é **somente leitura** — populado pelo sync mensal (`TpuSyncService`).
 */
interface ClasseResolverContract
{
    /**
     * Resolve uma classe processual pelo código CNJ.
     *
     * @return array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}|null
     *         null se o código não estiver no catálogo (próximo sync pode
     *         adicioná-lo — não cacheia o miss; AC6).
     */
    public function resolveClasse(int $codigo): ?array;
}
