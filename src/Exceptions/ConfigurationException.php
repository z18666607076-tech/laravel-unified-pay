<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Exceptions;

class ConfigurationException extends PaymentException
{
    public function __construct(string $message, ?string $channel = null)
    {
        parent::__construct($message, $channel, null, 501);
    }
}
