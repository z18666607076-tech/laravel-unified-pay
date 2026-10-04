<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('unified-pay.idempotency.table');
        $name = is_string($table) && $table !== '' ? $table : 'unified_pay_events';

        Schema::create($name, function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 32);
            $table->string('event_id', 191);
            $table->timestamp('processed_at')->useCurrent();
            $table->unique(['channel', 'event_id']);
        });
    }

    public function down(): void
    {
        $table = config('unified-pay.idempotency.table');
        $name = is_string($table) && $table !== '' ? $table : 'unified_pay_events';

        Schema::dropIfExists($name);
    }
};
