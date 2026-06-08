<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Dispatches notifications whose scheduled_at has come due. Registered to run
 * every minute by the scheduler (see routes/console.php), so a notification
 * created with a future scheduled_at stays pending until its time arrives.
 */
class DispatchScheduledNotifications extends Command
{
    protected $signature = 'notifications:dispatch-scheduled';

    protected $description = 'Dispatch pending notifications whose scheduled_at is now due.';

    public function handle(NotificationService $service): int
    {
        $dispatched = $service->dispatchDue();

        $this->info("Dispatched {$dispatched} due notification(s).");

        return self::SUCCESS;
    }
}
