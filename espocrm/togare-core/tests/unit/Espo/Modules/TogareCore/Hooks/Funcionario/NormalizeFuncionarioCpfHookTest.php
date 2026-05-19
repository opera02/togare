<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Funcionario;

use Espo\Modules\TogareCore\Entities\Funcionario;
use Espo\Modules\TogareCore\Hooks\Funcionario\NormalizeFuncionarioCpfHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.5 (AC2) — storage do CPF do Funcionario SÓ DÍGITOS via beforeSave.
 *
 * Arquitetura L457: CPF armazenado sem máscara. Espelha o comportamento de
 * NormalizeBrFieldsHook do Cliente reduzido ao campo cpf.
 */
final class NormalizeFuncionarioCpfHookTest extends TestCase
{
    public function testRemoveMascaraDeCpf(): void
    {
        $hook = new NormalizeFuncionarioCpfHook();
        $f = new Funcionario();
        $f->set('cpf', '529.982.247-25');

        $hook->beforeSave($f, SaveOptions::create());

        self::assertSame('52998224725', $f->get('cpf'));
    }

    public function testIdempotenteQuandoJaSoDigitos(): void
    {
        $hook = new NormalizeFuncionarioCpfHook();
        $f = new Funcionario();
        $f->set('cpf', '52998224725');

        $hook->beforeSave($f, SaveOptions::create());

        self::assertSame('52998224725', $f->get('cpf'));
    }

    public function testCpfVazioOuNullViraNullCanonicamente(): void
    {
        $hook = new NormalizeFuncionarioCpfHook();

        $vazio = new Funcionario();
        $vazio->set('cpf', '');
        $hook->beforeSave($vazio, SaveOptions::create());
        self::assertNull($vazio->get('cpf'));

        $mascaraVazia = new Funcionario();
        $mascaraVazia->set('cpf', '...---');
        $hook->beforeSave($mascaraVazia, SaveOptions::create());
        self::assertNull($mascaraVazia->get('cpf'));

        $nulo = new Funcionario();
        // cpf nunca setado → null
        $hook->beforeSave($nulo, SaveOptions::create());
        self::assertNull($nulo->get('cpf'));
    }

    public function testOrderEhDezParaRodarAntesDaValidacao(): void
    {
        self::assertSame(10, NormalizeFuncionarioCpfHook::$order);
    }
}
