<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

final readonly class ProfitShareResult
{
    public function __construct(
        public string $channel,
        public string $outOrderNo,
        public ?string $orderId,
        public string $state,
        public ?string $transactionId = null,
    ) {}
}
