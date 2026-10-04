<?php

declare(strict_types=1);

namespace Freeman\UnifiedPay\Idempotency;

use Freeman\UnifiedPay\Contracts\IdempotencyStore;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private DatabaseManager $database,
        private string $table = 'unified_pay_events',
    ) {}

    public function claim(string $channel, string $eventId, int $ttlSeconds): bool
    {
        try {
            $this->database->table($this->table)->insert([
                'channel' => $channel,
                'event_id' => $eventId,
                'processed_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (QueryException $exception) {
            if ($this->isDuplicate($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    public function release(string $channel, string $eventId): void
    {
        $this->database->table($this->table)
            ->where('channel', $channel)
            ->where('event_id', $eventId)
            ->delete();
    }

    private function isDuplicate(Throwable $exception): bool
    {
        $code = (string) $exception->getCode();

        if ($code === '23000' || $code === '23505') {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
