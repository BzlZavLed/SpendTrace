<?php

namespace App\Services;

use RuntimeException;

/** Contains only application-authored messages and explicitly filtered diagnostic metadata. */
class ReceiptExtractionException extends RuntimeException
{
    public function __construct(string $message, public readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }
}
