<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Cliente;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Cliente;
use Espo\Modules\TogareCore\Hooks\Cliente\ValidateBrFieldsHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC2 + AC3 (Story 3.1) — validação BR server-side.
 *
 * Server NUNCA confia no client (architecture L581). BadRequest pt-BR é
 * lançado em qualquer payload inválido — framework EspoCRM converte para
 * HTTP 400 com header X-Status-Reason automaticamente.
 */
final class ValidateBrFieldsHookTest extends TestCase
{
    public function testPfComCpfValidoPassa(): void
    {
        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'João da Silva',
            'cpf' => '52998224725', // CPF válido (DV correto, não-todos-iguais)
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('João da Silva', $cliente->get('name'));
        self::assertSame('52998224725', $cliente->get('cpf'));
    }

    public function testPfSemCpfFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CPF é obrigatório para Pessoa Física.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'Sem CPF',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testPfComCpfDvInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CPF inválido — confira o número e tente de novo.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'DV errado',
            'cpf' => '12345678900', // 11 dígitos mas DV inválido
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testPfComCnpjPreenchidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Cliente Pessoa Física não pode ter CNPJ — preencha apenas o CPF.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'PF com CNPJ ilegal',
            'cpf' => '52998224725',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testPjComCnpjValidoPassa(): void
    {
        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pj',
            'name' => 'Empresa S.A.',
            'razaoSocial' => 'Empresa Sociedade Anônima',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('11222333000181', $cliente->get('cnpj'));
    }

    public function testPjComCnpjDvInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CNPJ inválido — confira o número e tente de novo.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pj',
            'name' => 'CNPJ errado',
            'razaoSocial' => 'Razão Inválida',
            'cnpj' => '11222333000180', // DV errado
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testPjSemRazaoSocialFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Razão social é obrigatória para Pessoa Jurídica.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pj',
            'name' => 'Sem razão',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testPjBestEffortCopiaRazaoSocialParaName(): void
    {
        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pj',
            'name' => '', // vazio — gatilho do best-effort
            'razaoSocial' => 'Auto-Copy Ltda',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('Auto-Copy Ltda', $cliente->get('name'));
    }

    public function testTelefoneDddInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Telefone inválido — DDD entre 11 e 99; celular precisa do nono dígito.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'DDD ruim',
            'cpf' => '52998224725',
            'telefone' => '1038001234', // DDD 10 inválido
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testCelularSemNonoDigitoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Telefone inválido — DDD entre 11 e 99; celular precisa do nono dígito.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'Celular sem 9',
            'cpf' => '52998224725',
            'telefone' => '11887654321', // 11 dígitos mas 3º != 9
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testCepCom7DigitosFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CEP inválido — devem ser exatamente 8 dígitos.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'CEP curto',
            'cpf' => '52998224725',
            'cep' => '0131010', // 7 dígitos
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testTipoPessoaInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Tipo de pessoa inválido — escolha Pessoa Física ou Pessoa Jurídica.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'xx',
            'name' => 'Tipo errado',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testTelefone2DddInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Telefone inválido — DDD entre 11 e 99; celular precisa do nono dígito.');

        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'name' => 'DDD ruim telefone2',
            'cpf' => '52998224725',
            'telefone2' => '1038001234', // DDD 10 inválido
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());
    }

    public function testPjNaoSobrescreverNameJaPreenchidoComRazaoSocial(): void
    {
        $hook = new ValidateBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pj',
            'name' => 'Nome Já Existente',
            'razaoSocial' => 'Razao Social Nova Ltda',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('Nome Já Existente', $cliente->get('name'));
    }
}
