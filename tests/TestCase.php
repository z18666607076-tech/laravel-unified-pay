<?php

declare(strict_types=1);

namespace ZiwenZhao\UnifiedPay\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use ZiwenZhao\UnifiedPay\UnifiedPayServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [UnifiedPayServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('unified-pay.webhook.register', false);
        $app['config']->set('unified-pay.webhook.tolerance_seconds', 300);
        $app['config']->set('unified-pay.idempotency.store', 'array');
        $app['config']->set('unified-pay.idempotency.ttl', 3600);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
