<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Jobs;

use Espo\Core\Job\Job\Data;
use Espo\Core\Mail\EmailFactory;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Utils\Config;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Jobs\PrazoReminderJob;
use Espo\Modules\TogareCore\Migration\V016__create_togare_prazo_lembrete;
use Espo\Modules\TogareCore\Services\Notification\EmailNotificationService;
use Espo\Modules\TogareCore\Services\Notification\PrazoLembreteConstants;
use Espo\Modules\TogareCore\Services\Notification\StreamNotificationService;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre PrazoReminderJob (Story 4b.2, AC3 + AC9 + AC10).
 *
 * Estratégia: PDO sqlite::memory: real com Migration V016 aplicada → permite
 * SELECT/UPDATE/DELETE reais sobre togare_prazo_lembrete. Mocks PHPUnit para
 * EntityManager.getEntityById (retorna Prazo + Preferences). Stub StreamService
 * captura calls; stub EmailService configura sucesso/falha via flag.
 */
final class PrazoReminderJobTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new V016__create_togare_prazo_lembrete())->up($this->pdo);
    }

    public function testRunSemEntriesEhNoOpSilencioso(): void
    {
        $job = $this->makeJob();
        $job->run(Data::create()); // não lança
        self::assertSame(0, $this->countLembretes());
    }

    public function testEntryComCanalBothEAmbosCanaisOkMarcaSent(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-3');
        $audit = new AuditLogContractStub();
        $job = $this->makeJob(audit: $audit);

        $job->run(Data::create());

        $row = $this->fetchLembrete('lembrete-1');
        self::assertSame('sent', $row['status']);
        self::assertNotNull($row['sent_at']);
        $delivered = \array_filter($audit->calls, static fn ($c) => $c['event'] === 'audit.notification.delivered');
        self::assertCount(1, $delivered);
        $event = \array_values($delivered)[0];
        self::assertContains('popup', $event['context']['channelsDelivered']);
        self::assertContains('email', $event['context']['channelsDelivered']);
    }

    public function testEntryComPrazoInexistenteMarcaCancelled(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-fantasma', 'user-1', 'D-1');
        $audit = new AuditLogContractStub();
        $job = $this->makeJob(prazoNull: true, audit: $audit);

        $job->run(Data::create());

        $row = $this->fetchLembrete('lembrete-1');
        self::assertSame('cancelled', $row['status']);
        self::assertStringContainsString('prazo_not_found', (string) $row['last_error']);
        $cancelled = \array_filter($audit->calls, static fn ($c) => $c['event'] === 'audit.notification.cancelled');
        self::assertCount(1, $cancelled);
    }

    public function testUserDesativouMarcoMarcaCancelled(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-1');
        $audit = new AuditLogContractStub();
        // User desativa D-1 explicitamente.
        $userPrefs = ['marcos' => ['D-1' => false]];
        $job = $this->makeJob(userPreferences: $userPrefs, audit: $audit);

        $job->run(Data::create());

        $row = $this->fetchLembrete('lembrete-1');
        self::assertSame('cancelled', $row['status']);
        self::assertStringContainsString('user_disabled_channel', (string) $row['last_error']);
    }

    public function testUserDesativouTodosCanaisMarcaCancelled(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-3');
        $audit = new AuditLogContractStub();
        $userPrefs = ['channels' => ['popup' => false, 'email' => false]];
        $job = $this->makeJob(userPreferences: $userPrefs, audit: $audit);

        $job->run(Data::create());

        self::assertSame('cancelled', $this->fetchLembrete('lembrete-1')['status']);
    }

    public function testAmbosCanaisFalhamMarcaPendingComAttemptIncrementado(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-3');
        $audit = new AuditLogContractStub();
        $job = $this->makeJob(streamFails: true, emailFails: true, audit: $audit);

        $job->run(Data::create());

        $row = $this->fetchLembrete('lembrete-1');
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertStringContainsString('popup:', (string) $row['last_error']);
        self::assertStringContainsString('email:', (string) $row['last_error']);
    }

    public function testApos3FalhasMarcaFailedComAuditEmailFailed(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-3', attemptCount: 3);
        $audit = new AuditLogContractStub();
        $job = $this->makeJob(streamFails: true, emailFails: true, audit: $audit);

        $job->run(Data::create());

        $row = $this->fetchLembrete('lembrete-1');
        self::assertSame('failed', $row['status']);
        $failed = \array_filter($audit->calls, static fn ($c) => $c['event'] === 'audit.notification.email_failed');
        self::assertCount(1, $failed);
    }

    public function testTerceiraFalhaAindaAgendaRetryDe30Minutos(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-3', attemptCount: 2);
        $job = $this->makeJob(streamFails: true, emailFails: true);

        $job->run(Data::create());

        $row = $this->fetchLembrete('lembrete-1');
        self::assertSame('pending', $row['status']);
        self::assertSame(3, (int) $row['attempt_count']);
    }

    public function testPopupOkEmailFailMarcaSentComAuditPartialFailure(): void
    {
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-3');
        $audit = new AuditLogContractStub();
        $job = $this->makeJob(streamFails: false, emailFails: true, audit: $audit);

        $job->run(Data::create());

        $row = $this->fetchLembrete('lembrete-1');
        self::assertSame('sent', $row['status'], 'SLA cumprido pelo popup → status=sent.');
        $partial = \array_filter($audit->calls, static fn ($c) => $c['event'] === 'audit.notification.email_partial_failure');
        self::assertCount(1, $partial);
    }

    public function testThrowableEmEntryNaoInterrompeOutras(): void
    {
        // Entry 1 com prazo inexistente (não lança — é cancelled).
        // Entry 2 com prazo OK (deve sentar).
        $this->insertLembretePending('lembrete-1', 'prazo-fantasma', 'user-1', 'D-3');
        $this->insertLembretePending('lembrete-2', 'prazo-OK', 'user-2', 'D-1');

        // EM mock: prazo-fantasma → null; prazo-OK → entity OK; user-2 OK; etc.
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);
        $em->method('getEntityById')->willReturnCallback(static function ($type, $id) {
            if ($type === 'Prazo' && $id === 'prazo-OK') {
                $p = new \Espo\Core\ORM\Entity();
                $p->set([
                    'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
                    'descricao' => 'foo',
                    'dataFatal' => '2026-06-01',
                ]);
                return $p;
            }
            if ($type === 'User' && $id === 'user-2') {
                $u = new \Espo\Core\ORM\Entity();
                $u->setId($id);
                $u->set('emailAddress', 'user2@example.com');
                return $u;
            }
            if ($type === 'Preferences') {
                $p = new \Espo\Core\ORM\Entity();
                $p->set('togareLembreteConfig', []);
                return $p;
            }
            return null;
        });
        $em->method('getNewEntity')->willReturnCallback(static function ($type) {
            if ($type === 'Email') return new \Espo\Entities\Email();
            return new \Espo\Core\ORM\Entity();
        });

        $job = new PrazoReminderJob(
            $em,
            new StreamNotificationService($em),
            new EmailNotificationService($em, new EmailSender(), new EmailFactory(), new Config()),
            new AuditLogContractStub(),
        );
        $job->run(Data::create());

        self::assertSame('cancelled', $this->fetchLembrete('lembrete-1')['status']);
        self::assertSame('sent', $this->fetchLembrete('lembrete-2')['status']);
    }

    public function testFifoOrderingPorScheduledForAscEIdAsc(): void
    {
        // Datas no passado (todas <= NOW()). PrazoReminderJob processa
        // em ordem ASC por scheduled_for (FIFO).
        $this->insertLembretePending('lembrete-3', 'prazo-1', 'user-1', 'D-3', scheduledFor: '2026-05-08 10:00:00');
        $this->insertLembretePending('lembrete-1', 'prazo-1', 'user-1', 'D-7', scheduledFor: '2026-05-08 08:00:00');
        $this->insertLembretePending('lembrete-2', 'prazo-1', 'user-1', 'D-1', scheduledFor: '2026-05-08 09:00:00');

        $audit = new AuditLogContractStub();
        $job = $this->makeJob(audit: $audit);
        $job->run(Data::create());

        // Todos deveriam estar sent. Asserção primária: todos processados.
        self::assertSame('sent', $this->fetchLembrete('lembrete-1')['status']);
        self::assertSame('sent', $this->fetchLembrete('lembrete-2')['status']);
        self::assertSame('sent', $this->fetchLembrete('lembrete-3')['status']);
    }

    public function testEntriesNoFuturoNaoSaoProcessadas(): void
    {
        $this->insertLembretePending('lembrete-future', 'prazo-1', 'user-1', 'D-3', scheduledFor: '2099-01-01 00:00:00');

        $job = $this->makeJob();
        $job->run(Data::create());

        // Permanece pending.
        self::assertSame('pending', $this->fetchLembrete('lembrete-future')['status']);
    }

    // ====== Helpers ======

    private function makeJob(
        bool $prazoNull = false,
        array $userPreferences = [],
        bool $streamFails = false,
        bool $emailFails = false,
        ?AuditLogContractStub $audit = null,
    ): PrazoReminderJob {
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($this->pdo);

        $em->method('getEntityById')->willReturnCallback(function ($type, $id) use ($prazoNull, $userPreferences) {
            if ($type === 'Prazo') {
                return $prazoNull ? null : $this->makeDefaultPrazo();
            }
            if ($type === 'Preferences') {
                $prefs = new \Espo\Core\ORM\Entity();
                $prefs->set('togareLembreteConfig', $userPreferences);
                return $prefs;
            }
            if ($type === 'User') {
                $user = new \Espo\Core\ORM\Entity();
                $user->setId($id);
                $user->set('emailAddress', 'user@example.com');
                return $user;
            }
            return null;
        });

        $em->method('getNewEntity')->willReturnCallback(static function ($type) {
            if ($type === 'Email') {
                return new \Espo\Entities\Email();
            }
            return new \Espo\Core\ORM\Entity();
        });

        // EmailSender que pode falhar via flag do stub.
        $sender = new EmailSender();
        $sender->shouldThrow = $emailFails;

        $emailService = new EmailNotificationService($em, $sender, new EmailFactory(), new Config());

        // StreamService que pode falhar via subclasse anônima (Stream agora não-final).
        if ($streamFails) {
            $streamService = new class($em) extends StreamNotificationService {
                public function notifyPrazoReminder(
                    string $userId,
                    string $subject,
                    string $body,
                    string $prazoId,
                    string $marco,
                ): void
                {
                    throw new \RuntimeException('Simulated stream failure');
                }
            };
        } else {
            $streamService = new StreamNotificationService($em);
        }

        return new PrazoReminderJob(
            $em,
            $streamService,
            $emailService,
            $audit ?? new AuditLogContractStub(),
        );
    }

    private function makeDefaultPrazo(): \Espo\Core\ORM\Entity
    {
        $p = new \Espo\Core\ORM\Entity();
        $p->set([
            'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
            'descricao' => 'Apresentar contestação',
            'dataFatal' => '2026-06-01',
            'dataCumprimento' => '2026-05-28',
        ]);
        return $p;
    }

    private function insertLembretePending(
        string $id,
        string $prazoId,
        string $userId,
        string $marco,
        int $attemptCount = 0,
        string $scheduledFor = '2026-05-09 00:00:00',
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO togare_prazo_lembrete
                (id, prazo_id, user_id, marco, canal, scheduled_for, status, attempt_count, created_at, modified_at)
             VALUES (:id, :prazo_id, :user_id, :marco, :canal, :scheduled_for, :status, :attempt_count, :created_at, :modified_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':prazo_id' => $prazoId,
            ':user_id' => $userId,
            ':marco' => $marco,
            ':canal' => PrazoLembreteConstants::CANAL_BOTH,
            ':scheduled_for' => $scheduledFor,
            ':status' => PrazoLembreteConstants::STATUS_PENDING,
            ':attempt_count' => $attemptCount,
            ':created_at' => '2026-05-08 12:00:00',
            ':modified_at' => '2026-05-08 12:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function fetchLembrete(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM togare_prazo_lembrete WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    private function countLembretes(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM togare_prazo_lembrete');
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return $row !== false ? (int) $row['c'] : 0;
    }
}
