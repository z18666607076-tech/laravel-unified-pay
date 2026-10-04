<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Testing;

use ZiwenZhao\UnifiedPay\Alipay\Signer as AlipaySigner;
use ZiwenZhao\UnifiedPay\Support\Json;
use ZiwenZhao\UnifiedPay\Wechat\Cipher;
use ZiwenZhao\UnifiedPay\Wechat\Signer as WechatSigner;

/**
 * Build signed webhook fixtures in tests. Keys are arguments, never read from disk by this class.
 */
final class WebhookFactory
{
    /**
     * @param  array<string, mixed>  $resource
     * @return array{body: string, headers: array<string, string>}
     */
    public static function wechat(
        string $platformPrivateKey,
        string $apiV3Key,
        string $platformSerial,
        string $eventId,
        string $eventType,
        array $resource,
        string $associatedData = 'transaction',
        ?int $timestamp = null,
    ): array {
        $cipher = new Cipher;
        $signer = new WechatSigner;
        $plain = Json::encode($resource);
        $resourceNonce = 'resource-nonce';
        $body = Json::encode([
            'id' => $eventId,
            'create_time' => '2026-10-04T12:00:00+08:00',
            'resource_type' => 'encrypt-resource',
            'event_type' => $eventType,
            'summary' => 'test',
            'resource' => [
                'algorithm' => 'AEAD_AES_256_GCM',
                'ciphertext' => $cipher->encrypt($apiV3Key, $resourceNonce, $associatedData, $plain),
                'associated_data' => $associatedData,
                'nonce' => $resourceNonce,
            ],
        ]);
        $stamp = (string) ($timestamp ?? time());
        $nonce = 'header-nonce';

        return [
            'body' => $body,
            'headers' => [
                'Wechatpay-Timestamp' => $stamp,
                'Wechatpay-Nonce' => $nonce,
                'Wechatpay-Signature' => $signer->sign(
                    $signer->notificationMessage($stamp, $nonce, $body),
                    $platformPrivateKey,
                ),
                'Wechatpay-Serial' => $platformSerial,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $fields
     * @return array{body: string, headers: array<string, string>}
     */
    public static function alipay(string $privateKey, array $fields): array
    {
        $signer = new AlipaySigner;
        unset($fields['sign'], $fields['sign_type']);
        $fields['sign_type'] = 'RSA2';
        $unsigned = $fields;
        unset($unsigned['sign_type']);
        $fields['sign'] = $signer->signMessage($signer->canonical($unsigned, true), $privateKey);

        return [
            'body' => http_build_query($fields),
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ];
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    public static function stripe(string $payload, string $secret, ?int $timestamp = null): array
    {
        $stamp = $timestamp ?? time();
        $signature = hash_hmac('sha256', $stamp.'.'.$payload, $secret);

        return [
            'body' => $payload,
            'headers' => [
                'Stripe-Signature' => 't='.$stamp.',v1='.$signature,
                'Content-Type' => 'application/json',
            ],
        ];
    }
}
