<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Auto-vínculo defensivo de Cliente / ParteContraria quando o Prazo é
 * associado a um Processo (Story 4a.3.1 — F1.9 do feedback do smoke F1
 * da 4a.3; FR14 PRD v1.3.1).
 *
 * Decisão #4 da Story 4a.3.1 (alinhada a ADR-03 v1.1 §3):
 *  - Hook vive em **togare-core** (não embutido no PrazoCreatorService da
 *    togare-djen). Razão: aplica a TODO Prazo (DJEN + manual UI futuro +
 *    CSV import futuro), não apenas ao caminho DJEN.
 *  - Lê `processo.clientes` e `processo.partesContrarias` (links N:N
 *    declarados em Stories 3.1/3.2/3.4).
 *  - Comportamento N:N defensivo (FR14 PRD v1.3.1):
 *      0 clientes/partes  → deixa NULL + log info (skip)
 *      1 cliente/parte    → seta automaticamente + log info (assigned)
 *      2+ clientes/partes → deixa NULL + log info (skip — UI exige
 *                           seleção manual com hint visual; hook NÃO
 *                           infere preferência)
 *  - Idempotência: se `clienteId` ou `parteContrariaId` já está SETADO
 *    (manual ou pré-existente), hook NÃO sobrepõe.
 *  - Trigger: só dispara quando `processoId` está sendo SETADO neste save
 *    (`isAttributeChanged('processoId') === true` OU `isNew() && processoId
 *    !== null`); para saves que não tocam processo, hook é no-op.
 *
 * Order = 20 (entre Validate=10 e Audit=50): permite que ValidatePrazoFieldsHook
 * (validate dates + status×campos + motivoReagendamento) rode primeiro;
 * AutoLinkClient corre depois com payload já saneado; Audit registra final state.
 *
 * Try/catch `\Throwable` em torno de leituras de link defensivo — auto-link
 * NUNCA pode bloquear save (mesmo princípio do AuditPrazoHook).
 *
 * @implements BeforeSave<Entity>
 */
final class AutoLinkClientHook implements BeforeSave
{
    public static int $order = 20;

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Prazo) {
            return;
        }

        // Pré-condição: precisa ter Processo associado pra puxar cliente/parteContraria.
        $processoId = $entity->get('processoId');
        if ($processoId === null || $processoId === '') {
            return;
        }

        // Story 4a.3.1 smoke F1 fix: dispara em TODA save (não só create/processoId-change).
        // Se cliente/parteContraria já estão setados, autoLinkSingle skipa por idempotência.
        // Otimização: early-return se ambos já setados — evita 1 query no Processo.
        $hasCliente = ($entity->get('clienteId') ?? '') !== '';
        $hasParte = ($entity->get('parteContrariaId') ?? '') !== '';
        if ($hasCliente && $hasParte) {
            return;
        }

        $prazoId = (string) ($entity->getId() ?? 'new');

        try {
            $processo = $this->entityManager->getEntityById('Processo', (string) $processoId);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'prazo.auto_link.processo_load_failed',
                'AutoLinkClientHook: falha ao carregar Processo',
                ['processoId' => (string) $processoId, 'prazoId' => $prazoId, 'error' => $e->getMessage()],
            );
            return;
        }

        if ($processo === null) {
            // Processo não existe — ValidatePrazoFieldsHook não bloqueia link nulo
            // mas defensivo aqui também.
            return;
        }

        $this->autoLinkSingle(
            $entity,
            $processo,
            'clientes',
            'clienteId',
            'cliente',
            $prazoId,
            (string) $processoId,
        );

        $this->autoLinkSingle(
            $entity,
            $processo,
            'partesContrarias',
            'parteContrariaId',
            'parte_contraria',
            $prazoId,
            (string) $processoId,
        );
    }

    /**
     * Aplica heurística N:N defensiva para 1 link específico (cliente OU parteContraria).
     *
     * @param non-empty-string $linkName        Nome do link N:N no Processo (clientes / partesContrarias)
     * @param non-empty-string $idAttributeName Atributo no Prazo a setar (clienteId / parteContrariaId)
     * @param non-empty-string $logKindLabel    Discriminador no log (cliente / parte_contraria)
     */
    private function autoLinkSingle(
        Prazo $prazo,
        Entity $processo,
        string $linkName,
        string $idAttributeName,
        string $logKindLabel,
        string $prazoId,
        string $processoId,
    ): void {
        // Idempotência: se já está SETADO no Prazo, NÃO sobrepõe.
        $existingId = $prazo->get($idAttributeName);
        if ($existingId !== null && $existingId !== '') {
            return;
        }

        try {
            $relatedCollection = $processo->get($linkName);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'prazo.auto_link.' . $logKindLabel . '_load_failed',
                'AutoLinkClientHook: falha ao ler link no Processo',
                ['link' => $linkName, 'processoId' => $processoId, 'prazoId' => $prazoId, 'error' => $e->getMessage()],
            );
            return;
        }

        // Coletar IDs do Collection — defensivo contra null/array/Iterable.
        $ids = $this->collectIds($relatedCollection);
        $count = \count($ids);

        if ($count === 0) {
            TogareLogger::event(
                'info',
                'prazo.auto_link.' . $logKindLabel . '_skipped',
                'AutoLinkClientHook: nenhum ' . $logKindLabel . ' vinculado ao Processo — Prazo fica sem auto-link',
                ['reason' => 'no_' . $logKindLabel . '_in_processo', 'processoId' => $processoId, 'prazoId' => $prazoId],
            );
            return;
        }

        if ($count > 1) {
            TogareLogger::event(
                'info',
                'prazo.auto_link.' . $logKindLabel . '_skipped',
                'AutoLinkClientHook: múltiplos ' . $logKindLabel . ' no Processo — UI deve exigir seleção manual',
                [
                    'reason' => 'multiple_' . $logKindLabel . '_in_processo',
                    'processoId' => $processoId,
                    'prazoId' => $prazoId,
                    'count' => $count,
                ],
            );
            return;
        }

        // Caso ideal: exatamente 1.
        $singleId = $ids[0];
        $prazo->set($idAttributeName, $singleId);

        TogareLogger::event(
            'info',
            'prazo.auto_link.' . $logKindLabel . '_assigned',
            'AutoLinkClientHook: ' . $logKindLabel . ' auto-vinculado a partir do Processo',
            [
                $idAttributeName => $singleId,
                'processoId' => $processoId,
                'prazoId' => $prazoId,
            ],
        );
    }

    /**
     * Coleta IDs de um Collection / array de Entity — defensivo contra null e tipos
     * heterogêneos. Pattern usado em outros hooks AfterSave do togare-core.
     *
     * @param mixed $collection EntityCollection, array, null ou iterable.
     * @return list<string>
     */
    private function collectIds(mixed $collection): array
    {
        if ($collection === null) {
            return [];
        }

        $ids = [];

        if (\is_iterable($collection)) {
            foreach ($collection as $item) {
                if ($item instanceof Entity) {
                    $id = $item->getId();
                    if (\is_string($id) && $id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }

        return $ids;
    }
}
