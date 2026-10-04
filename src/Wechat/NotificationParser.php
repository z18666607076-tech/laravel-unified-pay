<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Wechat;

use Freeman\UnifiedPay\DTO\PaymentEvent;
use Freeman\UnifiedPay\Enums\Channel;
use Freeman\UnifiedPay\Enums\PaymentEventType;
use Freeman\UnifiedPay\Exceptions\PaymentException;
use Freeman\UnifiedPay\Exceptions\SignatureException;
use Freeman\UnifiedPay\Money;
use Freeman\UnifiedPay\Support\Headers;
use Freeman\UnifiedPay\Support\Json;
use Freeman\UnifiedPay\Support\Values;

final class NotificationParser
{
    public function __construct(
        private Signer $signer,
        private Cipher $cipher,
        private Credentials $credentials,
    ) {}

    /**
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    public function parse(string $body, array $headers, ?int $now = null): PaymentEvent
    {
        $timestamp = Headers::get($headers, 'Wechatpay-Timestamp');
        $nonce = Headers::get($headers, 'Wechatpay-Nonce');
        $signature = Headers::get($headers, 'Wechatpay-Signature');
        $serial = Headers::get($headers, 'Wechatpay-Serial');

        if ($timestamp === '' || $nonce === '' || $signature === '' || $serial === '') {
            throw new SignatureException('WeChat Pay notification is missing signature headers.', 'wechat');
        }

        if (preg_match('/^\d+$/', $timestamp) !== 1) {
            throw new SignatureException('WeChat Pay notification timestamp is invalid.', 'wechat');
        }

        $now ??= time();
        $window = Values::seconds(config('unified-pay.webhook.tolerance_seconds'), 300);

        if (abs($now - (int) $timestamp) > $window) {
            throw new SignatureException('WeChat Pay notification timestamp is outside the tolerance window.', 'wechat');
        }

        if (! hash_equals($this->credentials->platformSerial(), $serial)) {
            throw new SignatureException('WeChat Pay platform certificate serial does not match.', 'wechat');
        }

        $message = $this->signer->notificationMessage($timestamp, $nonce, $body);

        if (! $this->signer->verify($message, $signature, $this->credentials->platformPublicKey())) {
            throw new SignatureException('WeChat Pay notification signature is invalid.', 'wechat');
        }

        $envelope = Json::decodeObject($body, 'WeChat Pay notification is not JSON.');
        $eventId = Values::string($envelope['id'] ?? null);
        $eventType = Values::string($envelope['event_type'] ?? null);
        $resource = $envelope['resource'] ?? null;

        if ($eventId === null || $eventType === null || ! is_array($resource)) {
            throw new SignatureException('WeChat Pay notification is missing an event id.', 'wechat');
        }

        $algorithm = $resource['algorithm'] ?? null;
        $ciphertext = $resource['ciphertext'] ?? null;
        $resourceNonce = $resource['nonce'] ?? null;
        $associated = $resource['associated_data'] ?? '';

        if ($algorithm !== 'AEAD_AES_256_GCM' || ! is_string($ciphertext) || ! is_string($resourceNonce) || ! is_string($associated)) {
            throw new SignatureException('WeChat Pay notification resource is incomplete.', 'wechat');
        }

        $plain = $this->cipher->decrypt($this->credentials->apiV3Key(), $resourceNonce, $associated, $ciphertext);
        $decoded = Json::decodeObject($plain, 'WeChat Pay notification resource is not JSON.');

        return $this->event($eventId, $eventType, $decoded);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function event(string $eventId, string $eventType, array $resource): PaymentEvent
    {
        $outTradeNo = Values::string($resource['out_trade_no'] ?? null);
        $channel = Channel::Wechat->value;

        if ($eventType === 'TRANSACTION.SUCCESS') {
            $state = Values::string($resource['trade_state'] ?? null);

            if ($state !== 'SUCCESS') {
                return new PaymentEvent($channel, $eventId, PaymentEventType::Ignored, $outTradeNo, rawStatus: $state, payload: $resource);
            }

            return new PaymentEvent(
                channel: $channel,
                id: $eventId,
                type: PaymentEventType::PaymentSucceeded,
                outTradeNo: $outTradeNo,
                providerReference: Values::string($resource['transaction_id'] ?? null),
                money: $this->money($resource, 'total'),
                rawStatus: $state,
                payload: $resource,
            );
        }

        if ($eventType === 'REFUND.SUCCESS' || $eventType === 'REFUND.ABNORMAL' || $eventType === 'REFUND.CLOSED') {
            $status = Values::string($resource['refund_status'] ?? null);
            $type = match (true) {
                $eventType === 'REFUND.SUCCESS' && $status === 'SUCCESS' => PaymentEventType::RefundSucceeded,
                $eventType === 'REFUND.ABNORMAL', $eventType === 'REFUND.CLOSED' => PaymentEventType::RefundFailed,
                default => PaymentEventType::Ignored,
            };

            if ($type === PaymentEventType::Ignored) {
                return new PaymentEvent($channel, $eventId, $type, $outTradeNo, rawStatus: $status, payload: $resource);
            }

            return new PaymentEvent(
                channel: $channel,
                id: $eventId,
                type: $type,
                outTradeNo: $outTradeNo,
                outRefundNo: Values::string($resource['out_refund_no'] ?? null),
                providerReference: Values::string($resource['refund_id'] ?? null),
                money: $this->money($resource, 'refund'),
                rawStatus: $status,
                payload: $resource,
            );
        }

        return new PaymentEvent($channel, $eventId, PaymentEventType::Ignored, $outTradeNo, payload: $resource);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function money(array $resource, string $field): Money
    {
        $amount = $resource['amount'] ?? null;

        if (! is_array($amount)) {
            throw new PaymentException('Payment notification is missing an amount.', 'wechat', null, 422);
        }

        $currency = Values::string($amount['currency'] ?? null);

        if ($currency === null) {
            throw new PaymentException('Payment notification is missing a currency.', 'wechat', null, 422);
        }

        return Money::of(Values::int($amount[$field] ?? null, 'WeChat Pay notification', 'wechat'), $currency);
    }
}
