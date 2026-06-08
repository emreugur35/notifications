<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('notification_id')
                ->constrained('notifications')
                ->cascadeOnDelete();

            $table->unsignedInteger('attempt_number');
            $table->string('status');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['notification_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
