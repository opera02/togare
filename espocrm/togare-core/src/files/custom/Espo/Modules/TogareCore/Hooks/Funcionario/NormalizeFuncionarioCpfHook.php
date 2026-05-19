<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Funcionario;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Validators\BrValidator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Story 6.5 — normaliza o CPF do Funcionario removendo máscara antes de
 * persistir. Storage SEMPRE só dígitos (architecture.md L457).
 *
 * Espelha `Hooks\Cliente\NormalizeBrFieldsHook` reduzido ao campo `cpf`
 * (Funcionario não tem CNPJ/CEP/telefone).
 *
 * Idempotente: input já em só-dígitos passa sem mudança.
 *
 * Order $order = 10 — RODA ANTES de ValidateFuncionarioCpfHook (order 20),
 * que valida contra o valor já normalizado. Order menor = mais cedo no
 * runner do EspoCRM.
 *
 * @implements BeforeSave<Entity>
 */
final class NormalizeFuncionarioCpfHook implements BeforeSave
{
    public static int $order = 10;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $value = $entity->get('cpf');
        if ($value === null) {
            return;
        }
        if (! \is_string($value)) {
            return;
        }

        $normalized = BrValidator::digitsOnly($value);
        if ($normalized === '') {
            $entity->set('cpf', null);

            return;
        }

        if ($normalized !== $value) {
            $entity->set('cpf', $normalized);
        }
    }
}
