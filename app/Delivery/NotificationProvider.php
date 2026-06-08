<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Delivery\Exceptions\PermanentProviderException;
use App\Delivery\Exceptions\TransientProviderException;
use App\Models\Notification;

/**
 * Contract for a delivery provider. Implementations own the transport and the
 * success/failure classification: a successful delivery returns a
 * {@see ProviderResult}, while failures are thrown as transient (retryable) or
 * permanent (fail-fast) exceptions. The job never inspects HTTP status codes.
 */
interface NotificationProvider
{
    /**
     * @throws TransientProviderException retryable failure (connection, 429, 5xx)
     * @throws PermanentProviderException fail-fast failure (4xx, or 2xx without a messageId)
     */
    public function send(Notification $notification): ProviderResult;
}
