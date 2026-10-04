<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

use ZiwenZhao\UnifiedPay\Enums\PaymentMode;
use ZiwenZhao\UnifiedPay\Money;

final readonly class CreatePayment
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $outTradeNo,
        public Money $money,
        public string $description,
        public PaymentMode $mode,
        public ?string $notifyUrl = null,
        public ?string $returnUrl = null,
        public ?string $payerOpenId = null,
        public ?string $clientIp = null,
        public ?string $h5Type = null,
        public bool $profitSharing = false,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
    ) {}
}
