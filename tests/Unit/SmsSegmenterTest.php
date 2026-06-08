<?php

declare(strict_types=1);

use App\Support\Sms\SmsSegmenter;

it('treats a short ascii message as a single GSM-7 segment', function () {
    $result = (new SmsSegmenter)->segment('Hello world');

    expect($result->encoding)->toBe('GSM-7')
        ->and($result->length)->toBe(11)
        ->and($result->segments)->toBe(1)
        ->and($result->perSegment)->toBe(160);
});

it('counts GSM-7 segments at 153 chars each when concatenated', function () {
    expect((new SmsSegmenter)->segment(str_repeat('a', 160))->segments)->toBe(1)
        ->and((new SmsSegmenter)->segment(str_repeat('a', 161))->segments)->toBe(2)
        ->and((new SmsSegmenter)->segment(str_repeat('a', 306))->segments)->toBe(2)
        ->and((new SmsSegmenter)->segment(str_repeat('a', 307))->segments)->toBe(3);
});

it('falls back to UCS-2 for non-GSM characters at 67 chars/segment', function () {
    $single = (new SmsSegmenter)->segment(str_repeat('д', 70)); // Cyrillic -> UCS-2
    $multi = (new SmsSegmenter)->segment(str_repeat('д', 71));

    expect($single->encoding)->toBe('UCS-2')
        ->and($single->segments)->toBe(1)
        ->and($single->perSegment)->toBe(70)
        ->and($multi->segments)->toBe(2)
        ->and($multi->perSegment)->toBe(67);
});
