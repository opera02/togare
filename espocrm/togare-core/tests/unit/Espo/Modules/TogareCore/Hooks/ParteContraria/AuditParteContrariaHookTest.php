<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\ParteContraria;

use Espo\Modules\TogareCore\Entities\ParteContraria;
use Espo\Modules\TogareCore\Hooks\ParteContraria\AuditParteContrariaHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre auditoria implícita (FR37 + NFR10) para ParteContraria.
 *
 * Stream nativo do EspoCRM (entityDef "stream": true) cuida da camada UI
 * de mudanças. Este hook escreve em togare_audit_log via AuditLogContract
 * — camada estrutural append-only com retenção 24m.
 */
final class AuditParteContrariaHookTest extends TestCase
{
    public function testNovaParteContrariaEmiteEventoCreated(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditParteContrariaHook($stub);

        $parte = new ParteContraria();
        $parte->set([
            'name' => 'João Réu',
            'tipoPessoa' => 'pf',
            'cpf' => '52998224725',
        ]);
        // sem setId() → isNew()=true.

        $hook->afterSave($parte, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.parte_contraria.created', $stub->calls[0]['event']);
        self::assertSame('ParteContraria', $stub->calls[0]['entityType']);
        self::assertSame('João Réu', $stub->calls[0]['context']['name']);
        self::assertSame('pf', $stub->calls[0]['context']['tipoPessoa']);
    }

    public function testEdicaoSomenteEmiteEventoSeMudouCampoSensivel(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditParteContrariaHook($stub);

        $parte = new ParteContraria();
        $parte->setFetched('name', 'João Réu');
        $parte->setFetched('telefone', '11987654321');
        $parte->set([
            'name' => 'João Réu',
            'telefone' => '11987654321',
            // Nada mudou em relação a fetched.
        ]);
        $parte->setId('00000000000000001'); // marca isNew=false

        $hook->afterSave($parte, SaveOptions::create());

        // Sem mudanças sensíveis → SEM evento.
        self::assertCount(0, $stub->calls);
    }

    public function testEdicaoEmiteModifiedComChangedFields(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditParteContrariaHook($stub);

        $parte = new ParteContraria();
        $parte->setFetched('name', 'João Réu');
        $parte->setFetched('telefone', '11987654321');
        $parte->setFetched('tipoPessoa', 'pf');
        $parte->setFetched('observacoes', null);

        $parte->set([
            'name' => 'João Réu Junior',         // mudou
            'telefone' => '11987654321',         // não mudou
            'observacoes' => 'Anotação nova',    // novo
            'tipoPessoa' => 'pf',
        ]);
        $parte->setId('00000000000000001');

        $hook->afterSave($parte, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.parte_contraria.modified', $stub->calls[0]['event']);
        $changed = $stub->calls[0]['context']['changedFields'];
        self::assertContains('name', $changed);
        self::assertContains('observacoes', $changed);
        self::assertNotContains('telefone', $changed);
    }
}
