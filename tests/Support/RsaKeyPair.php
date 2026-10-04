<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Tests\Support;

use RuntimeException;

final class RsaKeyPair
{
    private static ?self $merchant = null;

    private static ?self $platform = null;

    public function __construct(
        public string $privatePem,
        public string $publicPem,
    ) {}

    public static function merchant(): self
    {
        return self::$merchant ??= self::generate();
    }

    public static function platform(): self
    {
        return self::$platform ??= self::generate();
    }

    public static function generate(): self
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate an RSA key.');
        }

        $private = '';

        if (! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Could not export an RSA key.');
        }

        $details = openssl_pkey_get_details($key);
        $public = is_array($details) ? ($details['key'] ?? null) : null;

        if (! is_string($public) || $public === '') {
            throw new RuntimeException('Could not read the RSA public key.');
        }

        return new self($private, $public);
    }

    public function privateBase64(): string
    {
        $body = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $this->privatePem);

        if (! is_string($body) || $body === '') {
            throw new RuntimeException('Could not strip the private key.');
        }

        return $body;
    }
}
