<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Idempotency;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use ZiwenZhao\UnifiedPay\Contracts\IdempotencyStore;

final class CacheIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private CacheManager $cache,
        private ?string $store = null,
    ) {}

    public function claim(string $channel, string $eventId, int $ttlSeconds): bool
    {
        return $this->repository()->add($this->key($channel, $eventId), '1', max(1, $ttlSeconds));
    }

    public function release(string $channel, string $eventId): void
    {
        $this->repository()->forget($this->key($channel, $eventId));
    }

    private function repository(): Repository
    {
        if ($this->store === null || $this->store === '') {
            return $this->cache->store();
        }

        return $this->cache->store($this->store);
    }

    private function key(string $channel, string $eventId): string
    {
        return 'unified-pay:'.$channel.':'.$eventId;
    }
}
