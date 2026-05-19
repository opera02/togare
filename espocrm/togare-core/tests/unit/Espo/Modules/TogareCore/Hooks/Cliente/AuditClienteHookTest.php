<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Cliente;

use Espo\Modules\TogareCore\Entities\Cliente;
use Espo\Modules\TogareCore\Hooks\Cliente\AuditClienteHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre AC7 (Story 3.1) — audit log dual-channel.
 *
 * Stream nativo do EspoCRM (entityDef "stream": true) cuida da camada UI
 * de mudanças. Este hook escreve em togare_audit_log via AuditLogContract
 * — camada estrutural append-only com retenção 24m (FR37 + NFR10).
 */
final class AuditClienteHookTest extends TestCase
{
    public function testNovoClienteEmiteEventoCreated(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditClienteHook($stub);

        $clienteNovo = new Cliente();
        $clienteNovo->set([
            'name' => 'João da Silva',
            'tipoPessoa' => 'pf',
            'cpf' => '52998224725',
        ]);
        // sem setId() → isNew()=true.

        $hook->afterSave($clienteNovo, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.cliente.created', $stub->calls[0]['event']);
        self::assertSame('Cliente', $stub->calls[0]['entityType']);
        self::assertSame('João da Silva', $stub->calls[0]['context']['name']);
        self::assertSame('pf', $stub->calls[0]['context']['tipoPessoa']);
    }

    public function testEdicaoSomenteEmiteEventoSeMudouCampoSensivel(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditClienteHook($stub);

        $cliente = new Cliente();
        $cliente->setFetched('name', 'João da Silva');
        $cliente->setFetched('telefone', '11987654321');
        $cliente->set([
            'name' => 'João da Silva',
            'telefone' => '11987654321',
            // Nada mudou em relação a fetched.
        ]);
        $cliente->setId('00000000000000001'); // marca isNew=false

        $hook->afterSave($cliente, SaveOptions::create());

        // Sem mudanças sensíveis → SEM evento.
        self::assertCount(0, $stub->calls);
    }

    public function testEdicaoEmiteUpdatedComChangedFields(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditClienteHook($stub);

        $cliente = new Cliente();
        $cliente->setFetched('name', 'João da Silva');
        $cliente->setFetched('telefone', '11987654321');
        $cliente->setFetched('tipoPessoa', 'pf');

        $cliente->set([
            'name' => 'João da Silva Junior', // mudou
            'telefone' => '11987654321',       // não mudou
            'cep' => '01310100',               // novo
            'tipoPessoa' => 'pf',
        ]);
        $cliente->setId('00000000000000001');

        $hook->afterSave($cliente, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        self::assertSame('audit.cliente.modified', $stub->calls[0]['event']);
        $changed = $stub->calls[0]['context']['changedFields'];
        self::assertContains('name', $changed);
        self::assertContains('cep', $changed);
        self::assertNotContains('telefone', $changed);
    }
}
