<?php

namespace App\Services\Didit;

class DiditApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }
}
