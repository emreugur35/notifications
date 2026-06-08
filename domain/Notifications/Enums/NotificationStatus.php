<?php

declare(strict_types=1);

namespace Domain\Notifications\Enums;

/**
 * Lifecycle states of a notification record.
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Sent || $this === self::Failed;
    }
}
