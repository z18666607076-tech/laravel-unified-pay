<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Exceptions;

use RuntimeException;

class PaymentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $channel = null,
        public readonly ?string $providerCode = null,
        public readonly int $status = 502,
    ) {
        parent::__construct($message);
    }
}
