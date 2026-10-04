<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Fake;

use ZiwenZhao\UnifiedPay\DTO\CreatePayment;
use ZiwenZhao\UnifiedPay\DTO\CreateRefund;
use ZiwenZhao\UnifiedPay\DTO\ProfitShareRequest;

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
