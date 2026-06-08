<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Delivery channel a notification is sent through.
 */
enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';

    /**
     * The queue this channel's notifications are processed on.
     */
    public function queue(): string
    {
        return match ($this) {
            self::Sms => 'notifications-sms',
            self::Email => 'notifications-email',
            self::Push => 'notifications-push',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::Push => 'Push',
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
