<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Closed = 'closed';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
