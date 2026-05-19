<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Hooks\Prazo\AutoLinkClientHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre o auto-vínculo defensivo de Cliente / ParteContraria quando o Prazo
 * é associado a um Processo (Story 4a.3.1, F1.9, AC6).
 *
 * Comportamento N:N defensivo (Decisão #4 + ADR-03 v1.1 §3):
 *  - 0 clientes/partes  → deixa NULL + log info (skip)
 *  - 1 cliente/parte    → seta automaticamente + log info (assigned)
 *  - 2+ clientes/partes → deixa NULL + log info (skip — UI exige seleção manual)
 *  - clienteId/parteContrariaId já SETADO → NÃO sobrepõe (idempotência)
 *  - processoId NÃO mudou neste save → no-op
 */
final class AutoLinkClientHookTest extends TestCase
{
    public function testAutoLinkClienteUnicoNoProcessoFazSet(): void
    {
        $cliente = $this->makeClienteStub('cli-001');
        $processo = $this->makeProcessoStub('proc-001', clientes: [$cliente], partesContrarias: []);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('Processo', 'proc-001')->willReturn($processo);

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-001');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('cli-001', $prazo->get('clienteId'));
        self::assertNull($prazo->get('parteContrariaId'));
    }

    public function testAutoLinkParteContrariaUnicaFazSet(): void
    {
        $parte = $this->makeClienteStub('parte-001');
        $processo = $this->makeProcessoStub('proc-001', clientes: [], partesContrarias: [$parte]);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($processo);

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-001');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('parte-001', $prazo->get('parteContrariaId'));
        self::assertNull($prazo->get('clienteId'));
    }

    public function testAutoLinkSemClientesNoProcessoDeixaNull(): void
    {
        $processo = $this->makeProcessoStub('proc-001', clientes: [], partesContrarias: []);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($processo);

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-001');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('clienteId'));
        self::assertNull($prazo->get('parteContrariaId'));
    }

    public function testAutoLinkClientesMultiplosNoProcessoDeixaNull(): void
    {
        $cliente1 = $this->makeClienteStub('cli-001');
        $cliente2 = $this->makeClienteStub('cli-002');
        $processo = $this->makeProcessoStub('proc-001', clientes: [$cliente1, $cliente2], partesContrarias: []);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($processo);

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-001');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('clienteId'), 'Múltiplos clientes → fica NULL (Decisão #4 — UI exige seleção manual)');
    }

    public function testAutoLinkPartesContrariasMultiplasDeixaNull(): void
    {
        $p1 = $this->makeClienteStub('parte-001');
        $p2 = $this->makeClienteStub('parte-002');
        $p3 = $this->makeClienteStub('parte-003');
        $processo = $this->makeProcessoStub('proc-001', clientes: [], partesContrarias: [$p1, $p2, $p3]);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($processo);

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-001');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('parteContrariaId'));
    }

    public function testAutoLinkNaoSobrescreveClienteJaSetado(): void
    {
        $cliente = $this->makeClienteStub('cli-otimo');
        $processo = $this->makeProcessoStub('proc-001', clientes: [$cliente], partesContrarias: []);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($processo);

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-001');
        // Advogado já escolheu manualmente.
        $prazo->set('clienteId', 'cli-manual');

        $hook->beforeSave($prazo, SaveOptions::create());

        // Idempotência: NÃO sobrepõe.
        self::assertSame('cli-manual', $prazo->get('clienteId'));
    }

    /**
     * Story 4a.3.1 smoke F1 fix: hook dispara em TODA save (não só quando processoId
     * muda). Cenário: Prazo já tem processoId, cliente é null, depois usuário adiciona
     * cliente ao Processo e salva o Prazo de novo — auto-link deve completar.
     */
    public function testAutoLinkDisparaEmEditQuandoProcessoIdNaoMudouMasClienteEstaVazio(): void
    {
        $cliente = $this->makeClienteStub('cli-001');
        $processo = $this->makeProcessoStub('proc-001', clientes: [$cliente], partesContrarias: []);

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($processo);

        $hook = new AutoLinkClientHook($em);

        $prazo = new Prazo();
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('clienteId', null);
        $prazo->set([
            'processoId' => 'proc-001', // não mudou
            'dataFatal' => '2026-06-08',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('cli-001', $prazo->get('clienteId'));
    }

    /**
     * Story 4a.3.1 — otimização: early-return quando ambos cliente E parteContraria
     * já estão setados (evita 1 query no Processo).
     */
    public function testAutoLinkNaoDisparaQuandoClienteEParteContrariaJaSetados(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $hook = new AutoLinkClientHook($em);

        $prazo = new Prazo();
        $prazo->setFetched('processoId', 'proc-001');
        // O Entity stub trata `get()` lendo de attributes, não de fetched —
        // setamos via `set([...])` para que o hook leia clienteId/parteContrariaId
        // como já preenchidos (cenário pós-load do banco).
        $prazo->set([
            'processoId' => 'proc-001',
            'clienteId' => 'cli-X',
            'parteContrariaId' => 'parte-Y',
            'dataFatal' => '2026-06-08',
        ]);
        $prazo->setId('00000000000000prz');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame('cli-X', $prazo->get('clienteId'));
        self::assertSame('parte-Y', $prazo->get('parteContrariaId'));
    }

    public function testAutoLinkNaoDisparaQuandoEntidadeNaoEhPrazo(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $hook = new AutoLinkClientHook($em);

        $other = new \Espo\Core\ORM\Entity();
        $other->set('processoId', 'proc-001');

        $hook->beforeSave($other, SaveOptions::create());

        // Sem assertion sobre o other — só queremos garantir que getEntityById nunca foi chamado.
        self::assertTrue(true);
    }

    public function testAutoLinkNaoDisparaQuandoProcessoIdEhNull(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getEntityById');

        $hook = new AutoLinkClientHook($em);

        $prazo = new Prazo();
        $prazo->set([
            'processoId' => null,
            'status' => Prazo::STATUS_RASCUNHO,
        ]);

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertTrue(true);
    }

    public function testAutoLinkNaoBloqueiaSaveQuandoProcessoNaoExiste(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn(null);

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-fantasma');

        // Não deve lançar.
        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('clienteId'));
        self::assertNull($prazo->get('parteContrariaId'));
    }

    public function testAutoLinkNaoBloqueiaSaveQuandoEntityManagerThrows(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willThrowException(new \RuntimeException('DB down'));

        $hook = new AutoLinkClientHook($em);
        $prazo = $this->makeNewPrazoComProcesso('proc-001');

        // Não deve relançar — try/catch interno + log warning.
        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertNull($prazo->get('clienteId'));
    }

    private function makeNewPrazoComProcesso(string $processoId): Prazo
    {
        $prazo = new Prazo();
        $prazo->set([
            'dataDisponibilizacao' => '2026-05-15',
            'dataInicioPrazo' => '2026-05-18',
            'dataFatal' => '2026-06-08',
            'prazoDias' => 15,
            'contagem' => Prazo::CONTAGEM_UTEIS,
            'atoCodigo' => 'contestacao',
            'referenciaLegal' => 'CPC art. 335',
            'confidence' => Prazo::CONFIDENCE_HIGH,
            'parserRegraVersao' => '1.0.0',
            'source' => Prazo::SOURCE_DJEN,
            'sourcePubId' => 12345,
            'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
            'status' => Prazo::STATUS_PENDENTE,
            'prioridade' => Prazo::PRIORIDADE_NORMAL,
            'processoId' => $processoId,
            'assignedUserId' => 'user-uuid-alice',
        ]);
        // isNew=true (não setou ID) — isProcessoChanged usa o branch isNew.

        return $prazo;
    }

    private function makeClienteStub(string $id): \Espo\Core\ORM\Entity
    {
        $entity = new \Espo\Core\ORM\Entity();
        $entity->setId($id);
        return $entity;
    }

    /**
     * Cria stub de Processo com links N:N pré-populados.
     * Hook lê via $processo->get('clientes') e $processo->get('partesContrarias').
     *
     * @param list<\Espo\Core\ORM\Entity> $clientes
     * @param list<\Espo\Core\ORM\Entity> $partesContrarias
     */
    private function makeProcessoStub(string $id, array $clientes, array $partesContrarias): \Espo\Core\ORM\Entity
    {
        $processo = new \Espo\Core\ORM\Entity();
        $processo->setId($id);
        $processo->set('clientes', $clientes);
        $processo->set('partesContrarias', $partesContrarias);
        return $processo;
    }
}
