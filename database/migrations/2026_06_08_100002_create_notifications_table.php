<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->nullable()
                ->constrained('notification_batches')
                ->nullOnDelete();

            $table->string('recipient');
            $table->string('channel');
            $table->text('content');
            $table->string('priority')->default('normal');
            $table->string('status')->default('pending');

            $table->string('idempotency_key')->nullable()->unique();
            $table->string('provider_message_id')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('channel');
            $table->index('created_at');
            $table->index('batch_id');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
