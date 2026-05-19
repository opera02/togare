<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Hooks\User;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Hook beforeSave em User: bloqueia criação de usuário regular sem ao menos
 * um role atribuído.
 *
 * Cobre AC5 da Story 2.2 (FR3 do PRD — separação de acessos por papel exige
 * que TODO usuário humano tenha exatamente um dos 8 roles seedados).
 *
 * Skip:
 *  - `! isNew()` — só vale na criação. Edições subsequentes podem temporariamente
 *    remover roles (ex.: trocar de Advogado pra Sócio/Admin) sem bloqueio.
 *  - `isAdmin()` — admin nativo bypass de ACL; pode existir sem role nominal
 *    (caso típico de admin técnico inicial). Sócio/Admin nominal = role + isAdmin
 *    geralmente caminham juntos, mas separamos pra não impedir o admin "técnico".
 *  - `isApi()` — API users não usam roles humanos.
 *  - `isPortal()` — Cliente-portal cai num caminho diferente.
 *
 * @implements BeforeSave<User>
 */
final class UserRoleRequired implements BeforeSave
{
    public static int $order = 5;

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof User) {
            return;
        }

        if (! $entity->isNew()) {
            return;
        }

        if ($entity->isAdmin() || $entity->isApi() || $entity->isPortal()) {
            return;
        }

        // Tipo `regular`/`super-admin` etc — exige role.
        $rolesIds = $entity->getLinkMultipleIdList('roles');

        if ($rolesIds !== []) {
            return;
        }

        TogareLogger::event(
            'warning',
            'user.role.required.violation',
            \sprintf("Tentativa de criar user '%s' sem role atribuído — bloqueada.", (string) $entity->get('userName')),
            [
                'userName' => $entity->get('userName'),
                'emailAddress' => $entity->get('emailAddress'),
            ],
        );

        throw BadRequest::createWithBody(
            'Usuário deve ter ao menos um role atribuído (FR3 do PRD Togare).',
            (string) \json_encode(['reason' => 'role_required'], JSON_UNESCAPED_UNICODE),
        );
    }
}
