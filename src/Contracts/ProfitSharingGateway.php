<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Contracts;

use Freeman\UnifiedPay\DTO\ProfitShareRequest;
use Freeman\UnifiedPay\DTO\ProfitShareResult;

/**
 * Optional capability. WeChat Pay implements it. Check with instanceof
 * before calling; Alipay and Stripe drivers do not.
 */
interface ProfitSharingGateway
{
    public function addProfitShareReceiver(string $type, string $account, string $relationType, ?string $name = null): void;

    public function profitShare(ProfitShareRequest $request): ProfitShareResult;

    public function queryProfitShare(string $outOrderNo, string $transactionId): ProfitShareResult;
}
