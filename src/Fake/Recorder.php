<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Fake;

use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\DTO\ProfitShareRequest;

final class Recorder
{
    /** @var list<array{channel: string, payment: CreatePayment}> */
    public array $created = [];

    /** @var list<array{channel: string, refund: CreateRefund}> */
    public array $refunded = [];

    /** @var list<array{channel: string, outTradeNo: string}> */
    public array $queried = [];

    /** @var list<array{channel: string, outTradeNo: string}> */
    public array $closed = [];

    /** @var list<array{channel: string, outRefundNo: string}> */
    public array $refundQueries = [];

    /** @var list<array{channel: string, request: ProfitShareRequest}> */
    public array $profitShares = [];

    /** @var list<array{channel: string, type: string, account: string}> */
    public array $profitShareReceivers = [];
}
