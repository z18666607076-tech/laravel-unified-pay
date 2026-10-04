# laravel-unified-pay

One Laravel-native interface for WeChat Pay (API v3), Alipay (Open Platform, RSA2), and Stripe. Signed webhooks and idempotent event handling are part of the package, not something each app reinvents.

[![Tests](https://github.com/z18666607076-tech/laravel-unified-pay/actions/workflows/tests.yml/badge.svg)](https://github.com/z18666607076-tech/laravel-unified-pay/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

The Packagist name in this repository, `freeman/laravel-unified-pay`, is a placeholder. The badge below starts working after you publish under the vendor you own. See [Publish on Packagist](#publish-on-packagist).

[![Packagist Version](https://img.shields.io/packagist/v/freeman/laravel-unified-pay.svg)](https://packagist.org/packages/freeman/laravel-unified-pay)

## What this is

`Pay::driver('wechat'|'alipay'|'stripe')` creates a payment, queries it, closes it, refunds it, and turns a notification into one `PaymentEvent`. Amounts are integers in minor units (`Money::of(2080, 'CNY')` is ¥20.80). WeChat profit-sharing is an optional interface, so Alipay and Stripe code does not pretend to support it.

The WeChat and Stripe pieces generalize the hand-written clients in [flashmall-api](https://github.com/z18666607076-tech/flashmall-api): API v3 RSA request signatures, AES-256-GCM notification decrypt, platform signature checks, Stripe webhook HMAC, refunds, and idempotent webhooks. Alipay RSA2 is implemented here. This package does not depend on FlashMall, and it does not wrap another payment SDK. The signing code is in this repository and covered by tests that generate keys at runtime.

This was built against the public protocol documents (WeChat Pay API v3, Alipay Open Platform, Stripe API). It has not been exercised against a live merchant account or a live Stripe secret. Treat a successful test run as proof of the cryptography and request shapes, then confirm the first real charge in each provider's sandbox before production.

## Requirements

| | Versions |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 11, 12, 13 (`illuminate/*` ^11.44 \|\| ^12.4 \|\| ^13.0) |
| Extensions | openssl, mbstring, json |

CI runs Orchestra Testbench on that matrix. Pest 5 requires PHP 8.4 and Laravel 13, so the PHP 8.3 cells and the Laravel 11/12 cells use Pest 3 or 4. Laravel 11 on PHP 8.5 is not in the matrix: 11 is security-only and is not a combination this package claims.

## Install

```bash
composer require freeman/laravel-unified-pay
```

Replace `freeman` with your Packagist vendor after you publish. Laravel discovers the service provider and the `Pay` alias. If another package already aliases `Pay` (for example yansongda/laravel-pay), import the facade yourself:

```php
use Freeman\UnifiedPay\Facades\Pay;
```

Publish the config, and publish the migration only if you want the database idempotency store:

```bash
php artisan vendor:publish --tag=unified-pay-config
php artisan vendor:publish --tag=unified-pay-migrations
php artisan migrate
```

## Configuration

Secrets stay in the environment. The published `config/unified-pay.php` reads them; do not commit key files.

```dotenv
WECHAT_PAY_APP_ID=
WECHAT_PAY_MINI_APP_ID=
WECHAT_PAY_MCH_ID=
WECHAT_PAY_MCH_SERIAL=
WECHAT_PAY_PRIVATE_KEY=        # PEM or an absolute path
WECHAT_PAY_API_V3_KEY=         # 32 bytes
WECHAT_PAY_PLATFORM_PUBLIC_KEY=
WECHAT_PAY_PLATFORM_SERIAL=
WECHAT_PAY_NOTIFY_URL="${APP_URL}/unified-pay/wechat"

ALIPAY_APP_ID=
ALIPAY_PRIVATE_KEY=
ALIPAY_PUBLIC_KEY=
ALIPAY_NOTIFY_URL="${APP_URL}/unified-pay/alipay"
ALIPAY_RETURN_URL="${APP_URL}/paid"
# ALIPAY_GATEWAY=https://openapi-sandbox.dl.alipaydev.com/gateway.do

STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

`private_key` / public key values accept a PEM string (real newlines or `\n` escapes), a file path, or the base64 body Alipay's console downloads without PEM headers.

Register the webhook routes in `routes/api.php` so they sit on the `api` middleware group and skip CSRF:

```php
use Freeman\UnifiedPay\Facades\Pay;

Pay::routes();
```

That mounts:

| Method | Path | Acknowledgement |
| --- | --- | --- |
| POST | `/unified-pay/wechat` | `{"code":"SUCCESS","message":"OK"}` |
| POST | `/unified-pay/alipay` | `success` |
| POST | `/unified-pay/stripe` | `{"received":true}` |

Failed signature checks return an error and do not dispatch an event. Set `UNIFIED_PAY_REGISTER_ROUTES=true` if you want the service provider to register the same routes.

## Quick start

```php
use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\Enums\PaymentMode;
use Freeman\UnifiedPay\Events\PaymentSucceeded;
use Freeman\UnifiedPay\Facades\Pay;
use Freeman\UnifiedPay\Money;

$payment = Pay::driver('wechat')->create(new CreatePayment(
    outTradeNo: 'ORDER1001',
    money: Money::of(2080, 'CNY'),
    description: 'Desk lamp',
    mode: PaymentMode::WechatMiniProgram,
    payerOpenId: $user->wechat_openid,
));

// $payment->clientPayload is the wx.requestPayment / mini program parameter set.
```

### WeChat Pay

Domestic API v3, CNY, amounts in fen.

| Mode | Endpoint the driver calls | You must pass |
| --- | --- | --- |
| `PaymentMode::WechatJsapi` | `POST /v3/pay/transactions/jsapi` | `payerOpenId` |
| `PaymentMode::WechatMiniProgram` | same JSAPI endpoint, `mini_app_id` | `payerOpenId` |
| `PaymentMode::WechatNative` | `POST /v3/pay/transactions/native` | — (`code_url` comes back) |
| `PaymentMode::WechatH5` | `POST /v3/pay/transactions/h5` | `clientIp`, optional `h5Type` (`Wap`, `iOS`, `Android`) |

`create()` returns `clientPayload` (`appId`, `timeStamp`, `nonceStr`, `package`, `signType`, `paySign` for JSAPI). Query, close, refund, and query refund use the merchant order number. A partial refund must set `originalAmount` to the original order total in fen; a full refund can omit it.

```php
Pay::driver('wechat')->query('ORDER1001');
Pay::driver('wechat')->close('ORDER1001');

Pay::driver('wechat')->refund(new CreateRefund(
    outRefundNo: 'RF1001',
    money: Money::of(500, 'CNY'),
    outTradeNo: 'ORDER1001',
    originalAmount: 2080,
));

Pay::driver('wechat')->queryRefund('RF1001');
```

Every API v3 response is checked with the platform public key (`Wechatpay-Timestamp`, `Wechatpay-Nonce`, `Wechatpay-Signature`, `Wechatpay-Serial`) before it is trusted.

### Alipay

Open Platform RSA2. CNY on the wire is a yuan string; you still pass fen.

| Mode | Method | Product code |
| --- | --- | --- |
| `PaymentMode::AlipayPage` | `alipay.trade.page.pay` | `FAST_INSTANT_TRADE_PAY` |
| `PaymentMode::AlipayWap` | `alipay.trade.wap.pay` | `QUICK_WAP_WAY` |
| `PaymentMode::AlipayApp` | `alipay.trade.app.pay` | `QUICK_MSECURITY_PAY` |

Page and WAP return `clientPayload['url']` (GET redirect, GMT+8 `timestamp`). App pay returns `clientPayload['order_string']`. Query, close, refund, and refund query are signed form posts. The driver verifies the signature over the raw JSON object in the response, not over a re-encoded array. Refund query needs both the refund number and the original `out_trade_no`.

```php
$url = Pay::driver('alipay')->create(new CreatePayment(
    outTradeNo: 'ORDER1001',
    money: Money::of(2080, 'CNY'),
    description: 'Desk lamp',
    mode: PaymentMode::AlipayPage,
))->clientPayload['url'];

Pay::driver('alipay')->queryRefund('RF1001', 'ORDER1001');
```

A notification with `gmt_refund` is a refund. `TRADE_SUCCESS` / `TRADE_FINISHED` is a payment. `TRADE_CLOSED` without a refund timestamp is a close.

### Stripe

PaymentIntents. The integer amount is sent unchanged (Stripe minor units, including zero-decimal currencies such as JPY). `metadata.out_trade_no` is how later searches find the intent. Cancel is `close()`. A refund needs the `pi_...` id on `providerReference`, or an `outTradeNo` that can be searched.

```php
$intent = Pay::driver('stripe')->create(new CreatePayment(
    outTradeNo: 'ORDER1001',
    money: Money::of(2500, 'USD'),
    description: 'Desk lamp',
    mode: PaymentMode::StripePaymentIntent,
));

$intent->clientPayload['client_secret'];
Pay::driver('stripe')->query('pi_123');          // or the out_trade_no, via Search
Pay::driver('stripe')->refund(new CreateRefund(
    outRefundNo: 'RF1001',
    money: Money::of(500, 'USD'),
    providerReference: 'pi_123',
    reason: 'requested_by_customer', // duplicate | fraudulent | requested_by_customer
));
```

Search by `out_trade_no` uses `GET /v1/payment_intents/search`, which is eventually consistent. Prefer the `pi_` id when you have it. Refund lookup by merchant number lists refunds for that payment intent and matches `metadata.out_refund_no`; lookup by `re_...` is direct.

### Profit-sharing (WeChat only)

Turn it on when creating the payment (`profitSharing: true` sets `settle_info.profit_sharing`). The WeChat driver implements `ProfitSharingGateway`. The others do not, including under `Pay::fake()`.

```php
use Freeman\UnifiedPay\Contracts\ProfitSharingGateway;
use Freeman\UnifiedPay\DTO\ProfitShareReceiver;
use Freeman\UnifiedPay\DTO\ProfitShareRequest;

$driver = Pay::driver('wechat');

if ($driver instanceof ProfitSharingGateway) {
    $driver->addProfitShareReceiver('MERCHANT_ID', '1900000109', 'SUPPLIER', 'Shop name');

    $driver->profitShare(new ProfitShareRequest(
        outOrderNo: 'PS1001',
        transactionId: '4200000001',
        receivers: [
            new ProfitShareReceiver('MERCHANT_ID', '1900000109', 500, 'Supplier share', 'Shop name'),
        ],
        unfreezeUnsplit: true,
    ));
}
```

Receiver names are encrypted with RSAES-OAEP and the platform public key, and `Wechatpay-Serial` is sent on those calls.

## Webhooks and events

The controller verifies the body, claims the provider event id, then dispatches:

| Provider signal | Laravel event |
| --- | --- |
| WeChat `TRANSACTION.SUCCESS`, Alipay `TRADE_SUCCESS` / `TRADE_FINISHED`, Stripe `payment_intent.succeeded` | `PaymentSucceeded` |
| Alipay `TRADE_CLOSED`, Stripe `payment_intent.canceled` | `PaymentClosed` |
| Stripe `payment_intent.payment_failed` | `PaymentFailed` |
| WeChat `REFUND.SUCCESS`, Alipay `gmt_refund`, Stripe `refund.updated` (succeeded) or `charge.refunded` | `RefundSucceeded` |
| WeChat `REFUND.ABNORMAL` / `REFUND.CLOSED`, Stripe `refund.failed` | `RefundFailed` |

Anything else becomes `PaymentEventType::Ignored` and does not dispatch. Each event carries a `PaymentEvent` (`id`, `outTradeNo`, `outRefundNo`, `providerReference`, `money`, `rawStatus`).

```php
use Freeman\UnifiedPay\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

Event::listen(PaymentSucceeded::class, function (PaymentSucceeded $event): void {
    // Mark the order paid. Compare $event->event->money with your order total first.
});
```

A second delivery of the same event id is acknowledged and not dispatched again. If your listener throws, the claim is released and the provider gets a failure response so it can retry.

### Idempotency stores

| `UNIFIED_PAY_IDEMPOTENCY_STORE` | Behaviour |
| --- | --- |
| `cache` (default) | Atomic cache add. Expires after `UNIFIED_PAY_IDEMPOTENCY_TTL` seconds (default 7 days). |
| `database` | Unique `(channel, event_id)` in `unified_pay_events`. Kept until you delete it. Use this in production. |
| `array` | In-memory. Tests only. |

```php
Pay::idempotency()->once('wechat', $event->id, function () use ($event): void {
    // runs once
});
```

The webhook controller already does this. Call `once()` yourself when you verify a notification outside `Pay::routes()`.

## Testing with fakes

```php
use Freeman\UnifiedPay\Facades\Pay;

Pay::fake(); // or Pay::fake(['wechat'])

Pay::driver('wechat')->create($payment);

Pay::assertCreated('wechat', fn ($payment) => $payment->outTradeNo === 'ORDER1001');
Pay::assertCreatedTimes('stripe', 0);
Pay::assertRefunded('wechat');
Pay::assertQueried('stripe', 'ORDER1001');
Pay::assertClosed('wechat', 'ORDER1001');
Pay::assertProfitShared('wechat');
Pay::assertNothingCreated();
```

While `Pay::fake()` is active, webhook routes accept a small JSON document and skip signature checks. That flag exists only in the test process. Production code that never calls `Pay::fake()` still verifies signatures.

```php
Pay::fake();
Pay::routes();

$this->postJson('/unified-pay/stripe', [
    'id' => 'evt_test',
    'type' => 'payment.succeeded',
    'out_trade_no' => 'ORDER1001',
    'amount' => 2500,
    'currency' => 'USD',
    'provider_reference' => 'pi_test',
]);
```

To hit the real verifiers from an application test, build a fixture with `Freeman\UnifiedPay\Testing\WebhookFactory`. Pass keys in; the factory does not read them from disk.

## Errors

| Exception | When |
| --- | --- |
| `ConfigurationException` | A required key, PEM, or 32-byte API v3 key is missing. |
| `SignatureException` | A signature, serial, timestamp, or ciphertext check failed. |
| `PaymentException` | The provider rejected the call, or the request is invalid (wrong currency, missing openid, unknown mode). `providerCode` and `status` are set when the provider sent them. |
| `AssertionFailedException` | A `Pay::assert*` check failed. |

WeChat and Alipay drivers reject anything other than CNY. Stripe forwards the ISO code you put on `Money`.

## Security notes

- Verify notifications only through `parseNotification()` or `Pay::routes()`. Do not json-decode a webhook and trust it.
- Compare the event amount and currency with the order before marking it paid. This package normalizes the amount; it does not know your order table.
- Keep the API v3 key, merchant private key, Alipay private key, and Stripe secret out of git. Prefer file paths outside the web root, or your host's secret store.
- Pin `platform_serial` / the platform public key and rotate them together when WeChat rotates certificates. A mismatched serial is rejected.
- Webhook tolerance defaults to 300 seconds (`UNIFIED_PAY_WEBHOOK_TOLERANCE`).
- Use the database idempotency store in production. Cache entries expire, and a late retry could dispatch twice.
- The package does not log request bodies. Do not log decrypted notification resources; they can contain payer identifiers.
- HTTPS only. The `api` middleware group is intentional: browser CSRF tokens are not available to these providers.

## Development

```bash
composer install
composer check
```

`composer check` runs Pint, Larastan at level 9, and Pest. See [CONTRIBUTING.md](CONTRIBUTING.md). The test suite generates RSA keys in process. No sandbox credentials are required.

## Publish on Packagist

The owner of the GitHub repository does this once. It is not done by the 0.1 pull request.

1. Pick a Packagist vendor you control. `freeman` in `composer.json` is a suggestion, not a registered name.
2. Before the first tag, set `"name": "your-vendor/laravel-unified-pay"` in `composer.json`. If you also change the PHP vendor, rename the `Freeman\` namespace and the `Pay` alias, then retarget the tests. Doing that after `v0.1.0` is a breaking change.
3. Push the repository to GitHub.
4. Create a Packagist account and submit the repository URL.
5. Enable the Packagist GitHub service hook so new tags update the package automatically.
6. Tag the release. Packagist versions come from git tags, not from `composer.json`:

   ```bash
   git tag v0.1.0
   git push origin v0.1.0
   ```

7. Confirm the package page, then in an application:

   ```bash
   composer require your-vendor/laravel-unified-pay
   ```

## License

MIT. See [LICENSE](LICENSE).
