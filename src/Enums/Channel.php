<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Enums;

enum Channel: string
{
    case Wechat = 'wechat';
    case Alipay = 'alipay';
    case Stripe = 'stripe';
}
