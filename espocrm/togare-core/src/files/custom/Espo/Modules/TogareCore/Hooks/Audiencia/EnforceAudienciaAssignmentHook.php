<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Audiencia;

use Espo\Core\ApplicationState;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Entities\Audiencia;
use Espo\Modules\TogareCore\Services\PrivilegedActorChecker;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Versão LIGHT do `EnforceAssignmentPolicyHook` da Story 3.5 — implementa
 * apenas **auto-titular em create** para Audiencia (Story 3.6-magro, FR16).
 *
 * Roda **antes** de ValidateAudienciaFieldsHook (10) e AuditAudienciaHook (50)
 * — order=5 garante que o `assignedUser` esteja preenchido antes de
 * qualquer validação ou audit emitir.
 *
 * **Política aplicada:**
 *
 *  1. **Auto-titular em create.** Se Advogado (não-privileged) cria audiência
 *     sem `assignedUserId`, o sistema atribui automaticamente o user corrente
 *     como responsável (AC4). Sócio/Admin sem `assignedUserId` deixa null —
 *     fluxo legítimo: admin marca audiência sem advogado pré-atribuído ou
 *     atribui depois.
 *
 *  2. **NÃO bloqueia mudança de assignment por terceiros** (diferente da Story
 *     3.5 do Processo). Audiência é menos sensível que Processo — admins
 *     delegando audiência específica para advogado júnior é fluxo legítimo
 *     (e a ACL `read=own` já filtra a visibilidade no nível da role).
 *
 * **Falha do ApplicationState** (sem usuário corrente, ex.: install via CLI,
 * scheduledJob): hook NÃO bloqueia — let it pass. Operações sistêmicas não
 * são alvo desta política. Verificamos `appState->hasUser()` primeiro.
 *
 * **Reuso:** `PrivilegedActorChecker` é o serviço já existente da Story 3.5
 * (`togare-core/Services/PrivilegedActorChecker.php`) — mesma definição
 * canônica de "Sócio/Admin Togare" + system superuser EspoCRM.
 *
 * @implements BeforeSave<Audiencia>
 */
final class EnforceAudienciaAssignmentHook implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private readonly ApplicationState $applicationState,
        private readonly PrivilegedActorChecker $privilegedActorChecker,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Audiencia) {
            return;
        }

        if (! $entity->isNew()) {
            // versão MAGRO: NÃO bloqueia mudança de assignment em update
            return;
        }

        if (! $this->applicationState->hasUser()) {
            return;
        }

        $user = $this->applicationState->getUser();
        if ($user === null) {
            return;
        }

        if ($this->privilegedActorChecker->isPrivileged($user)) {
            return;
        }

        $userId = $user->getId();
        if ($userId === null) {
            return;
        }

        $assignedUserId = $entity->get('assignedUserId');
        if ($assignedUserId === null || $assignedUserId === '') {
            $entity->set('assignedUserId', $userId);
        }
    }
}
