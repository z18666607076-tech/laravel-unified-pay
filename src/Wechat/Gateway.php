<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Wechat;

use Freeman\UnifiedPay\Contracts\Gateway as PaymentGateway;
use Freeman\UnifiedPay\Contracts\ProfitSharingGateway;
use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\DTO\Payment;
use Freeman\UnifiedPay\DTO\PaymentEvent;
use Freeman\UnifiedPay\DTO\ProfitShareRequest;
use Freeman\UnifiedPay\DTO\ProfitShareResult;
use Freeman\UnifiedPay\DTO\Refund;
use Freeman\UnifiedPay\Enums\Channel;
use Freeman\UnifiedPay\Enums\PaymentMode;
use Freeman\UnifiedPay\Enums\PaymentStatus;
use Freeman\UnifiedPay\Enums\RefundStatus;
use Freeman\UnifiedPay\Exceptions\PaymentException;
use Freeman\UnifiedPay\Money;
use Freeman\UnifiedPay\Support\Values;

final class Gateway implements PaymentGateway, ProfitSharingGateway
{
    public function __construct(
        private Client $client,
        private Signer $signer,
        private Credentials $credentials,
        private NotificationParser $notifications,
    ) {}

    public function channel(): string
    {
        return Channel::Wechat->value;
    }

    public function create(CreatePayment $payment): Payment
    {
        $this->assertMode($payment->mode);
        $this->assertCny($payment->money);
        $outTradeNo = Values::reference($payment->outTradeNo, 'out_trade_no', $this->channel());
        $description = $this->description($payment->description);
        $appId = $payment->mode === PaymentMode::WechatMiniProgram
            ? $this->credentials->miniAppId()
            : $this->credentials->appId();

        $body = [
            'appid' => $appId,
            'mchid' => $this->credentials->mchId(),
            'description' => $description,
            'out_trade_no' => $outTradeNo,
            'notify_url' => $payment->notifyUrl ?: $this->credentials->notifyUrl(),
            'amount' => [
                'total' => $payment->money->amount,
                'currency' => 'CNY',
            ],
        ];

        if ($payment->profitSharing) {
            $body['settle_info'] = ['profit_sharing' => true];
        }

        [$path, $body] = $this->modeBody($payment, $body);
        $payload = $this->client->call('POST', $path, $body);

        return $this->createdPayment($payment, $outTradeNo, $appId, $payload);
    }

    public function query(string $outTradeNo): Payment
    {
        $outTradeNo = Values::reference($outTradeNo, 'out_trade_no', $this->channel());
        $payload = $this->client->call(
            'GET',
            '/v3/pay/transactions/out-trade-no/'.rawurlencode($outTradeNo),
            null,
            ['mchid' => $this->credentials->mchId()],
        );

        return $this->paymentFromResource($payload, $outTradeNo);
    }

    public function close(string $outTradeNo): Payment
    {
        $outTradeNo = Values::reference($outTradeNo, 'out_trade_no', $this->channel());
        $this->client->call(
            'POST',
            '/v3/pay/transactions/out-trade-no/'.rawurlencode($outTradeNo).'/close',
            ['mchid' => $this->credentials->mchId()],
        );

        return new Payment(
            channel: $this->channel(),
            outTradeNo: $outTradeNo,
            money: null,
            status: PaymentStatus::Closed,
            rawStatus: 'CLOSED',
        );
    }

    public function refund(CreateRefund $refund): Refund
    {
        $this->assertCny($refund->money);
        $outRefundNo = Values::reference($refund->outRefundNo, 'out_refund_no', $this->channel());
        $outTradeNo = $refund->outTradeNo === null
            ? null
            : Values::reference($refund->outTradeNo, 'out_trade_no', $this->channel());
        $transactionId = Values::string($refund->providerReference);

        if ($outTradeNo === null && $transactionId === null) {
            throw new PaymentException('A WeChat refund needs an out_trade_no or a transaction id.', 'wechat', null, 422);
        }

        $total = $refund->originalAmount ?? $refund->money->amount;

        if ($total < $refund->money->amount) {
            throw new PaymentException('WeChat refund total must be at least the refunded amount.', 'wechat', null, 422);
        }

        $body = [
            'out_refund_no' => $outRefundNo,
            'notify_url' => $refund->notifyUrl ?: $this->credentials->notifyUrl(),
            'amount' => [
                'refund' => $refund->money->amount,
                'total' => $total,
                'currency' => 'CNY',
            ],
        ];

        if ($refund->reason !== null && $refund->reason !== '') {
            $body['reason'] = mb_substr($refund->reason, 0, 80);
        }

        if ($transactionId !== null) {
            $body['transaction_id'] = $transactionId;
        }

        if ($outTradeNo !== null) {
            $body['out_trade_no'] = $outTradeNo;
        }

        $payload = $this->client->call('POST', '/v3/refund/domestic/refunds', $body);

        return $this->refundFromResource($payload, $outRefundNo, $refund->money, $outTradeNo);
    }

    public function queryRefund(string $outRefundNo, ?string $outTradeNo = null): Refund
    {
        $outRefundNo = Values::reference($outRefundNo, 'out_refund_no', $this->channel());
        $payload = $this->client->call('GET', '/v3/refund/domestic/refunds/'.rawurlencode($outRefundNo));
        $money = $this->amount($payload, 'refund');

        return $this->refundFromResource($payload, $outRefundNo, $money, Values::string($payload['out_trade_no'] ?? null) ?? $outTradeNo);
    }

    public function parseNotification(string $body, array $headers): PaymentEvent
    {
        return $this->notifications->parse($body, $headers);
    }

    public function addProfitShareReceiver(string $type, string $account, string $relationType, ?string $name = null): void
    {
        $body = [
            'appid' => $this->credentials->appId(),
            'type' => $type,
            'account' => $account,
            'relation_type' => $relationType,
        ];
        $headers = [];

        if ($name !== null && $name !== '') {
            $body['name'] = $this->signer->encryptOaep($name, $this->credentials->platformPublicKey());
            $headers['Wechatpay-Serial'] = $this->credentials->platformSerial();
        }

        $this->client->call('POST', '/v3/profitsharing/receivers/add', $body, [], $headers);
    }

    public function profitShare(ProfitShareRequest $request): ProfitShareResult
    {
        $outOrderNo = Values::reference($request->outOrderNo, 'out_order_no', $this->channel());

        if ($request->receivers === []) {
            throw new PaymentException('Profit sharing needs at least one receiver.', 'wechat', null, 422);
        }

        $receivers = [];

        foreach ($request->receivers as $receiver) {
            if ($receiver->amount < 1) {
                throw new PaymentException('Profit sharing amount must be at least 1 fen.', 'wechat', null, 422);
            }

            $row = [
                'type' => $receiver->type,
                'account' => $receiver->account,
                'amount' => $receiver->amount,
                'description' => mb_substr($receiver->description, 0, 80),
            ];

            if ($receiver->name !== null && $receiver->name !== '') {
                $row['name'] = $this->signer->encryptOaep($receiver->name, $this->credentials->platformPublicKey());
            }

            $receivers[] = $row;
        }

        $headers = [];

        foreach ($request->receivers as $receiver) {
            if ($receiver->name !== null && $receiver->name !== '') {
                $headers['Wechatpay-Serial'] = $this->credentials->platformSerial();
                break;
            }
        }

        $payload = $this->client->call('POST', '/v3/profitsharing/orders', [
            'appid' => $this->credentials->appId(),
            'transaction_id' => $request->transactionId,
            'out_order_no' => $outOrderNo,
            'receivers' => $receivers,
            'unfreeze_unsplit' => $request->unfreezeUnsplit,
        ], [], $headers);

        return $this->shareResult($payload, $outOrderNo);
    }

    public function queryProfitShare(string $outOrderNo, string $transactionId): ProfitShareResult
    {
        $outOrderNo = Values::reference($outOrderNo, 'out_order_no', $this->channel());
        $payload = $this->client->call(
            'GET',
            '/v3/profitsharing/orders/'.rawurlencode($outOrderNo),
            null,
            ['transaction_id' => $transactionId],
        );

        return $this->shareResult($payload, $outOrderNo);
    }

    private function assertMode(PaymentMode $mode): void
    {
        if ($mode->channel() !== Channel::Wechat) {
            throw new PaymentException('Payment mode ['.$mode->value.'] is not valid for WeChat Pay.', 'wechat', null, 422);
        }
    }

    private function assertCny(Money $money): void
    {
        if ($money->currency !== 'CNY') {
            throw new PaymentException('WeChat Pay domestic charges are CNY only.', 'wechat', null, 422);
        }

        if ($money->amount < 1) {
            throw new PaymentException('WeChat Pay amount must be at least 1 fen.', 'wechat', null, 422);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function modeBody(CreatePayment $payment, array $body): array
    {
        if ($payment->mode === PaymentMode::WechatJsapi || $payment->mode === PaymentMode::WechatMiniProgram) {
            $openid = Values::string($payment->payerOpenId);

            if ($openid === null) {
                throw new PaymentException('WeChat JSAPI and mini program payments need a payer openid.', 'wechat', null, 422);
            }

            $body['payer'] = ['openid' => $openid];

            return ['/v3/pay/transactions/jsapi', $body];
        }

        if ($payment->mode === PaymentMode::WechatNative) {
            return ['/v3/pay/transactions/native', $body];
        }

        $ip = Values::string($payment->clientIp);

        if ($ip === null) {
            throw new PaymentException('WeChat H5 payments need the payer client IP.', 'wechat', null, 422);
        }

        $type = Values::string($payment->h5Type) ?? 'Wap';

        if (! in_array($type, ['Wap', 'iOS', 'Android'], true)) {
            throw new PaymentException('WeChat H5 type must be Wap, iOS, or Android.', 'wechat', null, 422);
        }

        $body['scene_info'] = [
            'payer_client_ip' => $ip,
            'h5_info' => ['type' => $type],
        ];

        return ['/v3/pay/transactions/h5', $body];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createdPayment(CreatePayment $payment, string $outTradeNo, string $appId, array $payload): Payment
    {
        if ($payment->mode === PaymentMode::WechatJsapi || $payment->mode === PaymentMode::WechatMiniProgram) {
            $prepayId = Values::string($payload['prepay_id'] ?? null);

            if ($prepayId === null) {
                throw new PaymentException('WeChat Pay did not return a prepay id.', 'wechat');
            }

            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $package = 'prepay_id='.$prepayId;
            $paySign = $this->signer->sign(
                $this->signer->clientMessage($appId, $timestamp, $nonce, $package),
                $this->credentials->privateKey(),
            );

            return new Payment(
                channel: $this->channel(),
                outTradeNo: $outTradeNo,
                money: $payment->money,
                status: PaymentStatus::Pending,
                providerReference: $prepayId,
                clientPayload: [
                    'appId' => $appId,
                    'timeStamp' => $timestamp,
                    'nonceStr' => $nonce,
                    'package' => $package,
                    'signType' => 'RSA',
                    'paySign' => $paySign,
                ],
                rawStatus: 'NOTPAY',
            );
        }

        if ($payment->mode === PaymentMode::WechatNative) {
            $codeUrl = Values::string($payload['code_url'] ?? null);

            if ($codeUrl === null) {
                throw new PaymentException('WeChat Pay did not return a code url.', 'wechat');
            }

            return new Payment(
                channel: $this->channel(),
                outTradeNo: $outTradeNo,
                money: $payment->money,
                status: PaymentStatus::Pending,
                clientPayload: ['code_url' => $codeUrl],
                rawStatus: 'NOTPAY',
            );
        }

        $h5Url = Values::string($payload['h5_url'] ?? null);

        if ($h5Url === null) {
            throw new PaymentException('WeChat Pay did not return an H5 url.', 'wechat');
        }

        return new Payment(
            channel: $this->channel(),
            outTradeNo: $outTradeNo,
            money: $payment->money,
            status: PaymentStatus::Pending,
            clientPayload: ['h5_url' => $h5Url],
            rawStatus: 'NOTPAY',
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function paymentFromResource(array $resource, string $outTradeNo): Payment
    {
        $state = Values::string($resource['trade_state'] ?? null) ?? 'UNKNOWN';
        $status = match ($state) {
            'SUCCESS' => PaymentStatus::Succeeded,
            'REFUND' => PaymentStatus::Refunded,
            'CLOSED', 'REVOKED' => PaymentStatus::Closed,
            'PAYERROR' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };

        return new Payment(
            channel: $this->channel(),
            outTradeNo: Values::string($resource['out_trade_no'] ?? null) ?? $outTradeNo,
            money: $this->amount($resource, 'total'),
            status: $status,
            providerReference: Values::string($resource['transaction_id'] ?? null),
            rawStatus: $state,
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function refundFromResource(array $resource, string $outRefundNo, Money $money, ?string $outTradeNo): Refund
    {
        $state = Values::string($resource['status'] ?? null) ?? Values::string($resource['refund_status'] ?? null) ?? 'PROCESSING';
        $status = match ($state) {
            'SUCCESS' => RefundStatus::Succeeded,
            'CLOSED', 'ABNORMAL' => RefundStatus::Failed,
            default => RefundStatus::Processing,
        };

        return new Refund(
            channel: $this->channel(),
            outRefundNo: Values::string($resource['out_refund_no'] ?? null) ?? $outRefundNo,
            money: $money,
            status: $status,
            providerReference: Values::string($resource['refund_id'] ?? null),
            outTradeNo: Values::string($resource['out_trade_no'] ?? null) ?? $outTradeNo,
            rawStatus: $state,
        );
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function amount(array $resource, string $field): Money
    {
        $amount = $resource['amount'] ?? null;

        if (! is_array($amount)) {
            throw new PaymentException('WeChat Pay response is missing an amount.', 'wechat');
        }

        $currency = Values::string($amount['currency'] ?? null) ?? 'CNY';

        return Money::of(Values::int($amount[$field] ?? null, 'WeChat Pay response', 'wechat'), $currency);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shareResult(array $payload, string $outOrderNo): ProfitShareResult
    {
        $state = Values::string($payload['state'] ?? null);

        if ($state === null) {
            throw new PaymentException('WeChat Pay did not return a profit-sharing state.', 'wechat');
        }

        return new ProfitShareResult(
            channel: $this->channel(),
            outOrderNo: Values::string($payload['out_order_no'] ?? null) ?? $outOrderNo,
            orderId: Values::string($payload['order_id'] ?? null),
            state: $state,
            transactionId: Values::string($payload['transaction_id'] ?? null),
        );
    }

    private function description(string $description): string
    {
        $description = trim($description);

        if ($description === '') {
            throw new PaymentException('Payment description is required.', 'wechat', null, 422);
        }

        return mb_substr($description, 0, 127);
    }
}
