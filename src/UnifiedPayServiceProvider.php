<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use ZiwenZhao\UnifiedPay\Contracts\IdempotencyStore;
use ZiwenZhao\UnifiedPay\Exceptions\ConfigurationException;
use ZiwenZhao\UnifiedPay\Idempotency\ArrayIdempotencyStore;
use ZiwenZhao\UnifiedPay\Idempotency\CacheIdempotencyStore;
use ZiwenZhao\UnifiedPay\Idempotency\DatabaseIdempotencyStore;
use ZiwenZhao\UnifiedPay\Idempotency\Idempotency;

class UnifiedPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/unified-pay.php', 'unified-pay');

        $this->app->singleton(PayManager::class);
        $this->app->alias(PayManager::class, 'unified-pay');

        $this->app->singleton(IdempotencyStore::class, function (): IdempotencyStore {
            $store = config('unified-pay.idempotency.store');
            $name = is_string($store) ? $store : 'cache';

            return match ($name) {
                'database' => new DatabaseIdempotencyStore(
                    $this->database(),
                    $this->table(),
                ),
                'array' => new ArrayIdempotencyStore,
                'cache' => new CacheIdempotencyStore($this->cache(), $this->cacheStore()),
                default => throw new ConfigurationException('Unknown idempotency store ['.$name.'].'),
            };
        });

        $this->app->singleton(Idempotency::class, function (): Idempotency {
            $ttl = config('unified-pay.idempotency.ttl');

            return new Idempotency(
                $this->app->make(IdempotencyStore::class),
                is_int($ttl) && $ttl > 0 ? $ttl : 604800,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/unified-pay.php' => $this->app->configPath('unified-pay.php'),
            ], 'unified-pay-config');

            $this->publishes([
                __DIR__.'/../database/migrations/2026_10_04_000000_create_unified_pay_events_table.php' => $this->app->databasePath('migrations/2026_10_04_000000_create_unified_pay_events_table.php'),
            ], 'unified-pay-migrations');
        }

        if (config('unified-pay.webhook.register') === true) {
            $this->app->booted(function (): void {
                $this->app->make(PayManager::class)->routes();
            });
        }
    }

    private function database(): DatabaseManager
    {
        return $this->app->make('db');
    }

    private function cache(): CacheManager
    {
        return $this->app->make('cache');
    }

    private function table(): string
    {
        $table = config('unified-pay.idempotency.table');

        return is_string($table) && $table !== '' ? $table : 'unified_pay_events';
    }

    private function cacheStore(): ?string
    {
        $store = config('unified-pay.idempotency.cache_store');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
