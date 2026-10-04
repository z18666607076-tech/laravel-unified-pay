<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\DTO;

use Freeman\UnifiedPay\Enums\RefundStatus;
use Freeman\UnifiedPay\Money;

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
