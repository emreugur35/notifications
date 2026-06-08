<?php

declare(strict_types=1);

namespace App\Providers;

use App\Delivery\CircuitBreaker;
use App\Delivery\NotificationProvider;
use App\Delivery\Providers\WebhookSiteProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Notifications domain. Binds the delivery provider
 * and the per-channel circuit breaker.
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationProvider::class, function (Application $app): WebhookSiteProvider {
            /** @var array{url: string, timeout: int|float, connect_timeout: int|float} $config */
            $config = $app->make('config')->get('services.webhook');

            return new WebhookSiteProvider(
                url: $config['url'],
                timeout: (float) $config['timeout'],
                connectTimeout: (float) $config['connect_timeout'],
            );
        });

        $this->app->singleton(CircuitBreaker::class, function (Application $app): CircuitBreaker {
            /** @var array{threshold: int, cooldown: int, failure_ttl: int} $config */
            $config = $app->make('config')->get('services.circuit_breaker');

            return new CircuitBreaker(
                threshold: (int) $config['threshold'],
                cooldownSeconds: (int) $config['cooldown'],
                failureTtlSeconds: (int) $config['failure_ttl'],
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
