<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ParteContraria;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Validators\BrValidator;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Normaliza campos BR (CPF/CNPJ/telefone) removendo máscara antes de persistir
 * — storage SEMPRE só dígitos (architecture.md L457).
 *
 * Diferença vs Cliente: ParteContraria não tem `cep` nem `telefone2`.
 *
 * Idempotente: input já em só-dígitos passa sem mudança.
 *
 * Order $order = 10 — RODA ANTES de ValidateBrFieldsHook (order 20) e antes de
 * qualquer hook que leia o valor já normalizado. Order menor = mais cedo no
 * runner do EspoCRM.
 *
 * @implements BeforeSave<Entity>
 */
final class NormalizeBrFieldsHook implements BeforeSave
{
    public static int $order = 10;

    /** @var list<string> Campos onde só dígitos são armazenados. */
    private const NORMALIZED_FIELDS = [
        'cpf',
        'cnpj',
        'telefone',
    ];

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        foreach (self::NORMALIZED_FIELDS as $field) {
            $value = $entity->get($field);
            if ($value === null || $value === '') {
                continue;
            }
            if (! \is_string($value)) {
                continue;
            }
            $normalized = BrValidator::digitsOnly($value);
            if ($normalized === '') {
                // Input was whitespace-only ou só máscara sem dígitos — coluna vai a NULL
                // para manter consistência com valor "ausente" (evita mistura NULL vs "" no DB).
                $entity->set($field, null);
                continue;
            }
            if ($normalized !== $value) {
                $entity->set($field, $normalized);
            }
        }
    }
}
