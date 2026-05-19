<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareTpu\Stubs;

use Espo\Modules\TogareTpu\Contracts\TpuSourceAdapterContract;
use Espo\Modules\TogareTpu\Exception\TpuAdapterUnavailableException;

/**
 * Stub controlável do TpuSourceAdapter para testes do TpuSyncService.
 *
 * Aceita 3 datasets pré-construídos via construtor + flag de "lança erro" por
 * tabela (simula partial failure do AC8).
 */
final class TpuSourceAdapterStub implements TpuSourceAdapterContract
{
    /**
     * @param list<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}> $classes
     * @param list<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}> $assuntos
     * @param list<array{codigo:int, nome:string, pai_codigo:?int, glossario:?string, ativo:bool}> $movimentos
     */
    public function __construct(
        private readonly array $classes = [],
        private readonly array $assuntos = [],
        private readonly array $movimentos = [],
        private readonly bool $failClasses = false,
        private readonly bool $failAssuntos = false,
        private readonly bool $failMovimentos = false,
    ) {
    }

    public function fetchClasses(): iterable
    {
        if ($this->failClasses) {
            throw new TpuAdapterUnavailableException('stub: classes indisponível');
        }
        yield from $this->classes;
    }

    public function fetchAssuntos(): iterable
    {
        if ($this->failAssuntos) {
            throw new TpuAdapterUnavailableException('stub: assuntos indisponível');
        }
        yield from $this->assuntos;
    }

    public function fetchMovimentos(): iterable
    {
        if ($this->failMovimentos) {
            throw new TpuAdapterUnavailableException('stub: movimentos indisponível');
        }
        yield from $this->movimentos;
    }
}
