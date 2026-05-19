<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Audiencia;

use Espo\Modules\TogareCore\Entities\Audiencia;
use Espo\Modules\TogareCore\Hooks\Audiencia\AuditAudienciaHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre auditoria implícita (FR37 + NFR10) para Audiencia (Story 3.6-magro).
 *
 * Stream nativo do EspoCRM (entityDef "stream": true) cuida da camada UI
 * de mudanças. Este hook escreve em togare_audit_log via AuditLogContract
 * — camada estrutural append-only com retenção 24m.
 *
 * Adicional: emite eventos derivados `audit.audiencia.cancelled` /
 * `audit.audiencia.realized` quando o status muda para `cancelada` /
 * `realizada` (ALÉM do `modified` regular).
 */
final class AuditAudienciaHookTest extends TestCase
{
    public function testNovaAudienciaEmiteEventoCreated(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditAudienciaHook($stub);

        $aud = new Audiencia();
        $aud->set([
            'dataHora' => '2026-05-15 14:00:00',
            'tipo' => Audiencia::TIPO_CONCILIACAO,
            'modalidade' => Audiencia::MODALIDADE_PRESENCIAL,
            'status' => Audiencia::STATUS_AGENDADA,
            'processoId' => 'proc-001',
            'assignedUserId' => 'user-001',
        ]);
        // sem setId() → isNew()=true.

        $hook->afterSave($aud, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.audiencia.created', $stub->calls[0]['event']);
        self::assertSame('Audiencia', $stub->calls[0]['entityType']);
        self::assertSame('proc-001', $stub->calls[0]['context']['processoId']);
        self::assertSame('2026-05-15 14:00:00', $stub->calls[0]['context']['dataHora']);
        self::assertSame(Audiencia::TIPO_CONCILIACAO, $stub->calls[0]['context']['tipo']);
        self::assertSame(Audiencia::MODALIDADE_PRESENCIAL, $stub->calls[0]['context']['modalidade']);
        self::assertSame('user-001', $stub->calls[0]['context']['assignedUserId']);
    }

    public function testEdicaoSemMudancaSensivelNaoEmiteEvento(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditAudienciaHook($stub);

        $aud = new Audiencia();
        $aud->setFetched('participantes', 'Dr. Ricardo');
        $aud->setFetched('duracaoMinutos', 60);
        $aud->set([
            'participantes' => 'Dr. Ricardo, Dra. Beatriz', // não está em SENSITIVE_FIELDS
            'duracaoMinutos' => 90, // não está em SENSITIVE_FIELDS
        ]);
        $aud->setId('00000000000000aud');

        $hook->afterSave($aud, SaveOptions::create());

        self::assertCount(0, $stub->calls);
    }

    public function testEdicaoEmiteModifiedComChangedFields(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditAudienciaHook($stub);

        $aud = new Audiencia();
        $aud->setFetched('processoId', 'proc-001');
        $aud->setFetched('dataHora', '2026-05-15 14:00:00');
        $aud->setFetched('tribunal', 'TJSP');
        $aud->setFetched('observacoes', null);

        $aud->set([
            'processoId' => 'proc-001',                  // não mudou
            'dataHora' => '2026-05-20 14:00:00',         // mudou
            'tribunal' => 'TRT-15',                       // mudou
            'observacoes' => 'Adiada por agenda do juiz', // novo
        ]);
        $aud->setId('00000000000000aud');

        $hook->afterSave($aud, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.audiencia.modified', $stub->calls[0]['event']);
        $changed = $stub->calls[0]['context']['changedFields'];
        self::assertContains('dataHora', $changed);
        self::assertContains('tribunal', $changed);
        self::assertContains('observacoes', $changed);
        self::assertNotContains('processoId', $changed);
        self::assertSame('proc-001', $stub->calls[0]['context']['processoId']);
    }

    public function testStatusCanceladaEmiteAuditCancelledAlemDoModified(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditAudienciaHook($stub);

        $aud = new Audiencia();
        $aud->setFetched('status', Audiencia::STATUS_AGENDADA);
        $aud->setFetched('processoId', 'proc-001');
        $aud->setFetched('dataHora', '2026-05-15 14:00:00');

        $aud->set([
            'status' => Audiencia::STATUS_CANCELADA,
            'processoId' => 'proc-001',
            'dataHora' => '2026-05-15 14:00:00',
        ]);
        $aud->setId('00000000000000aud');

        $hook->afterSave($aud, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.audiencia.modified', $stub->calls[0]['event']);
        self::assertSame('audit.audiencia.cancelled', $stub->calls[1]['event']);
        self::assertSame(Audiencia::STATUS_AGENDADA, $stub->calls[1]['context']['previousStatus']);
        self::assertSame('proc-001', $stub->calls[1]['context']['processoId']);
        self::assertSame('2026-05-15 14:00:00', $stub->calls[1]['context']['dataHora']);
    }

    public function testStatusRealizadaEmiteAuditRealizedAlemDoModified(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditAudienciaHook($stub);

        $aud = new Audiencia();
        $aud->setFetched('status', Audiencia::STATUS_AGENDADA);
        $aud->setFetched('processoId', 'proc-001');
        $aud->setFetched('dataHora', '2026-05-15 14:00:00');
        $aud->setFetched('duracaoMinutos', 60);

        $aud->set([
            'status' => Audiencia::STATUS_REALIZADA,
            'processoId' => 'proc-001',
            'dataHora' => '2026-05-15 14:00:00',
            'duracaoMinutos' => 90, // duracao mudou (mas não é sensitive — só status emite os 2 eventos)
        ]);
        $aud->setId('00000000000000aud');

        $hook->afterSave($aud, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.audiencia.modified', $stub->calls[0]['event']);
        self::assertSame('audit.audiencia.realized', $stub->calls[1]['event']);
        self::assertSame('proc-001', $stub->calls[1]['context']['processoId']);
        self::assertSame(90, $stub->calls[1]['context']['durationMinutes']);
    }

    public function testEntidadeNaoAudienciaIgnora(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditAudienciaHook($stub);

        $other = new \Espo\Core\ORM\Entity();
        $other->set('status', Audiencia::STATUS_REALIZADA);

        $hook->afterSave($other, SaveOptions::create());

        self::assertCount(0, $stub->calls);
    }
}
