<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareLicensing;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Clock controlável pra testes determinísticos.
 */
final class TestClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
