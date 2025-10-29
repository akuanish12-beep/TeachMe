<?php

declare(strict_types=1);

namespace App\Exceptions;

class GeminiSchemaViolationException extends \Exception
{
    private array $violationDetails;

    public function __construct(string $message, array $violationDetails, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->violationDetails = $violationDetails;
    }

    public function getViolationDetails(): array
    {
        return $this->violationDetails;
    }
}

