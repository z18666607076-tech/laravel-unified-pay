<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Stripe;

use ZiwenZhao\UnifiedPay\DTO\PaymentEvent;
use ZiwenZhao\UnifiedPay\Enums\Channel;
use ZiwenZhao\UnifiedPay\Enums\PaymentEventType;
use ZiwenZhao\UnifiedPay\Exceptions\SignatureException;
use ZiwenZhao\UnifiedPay\Money;
use ZiwenZhao\UnifiedPay\Support\Values;

final class NotificationParser
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function parse(array $event): PaymentEvent
    {
        $eventId = Values::string($event['id'] ?? null);
        $type = Values::string($event['type'] ?? null);
        $data = $event['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if ($eventId === null || $type === null || ! is_array($object)) {
            throw new SignatureException('Stripe event is missing an id or object.', 'stripe');
        }

        $object = Values::assoc($object);
        $channel = Channel::Stripe->value;
        $outTradeNo = $this->metadata($object, 'out_trade_no');
        $outRefundNo = $this->metadata($object, 'out_refund_no');

        if ($type === 'payment_intent.succeeded') {
            return $this->paymentEvent($channel, $eventId, PaymentEventType::PaymentSucceeded, $object, $outTradeNo, 'amount');
        }

        if ($type === 'payment_intent.canceled') {
            return $this->paymentEvent($channel, $eventId, PaymentEventType::PaymentClosed, $object, $outTradeNo, 'amount');
        }

        if ($type === 'payment_intent.payment_failed') {
            return $this->paymentEvent($channel, $eventId, PaymentEventType::PaymentFailed, $object, $outTradeNo, 'amount');
        }

        if ($type === 'refund.updated') {
            $status = Values::string($object['status'] ?? null);
            $mapped = $status === 'succeeded'
                ? PaymentEventType::RefundSucceeded
                : ($status === 'failed' ? PaymentEventType::RefundFailed : PaymentEventType::Ignored);

            if ($mapped === PaymentEventType::Ignored) {
                return new PaymentEvent($channel, $eventId, $mapped, $outTradeNo, $outRefundNo, rawStatus: $status, payload: $object);
            }

            return $this->refundEvent($channel, $eventId, $mapped, $object, $outTradeNo, $outRefundNo, 'amount', $status);
        }

        if ($type === 'refund.failed') {
            return $this->refundEvent($channel, $eventId, PaymentEventType::RefundFailed, $object, $outTradeNo, $outRefundNo, 'amount', 'failed');
        }

        if ($type === 'charge.refunded') {
            return $this->refundEvent(
                $channel,
                $eventId,
                PaymentEventType::RefundSucceeded,
                $object,
                $outTradeNo,
                $outRefundNo,
                'amount_refunded',
                'succeeded',
            );
        }

        return new PaymentEvent($channel, $eventId, PaymentEventType::Ignored, $outTradeNo, payload: $object);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function paymentEvent(
        string $channel,
        string $eventId,
        PaymentEventType $type,
        array $object,
        ?string $outTradeNo,
        string $amountField,
    ): PaymentEvent {
        return new PaymentEvent(
            channel: $channel,
            id: $eventId,
            type: $type,
            outTradeNo: $outTradeNo,
            providerReference: Values::string($object['id'] ?? null),
            money: $this->money($object, $amountField),
            rawStatus: Values::string($object['status'] ?? null),
            payload: $object,
        );
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function refundEvent(
        string $channel,
        string $eventId,
        PaymentEventType $type,
        array $object,
        ?string $outTradeNo,
        ?string $outRefundNo,
        string $amountField,
        ?string $rawStatus,
    ): PaymentEvent {
        $reference = Values::string($object['id'] ?? null);
        $paymentIntent = Values::string($object['payment_intent'] ?? null);

        return new PaymentEvent(
            channel: $channel,
            id: $eventId,
            type: $type,
            outTradeNo: $outTradeNo,
            outRefundNo: $outRefundNo,
            providerReference: $type === PaymentEventType::RefundSucceeded || $type === PaymentEventType::RefundFailed
                ? ($reference ?? $paymentIntent)
                : $paymentIntent,
            money: $this->money($object, $amountField),
            rawStatus: $rawStatus,
            payload: $object,
        );
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function metadata(array $object, string $key): ?string
    {
        $metadata = $object['metadata'] ?? null;

        if (! is_array($metadata)) {
            return null;
        }

        return Values::string($metadata[$key] ?? null);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function money(array $object, string $field): Money
    {
        $currency = Values::string($object['currency'] ?? null);

        if ($currency === null) {
            throw new SignatureException('Stripe event is missing a currency.', 'stripe');
        }

        return Money::of(Values::int($object[$field] ?? null, 'Stripe event', 'stripe'), $currency);
    }
}
