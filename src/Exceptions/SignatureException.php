<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Exceptions;

class SignatureException extends PaymentException
{
    public function __construct(string $message, ?string $channel = null)
    {
        parent::__construct($message, $channel, null, 401);
    }
}
