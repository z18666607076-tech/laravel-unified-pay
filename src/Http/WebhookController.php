<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Http;

use Freeman\UnifiedPay\DTO\PaymentEvent;
use Freeman\UnifiedPay\Enums\PaymentEventType;
use Freeman\UnifiedPay\Events\PaymentClosed;
use Freeman\UnifiedPay\Events\PaymentFailed;
use Freeman\UnifiedPay\Events\PaymentSucceeded;
use Freeman\UnifiedPay\Events\RefundFailed;
use Freeman\UnifiedPay\Events\RefundSucceeded;
use Freeman\UnifiedPay\Exceptions\PaymentException;
use Freeman\UnifiedPay\Exceptions\SignatureException;
use Freeman\UnifiedPay\Idempotency\Idempotency;
use Freeman\UnifiedPay\PayManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class WebhookController
{
    public function __construct(
        private PayManager $pay,
        private Idempotency $idempotency,
        private Dispatcher $events,
    ) {}

    public function wechat(Request $request): JsonResponse
    {
        return $this->jsonChannel('wechat', $request, static fn (string $message, int $status): JsonResponse => response()->json([
            'code' => 'FAIL',
            'message' => $message,
        ], $status), response()->json([
            'code' => 'SUCCESS',
            'message' => 'OK',
        ]));
    }

    public function alipay(Request $request): Response
    {
        try {
            $this->handle('alipay', $request);
        } catch (SignatureException) {
            return response('failure', 400);
        } catch (PaymentException) {
            return response('failure', 400);
        } catch (Throwable) {
            return response('failure', 500);
        }

        return response('success', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function stripe(Request $request): JsonResponse
    {
        return $this->jsonChannel('stripe', $request, static fn (string $message, int $status): JsonResponse => response()->json([
            'message' => $message,
        ], $status), response()->json([
            'received' => true,
        ]));
    }

    /**
     * @param  callable(string, int): JsonResponse  $fail
     */
    private function jsonChannel(string $channel, Request $request, callable $fail, JsonResponse $ok): JsonResponse
    {
        try {
            $this->handle($channel, $request);
        } catch (SignatureException $exception) {
            return $fail($exception->getMessage(), 401);
        } catch (PaymentException $exception) {
            return $fail($exception->getMessage(), $exception->status >= 400 ? $exception->status : 400);
        } catch (Throwable) {
            return $fail('Could not process the notification.', 500);
        }

        return $ok;
    }

    private function handle(string $channel, Request $request): void
    {
        $event = $this->pay->driver($channel)->parseNotification(
            $request->getContent(),
            $request->headers->all(),
        );
        $this->dispatch($event);
    }

    private function dispatch(PaymentEvent $event): void
    {
        $this->idempotency->once($event->channel, $event->id, function () use ($event): void {
            $laravelEvent = match ($event->type) {
                PaymentEventType::PaymentSucceeded => new PaymentSucceeded($event),
                PaymentEventType::PaymentClosed => new PaymentClosed($event),
                PaymentEventType::PaymentFailed => new PaymentFailed($event),
                PaymentEventType::RefundSucceeded => new RefundSucceeded($event),
                PaymentEventType::RefundFailed => new RefundFailed($event),
                PaymentEventType::Ignored => null,
            };

            if ($laravelEvent !== null) {
                $this->events->dispatch($laravelEvent);
            }
        });
    }
}
