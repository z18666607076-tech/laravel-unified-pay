<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Facades;

use Illuminate\Support\Facades\Facade;
use ZiwenZhao\UnifiedPay\Contracts\Gateway;
use ZiwenZhao\UnifiedPay\DTO\CreatePayment;
use ZiwenZhao\UnifiedPay\DTO\CreateRefund;
use ZiwenZhao\UnifiedPay\DTO\ProfitShareRequest;
use ZiwenZhao\UnifiedPay\Enums\Channel;
use ZiwenZhao\UnifiedPay\Idempotency\Idempotency;
use ZiwenZhao\UnifiedPay\PayManager;

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
