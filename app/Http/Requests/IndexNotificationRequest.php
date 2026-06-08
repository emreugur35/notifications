<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexNotificationRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(Status::class)],
            'channel' => ['sometimes', Rule::enum(Channel::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'status' => [
                'description' => 'Filter by status. One of: '.implode(', ', Status::values()).'.',
                'example' => Status::Pending->value,
            ],
            'channel' => [
                'description' => 'Filter by channel. One of: '.implode(', ', Channel::values()).'.',
                'example' => Channel::Email->value,
            ],
            'from' => [
                'description' => 'Filter notifications created on or after this date (inclusive).',
                'example' => '2026-01-01',
            ],
            'to' => [
                'description' => 'Filter notifications created on or before this date (inclusive).',
                'example' => '2026-12-31',
            ],
            'per_page' => [
                'description' => 'Results per page (1-100). Defaults to 15.',
                'example' => 25,
            ],
        ];
    }
}
