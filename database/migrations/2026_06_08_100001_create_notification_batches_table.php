<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();

            // Totals
            $table->unsignedInteger('total_count')->default(0);

            // Per-status counts
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('processing_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_batches');
    }
};
