<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Stripe;

use Freeman\UnifiedPay\Exceptions\ConfigurationException;
use Freeman\UnifiedPay\Exceptions\SignatureException;
use Freeman\UnifiedPay\Support\Json;
use Freeman\UnifiedPay\Support\Values;

final class WebhookVerifier
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $payload, string $header, string $secret, ?int $now = null): array
    {
        if ($secret === '') {
            throw new ConfigurationException('Stripe webhook secret is not configured.', 'stripe');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pieces = explode('=', trim($part), 2);

            if (count($pieces) !== 2) {
                continue;
            }

            if ($pieces[0] === 't') {
                $timestamp = $pieces[1];
            }

            if ($pieces[0] === 'v1') {
                $signatures[] = $pieces[1];
            }
        }

        if ($timestamp === null || $signatures === [] || preg_match('/^\d+$/', $timestamp) !== 1) {
            throw new SignatureException('Stripe webhook signature header is invalid.', 'stripe');
        }

        $now ??= time();
        $window = Values::seconds(config('unified-pay.webhook.tolerance_seconds'), 300);

        if (abs($now - (int) $timestamp) > $window) {
            throw new SignatureException('Stripe webhook timestamp is outside the tolerance window.', 'stripe');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = false;

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
                break;
            }
        }

        if (! $valid) {
            throw new SignatureException('Stripe webhook signature does not match.', 'stripe');
        }

        return Json::decodeObject($payload, 'Stripe webhook payload is not JSON.');
    }
}
