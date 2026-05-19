<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Prazo;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Default `dataCumprimento = dataFatal − 2 dias úteis` em CRIAÇÃO de Prazo
 * (Story 4a.5.1, AC3 + Decisão #2).
 *
 * **Por que coluna stored com hook (não cálculo SQL ou JS edit form)?**
 *  - SQL não conhece dias úteis brasileiros (precisa de `BrazilianBusinessCalendar`).
 *  - JS edit form só cobriria criação manual via UI; PrazoCreatorService da
 *    togare-djen ficaria sem default (caminho automático DJEN).
 *  - Hook BeforeSave em togare-core cobre TODOS os caminhos (DJEN + UI manual
 *    + futuros CSV imports).
 *
 * **Order = 15** (entre `ValidatePrazoFieldsHook=10` / `PrioridadeWeightHook=10`
 * e `AutoLinkClientHook=20` e `AuditPrazoHook=50`):
 *  - Validate=10 garante que `dataFatal` é não-vazio.
 *  - Default=15 deriva.
 *  - AutoLink=20 e Audit=50 rodam sobre payload completo (incluindo
 *    `dataCumprimento` recém-derivada).
 *
 * **Comportamento:**
 *  - Só dispara em `isNew()` — edits NÃO sobrescrevem `dataCumprimento`.
 *    Se advogado editar `dataFatal`, ele decide manualmente se atualiza
 *    `dataCumprimento` também.
 *  - Respeita override manual: se `dataCumprimento` JÁ está setado (mesmo
 *    em isNew), hook NÃO sobrescreve.
 *  - Bail silencioso se `dataFatal` vazio/non-string (Validate=10 já bloqueou;
 *    defesa em profundidade).
 *  - Try/catch \Throwable: nunca bloqueia save; loga warning em failure.
 *
 * @implements BeforeSave<Entity>
 */
final class DefaultDataCumprimentoHook implements BeforeSave
{
    public static int $order = 15;

    /**
     * Margem de segurança operacional default (Decisão #2 da Story 4a.5.1).
     * Futuro Epic 10 pode promover para Settings se feedback do piloto pedir.
     */
    private const DEFAULT_OFFSET_BUSINESS_DAYS = 2;

    private const TZ = 'America/Sao_Paulo';

    public function __construct(
        private readonly BrazilianBusinessCalendar $calendar,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Prazo) {
            return;
        }

        // Decisão #2 — só dispara em criação. Edits NÃO sobrescrevem.
        if (! $entity->isNew()) {
            return;
        }

        // Decisão #2 — respeita override manual (mesmo em isNew, advogado
        // pode preencher dataCumprimento explicitamente no form).
        if ($entity->get('dataCumprimento') !== null) {
            return;
        }

        $dataFatal = $entity->get('dataFatal');
        if (! \is_string($dataFatal) || $dataFatal === '') {
            // Validate=10 já bloqueia esse caso; defensivo.
            return;
        }

        try {
            $start = new DateTimeImmutable($dataFatal, new DateTimeZone(self::TZ));
            $default = $this->calendar->subtractBusinessDays(
                $start,
                self::DEFAULT_OFFSET_BUSINESS_DAYS,
            );
            $entity->set('dataCumprimento', $default->format('Y-m-d'));
        } catch (\Throwable $e) {
            // Defensivo: nunca bloquear save por bug no calendar ou parse de data.
            TogareLogger::event(
                'warning',
                'prazo.dataCumprimento.default_failed',
                'DefaultDataCumprimentoHook: falha ao calcular default; deixando NULL',
                [
                    'prazoId' => (string) ($entity->getId() ?? 'new'),
                    'dataFatal' => $dataFatal,
                    'exception' => $e->getMessage(),
                ],
            );
        }
    }
}
