<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use Espo\Modules\TogareCore\Services\TogareLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * State estático do TogareLogger (singleton) exige isolamento por teste.
 * RunInSeparateProcess em cada método.
 */
final class TogareLoggerTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testEmitsJsonWithAllFields(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        TogareLogger::init('togare-core', null, $stdout, $stderr);
        TogareLogger::event('info', 'djen.sync.completed', 'sync ok', ['advCount' => 12]);

        rewind($stdout);
        $line = stream_get_contents($stdout);

        self::assertNotEmpty($line);
        $record = json_decode(trim($line), true);
        self::assertIsArray($record);

        self::assertArrayHasKey('timestamp', $record);
        self::assertArrayHasKey('service', $record);
        self::assertArrayHasKey('level', $record);
        self::assertArrayHasKey('event', $record);
        self::assertArrayHasKey('correlationId', $record);
        self::assertArrayHasKey('userId', $record);
        self::assertArrayHasKey('message', $record);
        self::assertArrayHasKey('context', $record);

        self::assertSame('togare-core', $record['service']);
        self::assertSame('info', $record['level']);
        self::assertSame('djen.sync.completed', $record['event']);
        self::assertSame('sync ok', $record['message']);
        self::assertSame(['advCount' => 12], $record['context']);
        self::assertNull($record['userId']);
        // timestamp formato ISO 8601 com offset
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/',
            $record['timestamp'],
        );
    }

    #[RunInSeparateProcess]
    public function testUsesCorrelationIdFromServerVar(): void
    {
        $_SERVER['HTTP_X_TOGARE_CORRELATION_ID'] = 'test-corr-id-123';

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        TogareLogger::init('togare-core', null, $stdout, $stderr);
        TogareLogger::event('info', 'smoke', 'ok', []);

        rewind($stdout);
        $record = json_decode(trim(stream_get_contents($stdout)), true);
        self::assertSame('test-corr-id-123', $record['correlationId']);
    }

    #[RunInSeparateProcess]
    public function testGeneratesUuidWhenNoRequestContext(): void
    {
        unset($_SERVER['HTTP_X_TOGARE_CORRELATION_ID']);

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        TogareLogger::init('togare-core', null, $stdout, $stderr);
        TogareLogger::event('info', 'a', 'primeira', []);
        TogareLogger::event('info', 'b', 'segunda', []);

        rewind($stdout);
        $lines = array_filter(explode("\n", stream_get_contents($stdout)));
        self::assertCount(2, $lines);

        $r1 = json_decode($lines[0] ?? '', true);
        $r2 = json_decode($lines[1] ?? '', true);

        self::assertNotNull($r1['correlationId']);
        self::assertSame($r1['correlationId'], $r2['correlationId'], 'Mesmo processo, mesmo correlationId cacheado.');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $r1['correlationId'],
            'Deve ser UUID v4 canônico.',
        );
    }

    #[RunInSeparateProcess]
    public function testInvalidLevelThrows(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        TogareLogger::init('togare-core', null, $stdout, $stderr);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Level de log inválido: 'fatal'");

        TogareLogger::event('fatal', 'qualquer', 'msg', []);
    }

    #[RunInSeparateProcess]
    public function testStreamSplitByLevel(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        TogareLogger::init('togare-core', null, $stdout, $stderr);

        TogareLogger::event('info', 'i1', 'info msg', []);
        TogareLogger::event('error', 'e1', 'error msg', []);

        rewind($stdout);
        rewind($stderr);

        $outLines = array_filter(explode("\n", stream_get_contents($stdout)));
        $errLines = array_filter(explode("\n", stream_get_contents($stderr)));

        self::assertCount(1, $outLines);
        self::assertCount(1, $errLines);

        $outRec = json_decode($outLines[0] ?? '', true);
        $errRec = json_decode($errLines[0] ?? '', true);

        self::assertSame('info', $outRec['level']);
        self::assertSame('error', $errRec['level']);
        // error tem trace
        self::assertArrayHasKey('trace', $errRec);
        self::assertIsArray($errRec['trace']);
        // info não tem trace
        self::assertArrayNotHasKey('trace', $outRec);
    }
}
