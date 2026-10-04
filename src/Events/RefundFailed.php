<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Events;

use Freeman\UnifiedPay\DTO\PaymentEvent;

final readonly class RefundFailed
{
    public function __construct(public PaymentEvent $event) {}
}
