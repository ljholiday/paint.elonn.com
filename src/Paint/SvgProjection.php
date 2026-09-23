<?php

declare(strict_types=1);

namespace App\Paint;

use RuntimeException;

/**
 * Projects a Paint source document's Drawing Operations into the platform SVG Profile
 * (dev.elonn canonical/svg-profile.md). Runtimes render this markup directly; they do not
 * reconstruct graphics semantics from `operations`/`geometry` themselves (svg-profile.md,
 * Ownership).
 */
final class SvgProjection
{
    public const MEDIA_TYPE = 'image/svg+xml';

    public static function fromSource(string $sourceBytes): string
    {
        $source = SourceDocument::decode($sourceBytes);
        $width = max(1, (int) $source['width']);
        $height = max(1, (int) $source['height']);

        $paths = [];
        foreach ($source['operations'] as $operation) {
            if (!is_array($operation) || ($operation['type'] ?? '') !== 'stroke') {
                continue;
            }
            $paths[] = self::pathElement($operation);
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '">'
            . implode('', $paths)
            . '</svg>';
    }

    /** @param array<string, mixed> $operation */
    private static function pathElement(array $operation): string
    {
        $style = is_array($operation['style'] ?? null) ? $operation['style'] : [];
        $geometry = is_array($operation['geometry'] ?? null) ? $operation['geometry'] : [];
        $points = is_array($geometry['points'] ?? null) ? array_values($geometry['points']) : [];
        if (count($points) < 2) {
            throw new RuntimeException('Paint stroke operation is not canonical.');
        }

        $color = self::color((string) ($style['color'] ?? '#000000'));
        $width = self::positiveNumber($style['width'] ?? 4, 'Paint stroke width');

        $commands = [];
        foreach ($points as $index => $point) {
            if (!is_array($point)) {
                throw new RuntimeException('Paint stroke point is not canonical.');
            }
            $x = self::number($point['x'] ?? null, 'Paint stroke point x');
            $y = self::number($point['y'] ?? null, 'Paint stroke point y');
            $commands[] = ($index === 0 ? 'M' : 'L') . $x . ',' . $y;
        }

        return '<path d="' . implode(' ', $commands) . '"'
            . ' stroke="' . $color . '"'
            . ' stroke-width="' . $width . '"'
            . ' stroke-linecap="round"'
            . ' stroke-linejoin="round"'
            . ' fill="none"/>';
    }

    private static function color(string $value): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new RuntimeException('Paint stroke color is not canonical.');
        }

        return strtolower($value);
    }

    private static function positiveNumber(mixed $value, string $label): string
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException($label . ' is not canonical.');
        }

        return self::number($value, $label);
    }

    private static function number(mixed $value, string $label): string
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new RuntimeException($label . ' is not canonical.');
        }

        return number_format((float) $value, 2, '.', '');
    }
}
