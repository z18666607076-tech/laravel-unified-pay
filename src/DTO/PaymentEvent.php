<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

use ZiwenZhao\UnifiedPay\Enums\PaymentEventType;
use ZiwenZhao\UnifiedPay\Money;

/**
 * A provider notification after the signature (and, for WeChat, the ciphertext)
 * has been checked. This is the normalized shape every channel produces.
 */
final readonly class PaymentEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $channel,
        public string $id,
        public PaymentEventType $type,
        public ?string $outTradeNo = null,
        public ?string $outRefundNo = null,
        public ?string $providerReference = null,
        public ?Money $money = null,
        public ?string $rawStatus = null,
        public array $payload = [],
    ) {}
}
