<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Facades;

use Freeman\UnifiedPay\Contracts\Gateway;
use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\DTO\ProfitShareRequest;
use Freeman\UnifiedPay\Enums\Channel;
use Freeman\UnifiedPay\Idempotency\Idempotency;
use Freeman\UnifiedPay\PayManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Gateway driver(string|Channel $channel)
 * @method static void fake(string|array<int, string>|null $channels = null)
 * @method static Idempotency idempotency()
 * @method static void routes(array{prefix?: string, middleware?: string|array<int, string>, name?: string} $options = [])
 * @method static void assertCreated(string $channel, (callable(CreatePayment): bool)|null $callback = null)
 * @method static void assertCreatedTimes(string $channel, int $times)
 * @method static void assertNothingCreated()
 * @method static void assertRefunded(string $channel, (callable(CreateRefund): bool)|null $callback = null)
 * @method static void assertQueried(string $channel, string|null $outTradeNo = null)
 * @method static void assertClosed(string $channel, string|null $outTradeNo = null)
 * @method static void assertProfitShared(string $channel, (callable(ProfitShareRequest): bool)|null $callback = null)
 *
 * @see PayManager
 */
class Pay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PayManager::class;
    }
}
