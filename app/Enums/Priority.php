<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Relative urgency of a notification, used for queue ordering.
 */
enum Priority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * Numeric weight where a higher value means more urgent. Useful for
     * sorting or mapping onto queue priorities.
     */
    public function weight(): int
    {
        return match ($this) {
            self::High => 30,
            self::Normal => 20,
            self::Low => 10,
        };
    }

    /**
     * The queue notifications of this priority are dispatched onto. Each queue
     * is served by its own Horizon supervisor (see config/horizon.php).
     */
    public function queue(): string
    {
        return match ($this) {
            self::High => 'notifications-high',
            self::Normal => 'notifications-normal',
            self::Low => 'notifications-low',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
