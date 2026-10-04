<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

final readonly class ProfitShareRequest
{
    /**
     * @param  list<ProfitShareReceiver>  $receivers
     */
    public function __construct(
        public string $outOrderNo,
        public string $transactionId,
        public array $receivers,
        public bool $unfreezeUnsplit = true,
    ) {}
}
