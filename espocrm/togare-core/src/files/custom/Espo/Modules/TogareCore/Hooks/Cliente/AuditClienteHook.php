<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Cliente;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\Cliente;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Audit afterSave em `Cliente`: emite `audit.cliente.created` (em `isNew()`) ou
 * `audit.cliente.modified` (com lista de campos sensíveis alterados).
 *
 * Cobre FR37 + NFR10 (audit log append-only, retenção 24m). Persiste em
 * `togare_audit_log` via `AuditLogService` (Story 2.4) — concreta resolvida
 * pelo container EspoCRM via DI no contract `AuditLogContract`.
 *
 * Allowlist de campos sensíveis evita ruído (touch sem mudança real). PII
 * (CPF/CNPJ/email/telefone/endereço) está coberta — é exatamente o motivo da
 * auditoria existir nessas entidades.
 *
 * @implements AfterSave<Cliente>
 */
final class AuditClienteHook implements AfterSave
{
    public static int $order = 50;

    /** @var list<string> Allowlist de atributos cuja mudança merece audit. */
    private const SENSITIVE_FIELDS = [
        'name',
        'tipoPessoa',
        'cpf',
        'cnpj',
        'razaoSocial',
        'nomeFantasia',
        'rg',
        'inscricaoEstadual',
        'dataNascimento',
        'estadoCivil',
        'email',
        'telefone',
        'telefone2',
        'cep',
        'logradouro',
        'numeroEndereco',
        'complemento',
        'bairro',
        'cidade',
        'uf',
        'assignedUserId',
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Cliente) {
            return;
        }

        $clienteId = (string) $entity->getId();

        if ($entity->isNew()) {
            try {
                $this->auditLog->log(
                    'audit.cliente.created',
                    'Cliente',
                    $clienteId,
                    [
                        'name' => $entity->get('name'),
                        'tipoPessoa' => $entity->get('tipoPessoa'),
                    ],
                );
            } catch (\Throwable $e) {
                TogareLogger::event('error', 'audit.hook.failed', 'AuditClienteHook: falha ao registrar created', ['error' => $e->getMessage()]);
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
                'audit.cliente.modified',
                'Cliente',
                $clienteId,
                [
                    'name' => $entity->get('name'),
                    'tipoPessoa' => $entity->get('tipoPessoa'),
                    'changedFields' => $changed,
                ],
            );
        } catch (\Throwable $e) {
            TogareLogger::event('error', 'audit.hook.failed', 'AuditClienteHook: falha ao registrar modified', ['error' => $e->getMessage()]);
        }
    }
}
