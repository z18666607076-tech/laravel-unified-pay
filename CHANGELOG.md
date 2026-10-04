# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-04

### Added

- `Pay::driver('wechat'|'alipay'|'stripe')` with create, query, close, refund, and query refund.
- Channel modes: WeChat JSAPI, mini program, Native, and H5; Alipay page, WAP, and app; Stripe PaymentIntent.
- Money as integer minor units plus an ISO currency.
- WeChat Pay API v3 request signing, platform response verification, and AES-256-GCM notification decrypt.
- Alipay Open Platform RSA2 request signing and response / notification verification.
- Stripe webhook HMAC verification and PaymentIntent / refund HTTP calls.
- Normalized `PaymentEvent` plus Laravel events: `PaymentSucceeded`, `PaymentClosed`, `PaymentFailed`, `RefundSucceeded`, `RefundFailed`.
- `Pay::routes()` webhook endpoints and an idempotency helper with cache, database, and array stores.
- Optional WeChat profit-sharing (`ProfitSharingGateway`): add receiver, create order, query order.
- `Pay::fake()` and call assertions for application tests.
- `WebhookFactory` for signed fixtures. Tests generate RSA keys at runtime.
- Config and migration publishing, service provider, and `Pay` facade.

[0.1.0]: https://github.com/z18666607076-tech/laravel-unified-pay/releases/tag/v0.1.0
