<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Tests\Support;

use Freeman\UnifiedPay\Alipay\Signer as AlipaySigner;
use Freeman\UnifiedPay\Wechat\Signer as WechatSigner;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

final class PaymentConfig
{
    public const API_V3_KEY = '0123456789abcdef0123456789abcdef';

    public const PLATFORM_SERIAL = 'PLATFORM_SERIAL';

    public const MERCHANT_SERIAL = 'MERCHANT_SERIAL';

    public static function wechat(?RsaKeyPair $merchant = null, ?RsaKeyPair $platform = null): RsaKeyPair
    {
        $merchant ??= RsaKeyPair::merchant();
        $platform ??= RsaKeyPair::platform();

        config([
            'unified-pay.wechat.app_id' => 'wx-app',
            'unified-pay.wechat.mini_app_id' => 'wx-mini',
            'unified-pay.wechat.mch_id' => '1900000001',
            'unified-pay.wechat.mch_serial' => self::MERCHANT_SERIAL,
            'unified-pay.wechat.private_key' => $merchant->privatePem,
            'unified-pay.wechat.api_v3_key' => self::API_V3_KEY,
            'unified-pay.wechat.platform_public_key' => $platform->publicPem,
            'unified-pay.wechat.platform_serial' => self::PLATFORM_SERIAL,
            'unified-pay.wechat.notify_url' => 'https://example.test/unified-pay/wechat',
            'unified-pay.wechat.base_url' => 'https://api.mch.weixin.qq.com',
        ]);

        return $platform;
    }

    public static function alipay(?RsaKeyPair $merchant = null, ?RsaKeyPair $platform = null): void
    {
        $merchant ??= RsaKeyPair::merchant();
        $platform ??= RsaKeyPair::platform();

        config([
            'unified-pay.alipay.app_id' => '2021000000000001',
            'unified-pay.alipay.private_key' => $merchant->privatePem,
            'unified-pay.alipay.alipay_public_key' => $platform->publicPem,
            'unified-pay.alipay.notify_url' => 'https://example.test/unified-pay/alipay',
            'unified-pay.alipay.return_url' => 'https://example.test/paid',
            'unified-pay.alipay.gateway' => 'https://openapi.alipay.com/gateway.do',
        ]);
    }

    public static function stripe(): void
    {
        config([
            'unified-pay.stripe.secret' => 'sk_test_unified_pay',
            'unified-pay.stripe.webhook_secret' => 'whsec_test_secret',
            'unified-pay.stripe.base_url' => 'https://api.stripe.com',
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function signWechatResponse(string $body, array $headers = [], int $status = 200): PromiseInterface
    {
        $signer = new WechatSigner;
        $timestamp = (string) time();
        $nonce = 'resp-nonce';
        $signature = $signer->sign(
            $signer->notificationMessage($timestamp, $nonce, $body),
            RsaKeyPair::platform()->privatePem,
        );

        return Http::response($body, $status, array_merge([
            'Wechatpay-Timestamp' => $timestamp,
            'Wechatpay-Nonce' => $nonce,
            'Wechatpay-Signature' => $signature,
            'Wechatpay-Serial' => self::PLATFORM_SERIAL,
            'Content-Type' => 'application/json',
        ], $headers));
    }

    public static function alipaySignedBody(string $node, string $nodeName): string
    {
        $signer = new AlipaySigner;
        $sign = $signer->signMessage($node, RsaKeyPair::platform()->privatePem);

        return '{"'.$nodeName.'":'.$node.',"sign":"'.$sign.'"}';
    }
}
