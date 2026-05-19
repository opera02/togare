<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Cliente;

use Espo\Modules\TogareCore\Entities\Cliente;
use Espo\Modules\TogareCore\Hooks\Cliente\NormalizeBrFieldsHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC4 (Story 3.1) — storage SÓ DÍGITOS via beforeSave.
 *
 * Arquitetura L457: CPF/CNPJ/CEP/telefone armazenados sem máscara. Helpers
 * Handlebars aplicam máscara só na apresentação.
 */
final class NormalizeBrFieldsHookTest extends TestCase
{
    public function testRemoveMascaraDeCpf(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'cpf' => '123.456.789-09',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('12345678909', $cliente->get('cpf'));
    }

    public function testRemoveMascaraDeCnpj(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pj',
            'cnpj' => '11.222.333/0001-81',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('11222333000181', $cliente->get('cnpj'));
    }

    public function testRemoveMascaraDeCepETelefone(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'cep' => '01310-100',
            'telefone' => '(11) 98765-4321',
            'telefone2' => '(11) 3800-1234',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('01310100', $cliente->get('cep'));
        self::assertSame('11987654321', $cliente->get('telefone'));
        self::assertSame('1138001234', $cliente->get('telefone2'));
    }

    public function testIdempotenteQuandoJaSoDigitos(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'cpf' => '12345678909',
            'telefone' => '11987654321',
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        // Não muda — já estava sem máscara.
        self::assertSame('12345678909', $cliente->get('cpf'));
        self::assertSame('11987654321', $cliente->get('telefone'));
    }

    public function testCamposVaziosOuNullPassamSemTrabalho(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $cliente = new Cliente();
        $cliente->set([
            'tipoPessoa' => 'pf',
            'cpf' => '12345678909',
            // cnpj, cep, telefone, telefone2 ausentes/null
        ]);

        $hook->beforeSave($cliente, SaveOptions::create());

        self::assertSame('12345678909', $cliente->get('cpf'));
        self::assertNull($cliente->get('cnpj'));
        self::assertNull($cliente->get('cep'));
    }
}
