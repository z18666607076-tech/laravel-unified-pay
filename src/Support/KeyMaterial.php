<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Support;

use ZiwenZhao\UnifiedPay\Exceptions\ConfigurationException;

final class KeyMaterial
{
    public static function privateKey(string $value, string $label): string
    {
        return self::load($value, $label, private: true);
    }

    public static function publicKey(string $value, string $label): string
    {
        return self::load($value, $label, private: false);
    }

    private static function load(string $value, string $label, bool $private): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new ConfigurationException($label.' is not configured.');
        }

        if (str_contains($value, 'BEGIN')) {
            $pem = str_contains($value, "\n") ? $value : str_replace('\\n', "\n", $value);
            self::assertOpens($pem, $label, $private);

            return $pem;
        }

        if (is_file($value)) {
            $contents = file_get_contents($value);

            if ($contents === false || trim($contents) === '') {
                throw new ConfigurationException($label.' could not be read.');
            }

            return self::load($contents, $label, $private);
        }

        $base64 = preg_replace('/\s+/', '', $value) ?? '';

        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $base64) !== 1) {
            throw new ConfigurationException($label.' must be a PEM string, a file path, or a base64 key.');
        }

        $wrapped = self::wrap($base64, $private ? 'PRIVATE KEY' : 'PUBLIC KEY');

        if (self::opens($wrapped, $private)) {
            return $wrapped;
        }

        if ($private) {
            $pkcs1 = self::wrap($base64, 'RSA PRIVATE KEY');

            if (self::opens($pkcs1, true)) {
                return $pkcs1;
            }
        }

        throw new ConfigurationException($label.' is not a valid key.');
    }

    private static function wrap(string $base64, string $label): string
    {
        return '-----BEGIN '.$label."-----\n".trim(chunk_split($base64, 64, "\n"))."\n-----END ".$label."-----\n";
    }

    private static function assertOpens(string $pem, string $label, bool $private): void
    {
        if (! self::opens($pem, $private)) {
            throw new ConfigurationException($label.' is not a valid key.');
        }
    }

    private static function opens(string $pem, bool $private): bool
    {
        $key = $private ? openssl_pkey_get_private($pem) : openssl_pkey_get_public($pem);

        return $key !== false;
    }
}
