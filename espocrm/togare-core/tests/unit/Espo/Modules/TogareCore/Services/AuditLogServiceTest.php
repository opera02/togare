<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Migration\V006__create_togare_audit_log;
use Espo\Modules\TogareCore\Services\AuditLogService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC3, AC4, AC5 — AuditLogService.
 *
 * SQLite in-memory + Migration V006 aplicada inline. RunInSeparateProcess
 * porque AuditLogService → TogareLogger (singleton estático).
 */
final class AuditLogServiceTest extends TestCase
{
    private PDO $pdo;
    private AuditLogService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Aplica V006 (DDL compat com SQLite — DATETIME(3) vira DATETIME).
        (new V006__create_togare_audit_log())->up($this->pdo);

        // Reset singletons + streams de memória.
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test-audit-log', null, $stdout, $stderr);

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $this->service = new AuditLogService($em);
    }

    private function fetchAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM togare_audit_log ORDER BY occurred_at ASC');
        return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    #[RunInSeparateProcess]
    public function testLogPersisteRowComCamposObrigatorios(): void
    {
        $this->service->log('test.event', 'User', 'abc123', ['k' => 'v']);

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);

        $row = $rows[0];
        self::assertSame('test.event', $row['event']);
        self::assertSame('User', $row['entity_type']);
        self::assertSame('abc123', $row['entity_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row['id']);
        self::assertSame('{"k":"v"}', $row['context_json']);
        // occurred_at no formato Y-m-d H:i:s.v (com .ms)
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/',
            $row['occurred_at'],
        );
    }

    #[RunInSeparateProcess]
    public function testLogTruncaUserAgentEm500(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = \str_repeat('A', 600);

        $this->service->log('test.ua', 'User', 'abc');

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame(500, \mb_strlen((string) $rows[0]['user_agent']));
    }

    #[RunInSeparateProcess]
    public function testLogSerializaContextComoJsonUnicode(): void
    {
        $this->service->log('test.unicode', 'User', 'abc', ['nome' => 'João Petição']);

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        // JSON_UNESCAPED_UNICODE preserva acentos (não vira ã etc.).
        self::assertSame('{"nome":"João Petição"}', $rows[0]['context_json']);
    }

    #[RunInSeparateProcess]
    public function testLogValidaEventLengthLimite120(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'event' deve ter 1..120 chars");

        $this->service->log(\str_repeat('x', 121), 'User', null);
    }

    #[RunInSeparateProcess]
    public function testLogValidaEventVazio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'event' deve ter 1..120 chars");

        $this->service->log('', 'User', null);
    }

    #[RunInSeparateProcess]
    public function testLogValidaEntityTypeLengthLimite80(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'entityType' deve ter 1..80 chars");

        $this->service->log('valid.event', \str_repeat('y', 81), null);
    }

    #[RunInSeparateProcess]
    public function testLogValidaEntityTypeVazio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'entityType' deve ter 1..80 chars");

        $this->service->log('valid.event', '', null);
    }

    #[RunInSeparateProcess]
    public function testLogContextVazioGravaContextNull(): void
    {
        $this->service->log('test.empty', 'User', 'abc');

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['context_json']);
    }

    #[RunInSeparateProcess]
    public function testLogPdoErrorNaoPropagaParaCaller(): void
    {
        $this->expectNotToPerformAssertions();

        // Drop a tabela para forçar PDOException no INSERT.
        $this->pdo->exec('DROP TABLE togare_audit_log');

        // Não deve lançar — auditoria nunca quebra o flow de negócio (NFR9).
        $this->service->log('test.error', 'User', 'abc', ['x' => 1]);
    }

    #[RunInSeparateProcess]
    public function testLogChamadasDuplicadasGeram2Rows(): void
    {
        // Auditoria prefere redundância à omissão (NÃO é idempotente).
        $this->service->log('dup.event', 'User', 'abc', ['k' => 'v']);
        $this->service->log('dup.event', 'User', 'abc', ['k' => 'v']);

        self::assertCount(2, $this->fetchAll());
    }

    #[RunInSeparateProcess]
    public function testLogEntityIdNullParaEventoGlobal(): void
    {
        $this->service->log('config.security.changed', 'Settings', null, ['key' => 'auth2FA']);

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['entity_id']);
        self::assertSame('Settings', $rows[0]['entity_type']);
    }

    #[RunInSeparateProcess]
    public function testLogPreservaCorrelationIdDoServerVar(): void
    {
        $_SERVER['HTTP_X_TOGARE_CORRELATION_ID'] = 'corr-abc-123';

        $this->service->log('test.corr', 'User', 'abc');

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('corr-abc-123', $rows[0]['correlation_id']);
    }

    #[RunInSeparateProcess]
    public function testLogResolveIpDoRemoteAddr(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->service->log('test.ip', 'User', 'abc');

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame('203.0.113.7', $rows[0]['ip_address']);
    }

    #[RunInSeparateProcess]
    public function testLogTruncaIpAddressEm45(): void
    {
        $_SERVER['REMOTE_ADDR'] = \str_repeat('f', 50);

        $this->service->log('test.ip.long', 'User', 'abc');

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame(45, \mb_strlen((string) $rows[0]['ip_address']));
    }

    #[RunInSeparateProcess]
    public function testLogTruncaCorrelationIdEm64(): void
    {
        $_SERVER['HTTP_X_TOGARE_CORRELATION_ID'] = \str_repeat('c', 100);

        $this->service->log('test.corr.long', 'User', 'abc');

        $rows = $this->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame(64, \mb_strlen((string) $rows[0]['correlation_id']));
    }
}
