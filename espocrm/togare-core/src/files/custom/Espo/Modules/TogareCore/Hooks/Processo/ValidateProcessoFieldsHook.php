<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Processo;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos intrínsecos da entidade Processo (Story 3.4, FR7).
 *
 * Defesa em profundidade — framework EspoCRM já valida `required` e enum,
 * mas hook bloqueia se algum campo for setado fora-banda (ex.: API client
 * que envia POST com payload inválido):
 *
 *  - Enums obrigatórios em allowlist: area, instancia, fase, status, polo.
 *  - Inteiros positivos: classeCodigo > 0, assuntoCodigo > 0, movimentoCodigo
 *    (se preenchido) > 0.
 *  - valorCausa >= 0 (não permite negativo).
 *  - dataAutuacao >= dataDistribuicao quando ambos preenchidos.
 *
 * NÃO valida lookup TPU — esse é responsabilidade de `ResolveTpuFieldsHook`
 * em togare-tpu (Decisão #3 da story; sem ciclo de dep).
 *
 * Mensagens em pt-BR via `BadRequest::createWithBody` com payload JSON
 * `{field, reason, message}` para o frontend EspoCRM exibir corretamente.
 *
 * Order $order = 20 — roda DEPOIS de NormalizeCnjNumberHook (10) e ANTES de
 * ResolveTpuFieldsHook (30, em togare-tpu) e AuditProcessoHook (50).
 *
 * @implements BeforeSave<Entity>
 */
final class ValidateProcessoFieldsHook implements BeforeSave
{
    public static int $order = 20;

    private const VALID_AREAS = [
        Processo::AREA_CIVEL,
        Processo::AREA_CRIMINAL,
        Processo::AREA_TRABALHISTA,
        Processo::AREA_TRIBUTARIO,
        Processo::AREA_ADMINISTRATIVO,
        Processo::AREA_PREVIDENCIARIO,
        Processo::AREA_CONSUMIDOR,
        Processo::AREA_FAMILIA,
        Processo::AREA_EMPRESARIAL,
        Processo::AREA_AMBIENTAL,
        Processo::AREA_OUTRAS,
    ];

    private const VALID_INSTANCIAS = [
        Processo::INSTANCIA_PRIMEIRA,
        Processo::INSTANCIA_SEGUNDA,
        Processo::INSTANCIA_SUPERIOR,
    ];

    private const VALID_FASES = [
        Processo::FASE_CONHECIMENTO,
        Processo::FASE_CUMPRIMENTO_SENTENCA,
        Processo::FASE_EXECUCAO,
        Processo::FASE_RECURSAL,
        Processo::FASE_ARQUIVADO,
    ];

    private const VALID_STATUSES = [
        Processo::STATUS_ATIVO,
        Processo::STATUS_SUSPENSO,
        Processo::STATUS_ARQUIVADO,
        Processo::STATUS_BAIXADO,
    ];

    private const VALID_POLOS = [
        Processo::POLO_ATIVO,
        Processo::POLO_PASSIVO,
        Processo::POLO_TERCEIRO,
    ];

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->validateEnum($entity, 'area', self::VALID_AREAS, 'Área inválida — escolha uma das opções.');
        $this->validateEnum($entity, 'instancia', self::VALID_INSTANCIAS, 'Instância inválida — escolha uma das opções.');
        $this->validateEnum($entity, 'fase', self::VALID_FASES, 'Fase inválida — escolha uma das opções.');
        $this->validateEnum($entity, 'status', self::VALID_STATUSES, 'Status inválido — escolha uma das opções.');
        $this->validateEnum($entity, 'polo', self::VALID_POLOS, 'Polo inválido — escolha uma das opções.');

        $this->validatePositiveInt($entity, 'classeCodigo', 'Código de classe deve ser um número positivo.', required: true);
        $this->validatePositiveInt($entity, 'assuntoCodigo', 'Código de assunto deve ser um número positivo.', required: true);
        $this->validatePositiveInt($entity, 'movimentoCodigo', 'Código de movimento deve ser um número positivo.', required: false);

        $valorCausa = $entity->get('valorCausa');
        if ($valorCausa !== null && (float) $valorCausa < 0.0) {
            $this->fail('valorCausa', 'Valor da causa não pode ser negativo.');
        }

        $this->validateDateOrder($entity);
    }

    /**
     * @param non-empty-string $field
     * @param list<string> $valid
     * @param non-empty-string $message
     */
    private function validateEnum(Entity $entity, string $field, array $valid, string $message): void
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

    /**
     * @param non-empty-string $field
     * @param non-empty-string $message
     */
    private function validatePositiveInt(Entity $entity, string $field, string $message, bool $required): void
    {
        $value = $entity->get($field);
        if ($value === null || $value === '') {
            if ($required && ($entity->isNew() || $entity->isAttributeChanged($field))) {
                $this->fail($field, ucfirst($field) . ' é obrigatório.');
            }
            return;
        }
        if (! \is_int($value) && ! (\is_string($value) && \ctype_digit($value))) {
            $this->fail($field, $message);
        }
        if ((int) $value <= 0) {
            $this->fail($field, $message);
        }
    }

    private function validateDateOrder(Entity $entity): void
    {
        $dist = $entity->get('dataDistribuicao');
        $autu = $entity->get('dataAutuacao');
        if ($dist === null || $dist === '' || $autu === null || $autu === '') {
            return;
        }
        if (\strcmp((string) $autu, (string) $dist) < 0) {
            $this->fail(
                'dataAutuacao',
                'Data de autuação deve ser posterior ou igual à data de distribuição.',
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
            'processo.validation.failed',
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
