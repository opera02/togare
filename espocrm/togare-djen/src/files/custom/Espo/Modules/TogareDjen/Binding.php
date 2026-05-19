<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Container;
use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\Modules\TogareDjen\Contracts\PublicationSourceAdapterContract;
use Espo\Modules\TogareDjen\Services\DjenAdapter;
use Espo\ORM\EntityManager;

/**
 * DI bindings do módulo TogareDjen.
 *
 * Carregado automaticamente pelo EspoBindingLoader via convenção de nome
 * `Espo\Modules\<ModuleName>\Binding`. Resolve PublicationSourceAdapterContract
 * → DjenAdapter (Decisão #2 da Story 4a.1 — contract pluggable atende NFR24).
 *
 * Sem este binding, services/jobs que recebem PublicationSourceAdapterContract
 * via type-hint no construtor quebram com "Class 'PublicationSourceAdapterContract'
 * does not exist" (interface não pode ser instanciada via createInternal — ver
 * Binding do togare-tpu Story 3.3 para o mesmo padrão).
 *
 * Story 4a.6 (Decisão #5 — fallback aplicado): smoke F1 confirmou que o
 * autowire silencioso da Espo InjectableFactory NÃO resolve `?RateLimiter
 * $rateLimiter = null` (4º param do construtor do DjenAdapter). Sem este
 * `bindCallback` no contexto da DjenAdapter, o gate de rate-limit ficaria
 * desativado em produção sem aviso. O callback constrói RateLimiter
 * explicitamente passando o EntityManager do container.
 *
 * API canônica do ContextualBinder (EspoCRM 9.x — verificada em
 * application/Espo/Core/Binding/ContextualBinder.php):
 * bindImplementation, bindService, bindValue, bindInstance, bindCallback,
 * bindFactory. NÃO existe `bindParameter` — bindCallback é o caminho oficial
 * para resolver dependências por type-hint no construtor de uma classe alvo.
 */
final class Binding implements BindingProcessor
{
    public function process(Binder $binder): void
    {
        $binder->bindImplementation(PublicationSourceAdapterContract::class, DjenAdapter::class);

        // Story 4a.6 — força injeção do RateLimiter no 4º param do construtor
        // do DjenAdapter (autowire silencioso falhou em smoke F1 quando o
        // param é nullable+optional com default null).
        $binder->for(DjenAdapter::class)
            ->bindCallback(
                RateLimiter::class,
                static fn (Container $container): RateLimiter => new RateLimiter(
                    $container->getByClass(EntityManager::class),
                ),
            );
    }
}
