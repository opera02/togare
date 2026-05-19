<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Cliente;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareCore\Validators\BrValidator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos BR (CPF/CNPJ/CEP/telefone) + regras PF↔PJ no `beforeSave`.
 *
 * Lança `BadRequest` em pt-BR (UX-DR9 + architecture L562) quando:
 *  - PF sem CPF ou CPF DV inválido.
 *  - PF com CNPJ preenchido (combinação ilegal).
 *  - PJ sem CNPJ ou CNPJ DV inválido.
 *  - PJ sem razaoSocial.
 *  - PJ com CPF preenchido (combinação ilegal).
 *  - CEP preenchido com tamanho != 8 dígitos.
 *  - Telefone com DDD < 11 / > 99 ou celular sem nono '9'.
 *
 * Best-effort: se PJ está sendo criado e `name` está vazio mas `razaoSocial`
 * preenchido, copia razaoSocial → name (UX — `name` é exigido pelo EspoCRM
 * para detail/list nativos).
 *
 * Order $order = 20 — roda DEPOIS de NormalizeBrFieldsHook (order 10) para
 * validar contra os valores já normalizados (só dígitos).
 *
 * Defesa em profundidade: client-side `brValidators.js` (Story 1a.5) também
 * valida — server NUNCA confia no cliente (architecture L581).
 *
 * @implements BeforeSave<Entity>
 */
final class ValidateBrFieldsHook implements BeforeSave
{
    public static int $order = 20;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $tipo = $entity->get('tipoPessoa');

        if ($tipo === 'pf') {
            $this->validatePf($entity);
        } elseif ($tipo === 'pj') {
            $this->validatePj($entity);
        } else {
            $this->fail(
                'tipoPessoa',
                'Tipo de pessoa inválido — escolha Pessoa Física ou Pessoa Jurídica.',
            );
        }

        $this->validateCep($entity);
        $this->validatePhone($entity, 'telefone');
        $this->validatePhone($entity, 'telefone2');
    }

    private function validatePf(Entity $entity): void
    {
        $name = $entity->get('name');
        if ($name === null || \trim((string) $name) === '') {
            $this->fail('name', 'Nome é obrigatório para Pessoa Física.');
        }

        $cpf = $entity->get('cpf');
        if ($cpf === null || $cpf === '') {
            $this->fail('cpf', 'CPF é obrigatório para Pessoa Física.');
        }
        if (! BrValidator::isValidCpf((string) $cpf)) {
            $this->fail('cpf', 'CPF inválido — confira o número e tente de novo.');
        }
        $cnpj = $entity->get('cnpj');
        if ($cnpj !== null && $cnpj !== '') {
            $this->fail(
                'cnpj',
                'Cliente Pessoa Física não pode ter CNPJ — preencha apenas o CPF.',
            );
        }
    }

    private function validatePj(Entity $entity): void
    {
        $cnpj = $entity->get('cnpj');
        if ($cnpj === null || $cnpj === '') {
            $this->fail('cnpj', 'CNPJ é obrigatório para Pessoa Jurídica.');
        }
        if (! BrValidator::isValidCnpj((string) $cnpj)) {
            $this->fail('cnpj', 'CNPJ inválido — confira o número e tente de novo.');
        }

        $razao = $entity->get('razaoSocial');
        if ($razao === null || \trim((string) $razao) === '') {
            $this->fail('razaoSocial', 'Razão social é obrigatória para Pessoa Jurídica.');
        }

        $cpf = $entity->get('cpf');
        if ($cpf !== null && $cpf !== '') {
            $this->fail(
                'cpf',
                'Cliente Pessoa Jurídica não pode ter CPF — preencha apenas o CNPJ.',
            );
        }

        // Best-effort: copia razaoSocial → name quando name está vazio.
        $name = $entity->get('name');
        if (($name === null || \trim((string) $name) === '') && \is_string($razao) && \trim($razao) !== '') {
            $entity->set('name', \trim($razao));
        }
    }

    private function validateCep(Entity $entity): void
    {
        $cep = $entity->get('cep');
        if ($cep === null || $cep === '') {
            return;
        }
        if (! BrValidator::isValidCep((string) $cep)) {
            $this->fail('cep', 'CEP inválido — devem ser exatamente 8 dígitos.');
        }
    }

    private function validatePhone(Entity $entity, string $field): void
    {
        $value = $entity->get($field);
        if ($value === null || $value === '') {
            return;
        }
        if (! BrValidator::isValidPhone((string) $value)) {
            $this->fail(
                $field,
                'Telefone inválido — DDD entre 11 e 99; celular precisa do nono dígito.',
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
            'cliente.validation.failed',
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
