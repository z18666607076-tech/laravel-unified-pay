<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Support;

use JsonException;
use ZiwenZhao\UnifiedPay\Exceptions\PaymentException;

final class Json
{
    /**
     * @param  array<string, mixed>  $value
     */
    public static function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PaymentException('Could not encode the payment request: '.$exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeObject(string $json, string $error): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PaymentException($error);
        }

        if (! is_array($decoded)) {
            throw new PaymentException($error);
        }

        $normalized = [];

        foreach ($decoded as $key => $item) {
            if (! is_string($key)) {
                throw new PaymentException($error);
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
