<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ParteContraria;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\ParteContraria;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `ParteContraria`: emite `audit.parte_contraria.created`
 * (em `isNew()`) ou `audit.parte_contraria.modified` (com lista de campos
 * sensíveis alterados).
 *
 * Cobre FR37 + NFR10 (audit log append-only, retenção 24m). Persiste em
 * `togare_audit_log` via `AuditLogService` (Story 2.4) — concreta resolvida
 * pelo container EspoCRM via DI no contract `AuditLogContract` (binding em
 * `Binding.php` — adicionado na Story 3.1).
 *
 * Allowlist de campos sensíveis evita ruído (touch sem mudança real). Comparado
 * a `AuditClienteHook`, a lista é menor — ParteContraria não tem rg, dataNascimento,
 * estadoCivil, endereço, telefone2.
 *
 * @implements AfterSave<ParteContraria>
 */
final class AuditParteContrariaHook implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Allowlist de atributos cuja mudança merece audit. */
    private const SENSITIVE_FIELDS = [
        'name',
        'tipoPessoa',
        'cpf',
        'cnpj',
        'email',
        'telefone',
        'observacoes',
        'assignedUserId',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof ParteContraria) {
            return;
        }

        $parteId = (string) $entity->getId();

        if ($entity->isNew()) {
            try {
                $this->auditLog->log(
                    'audit.parte_contraria.created',
                    'ParteContraria',
                    $parteId,
                    [
                        'name' => $entity->get('name'),
                        'tipoPessoa' => $entity->get('tipoPessoa'),
                    ],
                );
            } catch (\Throwable $e) {
                TogareLogger::event('error', 'audit.hook.failed', 'AuditParteContrariaHook: falha ao registrar created', ['error' => $e->getMessage()]);
            }
            return;
        }

        $changed = [];
        foreach (self::SENSITIVE_FIELDS as $field) {
            if ($entity->isAttributeChanged($field)) {
                $changed[] = $field;
            }
        }

        if ($changed === []) {
            return;
        }

        try {
            $this->auditLog->log(
                'audit.parte_contraria.modified',
                'ParteContraria',
                $parteId,
                [
                    'name' => $entity->get('name'),
                    'tipoPessoa' => $entity->get('tipoPessoa'),
                    'changedFields' => $changed,
                ],
            );
        } catch (\Throwable $e) {
            TogareLogger::event('error', 'audit.hook.failed', 'AuditParteContrariaHook: falha ao registrar modified', ['error' => $e->getMessage()]);
        }
    }
}
