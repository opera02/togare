<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Entities;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 — testa constantes + isVigente() puro do ContratoHonorarios.
 */
final class ContratoHonorariosTest extends TestCase
{
    public function testEntityTypeConstant(): void
    {
        self::assertSame('ContratoHonorarios', ContratoHonorarios::ENTITY_TYPE);
    }

    public function testModalidadesCanonicas(): void
    {
        self::assertSame(
            ['fixo', 'exito', 'sucumbencia', 'mensal', 'hora_trabalhada', 'misto'],
            ContratoHonorarios::MODALIDADES,
        );
    }

    public function testModalidadesComValor(): void
    {
        self::assertContains('fixo', ContratoHonorarios::MODALIDADES_COM_VALOR);
        self::assertContains('mensal', ContratoHonorarios::MODALIDADES_COM_VALOR);
        self::assertContains('hora_trabalhada', ContratoHonorarios::MODALIDADES_COM_VALOR);
        self::assertContains('misto', ContratoHonorarios::MODALIDADES_COM_VALOR);
        self::assertNotContains('exito', ContratoHonorarios::MODALIDADES_COM_VALOR);
        self::assertNotContains('sucumbencia', ContratoHonorarios::MODALIDADES_COM_VALOR);
    }

    public function testModalidadesComPercentual(): void
    {
        self::assertContains('exito', ContratoHonorarios::MODALIDADES_COM_PERCENTUAL);
        self::assertContains('sucumbencia', ContratoHonorarios::MODALIDADES_COM_PERCENTUAL);
        self::assertContains('misto', ContratoHonorarios::MODALIDADES_COM_PERCENTUAL);
        self::assertNotContains('fixo', ContratoHonorarios::MODALIDADES_COM_PERCENTUAL);
    }

    public function testAllowedMimeTypesEPdfOnly(): void
    {
        self::assertSame(['application/pdf'], ContratoHonorarios::ALLOWED_MIME_TYPES);
    }

    public function testIsVigenteContratoSemVigenciaInicioRetornaFalse(): void
    {
        $c = new ContratoHonorarios();
        // vigenciaInicio NULL → não vigente.
        self::assertFalse($c->isVigente(new DateTimeImmutable('2026-05-13')));
    }

    public function testIsVigenteVigenciaInicioFuturaRetornaFalse(): void
    {
        $c = new ContratoHonorarios();
        $c->set('vigenciaInicio', '2026-06-01');
        $c->set('vigenciaFim', '2026-12-31');
        self::assertFalse($c->isVigente(new DateTimeImmutable('2026-05-13')));
    }

    public function testIsVigenteVigenciaFimNullContratoAbertoRetornaTrue(): void
    {
        $c = new ContratoHonorarios();
        $c->set('vigenciaInicio', '2026-01-01');
        $c->set('vigenciaFim', null);
        self::assertTrue($c->isVigente(new DateTimeImmutable('2026-05-13')));
    }

    public function testIsVigenteVigenciaFimNoPassadoRetornaFalse(): void
    {
        $c = new ContratoHonorarios();
        $c->set('vigenciaInicio', '2025-01-01');
        $c->set('vigenciaFim', '2025-12-31');
        self::assertFalse($c->isVigente(new DateTimeImmutable('2026-05-13')));
    }

    public function testIsVigenteJanelaCorrenteRetornaTrue(): void
    {
        $c = new ContratoHonorarios();
        $c->set('vigenciaInicio', '2026-01-01');
        $c->set('vigenciaFim', '2026-12-31');
        self::assertTrue($c->isVigente(new DateTimeImmutable('2026-05-13')));
    }

    public function testIsVigenteSemReferenceUsaHoje(): void
    {
        $c = new ContratoHonorarios();
        $c->set('vigenciaInicio', '2020-01-01');
        $c->set('vigenciaFim', null);
        // Default reference = today; vigência início no passado + fim aberto → vigente.
        self::assertTrue($c->isVigente());
    }
}
