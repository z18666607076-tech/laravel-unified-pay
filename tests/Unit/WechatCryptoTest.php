<?php

declare(strict_types=1);

use Freeman\UnifiedPay\Exceptions\ConfigurationException;
use Freeman\UnifiedPay\Exceptions\SignatureException;
use Freeman\UnifiedPay\Testing\WebhookFactory;
use Freeman\UnifiedPay\Tests\Support\PaymentConfig;
use Freeman\UnifiedPay\Tests\Support\RsaKeyPair;
use Freeman\UnifiedPay\Wechat\Cipher;
use Freeman\UnifiedPay\Wechat\NotificationParser;
use Freeman\UnifiedPay\Wechat\Signer;

it('signs wechat requests and mini program params with rsa', function () {
    $keys = RsaKeyPair::merchant();
    $signer = new Signer;
    $message = $signer->requestMessage('POST', '/v3/pay/transactions/jsapi', '1700000000', 'nonce', '{"amount":1}');
    $signature = $signer->sign($message, $keys->privatePem);

    expect($signer->verify($message, $signature, $keys->publicPem))->toBeTrue()
        ->and($signer->verify($message."tampered\n", $signature, $keys->publicPem))->toBeFalse();

    $client = $signer->clientMessage('wx123', '1700000000', 'nonce', 'prepay_id=wx999');
    $paySign = $signer->sign($client, $keys->privatePem);

    expect($signer->verify($client, $paySign, $keys->publicPem))->toBeTrue();
});

it('round trips a wechat notification with aes-gcm', function () {
    $cipher = new Cipher;
    $plain = '{"trade_state":"SUCCESS"}';
    $encoded = $cipher->encrypt(PaymentConfig::API_V3_KEY, 'nonce-value', 'transaction', $plain);

    expect($cipher->decrypt(PaymentConfig::API_V3_KEY, 'nonce-value', 'transaction', $encoded))->toBe($plain);
});

it('rejects a short api v3 key and a bad tag', function () {
    $cipher = new Cipher;

    expect(fn () => $cipher->encrypt('short', 'nonce', 'transaction', '{}'))
        ->toThrow(ConfigurationException::class);

    expect(fn () => $cipher->decrypt(PaymentConfig::API_V3_KEY, 'nonce', 'transaction', base64_encode('not-ciphertext')))
        ->toThrow(SignatureException::class);
});

it('normalizes a signed and encrypted wechat payment notification', function () {
    PaymentConfig::wechat();
    $fixture = WebhookFactory::wechat(
        RsaKeyPair::platform()->privatePem,
        PaymentConfig::API_V3_KEY,
        PaymentConfig::PLATFORM_SERIAL,
        'evt_wx_1',
        'TRANSACTION.SUCCESS',
        [
            'out_trade_no' => 'ORDER1',
            'transaction_id' => '4200000001',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 2080, 'currency' => 'CNY'],
        ],
    );

    $event = app(NotificationParser::class)->parse($fixture['body'], $fixture['headers']);

    expect($event->type->value)->toBe('payment.succeeded')
        ->and($event->outTradeNo)->toBe('ORDER1')
        ->and($event->providerReference)->toBe('4200000001')
        ->and($event->money?->amount)->toBe(2080)
        ->and($event->money?->currency)->toBe('CNY');
});

it('normalizes a wechat refund notification and rejects a bad signature', function () {
    PaymentConfig::wechat();
    $parser = app(NotificationParser::class);
    $fixture = WebhookFactory::wechat(
        RsaKeyPair::platform()->privatePem,
        PaymentConfig::API_V3_KEY,
        PaymentConfig::PLATFORM_SERIAL,
        'evt_wx_refund',
        'REFUND.SUCCESS',
        [
            'out_trade_no' => 'ORDER1',
            'out_refund_no' => 'RF1',
            'refund_id' => '5000000001',
            'refund_status' => 'SUCCESS',
            'amount' => ['refund' => 500, 'currency' => 'CNY'],
        ],
        'refund',
    );

    $event = $parser->parse($fixture['body'], $fixture['headers']);

    expect($event->type->value)->toBe('refund.succeeded')
        ->and($event->outRefundNo)->toBe('RF1')
        ->and($event->money?->amount)->toBe(500);

    $headers = $fixture['headers'];
    $headers['Wechatpay-Signature'] = base64_encode('not-a-signature');

    expect(fn () => $parser->parse($fixture['body'], $headers))->toThrow(SignatureException::class);
});

it('rejects a wechat notification outside the tolerance window or with the wrong serial', function () {
    PaymentConfig::wechat();
    $parser = app(NotificationParser::class);
    $stale = WebhookFactory::wechat(
        RsaKeyPair::platform()->privatePem,
        PaymentConfig::API_V3_KEY,
        PaymentConfig::PLATFORM_SERIAL,
        'evt_old',
        'TRANSACTION.SUCCESS',
        [
            'out_trade_no' => 'ORDER1',
            'transaction_id' => '4200000001',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 1, 'currency' => 'CNY'],
        ],
        'transaction',
        time() - 301,
    );

    expect(fn () => $parser->parse($stale['body'], $stale['headers']))
        ->toThrow(SignatureException::class, 'tolerance');

    $wrongSerial = $stale['headers'];
    $fresh = WebhookFactory::wechat(
        RsaKeyPair::platform()->privatePem,
        PaymentConfig::API_V3_KEY,
        PaymentConfig::PLATFORM_SERIAL,
        'evt_serial',
        'TRANSACTION.SUCCESS',
        [
            'out_trade_no' => 'ORDER1',
            'transaction_id' => '4200000001',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 1, 'currency' => 'CNY'],
        ],
    );
    $headers = $fresh['headers'];
    $headers['Wechatpay-Serial'] = 'OTHER';

    expect(fn () => $parser->parse($fresh['body'], $headers))
        ->toThrow(SignatureException::class, 'serial');
});
