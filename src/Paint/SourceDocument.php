<?php

declare(strict_types=1);

namespace App\Paint;

use RuntimeException;

/**
 * Builds Paint source documents stored as immutable Resources.
 */
final class SourceDocument
{
    public static function empty(int $width, int $height): string
    {
        $encoded = json_encode([
            'type' => 'paint.source',
            'width' => $width,
            'height' => $height,
            'operations' => [],
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            throw new RuntimeException('Paint source document could not be encoded.');
        }

        return $encoded;
    }
}
