<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

/**
 * Estados válidos de um item em togare_queue_items (ver V004).
 *
 * Transições canônicas:
 *   pending → processing → done
 *   pending → processing → failed_retry → (após next_retry_at) pending
 *   pending → processing → failed_dead_letter (max retries ou permanente)
 *   processing → pending (via reclaimStuck, quando worker morre)
 */
final class QueueItemStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const DONE = 'done';
    public const FAILED_RETRY = 'failed_retry';
    public const FAILED_DEAD_LETTER = 'failed_dead_letter';

    /** @return list<string> */
    public static function allValues(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::DONE,
            self::FAILED_RETRY,
            self::FAILED_DEAD_LETTER,
        ];
    }
}
