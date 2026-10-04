<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

use ZiwenZhao\UnifiedPay\Enums\RefundStatus;
use ZiwenZhao\UnifiedPay\Money;

final readonly class Refund
{
    public function __construct(
        public string $channel,
        public string $outRefundNo,
        public Money $money,
        public RefundStatus $status,
        public ?string $providerReference = null,
        public ?string $outTradeNo = null,
        public ?string $rawStatus = null,
    ) {}
}
