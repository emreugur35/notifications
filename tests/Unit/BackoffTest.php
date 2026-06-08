<?php

declare(strict_types=1);

use App\Support\Retry\Backoff;

it('returns the five-step escalating schedule', function () {
    $schedule = (new Backoff)->schedule();

    expect($schedule)->toHaveCount(5);

    // Strictly increasing across steps.
    for ($i = 1; $i < count($schedule); $i++) {
        expect($schedule[$i])->toBeGreaterThan($schedule[$i - 1]);
    }
});

it('keeps each step within its base..+20% jitter window', function () {
    // Sample repeatedly so the random jitter is exercised.
    for ($run = 0; $run < 30; $run++) {
        $schedule = (new Backoff)->schedule();

        foreach (Backoff::SCHEDULE as $index => $base) {
            $maxJitter = (int) round($base * Backoff::JITTER);

            expect($schedule[$index])->toBeGreaterThanOrEqual($base)
                ->toBeLessThanOrEqual($base + $maxJitter);
        }
    }
});
