<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\DTO;

use Freeman\UnifiedPay\Enums\PaymentStatus;
use Freeman\UnifiedPay\Money;

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
