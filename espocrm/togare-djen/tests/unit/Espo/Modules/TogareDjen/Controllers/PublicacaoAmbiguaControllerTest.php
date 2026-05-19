<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Controllers;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareDjen\Controllers\TogareDjenPublicacaoAmbigua;
use Espo\Modules\TogareDjen\Exception\AlreadyResolvedException;
use Espo\Modules\TogareDjen\Exception\InvalidCandidateException;
use Espo\Modules\TogareDjen\Services\AmbiguityResolverService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class PublicacaoAmbiguaControllerTest extends TestCase
{
    private function makeController(
        bool $aclEditAllowed = true,
        ?AmbiguityResolverService $resolver = null,
        string $userId = 'user-advogado',
        ?PublicacaoAmbigua $pub = null,
    ): TogareDjenPublicacaoAmbigua {
        $pub ??= $this->makePub();

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(
            static fn (string $type, string $id): ?Entity =>
                $type === PublicacaoAmbigua::ENTITY_TYPE && $id === $pub->getId() ? $pub : null,
        );

        $acl = new class ($aclEditAllowed) extends Acl {
            public function __construct(private bool $allowed) {}
            public function check(mixed $subject, ?string $action = null): bool
            {
                return $this->allowed;
            }
            public function checkScope(string $scope, ?string $action = null): bool
            {
                return $this->allowed;
            }
        };

        $user = new User();
        $user->setId($userId);

        $resolverActual = $resolver ?? $this->createMock(AmbiguityResolverService::class);

        return new TogareDjenPublicacaoAmbigua($em, $acl, $user, $resolverActual);
    }

    private function makePub(string $id = 'pub-001'): PublicacaoAmbigua
    {
        $pub = new PublicacaoAmbigua();
        $pub->setId($id);
        $pub->set('status', PublicacaoAmbigua::STATUS_PENDENTE_REVISAO);

        return $pub;
    }

    private function makeRequest(?string $id = null, ?\stdClass $body = null): Request
    {
        return new class ($id, $body) implements Request {
            public function __construct(private ?string $id, private ?\stdClass $body) {}
            public function getRouteParam(string $name): ?string
            {
                return $name === 'id' ? $this->id : null;
            }
            public function getParsedBody(): mixed
            {
                return $this->body;
            }
        };
    }

    public function testPostActionResolveHappyPathRetorna200ComPrazoId(): void
    {
        $prazo = new CoreEntity();
        $prazo->setId('prazo-novo-001');

        $resolver = $this->createMock(AmbiguityResolverService::class);
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('pub-001', 'proc-A', 'user-advogado')
            ->willReturn($prazo);

        $controller = $this->makeController(resolver: $resolver);
        $body = new \stdClass();
        $body->publicacaoAmbiguaId = 'pub-001';
        $body->chosenProcessoId = 'proc-A';
        $request = $this->makeRequest(body: $body);

        $response = $controller->postActionResolve($request);

        self::assertSame('prazo-novo-001', $response->prazoId);
    }

    public function testPostActionResolveSemPublicacaoAmbiguaIdThrowsBadRequest(): void
    {
        $controller = $this->makeController();
        $body = new \stdClass();
        $body->chosenProcessoId = 'proc-A';
        $request = $this->makeRequest(body: $body);

        $this->expectException(BadRequest::class);
        $controller->postActionResolve($request);
    }

    public function testPostActionResolveSemChosenProcessoIdThrowsBadRequest(): void
    {
        $controller = $this->makeController();
        $body = new \stdClass();
        $body->publicacaoAmbiguaId = 'pub-001';
        $request = $this->makeRequest(body: $body);

        $this->expectException(BadRequest::class);
        $controller->postActionResolve($request);
    }

    public function testPostActionResolveAlreadyResolvedThrowsConflict409(): void
    {
        $resolver = $this->createMock(AmbiguityResolverService::class);
        $resolver->method('resolve')->willThrowException(
            new AlreadyResolvedException(new DateTimeImmutable('2026-05-08 10:00:00')),
        );

        $controller = $this->makeController(resolver: $resolver);
        $body = new \stdClass();
        $body->publicacaoAmbiguaId = 'pub-001';
        $body->chosenProcessoId = 'proc-A';
        $request = $this->makeRequest(body: $body);

        $this->expectException(Conflict::class);
        $controller->postActionResolve($request);
    }

    public function testPostActionResolveInvalidCandidateThrowsBadRequest400(): void
    {
        $resolver = $this->createMock(AmbiguityResolverService::class);
        $resolver->method('resolve')->willThrowException(new InvalidCandidateException());

        $controller = $this->makeController(resolver: $resolver);
        $body = new \stdClass();
        $body->publicacaoAmbiguaId = 'pub-001';
        $body->chosenProcessoId = 'proc-INEXISTENTE';
        $request = $this->makeRequest(body: $body);

        $this->expectException(BadRequest::class);
        $controller->postActionResolve($request);
    }

    public function testPostActionResolveSemAclEditThrowsForbidden(): void
    {
        $controller = $this->makeController(aclEditAllowed: false);
        $body = new \stdClass();
        $body->publicacaoAmbiguaId = 'pub-001';
        $body->chosenProcessoId = 'proc-A';
        $request = $this->makeRequest(body: $body);

        $this->expectException(Forbidden::class);
        $controller->postActionResolve($request);
    }

    public function testPostActionIgnoreHappyPathRetorna200ComSuccess(): void
    {
        $resolver = $this->createMock(AmbiguityResolverService::class);
        $resolver->expects($this->once())
            ->method('ignore')
            ->with('pub-001', 'user-advogado');

        $controller = $this->makeController(resolver: $resolver);
        $body = new \stdClass();
        $body->publicacaoAmbiguaId = 'pub-001';
        $request = $this->makeRequest(body: $body);

        $response = $controller->postActionIgnore($request);

        self::assertTrue($response->success);
    }

    public function testPostActionIgnoreAlreadyResolvedThrowsConflict(): void
    {
        $resolver = $this->createMock(AmbiguityResolverService::class);
        $resolver->method('ignore')->willThrowException(
            new AlreadyResolvedException(new DateTimeImmutable('2026-05-08 10:00:00')),
        );

        $controller = $this->makeController(resolver: $resolver);
        $body = new \stdClass();
        $body->publicacaoAmbiguaId = 'pub-001';
        $request = $this->makeRequest(body: $body);

        $this->expectException(Conflict::class);
        $controller->postActionIgnore($request);
    }

    public function testPostActionBulkIgnoreProcessoHappyPathRetorna200ComCount(): void
    {
        $resolver = $this->createMock(AmbiguityResolverService::class);
        $resolver->expects($this->once())
            ->method('bulkIgnoreProcesso')
            ->with(
                'P_X',
                'user-advogado',
                $this->callback(static fn (mixed $filter): bool => \is_callable($filter)),
            )
            ->willReturn(3);

        $controller = $this->makeController(resolver: $resolver);
        $body = new \stdClass();
        $body->processoId = 'P_X';
        $request = $this->makeRequest(body: $body);

        $response = $controller->postActionBulkIgnoreProcesso($request);

        self::assertSame(3, $response->count);
    }

    public function testPostActionBulkIgnoreProcessoSemProcessoIdThrowsBadRequest(): void
    {
        $controller = $this->makeController();
        $request = $this->makeRequest(body: new \stdClass());

        $this->expectException(BadRequest::class);
        $controller->postActionBulkIgnoreProcesso($request);
    }
}
