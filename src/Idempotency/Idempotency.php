<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Idempotency;

use Throwable;
use ZiwenZhao\UnifiedPay\Contracts\IdempotencyStore;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;

final class Idempotency
{
    public function __construct(
        private IdempotencyStore $store,
        private int $ttlSeconds,
    ) {}

    /**
     * Run the callback the first time this event is seen.
     * A repeat returns null. A thrown callback releases the claim so the provider can retry.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public function once(string $channel, string $eventId, callable $callback): mixed
    {
        if ($eventId === '') {
            throw new PaymentException('Payment event id is empty.', $channel, null, 422);
        }

        if (! $this->store->claim($channel, $eventId, $this->ttlSeconds)) {
            return null;
        }

        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->store->release($channel, $eventId);

            throw $exception;
        }
    }
}
