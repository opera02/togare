<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareNextcloudBridge\Services;

use Espo\Modules\TogareCore\Contracts\EventBusContract;
use Espo\Modules\TogareCore\Events\IntegrationFailedEvent;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;
use Espo\Modules\TogareNextcloudBridge\Services\OcsApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Decisão #8 da Story 5.1: dispatch IntegrationFailedEvent UMA ÚNICA VEZ
 * quando CB transiciona fechado→aberto, e quando esgota retries.
 */
final class EventEmissionTest extends TestCase
{
    private string $stateFile;
    /** @var list<array{0:string, 1:array<string,mixed>}> */
    private array $events = [];
    /** @var list<object> */
    private array $busEvents = [];

    protected function setUp(): void
    {
        TogareLogger::reset();
        $this->stateFile = \sys_get_temp_dir() . '/togare-nextcloud-evtest-' . \bin2hex(\random_bytes(4)) . '.json';
        $this->events = [];
        $this->busEvents = [];
    }

    protected function tearDown(): void
    {
        if (\is_file($this->stateFile)) {
            @\unlink($this->stateFile);
        }
    }

    /**
     * @param list<array{status:int, body:string}> $responses
     */
    private function makeClient(array $responses, ?EventBusContract $eventBus = null): OcsApiClient
    {
        $cursor = 0;
        $http = function (string $url, array $opts) use (&$cursor, $responses): array {
            $r = $responses[$cursor] ?? ['status' => 500, 'body' => ''];
            $cursor++;
            $r['headers'] ??= [];
            return $r;
        };

        return new OcsApiClient(
            baseUrl: 'http://nextcloud-test:80',
            user: 'admin',
            password: 'pass',
            stateFilePath: $this->stateFile,
            httpExecutor: $http,
            clock: fn (): float => 1_715_000_000.0,
            sleeper: static fn (int $s) => null,
            eventEmitter: function (string $name, array $payload): void {
                $this->events[] = [$name, $payload];
            },
            eventBus: $eventBus,
        );
    }

    public function testCbAbrirDispachaIntegrationFailedEventReasonCbOpenedUmaVez(): void
    {
        // Pré-popula 4 falhas — 5ª falha desta request abre o CB.
        $now = 1_715_000_000;
        \file_put_contents($this->stateFile, \json_encode([
            'failures' => [$now - 10, $now - 8, $now - 6, $now - 4],
            'open_until' => 0,
            'opened_at' => 0,
        ]));

        $client = $this->makeClient([
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
        ]);

        try {
            $client->getWebDav('clientes/abc.pdf');
        } catch (NextcloudUnavailableException) {
            // esperado
        }

        $cbOpenedEvents = \array_filter(
            $this->events,
            fn ($e) => $e[0] === 'nextcloud.unavailable' && ($e[1]['reason'] ?? '') === 'cb_opened',
        );
        $this->assertCount(
            1,
            $cbOpenedEvents,
            'IntegrationFailedEvent reason=cb_opened deve ser disparado UMA ÚNICA VEZ',
        );
    }

    public function testEsgotamentoRetriesDispachaIntegrationFailedEventReasonRetriesExhausted(): void
    {
        // State file vazio (CB fechado, sem failures pré-existentes).
        // 3 falhas 503 retryable consecutivas — não atinge threshold do CB
        // (ainda 3 < 5), mas esgota retries da request.
        $client = $this->makeClient([
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
        ]);

        $this->expectException(NextcloudUnavailableException::class);
        try {
            $client->getWebDav('clientes/abc.pdf');
        } finally {
            $retriesExhausted = \array_filter(
                $this->events,
                fn ($e) => $e[0] === 'nextcloud.unavailable' && ($e[1]['reason'] ?? '') === 'retries_exhausted',
            );
            // Deve haver um evento retries_exhausted.
            $this->assertCount(
                1,
                $retriesExhausted,
                'IntegrationFailedEvent reason=retries_exhausted deve ser disparado',
            );
        }
    }

    public function testEsgotamentoRetriesDespachaIntegrationFailedEventRealNoEventBus(): void
    {
        $eventBus = new class ($this->busEvents) implements EventBusContract {
            /** @var list<object> */
            private array $events;

            /** @param list<object> $events */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function dispatch(object $event): void
            {
                $this->events[] = $event;
            }

            public function subscribe(string $eventClass, callable $listener): void
            {
            }
        };

        $client = $this->makeClient([
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
            ['status' => 503, 'body' => ''],
        ], $eventBus);

        try {
            $client->getWebDav('clientes/abc.pdf');
            $this->fail('esperava NextcloudUnavailableException');
        } catch (NextcloudUnavailableException) {
            // esperado
        }

        $this->assertCount(1, $this->busEvents);
        $this->assertInstanceOf(IntegrationFailedEvent::class, $this->busEvents[0]);
        $this->assertSame('nextcloud', $this->busEvents[0]->integrationName);
        $this->assertSame('retries_exhausted', $this->busEvents[0]->reason);
    }
}
