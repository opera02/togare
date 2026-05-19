<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\ParteContraria;

use Espo\Modules\TogareCore\Entities\ParteContraria;
use Espo\Modules\TogareCore\Hooks\ParteContraria\NormalizeBrFieldsHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC5 (Story 3.2) — storage SÓ DÍGITOS via beforeSave.
 *
 * Arquitetura L457: CPF/CNPJ/telefone armazenados sem máscara. Helpers
 * Handlebars aplicam máscara só na apresentação.
 *
 * Diferença vs Cliente: ParteContraria não tem `cep` nem `telefone2`.
 */
final class NormalizeBrFieldsHookTest extends TestCase
{
    public function testRemoveMascaraDeCpf(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'Réu Mascarado',
            'cpf' => '123.456.789-09',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('12345678909', $parte->get('cpf'));
    }

    public function testRemoveMascaraDeCnpj(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pj',
            'name' => 'Empresa Ré',
            'cnpj' => '11.222.333/0001-81',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('11222333000181', $parte->get('cnpj'));
    }

    public function testRemoveMascaraDeTelefone(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'Tel Mascarado',
            'telefone' => '(11) 98765-4321',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertSame('11987654321', $parte->get('telefone'));
    }

    public function testIdempotenteQuandoJaSoDigitos(): void
    {
        $hook = new NormalizeBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'Já sem máscara',
            'cpf' => '12345678909',
            'telefone' => '11987654321',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        // Não muda — já estava sem máscara.
        self::assertSame('12345678909', $parte->get('cpf'));
        self::assertSame('11987654321', $parte->get('telefone'));
    }

    public function testWhitespaceOnlyViraNull(): void
    {
        // Bug #P4 do code review: input "   " (só espaços) ou só máscara sem
        // dígitos vinha sendo persistido como string vazia em vez de NULL,
        // criando inconsistência de NULL vs "" no DB.
        $hook = new NormalizeBrFieldsHook();
        $parte = new ParteContraria();
        $parte->set([
            'tipoPessoa' => 'pf',
            'name' => 'Whitespace',
            'cpf' => '   ',
            'telefone' => '(  ) -',
        ]);

        $hook->beforeSave($parte, SaveOptions::create());

        self::assertNull($parte->get('cpf'));
        self::assertNull($parte->get('telefone'));
    }
}
