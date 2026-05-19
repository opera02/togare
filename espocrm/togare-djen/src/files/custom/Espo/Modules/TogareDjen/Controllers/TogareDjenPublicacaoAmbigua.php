<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareDjen\Exception\AlreadyResolvedException;
use Espo\Modules\TogareDjen\Exception\InvalidCandidateException;
use Espo\Modules\TogareDjen\Services\AmbiguityResolverService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * DJEN-owned REST bridge for resolving PublicacaoAmbigua rows.
 *
 * Endpoints:
 * - POST /api/v1/TogareDjenPublicacaoAmbigua/action/resolve
 *   body: {publicacaoAmbiguaId, chosenProcessoId}
 * - POST /api/v1/TogareDjenPublicacaoAmbigua/action/ignore
 *   body: {publicacaoAmbiguaId}
 * - POST /api/v1/TogareDjenPublicacaoAmbigua/action/bulkIgnoreProcesso
 *   body: {processoId}
 */
class TogareDjenPublicacaoAmbigua
{
    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Acl $acl,
        private readonly User $user,
        private readonly AmbiguityResolverService $resolver,
    ) {
    }

    /**
     * @throws BadRequest
     * @throws Conflict
     * @throws Forbidden
     */
    public function postActionResolve(Request $request): stdClass
    {
        $body = $request->getParsedBody();
        $id = $this->getPublicacaoAmbiguaId($request, $body);
        $this->loadEditablePublicacao($id);

        $chosenProcessoId = $this->stringFromBody($body, 'chosenProcessoId');
        if ($chosenProcessoId === '') {
            throw new BadRequest('Body must contain chosenProcessoId.');
        }

        try {
            $prazo = $this->resolver->resolve($id, $chosenProcessoId, $this->currentUserId());
        } catch (AlreadyResolvedException $e) {
            throw new Conflict($e->getMessage());
        } catch (InvalidCandidateException $e) {
            throw new BadRequest($e->getMessage());
        }

        $response = new stdClass();
        $response->prazoId = (string) $prazo->getId();

        return $response;
    }

    /**
     * @throws BadRequest
     * @throws Conflict
     * @throws Forbidden
     */
    public function postActionIgnore(Request $request): stdClass
    {
        $body = $request->getParsedBody();
        $id = $this->getPublicacaoAmbiguaId($request, $body);
        $this->loadEditablePublicacao($id);

        try {
            $this->resolver->ignore($id, $this->currentUserId());
        } catch (AlreadyResolvedException $e) {
            throw new Conflict($e->getMessage());
        } catch (InvalidCandidateException $e) {
            throw new BadRequest($e->getMessage());
        }

        $response = new stdClass();
        $response->success = true;

        return $response;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    public function postActionBulkIgnoreProcesso(Request $request): stdClass
    {
        if (! $this->acl->checkScope(PublicacaoAmbigua::ENTITY_TYPE, 'edit')) {
            throw new Forbidden('No edit access to PublicacaoAmbigua.');
        }

        $body = $request->getParsedBody();
        $processoId = $this->stringFromBody($body, 'processoId');
        if ($processoId === '') {
            throw new BadRequest('Body must contain processoId.');
        }

        try {
            $count = $this->resolver->bulkIgnoreProcesso(
                $processoId,
                $this->currentUserId(),
                fn (Entity $pub): bool => $this->acl->check($pub, 'edit'),
            );
        } catch (InvalidCandidateException $e) {
            throw new BadRequest($e->getMessage());
        }

        $response = new stdClass();
        $response->count = $count;

        return $response;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     */
    private function loadEditablePublicacao(string $id): Entity
    {
        $pub = $this->entityManager->getEntityById(PublicacaoAmbigua::ENTITY_TYPE, $id);
        if (! $pub instanceof Entity) {
            throw new BadRequest('PublicacaoAmbigua not found.');
        }

        if (! $this->acl->check($pub, 'edit')) {
            throw new Forbidden('No edit access to this PublicacaoAmbigua.');
        }

        return $pub;
    }

    /**
     * @throws BadRequest
     */
    private function getPublicacaoAmbiguaId(Request $request, mixed $body): string
    {
        $routeId = $request->getRouteParam('id');
        if (\is_string($routeId) && $routeId !== '') {
            return $routeId;
        }

        $bodyId = $this->stringFromBody($body, 'publicacaoAmbiguaId');
        if ($bodyId === '') {
            throw new BadRequest('Body must contain publicacaoAmbiguaId.');
        }

        return $bodyId;
    }

    private function stringFromBody(mixed $body, string $field): string
    {
        if (! \is_object($body) || ! isset($body->{$field}) || ! \is_string($body->{$field})) {
            return '';
        }

        return \trim($body->{$field});
    }

    private function currentUserId(): string
    {
        return (string) $this->user->getId();
    }
}
