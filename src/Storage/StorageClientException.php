<?php

declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * Represents an outbound Storage dependency failure without exposing credentials.
 */
final class StorageClientException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $errorClass,
        string $message,
        public readonly int $httpStatus = 503,
    ) {
        parent::__construct($message);
    }
}
