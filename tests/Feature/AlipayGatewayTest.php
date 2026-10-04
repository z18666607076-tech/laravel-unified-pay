<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZiwenZhao\UnifiedPay\Alipay\Signer;
use ZiwenZhao\UnifiedPay\DTO\CreatePayment;
use ZiwenZhao\UnifiedPay\DTO\CreateRefund;
use ZiwenZhao\UnifiedPay\Enums\PaymentMode;
use ZiwenZhao\UnifiedPay\Enums\PaymentStatus;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;
use ZiwenZhao\UnifiedPay\Exceptions\SignatureException;
use ZiwenZhao\UnifiedPay\Facades\Pay;
use ZiwenZhao\UnifiedPay\Money;
use ZiwenZhao\UnifiedPay\Tests\Support\PaymentConfig;
use ZiwenZhao\UnifiedPay\Tests\Support\RsaKeyPair;

function alipayPayment(PaymentMode $mode): CreatePayment
{
    return new CreatePayment(
        outTradeNo: 'ORDER1',
        money: Money::of(2080, 'CNY'),
        description: 'Desk lamp',
        mode: $mode,
    );
}

beforeEach(function () {
    PaymentConfig::alipay();
});

it('builds a signed page, wap, and app checkout', function (PaymentMode $mode, string $method, string $product) {
    $payment = Pay::driver('alipay')->create(alipayPayment($mode));
    $source = $payment->clientPayload['url'] ?? $payment->clientPayload['order_string'];
    $query = parse_url($source, PHP_URL_QUERY) ?: $source;
    parse_str((string) $query, $params);
    expect($params['method'])->toBe($method)
        ->and($params['sign_type'])->toBe('RSA2');

    $sign = $params['sign'];
    unset($params['sign']);
    $signer = new Signer;

    expect($signer->verify($signer->canonical($params), $sign, RsaKeyPair::merchant()->publicPem))->toBeTrue();
    $biz = json_decode($params['biz_content'], true);
    expect($biz['product_code'])->toBe($product)
        ->and($biz['total_amount'])->toBe('20.80');
})->with([
    [PaymentMode::AlipayPage, 'alipay.trade.page.pay', 'FAST_INSTANT_TRADE_PAY'],
    [PaymentMode::AlipayWap, 'alipay.trade.wap.pay', 'QUICK_WAP_WAY'],
    [PaymentMode::AlipayApp, 'alipay.trade.app.pay', 'QUICK_MSECURITY_PAY'],
]);

it('queries and refunds against a signed alipay response', function () {
    $queryNode = '{"code":"10000","msg":"Success","out_trade_no":"ORDER1","total_amount":"20.80","trade_no":"2026001","trade_status":"TRADE_SUCCESS"}';
    $refundNode = '{"code":"10000","msg":"Success","out_trade_no":"ORDER1","trade_no":"2026001","refund_fee":"5.00"}';

    Http::fake(function ($request) use ($queryNode, $refundNode) {
        $method = $request->data()['method'] ?? '';
        $body = $method === 'alipay.trade.refund'
            ? PaymentConfig::alipaySignedBody($refundNode, 'alipay_trade_refund_response')
            : PaymentConfig::alipaySignedBody($queryNode, 'alipay_trade_query_response');

        return Http::response($body, 200, ['Content-Type' => 'application/json']);
    });

    $payment = Pay::driver('alipay')->query('ORDER1');

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->providerReference)->toBe('2026001')
        ->and($payment->money?->amount)->toBe(2080);

    $refund = Pay::driver('alipay')->refund(new CreateRefund(
        outRefundNo: 'RF1',
        money: Money::of(500, 'CNY'),
        outTradeNo: 'ORDER1',
    ));

    expect($refund->status->value)->toBe('succeeded')
        ->and($refund->money->amount)->toBe(500);
});

it('rejects an alipay response signed with the wrong key', function () {
    $node = '{"code":"10000","msg":"Success","out_trade_no":"ORDER1","total_amount":"1.00","trade_status":"WAIT_BUYER_PAY"}';
    $signer = new Signer;
    $sign = $signer->signMessage($node, RsaKeyPair::merchant()->privatePem);
    Http::fake([
        '*' => Http::response('{"alipay_trade_query_response":'.$node.',"sign":"'.$sign.'"}'),
    ]);

    expect(fn () => Pay::driver('alipay')->query('ORDER1'))->toThrow(SignatureException::class);
});

it('queries a refund by merchant numbers', function () {
    $node = '{"code":"10000","msg":"Success","out_trade_no":"ORDER1","trade_no":"2026001","refund_amount":"5.00","refund_status":"REFUND_SUCCESS"}';
    Http::fake(fn () => Http::response(PaymentConfig::alipaySignedBody($node, 'alipay_trade_fastpay_refund_query_response')));

    $refund = Pay::driver('alipay')->queryRefund('RF1', 'ORDER1');

    expect($refund->status->value)->toBe('succeeded')
        ->and($refund->money->amount)->toBe(500)
        ->and(fn () => Pay::driver('alipay')->queryRefund('RF1'))->toThrow(PaymentException::class);
});

it('closes a trade', function () {
    $node = '{"code":"10000","msg":"Success","out_trade_no":"ORDER1"}';
    Http::fake([
        '*' => Http::response(PaymentConfig::alipaySignedBody($node, 'alipay_trade_close_response')),
    ]);

    expect(Pay::driver('alipay')->close('ORDER1')->status)->toBe(PaymentStatus::Closed);
});
