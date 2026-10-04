<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Wechat;

use ZiwenZhao\UnifiedPay\Exceptions\ConfigurationException;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;

/**
 * WeChat Pay API v3 request, client, and notification signatures.
 * Message layouts match the API v3 rules: each field is a line, including the trailing newline.
 */
final class Signer
{
    public function sign(string $message, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new ConfigurationException('WeChat Pay private key is invalid.', 'wechat');
        }

        $signature = '';

        if (! openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PaymentException('Could not sign the WeChat Pay request.', 'wechat');
        }

        return base64_encode($signature);
    }

    public function verify(string $message, string $signatureBase64, string $publicKeyPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        $raw = base64_decode($signatureBase64, true);

        if ($key === false || $raw === false) {
            return false;
        }

        return openssl_verify($message, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    public function requestMessage(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$body."\n";
    }

    public function clientMessage(string $appId, string $timestamp, string $nonce, string $package): string
    {
        return $appId."\n".$timestamp."\n".$nonce."\n".$package."\n";
    }

    public function notificationMessage(string $timestamp, string $nonce, string $body): string
    {
        return $timestamp."\n".$nonce."\n".$body."\n";
    }

    public function encryptOaep(string $plaintext, string $publicKeyPem): string
    {
        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            throw new ConfigurationException('WeChat Pay platform public key is invalid.', 'wechat');
        }

        $encrypted = '';

        if (! openssl_public_encrypt($plaintext, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new PaymentException('Could not encrypt the profit-sharing receiver name.', 'wechat');
        }

        return base64_encode($encrypted);
    }
}
