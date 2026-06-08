<?php

declare(strict_types=1);

namespace App\Support\Sms;

/**
 * Result of segmenting an SMS message body.
 */
final class SmsSegmentation
{
    public function __construct(
        public readonly string $encoding,
        public readonly int $length,
        public readonly int $segments,
        public readonly int $perSegment,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'encoding' => $this->encoding,
            'length' => $this->length,
            'segments' => $this->segments,
            'per_segment' => $this->perSegment,
        ];
    }
}
