<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Support;

final class Headers
{
    /**
     * @param  array<string, string|array<int, string|null>|null>  $headers
     */
    public static function get(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $first = $value[0] ?? null;

                return is_string($first) ? $first : '';
            }

            return is_string($value) ? $value : '';
        }

        return '';
    }
}
