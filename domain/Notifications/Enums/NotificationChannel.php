<?php

declare(strict_types=1);

namespace Domain\Notifications\Enums;

/**
 * Delivery channels a notification can be dispatched through.
 */
enum NotificationChannel: string
{
    case Mail = 'mail';
    case Sms = 'sms';
    case Push = 'push';
    case Database = 'database';

    /**
     * The Horizon/queue connection-agnostic queue name for this channel.
     */
    public function queue(): string
    {
        return match ($this) {
            self::Mail => 'notifications-mail',
            self::Sms => 'notifications-sms',
            self::Push => 'notifications-push',
            self::Database => 'notifications-default',
        };
    }
}
