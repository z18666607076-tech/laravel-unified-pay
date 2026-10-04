<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Enums;

enum RefundStatus: string
{
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
