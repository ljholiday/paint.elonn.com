<?php

declare(strict_types=1);

namespace App\Paint;

use InvalidArgumentException;
use RuntimeException;

/**
 * Builds Paint source documents stored as immutable Resources.
 */
final class SourceDocument
{
    public const MEDIA_TYPE = 'application/vnd.elonn.paint+json';

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

    /**
     * @param array<string, mixed> $stroke
     */
    public static function appendStroke(string $source, array $stroke): string
    {
        $document = self::decode($source);
        $operations = $document['operations'];
        $operations[] = self::normalizeStroke($stroke);
        $document['operations'] = $operations;

        return self::encode($document);
    }

    /** @return array{type:string,width:int,height:int,operations:array<int,array<string,mixed>>} */
    public static function decode(string $source): array
    {
        $decoded = json_decode($source, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Paint source document is not valid JSON.');
        }

        $type = $decoded['type'] ?? 'paint.source';
        $width = $decoded['width'] ?? null;
        $height = $decoded['height'] ?? null;
        $operations = $decoded['operations'] ?? null;
        if ($type !== 'paint.source' || !is_int($width) || !is_int($height) || !is_array($operations)) {
            throw new RuntimeException('Paint source document is not canonical.');
        }

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                throw new RuntimeException('Paint source document operations are not canonical.');
            }
        }

        return [
            'type' => 'paint.source',
            'width' => $width,
            'height' => $height,
            'operations' => array_values($operations),
        ];
    }

    /**
     * @param array{type:string,width:int,height:int,operations:array<int,array<string,mixed>>} $document
     */
    private static function encode(array $document): string
    {
        $encoded = json_encode($document, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Paint source document could not be encoded.');
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $stroke
     * @return array<string, mixed>
     */
    private static function normalizeStroke(array $stroke): array
    {
        $tool = trim((string) ($stroke['tool'] ?? 'pencil'));
        if ($tool !== 'pencil') {
            throw new InvalidArgumentException('Paint stroke tool is not supported.');
        }

        $color = trim((string) ($stroke['color'] ?? $stroke['style']['color'] ?? '#000000'));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            throw new InvalidArgumentException('Paint stroke color is invalid.');
        }

        $width = $stroke['width'] ?? $stroke['style']['width'] ?? 4;
        if (!is_int($width) && !(is_string($width) && ctype_digit($width))) {
            throw new InvalidArgumentException('Paint stroke width is invalid.');
        }
        $width = (int) $width;
        if ($width < 1 || $width > 128) {
            throw new InvalidArgumentException('Paint stroke width is outside the supported range.');
        }

        $points = $stroke['points'] ?? $stroke['geometry']['points'] ?? null;
        if (!is_array($points) || count($points) < 2) {
            throw new InvalidArgumentException('Paint stroke requires at least two points.');
        }

        return [
            'id' => 'paint.operation:' . bin2hex(random_bytes(16)),
            'type' => 'stroke',
            'created' => gmdate('c'),
            'tool' => $tool,
            'style' => [
                'color' => strtolower($color),
                'width' => $width,
            ],
            'geometry' => [
                'points' => self::points($points),
            ],
        ];
    }

    /**
     * @param array<int|string, mixed> $points
     * @return array<int, array{x:float,y:float}>
     */
    private static function points(array $points): array
    {
        $normalized = [];
        foreach (array_values($points) as $point) {
            if (!is_array($point) || !is_numeric($point['x'] ?? null) || !is_numeric($point['y'] ?? null)) {
                throw new InvalidArgumentException('Paint stroke points must contain numeric x and y values.');
            }

            $x = (float) $point['x'];
            $y = (float) $point['y'];
            if (!is_finite($x) || !is_finite($y)) {
                throw new InvalidArgumentException('Paint stroke points must be finite.');
            }

            $normalized[] = [
                'x' => $x,
                'y' => $y,
            ];
        }

        return $normalized;
    }
}
