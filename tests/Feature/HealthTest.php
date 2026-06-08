<?php

declare(strict_types=1);

it('returns 200 from the health endpoint', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'notifications',
        ]);
});
