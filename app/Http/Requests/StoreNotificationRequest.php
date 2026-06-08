<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Priority;
use App\Support\Content\ContentValidationException;
use App\Support\Content\ContentValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::enum(Channel::class)],
            'content' => ['required', 'string', 'max:10000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::enum(Priority::class)],
            'scheduled_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Apply per-channel content validation (email subject+body, push
     * title+body, SMS body) once the base rules have passed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $channel = Channel::tryFrom((string) $this->input('channel'));

            if ($channel === null) {
                return; // invalid channel is reported by the enum rule
            }

            try {
                app(ContentValidator::class)->validate($channel, [
                    'content' => $this->input('content'),
                    'subject' => $this->input('subject'),
                    'title' => $this->input('title'),
                ]);
            } catch (ContentValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }

    /**
     * Scribe documentation for the request body.
     *
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'recipient' => [
                'description' => 'Destination address: phone number for SMS, email address for email, or device token for push.',
                'example' => 'user@example.com',
            ],
            'channel' => [
                'description' => 'Delivery channel. One of: '.implode(', ', Channel::values()).'.',
                'example' => Channel::Email->value,
            ],
            'content' => [
                'description' => 'Rendered notification body. Required for every channel.',
                'example' => 'Your order has shipped.',
            ],
            'subject' => [
                'description' => 'Subject line. Required for the email channel.',
                'example' => 'Your order has shipped',
            ],
            'title' => [
                'description' => 'Title. Required for the push channel.',
                'example' => 'Order update',
            ],
            'priority' => [
                'description' => 'Relative urgency. One of: '.implode(', ', Priority::values()).'. Defaults to normal.',
                'example' => Priority::Normal->value,
            ],
            'scheduled_at' => [
                'description' => 'Optional ISO-8601 timestamp to defer delivery.',
                'example' => null,
            ],
            'idempotency_key' => [
                'description' => 'Optional client-supplied key; repeat requests with the same key return the original notification.',
                'example' => null,
            ],
            'metadata' => [
                'description' => 'Optional arbitrary key/value metadata.',
                'example' => null,
            ],
        ];
    }
}
