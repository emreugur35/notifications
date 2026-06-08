<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Support\Content\ContentValidationException;
use App\Support\Content\ContentValidator;
use App\Support\Sms\SmsSegmenter;

function contentValidator(): ContentValidator
{
    return new ContentValidator(new SmsSegmenter);
}

it('requires a subject and body for email', function () {
    expect(fn () => contentValidator()->validate(Channel::Email, ['content' => 'Body']))
        ->toThrow(ContentValidationException::class);

    expect(contentValidator()->validate(Channel::Email, ['subject' => 'Hi', 'content' => 'Body']))
        ->toBe([]);
});

it('requires a title and body for push', function () {
    expect(fn () => contentValidator()->validate(Channel::Push, ['content' => 'Body']))
        ->toThrow(ContentValidationException::class);

    expect(contentValidator()->validate(Channel::Push, ['title' => 'Hi', 'content' => 'Body']))
        ->toBe([]);
});

it('rejects empty sms content and returns segmentation for valid sms', function () {
    expect(fn () => contentValidator()->validate(Channel::Sms, ['content' => '   ']))
        ->toThrow(ContentValidationException::class);

    $meta = contentValidator()->validate(Channel::Sms, ['content' => 'Hello']);

    expect($meta)->toHaveKey('sms')
        ->and($meta['sms']['segments'])->toBe(1)
        ->and($meta['sms']['encoding'])->toBe('GSM-7');
});

it('reports the offending field in the exception', function () {
    try {
        contentValidator()->validate(Channel::Email, ['content' => 'Body']);
        throw new RuntimeException('Expected ContentValidationException');
    } catch (ContentValidationException $e) {
        expect($e->errors())->toHaveKey('subject');
    }
});
