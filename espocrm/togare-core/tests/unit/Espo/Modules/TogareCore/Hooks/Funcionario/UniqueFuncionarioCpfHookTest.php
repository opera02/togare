<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Funcionario;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Funcionario;
use Espo\Modules\TogareCore\Hooks\Funcionario\UniqueFuncionarioCpfHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.5 fix-pass 0.37.2 (AC1) — CPF único entre funcionários ATIVOS,
 * com mensagem pt-BR amigável (não HTTP 500).
 *
 * Bug original (smoke browser Felipe passo 2): índice UNIQUE cru de banco
 * → PDOException/500 + CPF de funcionário soft-deleted continuava bloqueado.
 * A exclusão de soft-deleted é garantida pelo default do RDBRepository
 * (`where()->findOne()` filtra deleted=0) e coberta no smoke CLI step 12.
 */
final class UniqueFuncionarioCpfHookTest extends TestCase
{
    public function testCpfUnicoPassa(): void
    {
        $hook = $this->makeHook(null); // repo não encontra duplicado
        $f = new Funcionario();
        $f->set(['nome' => 'João', 'cargo' => 'Advogado', 'cpf' => '52998224725']);

        $hook->beforeSave($f, SaveOptions::create());

        self::assertSame('52998224725', $f->get('cpf'));
    }

    public function testCpfDuplicadoEntreAtivosLancaBadRequestPtBr(): void
    {
        $existente = new Funcionario();
        $existente->setId('func-existente-1');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Já existe um funcionário cadastrado com este CPF.');

        $hook = $this->makeHook($existente);
        $f = new Funcionario();
        $f->set(['nome' => 'João Dup', 'cargo' => 'RH', 'cpf' => '52998224725']);

        $hook->beforeSave($f, SaveOptions::create());
    }

    public function testCpfVazioNaoConsultaRepositorio(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getRDBRepository');
        $hook = new UniqueFuncionarioCpfHook($em);

        $f = new Funcionario();
        $f->set(['nome' => 'Sem CPF', 'cargo' => 'Estagiário']);

        $hook->beforeSave($f, SaveOptions::create());
        self::assertNull($f->get('cpf'));
    }

    public function testEntidadeNaoFuncionarioEhNoOp(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects(self::never())->method('getRDBRepository');
        $hook = new UniqueFuncionarioCpfHook($em);

        $outra = new \Espo\Modules\TogareCore\Entities\Cliente();
        $outra->set(['cpf' => '52998224725']);

        $hook->beforeSave($outra, SaveOptions::create());
        self::assertSame('52998224725', $outra->get('cpf'));
    }

    public function testOrderEhVinteCincoParaRodarAposNormalizeEValidate(): void
    {
        self::assertSame(25, UniqueFuncionarioCpfHook::$order);
    }

    private function makeHook(?Funcionario $existing): UniqueFuncionarioCpfHook
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn(new FakeFuncionarioUniqueRepository($existing));

        return new UniqueFuncionarioCpfHook($em);
    }
}

/**
 * @internal stub do RDBRepository para o hook de unicidade.
 * `where()` é chainável; `findOne()` devolve o duplicado configurado (ou null).
 */
final class FakeFuncionarioUniqueRepository
{
    public function __construct(private readonly ?Funcionario $existing)
    {
    }

    /** @param array<string, mixed> $where */
    public function where(array $where): self
    {
        return $this;
    }

    public function findOne(): ?Funcionario
    {
        return $this->existing;
    }
}
