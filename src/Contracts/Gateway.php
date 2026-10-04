<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Contracts;

use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\DTO\Payment;
use Freeman\UnifiedPay\DTO\PaymentEvent;
use Freeman\UnifiedPay\DTO\Refund;

interface Gateway
{
    public function channel(): string;

    public function create(CreatePayment $payment): Payment;

    public function query(string $outTradeNo): Payment;

    public function close(string $outTradeNo): Payment;

    public function refund(CreateRefund $refund): Refund;

    public function queryRefund(string $outRefundNo, ?string $outTradeNo = null): Refund;

    /**
     * Verify the provider signature and return one normalized event.
     *
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    public function parseNotification(string $body, array $headers): PaymentEvent;
}
