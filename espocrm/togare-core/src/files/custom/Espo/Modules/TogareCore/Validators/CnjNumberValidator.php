<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Validators;

use InvalidArgumentException;

/**
 * Validador e formatador de número CNJ (Res. CNJ 65/2008).
 *
 * Formato: NNNNNNN-DD.AAAA.J.TR.OOOO (20 dígitos totais)
 *   NNNNNNN = 7 dígitos sequenciais da unidade de origem
 *   DD      = 2 dígitos verificadores (mod 97)
 *   AAAA    = 4 dígitos do ano de distribuição
 *   J       = 1 dígito do órgão (1=STF, 2=CNJ, 3=STJ, 4=Justiça Federal, ...)
 *   TR      = 2 dígitos do tribunal
 *   OOOO    = 4 dígitos da unidade de origem
 *
 * Regra do DV: (NNNNNNN + AAAA + J + TR + OOOO + DD) mod 97 == 1.
 *
 * Como o número tem 20 dígitos (excede int64 em alguns casos), calculamos
 * o mod 97 progressivamente caractere-a-caractere — evita dependência de
 * ext-bcmath ou ext-gmp.
 */
final class CnjNumberValidator
{
    private const CNJ_REGEX_FORMATTED =
        '/^(\d{7})-(\d{2})\.(\d{4})\.(\d)\.(\d{2})\.(\d{4})$/';

    /**
     * Aceita input nos 2 formatos:
     *   - 20 dígitos puros: '00012345620238260100'
     *   - Formatado: '0001234-56.2023.8.26.0100'
     */
    public static function isValid(string $input): bool
    {
        $digits = BrValidator::digitsOnly($input);
        if (\strlen($digits) !== 20) {
            return false;
        }

        return self::modular97(self::toVerificationSequence($digits)) === 1;
    }

    /**
     * Normaliza input para o formato padrão NNNNNNN-DD.AAAA.J.TR.OOOO.
     * Aceita input de 20 dígitos ou já formatado (idempotente).
     *
     * @throws InvalidArgumentException se o input for inválido (tamanho
     *                                  errado ou DV não bate)
     */
    public static function format(string $input): string
    {
        $digits = BrValidator::digitsOnly($input);
        if (\strlen($digits) !== 20) {
            throw new InvalidArgumentException(
                "Número CNJ inválido: esperado 20 dígitos, recebeu " . \strlen($digits),
            );
        }
        if (! self::isValid($digits)) {
            throw new InvalidArgumentException(
                "Número CNJ inválido: dígito verificador não confere",
            );
        }

        return \sprintf(
            '%s-%s.%s.%s.%s.%s',
            \substr($digits, 0, 7),   // NNNNNNN
            \substr($digits, 7, 2),   // DD
            \substr($digits, 9, 4),   // AAAA
            \substr($digits, 13, 1),  // J
            \substr($digits, 14, 2),  // TR
            \substr($digits, 16, 4),  // OOOO
        );
    }

    /**
     * Calcula mod 97 progressivamente — trabalha caractere-a-caractere para
     * evitar overflow de int64 (número CNJ tem até 20 dígitos).
     *
     * Invariante: a cada passo, $n equivale a (número lido até agora) mod 97.
     */
    private static function modular97(string $digits): int
    {
        $n = 0;
        $len = \strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $n = ($n * 10 + (int) $digits[$i]) % 97;
        }
        return $n;
    }

    /**
     * Storage/formato visual: NNNNNNNDD.AAAA.J.TR.OOOO.
     * Sequência de verificação CNJ: NNNNNNNAAAAJTROOOODD.
     */
    private static function toVerificationSequence(string $digits): string
    {
        return \substr($digits, 0, 7)
            . \substr($digits, 9, 4)
            . \substr($digits, 13, 1)
            . \substr($digits, 14, 2)
            . \substr($digits, 16, 4)
            . \substr($digits, 7, 2);
    }
}
