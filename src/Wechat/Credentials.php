<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Wechat;

use ZiwenZhao\UnifiedPay\Exceptions\ConfigurationException;
use ZiwenZhao\UnifiedPay\Support\KeyMaterial;

final class Credentials
{
    public function appId(): string
    {
        return $this->required('unified-pay.wechat.app_id', 'WeChat Pay app id');
    }

    public function miniAppId(): string
    {
        $mini = config('unified-pay.wechat.mini_app_id');

        if (is_string($mini) && $mini !== '') {
            return $mini;
        }

        return $this->appId();
    }

    public function mchId(): string
    {
        return $this->required('unified-pay.wechat.mch_id', 'WeChat Pay merchant id');
    }

    public function merchantSerial(): string
    {
        return $this->required('unified-pay.wechat.mch_serial', 'WeChat Pay merchant certificate serial');
    }

    public function privateKey(): string
    {
        return KeyMaterial::privateKey(
            $this->required('unified-pay.wechat.private_key', 'WeChat Pay private key'),
            'WeChat Pay private key',
        );
    }

    public function apiV3Key(): string
    {
        $key = $this->required('unified-pay.wechat.api_v3_key', 'WeChat Pay APIv3 key');

        if (strlen($key) !== 32) {
            throw new ConfigurationException('WeChat Pay APIv3 key must be 32 bytes.', 'wechat');
        }

        return $key;
    }

    public function platformPublicKey(): string
    {
        return KeyMaterial::publicKey(
            $this->required('unified-pay.wechat.platform_public_key', 'WeChat Pay platform public key'),
            'WeChat Pay platform public key',
        );
    }

    public function platformSerial(): string
    {
        return $this->required('unified-pay.wechat.platform_serial', 'WeChat Pay platform serial');
    }

    public function notifyUrl(): string
    {
        return $this->required('unified-pay.wechat.notify_url', 'WeChat Pay notify URL');
    }

    public function baseUrl(): string
    {
        return rtrim($this->required('unified-pay.wechat.base_url', 'WeChat Pay base URL'), '/');
    }

    private function required(string $key, string $label): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw new ConfigurationException($label.' is not configured.', 'wechat');
        }

        return $value;
    }
}
