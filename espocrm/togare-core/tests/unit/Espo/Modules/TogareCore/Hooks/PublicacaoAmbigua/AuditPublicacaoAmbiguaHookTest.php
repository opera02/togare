<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\PublicacaoAmbigua;

use Espo\Modules\TogareCore\Entities\PublicacaoAmbigua;
use Espo\Modules\TogareCore\Hooks\PublicacaoAmbigua\AuditPublicacaoAmbiguaHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareCore\Stubs\AuditLogContractStub;

/**
 * Cobre AuditPublicacaoAmbiguaHook (Story 4b.1a, AC6 + FR37 + NFR10).
 *
 * Eventos emitidos:
 *  - audit.publicacao_ambigua.created
 *  - audit.publicacao_ambigua.modified
 *  - audit.publicacao_ambigua.status_resolvido
 *  - audit.publicacao_ambigua.status_ignorado
 *  - audit.publicacao_ambigua.status_bulk_ignorado
 *
 * Pattern espelha AuditPrazoHookTest (Story 4a.3.1).
 */
final class AuditPublicacaoAmbiguaHookTest extends TestCase
{
    public function testNovaPublicacaoAmbiguaEmiteEventoCreated(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPublicacaoAmbiguaHook($stub);

        $pub = $this->makeNewPubAmbigua();
        // sem setId() → isNew()=true.

        $hook->afterSave($pub, SaveOptions::create());

        self::assertCount(1, $stub->calls);
        $call = $stub->calls[0];
        self::assertSame('audit.publicacao_ambigua.created', $call['event']);
        self::assertSame('PublicacaoAmbigua', $call['entityType']);
        self::assertSame(999991, $call['context']['sourcePubId']);
        self::assertSame(PublicacaoAmbigua::AMBIGUITY_REASON_NAME_MATCH_MULTIPLE, $call['context']['ambiguityReason']);
        self::assertSame(PublicacaoAmbigua::STATUS_PENDENTE_REVISAO, $call['context']['status']);
        self::assertSame('2026-06-08', $call['context']['dataFatal']);
        self::assertSame('manifestacao_generica', $call['context']['atoCodigo']);
        self::assertSame(2, $call['context']['candidatosCount']);
        self::assertSame('user-001', $call['context']['assignedUserId']);
    }

    public function testTransicaoParaResolvidoEmiteModifiedEStatusResolvido(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPublicacaoAmbiguaHook($stub);

        $pub = new PublicacaoAmbigua();
        $pub->setFetched('status', PublicacaoAmbigua::STATUS_PENDENTE_REVISAO);
        $pub->setFetched('decisionType', null);
        $pub->setFetched('decisionProcessoId', null);
        $pub->setFetched('prazoCriadoId', null);
        $pub->setFetched('decidedById', null);
        $pub->setFetched('assignedUserId', 'user-001');
        $pub->setFetched('candidatos', '[]');
        $pub->setFetched('ambiguityReason', PublicacaoAmbigua::AMBIGUITY_REASON_NAME_MATCH_MULTIPLE);

        $pub->set([
            'status' => PublicacaoAmbigua::STATUS_RESOLVIDO,
            'decisionType' => PublicacaoAmbigua::DECISION_CONFIRMAR_CANDIDATO,
            'decisionProcessoId' => 'proc-A',
            'prazoCriadoId' => 'prz-novo',
            'decidedById' => 'advogado-1',
            'assignedUserId' => 'user-001',
            'candidatos' => '[]',
            'ambiguityReason' => PublicacaoAmbigua::AMBIGUITY_REASON_NAME_MATCH_MULTIPLE,
        ]);
        $pub->setId('00000000000000pub');

        $hook->afterSave($pub, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.publicacao_ambigua.modified', $stub->calls[0]['event']);
        self::assertSame('audit.publicacao_ambigua.status_resolvido', $stub->calls[1]['event']);
        self::assertSame(PublicacaoAmbigua::STATUS_PENDENTE_REVISAO, $stub->calls[1]['context']['previousStatus']);
        self::assertSame(PublicacaoAmbigua::DECISION_CONFIRMAR_CANDIDATO, $stub->calls[1]['context']['decisionType']);
        self::assertSame('proc-A', $stub->calls[1]['context']['decisionProcessoId']);
        self::assertSame('prz-novo', $stub->calls[1]['context']['prazoCriadoId']);
    }

    public function testTransicaoParaIgnoradoEmiteEventoDedicado(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPublicacaoAmbiguaHook($stub);

        $pub = new PublicacaoAmbigua();
        $pub->setFetched('status', PublicacaoAmbigua::STATUS_PENDENTE_REVISAO);
        $pub->setFetched('decisionType', null);

        $pub->set([
            'status' => PublicacaoAmbigua::STATUS_IGNORADO,
            'decisionType' => PublicacaoAmbigua::DECISION_IGNORAR,
        ]);
        $pub->setId('00000000000000pub');

        $hook->afterSave($pub, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.publicacao_ambigua.modified', $stub->calls[0]['event']);
        self::assertSame('audit.publicacao_ambigua.status_ignorado', $stub->calls[1]['event']);
    }

    public function testTransicaoBulkIgnoradoEmiteEventoDedicado(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPublicacaoAmbiguaHook($stub);

        $pub = new PublicacaoAmbigua();
        $pub->setFetched('status', PublicacaoAmbigua::STATUS_PENDENTE_REVISAO);
        $pub->setFetched('decisionType', null);

        $pub->set([
            'status' => PublicacaoAmbigua::STATUS_BULK_IGNORADO,
            'decisionType' => PublicacaoAmbigua::DECISION_BULK_IGNORAR_PROCESSO,
        ]);
        $pub->setId('00000000000000pub');

        $hook->afterSave($pub, SaveOptions::create());

        self::assertCount(2, $stub->calls);
        self::assertSame('audit.publicacao_ambigua.status_bulk_ignorado', $stub->calls[1]['event']);
    }

    public function testEdicaoSemMudancaSensivelNaoEmiteEvento(): void
    {
        $stub = new AuditLogContractStub();
        $hook = new AuditPublicacaoAmbiguaHook($stub);

        $pub = new PublicacaoAmbigua();
        $pub->setFetched('texto', 'antigo');
        $pub->setFetched('fonteExcerpt', 'antigo excerpt');
        $pub->set([
            'texto' => 'novo', // não está em SENSITIVE_FIELDS
            'fonteExcerpt' => 'novo excerpt', // idem
        ]);
        $pub->setId('00000000000000pub');

        $hook->afterSave($pub, SaveOptions::create());

        self::assertCount(0, $stub->calls);
    }

    public function testHookNaoBloqueiaSaveQuandoAuditLogFalhaComException(): void
    {
        $stub = new AuditLogContractStub();
        $stub->shouldThrow = true;
        $hook = new AuditPublicacaoAmbiguaHook($stub);

        $pub = $this->makeNewPubAmbigua();
        // sem setId() → isNew()=true.

        // Hook DEVE capturar e logar via TogareLogger sem propagar (try/catch \Throwable).
        try {
            $hook->afterSave($pub, SaveOptions::create());
            self::assertSame(1, $stub->throwCount, 'Audit log foi tentado 1 vez');
            self::assertCount(0, $stub->calls, 'Audit log throws → calls fica vazio');
        } catch (\Throwable $e) {
            self::fail('Hook NÃO deve propagar exceção do audit log: ' . $e->getMessage());
        }
    }

    /**
     * Pub ambígua nova com 2 candidatos. Pattern para reuso.
     */
    private function makeNewPubAmbigua(): PublicacaoAmbigua
    {
        $pub = new PublicacaoAmbigua();
        $pub->set([
            'sourcePubId' => 999991,
            'numeroProcessoOriginal' => '',
            'payload' => '{}',
            'texto' => 'Texto da publicação fake',
            'dataDisponibilizacao' => '2026-05-25',
            'dataInicioPrazo' => '2026-05-26',
            'dataFatal' => '2026-06-08',
            'prazoDias' => 15,
            'contagem' => 'uteis',
            'atoCodigo' => 'manifestacao_generica',
            'referenciaLegal' => 'CPC art. 218',
            'confidence' => 'low',
            'parserRegraVersao' => '1.0.0',
            'fonteExcerpt' => 'trecho ambíguo',
            'candidatos' => '[{"processoId":"proc-A","numeroCnj":"01234567890123456789","clienteNome":"João Silva","parteContrariaNome":null,"codigoCor":"azul"},{"processoId":"proc-B","numeroCnj":"02345678901234567890","clienteNome":"Maria Souza","parteContrariaNome":null,"codigoCor":"laranja"}]',
            'ambiguityReason' => PublicacaoAmbigua::AMBIGUITY_REASON_NAME_MATCH_MULTIPLE,
            'status' => PublicacaoAmbigua::STATUS_PENDENTE_REVISAO,
            'assignedUserId' => 'user-001',
        ]);
        return $pub;
    }
}
