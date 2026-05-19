<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\TogareDjen\Controllers\TogareDjenStatus;
use Espo\Modules\TogareDjen\Services\DjenAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Story 4b.4 / ADR 0009 — TogareDjenStatus controller (snapshot endpoint).
 *
 * Cobre AC7 e AC8: shape do JSON + ACL bloqueando portal.
 *
 * **Estratégia de adapter:** usa `DjenAdapter` real com state-file temporário.
 * A classe é final; não usamos anonymous subclass.
 */
final class TogareDjenStatusControllerTest extends TestCase
{
    /** @var list<string> */
    private array $stateFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->stateFiles as $file) {
            if (\is_file($file)) {
                @\unlink($file);
            }
        }
        $this->stateFiles = [];
        parent::tearDown();
    }

    /**
     * @param array{failures:list<int>, open_until:int, opened_at:int, unavailable_since?:int} $cbState
     */
    private function makeAdapter(array $cbState): DjenAdapter
    {
        $path = \sys_get_temp_dir() . '/togare-djen-controller-test-' . \uniqid() . '.json';
        $this->stateFiles[] = $path;
        \file_put_contents($path, \json_encode($cbState, JSON_THROW_ON_ERROR));

        return new DjenAdapter(
            'https://comunicaapi.pje.jus.br/api/v1',
            $path,
        );
    }

    private function makeAcl(bool $allowed): Acl
    {
        return new class ($allowed) extends Acl {
            public function __construct(private bool $allowed)
            {
            }

            public function checkScope(string $scope, ?string $action = null): bool
            {
                return $this->allowed;
            }
        };
    }

    private function makeRequest(): Request
    {
        return new class implements Request {
            public function getRouteParam(string $name): ?string
            {
                return null;
            }

            public function getParsedBody(): mixed
            {
                return null;
            }
        };
    }

    public function testSnapshotRetornaCbOpenFalseQuandoStateVazio(): void
    {
        $adapter = $this->makeAdapter([
            'failures' => [],
            'open_until' => 0,
            'opened_at' => 0,
            'unavailable_since' => 0,
        ]);
        $controller = new TogareDjenStatus($this->makeAcl(true), $adapter);

        $response = $controller->getActionSnapshot($this->makeRequest());

        $this->assertFalse($response->cbOpen);
        $this->assertFalse($response->technicalCbOpen);
        $this->assertNull($response->openedAt);
        $this->assertNull($response->openUntil);
        $this->assertNull($response->unavailableSince);
        $this->assertSame(0, $response->minutesOpen);
        $this->assertNull($response->nextRetryHint);
    }

    public function testSnapshotRetornaCbOpenTrueComMinutesOpenENextRetryHint(): void
    {
        $now = \time();
        $openedAt = $now - 1800; // 30 min atrás
        $openUntil = $now + 600; // 10 min no futuro

        $adapter = $this->makeAdapter([
            'failures' => [$openedAt - 100, $openedAt - 80, $openedAt - 60, $openedAt - 40, $openedAt],
            'open_until' => $openUntil,
            'opened_at' => $openedAt,
            'unavailable_since' => $openedAt,
        ]);
        $controller = new TogareDjenStatus($this->makeAcl(true), $adapter);

        $response = $controller->getActionSnapshot($this->makeRequest());

        $this->assertTrue($response->cbOpen);
        $this->assertTrue($response->technicalCbOpen);
        $this->assertNotNull($response->openedAt);
        $this->assertNotNull($response->openUntil);
        $this->assertNotNull($response->unavailableSince);
        // minutesOpen é floor((now - unavailable_since) / 60). 1800s = 30min.
        // Tolerância: pode dar 29 ou 30 dependendo de drift de execução.
        $this->assertGreaterThanOrEqual(29, $response->minutesOpen);
        $this->assertLessThanOrEqual(31, $response->minutesOpen);
        // nextRetryHint = HH:MM 24h, BRT.
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $response->nextRetryHint);
        // ISO 8601 com offset de America/Sao_Paulo (-03:00).
        $this->assertStringContainsString('-03:00', $response->openedAt);
        $this->assertStringContainsString('-03:00', $response->openUntil);
    }

    public function testSnapshotRetornaForbiddenQuandoAclFalha(): void
    {
        $adapter = $this->makeAdapter([
            'failures' => [],
            'open_until' => 0,
            'opened_at' => 0,
            'unavailable_since' => 0,
        ]);
        $controller = new TogareDjenStatus($this->makeAcl(false), $adapter);

        $this->expectException(Forbidden::class);
        $controller->getActionSnapshot($this->makeRequest());
    }

    public function testSnapshotMantemIndisponivelQuandoCooldownTecnicoJaPassou(): void
    {
        $now = \time();
        $unavailableSince = $now - 1900;
        $openUntilPassado = $now - 1; // CB fechou há 1s

        $adapter = $this->makeAdapter([
            'failures' => [$unavailableSince - 100],
            'open_until' => $openUntilPassado,
            'opened_at' => $now - 700,
            'unavailable_since' => $unavailableSince,
        ]);
        $controller = new TogareDjenStatus($this->makeAcl(true), $adapter);

        $response = $controller->getActionSnapshot($this->makeRequest());

        $this->assertTrue($response->cbOpen, 'cbOpen=true enquanto DJEN segue operacionalmente indisponível');
        $this->assertFalse($response->technicalCbOpen, 'technicalCbOpen=false quando open_until <= now');
        $this->assertNotNull($response->openedAt);
        $this->assertNotNull($response->openUntil);
        $this->assertGreaterThanOrEqual(31, $response->minutesOpen);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $response->nextRetryHint);
    }
}
