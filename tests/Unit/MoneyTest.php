<?php

declare(strict_types=1);

use ZiwenZhao\UnifiedPay\Money;

it('keeps money in minor units', function () {
    $money = Money::of(1234, 'cny');

    expect($money->amount)->toBe(1234)
        ->and($money->currency)->toBe('CNY')
        ->and($money->toDecimal())->toBe('12.34');
});

it('converts yuan strings without floats', function () {
    expect(Money::fromDecimal('1.00', 'CNY')->amount)->toBe(100)
        ->and(Money::fromDecimal('1.5', 'CNY')->amount)->toBe(150)
        ->and(Money::fromDecimal('100', 'JPY')->toDecimal())->toBe('100');
});

it('rejects negative amounts and extra decimals', function () {
    expect(fn () => Money::of(-1, 'CNY'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromDecimal('1.234', 'CNY'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(1, 'US'))->toThrow(InvalidArgumentException::class);
});
