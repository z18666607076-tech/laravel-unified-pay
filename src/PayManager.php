<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay;

use Freeman\UnifiedPay\Alipay\Gateway as AlipayGateway;
use Freeman\UnifiedPay\Contracts\Gateway;
use Freeman\UnifiedPay\DTO\CreatePayment;
use Freeman\UnifiedPay\DTO\CreateRefund;
use Freeman\UnifiedPay\DTO\ProfitShareRequest;
use Freeman\UnifiedPay\Enums\Channel;
use Freeman\UnifiedPay\Exceptions\AssertionFailedException;
use Freeman\UnifiedPay\Fake\FakeGateway;
use Freeman\UnifiedPay\Fake\FakeWechatGateway;
use Freeman\UnifiedPay\Fake\Recorder;
use Freeman\UnifiedPay\Http\WebhookController;
use Freeman\UnifiedPay\Idempotency\Idempotency;
use Freeman\UnifiedPay\Stripe\Gateway as StripeGateway;
use Freeman\UnifiedPay\Wechat\Gateway as WechatGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use InvalidArgumentException;

final class PayManager
{
    /** @var array<string, Gateway> */
    private array $drivers = [];

    private bool $fakeAll = false;

    /** @var array<string, true> */
    private array $faked = [];

    private ?Recorder $recorder = null;

    private bool $routesRegistered = false;

    public function __construct(private Application $app) {}

    public function driver(string|Channel $channel): Gateway
    {
        $name = $this->name($channel);

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        if ($this->fakeAll || isset($this->faked[$name])) {
            return $this->drivers[$name] = $name === Channel::Wechat->value
                ? new FakeWechatGateway($this->recorder())
                : new FakeGateway($name, $this->recorder());
        }

        return $this->drivers[$name] = match ($name) {
            Channel::Wechat->value => $this->app->make(WechatGateway::class),
            Channel::Alipay->value => $this->app->make(AlipayGateway::class),
            Channel::Stripe->value => $this->app->make(StripeGateway::class),
            default => throw new InvalidArgumentException('Unknown payment channel ['.$name.'].'),
        };
    }

    /**
     * @param  string|array<int, string>|null  $channels
     */
    public function fake(string|array|null $channels = null): void
    {
        $this->drivers = [];
        $this->recorder = new Recorder;

        if ($channels === null) {
            $this->fakeAll = true;
            $this->faked = [];

            return;
        }

        $this->fakeAll = false;
        $this->faked = [];

        foreach ((array) $channels as $channel) {
            $this->faked[$this->name($channel)] = true;
        }
    }

    public function idempotency(): Idempotency
    {
        return $this->app->make(Idempotency::class);
    }

    /**
     * @param  array{prefix?: string, middleware?: string|array<int, string>, name?: string}  $options
     */
    public function routes(array $options = []): void
    {
        if ($this->routesRegistered) {
            return;
        }

        $this->routesRegistered = true;
        $router = $this->app->make('router');
        $prefix = $options['prefix'] ?? config('unified-pay.webhook.prefix', 'unified-pay');
        $middleware = $options['middleware'] ?? config('unified-pay.webhook.middleware', ['api']);
        $name = $options['name'] ?? config('unified-pay.webhook.name', 'unified-pay');

        $router->group([
            'prefix' => is_string($prefix) ? $prefix : 'unified-pay',
            'middleware' => is_string($middleware) || is_array($middleware) ? $middleware : ['api'],
        ], function (Router $router) use ($name): void {
            $as = is_string($name) ? $name : 'unified-pay';
            $router->post('wechat', [WebhookController::class, 'wechat'])->name($as.'.wechat');
            $router->post('alipay', [WebhookController::class, 'alipay'])->name($as.'.alipay');
            $router->post('stripe', [WebhookController::class, 'stripe'])->name($as.'.stripe');
        });
    }

    /**
     * @param  (callable(CreatePayment): bool)|null  $callback
     */
    public function assertCreated(string $channel, ?callable $callback = null): void
    {
        if ($this->created($channel, $callback) === []) {
            throw new AssertionFailedException('No payment was created on ['.$channel.'].');
        }
    }

    public function assertCreatedTimes(string $channel, int $times): void
    {
        $count = count($this->created($channel, null));

        if ($count !== $times) {
            throw new AssertionFailedException('Expected '.$times.' payments on ['.$channel.'], got '.$count.'.');
        }
    }

    public function assertNothingCreated(): void
    {
        if ($this->recorder()->created !== []) {
            throw new AssertionFailedException('Payments were created.');
        }
    }

    /**
     * @param  (callable(CreateRefund): bool)|null  $callback
     */
    public function assertRefunded(string $channel, ?callable $callback = null): void
    {
        $matched = false;

        foreach ($this->recorder()->refunded as $call) {
            if ($call['channel'] !== strtolower($channel)) {
                continue;
            }

            if ($callback === null || $callback($call['refund'])) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw new AssertionFailedException('No refund was created on ['.$channel.'].');
        }
    }

    public function assertQueried(string $channel, ?string $outTradeNo = null): void
    {
        foreach ($this->recorder()->queried as $call) {
            if ($call['channel'] !== strtolower($channel)) {
                continue;
            }

            if ($outTradeNo === null || $call['outTradeNo'] === $outTradeNo) {
                return;
            }
        }

        throw new AssertionFailedException('No query was recorded on ['.$channel.'].');
    }

    public function assertClosed(string $channel, ?string $outTradeNo = null): void
    {
        foreach ($this->recorder()->closed as $call) {
            if ($call['channel'] !== strtolower($channel)) {
                continue;
            }

            if ($outTradeNo === null || $call['outTradeNo'] === $outTradeNo) {
                return;
            }
        }

        throw new AssertionFailedException('No close was recorded on ['.$channel.'].');
    }

    /**
     * @param  (callable(ProfitShareRequest): bool)|null  $callback
     */
    public function assertProfitShared(string $channel, ?callable $callback = null): void
    {
        foreach ($this->recorder()->profitShares as $call) {
            if ($call['channel'] !== strtolower($channel)) {
                continue;
            }

            if ($callback === null || $callback($call['request'])) {
                return;
            }
        }

        throw new AssertionFailedException('No profit-sharing request was recorded on ['.$channel.'].');
    }

    private function recorder(): Recorder
    {
        if ($this->recorder === null) {
            throw new AssertionFailedException('Call Pay::fake() before recording or asserting payment calls.');
        }

        return $this->recorder;
    }

    private function name(string|Channel $channel): string
    {
        $name = $channel instanceof Channel ? $channel->value : strtolower($channel);
        $resolved = Channel::tryFrom($name);

        if ($resolved === null) {
            throw new InvalidArgumentException('Unknown payment channel ['.$name.'].');
        }

        return $resolved->value;
    }

    /**
     * @param  (callable(CreatePayment): bool)|null  $callback
     * @return list<array{channel: string, payment: CreatePayment}>
     */
    private function created(string $channel, ?callable $callback): array
    {
        $matched = [];

        foreach ($this->recorder()->created as $call) {
            if ($call['channel'] !== strtolower($channel)) {
                continue;
            }

            if ($callback === null || $callback($call['payment'])) {
                $matched[] = $call;
            }
        }

        return $matched;
    }
}
