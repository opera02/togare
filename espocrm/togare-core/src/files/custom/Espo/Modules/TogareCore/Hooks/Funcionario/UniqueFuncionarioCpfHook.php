<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Funcionario;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Funcionario;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Story 6.5 fix-pass 0.37.2 — garante CPF único entre funcionários ATIVOS
 * (não-deletados), com mensagem pt-BR amigável.
 *
 * **Bug corrigido (smoke browser Felipe, passo 2):** a 1ª versão usava um
 * índice UNIQUE cru de banco em `cpf`. Isso causava dois problemas:
 *  1. Violação → `PDOException` cru → **HTTP 500** (não a mensagem pt-BR).
 *  2. O índice abrange linhas soft-deleted (`deleted=1`), então o CPF de um
 *     funcionário **excluído** continuava bloqueado — recriar dava 500.
 *     (Mesma classe do bug 6.3 numero Fatura — UNIQUE × soft-delete.)
 *
 * Solução (idiomática EspoCRM): unicidade enforçada em `beforeSave` via
 * query do RDBRepository, que por padrão **exclui soft-deleted** — logo o
 * CPF de um funcionário excluído fica livre para reuso. Conflito real (com
 * funcionário ATIVO) lança `BadRequest` (HTTP 400) com corpo JSON pt-BR, no
 * mesmo padrão exato de `ValidateFuncionarioCpfHook` (a mensagem que o
 * Felipe validou no passo 5 do smoke browser).
 *
 * Order $order = 25 — roda DEPOIS de NormalizeFuncionarioCpfHook (10, CPF já
 * só-dígitos) e ValidateFuncionarioCpfHook (20, formato/DV válido); só faz a
 * checagem de unicidade quando o CPF já está normalizado e bem-formado.
 *
 * CPF é OPCIONAL no Funcionario — ausência/vazio não dispara checagem
 * (vários funcionários sem CPF coexistem; NormalizeHook converte vazio→null).
 *
 * @implements BeforeSave<Funcionario>
 */
final class UniqueFuncionarioCpfHook implements BeforeSave
{
    public static int $order = 25;

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Funcionario) {
            return;
        }

        $cpf = $entity->get('cpf');
        if ($cpf === null || $cpf === '') {
            return;
        }
        $cpf = (string) $cpf;

        // RDBRepository->where()->findOne() já filtra deleted=0 por padrão —
        // CPF de funcionário excluído NÃO conta (reuso liberado).
        $builder = $this->entityManager
            ->getRDBRepository(Funcionario::ENTITY_TYPE)
            ->where(['cpf' => $cpf]);

        $currentId = $entity->getId();
        if ($currentId !== null && $currentId !== '') {
            $builder = $builder->where(['id!=' => (string) $currentId]);
        }

        $existing = $builder->findOne();
        if ($existing === null) {
            return;
        }

        $message = 'Já existe um funcionário cadastrado com este CPF.';

        TogareLogger::event(
            'warning',
            'funcionario.cpf.duplicado',
            $message,
            [
                'field' => 'cpf',
                'reason' => 'duplicate',
                'existingId' => (string) ($existing->getId() ?? ''),
            ],
        );

        throw BadRequest::createWithBody(
            $message,
            (string) \json_encode(
                ['field' => 'cpf', 'reason' => 'duplicate', 'message' => $message],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
