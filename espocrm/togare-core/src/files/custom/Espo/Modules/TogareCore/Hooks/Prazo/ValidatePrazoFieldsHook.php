<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos intrínsecos da entidade Prazo (Story 4a.3 + 4a.3.1 — FR12+FR13+FR14).
 *
 * Defesa em profundidade — framework EspoCRM já valida `required` e enum,
 * mas hook bloqueia quando algum campo for setado fora-banda (ex.: API client
 * que envia POST com payload inválido OU PrazoCreatorService da togare-djen
 * com bug que set status='pendente' sem processoId):
 *
 *  - Enums em allowlist: status (9 valores: 8 visíveis + descartado oculto técnico),
 *    contagem, confidence, source, prioridade.
 *  - dataDisponibilizacao / dataInicioPrazo / dataFatal não vazios em create.
 *  - prazoDias entre 1 e 365.
 *  - dataFatal >= dataDisponibilizacao + dataInicioPrazo >= dataDisponibilizacao
 *    + dataInicioPrazo <= dataFatal (sanity).
 *  - status='pendente' EXIGE processoId NOT NULL E assignedUserId NOT NULL
 *    (caminho match do PrazoCreatorService).
 *  - status='rascunho' EXIGE numeroProcessoOriginal NOT NULL
 *    (preserva CNJ original quando sem match — renomeado de rascunho_nao_vinculado
 *    em Story 4a.3.1).
 *  - status='atrasado_reagendado' EXIGE motivoReagendamento NOT NULL e
 *    mb_strlen(trim) >= 10 chars (Decisão #2 da 4a.3.1 — sanity guard
 *    contra "x"/"OK"/espaços).
 *
 * Mensagens em pt-BR via `BadRequest::createWithBody` com payload JSON
 * `{field, reason, message}` (mesmo padrão Cliente/ParteContraria/Audiencia).
 *
 * Order $order = 10 — pattern Audiencia 3.6-magro.
 *
 * @implements BeforeSave<Entity>
 */
final class ValidatePrazoFieldsHook implements BeforeSave
{
    public static int $order = 10;

    /** Story 4a.3.1 — 9 valores (8 visíveis + descartado técnico oculto). */
    private const VALID_STATUSES = [
        Prazo::STATUS_RASCUNHO,
        Prazo::STATUS_PENDENTE,
        Prazo::STATUS_REAGENDADO,
        Prazo::STATUS_AGUARDANDO_CLIENTE,
        Prazo::STATUS_AGUARDANDO_CORRECAO,
        Prazo::STATUS_PROTOCOLADO,
        Prazo::STATUS_CIENCIA_RENUNCIA,
        Prazo::STATUS_ACOMPANHAMENTO,
        Prazo::STATUS_DESCARTADO,
    ];

    private const VALID_CONTAGENS = [
        Prazo::CONTAGEM_UTEIS,
        Prazo::CONTAGEM_CORRIDOS,
    ];

    private const VALID_CONFIDENCES = [
        Prazo::CONFIDENCE_HIGH,
        Prazo::CONFIDENCE_MEDIUM,
        Prazo::CONFIDENCE_LOW,
    ];

    private const VALID_SOURCES = [
        Prazo::SOURCE_DJEN,
        Prazo::SOURCE_MANUAL,
        Prazo::SOURCE_MANUAL_AMBIGUO,
    ];

    /** Story 4a.3.1 — 4 prioridades canônicas (F1.11). */
    private const VALID_PRIORIDADES = [
        Prazo::PRIORIDADE_BAIXA,
        Prazo::PRIORIDADE_NORMAL,
        Prazo::PRIORIDADE_ALTA,
        Prazo::PRIORIDADE_URGENTE,
    ];

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Prazo) {
            return;
        }

        $this->validateRequiredAttribute($entity, 'dataDisponibilizacao', 'Data de disponibilização é obrigatória.');
        $this->validateRequiredAttribute($entity, 'dataInicioPrazo', 'Data de início da contagem é obrigatória.');
        $this->validateRequiredAttribute($entity, 'dataFatal', 'Data fatal do prazo é obrigatória.');

        $this->validateEnum($entity, 'status', self::VALID_STATUSES, 'Status inválido — escolha uma das opções.');
        $this->validateEnum($entity, 'contagem', self::VALID_CONTAGENS, "Tipo de contagem inválido — use 'úteis' ou 'corridos'.");
        $this->validateEnum($entity, 'confidence', self::VALID_CONFIDENCES, "Confiança inválida — use 'high', 'medium' ou 'low'.");
        $this->validateEnum($entity, 'source', self::VALID_SOURCES, "Origem inválida — use 'djen', 'manual' ou 'manual_ambiguo'.");
        $this->validateEnum($entity, 'prioridade', self::VALID_PRIORIDADES, 'Prioridade inválida — escolha entre baixa, normal, alta ou urgente.');

        $this->validatePrazoDias($entity);
        $this->validateDateOrdering($entity);
        $this->validateStatusRequirements($entity);
        $this->validateMotivoReagendamento($entity);
    }

    /**
     * @param non-empty-string $attribute
     * @param non-empty-string $message
     */
    private function validateRequiredAttribute(
        Prazo $entity,
        string $attribute,
        string $message,
        ?string $reportField = null,
    ): void {
        if (! $entity->isNew() && ! $entity->isAttributeChanged($attribute)) {
            return;
        }

        $value = $entity->get($attribute);
        if ($value === null || $value === '' || $value === '0') {
            $this->fail($reportField ?? $attribute, $message);
        }
    }

    /**
     * @param non-empty-string $field
     * @param list<string> $valid
     * @param non-empty-string $message
     */
    private function validateEnum(Prazo $entity, string $field, array $valid, string $message): void
    {
        $value = $entity->get($field);
        if ($value === null || $value === '') {
            return;
        }
        if (! \in_array($value, $valid, true)) {
            $this->fail($field, $message);
        }
    }

    private function validatePrazoDias(Prazo $entity): void
    {
        $value = $entity->get('prazoDias');
        if ($value === null || $value === '') {
            if ($entity->isNew()) {
                $this->fail('prazoDias', 'Prazo em dias é obrigatório.');
            }
            return;
        }
        if (! \is_int($value) && ! (\is_string($value) && \ctype_digit((string) $value))) {
            $this->fail('prazoDias', 'Prazo em dias deve ser inteiro entre 1 e 365.');
        }
        $dias = (int) $value;
        if ($dias < Prazo::PRAZO_DIAS_MIN || $dias > Prazo::PRAZO_DIAS_MAX) {
            $this->fail('prazoDias', 'Prazo em dias deve estar entre 1 e 365.');
        }
    }

    private function validateDateOrdering(Prazo $entity): void
    {
        $disp = $entity->get('dataDisponibilizacao');
        $inicio = $entity->get('dataInicioPrazo');
        $fatal = $entity->get('dataFatal');

        if (\is_string($disp) && \is_string($fatal) && $disp !== '' && $fatal !== '' && $fatal < $disp) {
            $this->fail('dataFatal', 'Data fatal não pode ser anterior à data de disponibilização.');
        }
        if (\is_string($disp) && \is_string($inicio) && $disp !== '' && $inicio !== '' && $inicio < $disp) {
            $this->fail('dataInicioPrazo', 'Data de início da contagem não pode ser anterior à data de disponibilização.');
        }
        if (\is_string($inicio) && \is_string($fatal) && $inicio !== '' && $fatal !== '' && $inicio > $fatal) {
            $this->fail('dataInicioPrazo', 'Data de início da contagem não pode ser posterior à data fatal.');
        }
    }

    private function validateStatusRequirements(Prazo $entity): void
    {
        $status = $entity->get('status');

        if ($status === Prazo::STATUS_PENDENTE) {
            $processoId = $entity->get('processoId');
            if ($processoId === null || $processoId === '') {
                $this->fail('processo', 'Prazo pendente precisa estar vinculado a um processo.');
            }
            $assignedUserId = $entity->get('assignedUserId');
            if ($assignedUserId === null || $assignedUserId === '') {
                $this->fail('assignedUser', 'Prazo pendente precisa ter um advogado responsável.');
            }
            return;
        }

        if ($status === Prazo::STATUS_RASCUNHO) {
            $numero = $entity->get('numeroProcessoOriginal');
            if ($numero === null || $numero === '') {
                $this->fail(
                    'numeroProcessoOriginal',
                    'Rascunho de prazo precisa preservar o número do processo da publicação original.',
                );
            }
        }
    }

    /**
     * Story 4a.3.1 — Decisão #2: motivoReagendamento obrigatório com sanity ≥10 chars
     * quando status = atrasado_reagendado. Trim aplicado para rejeitar espaços puros.
     */
    private function validateMotivoReagendamento(Prazo $entity): void
    {
        $status = $entity->get('status');
        if ($status !== Prazo::STATUS_REAGENDADO) {
            return;
        }

        $motivo = $entity->get('motivoReagendamento');
        if (! \is_string($motivo)) {
            $this->fail(
                'motivoReagendamento',
                'Para mudar para Atrasado/Reagendado, informe o motivo do reagendamento (mínimo 10 caracteres).',
            );
        }

        $trimmed = \trim($motivo);
        if (\mb_strlen($trimmed) < Prazo::MOTIVO_REAGENDAMENTO_MIN_LEN) {
            $this->fail(
                'motivoReagendamento',
                'Para mudar para Atrasado/Reagendado, informe o motivo do reagendamento (mínimo 10 caracteres).',
            );
        }
    }

    /**
     * @param non-empty-string $field
     * @param non-empty-string $message
     */
    private function fail(string $field, string $message): never
    {
        TogareLogger::event(
            'warning',
            'prazo.validation.failed',
            $message,
            [
                'field' => $field,
                'reason' => 'invalid',
            ],
        );

        throw BadRequest::createWithBody(
            $message,
            (string) \json_encode(
                ['field' => $field, 'reason' => 'invalid', 'message' => $message],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
