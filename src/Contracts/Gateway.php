<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Contracts;

use ZiwenZhao\UnifiedPay\DTO\CreatePayment;
use ZiwenZhao\UnifiedPay\DTO\CreateRefund;
use ZiwenZhao\UnifiedPay\DTO\Payment;
use ZiwenZhao\UnifiedPay\DTO\PaymentEvent;
use ZiwenZhao\UnifiedPay\DTO\Refund;

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
