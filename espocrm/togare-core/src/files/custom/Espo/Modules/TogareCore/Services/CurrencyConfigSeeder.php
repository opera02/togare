<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

/**
 * Garante defaults de moeda coerentes com o domÃ­nio brasileiro do Togare.
 *
 * Story 6.5 fechou o deferred herdado da 6.1: campos currency com
 * defaultCurrency=BRL nÃ£o funcionam fim-a-fim se o EspoCRM mantÃ©m a config
 * stock currencyList/defaultCurrency/baseCurrency em USD.
 */
final class CurrencyConfigSeeder
{
    public const BRL = 'BRL';

    /**
     * @param mixed $rawCurrencyList
     * @return array{changed: bool, currencyList: list<string>, defaultCurrency: string, baseCurrency: string}
     */
    public static function buildBrlConfig(
        mixed $rawCurrencyList,
        mixed $currentDefaultCurrency,
        mixed $currentBaseCurrency,
    ): array {
        $currencyList = [];
        if (\is_array($rawCurrencyList)) {
            foreach ($rawCurrencyList as $code) {
                if (\is_string($code) && $code !== '' && ! \in_array($code, $currencyList, true)) {
                    $currencyList[] = $code;
                }
            }
        }

        if (! \in_array(self::BRL, $currencyList, true)) {
            $currencyList[] = self::BRL;
        }

        $defaultCurrency = self::BRL;
        $baseCurrency = self::BRL;

        return [
            'changed' => $currencyList !== $rawCurrencyList
                || $currentDefaultCurrency !== $defaultCurrency
                || $currentBaseCurrency !== $baseCurrency,
            'currencyList' => $currencyList,
            'defaultCurrency' => $defaultCurrency,
            'baseCurrency' => $baseCurrency,
        ];
    }
}
