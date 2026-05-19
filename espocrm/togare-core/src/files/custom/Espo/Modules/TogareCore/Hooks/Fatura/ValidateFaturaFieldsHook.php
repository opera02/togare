<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Fatura;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Services\ContratoHonorariosLookupService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos da Fatura — Story 6.3 Decisões #2 (gate FR23 backend), #3
 * (numero auto-gerado), #4 (status computed), #9 (saldo derivado).
 *
 * Validações (na ordem):
 *  1. **GATE FR23 BACKEND (Decisão #2):** contratoHonorariosId obrigatório +
 *     contrato vigente via ContratoHonorariosLookupService::hasContratoVigente.
 *     **Defesa em profundidade** — Story 6.2 entrega banner UX inline; aqui
 *     enforça via Hook.
 *  2. Cliente herdado de contrato.cliente (auto-populado se vazio na criação).
 *  3. Processo cross-cliente bloqueado (se setado, deve pertencer ao mesmo
 *     cliente da Fatura).
 *  4. dataEmissao + dataVencimento obrigatórias; dataVencimento >= dataEmissao.
 *  5. valorBruto > 0.
 *  6. numero auto-gerado se NEW (formato YYYY-NNNN; sequência por ano + UNIQUE
 *     retry 1× se race condition).
 *  7. Campos imutáveis pós-isNew=false: numero, contratoHonorariosId,
 *     dataEmissao, clienteId.
 *  8. Status: só pode ser modificado via FaturaSaldoService (silent flag);
 *     bloqueia mutação direta via API REST.
 *  9. Recompute saldo quando valorBruto muda pós-isNew=false (delegado ao
 *     RecomputeFaturaSaldoHook indiretamente via flag, OR direto via Service —
 *     simplificação: deixa o caller registrar lançamento de acerto se
 *     necessário; mudança de valorBruto sem recompute deixa saldo
 *     desatualizado até o próximo lançamento — aceito MVP, audit captura).
 *  10. Fatura.status=cancelada: bloqueia qualquer mutação (exceto via Service
 *      silent flag).
 *  11. Inicializa valorPago=0 + saldo=valorBruto na criação.
 *
 * Order = 10 (executa antes de DefaultFaturaAssignment=15).
 *
 * @implements BeforeSave<Fatura>
 */
final class ValidateFaturaFieldsHook implements BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly ContratoHonorariosLookupService $contratoLookup,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Fatura) {
            return;
        }

        // Bypass para saves do FaturaSaldoService (silent flag) — recompute legítimo.
        if ($this->isFromRecompute($options)) {
            return;
        }

        if ($entity->isNew()) {
            $this->validateContratoConsistencia($entity);
            $this->inheritClienteFromContrato($entity);
            $this->validateProcessoCrossCliente($entity);
            $this->validateDatas($entity);
            $this->validateValorBruto($entity);
            $this->generateNumeroIfMissing($entity);
            $this->initializeValorPagoSaldo($entity);
            return;
        }

        $this->validateCanceladaNaoAceitaMudanca($entity);
        $this->validateImmutableFields($entity);
        $this->validateStatusMutation($entity);
        $this->validateProcessoCrossCliente($entity);
        $this->validateDatas($entity);
        $this->validateValorBruto($entity);
    }

    /**
     * Detecta save vindo do FaturaSaldoService (silent + _fromRecompute).
     */
    private function isFromRecompute(SaveOptions $options): bool
    {
        return $options->get('silent') === true && $options->get('_fromRecompute') === true;
    }

    /**
     * Story 6.2 — REINTERPRETAÇÃO de escopo (decisão de Felipe, autoridade de
     * domínio jurídico, 2026-05-16): a Fatura PODE ser emitida SEM
     * ContratoHonorarios. O Art. 22 §4º OAB NÃO exige contrato escrito —
     * contrato verbal é válido (apenas não é título executivo). O GateBanner
     * passa a ser APENAS INFORMATIVO; o software não decide consequências
     * jurídicas — o operador/escritório decide. Ver memória
     * feedback_oab_art22_contrato_verbal.
     *
     * Este método NÃO bloqueia mais. Mantém só uma checagem de integridade
     * referencial não-fatal: se um contratoHonorariosId foi informado mas
     * aponta para registro inexistente, limpa o FK pendente (evita FK órfão)
     * em vez de rejeitar a emissão.
     */
    private function validateContratoConsistencia(Fatura $entity): void
    {
        $contratoId = (string) ($entity->get('contratoHonorariosId') ?? '');
        if ($contratoId === '') {
            // Sem contrato → emissão permitida (banner é só informativo).
            return;
        }

        $contrato = $this->entityManager
            ->getRDBRepository(ContratoHonorarios::ENTITY_TYPE)
            ->getById($contratoId);

        if (! $contrato instanceof ContratoHonorarios) {
            // FK dangling → limpa em vez de bloquear a emissão.
            $entity->set('contratoHonorariosId', null);
        }
    }

    /**
     * Cliente da Fatura herda de contrato.cliente automaticamente quando
     * vazio na criação. Se setado, valida que bate com contrato.cliente.
     */
    private function inheritClienteFromContrato(Fatura $entity): void
    {
        $contratoId = (string) ($entity->get('contratoHonorariosId') ?? '');
        $contrato = $this->entityManager
            ->getRDBRepository(ContratoHonorarios::ENTITY_TYPE)
            ->getById($contratoId);

        if (! $contrato instanceof ContratoHonorarios) {
            return;
        }

        $clienteContrato = (string) ($contrato->get('clienteId') ?? '');
        if ($clienteContrato === '') {
            throw new BadRequest('Contrato de honorários está sem cliente vinculado — corrija o contrato.');
        }

        $clienteFatura = (string) ($entity->get('clienteId') ?? '');
        if ($clienteFatura === '') {
            $entity->set('clienteId', $clienteContrato);
            return;
        }

        if ($clienteFatura !== $clienteContrato) {
            throw new BadRequest('Cliente da fatura deve ser o mesmo do contrato de honorários.');
        }
    }

    private function validateProcessoCrossCliente(Fatura $entity): void
    {
        $processoId = (string) ($entity->get('processoId') ?? '');
        if ($processoId === '') {
            return;
        }

        $clienteId = (string) ($entity->get('clienteId') ?? '');
        if ($clienteId === '') {
            return;
        }

        // Busca pivot cliente_processo
        try {
            $stmt = $this->entityManager
                ->getQueryExecutor()
                ->execute(
                    $this->entityManager->getQueryBuilder()
                        ->select(['clienteId'])
                        ->from('ClienteProcesso')
                        ->where(['processoId' => $processoId])
                        ->build(),
                );
            $rows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            // Fail-closed: erro de query no guarda de cross-cliente → bloquear por segurança.
            throw new BadRequest('Não foi possível validar o processo vinculado. Tente novamente. (' . $e->getMessage() . ')');
        }

        if ($rows === []) {
            // Processo sem cliente — passa (improvável; mas não bloqueia defensivamente)
            return;
        }

        $clienteIds = \array_column($rows, 'clienteId');
        if (! \in_array($clienteId, $clienteIds, true)) {
            throw new BadRequest('O processo informado pertence a outro cliente.');
        }
    }

    private function validateDatas(Fatura $entity): void
    {
        $emissao = (string) ($entity->get('dataEmissao') ?? '');
        $vencimento = (string) ($entity->get('dataVencimento') ?? '');

        if ($emissao === '') {
            throw new BadRequest('Data de emissão é obrigatória.');
        }
        if ($vencimento === '') {
            throw new BadRequest('Data de vencimento é obrigatória.');
        }
        if ($vencimento < $emissao) {
            throw new BadRequest('Data de vencimento deve ser igual ou posterior à data de emissão.');
        }
    }

    private function validateValorBruto(Fatura $entity): void
    {
        $valor = (float) ($entity->get('valorBruto') ?? 0.0);
        if ($valor <= 0) {
            throw new BadRequest('Valor bruto deve ser maior que zero.');
        }
    }

    /**
     * Auto-gera numero no formato YYYY-NNNN. Sequência reinicia por ano.
     * UNIQUE index `numeroUnique` cobre race — retry 1× se race condition
     * produzir candidato já existente entre calcular e salvar (P1).
     * Scoped por tenantId quando não-null (P13 — multi-tenant ready).
     */
    private function generateNumeroIfMissing(Fatura $entity): void
    {
        $numero = (string) ($entity->get('numero') ?? '');
        if ($numero !== '') {
            return; // já setado (improvável; entityDefs marca readOnly)
        }

        $ano = (string) (\date('Y'));
        $emissao = (string) ($entity->get('dataEmissao') ?? '');
        if ($emissao !== '') {
            try {
                $ano = (new \DateTimeImmutable($emissao))->format('Y');
            } catch (\Throwable) {
                // mantém ano atual
            }
        }

        $tenantId = (string) ($entity->get('tenantId') ?? '') ?: null;

        $proxSeq = $this->calcularProximaSequencia($ano, $tenantId);
        $candidate = \sprintf('%s-%04d', $ano, $proxSeq);

        // Retry 1× se o candidato já existe. withDeleted(): a UNIQUE constraint
        // numeroUnique abrange linhas soft-deleted — número de fatura NÃO pode
        // ser reusado (integridade fiscal). Sem withDeleted, fatura deletada
        // "liberava" o número → Duplicate entry 1062 no INSERT.
        $repository = $this->entityManager->getRDBRepository(Fatura::ENTITY_TYPE);
        $existsQuery = SelectBuilder::create()
            ->from(Fatura::ENTITY_TYPE)
            ->withDeleted()
            ->where(['numero' => $candidate])
            ->build();
        $existing = $repository->clone($existsQuery)->findOne();
        if ($existing !== null) {
            $proxSeq++;
            $candidate = \sprintf('%s-%04d', $ano, $proxSeq);
        }

        $entity->set('numero', $candidate);
    }

    private function calcularProximaSequencia(string $ano, ?string $tenantId = null): int
    {
        $repository = $this->entityManager->getRDBRepository(Fatura::ENTITY_TYPE);

        $where = ['numero*' => $ano . '-%'];
        if ($tenantId !== null && $tenantId !== '') {
            $where['tenantId'] = $tenantId;
        }

        // withDeleted(): sequência considera TODAS as faturas (inclusive
        // soft-deleted) — número de fatura é monotônico e nunca reusado, mesmo
        // após exclusão (integridade fiscal/legal + evita Duplicate entry).
        $seqQuery = SelectBuilder::create()
            ->from(Fatura::ENTITY_TYPE)
            ->withDeleted()
            ->where($where)
            ->order('numero', 'DESC')
            ->limit(0, 1)
            ->build();
        $collection = $repository->clone($seqQuery)->find();

        $maxSeq = 0;
        foreach ($collection as $row) {
            $num = (string) $row->get('numero');
            $parts = \explode('-', $num);
            if (\count($parts) === 2 && \ctype_digit($parts[1])) {
                $maxSeq = (int) $parts[1];
            }
            break;
        }

        return $maxSeq + 1;
    }

    private function initializeValorPagoSaldo(Fatura $entity): void
    {
        if ($entity->get('valorPago') === null || (float) $entity->get('valorPago') === 0.0) {
            $entity->set('valorPago', 0.0);
        }

        $valorBruto = (float) ($entity->get('valorBruto') ?? 0.0);
        if ($entity->get('saldo') === null || (float) $entity->get('saldo') === 0.0) {
            $entity->set('saldo', $valorBruto);
        }

        if ((string) ($entity->get('status') ?? '') === '') {
            $entity->set('status', Fatura::STATUS_EMITIDA);
        }
    }

    private function validateCanceladaNaoAceitaMudanca(Fatura $entity): void
    {
        $statusAtual = (string) $entity->getFetched('status');
        if ($statusAtual !== Fatura::STATUS_CANCELADA) {
            return;
        }
        // Permite apenas update de motivoCancelamento; bloqueia qualquer outro
        // campo de negócio sensível mudando em uma fatura já cancelada.
        $bloquearMudancaEm = [
            'numero',
            'descricao',
            'status',
            'dataEmissao',
            'dataVencimento',
            'valorBruto',
            'valorPago',
            'saldo',
            'observacoes',
            'clienteId',
            'processoId',
            'contratoHonorariosId',
            'assignedUserId',
        ];
        foreach ($bloquearMudancaEm as $attr) {
            if ($entity->isAttributeChanged($attr)) {
                throw new BadRequest('Esta fatura está cancelada e não aceita novas operações.');
            }
        }
    }

    private function validateImmutableFields(Fatura $entity): void
    {
        // contratoHonorariosId NÃO é mais imutável (Story 6.2 reinterpretada):
        // o escritório pode vincular/ajustar o contrato depois da emissão.
        $imutaveis = ['numero', 'dataEmissao', 'clienteId'];
        foreach ($imutaveis as $field) {
            if ($entity->isAttributeChanged($field)) {
                $msgKey = match ($field) {
                    'numero' => 'Número da fatura não pode ser alterado após a emissão.',
                    'contratoHonorariosId' => 'Contrato de honorários não pode ser alterado após a emissão.',
                    'dataEmissao' => 'Data de emissão não pode ser alterada após a criação.',
                    'clienteId' => 'Cliente da fatura não pode ser alterado após a emissão.',
                    default => "Campo {$field} é imutável.",
                };
                throw new BadRequest($msgKey);
            }
        }
    }

    private function validateStatusMutation(Fatura $entity): void
    {
        if ($entity->isAttributeChanged('status')) {
            throw new BadRequest(
                'Status só pode ser alterado via registro de pagamento ou cancelamento.'
            );
        }
    }
}
