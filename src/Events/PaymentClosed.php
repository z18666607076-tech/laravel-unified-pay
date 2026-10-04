<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Events;

use ZiwenZhao\UnifiedPay\DTO\PaymentEvent;

final readonly class PaymentClosed
{
    public function __construct(public PaymentEvent $event) {}
}
