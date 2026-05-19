<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Audiencia;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Audiencia;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos intrínsecos da entidade Audiencia (Story 3.6-magro, FR16).
 *
 * Defesa em profundidade — framework EspoCRM já valida `required` e enum,
 * mas hook bloqueia se algum campo for setado fora-banda (ex.: API client
 * que envia POST com payload inválido):
 *
 *  - Enums obrigatórios em allowlist: tipo, modalidade, status.
 *  - dataHora não vazio (defensivo, já é required).
 *  - processoId não vazio (defensivo, já é required).
 *  - duracaoMinutos entre 15 e 480 quando preenchido.
 *
 * Mensagens em pt-BR via `BadRequest::createWithBody` com payload JSON
 * `{field, reason, message}` para o frontend EspoCRM exibir corretamente
 * (mesmo padrão Cliente/ParteContraria/Processo).
 *
 * Order $order = 10 — roda DEPOIS de EnforceAudienciaAssignmentHook (5)
 * e ANTES de AuditAudienciaHook (50).
 *
 * @implements BeforeSave<Entity>
 */
final class ValidateAudienciaFieldsHook implements BeforeSave
{
    public static int $order = 10;

    private const VALID_TIPOS = [
        Audiencia::TIPO_CONCILIACAO,
        Audiencia::TIPO_INSTRUCAO_JULGAMENTO,
        Audiencia::TIPO_JULGAMENTO,
        Audiencia::TIPO_UNA,
        Audiencia::TIPO_CONCILIACAO_MEDIACAO,
        Audiencia::TIPO_OUTRAS,
    ];

    private const VALID_MODALIDADES = [
        Audiencia::MODALIDADE_PRESENCIAL,
        Audiencia::MODALIDADE_VIRTUAL,
        Audiencia::MODALIDADE_HIBRIDA,
    ];

    private const VALID_STATUSES = [
        Audiencia::STATUS_AGENDADA,
        Audiencia::STATUS_REALIZADA,
        Audiencia::STATUS_CANCELADA,
        Audiencia::STATUS_ADIADA,
    ];

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Audiencia) {
            return;
        }

        $this->validateRequiredAttribute($entity, 'dataHora', 'Data e hora da audiência são obrigatórias.');
        $this->validateRequiredAttribute($entity, 'processoId', 'Audiência precisa estar vinculada a um processo.', 'processo');

        $this->validateEnum($entity, 'tipo', self::VALID_TIPOS, 'Tipo inválido — escolha uma das opções.');
        $this->validateEnum($entity, 'modalidade', self::VALID_MODALIDADES, 'Modalidade inválida — escolha uma das opções.');
        $this->validateEnum($entity, 'status', self::VALID_STATUSES, 'Status inválido — escolha uma das opções.');

        $this->validateDuracao($entity);
    }

    /**
     * @param non-empty-string $attribute
     * @param non-empty-string $message
     */
    private function validateRequiredAttribute(
        Audiencia $entity,
        string $attribute,
        string $message,
        ?string $reportField = null,
    ): void {
        // só checa se é create OU se o atributo foi alterado nesta save
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
    private function validateEnum(Audiencia $entity, string $field, array $valid, string $message): void
    {
        $value = $entity->get($field);
        if ($value === null || $value === '') {
            // required é tratado pelo framework — aqui só rejeita valores fora da allowlist
            return;
        }
        if (! \in_array($value, $valid, true)) {
            $this->fail($field, $message);
        }
    }

    private function validateDuracao(Audiencia $entity): void
    {
        $value = $entity->get('duracaoMinutos');
        if ($value === null || $value === '') {
            return;
        }
        if (! \is_int($value) && ! (\is_string($value) && \ctype_digit($value))) {
            $this->fail('duracaoMinutos', 'Duração deve estar entre 15 e 480 minutos.');
        }
        $duracao = (int) $value;
        if ($duracao < Audiencia::DURACAO_MIN_MINUTOS || $duracao > Audiencia::DURACAO_MAX_MINUTOS) {
            $this->fail('duracaoMinutos', 'Duração deve estar entre 15 e 480 minutos.');
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
            'audiencia.validation.failed',
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
