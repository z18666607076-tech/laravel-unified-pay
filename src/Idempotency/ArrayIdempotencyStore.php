<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Idempotency;

use ZiwenZhao\UnifiedPay\Contracts\IdempotencyStore;

/**
 * Process-local store. Use it in tests. Do not use it across PHP processes.
 */
final class ArrayIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, true> */
    private array $keys = [];

    public function claim(string $channel, string $eventId, int $ttlSeconds): bool
    {
        $key = $this->key($channel, $eventId);

        if (isset($this->keys[$key])) {
            return false;
        }

        $this->keys[$key] = true;

        return true;
    }

    public function release(string $channel, string $eventId): void
    {
        unset($this->keys[$this->key($channel, $eventId)]);
    }

    private function key(string $channel, string $eventId): string
    {
        return $channel."\0".$eventId;
    }
}
