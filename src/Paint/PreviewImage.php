<?php

declare(strict_types=1);

namespace App\Paint;

use RuntimeException;

/**
 * Provides initial Paint preview bytes until rendering exists.
 */
final class PreviewImage
{
    public static function transparentPng(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true
        );
        if (!is_string($bytes)) {
            throw new RuntimeException('Initial Paint preview could not be loaded.');
        }

        return $bytes;
    }
}
