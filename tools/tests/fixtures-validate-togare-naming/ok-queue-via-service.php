<?php

/**
 * Fixture OK — consumidor legítimo do QueueService, sem INSERT direto.
 * Esperado: validator aceita (exit 0).
 */

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

class GoodConsumer
{
    public function __construct(private readonly QueueService $queue)
    {
    }

    public function doWork(string $pubId): string
    {
        return $this->queue->enqueue('djen', ['pubId' => $pubId], "djen.pub.{$pubId}");
    }
}
