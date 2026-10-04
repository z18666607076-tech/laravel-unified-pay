<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Enums;

enum PaymentEventType: string
{
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentClosed = 'payment.closed';
    case PaymentFailed = 'payment.failed';
    case RefundSucceeded = 'refund.succeeded';
    case RefundFailed = 'refund.failed';
    case Ignored = 'ignored';
}
