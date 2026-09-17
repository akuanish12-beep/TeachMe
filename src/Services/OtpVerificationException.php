<?php

declare(strict_types=1);

namespace App\Services;

class OtpVerificationException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
