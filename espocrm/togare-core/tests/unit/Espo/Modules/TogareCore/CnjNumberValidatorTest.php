<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use Espo\Modules\TogareCore\Validators\CnjNumberValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Testes unit do CnjNumberValidator — algoritmo mod 97 da Res. CNJ 65/2008.
 *
 * Fixtures fixas evitam que o teste replique o mesmo algoritmo do validador.
 * Cada número abaixo satisfaz a regra CNJ:
 * (NNNNNNN + AAAA + J + TR + OOOO + DD) mod 97 == 1.
 */
final class CnjNumberValidatorTest extends TestCase
{
    private const CNJ_SP_2023 = '00012340820238260100';
    private const CNJ_JF_2024 = '00055552220244037700';
    private const CNJ_TR_2022 = '00077775820225139999';
    private const CNJ_SP_2021 = '00011117920218190001';

    public function testValidCnjAcceptsBothFormats(): void
    {
        $raw = self::CNJ_SP_2023;

        self::assertTrue(CnjNumberValidator::isValid($raw));

        // Mesmo número em formato NNNNNNN-DD.AAAA.J.TR.OOOO.
        $formatted = sprintf(
            '%s-%s.%s.%s.%s.%s',
            substr($raw, 0, 7),
            substr($raw, 7, 2),
            substr($raw, 9, 4),
            substr($raw, 13, 1),
            substr($raw, 14, 2),
            substr($raw, 16, 4),
        );
        self::assertTrue(CnjNumberValidator::isValid($formatted));
    }

    public function testInvalidDvRejects(): void
    {
        $raw = self::CNJ_JF_2024;

        // Altera o 1º dígito do DV — quase certamente invalida.
        $bad = substr($raw, 0, 7) . $this->flipDigit($raw[7]) . substr($raw, 8);
        self::assertFalse(CnjNumberValidator::isValid($bad));
    }

    public function testRejectsWrongLength(): void
    {
        self::assertFalse(CnjNumberValidator::isValid(''));
        self::assertFalse(CnjNumberValidator::isValid('12345'));
        self::assertFalse(CnjNumberValidator::isValid('123456789012345678901')); // 21
    }

    public function testFormatFromRaw(): void
    {
        $raw = self::CNJ_TR_2022;
        $formatted = CnjNumberValidator::format($raw);

        self::assertMatchesRegularExpression(
            '/^\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}$/',
            $formatted,
        );
        // Idempotente: format(format(x)) == format(x).
        self::assertSame($formatted, CnjNumberValidator::format($formatted));
    }

    public function testFormatRejectsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CnjNumberValidator::format('not-a-cnj');
    }

    public function testFormatRejectsBadDv(): void
    {
        $raw = self::CNJ_SP_2021;
        $bad = substr($raw, 0, 7) . $this->flipDigit($raw[7]) . substr($raw, 8);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dígito verificador');
        CnjNumberValidator::format($bad);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function flipDigit(string $d): string
    {
        return (string) (((int) $d + 1) % 10);
    }
}
