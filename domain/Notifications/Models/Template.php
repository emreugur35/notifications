<?php

declare(strict_types=1);

namespace Domain\Notifications\Models;

use Database\Factories\TemplateFactory;
use Domain\Notifications\Enums\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property Channel $channel
 * @property string|null $subject
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
        ];
    }

    protected static function newFactory(): TemplateFactory
    {
        return TemplateFactory::new();
    }
}
