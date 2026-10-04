<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\DTO;

final readonly class ProfitShareReceiver
{
    public function __construct(
        public string $type,
        public string $account,
        public int $amount,
        public string $description,
        public ?string $name = null,
    ) {}
}
