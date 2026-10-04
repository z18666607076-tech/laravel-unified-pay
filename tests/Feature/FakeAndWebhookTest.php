<?php

declare(strict_types=1);

use Freeman\UnifiedPay\Contracts\ProfitSharingGateway;
use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\DTO\ProfitShareReceiver;
use Freeman\UnifiedPay\DTO\ProfitShareRequest;
use Freeman\UnifiedPay\Enums\PaymentMode;
use Freeman\UnifiedPay\Events\PaymentSucceeded;
use Freeman\UnifiedPay\Events\RefundSucceeded;
use Freeman\UnifiedPay\Exceptions\AssertionFailedException;
use Freeman\UnifiedPay\Facades\Pay;
use Freeman\UnifiedPay\Money;
use Freeman\UnifiedPay\Testing\WebhookFactory;
use Freeman\UnifiedPay\Tests\Support\PaymentConfig;
use Freeman\UnifiedPay\Tests\Support\RsaKeyPair;
use Freeman\UnifiedPay\UnifiedPayServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

it('fakes every channel and records assertions', function () {
    Pay::fake();

    $payment = Pay::driver('stripe')->create(new CreatePayment(
        outTradeNo: 'ORDER1',
        money: Money::of(900, 'USD'),
        description: 'Test',
        mode: PaymentMode::StripePaymentIntent,
    ));

    expect($payment->clientPayload['client_secret'])->toContain('secret')
        ->and(Pay::driver('alipay'))->not->toBeInstanceOf(ProfitSharingGateway::class);

    Pay::driver('wechat')->create(new CreatePayment(
        outTradeNo: 'ORDER2',
        money: Money::of(100, 'CNY'),
        description: 'Mini',
        mode: PaymentMode::WechatMiniProgram,
        payerOpenId: 'openid',
    ));
    Pay::driver('wechat')->refund(new CreateRefund('RF1', Money::of(100, 'CNY'), 'ORDER2'));
    Pay::driver('wechat')->query('ORDER2');
    Pay::driver('wechat')->close('ORDER2');

    $wechat = Pay::driver('wechat');
    expect($wechat)->toBeInstanceOf(ProfitSharingGateway::class);
    $wechat->profitShare(new ProfitShareRequest('PS1', '4200', [
        new ProfitShareReceiver('MERCHANT_ID', '1900000002', 40, 'share'),
    ]));

    Pay::assertCreated('stripe', fn (CreatePayment $created): bool => $created->outTradeNo === 'ORDER1');
    Pay::assertCreatedTimes('wechat', 1);
    Pay::assertRefunded('wechat');
    Pay::assertQueried('wechat', 'ORDER2');
    Pay::assertClosed('wechat', 'ORDER2');
    Pay::assertProfitShared('wechat', fn (ProfitShareRequest $request): bool => $request->outOrderNo === 'PS1');

    expect(fn () => Pay::assertCreated('alipay'))->toThrow(AssertionFailedException::class);
});

it('accepts an unsigned webhook only while the driver is faked', function () {
    Pay::fake();
    Pay::routes();
    Event::fake([PaymentSucceeded::class]);

    $this->postJson('/unified-pay/stripe', [
        'id' => 'evt_fake',
        'type' => 'payment.succeeded',
        'out_trade_no' => 'ORDER1',
        'amount' => 900,
        'currency' => 'USD',
        'provider_reference' => 'pi_fake_ORDER1',
    ])->assertOk()->assertJson(['received' => true]);

    $this->postJson('/unified-pay/stripe', [
        'id' => 'evt_fake',
        'type' => 'payment.succeeded',
        'out_trade_no' => 'ORDER1',
        'amount' => 900,
        'currency' => 'USD',
    ])->assertOk();

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('verifies live webhooks, dispatches one event, and acks each channel', function () {
    PaymentConfig::wechat();
    PaymentConfig::alipay();
    PaymentConfig::stripe();
    Pay::routes();
    Event::fake([PaymentSucceeded::class, RefundSucceeded::class]);

    $wechat = WebhookFactory::wechat(
        RsaKeyPair::platform()->privatePem,
        PaymentConfig::API_V3_KEY,
        PaymentConfig::PLATFORM_SERIAL,
        'evt_wx',
        'TRANSACTION.SUCCESS',
        [
            'out_trade_no' => 'ORDER1',
            'transaction_id' => '4200',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 100, 'currency' => 'CNY'],
        ],
    );

    $this->call('POST', '/unified-pay/wechat', [], [], [], $this->transformHeadersToServerVars([
        'CONTENT_TYPE' => 'application/json',
        'Wechatpay-Timestamp' => $wechat['headers']['Wechatpay-Timestamp'],
        'Wechatpay-Nonce' => $wechat['headers']['Wechatpay-Nonce'],
        'Wechatpay-Signature' => $wechat['headers']['Wechatpay-Signature'],
        'Wechatpay-Serial' => $wechat['headers']['Wechatpay-Serial'],
    ]), $wechat['body'])->assertOk()->assertJson(['code' => 'SUCCESS']);

    $this->call('POST', '/unified-pay/wechat', [], [], [], $this->transformHeadersToServerVars([
        'CONTENT_TYPE' => 'application/json',
        'Wechatpay-Timestamp' => $wechat['headers']['Wechatpay-Timestamp'],
        'Wechatpay-Nonce' => $wechat['headers']['Wechatpay-Nonce'],
        'Wechatpay-Signature' => $wechat['headers']['Wechatpay-Signature'],
        'Wechatpay-Serial' => $wechat['headers']['Wechatpay-Serial'],
    ]), $wechat['body'])->assertOk();

    $alipay = WebhookFactory::alipay(RsaKeyPair::platform()->privatePem, [
        'notify_id' => 'notify-live',
        'out_trade_no' => 'ORDER1',
        'trade_no' => 'T1',
        'trade_status' => 'TRADE_SUCCESS',
        'total_amount' => '1.00',
    ]);
    $this->call('POST', '/unified-pay/alipay', [], [], [], $this->transformHeadersToServerVars([
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ]), $alipay['body'])->assertOk()->assertSee('success');

    $stripeBody = json_encode([
        'id' => 'evt_live',
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_1',
                'amount_refunded' => 100,
                'currency' => 'usd',
                'payment_intent' => 'pi_123',
                'metadata' => ['out_trade_no' => 'ORDER1', 'out_refund_no' => 'RF1'],
            ],
        ],
    ]);
    expect($stripeBody)->toBeString();
    $stripe = WebhookFactory::stripe($stripeBody, 'whsec_test_secret');
    $this->call('POST', '/unified-pay/stripe', [], [], [], $this->transformHeadersToServerVars([
        'CONTENT_TYPE' => 'application/json',
        'Stripe-Signature' => $stripe['headers']['Stripe-Signature'],
    ]), $stripe['body'])->assertOk()->assertJson(['received' => true]);

    Event::assertDispatchedTimes(PaymentSucceeded::class, 2);
    Event::assertDispatched(RefundSucceeded::class);

    $this->postJson('/unified-pay/stripe', ['id' => 'evt_live'])->assertStatus(401);
});

it('publishes config and migration tags', function () {
    $config = ServiceProvider::pathsToPublish(UnifiedPayServiceProvider::class, 'unified-pay-config');
    $migrations = ServiceProvider::pathsToPublish(UnifiedPayServiceProvider::class, 'unified-pay-migrations');

    expect($config)->not->toBeEmpty()
        ->and($migrations)->not->toBeEmpty();
});
