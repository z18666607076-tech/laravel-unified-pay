# Contributing

Thanks for looking at laravel-unified-pay. Issues and pull requests are welcome.

## Local setup

Requirements: PHP 8.3 or newer (`ext-openssl`, `ext-mbstring`, `ext-json`), Composer 2.

```bash
composer install
composer check
```

`composer check` runs Pint, PHPStan (Larastan level 9), and Pest.

You can run the pieces separately:

```bash
vendor/bin/pint
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pest
```

The CI matrix installs Laravel 11, 12, and 13 on PHP 8.3, 8.4, and 8.5 via Orchestra Testbench. Pest 5 is used only where it is supported (PHP 8.4+ and Laravel 13). Older cells use Pest 3 or 4. See `.github/workflows/tests.yml`.

## Tests

Do not commit merchant certificates, API keys, or webhook secrets. Cryptographic tests generate RSA keys with `openssl_pkey_new()` and use fixed fake API v3 / webhook secrets that are not real credentials.

Prefer a failing test that shows the protocol rule (signature, decrypt, amount, idempotency) before changing a driver.

## Commits

Use [Conventional Commits](https://www.conventionalcommits.org/):

```text
feat(wechat): verify platform response signatures
fix(alipay): exclude sign_type when verifying notifications
test(stripe): reject webhook timestamps outside the tolerance window
```

## Compatibility

The package supports PHP `^8.3` and `illuminate/*` `^11.44 || ^12.4 || ^13.0`. Avoid syntax that needs PHP 8.4 or newer (property hooks, `array_find`, and so on).

Public DTOs and the `Gateway` interface are the stable surface for 0.x. Breaking changes belong in a minor bump until 1.0, and should be called out in `CHANGELOG.md`.
