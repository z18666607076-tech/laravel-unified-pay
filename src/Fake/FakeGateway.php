<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Fake;

use ZiwenZhao\UnifiedPay\Contracts\Gateway as PaymentGateway;
use ZiwenZhao\UnifiedPay\DTO\CreatePayment;
use ZiwenZhao\UnifiedPay\DTO\CreateRefund;
use ZiwenZhao\UnifiedPay\DTO\Payment;
use ZiwenZhao\UnifiedPay\DTO\PaymentEvent;
use ZiwenZhao\UnifiedPay\DTO\Refund;
use ZiwenZhao\UnifiedPay\Enums\PaymentEventType;
use ZiwenZhao\UnifiedPay\Enums\PaymentMode;
use ZiwenZhao\UnifiedPay\Enums\PaymentStatus;
use ZiwenZhao\UnifiedPay\Enums\RefundStatus;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;
use ZiwenZhao\UnifiedPay\Money;
use ZiwenZhao\UnifiedPay\Support\Json;
use ZiwenZhao\UnifiedPay\Support\Values;

class FakeGateway implements PaymentGateway
{
    /** @var array<string, Payment> */
    private array $payments = [];

    /** @var array<string, Refund> */
    private array $refunds = [];

    public function __construct(
        private string $name,
        protected Recorder $recorder,
    ) {}

    public function channel(): string
    {
        return $this->name;
    }

    public function create(CreatePayment $payment): Payment
    {
        if ($payment->mode->channel()->value !== $this->name) {
            throw new PaymentException(
                'Payment mode ['.$payment->mode->value.'] is not valid for ['.$this->name.'].',
                $this->name,
                null,
                422,
            );
        }

        $outTradeNo = Values::reference($payment->outTradeNo, 'out_trade_no', $this->name);
        $this->recorder->created[] = ['channel' => $this->name, 'payment' => $payment];
        $reference = $this->referencePrefix().$outTradeNo;
        $created = new Payment(
            channel: $this->name,
            outTradeNo: $outTradeNo,
            money: $payment->money,
            status: PaymentStatus::Pending,
            providerReference: $reference,
            clientPayload: $this->clientPayload($payment, $reference),
            rawStatus: 'fake_pending',
        );
        $this->payments[$outTradeNo] = $created;

        return $created;
    }

    public function query(string $outTradeNo): Payment
    {
        $outTradeNo = Values::reference($outTradeNo, 'out_trade_no', $this->name);
        $this->recorder->queried[] = ['channel' => $this->name, 'outTradeNo' => $outTradeNo];
        $payment = $this->payments[$outTradeNo] ?? null;

        if ($payment === null) {
            throw new PaymentException('Fake payment ['.$outTradeNo.'] was not created.', $this->name, 'resource_missing', 404);
        }

        return $payment;
    }

    public function close(string $outTradeNo): Payment
    {
        $outTradeNo = Values::reference($outTradeNo, 'out_trade_no', $this->name);
        $this->recorder->closed[] = ['channel' => $this->name, 'outTradeNo' => $outTradeNo];
        $existing = $this->payments[$outTradeNo] ?? null;
        $closed = new Payment(
            channel: $this->name,
            outTradeNo: $outTradeNo,
            money: $existing?->money,
            status: PaymentStatus::Closed,
            providerReference: $existing?->providerReference,
            rawStatus: 'fake_closed',
        );
        $this->payments[$outTradeNo] = $closed;

        return $closed;
    }

    public function refund(CreateRefund $refund): Refund
    {
        $outRefundNo = Values::reference($refund->outRefundNo, 'out_refund_no', $this->name);
        $this->recorder->refunded[] = ['channel' => $this->name, 'refund' => $refund];
        $created = new Refund(
            channel: $this->name,
            outRefundNo: $outRefundNo,
            money: $refund->money,
            status: RefundStatus::Succeeded,
            providerReference: 're_fake_'.$outRefundNo,
            outTradeNo: $refund->outTradeNo,
            rawStatus: 'fake_succeeded',
        );
        $this->refunds[$outRefundNo] = $created;

        return $created;
    }

    public function queryRefund(string $outRefundNo, ?string $outTradeNo = null): Refund
    {
        $outRefundNo = Values::reference($outRefundNo, 'out_refund_no', $this->name);
        $this->recorder->refundQueries[] = ['channel' => $this->name, 'outRefundNo' => $outRefundNo];
        $refund = $this->refunds[$outRefundNo] ?? null;

        if ($refund === null) {
            throw new PaymentException('Fake refund ['.$outRefundNo.'] was not created.', $this->name, 'resource_missing', 404);
        }

        return $refund;
    }

    public function parseNotification(string $body, array $headers): PaymentEvent
    {
        $decoded = Json::decodeObject($body, 'Fake notification is not JSON.');
        $id = Values::string($decoded['id'] ?? null);
        $typeName = Values::string($decoded['type'] ?? null);
        $type = $typeName === null ? null : PaymentEventType::tryFrom($typeName);

        if ($id === null || $type === null) {
            throw new PaymentException('Fake notification needs an id and a known type.', $this->name, null, 422);
        }

        $amount = $decoded['amount'] ?? null;
        $currency = Values::string($decoded['currency'] ?? null);
        $money = is_int($amount) && $currency !== null ? Money::of($amount, $currency) : null;

        return new PaymentEvent(
            channel: $this->name,
            id: $id,
            type: $type,
            outTradeNo: Values::string($decoded['out_trade_no'] ?? null),
            outRefundNo: Values::string($decoded['out_refund_no'] ?? null),
            providerReference: Values::string($decoded['provider_reference'] ?? null),
            money: $money,
            rawStatus: 'fake',
            payload: $decoded,
        );
    }

    /**
     * @return array<string, string>
     */
    private function clientPayload(CreatePayment $payment, string $reference): array
    {
        return match ($payment->mode) {
            PaymentMode::WechatJsapi, PaymentMode::WechatMiniProgram => [
                'appId' => 'wx_fake_app',
                'timeStamp' => (string) time(),
                'nonceStr' => 'fake-nonce',
                'package' => 'prepay_id='.$reference,
                'signType' => 'RSA',
                'paySign' => 'fake',
            ],
            PaymentMode::WechatNative => [
                'code_url' => 'weixin://wxpay/bizpayurl?pr='.$reference,
            ],
            PaymentMode::WechatH5 => [
                'h5_url' => 'https://wx.tenpay.com/fake/'.$reference,
            ],
            PaymentMode::AlipayPage, PaymentMode::AlipayWap => [
                'url' => 'https://openapi.alipay.com/gateway.do?fake='.$reference,
            ],
            PaymentMode::AlipayApp => [
                'order_string' => 'fake_order='.$reference,
            ],
            PaymentMode::StripePaymentIntent => [
                'payment_intent' => $reference,
                'client_secret' => $reference.'_secret_test',
            ],
        };
    }

    private function referencePrefix(): string
    {
        return match ($this->name) {
            'wechat' => 'wx_fake_',
            'alipay' => 'ali_fake_',
            default => 'pi_fake_',
        };
    }
}
