<?php

declare(strict_types=1);

use Freeman\UnifiedPay\Exceptions\ConfigurationException;
use Freeman\UnifiedPay\Support\KeyMaterial;
use Freeman\UnifiedPay\Tests\Support\RsaKeyPair;

it('loads a pem, a raw base64 key, and a file path', function () {
    $keys = RsaKeyPair::generate();

    expect(KeyMaterial::privateKey($keys->privatePem, 'private'))->toContain('PRIVATE KEY')
        ->and(openssl_pkey_get_private(KeyMaterial::privateKey($keys->privateBase64(), 'private')))->not->toBeFalse();

    $path = tempnam(sys_get_temp_dir(), 'pay-key');
    expect($path)->toBeString();
    file_put_contents($path, $keys->publicPem);

    expect(KeyMaterial::publicKey($path, 'public'))->toContain('PUBLIC KEY');
    unlink($path);
});

it('rejects an empty key', function () {
    expect(fn () => KeyMaterial::privateKey('   ', 'private'))->toThrow(ConfigurationException::class);
});
