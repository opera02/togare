<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Fatura;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Entities\Fatura;
use Espo\Modules\TogareCore\Hooks\Fatura\ValidateFaturaFieldsHook;
use Espo\Modules\TogareCore\Services\ContratoHonorariosLookupService;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa ValidateFaturaFieldsHook focando nos paths críticos:
 *  - Gate FR23 backend (contrato obrigatório + vigente).
 *  - Validações de datas + valorBruto.
 *  - Imutabilidade pós-isNew=false.
 *  - Bypass para saves do FaturaSaldoService (silent + _fromRecompute).
 *
 * Paths com SQL direto (numero auto + processo cross-cliente) ficam para
 * cobertura de smoke F1 end-to-end (Sessão E).
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ValidateFaturaFieldsHookTest extends TestCase
{
    /**
     * Story 6.2 REINTERPRETADA (Felipe, 2026-05-16): Fatura PODE ser emitida
     * SEM ContratoHonorarios. Art. 22 §4º OAB não exige contrato escrito.
     * O hook NÃO bloqueia mais — banner é só informativo.
     */
    public function testFaturaSemContratoNaoBloqueia(): void
    {
        $hook = $this->makeHook();
        $f = $this->makeNew([
            'clienteId' => 'cli-001',
            'dataEmissao' => '2026-05-01',
            'dataVencimento' => '2026-05-31',
            'valorBruto' => 1000.0,
            'numero' => '2026-9999', // bypass gerador SQL
        ]);

        $hook->beforeSave($f, SaveOptions::create());
        // Não lança; emissão permitida sem contrato.
        self::assertSame('cli-001', $f->get('clienteId'));
        self::assertSame('', (string) ($f->get('contratoHonorariosId') ?? ''));
    }

    /**
     * Contrato informado mas não vigente: NÃO bloqueia (escritório decide).
     */
    public function testContratoNaoVigenteNaoBloqueia(): void
    {
        $hook = $this->makeHook(
            contratoVigente: false,
            contratoCliente: 'cli-001',
        );
        $f = $this->makeNew([
            'contratoHonorariosId' => 'contrato-001',
            'dataEmissao' => '2026-05-01',
            'dataVencimento' => '2026-05-31',
            'valorBruto' => 1000.0,
            'numero' => '2026-9998', // bypass gerador SQL
        ]);

        $hook->beforeSave($f, SaveOptions::create());
        // Não lança; contrato não-vigente é aceito (decisão do operador).
        self::assertSame('contrato-001', $f->get('contratoHonorariosId'));
    }

    public function testClienteHerdadoDoContratoQuandoVazio(): void
    {
        $hook = $this->makeHook(
            contratoVigente: true,
            contratoCliente: 'cli-001',
        );
        $f = $this->makeNew([
            'contratoHonorariosId' => 'contrato-001',
            'dataEmissao' => '2026-05-01',
            'dataVencimento' => '2026-05-31',
            'valorBruto' => 1000.0,
            'numero' => '2026-9999', // bypass do gerador SQL para teste
        ]);
        // skipa generateNumero porque já tem numero; skipa cross-cliente porque
        // sem processoId.

        $hook->beforeSave($f, SaveOptions::create());
        self::assertSame('cli-001', $f->get('clienteId'));
    }

    public function testDataVencimentoAntesEmissaoLancaBadRequest(): void
    {
        $hook = $this->makeHook(contratoVigente: true, contratoCliente: 'cli-001');
        $f = $this->makeNew([
            'contratoHonorariosId' => 'contrato-001',
            'clienteId' => 'cli-001',
            'dataEmissao' => '2026-05-15',
            'dataVencimento' => '2026-05-01', // antes da emissão
            'valorBruto' => 1000.0,
            'numero' => '2026-9999',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/posterior à data de emissão/');
        $hook->beforeSave($f, SaveOptions::create());
    }

    public function testValorBrutoZeroLancaBadRequest(): void
    {
        $hook = $this->makeHook(contratoVigente: true, contratoCliente: 'cli-001');
        $f = $this->makeNew([
            'contratoHonorariosId' => 'contrato-001',
            'clienteId' => 'cli-001',
            'dataEmissao' => '2026-05-01',
            'dataVencimento' => '2026-05-31',
            'valorBruto' => 0,
            'numero' => '2026-9999',
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/maior que zero/');
        $hook->beforeSave($f, SaveOptions::create());
    }

    public function testInitializeValorPagoSaldoStatusNaCriacao(): void
    {
        $hook = $this->makeHook(contratoVigente: true, contratoCliente: 'cli-001');
        $f = $this->makeNew([
            'contratoHonorariosId' => 'contrato-001',
            'clienteId' => 'cli-001',
            'dataEmissao' => '2026-05-01',
            'dataVencimento' => '2026-05-31',
            'valorBruto' => 1000.0,
            'numero' => '2026-9999',
        ]);

        $hook->beforeSave($f, SaveOptions::create());

        self::assertSame(0.0, (float) $f->get('valorPago'));
        self::assertSame(1000.0, (float) $f->get('saldo'));
        self::assertSame(Fatura::STATUS_EMITIDA, $f->get('status'));
    }

    public function testBypassParaSavesDoFaturaSaldoService(): void
    {
        $hook = $this->makeHook();
        $f = $this->makeNew([
            // entity sem nenhum campo obrigatório — gate validations seriam disparadas
        ]);

        // Save silent + _fromRecompute = bypass.
        $options = SaveOptions::create([
            'silent' => true,
            '_fromRecompute' => true,
        ]);

        // Não deve lançar.
        $hook->beforeSave($f, $options);
        self::assertTrue(true);
    }

    public function testStatusMutacaoDiretaBloqueadaEmUpdate(): void
    {
        $hook = $this->makeHook(contratoVigente: true, contratoCliente: 'cli-001');
        $f = $this->makeExisting([
            'contratoHonorariosId' => 'contrato-001',
            'clienteId' => 'cli-001',
            'dataEmissao' => '2026-05-01',
            'dataVencimento' => '2026-05-31',
            'valorBruto' => 1000.0,
            'numero' => '2026-0001',
            'status' => 'emitida',
        ]);
        // muda status direto (cenário API/curl manipulação)
        $f->set('status', 'paga');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/Status só pode ser alterado/');
        $hook->beforeSave($f, SaveOptions::create());
    }

    public function testCanceladaNaoAceitaMudancaExcetoMotivoCancelamento(): void
    {
        $hook = $this->makeHook(contratoVigente: true, contratoCliente: 'cli-001');
        $f = $this->makeExisting([
            'contratoHonorariosId' => 'contrato-001',
            'clienteId' => 'cli-001',
            'dataEmissao' => '2026-05-01',
            'dataVencimento' => '2026-05-31',
            'valorBruto' => 1000.0,
            'numero' => '2026-0001',
            'status' => 'cancelada',
        ]);

        // Tenta alterar descricao (campo qualquer)
        $f->set('descricao', 'Novo texto');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessageMatches('/cancelada/');
        $hook->beforeSave($f, SaveOptions::create());
    }

    private function makeHook(
        bool $contratoVigente = false,
        ?string $contratoCliente = null,
    ): ValidateFaturaFieldsHook {
        $em = $this->createMock(EntityManager::class);

        if ($contratoCliente !== null) {
            $contrato = new ContratoHonorarios();
            $contrato->setId('contrato-001');
            $contrato->set('clienteId', $contratoCliente);

            $repo = new FakeFaturaValidateRepository($contrato);
            $em->method('getRDBRepository')->willReturn($repo);
        }

        $lookup = $this->createMock(ContratoHonorariosLookupService::class);
        $lookup->method('hasContratoVigente')->willReturn($contratoVigente);

        return new ValidateFaturaFieldsHook($em, $lookup);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function makeNew(array $attrs): Fatura
    {
        $f = new Fatura();
        $f->set($attrs);
        // Sem setId → isNew() === true por default.
        return $f;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function makeExisting(array $attrs): Fatura
    {
        $f = new Fatura();
        // setId(stub) já marca new=false → isNew()=false. setFetched marca
        // estado "vindo do banco"; set() com mesmo valor garante que
        // isAttributeChanged() retorne false (estado inicial coerente).
        $f->setId('fat-' . substr(uniqid(), -8));
        foreach ($attrs as $k => $v) {
            $f->setFetched($k, $v);
            $f->set($k, $v);
        }
        return $f;
    }
}

/**
 * @internal stub para Fatura validate hook (carrega contrato).
 */
final class FakeFaturaValidateRepository
{
    public function __construct(private readonly ?ContratoHonorarios $contrato)
    {
    }

    public function getById(string $id): ?ContratoHonorarios
    {
        if ($this->contrato === null) {
            return null;
        }
        return ((string) $this->contrato->getId() === $id) ? $this->contrato : null;
    }

    /** @param array<string, mixed> $where */
    public function where(array $where): self
    {
        return $this;
    }

    public function clone(mixed $query): self
    {
        return $this;
    }

    public function order(string $field, string $direction): self
    {
        return $this;
    }

    public function limit(int $offset, int $maxSize): self
    {
        return $this;
    }

    /** @return list<\Espo\ORM\Entity> */
    public function find(): array
    {
        return [];
    }

    public function findOne(): ?ContratoHonorarios
    {
        return null; // nenhum numero duplicado no contexto de teste
    }
}
