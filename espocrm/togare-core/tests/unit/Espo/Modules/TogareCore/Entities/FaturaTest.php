<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Entities;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Entities\Fatura;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.3 — testa constantes + getters puros de Fatura.
 */
final class FaturaTest extends TestCase
{
    public function testEntityTypeConstant(): void
    {
        self::assertSame('Fatura', Fatura::ENTITY_TYPE);
    }

    public function testStatusesCanonicos(): void
    {
        self::assertSame(
            ['emitida', 'parcialmente_paga', 'paga', 'vencida', 'cancelada'],
            Fatura::STATUSES,
        );
    }

    public function testStatusesOpenExcluiPagaECancelada(): void
    {
        self::assertContains(Fatura::STATUS_EMITIDA, Fatura::STATUSES_OPEN);
        self::assertContains(Fatura::STATUS_PARCIALMENTE_PAGA, Fatura::STATUSES_OPEN);
        self::assertContains(Fatura::STATUS_VENCIDA, Fatura::STATUSES_OPEN);
        self::assertNotContains(Fatura::STATUS_PAGA, Fatura::STATUSES_OPEN);
        self::assertNotContains(Fatura::STATUS_CANCELADA, Fatura::STATUSES_OPEN);
    }

    public function testIsVencidaCanceladaSempreFalse(): void
    {
        $f = new Fatura();
        $f->set('status', Fatura::STATUS_CANCELADA);
        $f->set('saldo', 100.0);
        $f->set('dataVencimento', '2020-01-01');
        self::assertFalse($f->isVencida(new DateTimeImmutable('2026-05-15')));
    }

    public function testIsVencidaSaldoZeroRetornaFalse(): void
    {
        $f = new Fatura();
        $f->set('status', Fatura::STATUS_PAGA);
        $f->set('saldo', 0.0);
        $f->set('dataVencimento', '2020-01-01');
        self::assertFalse($f->isVencida(new DateTimeImmutable('2026-05-15')));
    }

    public function testIsVencidaDataVencimentoPassadaSaldoPositivoRetornaTrue(): void
    {
        $f = new Fatura();
        $f->set('status', Fatura::STATUS_VENCIDA);
        $f->set('saldo', 100.0);
        $f->set('dataVencimento', '2025-01-01');
        self::assertTrue($f->isVencida(new DateTimeImmutable('2026-05-15')));
    }

    public function testIsVencidaDataVencimentoFuturaRetornaFalse(): void
    {
        $f = new Fatura();
        $f->set('status', Fatura::STATUS_EMITIDA);
        $f->set('saldo', 100.0);
        $f->set('dataVencimento', '2026-12-31');
        self::assertFalse($f->isVencida(new DateTimeImmutable('2026-05-15')));
    }

    public function testIsPaga(): void
    {
        $f = new Fatura();
        $f->set('status', Fatura::STATUS_PAGA);
        self::assertTrue($f->isPaga());

        $f->set('status', Fatura::STATUS_EMITIDA);
        self::assertFalse($f->isPaga());
    }

    public function testIsCancelada(): void
    {
        $f = new Fatura();
        $f->set('status', Fatura::STATUS_CANCELADA);
        self::assertTrue($f->isCancelada());

        $f->set('status', Fatura::STATUS_EMITIDA);
        self::assertFalse($f->isCancelada());
    }
}
