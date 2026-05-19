<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

/**
 * Stub mínimo de TogareLogger para testes unit standalone do togare-tpu.
 *
 * Carregado pelo bootstrap.php apenas se a classe real não estiver
 * disponível (em runtime real do EspoCRM, o togare-core fornece a impl
 * correta — esta stub não é executada).
 *
 * Recording opcional via `getRecorded()` — usado em testes que querem
 * assert sobre eventos de log emitidos (ex.: `tpu.cache.miss.code_not_found`).
 */
final class TogareLogger
{
    /** @var list<array{level:string,event:string,message:string,context:array<string,mixed>}> */
    private static array $recorded = [];

    public static function init(string $service, mixed $container = null): void
    {
        // no-op
    }

    public static function getInstance(): self
    {
        return new self();
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function event(string $level, string $event, string $message, array $context = []): void
    {
        self::$recorded[] = [
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level:string,event:string,message:string,context:array<string,mixed>}>
     */
    public static function getRecorded(): array
    {
        return self::$recorded;
    }

    public static function reset(): void
    {
        self::$recorded = [];
    }
}
