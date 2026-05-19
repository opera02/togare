<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ParteContraria;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareCore\Validators\BrValidator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos BR (CPF/CNPJ/telefone) + regras tipoPessoa↔documento no
 * `beforeSave` da entidade ParteContraria (Story 3.2, FR6/FR7).
 *
 * Lança `BadRequest` em pt-BR (UX-DR9 + architecture L562) quando:
 *  - CPF preenchido com DV inválido (em qualquer tipo).
 *  - CNPJ preenchido com DV inválido (em qualquer tipo).
 *  - PF com CNPJ preenchido (combinação ilegal).
 *  - PJ com CPF preenchido (combinação ilegal).
 *  - desconhecida com CPF ou CNPJ preenchido.
 *  - tipoPessoa fora de pf/pj/desconhecida.
 *  - Telefone com DDD < 11 / > 99 ou celular sem nono '9'.
 *
 * Diferenças críticas em relação a Cliente (Story 3.1):
 *  - CPF e CNPJ são OPCIONAIS em todos os tipos (parte pode ser identificada só
 *    pelo nome — caso `desconhecida`).
 *  - Aceita terceiro tipo `desconhecida` (parte sem documento).
 *  - Sem campo `cep`/`telefone2` — entidade enxuta.
 *  - Sem `razaoSocial` (campo único `name` cobre PF e PJ — best-effort de
 *    razão social do Cliente não se aplica).
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

        $name = $entity->get('name');
        if ($name === null || \trim((string) $name) === '') {
            $this->fail('name', 'Nome é obrigatório para Parte Contrária.');
        }

        if ($tipo === 'pf') {
            $this->validatePf($entity);
        } elseif ($tipo === 'pj') {
            $this->validatePj($entity);
        } elseif ($tipo === 'desconhecida') {
            $this->validateDesconhecida($entity);
        } else {
            $this->fail(
                'tipoPessoa',
                'Tipo de parte inválido — escolha Pessoa Física, Pessoa Jurídica ou Desconhecida.',
            );
        }

        $this->validatePhone($entity, 'telefone');
    }

    private function validatePf(Entity $entity): void
    {
        $cpf = $entity->get('cpf');
        if ($cpf !== null && $cpf !== '' && ! BrValidator::isValidCpf((string) $cpf)) {
            $this->fail('cpf', 'CPF inválido — confira o número e tente de novo.');
        }
        $cnpj = $entity->get('cnpj');
        if ($cnpj !== null && $cnpj !== '') {
            $this->fail(
                'cnpj',
                'Parte Pessoa Física não pode ter CNPJ — preencha apenas o CPF (ou nenhum dos dois).',
            );
        }
    }

    private function validatePj(Entity $entity): void
    {
        $cnpj = $entity->get('cnpj');
        if ($cnpj !== null && $cnpj !== '' && ! BrValidator::isValidCnpj((string) $cnpj)) {
            $this->fail('cnpj', 'CNPJ inválido — confira o número e tente de novo.');
        }
        $cpf = $entity->get('cpf');
        if ($cpf !== null && $cpf !== '') {
            $this->fail(
                'cpf',
                'Parte Pessoa Jurídica não pode ter CPF — preencha apenas o CNPJ (ou nenhum dos dois).',
            );
        }
    }

    private function validateDesconhecida(Entity $entity): void
    {
        $cpf = $entity->get('cpf');
        if ($cpf !== null && $cpf !== '') {
            $this->fail(
                'cpf',
                'Parte desconhecida não pode ter CPF — mude o tipo para Pessoa Física se quiser informar o documento.',
            );
        }
        $cnpj = $entity->get('cnpj');
        if ($cnpj !== null && $cnpj !== '') {
            $this->fail(
                'cnpj',
                'Parte desconhecida não pode ter CNPJ — mude o tipo para Pessoa Jurídica se quiser informar o documento.',
            );
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
            'parte_contraria.validation.failed',
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
