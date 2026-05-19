<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\ParteContraria;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\ParteContraria;
use Espo\Modules\TogareCore\Hooks\ParteContraria\ValidateBrFieldsHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC2 + AC3 + AC4 (Story 3.2) — validação BR server-side.
 *
 * Server NUNCA confia no client (architecture L581). BadRequest pt-BR é
 * lançado em qualquer payload inválido — framework EspoCRM converte para
 * HTTP 400 com header X-Status-Reason automaticamente.
 *
 * Diferença CRÍTICA vs Cliente: CPF e CNPJ são OPCIONAIS em todos os tipos.
 * Tipo `desconhecida` adicional permite parte sem documento.
 */
final class ValidateBrFieldsHookTest extends TestCase
{
    public function testPfSemCpfPassa(): void
    {
        // Diferença chave vs Cliente — PF sem CPF DEVE passar em ParteContraria.
        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'Réu sem documento',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('Réu sem documento', $parte->get('name'));
        self::assertNull($parte->get('cpf'));
    }

    public function testPfComCpfValidoPassa(): void
    {
        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'João Réu',
            'cpf' => '52998224725',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('52998224725', $parte->get('cpf'));
    }

    public function testPfComCpfDvInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CPF inválido — confira o número e tente de novo.');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'DV errado',
            'cpf' => '12345678900',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testPfComCnpjPreenchidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Parte Pessoa Física não pode ter CNPJ');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'PF com CNPJ ilegal',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testPjSemCnpjPassa(): void
    {
        // Diferença chave vs Cliente — PJ sem CNPJ DEVE passar em ParteContraria.
        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pj',
            'name' => 'Empresa Ré sem CNPJ',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('Empresa Ré sem CNPJ', $parte->get('name'));
        self::assertNull($parte->get('cnpj'));
    }

    public function testPjComCnpjValidoPassa(): void
    {
        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pj',
            'name' => 'Empresa Válida',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('11222333000181', $parte->get('cnpj'));
    }

    public function testPjComCnpjDvInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('CNPJ inválido — confira o número e tente de novo.');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pj',
            'name' => 'CNPJ errado',
            'cnpj' => '11222333000180',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testPjComCpfPreenchidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Parte Pessoa Jurídica não pode ter CPF');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pj',
            'name' => 'PJ com CPF ilegal',
            'cpf' => '52998224725',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testDesconhecidaSemDocumentoPassa(): void
    {
        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'desconhecida',
            'name' => 'Parte Anônima',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('Parte Anônima', $parte->get('name'));
    }

    public function testDesconhecidaComCpfFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Parte desconhecida não pode ter CPF');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'desconhecida',
            'name' => 'Anônima com CPF',
            'cpf' => '52998224725',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testDesconhecidaComCnpjFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Parte desconhecida não pode ter CNPJ');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'desconhecida',
            'name' => 'Anônima com CNPJ',
            'cnpj' => '11222333000181',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testTipoPessoaInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Tipo de parte inválido');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'xx',
            'name' => 'Tipo errado',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testTelefoneInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Telefone inválido');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'DDD ruim',
            'telefone' => '1038001234',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }

    public function testNameVazioFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Nome é obrigatório');

        $hook = new ValidateBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => '',
            'cpf' => '52998224725',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());
    }
}
