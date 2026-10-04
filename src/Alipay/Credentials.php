<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Alipay;

use ZiwenZhao\UnifiedPay\Exceptions\ConfigurationException;
use ZiwenZhao\UnifiedPay\Support\KeyMaterial;

final class Credentials
{
    public function appId(): string
    {
        return $this->required('unified-pay.alipay.app_id', 'Alipay app id');
    }

    public function privateKey(): string
    {
        return KeyMaterial::privateKey(
            $this->required('unified-pay.alipay.private_key', 'Alipay private key'),
            'Alipay private key',
        );
    }

    public function publicKey(): string
    {
        return KeyMaterial::publicKey(
            $this->required('unified-pay.alipay.alipay_public_key', 'Alipay public key'),
            'Alipay public key',
        );
    }

    public function notifyUrl(): string
    {
        return $this->required('unified-pay.alipay.notify_url', 'Alipay notify URL');
    }

    public function returnUrl(): ?string
    {
        $value = config('unified-pay.alipay.return_url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function gateway(): string
    {
        return $this->required('unified-pay.alipay.gateway', 'Alipay gateway');
    }

    private function required(string $key, string $label): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new ConfigurationException($label.' is not configured.', 'alipay');
        }

        return $value;
    }
}
