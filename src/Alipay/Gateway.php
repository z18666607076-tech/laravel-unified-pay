<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Alipay;

use DateTimeImmutable;
use DateTimeZone;
use Freeman\UnifiedPay\Contracts\Gateway as PaymentGateway;
use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\DTO\Payment;
use Freeman\UnifiedPay\DTO\PaymentEvent;
use Freeman\UnifiedPay\DTO\Refund;
use Freeman\UnifiedPay\Enums\Channel;
use Freeman\UnifiedPay\Enums\PaymentMode;
use Freeman\UnifiedPay\Enums\PaymentStatus;
use Freeman\UnifiedPay\Enums\RefundStatus;
use Freeman\UnifiedPay\Exceptions\PaymentException;
use Freeman\UnifiedPay\Exceptions\SignatureException;
use Freeman\UnifiedPay\Money;
use Freeman\UnifiedPay\Support\Json;
use Freeman\UnifiedPay\Support\Values;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;

final class Gateway implements PaymentGateway
{
    public function __construct(
        private Factory $http,
        private Signer $signer,
        private Credentials $credentials,
        private NotificationParser $notifications,
    ) {}

    public function channel(): string
    {
        return Channel::Alipay->value;
    }

    public function create(CreatePayment $payment): Payment
    {
        $this->assertMode($payment->mode);
        $this->assertCny($payment->money);
        $outTradeNo = Values::reference($payment->outTradeNo, 'out_trade_no', $this->channel());
        $subject = trim($payment->description);

        if ($subject === '') {
            throw new PaymentException('Payment description is required.', 'alipay', null, 422);
        }

        $method = match ($payment->mode) {
            PaymentMode::AlipayPage => 'alipay.trade.page.pay',
            PaymentMode::AlipayWap => 'alipay.trade.wap.pay',
            PaymentMode::AlipayApp => 'alipay.trade.app.pay',
            default => throw new PaymentException('Payment mode ['.$payment->mode->value.'] is not valid for Alipay.', 'alipay', null, 422),
        };
        $productCode = match ($payment->mode) {
            PaymentMode::AlipayPage => 'FAST_INSTANT_TRADE_PAY',
            PaymentMode::AlipayWap => 'QUICK_WAP_WAY',
            default => 'QUICK_MSECURITY_PAY',
        };

        $biz = [
            'out_trade_no' => $outTradeNo,
            'total_amount' => $payment->money->toDecimal(),
            'subject' => mb_substr($subject, 0, 256),
            'product_code' => $productCode,
        ];
        $params = $this->common($method, $biz, $payment->notifyUrl, $payment->returnUrl);
        $signed = $this->withSign($params);
        $query = http_build_query($signed, '', '&', PHP_QUERY_RFC3986);
        $payload = $payment->mode === PaymentMode::AlipayApp
            ? ['order_string' => $query]
            : ['url' => $this->credentials->gateway().'?'.$query, 'method' => $method];

        return new Payment(
            channel: $this->channel(),
            outTradeNo: $outTradeNo,
            money: $payment->money,
            status: PaymentStatus::Pending,
            clientPayload: $payload,
            rawStatus: 'WAIT_BUYER_PAY',
        );
    }

    public function query(string $outTradeNo): Payment
    {
        $outTradeNo = Values::reference($outTradeNo, 'out_trade_no', $this->channel());
        $node = $this->execute('alipay.trade.query', ['out_trade_no' => $outTradeNo], 'alipay_trade_query_response');

        return $this->paymentFromNode($node, $outTradeNo);
    }

    public function close(string $outTradeNo): Payment
    {
        $outTradeNo = Values::reference($outTradeNo, 'out_trade_no', $this->channel());
        $this->execute('alipay.trade.close', ['out_trade_no' => $outTradeNo], 'alipay_trade_close_response');

        return new Payment(
            channel: $this->channel(),
            outTradeNo: $outTradeNo,
            money: null,
            status: PaymentStatus::Closed,
            rawStatus: 'TRADE_CLOSED',
        );
    }

    public function refund(CreateRefund $refund): Refund
    {
        $this->assertCny($refund->money);
        $outRefundNo = Values::reference($refund->outRefundNo, 'out_request_no', $this->channel());
        $outTradeNo = $refund->outTradeNo === null
            ? null
            : Values::reference($refund->outTradeNo, 'out_trade_no', $this->channel());
        $tradeNo = Values::string($refund->providerReference);

        if ($outTradeNo === null && $tradeNo === null) {
            throw new PaymentException('An Alipay refund needs an out_trade_no or a trade_no.', 'alipay', null, 422);
        }

        $biz = [
            'refund_amount' => $refund->money->toDecimal(),
            'out_request_no' => $outRefundNo,
        ];

        if ($outTradeNo !== null) {
            $biz['out_trade_no'] = $outTradeNo;
        }

        if ($tradeNo !== null) {
            $biz['trade_no'] = $tradeNo;
        }

        if ($refund->reason !== null && $refund->reason !== '') {
            $biz['refund_reason'] = mb_substr($refund->reason, 0, 256);
        }

        $node = $this->execute('alipay.trade.refund', $biz, 'alipay_trade_refund_response');
        $fee = Values::string($node['refund_fee'] ?? null);

        return new Refund(
            channel: $this->channel(),
            outRefundNo: $outRefundNo,
            money: $fee === null ? $refund->money : Money::fromDecimal($fee, 'CNY'),
            status: RefundStatus::Succeeded,
            providerReference: Values::string($node['trade_no'] ?? null),
            outTradeNo: Values::string($node['out_trade_no'] ?? null) ?? $outTradeNo,
            rawStatus: 'SUCCESS',
        );
    }

    public function queryRefund(string $outRefundNo, ?string $outTradeNo = null): Refund
    {
        $outRefundNo = Values::reference($outRefundNo, 'out_request_no', $this->channel());

        if ($outTradeNo === null || $outTradeNo === '') {
            throw new PaymentException('Alipay refund queries need the original out_trade_no.', 'alipay', null, 422);
        }

        $outTradeNo = Values::reference($outTradeNo, 'out_trade_no', $this->channel());
        $node = $this->execute('alipay.trade.fastpay.refund.query', [
            'out_trade_no' => $outTradeNo,
            'out_request_no' => $outRefundNo,
        ], 'alipay_trade_fastpay_refund_query_response');
        $fee = Values::string($node['refund_amount'] ?? null);

        if ($fee === null) {
            throw new PaymentException('Alipay refund query did not return an amount.', 'alipay');
        }

        $status = Values::string($node['refund_status'] ?? null);

        return new Refund(
            channel: $this->channel(),
            outRefundNo: $outRefundNo,
            money: Money::fromDecimal($fee, 'CNY'),
            status: $status === 'REFUND_SUCCESS' || $status === null ? RefundStatus::Succeeded : RefundStatus::Processing,
            providerReference: Values::string($node['trade_no'] ?? null),
            outTradeNo: $outTradeNo,
            rawStatus: $status ?? 'REFUND_SUCCESS',
        );
    }

    public function parseNotification(string $body, array $headers): PaymentEvent
    {
        return $this->notifications->parse($body, $headers);
    }

    /**
     * @param  array<string, string>  $biz
     * @return array<string, mixed>
     */
    private function execute(string $method, array $biz, string $node): array
    {
        $params = $this->withSign($this->common($method, $biz, null, null));
        $timeout = config('unified-pay.http.timeout');
        $pending = $this->pending(is_int($timeout) && $timeout > 0 ? $timeout : 10);
        $response = $pending->asForm()->post($this->credentials->gateway(), $params);

        if (! $response->successful()) {
            throw new PaymentException('Alipay rejected the request.', 'alipay', null, 502);
        }

        $raw = $response->body();
        $content = $this->signer->extractObject($raw, $node);
        $sign = $this->responseSign($raw);

        if (! $this->signer->verify($content, $sign, $this->credentials->publicKey())) {
            throw new SignatureException('Alipay response signature is invalid.', 'alipay');
        }

        $decoded = Json::decodeObject($content, 'Alipay response is not JSON.');
        $code = Values::string($decoded['code'] ?? null);

        if ($code !== '10000') {
            $message = Values::string($decoded['sub_msg'] ?? null) ?? Values::string($decoded['msg'] ?? null) ?? 'Alipay rejected the request.';
            $sub = Values::string($decoded['sub_code'] ?? null);

            throw new PaymentException($message, 'alipay', $sub ?? $code, 502);
        }

        return $decoded;
    }

    /**
     * @param  array<string, string>  $biz
     * @return array<string, string>
     */
    private function common(string $method, array $biz, ?string $notifyUrl, ?string $returnUrl): array
    {
        $params = [
            'app_id' => $this->credentials->appId(),
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => Json::encode($biz),
        ];
        $notify = Values::string($notifyUrl) ?? $this->optionalNotify();

        if ($notify !== null) {
            $params['notify_url'] = $notify;
        }

        $return = Values::string($returnUrl) ?? $this->credentials->returnUrl();

        if ($return !== null && ($method === 'alipay.trade.page.pay' || $method === 'alipay.trade.wap.pay')) {
            $params['return_url'] = $return;
        }

        return $params;
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, string>
     */
    private function withSign(array $params): array
    {
        $params['sign'] = $this->signer->sign($params, $this->credentials->privateKey());

        return $params;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function paymentFromNode(array $node, string $outTradeNo): Payment
    {
        $tradeStatus = Values::string($node['trade_status'] ?? null) ?? 'UNKNOWN';
        $status = match ($tradeStatus) {
            'TRADE_SUCCESS', 'TRADE_FINISHED' => PaymentStatus::Succeeded,
            'TRADE_CLOSED' => PaymentStatus::Closed,
            default => PaymentStatus::Pending,
        };
        $total = Values::string($node['total_amount'] ?? null);

        return new Payment(
            channel: $this->channel(),
            outTradeNo: Values::string($node['out_trade_no'] ?? null) ?? $outTradeNo,
            money: $total === null ? null : Money::fromDecimal($total, 'CNY'),
            status: $status,
            providerReference: Values::string($node['trade_no'] ?? null),
            rawStatus: $tradeStatus,
        );
    }

    private function responseSign(string $raw): string
    {
        $decoded = Json::decodeObject($raw, 'Alipay response is not JSON.');
        $sign = Values::string($decoded['sign'] ?? null);

        if ($sign === null) {
            throw new SignatureException('Alipay response is missing a signature.', 'alipay');
        }

        return $sign;
    }

    private function optionalNotify(): ?string
    {
        $value = config('unified-pay.alipay.notify_url');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function pending(int $timeout): PendingRequest
    {
        $agent = config('unified-pay.http.user_agent');

        return $this->http
            ->withHeaders([
                'User-Agent' => is_string($agent) && $agent !== '' ? $agent : 'freeman-laravel-unified-pay/0.1',
            ])
            ->timeout($timeout)
            ->acceptJson();
    }

    private function assertMode(PaymentMode $mode): void
    {
        if ($mode->channel() !== Channel::Alipay) {
            throw new PaymentException('Payment mode ['.$mode->value.'] is not valid for Alipay.', 'alipay', null, 422);
        }
    }

    private function assertCny(Money $money): void
    {
        if ($money->currency !== 'CNY') {
            throw new PaymentException('Alipay open-platform charges are CNY only.', 'alipay', null, 422);
        }

        if ($money->amount < 1) {
            throw new PaymentException('Alipay amount must be at least 1 fen.', 'alipay', null, 422);
        }
    }
}
