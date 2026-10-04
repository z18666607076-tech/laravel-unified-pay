<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Alipay;

use ZiwenZhao\UnifiedPay\DTO\PaymentEvent;
use ZiwenZhao\UnifiedPay\Enums\Channel;
use ZiwenZhao\UnifiedPay\Enums\PaymentEventType;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;
use ZiwenZhao\UnifiedPay\Exceptions\SignatureException;
use ZiwenZhao\UnifiedPay\Money;
use ZiwenZhao\UnifiedPay\Support\Values;

final class NotificationParser
{
    public function __construct(
        private Signer $signer,
        private Credentials $credentials,
    ) {}

    /**
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    public function parse(string $body, array $headers = []): PaymentEvent
    {
        $params = $this->form($body);
        $sign = $params['sign'] ?? '';

        if ($sign === '') {
            throw new SignatureException('Alipay notification is missing a signature.', 'alipay');
        }

        unset($params['sign'], $params['sign_type']);
        $message = $this->signer->canonical($params, true);

        if (! $this->signer->verify($message, $sign, $this->credentials->publicKey())) {
            throw new SignatureException('Alipay notification signature is invalid.', 'alipay');
        }

        $outTradeNo = Values::string($params['out_trade_no'] ?? null);
        $tradeNo = Values::string($params['trade_no'] ?? null);
        $notifyId = Values::string($params['notify_id'] ?? null) ?? hash('sha256', $message);
        $tradeStatus = Values::string($params['trade_status'] ?? null);
        $refundedAt = Values::string($params['gmt_refund'] ?? null);
        $channel = Channel::Alipay->value;

        if ($refundedAt !== null) {
            $refundFee = Values::string($params['refund_fee'] ?? null) ?? Values::string($params['total_amount'] ?? null);

            if ($refundFee === null) {
                throw new PaymentException('Alipay refund notification is missing an amount.', 'alipay', null, 422);
            }

            return new PaymentEvent(
                channel: $channel,
                id: $notifyId,
                type: PaymentEventType::RefundSucceeded,
                outTradeNo: $outTradeNo,
                outRefundNo: Values::string($params['out_biz_no'] ?? null),
                providerReference: $tradeNo,
                money: Money::fromDecimal($refundFee, 'CNY'),
                rawStatus: $tradeStatus,
                payload: $params,
            );
        }

        $type = match ($tradeStatus) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => PaymentEventType::PaymentSucceeded,
            'TRADE_CLOSED' => PaymentEventType::PaymentClosed,
            default => PaymentEventType::Ignored,
        };

        $total = Values::string($params['total_amount'] ?? null);
        $money = $type === PaymentEventType::Ignored || $total === null
            ? null
            : Money::fromDecimal($total, 'CNY');

        if ($type !== PaymentEventType::Ignored && $money === null) {
            throw new PaymentException('Alipay notification is missing an amount.', 'alipay', null, 422);
        }

        return new PaymentEvent(
            channel: $channel,
            id: $notifyId,
            type: $type,
            outTradeNo: $outTradeNo,
            providerReference: $tradeNo,
            money: $money,
            rawStatus: $tradeStatus,
            payload: $params,
        );
    }

    /**
     * @return array<string, string>
     */
    private function form(string $body): array
    {
        $parsed = [];
        parse_str($body, $parsed);
        $params = [];

        foreach ($parsed as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $params[$key] = (string) $value;
        }

        if ($params === []) {
            throw new SignatureException('Alipay notification is empty.', 'alipay');
        }

        return $params;
    }
}
