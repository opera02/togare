<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Mapeia `prioridade` (string enum) → `prioridade_weight` (int) em todo save
 * de Prazo (Story 4a.5, AC4 + Decisão #2 Plano C).
 *
 * **Por que coluna stored?** Ordenação por enum stored produz ordem alfabética
 * incorreta (alta < baixa < normal < urgente). A coluna `prioridade_weight`
 * permite `ORDER BY prioridade_weight DESC` natural (urgente=4 > alta=3 >
 * normal=2 > baixa=1) + indexação trivial — usado pelo boolFilter
 * `MeusPendentesPriorizados` no dashlet do BriefingDoDia.
 *
 * **Order = 10** (ANTES de AutoLinkClientHook=20 e AuditPrazoHook=50): mapping
 * simples sem dependência de outros hooks; valida cedo no pipeline.
 *
 * **Comportamento:**
 *  - Sempre seta `prioridadeWeight` baseado em `prioridade`.
 *  - Valor null/empty → weight=2 (default = normal); silencioso.
 *  - Valor inválido (não nos 4 valores) → weight=2 + warning log
 *    `prazo.prioridade.invalid_value`. Não bloqueia save (defensivo;
 *    ValidatePrazoFieldsHook order=10 deve rejeitar inválido antes,
 *    mas se passar, hook não cria erro silencioso de schema).
 *  - Idempotente: se `prioridade` não mudou, weight é re-derivado e
 *    sobrescrito com mesmo valor.
 *
 * @implements BeforeSave<Entity>
 */
final class PrioridadeWeightHook implements BeforeSave
{
    public static int $order = 10;

    /** Mapping canônico — espelhado no V012 backfill. */
    private const PRIORIDADE_WEIGHT_MAP = [
        Prazo::PRIORIDADE_URGENTE => 4,
        Prazo::PRIORIDADE_ALTA => 3,
        Prazo::PRIORIDADE_NORMAL => 2,
        Prazo::PRIORIDADE_BAIXA => 1,
    ];

    /** Default weight aplicado a null/empty/inválido (espelha enum default 'normal'). */
    private const DEFAULT_WEIGHT = 2;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Prazo) {
            return;
        }

        $prioridade = $entity->get('prioridade');

        if ($prioridade === null || $prioridade === '') {
            $entity->set('prioridadeWeight', self::DEFAULT_WEIGHT);
            return;
        }

        if (! \is_string($prioridade)) {
            // Valor não-string (ex.: int passado por engano) — defensivo.
            $entity->set('prioridadeWeight', self::DEFAULT_WEIGHT);
            TogareLogger::event(
                'warning',
                'prazo.prioridade.invalid_value',
                'PrioridadeWeightHook: prioridade não-string; usando weight default',
                ['value' => $prioridade, 'prazoId' => (string) ($entity->getId() ?? 'new')],
            );
            return;
        }

        if (! \array_key_exists($prioridade, self::PRIORIDADE_WEIGHT_MAP)) {
            $entity->set('prioridadeWeight', self::DEFAULT_WEIGHT);
            TogareLogger::event(
                'warning',
                'prazo.prioridade.invalid_value',
                'PrioridadeWeightHook: prioridade fora do enum canônico; usando weight default',
                ['value' => $prioridade, 'prazoId' => (string) ($entity->getId() ?? 'new')],
            );
            return;
        }

        $entity->set('prioridadeWeight', self::PRIORIDADE_WEIGHT_MAP[$prioridade]);
    }
}
