<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Enums;

enum PaymentMode: string
{
    case WechatJsapi = 'wechat_jsapi';
    case WechatMiniProgram = 'wechat_mini_program';
    case WechatNative = 'wechat_native';
    case WechatH5 = 'wechat_h5';
    case AlipayPage = 'alipay_page';
    case AlipayWap = 'alipay_wap';
    case AlipayApp = 'alipay_app';
    case StripePaymentIntent = 'stripe_payment_intent';

    public function channel(): Channel
    {
        return match ($this) {
            self::WechatJsapi, self::WechatMiniProgram, self::WechatNative, self::WechatH5 => Channel::Wechat,
            self::AlipayPage, self::AlipayWap, self::AlipayApp => Channel::Alipay,
            self::StripePaymentIntent => Channel::Stripe,
        };
    }
}
