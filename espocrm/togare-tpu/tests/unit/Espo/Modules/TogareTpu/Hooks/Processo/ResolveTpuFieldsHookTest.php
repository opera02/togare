<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Hooks\Processo;

use Espo\Modules\TogareTpu\Contracts\AssuntoResolverContract;
use Espo\Modules\TogareTpu\Contracts\ClasseResolverContract;
use Espo\Modules\TogareTpu\Contracts\MovimentoResolverContract;
use Espo\Modules\TogareTpu\Exception\TpuCodeNotFoundException;
use Espo\Modules\TogareTpu\Hooks\Processo\ResolveTpuFieldsHook;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Implementações fake dos 3 ResolverContracts com mapping em-memória.
 */
final class FakeClasseResolver implements ClasseResolverContract
{
    /** @param array<int, array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}> $catalog */
    public function __construct(private array $catalog) {}

    public function resolveClasse(int $codigo): ?array
    {
        return $this->catalog[$codigo] ?? null;
    }
}

final class FakeAssuntoResolver implements AssuntoResolverContract
{
    /** @param array<int, array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}> $catalog */
    public function __construct(private array $catalog) {}

    public function resolveAssunto(int $codigo): ?array
    {
        return $this->catalog[$codigo] ?? null;
    }
}

final class FakeMovimentoResolver implements MovimentoResolverContract
{
    /** @param array<int, array{codigo:int, nome:string, pai_codigo:?int, ativo:bool}> $catalog */
    public function __construct(private array $catalog) {}

    public function resolveMovimento(int $codigo): ?array
    {
        return $this->catalog[$codigo] ?? null;
    }
}

/**
 * Stub de Espo\ORM\Entity usado nos testes do hook (sem depender da Entity
 * real do EspoCRM ou da Processo de togare-core).
 */
final class StubEntity implements Entity
{
    /** @var array<string, mixed> */
    private array $attributes = [];
    /** @var array<string, mixed> */
    private array $fetched = [];
    private bool $new = true;
    private string $entityType = 'Processo';

    public function getId(): ?string { return null; }

    public function get(string $name): mixed { return $this->attributes[$name] ?? null; }

    public function set(mixed $key, mixed $value = null): void
    {
        if (\is_array($key)) {
            foreach ($key as $k => $v) { $this->attributes[$k] = $v; }
            return;
        }
        $this->attributes[$key] = $value;
    }

    public function isNew(): bool { return $this->new; }

    public function setNotNew(): void { $this->new = false; }

    public function getEntityType(): string { return $this->entityType; }

    public function setEntityType(string $type): void { $this->entityType = $type; }

    public function isAttributeChanged(string $name): bool
    {
        if (! \array_key_exists($name, $this->fetched)) {
            return \array_key_exists($name, $this->attributes);
        }
        return $this->fetched[$name] !== ($this->attributes[$name] ?? null);
    }

    public function setFetched(string $name, mixed $value): void { $this->fetched[$name] = $value; }
}

/**
 * Cobre Story 3.4 AC4 — lookup TPU obrigatório no save (FR8).
 *
 * Hook valida classe/assunto/movimento contra catálogo. Código fora do
 * catálogo lança TpuCodeNotFoundException (extends BadRequest → HTTP 400).
 * Movimento é OPCIONAL.
 */
final class ResolveTpuFieldsHookTest extends TestCase
{
    private function buildHook(): ResolveTpuFieldsHook
    {
        return new ResolveTpuFieldsHook(
            new FakeClasseResolver([
                436 => ['codigo' => 436, 'nome' => 'Procedimento Comum Cível', 'pai_codigo' => 7, 'ativo' => true],
            ]),
            new FakeAssuntoResolver([
                10001 => ['codigo' => 10001, 'nome' => 'DIREITO CIVIL / Obrigações', 'pai_codigo' => null, 'ativo' => true],
            ]),
            new FakeMovimentoResolver([
                193 => ['codigo' => 193, 'nome' => 'Recebimento', 'pai_codigo' => null, 'ativo' => true],
            ]),
        );
    }

    public function testClasseLookupHitDenormalizaNome(): void
    {
        $hook = $this->buildHook();
        $proc = new StubEntity();
        $proc->set([
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
        ]);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('Procedimento Comum Cível', $proc->get('classeNome'));
        self::assertSame('DIREITO CIVIL / Obrigações', $proc->get('assuntoNome'));
    }

    public function testClasseCodigoNaoEncontradoLancaException(): void
    {
        $this->expectException(TpuCodeNotFoundException::class);
        $this->expectExceptionMessage('Código TPU 999999 não encontrado no catálogo de classe');

        $hook = $this->buildHook();
        $proc = new StubEntity();
        $proc->set('classeCodigo', 999999);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testAssuntoCodigoNaoEncontradoLancaException(): void
    {
        $this->expectException(TpuCodeNotFoundException::class);
        $this->expectExceptionMessage('Código TPU 888888 não encontrado no catálogo de assunto');

        $hook = $this->buildHook();
        $proc = new StubEntity();
        $proc->set([
            'classeCodigo' => 436,
            'assuntoCodigo' => 888888,
        ]);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testMovimentoCodigoNullLimpaMovimentoNome(): void
    {
        $hook = $this->buildHook();
        $proc = new StubEntity();
        $proc->setFetched('movimentoCodigo', 193);
        $proc->setFetched('movimentoNome', 'Recebimento');
        // Nova save com movimentoCodigo null → limpa movimentoNome
        $proc->set([
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
            'movimentoCodigo' => null,
        ]);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertNull($proc->get('movimentoNome'));
    }

    public function testMovimentoCodigoPreenchidoEnaoEncontradoFalha(): void
    {
        $this->expectException(TpuCodeNotFoundException::class);
        $this->expectExceptionMessage('Código TPU 555 não encontrado no catálogo de movimento');

        $hook = $this->buildHook();
        $proc = new StubEntity();
        $proc->set([
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
            'movimentoCodigo' => 555,
        ]);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testMovimentoCodigoPreenchidoEEncontradoDenormalizaNome(): void
    {
        $hook = $this->buildHook();
        $proc = new StubEntity();
        $proc->set([
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
            'movimentoCodigo' => 193,
        ]);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('Recebimento', $proc->get('movimentoNome'));
    }

    public function testEntityNaoProcessoNaoFazNada(): void
    {
        $hook = $this->buildHook();
        $proc = new StubEntity();
        $proc->setEntityType('Cliente');
        $proc->set([
            'classeCodigo' => 999999, // não seria encontrado, mas hook deve sair antes
        ]);

        $hook->beforeSave($proc, SaveOptions::create());

        // Sem exception = passou (early return por entityType !== Processo)
        self::assertNull($proc->get('classeNome'));
    }
}
