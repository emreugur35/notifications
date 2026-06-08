<?php

declare(strict_types=1);

namespace App\Support\Content;

use Exception;

/**
 * Raised when a notification's content fails channel-specific validation.
 */
class ContentValidationException extends Exception
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Notification content failed channel validation.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
