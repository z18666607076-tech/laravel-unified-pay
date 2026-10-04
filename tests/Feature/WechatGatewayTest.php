<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZiwenZhao\UnifiedPay\Contracts\ProfitSharingGateway;
use ZiwenZhao\UnifiedPay\DTO\CreatePayment;
use ZiwenZhao\UnifiedPay\DTO\CreateRefund;
use ZiwenZhao\UnifiedPay\DTO\ProfitShareReceiver;
use ZiwenZhao\UnifiedPay\DTO\ProfitShareRequest;
use ZiwenZhao\UnifiedPay\Enums\PaymentMode;
use ZiwenZhao\UnifiedPay\Enums\PaymentStatus;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;
use ZiwenZhao\UnifiedPay\Exceptions\SignatureException;
use ZiwenZhao\UnifiedPay\Facades\Pay;
use ZiwenZhao\UnifiedPay\Money;
use ZiwenZhao\UnifiedPay\Tests\Support\PaymentConfig;
use ZiwenZhao\UnifiedPay\Tests\Support\RsaKeyPair;
use ZiwenZhao\UnifiedPay\Wechat\Signer;

function wechatPayment(PaymentMode $mode, array $overrides = []): CreatePayment
{
    return new CreatePayment(
        outTradeNo: $overrides['outTradeNo'] ?? 'ORDER1',
        money: Money::of(2080, 'CNY'),
        description: 'Flash light',
        mode: $mode,
        payerOpenId: $overrides['openid'] ?? 'openid-1',
        clientIp: $overrides['ip'] ?? '203.0.113.8',
        h5Type: $overrides['h5'] ?? 'Wap',
        profitSharing: $overrides['profitSharing'] ?? false,
    );
}

/**
 * @param  array<string, mixed>|null  $json
 */
function fakeWechat(string $method, string $path, ?array $json, int $status = 200): void
{
    Http::fake(function ($request) use ($method, $path, $json, $status) {
        expect($request->method())->toBe($method);
        $url = parse_url($request->url());
        $signedPath = ($url['path'] ?? '').(isset($url['query']) ? '?'.$url['query'] : '');
        expect($signedPath)->toBe($path);

        $authorization = $request->header('Authorization');
        $header = is_array($authorization) ? ($authorization[0] ?? '') : (string) $authorization;
        expect($header)->toStartWith('WECHATPAY2-SHA256-RSA2048');
        preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER);
        $fields = [];

        foreach ($matches as $match) {
            $fields[$match[1]] = $match[2];
        }

        $signer = new Signer;
        $message = $signer->requestMessage($method, $signedPath, $fields['timestamp'], $fields['nonce_str'], $request->body());

        expect($signer->verify($message, $fields['signature'], RsaKeyPair::merchant()->publicPem))->toBeTrue()
            ->and($fields['mchid'])->toBe('1900000001')
            ->and($fields['serial_no'])->toBe(PaymentConfig::MERCHANT_SERIAL);

        $body = $json === null ? '' : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        expect($body)->toBeString();

        return PaymentConfig::signWechatResponse($body, [], $status);
    });
}

beforeEach(function () {
    PaymentConfig::wechat();
});

it('creates a jsapi payment and signs the mini program parameters', function () {
    fakeWechat('POST', '/v3/pay/transactions/jsapi', ['prepay_id' => 'wx_prepay_1']);

    $payment = Pay::driver('wechat')->create(wechatPayment(PaymentMode::WechatJsapi));

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->providerReference)->toBe('wx_prepay_1')
        ->and($payment->clientPayload['package'])->toBe('prepay_id=wx_prepay_1')
        ->and($payment->clientPayload['signType'])->toBe('RSA');

    $signer = new Signer;
    $message = $signer->clientMessage(
        $payment->clientPayload['appId'],
        $payment->clientPayload['timeStamp'],
        $payment->clientPayload['nonceStr'],
        $payment->clientPayload['package'],
    );

    expect($signer->verify($message, $payment->clientPayload['paySign'], RsaKeyPair::merchant()->publicPem))->toBeTrue();
});

it('creates a native payment', function () {
    fakeWechat('POST', '/v3/pay/transactions/native', ['code_url' => 'weixin://wxpay/bizpayurl?pr=abc']);
    $native = Pay::driver('wechat')->create(wechatPayment(PaymentMode::WechatNative));

    expect($native->clientPayload['code_url'])->toContain('pr=abc');
});

it('creates an h5 payment', function () {
    fakeWechat('POST', '/v3/pay/transactions/h5', ['h5_url' => 'https://wx.tenpay.com/check']);
    $h5 = Pay::driver('wechat')->create(wechatPayment(PaymentMode::WechatH5));

    expect($h5->clientPayload['h5_url'])->toBe('https://wx.tenpay.com/check');
});

it('uses the mini program app id', function () {
    Http::fake(function ($request) {
        $decoded = json_decode($request->body(), true);
        expect($decoded['appid'])->toBe('wx-mini')
            ->and($decoded['settle_info']['profit_sharing'])->toBeTrue();

        return PaymentConfig::signWechatResponse('{"prepay_id":"wx_mini_prepay"}');
    });

    $payment = Pay::driver('wechat')->create(wechatPayment(PaymentMode::WechatMiniProgram, [
        'profitSharing' => true,
    ]));

    expect($payment->clientPayload['appId'])->toBe('wx-mini');
});

it('queries a paid transaction', function () {
    fakeWechat('GET', '/v3/pay/transactions/out-trade-no/ORDER1?mchid=1900000001', [
        'out_trade_no' => 'ORDER1',
        'transaction_id' => '4200001',
        'trade_state' => 'SUCCESS',
        'amount' => ['total' => 2080, 'currency' => 'CNY'],
    ]);

    $queried = Pay::driver('wechat')->query('ORDER1');

    expect($queried->status)->toBe(PaymentStatus::Succeeded)
        ->and($queried->providerReference)->toBe('4200001');
});

it('closes a transaction', function () {
    fakeWechat('POST', '/v3/pay/transactions/out-trade-no/ORDER1/close', null, 204);

    expect(Pay::driver('wechat')->close('ORDER1')->status)->toBe(PaymentStatus::Closed);
});

it('refunds a transaction and keeps the original total', function () {
    Http::fake(function ($request) {
        $decoded = json_decode($request->body(), true);
        expect($decoded['amount']['refund'])->toBe(1000)
            ->and($decoded['amount']['total'])->toBe(2080);

        return PaymentConfig::signWechatResponse(json_encode([
            'out_refund_no' => 'RF1',
            'out_trade_no' => 'ORDER1',
            'refund_id' => '5001',
            'status' => 'PROCESSING',
            'amount' => ['refund' => 1000, 'total' => 2080, 'currency' => 'CNY'],
        ], JSON_UNESCAPED_SLASHES));
    });

    $refund = Pay::driver('wechat')->refund(new CreateRefund(
        outRefundNo: 'RF1',
        money: Money::of(1000, 'CNY'),
        outTradeNo: 'ORDER1',
        originalAmount: 2080,
    ));

    expect($refund->status->value)->toBe('processing')
        ->and($refund->money->amount)->toBe(1000);
});

it('queries a refund', function () {
    fakeWechat('GET', '/v3/refund/domestic/refunds/RF1', [
        'out_refund_no' => 'RF1',
        'out_trade_no' => 'ORDER1',
        'refund_id' => '5001',
        'status' => 'SUCCESS',
        'amount' => ['refund' => 1000, 'currency' => 'CNY'],
    ]);

    expect(Pay::driver('wechat')->queryRefund('RF1')->status->value)->toBe('succeeded');
});

it('rejects a response whose platform signature does not match', function () {
    Http::fake(fn () => PaymentConfig::signWechatResponse('{"prepay_id":"wx"}', [
        'Wechatpay-Signature' => base64_encode('nope'),
    ]));

    expect(fn () => Pay::driver('wechat')->create(wechatPayment(PaymentMode::WechatJsapi)))
        ->toThrow(SignatureException::class);
});

it('encrypts a profit-sharing receiver name and requests a split', function () {
    Http::fake(function ($request) {
        $decoded = json_decode($request->body(), true);
        expect($request->header('Wechatpay-Serial'))->not->toBeNull();
        $name = $decoded['receivers'][0]['name'];
        $raw = base64_decode($name, true);
        expect($raw)->toBeString();
        $plain = '';
        expect(openssl_private_decrypt($raw, $plain, RsaKeyPair::platform()->privatePem, OPENSSL_PKCS1_OAEP_PADDING))->toBeTrue()
            ->and($plain)->toBe('Ada');

        return PaymentConfig::signWechatResponse(json_encode([
            'out_order_no' => 'PS1',
            'order_id' => '3001',
            'state' => 'PROCESSING',
            'transaction_id' => '4200001',
        ], JSON_UNESCAPED_SLASHES));
    });

    $gateway = Pay::driver('wechat');
    expect($gateway)->toBeInstanceOf(ProfitSharingGateway::class);
    $result = $gateway->profitShare(new ProfitShareRequest(
        outOrderNo: 'PS1',
        transactionId: '4200001',
        receivers: [
            new ProfitShareReceiver('MERCHANT_ID', '1900000002', 500, 'Supplier share', 'Ada'),
        ],
    ));

    expect($result->state)->toBe('PROCESSING')
        ->and($result->orderId)->toBe('3001');
});

it('refuses a non-cny wechat charge', function () {
    expect(fn () => Pay::driver('wechat')->create(new CreatePayment(
        outTradeNo: 'ORDER1',
        money: Money::of(100, 'USD'),
        description: 'Nope',
        mode: PaymentMode::WechatNative,
    )))->toThrow(PaymentException::class, 'CNY');
});
