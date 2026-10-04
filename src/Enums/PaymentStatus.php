<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Closed = 'closed';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
