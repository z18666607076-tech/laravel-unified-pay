<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Stripe;

use ZiwenZhao\UnifiedPay\Exceptions\ConfigurationException;

final class Credentials
{
    public function secret(): string
    {
        return $this->required('unified-pay.stripe.secret', 'Stripe secret key');
    }

    public function webhookSecret(): string
    {
        return $this->required('unified-pay.stripe.webhook_secret', 'Stripe webhook secret');
    }

    public function baseUrl(): string
    {
        return rtrim($this->required('unified-pay.stripe.base_url', 'Stripe base URL'), '/');
    }

    public function apiVersion(): ?string
    {
        $value = config('unified-pay.stripe.api_version');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function required(string $key, string $label): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new ConfigurationException($label.' is not configured.', 'stripe');
        }

        return $value;
    }
}
