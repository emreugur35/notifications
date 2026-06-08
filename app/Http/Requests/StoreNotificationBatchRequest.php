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

class StoreNotificationBatchRequest extends FormRequest
{
    /**
     * Maximum number of notifications accepted in a single batch.
     */
    public const MAX_BATCH_SIZE = 1000;

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
            'name' => ['nullable', 'string', 'max:255'],
            'notifications' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH_SIZE],
            'notifications.*.recipient' => ['required', 'string', 'max:255'],
            'notifications.*.channel' => ['required', Rule::enum(Channel::class)],
            'notifications.*.content' => ['required', 'string', 'max:10000'],
            'notifications.*.subject' => ['nullable', 'string', 'max:255'],
            'notifications.*.title' => ['nullable', 'string', 'max:255'],
            'notifications.*.priority' => ['sometimes', Rule::enum(Priority::class)],
            'notifications.*.scheduled_at' => ['nullable', 'date'],
            'notifications.*.idempotency_key' => ['nullable', 'string', 'max:255'],
            'notifications.*.metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * Apply per-channel content validation to each item in the batch.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('notifications');

            if (! is_array($items)) {
                return;
            }

            $contentValidator = app(ContentValidator::class);

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $channel = Channel::tryFrom((string) ($item['channel'] ?? ''));

                if ($channel === null) {
                    continue;
                }

                try {
                    $contentValidator->validate($channel, [
                        'content' => $item['content'] ?? null,
                        'subject' => $item['subject'] ?? null,
                        'title' => $item['title'] ?? null,
                    ]);
                } catch (ContentValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add("notifications.{$index}.{$field}", $message);
                        }
                    }
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notifications.max' => 'A batch may contain at most '.self::MAX_BATCH_SIZE.' notifications.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Optional human-readable label for the batch.',
                'example' => 'november-promo',
            ],
            'notifications' => [
                'description' => 'Array of notifications to create (1 to '.self::MAX_BATCH_SIZE.').',
            ],
            'notifications.*.recipient' => [
                'description' => 'Destination address for this notification.',
                'example' => 'user@example.com',
            ],
            'notifications.*.channel' => [
                'description' => 'Delivery channel. One of: '.implode(', ', Channel::values()).'.',
                'example' => Channel::Email->value,
            ],
            'notifications.*.content' => [
                'description' => 'Rendered notification body.',
                'example' => 'Your order has shipped.',
            ],
            'notifications.*.priority' => [
                'description' => 'Relative urgency. One of: '.implode(', ', Priority::values()).'.',
                'example' => Priority::Normal->value,
            ],
        ];
    }
}
