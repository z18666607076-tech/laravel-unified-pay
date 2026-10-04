<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Stripe;

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
use Freeman\UnifiedPay\Money;
use Freeman\UnifiedPay\Support\Headers;
use Freeman\UnifiedPay\Support\Values;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class Gateway implements PaymentGateway
{
    public function __construct(
        private Factory $http,
        private Credentials $credentials,
        private WebhookVerifier $verifier,
        private NotificationParser $notifications,
    ) {}

    public function channel(): string
    {
        return Channel::Stripe->value;
    }

    public function create(CreatePayment $payment): Payment
    {
        if ($payment->mode !== PaymentMode::StripePaymentIntent) {
            throw new PaymentException('Payment mode ['.$payment->mode->value.'] is not valid for Stripe.', 'stripe', null, 422);
        }

        $outTradeNo = Values::reference($payment->outTradeNo, 'out_trade_no', $this->channel());

        if ($payment->money->amount < 1) {
            throw new PaymentException('Stripe amount must be at least 1 minor unit.', 'stripe', null, 422);
        }

        $form = [
            'amount' => $payment->money->amount,
            'currency' => strtolower($payment->money->currency),
            'metadata' => $this->metadata($payment->metadata, ['out_trade_no' => $outTradeNo]),
            'automatic_payment_methods' => ['enabled' => 'true'],
            'description' => mb_substr(trim($payment->description), 0, 1000),
        ];
        $payload = $this->post('/v1/payment_intents', $form, $payment->idempotencyKey ?: 'pay_'.$outTradeNo);

        return $this->paymentFromIntent($payload, $outTradeNo);
    }

    public function query(string $outTradeNo): Payment
    {
        $reference = trim($outTradeNo);

        if (str_starts_with($reference, 'pi_')) {
            return $this->paymentFromIntent($this->get('/v1/payment_intents/'.rawurlencode($reference)), $reference);
        }

        $reference = Values::reference($reference, 'out_trade_no', $this->channel());
        $found = $this->searchPaymentIntent($reference);

        return $this->paymentFromIntent($found, $reference);
    }

    public function close(string $outTradeNo): Payment
    {
        $intent = $this->intentId($outTradeNo);
        $payload = $this->post('/v1/payment_intents/'.rawurlencode($intent).'/cancel', [], 'cancel_'.$intent);

        return $this->paymentFromIntent($payload, $this->metadataValue($payload, 'out_trade_no') ?? $outTradeNo);
    }

    public function refund(CreateRefund $refund): Refund
    {
        $outRefundNo = Values::reference($refund->outRefundNo, 'out_refund_no', $this->channel());
        $intent = Values::string($refund->providerReference);

        if ($intent === null && $refund->outTradeNo !== null) {
            $intent = $this->intentId($refund->outTradeNo);
        }

        if ($intent === null || ! str_starts_with($intent, 'pi_')) {
            throw new PaymentException('A Stripe refund needs a payment intent id (pi_...).', 'stripe', null, 422);
        }

        if ($refund->money->amount < 1) {
            throw new PaymentException('Stripe refund amount must be at least 1 minor unit.', 'stripe', null, 422);
        }

        $metadata = ['out_refund_no' => $outRefundNo];

        if ($refund->outTradeNo !== null) {
            $metadata['out_trade_no'] = $refund->outTradeNo;
        }

        $form = [
            'payment_intent' => $intent,
            'amount' => $refund->money->amount,
            'metadata' => $this->metadata($refund->metadata, $metadata),
        ];

        if ($refund->reason !== null && in_array($refund->reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true)) {
            $form['reason'] = $refund->reason;
        }

        $payload = $this->post('/v1/refunds', $form, 'refund_'.$outRefundNo);

        return $this->refundFromResource($payload, $outRefundNo, $refund->outTradeNo);
    }

    public function queryRefund(string $outRefundNo, ?string $outTradeNo = null): Refund
    {
        if (str_starts_with($outRefundNo, 're_')) {
            return $this->refundFromResource($this->get('/v1/refunds/'.rawurlencode($outRefundNo)), $outRefundNo, $outTradeNo);
        }

        $outRefundNo = Values::reference($outRefundNo, 'out_refund_no', $this->channel());

        if ($outTradeNo === null || $outTradeNo === '') {
            throw new PaymentException('Stripe refund lookup by merchant number needs the payment intent id or the out_trade_no.', 'stripe', null, 422);
        }

        $intent = str_starts_with($outTradeNo, 'pi_') ? $outTradeNo : $this->intentId($outTradeNo);
        $payload = $this->get('/v1/refunds', ['payment_intent' => $intent, 'limit' => 100]);
        $rows = $payload['data'] ?? null;

        if (! is_array($rows)) {
            throw new PaymentException('Stripe did not return a refund list.', 'stripe');
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $refund = Values::assoc($row);

            if ($this->metadataValue($refund, 'out_refund_no') === $outRefundNo) {
                return $this->refundFromResource($refund, $outRefundNo, $outTradeNo);
            }
        }

        throw new PaymentException('Stripe refund ['.$outRefundNo.'] was not found.', 'stripe', 'resource_missing', 404);
    }

    public function parseNotification(string $body, array $headers): PaymentEvent
    {
        $secret = $this->credentials->webhookSecret();
        $event = $this->verifier->parse($body, Headers::get($headers, 'Stripe-Signature'), $secret);

        return $this->notifications->parse($event);
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @param  array<string, string>  $base
     * @return array<string, string>
     */
    private function metadata(array $extra, array $base): array
    {
        foreach ($extra as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $base[$key] = mb_substr($value, 0, 500);
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function searchPaymentIntent(string $outTradeNo): array
    {
        $this->assertSearchable($outTradeNo);
        $payload = $this->get('/v1/payment_intents/search', [
            'query' => "metadata['out_trade_no']:'".$outTradeNo."'",
        ]);
        $rows = $payload['data'] ?? null;
        $first = is_array($rows) ? ($rows[0] ?? null) : null;

        if (! is_array($first)) {
            throw new PaymentException('Stripe payment ['.$outTradeNo.'] was not found.', 'stripe', 'resource_missing', 404);
        }

        return Values::assoc($first);
    }

    private function intentId(string $reference): string
    {
        if (str_starts_with($reference, 'pi_')) {
            return $reference;
        }

        $intent = $this->searchPaymentIntent(Values::reference($reference, 'out_trade_no', $this->channel()));
        $id = Values::string($intent['id'] ?? null);

        if ($id === null) {
            throw new PaymentException('Stripe payment ['.$reference.'] was not found.', 'stripe', 'resource_missing', 404);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function paymentFromIntent(array $payload, string $outTradeNo): Payment
    {
        $id = Values::string($payload['id'] ?? null);
        $status = Values::string($payload['status'] ?? null);
        $currency = Values::string($payload['currency'] ?? null);
        $secret = Values::string($payload['client_secret'] ?? null);

        if ($id === null || $status === null || $currency === null) {
            throw new PaymentException('Stripe did not return a payment intent.', 'stripe');
        }

        $mapped = match ($status) {
            'succeeded' => PaymentStatus::Succeeded,
            'canceled' => PaymentStatus::Closed,
            default => PaymentStatus::Pending,
        };
        $client = ['payment_intent' => $id];

        if ($secret !== null) {
            $client['client_secret'] = $secret;
        }

        return new Payment(
            channel: $this->channel(),
            outTradeNo: $this->metadataValue($payload, 'out_trade_no') ?? $outTradeNo,
            money: Money::of(Values::int($payload['amount'] ?? null, 'Stripe payment intent', 'stripe'), $currency),
            status: $mapped,
            providerReference: $id,
            clientPayload: $client,
            rawStatus: $status,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function refundFromResource(array $payload, string $outRefundNo, ?string $outTradeNo): Refund
    {
        $id = Values::string($payload['id'] ?? null);
        $status = Values::string($payload['status'] ?? null);
        $currency = Values::string($payload['currency'] ?? null);

        if ($id === null || $status === null || $currency === null) {
            throw new PaymentException('Stripe did not return a refund.', 'stripe');
        }

        $mapped = match ($status) {
            'succeeded' => RefundStatus::Succeeded,
            'failed', 'canceled' => RefundStatus::Failed,
            default => RefundStatus::Processing,
        };

        return new Refund(
            channel: $this->channel(),
            outRefundNo: $this->metadataValue($payload, 'out_refund_no') ?? $outRefundNo,
            money: Money::of(Values::int($payload['amount'] ?? null, 'Stripe refund', 'stripe'), $currency),
            status: $mapped,
            providerReference: $id,
            outTradeNo: $this->metadataValue($payload, 'out_trade_no') ?? $outTradeNo,
            rawStatus: $status,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function metadataValue(array $payload, string $key): ?string
    {
        $metadata = $payload['metadata'] ?? null;

        if (! is_array($metadata)) {
            return null;
        }

        return Values::string($metadata[$key] ?? null);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function post(string $path, array $form, string $idempotencyKey): array
    {
        $response = $this->pending()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->asForm()
            ->post($this->credentials->baseUrl().$path, $form);

        return $this->decode($response);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->pending()->get($this->credentials->baseUrl().$path, $query);

        return $this->decode($response);
    }

    private function pending(): PendingRequest
    {
        $timeout = config('unified-pay.http.timeout');
        $headers = ['User-Agent' => $this->userAgent()];
        $version = $this->credentials->apiVersion();

        if ($version !== null) {
            $headers['Stripe-Version'] = $version;
        }

        return $this->http
            ->withToken($this->credentials->secret())
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout(is_int($timeout) && $timeout > 0 ? $timeout : 10);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $body = $response->body();
        $decoded = $body === '' ? [] : json_decode($body, true);

        if (! is_array($decoded)) {
            throw new PaymentException('Stripe returned a non-JSON response.', 'stripe');
        }

        $normalized = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        if (! $response->successful()) {
            $error = $normalized['error'] ?? null;
            $message = is_array($error) ? Values::string($error['message'] ?? null) : null;
            $code = is_array($error) ? Values::string($error['code'] ?? null) : null;

            throw new PaymentException(
                $message ?? 'Stripe rejected the request.',
                'stripe',
                $code,
                502,
            );
        }

        return $normalized;
    }

    private function assertSearchable(string $reference): void
    {
        if (str_contains($reference, "'") || str_contains($reference, '\\')) {
            throw new PaymentException('Stripe search reference contains unsupported characters.', 'stripe', null, 422);
        }
    }

    private function userAgent(): string
    {
        $agent = config('unified-pay.http.user_agent');

        return is_string($agent) && $agent !== '' ? $agent : 'freeman-laravel-unified-pay/0.1';
    }
}
