<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

use ZiwenZhao\UnifiedPay\Money;

final readonly class CreateRefund
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $outRefundNo,
        public Money $money,
        public ?string $outTradeNo = null,
        public ?string $providerReference = null,
        public ?string $reason = null,
        public ?string $notifyUrl = null,
        public ?int $originalAmount = null,
        public array $metadata = [],
    ) {}
}
