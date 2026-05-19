<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Funcionario;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Funcionario;
use Espo\Modules\TogareCore\Hooks\Funcionario\ValidateFuncionarioCpfHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.5 (AC2) — validação server-side do CPF do Funcionario.
 *
 * Server NUNCA confia no client (architecture L581). CPF é OPCIONAL no
 * Funcionario: ausência passa; presença DEVE ser válida (mod 11 / não
 * sequência repetida) senão lança BadRequest pt-BR.
 */
final class ValidateFuncionarioCpfHookTest extends TestCase
{
    public function testCpfValidoPassa(): void
    {
        $hook = new ValidateFuncionarioCpfHook();
        $f = new Funcionario();
        $f->set([
            'nome' => 'João da Silva',
            'cargo' => 'Advogado',
            'cpf' => '52998224725', // CPF válido
        ]);

        $hook->beforeSave($f, SaveOptions::create());

        self::assertSame('52998224725', $f->get('cpf'));
    }

    public function testCpfAusentePassaPorqueEhOpcional(): void
    {
        $hook = new ValidateFuncionarioCpfHook();
        $f = new Funcionario();
        $f->set([
            'nome' => 'Sem CPF ainda',
            'cargo' => 'Estagiário',
        ]);

        $hook->beforeSave($f, SaveOptions::create());

        self::assertNull($f->get('cpf'));
    }

    public function testCpfVazioPassaPorqueEhOpcional(): void
    {
        $hook = new ValidateFuncionarioCpfHook();
        $f = new Funcionario();
        $f->set(['nome' => 'Vazio', 'cargo' => 'RH', 'cpf' => '']);

        $hook->beforeSave($f, SaveOptions::create());

        self::assertSame('', $f->get('cpf'));
    }

    public function testCpfComDvInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CPF inválido — confira o número e tente de novo.');

        $hook = new ValidateFuncionarioCpfHook();
        $f = new Funcionario();
        $f->set([
            'nome' => 'DV errado',
            'cargo' => 'Financeiro',
            'cpf' => '12345678900', // 11 dígitos mas DV inválido
        ]);

        $hook->beforeSave($f, SaveOptions::create());
    }

    public function testCpfSequenciaRepetidaFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CPF inválido — confira o número e tente de novo.');

        $hook = new ValidateFuncionarioCpfHook();
        $f = new Funcionario();
        $f->set([
            'nome' => 'Sequência',
            'cargo' => 'Secretária',
            'cpf' => '11111111111', // todos iguais
        ]);

        $hook->beforeSave($f, SaveOptions::create());
    }

    public function testOrderEhVinteParaRodarDepoisDaNormalizacao(): void
    {
        self::assertSame(20, ValidateFuncionarioCpfHook::$order);
    }
}
