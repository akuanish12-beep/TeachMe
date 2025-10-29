<?php

declare(strict_types=1);

namespace App\Application\Helpers;

class Validator
{
    private array $errors = [];

    /**
     * Validate required fields
     */
    public function required(array $data, array $fields): self
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $this->errors[$field] = "The {$field} field is required";
            }
        }
        return $this;
    }

    /**
     * Validate email format
     */
    public function email(array $data, string $field): self
    {
        if (isset($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "The {$field} must be a valid email address";
        }
        return $this;
    }

    /**
     * Validate minimum length
     */
    public function minLength(array $data, string $field, int $length): self
    {
        if (isset($data[$field]) && strlen($data[$field]) < $length) {
            $this->errors[$field] = "The {$field} must be at least {$length} characters";
        }
        return $this;
    }

    /**
     * Validate maximum length
     */
    public function maxLength(array $data, string $field, int $length): self
    {
        if (isset($data[$field]) && strlen($data[$field]) > $length) {
            $this->errors[$field] = "The {$field} must not exceed {$length} characters";
        }
        return $this;
    }

    /**
     * Check if validation passed
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     */
    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Get validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

