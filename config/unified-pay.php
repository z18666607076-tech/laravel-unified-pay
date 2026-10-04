<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Timeout for outbound calls to WeChat Pay, Alipay, and Stripe. Secrets
    | stay in the environment; nothing in this file should contain a key.
    |
    */

    'http' => [
        'timeout' => (int) env('UNIFIED_PAY_HTTP_TIMEOUT', 10),
        'user_agent' => env('UNIFIED_PAY_USER_AGENT', 'ziwen-zhao-laravel-unified-pay/0.1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Pay::routes() registers one POST endpoint per channel. Set register to
    | true to mount those routes from the service provider instead.
    |
    */

    'webhook' => [
        'register' => (bool) env('UNIFIED_PAY_REGISTER_ROUTES', false),
        'prefix' => env('UNIFIED_PAY_WEBHOOK_PREFIX', 'unified-pay'),
        'middleware' => ['api'],
        'name' => 'unified-pay',
        'tolerance_seconds' => (int) env('UNIFIED_PAY_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | cache: atomic add with a TTL (fine for a single server, expires).
    | database: unique (channel, event_id), kept until you delete it.
    | array: in-memory, for Pay::fake() and the test suite only.
    |
    | Prefer database in production. Stripe retries for up to three days.
    |
    */

    'idempotency' => [
        'store' => env('UNIFIED_PAY_IDEMPOTENCY_STORE', 'cache'),
        'cache_store' => env('UNIFIED_PAY_IDEMPOTENCY_CACHE_STORE'),
        'ttl' => (int) env('UNIFIED_PAY_IDEMPOTENCY_TTL', 60 * 60 * 24 * 7),
        'table' => 'unified_pay_events',
    ],

    /*
    |--------------------------------------------------------------------------
    | WeChat Pay (API v3)
    |--------------------------------------------------------------------------
    |
    | private_key and platform_public_key accept a PEM string or an absolute
    | path. Domestic transactions are CNY, in fen (minor units).
    |
    */

    'wechat' => [
        'app_id' => env('WECHAT_PAY_APP_ID'),
        'mini_app_id' => env('WECHAT_PAY_MINI_APP_ID'),
        'mch_id' => env('WECHAT_PAY_MCH_ID'),
        'mch_serial' => env('WECHAT_PAY_MCH_SERIAL'),
        'private_key' => env('WECHAT_PAY_PRIVATE_KEY'),
        'api_v3_key' => env('WECHAT_PAY_API_V3_KEY'),
        'platform_public_key' => env('WECHAT_PAY_PLATFORM_PUBLIC_KEY'),
        'platform_serial' => env('WECHAT_PAY_PLATFORM_SERIAL'),
        'notify_url' => env('WECHAT_PAY_NOTIFY_URL'),
        'base_url' => env('WECHAT_PAY_BASE_URL', 'https://api.mch.weixin.qq.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alipay (open platform, RSA2)
    |--------------------------------------------------------------------------
    |
    | Amounts on the wire are yuan strings. This package converts integer fen.
    | Sandbox gateway: https://openapi-sandbox.dl.alipaydev.com/gateway.do
    |
    */

    'alipay' => [
        'app_id' => env('ALIPAY_APP_ID'),
        'private_key' => env('ALIPAY_PRIVATE_KEY'),
        'alipay_public_key' => env('ALIPAY_PUBLIC_KEY'),
        'notify_url' => env('ALIPAY_NOTIFY_URL'),
        'return_url' => env('ALIPAY_RETURN_URL'),
        'gateway' => env('ALIPAY_GATEWAY', 'https://openapi.alipay.com/gateway.do'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Amounts are integer minor units and are forwarded unchanged. Payment
    | intents are created with metadata.out_trade_no so later lookups work.
    |
    */

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com'),
        'api_version' => env('STRIPE_API_VERSION'),
    ],

];
