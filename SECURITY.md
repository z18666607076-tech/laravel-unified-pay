# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x | Yes |

## Reporting a vulnerability

Please use a private GitHub security advisory on this repository:

https://github.com/z18666607076-tech/laravel-unified-pay/security/advisories/new

Do not open a public issue for a signature bypass, webhook replay, key-handling bug, or anything else that would let an attacker forge a payment or refund notification.

Include a short description, the affected version, and a Pest test or request fixture if you can share one without embedding live secrets.

## What this package already checks

- WeChat Pay API v3: request signatures, platform response signatures, notification signatures, serial match, timestamp tolerance, and AES-256-GCM decrypt.
- Alipay: RSA2 on the request string, on the raw gateway response object, and on async notifications (`sign` and `sign_type` removed before verify).
- Stripe: `Stripe-Signature` HMAC-SHA256 with timestamp tolerance.
- Webhook handlers claim an idempotency key before dispatching Laravel events, and release it if a listener throws so the provider can retry.

You still need HTTPS, a private key that is not in git, and a durable idempotency store (`database`) in production. The cache store expires.
