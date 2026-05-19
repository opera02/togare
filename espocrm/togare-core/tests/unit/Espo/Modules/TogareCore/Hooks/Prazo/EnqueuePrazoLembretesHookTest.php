<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Hooks\Prazo\EnqueuePrazoLembretesHook;
use Espo\Modules\TogareCore\Migration\V016__create_togare_prazo_lembrete;
use Espo\Modules\TogareCore\Services\Calendar\BrazilianBusinessCalendar;
use Espo\Modules\TogareCore\Services\Notification\PrazoLembreteConstants;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PDO;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre Story 4b.2 EnqueuePrazoLembretesHook (AC2 + AC7).
 *
 * Estratégia: PDO sqlite::memory: real com Migration V016 aplicada → permite
 * testar SQL real (INSERT IGNORE, UNIQUE, UPDATE batch). Mock EntityManager
 * que retorna esse PDO em getPDO(). Calendar é instância real (puro).
 *
 * Hook roda em isolation: tabelas user/user_role/role NÃO existem → query
 * findSocioAdminUserIds retorna [] silenciosamente (defensivo). Para cenários
 * que precisam de Sócio/Admin, criamos mini-tabelas user/user_role/role no
 * SQLite e populamos.
 */
final class EnqueuePrazoLembretesHookTest extends TestCase
{
    private PDO $pdo;
    private EntityManager $em;
    private AuditLogContractStub $audit;
    private EnqueuePrazoLembretesHook $hook;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Aplica Migration V016.
        (new V016__create_togare_prazo_lembrete())->up($this->pdo);

        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getPDO')->willReturn($this->pdo);

        $this->audit = new AuditLogContractStub();
        $this->hook = new EnqueuePrazoLembretesHook(
            $this->em,
            new BrazilianBusinessCalendar(),
            $this->audit,
        );
    }

    public function testHookOrderEh40(): void
    {
        // Vinculante: entre AutoLink=20 e Audit=50.
        self::assertSame(40, EnqueuePrazoLembretesHook::$order);
    }

    public function testEntidadeNaoPrazoEhNoOp(): void
    {
        $other = new \Espo\Core\ORM\Entity();
        $other->setId('outra-001');
        $other->set('status', 'pendente');

        $this->hook->afterSave($other, SaveOptions::create());

        self::assertSame(0, $this->countLembretes());
    }

    public function testCriacaoComStatusPendenteEnfileira4MarcosParaAdvogado(): void
    {
        // Story 4b.3: 4 marcos (D-7/D-3/D-1/D-0) × 1 destinatário = 4 entries.
        // dataFatal = seg 01/06/2026.
        // subtractBusinessDays itera 1 a 1; conta sex/qui/qua/ter/seg como úteis.
        // -0 = mesmo dia (D-0): seg 01/06.
        // -1 = sex 29/05 (1)  -2 = qui 28/05 (2)  -3 = qua 27/05 (3)
        // -4 = ter 26/05 (4)  -5 = seg 25/05 (5)  -6 = sex 22/05 (6)
        // -7 = qui 21/05 (7)
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');

        $this->hook->afterSave($prazo, SaveOptions::create());

        self::assertSame(4, $this->countLembretes(), 'Sem Sócio/Admin no setUp; só assignedUser → 4 marcos × 1 dest (Story 4b.3).');

        $rows = $this->fetchLembretes('prazo-001');
        $marcos = \array_map(static fn ($r) => $r['marco'], $rows);
        \sort($marcos);
        self::assertSame(['D-0', 'D-1', 'D-3', 'D-7'], $marcos);

        // Valida cálculo de datas (UTC após conversão de hora BRT).
        // D-7/D-3/D-1: 09:00 America/Sao_Paulo = 12:00 UTC (BRT é UTC-3).
        // Story 4b.3 D-0: 00:05 America/Sao_Paulo = 03:05 UTC (BRT é UTC-3).
        $byMarco = [];
        foreach ($rows as $r) {
            $byMarco[$r['marco']] = $r;
        }
        self::assertStringContainsString('2026-05-21 12:00:00', $byMarco['D-7']['scheduled_for']);
        self::assertStringContainsString('2026-05-27 12:00:00', $byMarco['D-3']['scheduled_for']);
        self::assertStringContainsString('2026-05-29 12:00:00', $byMarco['D-1']['scheduled_for']);
        self::assertStringContainsString('2026-06-01 03:05:00', $byMarco['D-0']['scheduled_for']);

        // Canal default = both para todos os marcos (incluindo D-0).
        self::assertSame('both', $byMarco['D-7']['canal']);
        self::assertSame('both', $byMarco['D-0']['canal']);
        // Status default = pending.
        self::assertSame('pending', $byMarco['D-7']['status']);
        self::assertSame('pending', $byMarco['D-0']['status']);
        self::assertSame(0, (int) $byMarco['D-7']['attempt_count']);
        self::assertSame(0, (int) $byMarco['D-0']['attempt_count']);

        // Audit log: 4 entries `audit.notification.scheduled` (Story 4b.3).
        $scheduledCalls = \array_filter(
            $this->audit->calls,
            static fn (array $c) => $c['event'] === 'audit.notification.scheduled',
        );
        self::assertCount(4, $scheduledCalls);
    }

    public function testCriacaoComStatusRascunhoNaoEnfileira(): void
    {
        $prazo = $this->makeNewPrazo([
            'dataFatal' => '2026-06-01',
            'assignedUserId' => 'adv-001',
            'status' => Prazo::STATUS_RASCUNHO,
        ]);
        $prazo->setId('prazo-001');

        $this->hook->afterSave($prazo, SaveOptions::create());

        self::assertSame(0, $this->countLembretes(), 'Rascunho não enfileira (família pendente only).');
    }

    public function testCriacaoIncluiSocioAdminQuandoExistente(): void
    {
        $this->createMiniUserRoleSchema();
        $this->seedUserComRole('socio-001', 'Sócio/Admin', isActive: true);
        $this->seedUserComRole('socio-002', 'Sócio/Admin', isActive: true);
        $this->seedUserComRole('socio-inativo', 'Sócio/Admin', isActive: false); // não conta
        $this->seedUserComRole('outro-role', 'Advogado', isActive: true); // não conta

        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');

        $this->hook->afterSave($prazo, SaveOptions::create());

        // Story 4b.3: 4 marcos × 3 destinatários (adv-001 + socio-001 + socio-002) = 12.
        self::assertSame(12, $this->countLembretes());

        $userIds = \array_unique(\array_map(
            static fn ($r) => $r['user_id'],
            $this->fetchLembretes('prazo-001'),
        ));
        \sort($userIds);
        self::assertSame(['adv-001', 'socio-001', 'socio-002'], $userIds);
    }

    public function testCriacaoSemAssignedUserSoEnfileiraSocioAdmin(): void
    {
        $this->createMiniUserRoleSchema();
        $this->seedUserComRole('socio-001', 'Sócio/Admin', isActive: true);

        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => null]);
        $prazo->setId('prazo-001');

        $this->hook->afterSave($prazo, SaveOptions::create());

        // Story 4b.3: 4 marcos × 1 sócio = 4.
        self::assertSame(4, $this->countLembretes(), '4 marcos × 1 sócio = 4.');
        $userIds = \array_unique(\array_map(
            static fn ($r) => $r['user_id'],
            $this->fetchLembretes('prazo-001'),
        ));
        self::assertSame(['socio-001'], $userIds);
    }

    public function testCriacaoSemDestinatariosEhNoOp(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => null]);
        $prazo->setId('prazo-001');

        $this->hook->afterSave($prazo, SaveOptions::create());

        self::assertSame(0, $this->countLembretes(), 'Sem advogado e sem Sócio/Admin → 0 lembretes.');
    }

    public function testTransicaoStatusParaAtrasadoReagendadoEnfileiraMarco(): void
    {
        $this->createMiniUserRoleSchema();
        $this->seedUserComRole('socio-001', 'Sócio/Admin', isActive: true);

        // Story 4b.3: pré-popula 4 marcos D-7/D-3/D-1/D-0 × 2 dest = 8 entries
        // (adv-001 + socio-001). Devem permanecer pending após status_dirigido.
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());
        $countBefore = $this->countLembretes();
        $this->audit->calls = []; // limpar para foco do teste

        // Simula transição: status muda de pendente → atrasado_reagendado.
        $prazoTransicao = $this->makeExistingPrazo([
            'fetched' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001'],
            'set' => ['status' => Prazo::STATUS_REAGENDADO],
            'id' => 'prazo-001',
        ]);

        $this->hook->afterSave($prazoTransicao, SaveOptions::create());

        // Espera 2 entries novas (adv-001 + socio-001) com marco status_atrasado_reagendado.
        self::assertSame($countBefore + 2, $this->countLembretes());
        $rows = $this->fetchLembretesByMarco('prazo-001', 'status_atrasado_reagendado');
        self::assertCount(2, $rows);

        // Originais D-7/D-3/D-1/D-0 ainda pending (não cancelados — status_dirigido NÃO é final).
        // Story 4b.3: 4 marcos × 2 dest = 8 + 2 status_dirigido = 10.
        $pendingD = $this->countLembretes(['status' => 'pending']);
        self::assertGreaterThanOrEqual(2 + 8, $pendingD);
    }

    public function testTransicaoParaProtocoladoCancelaTodosPendentes(): void
    {
        // Story 4b.3: pré-popula 4 lembretes D-X (D-7/D-3/D-1/D-0) em pending.
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());
        self::assertSame(4, $this->countLembretes(['status' => 'pending']));
        $this->audit->calls = [];

        // Transição para protocolado.
        $prazoFinal = $this->makeExistingPrazo([
            'fetched' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001'],
            'set' => ['status' => Prazo::STATUS_PROTOCOLADO],
            'id' => 'prazo-001',
        ]);
        $this->hook->afterSave($prazoFinal, SaveOptions::create());

        // Decisão D1.1 — cancel = HARD DELETE; togare_audit_log carrega o histórico.
        self::assertSame(0, $this->countLembretes(['status' => 'pending']));
        self::assertSame(0, $this->countLembretes(), 'Cancel hard-deletou as 4 entries (Story 4b.3 inclui D-0).');

        // Audit: 4 cancelled events (Story 4b.3 — incluindo D-0).
        $cancelCalls = \array_filter(
            $this->audit->calls,
            static fn (array $c) => $c['event'] === 'audit.notification.cancelled',
        );
        self::assertCount(4, $cancelCalls);
        foreach ($cancelCalls as $c) {
            self::assertSame('status_final', $c['context']['reason']);
        }
        // Story 4b.3: confirma que D-0 foi cancelado entre os 4.
        $marcosCancelados = \array_map(
            static fn (array $c) => $c['context']['marco'],
            $cancelCalls,
        );
        \sort($marcosCancelados);
        self::assertSame(['D-0', 'D-1', 'D-3', 'D-7'], $marcosCancelados);
    }

    public function testDataFatalMudouCancelaERefazComNovaData(): void
    {
        // Story 4b.3: pré-popula 4 marcos D-7/D-3/D-1/D-0 com dataFatal=2026-06-01.
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());
        self::assertSame(4, $this->countLembretes(['status' => 'pending']));

        // Mudança: dataFatal=2026-06-15.
        $prazoEdit = $this->makeExistingPrazo([
            'fetched' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001'],
            'set' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-15', 'assignedUserId' => 'adv-001'],
            'id' => 'prazo-001',
        ]);
        $this->hook->afterSave($prazoEdit, SaveOptions::create());

        // Decisão D1.1 — cancel = HARD DELETE. Após cancel + re-enqueue: só 4 novas (Story 4b.3).
        self::assertSame(4, $this->countLembretes(['status' => 'pending']));
        self::assertSame(4, $this->countLembretes(), 'Total = 4 novas (originais hard-deletadas).');

        // Novos pending devem ter scheduled_for derivado de 2026-06-15.
        $pendingRows = $this->fetchLembretesByStatus('prazo-001', 'pending');
        $byMarco = [];
        foreach ($pendingRows as $r) {
            $byMarco[$r['marco']] = $r;
        }
        // dataFatal=2026-06-15 (seg).
        // -0 = mesmo dia (D-0): seg 15/06.
        // -1 = sex 12/06 (1)  -2 = qui 11/06 (2)  -3 = qua 10/06 (3)
        // -4 = ter 09/06 (4)  -5 = seg 08/06 (5)  -6 = sex 05/06 (6)
        // -7 = qui 04/06 é Corpus Christi → pula → qua 03/06 (7).
        self::assertStringContainsString('2026-06-03', $byMarco['D-7']['scheduled_for']);
        self::assertStringContainsString('2026-06-10', $byMarco['D-3']['scheduled_for']);
        self::assertStringContainsString('2026-06-12', $byMarco['D-1']['scheduled_for']);
        // Story 4b.3: D-0 é mesmo dia em hora 00:05 BRT = 03:05 UTC.
        self::assertStringContainsString('2026-06-15 03:05:00', $byMarco['D-0']['scheduled_for']);
    }

    public function testAssignedUserMudouCancelaERefazComNovoDestinatario(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-old']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());
        self::assertSame(4, $this->countLembretes(['status' => 'pending']));

        // Mudança de assignedUser.
        $prazoEdit = $this->makeExistingPrazo([
            'fetched' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-old'],
            'set' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-new'],
            'id' => 'prazo-001',
        ]);
        $this->hook->afterSave($prazoEdit, SaveOptions::create());

        // Story 4b.3: Decisão D1.1 — originais hard-deletadas + 4 novas para adv-new.
        self::assertSame(4, $this->countLembretes(), 'Total = 4 novas (originais do adv-old hard-deletadas).');
        $newPending = $this->fetchLembretesByStatus('prazo-001', 'pending');
        self::assertCount(4, $newPending);
        foreach ($newPending as $r) {
            self::assertSame('adv-new', $r['user_id']);
        }
    }

    public function testReSaveSemMudancaSensivelEhNoOp(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());
        self::assertSame(4, $this->countLembretes());

        // Re-save sem mudança em status/dataFatal/assignedUserId — só descricao.
        $prazoReSave = $this->makeExistingPrazo([
            'fetched' => [
                'status' => Prazo::STATUS_PENDENTE,
                'dataFatal' => '2026-06-01',
                'assignedUserId' => 'adv-001',
                'descricao' => 'velha',
            ],
            'set' => [
                'status' => Prazo::STATUS_PENDENTE, // não mudou
                'dataFatal' => '2026-06-01',         // não mudou
                'assignedUserId' => 'adv-001',       // não mudou
                'descricao' => 'nova',               // mudou (não-sensível)
            ],
            'id' => 'prazo-001',
        ]);
        $this->hook->afterSave($prazoReSave, SaveOptions::create());

        // Story 4b.3: conta total inalterada — UNIQUE INDEX bloqueia + cenário 1/2 não dispara.
        self::assertSame(4, $this->countLembretes());
    }

    public function testReExecucaoIdempotenteNaoDuplica(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');

        // Simula 2x execução do mesmo cenário (race / re-trigger).
        $this->hook->afterSave($prazo, SaveOptions::create());
        $this->hook->afterSave($prazo, SaveOptions::create());

        self::assertSame(4, $this->countLembretes(), 'UNIQUE INDEX impede duplicação (Story 4b.3 — 4 marcos).');
    }

    public function testHookNaoBloqueiaSaveQuandoEntityManagerThrows(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willThrowException(new \RuntimeException('DB down'));
        $hook = new EnqueuePrazoLembretesHook(
            $em,
            new BrazilianBusinessCalendar(),
            new AuditLogContractStub(),
        );

        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');

        // Não deve lançar — try/catch \Throwable defensivo.
        $hook->afterSave($prazo, SaveOptions::create());
        self::assertTrue(true, 'Hook engoliu falha do EntityManager sem propagar.');
    }

    public function testDataFatalInvalidaSkipsDeadlineEnqueue(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => 'not-a-date', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');

        // Não deve lançar; deadline marcos não enfileirados.
        $this->hook->afterSave($prazo, SaveOptions::create());

        self::assertSame(0, $this->countLembretes());
    }

    public function testStatusDirigidoFinalNaoEnfileira(): void
    {
        // ciencia_renuncia é status final → cancela pending mas NÃO enfileira marco.
        $this->createMiniUserRoleSchema();
        $this->seedUserComRole('socio-001', 'Sócio/Admin', isActive: true);

        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());
        $this->audit->calls = [];

        $prazoFinal = $this->makeExistingPrazo([
            'fetched' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001'],
            'set' => ['status' => Prazo::STATUS_CIENCIA_RENUNCIA],
            'id' => 'prazo-001',
        ]);
        $this->hook->afterSave($prazoFinal, SaveOptions::create());

        // Todos cancelled, nenhum novo marco status_dirigido (status_final tem early-exit).
        self::assertSame(0, $this->countLembretes(['status' => 'pending']));
        $rows = $this->fetchLembretes('prazo-001');
        $marcosNovos = \array_filter(
            \array_map(static fn ($r) => $r['marco'], $rows),
            static fn ($m) => \str_starts_with((string) $m, 'status_'),
        );
        self::assertSame([], \array_values($marcosNovos), 'Status final NÃO enfileira marco status_dirigido (early-exit).');
    }

    // ====== Story 4b.3 — testes D-0 dedicados ======

    /**
     * Story 4b.3 AC2 — Prazo criado pendente com 1 advogado + 1 sócio
     * enfileira 8 entries (4 marcos × 2 destinatários).
     */
    public function testCriacaoPendenteComSocioAdminEnfileira8Entries4Marcos(): void
    {
        $this->createMiniUserRoleSchema();
        $this->seedUserComRole('socio-001', 'Sócio/Admin', isActive: true);

        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');

        $this->hook->afterSave($prazo, SaveOptions::create());

        // Story 4b.3: 4 marcos (D-7/D-3/D-1/D-0) × 2 destinatários (adv-001 + socio-001) = 8.
        self::assertSame(8, $this->countLembretes(), '4 marcos × 2 destinatários = 8 entries (Story 4b.3).');

        $rows = $this->fetchLembretes('prazo-001');
        $marcosByUser = [];
        foreach ($rows as $r) {
            $marcosByUser[$r['user_id']][] = $r['marco'];
        }
        foreach (['adv-001', 'socio-001'] as $uid) {
            \sort($marcosByUser[$uid]);
            self::assertSame(['D-0', 'D-1', 'D-3', 'D-7'], $marcosByUser[$uid], "user {$uid} recebeu os 4 marcos");
        }

        // Audit: 8 scheduled events.
        $scheduledCalls = \array_filter(
            $this->audit->calls,
            static fn (array $c) => $c['event'] === 'audit.notification.scheduled',
        );
        self::assertCount(8, $scheduledCalls);
    }

    /**
     * Story 4b.3 AC2 — D-0 entry tem scheduled_for em 00:05 BRT (NÃO 09:00).
     * Validação literal do mapping HORA_DISPARO_BY_MARCO + MINUTO_DISPARO_BY_MARCO.
     *
     * 00:05 America/Sao_Paulo (UTC-3) = 03:05 UTC do MESMO dia.
     */
    public function testD0EntryTemScheduledFor0005BrtConvertidoPara0305Utc(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');

        $this->hook->afterSave($prazo, SaveOptions::create());

        $d0 = $this->fetchLembretesByMarco('prazo-001', 'D-0');
        self::assertCount(1, $d0);
        // dataFatal=2026-06-01 BRT 00:05 → 2026-06-01 03:05 UTC (BRT é UTC-3).
        self::assertStringContainsString('2026-06-01 03:05:00', $d0[0]['scheduled_for']);

        // D-1 mantém 09:00 BRT = 12:00 UTC (não-regressão).
        $d1 = $this->fetchLembretesByMarco('prazo-001', 'D-1');
        self::assertCount(1, $d1);
        self::assertStringContainsString('2026-05-29 12:00:00', $d1[0]['scheduled_for']);
    }

    /**
     * Story 4b.3 AC11 — audit.notification.scheduled inclui marco='D-0' no contexto.
     */
    public function testD0AuditScheduledIncluiMarcoNoContext(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());

        $d0Audits = \array_filter(
            $this->audit->calls,
            static fn (array $c) =>
                $c['event'] === 'audit.notification.scheduled'
                && ($c['context']['marco'] ?? null) === 'D-0',
        );
        self::assertCount(1, $d0Audits);
        $audit = \array_values($d0Audits)[0];
        self::assertSame('prazo-001', $audit['context']['prazoId']);
        self::assertSame('adv-001', $audit['context']['userId']);
        self::assertSame('both', $audit['context']['canal']);
        self::assertStringContainsString('2026-06-01 03:05:00', $audit['context']['scheduledFor']);
    }

    /**
     * Story 4b.3 AC3 — dataFatal mudou cancela e re-enqueue inclui D-0
     * com nova data de disparo.
     */
    public function testD0SobreviveDataFatalMudouComNovaDataNoNovaEntryD0(): void
    {
        $prazo = $this->makeNewPrazo(['dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-001');
        $this->hook->afterSave($prazo, SaveOptions::create());

        // Confirma D-0 inicial em 2026-06-01.
        $d0Before = $this->fetchLembretesByMarco('prazo-001', 'D-0');
        self::assertCount(1, $d0Before);
        self::assertStringContainsString('2026-06-01 03:05:00', $d0Before[0]['scheduled_for']);

        // Muda dataFatal para 2026-06-15.
        $prazoEdit = $this->makeExistingPrazo([
            'fetched' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-01', 'assignedUserId' => 'adv-001'],
            'set' => ['status' => Prazo::STATUS_PENDENTE, 'dataFatal' => '2026-06-15', 'assignedUserId' => 'adv-001'],
            'id' => 'prazo-001',
        ]);
        $this->hook->afterSave($prazoEdit, SaveOptions::create());

        // D-0 antigo hard-deletado; novo D-0 em 2026-06-15.
        $d0After = $this->fetchLembretesByMarco('prazo-001', 'D-0');
        self::assertCount(1, $d0After, 'D-0 re-enqueued após dataFatal mudou.');
        self::assertStringContainsString('2026-06-15 03:05:00', $d0After[0]['scheduled_for']);
    }

    public function testDataFatalPassadaNaoEnfileiraD0VenceHoje(): void
    {
        $yesterdayBrt = (new \DateTimeImmutable('now', new \DateTimeZone(PrazoLembreteConstants::TZ_BRT)))
            ->modify('-1 day')
            ->format('Y-m-d');

        $prazo = $this->makeNewPrazo(['dataFatal' => $yesterdayBrt, 'assignedUserId' => 'adv-001']);
        $prazo->setId('prazo-passado');

        $this->hook->afterSave($prazo, SaveOptions::create());

        self::assertSame(3, $this->countLembretes(), 'Data fatal passada ainda preserva D-7/D-3/D-1 imediatos, mas nao cria D-0.');
        self::assertCount(0, $this->fetchLembretesByMarco('prazo-passado', PrazoLembreteConstants::MARCO_D0));

        $marcos = \array_map(static fn (array $r) => $r['marco'], $this->fetchLembretes('prazo-passado'));
        \sort($marcos);
        self::assertSame(['D-1', 'D-3', 'D-7'], $marcos);
    }

    // ====== Helpers ======

    /** @param array<string, mixed> $overrides */
    private function makeNewPrazo(array $overrides = []): Prazo
    {
        $prazo = new Prazo();
        $prazo->set(\array_merge([
            'status' => Prazo::STATUS_PENDENTE,
            'dataDisponibilizacao' => '2026-05-04',
            'dataInicioPrazo' => '2026-05-05',
            'dataFatal' => '2026-06-01',
            'prazoDias' => 15,
            'contagem' => Prazo::CONTAGEM_UTEIS,
            'atoCodigo' => 'contestacao',
            'referenciaLegal' => 'CPC art. 335',
            'confidence' => Prazo::CONFIDENCE_HIGH,
            'parserRegraVersao' => '1.0.0',
            'source' => Prazo::SOURCE_DJEN,
            'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
            'prioridade' => Prazo::PRIORIDADE_NORMAL,
            'assignedUserId' => 'adv-001',
        ], $overrides));
        return $prazo;
    }

    /**
     * @param array{fetched: array<string, mixed>, set: array<string, mixed>, id: string} $params
     *
     * Em runtime real do EspoCRM, $entity->get('foo') retorna o "current value":
     * o valor de set() se houver, senão o valor fetched. O stub Entity (CoreStubs)
     * só lê de attributes — então copiamos fetched para attributes primeiro,
     * depois aplicamos set por cima (sobrescreve atributos mudados).
     * Idempotente: isAttributeChanged() compara fetched com attributes; chaves
     * iguais permanecem "não-mudadas" porque attributes copiou exatamente o
     * fetched antes do set sobrepor.
     */
    private function makeExistingPrazo(array $params): Prazo
    {
        $prazo = new Prazo();
        foreach ($params['fetched'] as $k => $v) {
            $prazo->setFetched($k, $v);
            $prazo->set($k, $v); // popula attributes com valores atuais (espelha fetched).
        }
        foreach ($params['set'] as $k => $v) {
            $prazo->set($k, $v); // sobrescreve apenas o que mudou neste save.
        }
        $prazo->setId($params['id']);
        return $prazo;
    }

    private function countLembretes(array $where = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM togare_prazo_lembrete';
        if (isset($where['status'])) {
            $sql .= " WHERE status = " . $this->pdo->quote($where['status']);
        }
        $stmt = $this->pdo->query($sql);
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return $row !== false ? (int) $row['c'] : 0;
    }

    /** @return list<array<string, mixed>> */
    private function fetchLembretes(string $prazoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM togare_prazo_lembrete WHERE prazo_id = :pid ORDER BY marco, user_id'
        );
        $stmt->execute([':pid' => $prazoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    /** @return list<array<string, mixed>> */
    private function fetchLembretesByMarco(string $prazoId, string $marco): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM togare_prazo_lembrete WHERE prazo_id = :pid AND marco = :m'
        );
        $stmt->execute([':pid' => $prazoId, ':m' => $marco]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    /** @return list<array<string, mixed>> */
    private function fetchLembretesByStatus(string $prazoId, string $status): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM togare_prazo_lembrete WHERE prazo_id = :pid AND status = :s ORDER BY marco'
        );
        $stmt->execute([':pid' => $prazoId, ':s' => $status]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows === false ? [] : $rows;
    }

    private function createMiniUserRoleSchema(): void
    {
        // EspoCRM 9.x usa tabela join `role_user` (NÃO `user_role`).
        $this->pdo->exec("CREATE TABLE user (
            id VARCHAR(17) PRIMARY KEY,
            is_active INT NOT NULL DEFAULT 1,
            deleted INT NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE role (
            id VARCHAR(17) PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            deleted INT NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE role_user (
            id VARCHAR(17) PRIMARY KEY,
            user_id VARCHAR(17) NOT NULL,
            role_id VARCHAR(17) NOT NULL,
            deleted INT NOT NULL DEFAULT 0
        )");
    }

    private function seedUserComRole(string $userId, string $roleName, bool $isActive): void
    {
        // Upsert role pelo nome.
        $stmt = $this->pdo->prepare("SELECT id FROM role WHERE name = :n");
        $stmt->execute([':n' => $roleName]);
        $roleRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($roleRow === false) {
            $roleId = 'role-' . \substr(\md5($roleName), 0, 12);
            $this->pdo->prepare("INSERT INTO role (id, name) VALUES (:id, :n)")
                ->execute([':id' => $roleId, ':n' => $roleName]);
        } else {
            $roleId = (string) $roleRow['id'];
        }

        $this->pdo->prepare("INSERT OR IGNORE INTO user (id, is_active, deleted) VALUES (:id, :a, 0)")
            ->execute([':id' => $userId, ':a' => $isActive ? 1 : 0]);

        $urId = 'ru-' . \substr(\md5($userId . $roleName), 0, 12);
        $this->pdo->prepare("INSERT OR IGNORE INTO role_user (id, user_id, role_id) VALUES (:id, :uid, :rid)")
            ->execute([':id' => $urId, ':uid' => $userId, ':rid' => $roleId]);
    }
}
