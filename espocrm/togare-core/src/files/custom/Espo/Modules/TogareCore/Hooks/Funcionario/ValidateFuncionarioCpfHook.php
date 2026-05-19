<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Funcionario;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareCore\Validators\BrValidator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Story 6.5 — valida o CPF do Funcionario no `beforeSave`.
 *
 * Espelha o trecho CPF de `Hooks\Cliente\ValidateBrFieldsHook` (L62-83):
 * lança `BadRequest` em pt-BR (UX-DR9 + architecture L562) quando o CPF
 * informado tem dígitos verificadores inválidos (mod 11) ou é sequência
 * repetida.
 *
 * CPF é OPCIONAL no Funcionario (não consta em `required` no entityDefs —
 * o escritório pode cadastrar antes de ter o documento). Portanto: CPF
 * vazio passa sem erro; CPF preenchido DEVE ser válido. Caso o campo passe
 * a ser required no futuro, a guarda de presença abaixo cobre o caso.
 *
 * Order $order = 20 — roda DEPOIS de NormalizeFuncionarioCpfHook (order 10)
 * para validar contra o valor já normalizado (só dígitos).
 *
 * Defesa em profundidade: client-side via field view
 * `togare-core:views/fields/cpf-br` (máscara + validação inline) também
 * valida — o servidor NUNCA confia no cliente (architecture L581).
 *
 * @implements BeforeSave<Entity>
 */
final class ValidateFuncionarioCpfHook implements BeforeSave
{
    public static int $order = 20;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $cpf = $entity->get('cpf');

        // Campo opcional: ausência de CPF não é erro.
        if ($cpf === null || $cpf === '') {
            return;
        }

        if (! BrValidator::isValidCpf((string) $cpf)) {
            $this->fail('cpf', 'CPF inválido — confira o número e tente de novo.');
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
            'funcionario.validation.failed',
            $message,
            [
                'field' => $field,
                'reason' => 'invalid',
            ],
        );

        // `message` no body JSON é lido pelo frontend EspoCRM (espo-main.js
        // _processErrorAlert) antes do fallback ao header X-Status-Reason —
        // headers HTTP são ASCII-only e bytes UTF-8 multi-byte viram mojibake
        // quando o navegador decodifica como ISO-8859-1.
        throw BadRequest::createWithBody(
            $message,
            (string) \json_encode(
                ['field' => $field, 'reason' => 'invalid', 'message' => $message],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
