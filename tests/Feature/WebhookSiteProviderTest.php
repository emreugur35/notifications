<?php

declare(strict_types=1);

use App\Delivery\Exceptions\PermanentProviderException;
use App\Delivery\Exceptions\TransientProviderException;
use App\Delivery\Providers\WebhookSiteProvider;
use App\Enums\Channel;
use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function webhookProvider(): WebhookSiteProvider
{
    return new WebhookSiteProvider('https://webhook.site/test', 10.0, 5.0);
}

function makeNotification(Channel $channel = Channel::Sms): Notification
{
    return Notification::factory()->forChannel($channel)->make([
        'recipient' => '+15551234567',
        'content' => 'hello',
    ]);
}

it('returns a ProviderResult on a 2xx carrying a messageId', function () {
    Http::fake(['*' => Http::response(['messageId' => 'msg-abc-123'], 200)]);

    $result = webhookProvider()->send(makeNotification(Channel::Email));

    expect($result->messageId)->toBe('msg-abc-123')
        ->and($result->status)->toBe('sent')
        ->and($result->statusCode)->toBe(200)
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0)
        ->and($result->raw)->toMatchArray(['messageId' => 'msg-abc-123']);

    Http::assertSent(fn ($request) => $request['to'] === '+15551234567'
        && $request['channel'] === 'email'
        && $request['content'] === 'hello');
});

it('throws a permanent error on a 2xx without a messageId', function () {
    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

    expect(fn () => webhookProvider()->send(makeNotification()))
        ->toThrow(PermanentProviderException::class);
});

it('classifies 429 as transient', function () {
    Http::fake(['*' => Http::response(['error' => 'slow down'], 429)]);

    try {
        webhookProvider()->send(makeNotification());
        throw new RuntimeException('expected transient');
    } catch (TransientProviderException $e) {
        expect($e->statusCode)->toBe(429);
    }
});

it('classifies 5xx as transient', function () {
    Http::fake(['*' => Http::response('boom', 503)]);

    expect(fn () => webhookProvider()->send(makeNotification()))
        ->toThrow(TransientProviderException::class);
});

it('classifies 4xx (not 429) as permanent', function () {
    Http::fake(['*' => Http::response(['error' => 'bad request'], 422)]);

    try {
        webhookProvider()->send(makeNotification());
        throw new RuntimeException('expected permanent');
    } catch (PermanentProviderException $e) {
        expect($e->statusCode)->toBe(422);
    }
});

it('classifies a connection error as transient with status 0', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    try {
        webhookProvider()->send(makeNotification());
        throw new RuntimeException('expected transient');
    } catch (TransientProviderException $e) {
        expect($e->statusCode)->toBe(0)
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});
