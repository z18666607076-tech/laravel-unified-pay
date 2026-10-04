<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Contracts;

interface IdempotencyStore
{
    /**
     * Reserve this event. False means a previous claim is still held.
     */
    public function claim(string $channel, string $eventId, int $ttlSeconds): bool;

    public function release(string $channel, string $eventId): void;
}
