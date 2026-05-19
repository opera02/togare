<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Modules\TogareTpu\Contracts\AssuntoResolverContract;
use Espo\Modules\TogareTpu\Contracts\ClasseResolverContract;
use Espo\Modules\TogareTpu\Contracts\MovimentoResolverContract;
use Espo\Modules\TogareTpu\Contracts\TpuSourceAdapterContract;
use Espo\Modules\TogareTpu\Services\PdpjAdapter;
use Espo\Modules\TogareTpu\Services\TpuCacheService;

/**
 * DI bindings do módulo TogareTpu.
 *
 * Carregado automaticamente pelo EspoBindingLoader via convenção de nome
 * `Espo\Modules\<ModuleName>\Binding`. Resolve os 3 ResolverContracts
 * (Classe/Assunto/Movimento) → TpuCacheService e
 * TpuSourceAdapterContract → PdpjAdapter (Decisão #1 item 8 do Story 3.3).
 *
 * Sem este binding, hooks/services que recebem qualquer contract destes via
 * type-hint no construtor quebram com "Class 'XContract' does not exist"
 * (interface não pode ser instanciada via createInternal — ver Binding do
 * togare-core para o mesmo padrão).
 */
final class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->bindImplementation(ClasseResolverContract::class, TpuCacheService::class);
        $binder->bindImplementation(AssuntoResolverContract::class, TpuCacheService::class);
        $binder->bindImplementation(MovimentoResolverContract::class, TpuCacheService::class);
        $binder->bindImplementation(TpuSourceAdapterContract::class, PdpjAdapter::class);
    }
}
