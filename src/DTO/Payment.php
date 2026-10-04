<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

use ZiwenZhao\UnifiedPay\Enums\PaymentStatus;
use ZiwenZhao\UnifiedPay\Money;

final readonly class Payment
{
    /**
     * @param  array<string, string>  $clientPayload
     */
    public function __construct(
        public string $channel,
        public string $outTradeNo,
        public ?Money $money,
        public PaymentStatus $status,
        public ?string $providerReference = null,
        public array $clientPayload = [],
        public ?string $rawStatus = null,
    ) {}
}
