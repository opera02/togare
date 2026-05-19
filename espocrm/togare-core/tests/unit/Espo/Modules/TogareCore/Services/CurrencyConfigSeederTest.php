<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Services\CurrencyConfigSeeder;
use PHPUnit\Framework\TestCase;

final class CurrencyConfigSeederTest extends TestCase
{
    public function testAdicionaBrlEPromoveDefaultEBaseCurrency(): void
    {
        $next = CurrencyConfigSeeder::buildBrlConfig(['USD'], 'USD', 'USD');

        self::assertTrue($next['changed']);
        self::assertSame(['USD', 'BRL'], $next['currencyList']);
        self::assertSame('BRL', $next['defaultCurrency']);
        self::assertSame('BRL', $next['baseCurrency']);
    }

    public function testEhIdempotenteQuandoJaEstaEmBrl(): void
    {
        $next = CurrencyConfigSeeder::buildBrlConfig(['USD', 'BRL'], 'BRL', 'BRL');

        self::assertFalse($next['changed']);
        self::assertSame(['USD', 'BRL'], $next['currencyList']);
        self::assertSame('BRL', $next['defaultCurrency']);
        self::assertSame('BRL', $next['baseCurrency']);
    }

    public function testSanitizaCurrencyListMalformada(): void
    {
        $next = CurrencyConfigSeeder::buildBrlConfig(['USD', 'USD', '', 123], 'USD', 'USD');

        self::assertTrue($next['changed']);
        self::assertSame(['USD', 'BRL'], $next['currencyList']);
    }
}
