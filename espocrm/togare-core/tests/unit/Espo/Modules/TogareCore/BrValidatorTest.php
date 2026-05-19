<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore;

use Espo\Modules\TogareCore\Validators\BrValidator;
use PHPUnit\Framework\TestCase;

/**
 * Testes unit do BrValidator — funções puras, sem state estático, sem I/O.
 * Não precisa de RunInSeparateProcess.
 */
final class BrValidatorTest extends TestCase
{
    public function testCpfAcceptsValidWithAndWithoutMask(): void
    {
        // '529.982.247-25' é um CPF gerado com DV correto (fixture pública).
        self::assertTrue(BrValidator::isValidCpf('52998224725'));
        self::assertTrue(BrValidator::isValidCpf('529.982.247-25'));
    }

    public function testCpfRejectsInvalidDv(): void
    {
        self::assertFalse(BrValidator::isValidCpf('52998224700'));
        self::assertFalse(BrValidator::isValidCpf('12345678900'));
    }

    public function testCpfRejectsAllSameDigits(): void
    {
        foreach (['00000000000', '11111111111', '99999999999'] as $invalid) {
            self::assertFalse(
                BrValidator::isValidCpf($invalid),
                "CPF com todos iguais deveria ser rejeitado: {$invalid}",
            );
        }
    }

    public function testCpfRejectsWrongLength(): void
    {
        self::assertFalse(BrValidator::isValidCpf('1234567890'));  // 10 dígitos
        self::assertFalse(BrValidator::isValidCpf('123456789090')); // 12
        self::assertFalse(BrValidator::isValidCpf(''));
    }

    public function testCnpjAcceptsValid(): void
    {
        // '11.222.333/0001-81' — CNPJ fictício com DV correto.
        self::assertTrue(BrValidator::isValidCnpj('11222333000181'));
        self::assertTrue(BrValidator::isValidCnpj('11.222.333/0001-81'));
    }

    public function testCnpjRejectsInvalidDv(): void
    {
        self::assertFalse(BrValidator::isValidCnpj('11222333000100'));
        self::assertFalse(BrValidator::isValidCnpj('00000000000000')); // todos iguais
    }

    public function testCepAcceptsEightDigits(): void
    {
        self::assertTrue(BrValidator::isValidCep('01310100'));
        self::assertTrue(BrValidator::isValidCep('01310-100'));
    }

    public function testCepRejectsWrongLength(): void
    {
        self::assertFalse(BrValidator::isValidCep('0131010'));  // 7
        self::assertFalse(BrValidator::isValidCep('013101000')); // 9
        self::assertFalse(BrValidator::isValidCep(''));
    }

    public function testPhoneAcceptsFixedAndMobile(): void
    {
        self::assertTrue(BrValidator::isValidPhone('1138001234'));   // fixo SP
        self::assertTrue(BrValidator::isValidPhone('11987654321'));  // celular SP
        self::assertTrue(BrValidator::isValidPhone('(11) 98765-4321'));
    }

    public function testPhoneRejectsInvalidDdd(): void
    {
        self::assertFalse(BrValidator::isValidPhone('0138001234'));  // DDD 01
        self::assertFalse(BrValidator::isValidPhone('1038001234'));  // DDD 10
    }

    public function testPhoneRejectsMobileWithoutNinth(): void
    {
        // 11 dígitos mas 3º dígito não é 9 — celular BR moderno inválido.
        self::assertFalse(BrValidator::isValidPhone('11887654321'));
        self::assertFalse(BrValidator::isValidPhone('11187654321'));
    }

    public function testDigitsOnlyStripsMask(): void
    {
        self::assertSame('12345678909', BrValidator::digitsOnly('123.456.789-09'));
        self::assertSame('1138001234', BrValidator::digitsOnly('(11) 3800-1234'));
        self::assertSame('', BrValidator::digitsOnly('abc.def'));
    }
}
