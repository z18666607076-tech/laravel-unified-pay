<?php

declare(strict_types=1);

use ZiwenZhao\UnifiedPay\Alipay\NotificationParser;
use ZiwenZhao\UnifiedPay\Alipay\Signer;
use ZiwenZhao\UnifiedPay\Exceptions\SignatureException;
use ZiwenZhao\UnifiedPay\Testing\WebhookFactory;
use ZiwenZhao\UnifiedPay\Tests\Support\PaymentConfig;
use ZiwenZhao\UnifiedPay\Tests\Support\RsaKeyPair;

it('signs alipay requests with rsa2 and keeps sign_type in the request string', function () {
    $keys = RsaKeyPair::merchant();
    $signer = new Signer;
    $params = [
        'app_id' => '2021',
        'method' => 'alipay.trade.page.pay',
        'sign_type' => 'RSA2',
        'biz_content' => '{"out_trade_no":"ORDER1"}',
    ];
    $signature = $signer->sign($params, $keys->privatePem);
    $message = $signer->canonical($params);

    expect($message)->toContain('sign_type=RSA2')
        ->and($signer->verify($message, $signature, $keys->publicPem))->toBeTrue();
});

it('verifies an alipay notification without sign_type and reads a refund', function () {
    PaymentConfig::alipay();
    $parser = app(NotificationParser::class);
    $paid = WebhookFactory::alipay(RsaKeyPair::platform()->privatePem, [
        'notify_id' => 'notify-1',
        'out_trade_no' => 'ORDER1',
        'trade_no' => '202610040001',
        'trade_status' => 'TRADE_SUCCESS',
        'total_amount' => '20.80',
    ]);

    $event = $parser->parse($paid['body']);

    expect($event->type->value)->toBe('payment.succeeded')
        ->and($event->money?->amount)->toBe(2080)
        ->and($event->providerReference)->toBe('202610040001');

    $refund = WebhookFactory::alipay(RsaKeyPair::platform()->privatePem, [
        'notify_id' => 'notify-2',
        'out_trade_no' => 'ORDER1',
        'trade_no' => '202610040001',
        'trade_status' => 'TRADE_CLOSED',
        'gmt_refund' => '2026-10-04 12:00:00',
        'out_biz_no' => 'RF1',
        'refund_fee' => '5.00',
    ]);
    $refundEvent = $parser->parse($refund['body']);

    expect($refundEvent->type->value)->toBe('refund.succeeded')
        ->and($refundEvent->outRefundNo)->toBe('RF1')
        ->and($refundEvent->money?->amount)->toBe(500);

    expect(fn () => $parser->parse(str_replace('ORDER1', 'ORDER2', $paid['body'])))
        ->toThrow(SignatureException::class);
});

it('extracts the raw alipay response object that was signed', function () {
    $signer = new Signer;
    $node = '{"code":"10000","msg":"Success","nested":{"a":"b"}}';
    $raw = '{"alipay_trade_query_response":'.$node.',"sign":"abc"}';

    expect($signer->extractObject($raw, 'alipay_trade_query_response'))->toBe($node);
});
