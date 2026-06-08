<?php

declare(strict_types=1);

use App\Http\Controllers\BatchController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications', [NotificationController::class, 'store'])->name('notifications.store');
    Route::post('/notifications/batch', [NotificationController::class, 'storeBatch'])->name('notifications.batch');
    Route::get('/notifications/{notification}', [NotificationController::class, 'show'])
        ->whereUuid('notification')->name('notifications.show');
    Route::post('/notifications/{notification}/cancel', [NotificationController::class, 'cancel'])
        ->whereUuid('notification')->name('notifications.cancel');

    Route::get('/batches/{batch}', [BatchController::class, 'show'])
        ->whereUuid('batch')->name('batches.show');
});
