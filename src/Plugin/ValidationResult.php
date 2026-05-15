<?php

declare(strict_types=1);

namespace App\Plugin;

final readonly class ValidationResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
    ) {
    }

    public static function valid(): self
    {
        return new self(true);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }

    public function message(): string
    {
        return implode(' ', $this->errors);
    }
}
