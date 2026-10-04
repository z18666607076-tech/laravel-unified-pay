<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Alipay;

use Freeman\UnifiedPay\Exceptions\ConfigurationException;
use Freeman\UnifiedPay\Exceptions\PaymentException;
use Freeman\UnifiedPay\Exceptions\SignatureException;

/**
 * Alipay Open Platform RSA2 (SHA256WithRSA).
 *
 * Request signatures include sign_type and exclude sign.
 * Async notifications are verified after removing both sign and sign_type.
 * Gateway responses are verified against the raw JSON object, not a rebuilt string.
 */
final class Signer
{
    /**
     * @param  array<string, string>  $params
     */
    public function sign(array $params, string $privateKeyPem): string
    {
        return $this->signMessage($this->canonical($params), $privateKeyPem);
    }

    public function signMessage(string $message, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new ConfigurationException('Alipay private key is invalid.', 'alipay');
        }

        $signature = '';

        if (! openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PaymentException('Could not sign the Alipay request.', 'alipay');
        }

        return base64_encode($signature);
    }

    public function verify(string $message, string $signatureBase64, string $publicKeyPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        $raw = base64_decode($signatureBase64, true);

        if ($key === false || $raw === false) {
            return false;
        }

        return openssl_verify($message, $raw, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @param  array<string, string>  $params
     */
    public function canonical(array $params, bool $excludeSignType = false): string
    {
        $filtered = [];

        foreach ($params as $key => $value) {
            if ($key === 'sign' || ($excludeSignType && $key === 'sign_type')) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $filtered[$key] = $value;
        }

        ksort($filtered);
        $pairs = [];

        foreach ($filtered as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return implode('&', $pairs);
    }

    public function extractObject(string $raw, string $node): string
    {
        $needle = '"'.$node.'"';
        $position = strpos($raw, $needle);

        if ($position === false) {
            throw new SignatureException('Alipay response is missing '.$node.'.', 'alipay');
        }

        $start = strpos($raw, '{', $position);

        if ($start === false) {
            throw new SignatureException('Alipay response is malformed.', 'alipay');
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($raw);

        for ($index = $start; $index < $length; $index++) {
            $char = $raw[$index];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }

                if ($char === '\\') {
                    $escape = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $index - $start + 1);
                }
            }
        }

        throw new SignatureException('Alipay response is malformed.', 'alipay');
    }
}
