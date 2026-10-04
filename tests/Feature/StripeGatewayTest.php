<?php

declare(strict_types=1);

use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\Enums\PaymentMode;
use Freeman\UnifiedPay\Enums\PaymentStatus;
use Freeman\UnifiedPay\Facades\Pay;
use Freeman\UnifiedPay\Money;
use Freeman\UnifiedPay\Testing\WebhookFactory;
use Freeman\UnifiedPay\Tests\Support\PaymentConfig;
use Illuminate\Support\Facades\Http;

function stripeIntent(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'pi_123',
        'object' => 'payment_intent',
        'amount' => 2500,
        'currency' => 'usd',
        'status' => 'requires_payment_method',
        'client_secret' => 'pi_123_secret_test',
        'metadata' => ['out_trade_no' => 'ORDER1'],
    ], $overrides);
}

beforeEach(function () {
    PaymentConfig::stripe();
});

it('creates a payment intent in minor units', function () {
    Http::fake([
        'https://api.stripe.com/v1/payment_intents' => function ($request) {
            expect($request->header('Idempotency-Key'))->not->toBeNull()
                ->and($request->data()['amount'])->toBe(2500)
                ->and($request->data()['currency'])->toBe('usd')
                ->and($request->data()['metadata']['out_trade_no'])->toBe('ORDER1');

            return Http::response(stripeIntent());
        },
    ]);

    $payment = Pay::driver('stripe')->create(new CreatePayment(
        outTradeNo: 'ORDER1',
        money: Money::of(2500, 'USD'),
        description: 'Lamp',
        mode: PaymentMode::StripePaymentIntent,
    ));

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->providerReference)->toBe('pi_123')
        ->and($payment->clientPayload['client_secret'])->toBe('pi_123_secret_test');
});

it('queries by payment intent id, cancels, and refunds', function () {
    Http::fake([
        'https://api.stripe.com/v1/payment_intents/pi_123' => Http::response(stripeIntent([
            'status' => 'succeeded',
        ])),
        'https://api.stripe.com/v1/payment_intents/pi_123/cancel' => Http::response(stripeIntent([
            'status' => 'canceled',
        ])),
        'https://api.stripe.com/v1/refunds' => Http::response([
            'id' => 're_123',
            'object' => 'refund',
            'amount' => 500,
            'currency' => 'usd',
            'status' => 'succeeded',
            'payment_intent' => 'pi_123',
            'metadata' => ['out_refund_no' => 'RF1', 'out_trade_no' => 'ORDER1'],
        ]),
        'https://api.stripe.com/v1/refunds/re_123' => Http::response([
            'id' => 're_123',
            'amount' => 500,
            'currency' => 'usd',
            'status' => 'succeeded',
            'metadata' => ['out_refund_no' => 'RF1'],
        ]),
    ]);

    expect(Pay::driver('stripe')->query('pi_123')->status)->toBe(PaymentStatus::Succeeded);
    expect(Pay::driver('stripe')->close('pi_123')->status)->toBe(PaymentStatus::Closed);

    $refund = Pay::driver('stripe')->refund(new CreateRefund(
        outRefundNo: 'RF1',
        money: Money::of(500, 'USD'),
        outTradeNo: 'ORDER1',
        providerReference: 'pi_123',
        reason: 'requested_by_customer',
    ));

    expect($refund->providerReference)->toBe('re_123')
        ->and(Pay::driver('stripe')->queryRefund('re_123')->status->value)->toBe('succeeded');
});

it('finds a payment intent by the merchant order number', function () {
    Http::fake([
        'https://api.stripe.com/v1/payment_intents/search*' => Http::response([
            'data' => [
                stripeIntent(['status' => 'succeeded']),
            ],
        ]),
    ]);

    expect(Pay::driver('stripe')->query('ORDER1')->providerReference)->toBe('pi_123');
});

it('parses a signed stripe webhook through the driver', function () {
    $payload = json_encode([
        'id' => 'evt_123',
        'type' => 'payment_intent.canceled',
        'data' => [
            'object' => [
                'id' => 'pi_123',
                'amount' => 2500,
                'currency' => 'usd',
                'status' => 'canceled',
                'metadata' => ['out_trade_no' => 'ORDER1'],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
    expect($payload)->toBeString();
    $fixture = WebhookFactory::stripe($payload, 'whsec_test_secret');
    $event = Pay::driver('stripe')->parseNotification($fixture['body'], $fixture['headers']);

    expect($event->type->value)->toBe('payment.closed')
        ->and($event->outTradeNo)->toBe('ORDER1');
});
