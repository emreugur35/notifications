<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a notification (and of an individual delivery attempt).
 */
enum Status: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether this is a final state that will not transition further.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Whether the notification was handed off or confirmed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Sent || $this === self::Delivered;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
