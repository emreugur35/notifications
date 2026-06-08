<?php

declare(strict_types=1);

use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Context;

it('publishes an inbound correlation id to Context (for logs + jobs)', function () {
    $request = Request::create('/api/v1/health');
    $request->headers->set(AssignCorrelationId::HEADER, 'corr-inbound');

    $response = (new AssignCorrelationId)->handle($request, fn (Request $r): Response => new Response('ok'));

    expect(Context::get(AssignCorrelationId::ATTRIBUTE))->toBe('corr-inbound')
        ->and($response->headers->get(AssignCorrelationId::HEADER))->toBe('corr-inbound');
});

it('generates a correlation id when none is supplied', function () {
    $request = Request::create('/api/v1/health');

    (new AssignCorrelationId)->handle($request, fn (Request $r): Response => new Response('ok'));

    expect(Context::get(AssignCorrelationId::ATTRIBUTE))->toBeString()->not->toBeEmpty();
});

it('echoes the correlation id on responses end to end', function () {
    $this->withHeader(AssignCorrelationId::HEADER, 'corr-e2e')
        ->getJson('/api/v1/health')
        ->assertHeader(AssignCorrelationId::HEADER, 'corr-e2e');
});
