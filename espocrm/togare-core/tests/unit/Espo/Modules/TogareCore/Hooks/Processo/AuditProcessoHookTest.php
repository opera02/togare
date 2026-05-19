<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Modules\TogareCore\Entities\Processo;
use Espo\Modules\TogareCore\Hooks\Processo\AuditProcessoHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre auditoria implícita (FR37 + NFR10) para Processo (Story 3.4).
 *
 * Stream nativo do EspoCRM (entityDef "stream": true) cuida da camada UI
 * de mudanças. Este hook escreve em togare_audit_log via AuditLogContract
 * — camada estrutural append-only com retenção 24m.
 */
final class AuditProcessoHookTest extends TestCase
{
    public function testNovoProcessoEmiteEventoCreated(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditProcessoHook($stub);

        $proc = new Processo();
        $proc->set([
            'numeroCnj' => '00000007520238260100',
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
            'assignedUserId' => 'user-001',
        ]);
        // sem setId() → isNew()=true.

        $hook->afterSave($proc, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.processo.created', $stub->calls[0]['event']);
        self::assertSame('Processo', $stub->calls[0]['entityType']);
        self::assertSame('00000007520238260100', $stub->calls[0]['context']['numeroCnj']);
        self::assertSame(436, $stub->calls[0]['context']['classeCodigo']);
        self::assertSame('user-001', $stub->calls[0]['context']['assignedUserId']);
    }

    public function testEdicaoSemMudancaSensivelNaoEmiteEvento(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditProcessoHook($stub);

        $proc = new Processo();
        $proc->setFetched('numeroCnj', '00000007520238260100');
        $proc->setFetched('tribunal', 'TJSP');
        $proc->set([
            'numeroCnj' => '00000007520238260100',
            'tribunal' => 'TJSP',
            // tribunal não está em CAMPOS_SENSIVEIS — mudança seria irrelevante
        ]);
        $proc->setId('00000000000000001'); // marca isNew=false

        $hook->afterSave($proc, SaveOptions::create());

        // Nenhum campo sensível mudou → SEM evento.
        self::assertCount(0, $stub->calls);
    }

    public function testEdicaoEmiteModifiedComChangedFields(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditProcessoHook($stub);

        $proc = new Processo();
        $proc->setFetched('numeroCnj', '00000007520238260100');
        $proc->setFetched('status', Processo::STATUS_ATIVO);
        $proc->setFetched('valorCausa', 1000.0);
        $proc->setFetched('observacoes', null);

        $proc->set([
            'numeroCnj' => '00000007520238260100',  // não mudou
            'status' => Processo::STATUS_SUSPENSO,    // mudou
            'valorCausa' => 1500.0,                    // mudou
            'observacoes' => 'Anotação nova',          // novo
        ]);
        $proc->setId('00000000000000001');

        $hook->afterSave($proc, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.processo.modified', $stub->calls[0]['event']);
        $changed = $stub->calls[0]['context']['changedFields'];
        self::assertContains('status', $changed);
        self::assertContains('valorCausa', $changed);
        self::assertContains('observacoes', $changed);
        self::assertNotContains('numeroCnj', $changed);
    }

    /**
     * Story 3.5 — collaboratorsIds entra em SENSITIVE_FIELDS.
     */
    public function testCreateComCollaboratorIdsRegistraNoContext(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditProcessoHook($stub);

        $proc = new Processo();
        $proc->set([
            'numeroCnj' => '00000007520238260100',
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
            'assignedUserId' => 'user-titular',
            'collaboratorsIds' => ['user-colab-1', 'user-colab-2'],
        ]);

        $hook->afterSave($proc, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.processo.created', $stub->calls[0]['event']);
        self::assertSame(
            ['user-colab-1', 'user-colab-2'],
            $stub->calls[0]['context']['initialCollaboratorIds'] ?? null,
            'Created context deve carregar initialCollaboratorIds quando informados',
        );
    }

    /**
     * Story 3.5 — colaborador adicionado em update emite modified com lista granular.
     */
    public function testEdicaoCollaboratorAdicionadoEmiteAddedList(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditProcessoHook($stub);

        $proc = new Processo();
        $proc->setId('proc-001');
        $proc->setFetched('numeroCnj', '00000007520238260100');
        $proc->setFetched('collaboratorsIds', ['user-colab-1']);
        $proc->set([
            'numeroCnj' => '00000007520238260100',
            'collaboratorsIds' => ['user-colab-1', 'user-colab-2'],
        ]);

        $hook->afterSave($proc, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.processo.modified', $stub->calls[0]['event']);
        self::assertContains('collaboratorsIds', $stub->calls[0]['context']['changedFields']);
        self::assertSame(['user-colab-2'], $stub->calls[0]['context']['addedCollaboratorIds']);
        self::assertSame([], $stub->calls[0]['context']['removedCollaboratorIds']);
    }

    /**
     * Story 3.5 — colaborador removido em update emite modified com lista granular.
     */
    public function testEdicaoCollaboratorRemovidoEmiteRemovedList(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditProcessoHook($stub);

        $proc = new Processo();
        $proc->setId('proc-001');
        $proc->setFetched('numeroCnj', '00000007520238260100');
        $proc->setFetched('collaboratorsIds', ['user-colab-1', 'user-colab-2']);
        $proc->set([
            'numeroCnj' => '00000007520238260100',
            'collaboratorsIds' => ['user-colab-1'],
        ]);

        $hook->afterSave($proc, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.processo.modified', $stub->calls[0]['event']);
        self::assertContains('collaboratorsIds', $stub->calls[0]['context']['changedFields']);
        self::assertSame([], $stub->calls[0]['context']['addedCollaboratorIds']);
        self::assertSame(['user-colab-2'], $stub->calls[0]['context']['removedCollaboratorIds']);
    }

    /**
     * Story 3.5 — collaboratorsIds inalterado NÃO emite evento.
     */
    public function testCollaboratorsIdsInalteradoNaoEmiteEvento(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditProcessoHook($stub);

        $proc = new Processo();
        $proc->setId('proc-001');
        $proc->setFetched('collaboratorsIds', ['user-colab-1']);
        $proc->set('collaboratorsIds', ['user-colab-1']);

        $hook->afterSave($proc, SaveOptions::create());

        self::assertCount(0, $stub->calls);
    }
}
