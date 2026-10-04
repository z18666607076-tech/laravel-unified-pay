<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay;

use InvalidArgumentException;

/**
 * An amount in integer minor units, plus an ISO 4217 currency code.
 *
 * CNY 100 is 1.00 yuan. JPY 100 is 100 yen. Unknown currencies use two decimals.
 */
final readonly class Money
{
    /** @var array<string, int> */
    private const EXPONENTS = [
        'BHD' => 3,
        'CNY' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JPY' => 0,
        'KWD' => 3,
        'KRW' => 0,
        'USD' => 2,
    ];

    public string $currency;

    public function __construct(
        public int $amount,
        string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        $currency = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO code.');
        }

        $this->currency = $currency;
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function exponent(): int
    {
        return self::EXPONENTS[$this->currency] ?? 2;
    }

    public function toDecimal(): string
    {
        $exponent = $this->exponent();
        $factor = 10 ** $exponent;
        $major = intdiv($this->amount, $factor);
        $minor = $this->amount % $factor;

        if ($exponent === 0) {
            return (string) $major;
        }

        return $major.'.'.str_pad((string) $minor, $exponent, '0', STR_PAD_LEFT);
    }

    public static function fromDecimal(string $decimal, string $currency): self
    {
        $decimal = trim($decimal);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $decimal, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid decimal money ['.$decimal.'].');
        }

        $probe = new self(0, $currency);
        $exponent = $probe->exponent();
        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > $exponent) {
            throw new InvalidArgumentException('Too many decimal places for '.$probe->currency.'.');
        }

        $fraction = str_pad($fraction, $exponent, '0');
        $minor = $exponent === 0 ? 0 : (int) $fraction;
        $amount = ((int) $matches[1]) * (10 ** $exponent) + $minor;

        return new self($amount, $probe->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }
}
