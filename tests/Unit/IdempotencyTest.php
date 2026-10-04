<?php

declare(strict_types=1);

use Freeman\UnifiedPay\Facades\Pay;
use Freeman\UnifiedPay\Idempotency\CacheIdempotencyStore;
use Freeman\UnifiedPay\Idempotency\DatabaseIdempotencyStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;

it('runs a callback once and releases the claim when it throws', function () {
    $idempotency = Pay::idempotency();
    $runs = 0;

    $first = $idempotency->once('wechat', 'evt_1', function () use (&$runs): string {
        $runs++;

        return 'ok';
    });
    $second = $idempotency->once('wechat', 'evt_1', function () use (&$runs): string {
        $runs++;

        return 'again';
    });

    expect($first)->toBe('ok')
        ->and($second)->toBeNull()
        ->and($runs)->toBe(1);

    expect(fn () => $idempotency->once('wechat', 'evt_2', function (): never {
        throw new RuntimeException('listener failed');
    }))->toThrow(RuntimeException::class);

    $retried = $idempotency->once('wechat', 'evt_2', fn (): string => 'retried');

    expect($retried)->toBe('retried');
});

it('claims cache keys once until they are released', function () {
    $cache = app('cache');
    expect($cache)->toBeInstanceOf(CacheManager::class);
    $store = new CacheIdempotencyStore($cache);

    expect($store->claim('stripe', 'evt_cache', 60))->toBeTrue()
        ->and($store->claim('stripe', 'evt_cache', 60))->toBeFalse();

    $store->release('stripe', 'evt_cache');

    expect($store->claim('stripe', 'evt_cache', 60))->toBeTrue();
});

it('uses a unique database row as the idempotency claim', function () {
    config(['unified-pay.idempotency.store' => 'database']);
    $migration = include dirname(__DIR__, 2).'/database/migrations/2026_10_04_000000_create_unified_pay_events_table.php';
    $migration->up();

    $database = app('db');
    expect($database)->toBeInstanceOf(DatabaseManager::class);
    $store = new DatabaseIdempotencyStore($database, 'unified_pay_events');

    expect($store->claim('alipay', 'notify-1', 60))->toBeTrue()
        ->and($store->claim('alipay', 'notify-1', 60))->toBeFalse()
        ->and($store->claim('alipay', 'notify-2', 60))->toBeTrue();

    $store->release('alipay', 'notify-1');

    expect($store->claim('alipay', 'notify-1', 60))->toBeTrue();
});
