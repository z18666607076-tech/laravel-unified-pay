<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Support;

use Freeman\UnifiedPay\Exceptions\PaymentException;

final class Values
{
    public static function int(mixed $value, string $label, string $channel): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new PaymentException($label.' is missing an amount.', $channel, null, 422);
    }

    /**
     * @return array<string, mixed>
     */
    public static function assoc(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    public static function seconds(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $default;
    }

    public static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function reference(string $value, string $label, string $channel): string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 64 || preg_match('/\s/', $value) === 1) {
            throw new PaymentException($label.' is empty or too long.', $channel, null, 422);
        }

        return $value;
    }
}
