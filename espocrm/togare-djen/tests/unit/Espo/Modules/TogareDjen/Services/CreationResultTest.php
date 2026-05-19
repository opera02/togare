<?php

declare(strict_types=1);

namespace Tests\Unit\Espo\Modules\TogareDjen\Services;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareDjen\Services\CreationResult;
use PHPUnit\Framework\TestCase;

/**
 * Story 4b.1b — CreationResult value-object (DTO de saída do PrazoCreatorService::create).
 *
 * Cobre os 4 factory methods estáticos + propriedades readonly.
 */
final class CreationResultTest extends TestCase
{
    public function testPrazoBoundFactoryRetornaKindPrazoBound(): void
    {
        $prazo = new CoreEntity();
        $prazo->setId('prazo-uuid-001');

        $r = CreationResult::prazoBound($prazo);

        self::assertSame('prazo_bound', $r->kind);
        self::assertSame($prazo, $r->entity);
    }

    public function testPrazoRascunhoFactoryRetornaKindPrazoRascunho(): void
    {
        $prazo = new CoreEntity();
        $prazo->setId('prazo-uuid-rascunho');

        $r = CreationResult::prazoRascunho($prazo);

        self::assertSame('prazo_rascunho', $r->kind);
        self::assertSame($prazo, $r->entity);
    }

    public function testPublicacaoAmbiguaFactoryRetornaKindCorreto(): void
    {
        $pub = new CoreEntity();
        $pub->setId('pub-uuid-001');

        $r = CreationResult::publicacaoAmbigua($pub);

        self::assertSame('publicacao_ambigua', $r->kind);
        self::assertSame($pub, $r->entity);
    }

    public function testDedupedFactoryRetornaKindDedupedComEntityExistente(): void
    {
        $existing = new CoreEntity();
        $existing->setId('existing-uuid');

        $r = CreationResult::deduped($existing);

        self::assertSame('deduped', $r->kind);
        self::assertSame($existing, $r->entity);
    }

    public function testReadonlyClass(): void
    {
        $reflect = new \ReflectionClass(CreationResult::class);
        self::assertTrue($reflect->isReadOnly(), 'CreationResult deve ser readonly');
    }
}
