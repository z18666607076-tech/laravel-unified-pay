<?php

declare(strict_types=1);

use ZiwenZhao\UnifiedPay\Exceptions\SignatureException;
use ZiwenZhao\UnifiedPay\Stripe\NotificationParser;
use ZiwenZhao\UnifiedPay\Stripe\WebhookVerifier;
use ZiwenZhao\UnifiedPay\Testing\WebhookFactory;

it('verifies a stripe webhook signature and rejects a bad one', function () {
    $secret = 'whsec_test_secret';
    $payload = '{"id":"evt_1","type":"payment_intent.succeeded"}';
    $fixture = WebhookFactory::stripe($payload, $secret);
    $verifier = new WebhookVerifier;

    expect($verifier->parse($payload, $fixture['headers']['Stripe-Signature'], $secret)['id'])->toBe('evt_1');

    expect(fn () => $verifier->parse($payload, 't='.time().',v1=deadbeef', $secret))
        ->toThrow(SignatureException::class);

    $stale = WebhookFactory::stripe($payload, $secret, time() - 301);

    expect(fn () => $verifier->parse($payload, $stale['headers']['Stripe-Signature'], $secret))
        ->toThrow(SignatureException::class, 'tolerance');
});

it('accepts one valid v1 signature among several', function () {
    $secret = 'whsec_test_secret';
    $payload = '{"id":"evt_2","type":"charge.refunded"}';
    $timestamp = time();
    $good = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    $header = 't='.$timestamp.',v1=deadbeef,v1='.$good;

    expect(app(WebhookVerifier::class)->parse($payload, $header, $secret)['id'])->toBe('evt_2');
});

it('normalizes stripe payment and refund events', function () {
    $parser = new NotificationParser;
    $paid = $parser->parse([
        'id' => 'evt_paid',
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'id' => 'pi_123',
                'object' => 'payment_intent',
                'amount' => 2500,
                'currency' => 'usd',
                'status' => 'succeeded',
                'metadata' => ['out_trade_no' => 'ORDER9'],
            ],
        ],
    ]);

    expect($paid->type->value)->toBe('payment.succeeded')
        ->and($paid->outTradeNo)->toBe('ORDER9')
        ->and($paid->money?->amount)->toBe(2500)
        ->and($paid->money?->currency)->toBe('USD');

    $refund = $parser->parse([
        'id' => 'evt_refund',
        'type' => 'refund.updated',
        'data' => [
            'object' => [
                'id' => 're_123',
                'object' => 'refund',
                'amount' => 700,
                'currency' => 'usd',
                'status' => 'succeeded',
                'payment_intent' => 'pi_123',
                'metadata' => ['out_trade_no' => 'ORDER9', 'out_refund_no' => 'RF9'],
            ],
        ],
    ]);

    expect($refund->type->value)->toBe('refund.succeeded')
        ->and($refund->outRefundNo)->toBe('RF9')
        ->and($refund->providerReference)->toBe('re_123');

    $failed = $parser->parse([
        'id' => 'evt_failed',
        'type' => 'payment_intent.payment_failed',
        'data' => [
            'object' => [
                'id' => 'pi_123',
                'amount' => 2500,
                'currency' => 'usd',
                'status' => 'requires_payment_method',
                'metadata' => ['out_trade_no' => 'ORDER9'],
            ],
        ],
    ]);

    expect($failed->type->value)->toBe('payment.failed');
});
