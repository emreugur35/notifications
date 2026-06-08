<?php

declare(strict_types=1);

namespace App\Support\Content;

use App\Enums\Channel;
use App\Support\Sms\SmsSegmenter;

/**
 * Validates notification content per channel and returns metadata additions
 * to persist (e.g. SMS segmentation).
 */
class ContentValidator
{
    public function __construct(private readonly SmsSegmenter $segmenter) {}

    /**
     * @param  array<string, mixed>  $payload  keys: content, subject, title
     * @return array<string, mixed> metadata additions
     *
     * @throws ContentValidationException
     */
    public function validate(Channel $channel, array $payload): array
    {
        return match ($channel) {
            Channel::Sms => $this->sms($payload),
            Channel::Email => $this->email($payload),
            Channel::Push => $this->push($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sms(array $payload): array
    {
        $content = $this->string($payload, 'content');

        if ($content === '') {
            throw new ContentValidationException(['content' => ['SMS content must not be empty.']]);
        }

        return ['sms' => $this->segmenter->segment($content)->toArray()];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function email(array $payload): array
    {
        $errors = [];

        if ($this->string($payload, 'subject') === '') {
            $errors['subject'] = ['Email notifications require a subject.'];
        }

        if ($this->string($payload, 'content') === '') {
            $errors['content'] = ['Email notifications require a body.'];
        }

        if ($errors !== []) {
            throw new ContentValidationException($errors);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function push(array $payload): array
    {
        $errors = [];

        if ($this->string($payload, 'title') === '') {
            $errors['title'] = ['Push notifications require a title.'];
        }

        if ($this->string($payload, 'content') === '') {
            $errors['content'] = ['Push notifications require a body.'];
        }

        if ($errors !== []) {
            throw new ContentValidationException($errors);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}
