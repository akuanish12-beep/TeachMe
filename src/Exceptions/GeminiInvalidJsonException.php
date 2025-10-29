<?php

declare(strict_types=1);

namespace App\Exceptions;

class GeminiInvalidJsonException extends \Exception
{
    private string $rawPayload;

    public function __construct(string $message, string $rawPayload, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->rawPayload = $rawPayload;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }
}

