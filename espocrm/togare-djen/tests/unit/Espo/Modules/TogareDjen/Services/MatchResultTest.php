<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareDjen\Services\MatchResult;
use PHPUnit\Framework\TestCase;

/**
 * Story 4b.1b — MatchResult value-object (DTO de saída do PublicationMatcher).
 *
 * Cobre os 4 factory methods estáticos + propriedades readonly.
 */
final class MatchResultTest extends TestCase
{
    public function testSingleFactoryReturnsKindSingleComProcesso(): void
    {
        $processo = new CoreEntity();
        $processo->setId('proc-uuid-001');

        $r = MatchResult::single($processo, '00012345620248260001');

        self::assertSame('single', $r->kind);
        self::assertSame($processo, $r->processo);
        self::assertSame([], $r->candidatos);
        self::assertNull($r->ambiguityReason);
        self::assertSame('00012345620248260001', $r->numeroProcessoOriginal);
    }

    public function testNoneFactoryRetornaKindNoneSemProcesso(): void
    {
        $r = MatchResult::none('payload-cnj-malformed');

        self::assertSame('none', $r->kind);
        self::assertNull($r->processo);
        self::assertSame([], $r->candidatos);
        self::assertNull($r->ambiguityReason);
        self::assertSame('payload-cnj-malformed', $r->numeroProcessoOriginal);
    }

    public function testMultipleFactoryRetornaKindMultipleComCandidatosEReason(): void
    {
        $candidatos = [
            [
                'processoId' => 'proc-A',
                'numeroCnj' => '00012345620248260001',
                'clienteNome' => 'João Silva',
                'parteContrariaNome' => 'Banco X',
                'dataDistribuicao' => '2024-01-15',
                'area' => 'civel',
                'fase' => 'conhecimento',
                'codigoCor' => 'azul',
            ],
            [
                'processoId' => 'proc-B',
                'numeroCnj' => '00099999920248260001',
                'clienteNome' => 'Maria Souza',
                'parteContrariaNome' => 'Banco Y',
                'dataDistribuicao' => '2024-03-20',
                'area' => 'trabalhista',
                'fase' => 'recurso',
                'codigoCor' => 'laranja',
            ],
        ];

        $r = MatchResult::multiple($candidatos, 'name_match_multiplos_candidatos', '0000000-00.2024.0.00.0000');

        self::assertSame('multiple', $r->kind);
        self::assertNull($r->processo);
        self::assertCount(2, $r->candidatos);
        self::assertSame('proc-A', $r->candidatos[0]['processoId']);
        self::assertSame('azul', $r->candidatos[0]['codigoCor']);
        self::assertSame('laranja', $r->candidatos[1]['codigoCor']);
        self::assertSame('name_match_multiplos_candidatos', $r->ambiguityReason);
    }

    public function testTooManyFactoryRetornaKindTooManySemCandidatos(): void
    {
        $r = MatchResult::tooMany('cnj-original');

        self::assertSame('too_many', $r->kind);
        self::assertNull($r->processo);
        self::assertSame([], $r->candidatos);
        self::assertNull($r->ambiguityReason);
        self::assertSame('cnj-original', $r->numeroProcessoOriginal);
    }

    public function testReadonlyClass(): void
    {
        $r = MatchResult::none('cnj');
        $reflect = new \ReflectionClass(MatchResult::class);
        self::assertTrue($reflect->isReadOnly(), 'MatchResult deve ser readonly');
    }
}
