<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Wechat;

use Freeman\UnifiedPay\Exceptions\ConfigurationException;
use Freeman\UnifiedPay\Exceptions\SignatureException;

/**
 * AEAD_AES_256_GCM as used by WeChat Pay API v3 notifications.
 * The ciphertext field is base64(ciphertext || 16-byte tag).
 */
final class Cipher
{
    public function encrypt(string $apiV3Key, string $nonce, string $associatedData, string $plaintext): string
    {
        $this->assertKey($apiV3Key);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $apiV3Key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData,
            16,
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new SignatureException('Could not encrypt the WeChat Pay payload.', 'wechat');
        }

        return base64_encode($ciphertext.$tag);
    }

    public function decrypt(string $apiV3Key, string $nonce, string $associatedData, string $ciphertext): string
    {
        $this->assertKey($apiV3Key);
        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false || strlen($decoded) <= 16) {
            throw new SignatureException('Could not decrypt the WeChat Pay notification.', 'wechat');
        }

        $tag = substr($decoded, -16);
        $data = substr($decoded, 0, -16);
        $plain = openssl_decrypt(
            $data,
            'aes-256-gcm',
            $apiV3Key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associatedData,
        );

        if ($plain === false) {
            throw new SignatureException('Could not decrypt the WeChat Pay notification.', 'wechat');
        }

        return $plain;
    }

    private function assertKey(string $apiV3Key): void
    {
        if (strlen($apiV3Key) !== 32) {
            throw new ConfigurationException('WeChat Pay APIv3 key must be 32 bytes.', 'wechat');
        }
    }
}
