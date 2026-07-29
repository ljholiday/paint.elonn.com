<?php

declare(strict_types=1);

namespace App\Paint;

use RuntimeException;

/**
 * Renders Paint source documents into PNG preview bytes.
 */
final class PreviewImage
{
    private const MAX_PREVIEW_EDGE = 1024;

    public static function fromSource(string $sourceBytes): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Paint preview rendering requires the GD extension.');
        }

        $source = SourceDocument::decode($sourceBytes);
        $sourceWidth = max(1, (int) $source['width']);
        $sourceHeight = max(1, (int) $source['height']);
        $scale = min(1.0, self::MAX_PREVIEW_EDGE / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Paint preview image could not be created.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($transparent === false) {
            imagedestroy($image);
            throw new RuntimeException('Paint preview transparency could not be allocated.');
        }
        imagefilledrectangle($image, 0, 0, $width, $height, $transparent);
        imagealphablending($image, true);
        imageantialias($image, true);

        foreach ($source['operations'] as $operation) {
            if (($operation['type'] ?? '') !== 'stroke') {
                continue;
            }
            self::drawStroke($image, $scale, $operation);
        }

        ob_start();
        $encoded = imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        if ($encoded !== true || !is_string($bytes)) {
            throw new RuntimeException('Paint preview image could not be encoded.');
        }

        return $bytes;
    }

    /** @param array<string, mixed> $operation */
    private static function drawStroke(\GdImage $image, float $scale, array $operation): void
    {
        $style = is_array($operation['style'] ?? null) ? $operation['style'] : [];
        $geometry = is_array($operation['geometry'] ?? null) ? $operation['geometry'] : [];
        $points = is_array($geometry['points'] ?? null) ? array_values($geometry['points']) : [];
        if (count($points) < 2) {
            return;
        }

        [$red, $green, $blue] = self::color((string) ($style['color'] ?? '#000000'));
        $color = imagecolorallocatealpha($image, $red, $green, $blue, 0);
        if ($color === false) {
            throw new RuntimeException('Paint preview stroke color could not be allocated.');
        }

        $lineWidth = max(1, (int) round((float) ($style['width'] ?? 4) * $scale));
        imagesetthickness($image, $lineWidth);
        for ($index = 1; $index < count($points); $index++) {
            $from = $points[$index - 1];
            $to = $points[$index];
            if (!is_array($from) || !is_array($to)) {
                continue;
            }
            $x1 = (int) round((float) ($from['x'] ?? 0) * $scale);
            $y1 = (int) round((float) ($from['y'] ?? 0) * $scale);
            $x2 = (int) round((float) ($to['x'] ?? 0) * $scale);
            $y2 = (int) round((float) ($to['y'] ?? 0) * $scale);
            imageline($image, $x1, $y1, $x2, $y2, $color);
            imagefilledellipse($image, $x1, $y1, $lineWidth, $lineWidth, $color);
            imagefilledellipse($image, $x2, $y2, $lineWidth, $lineWidth, $color);
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private static function color(string $value): array
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $matches) !== 1) {
            return [0, 0, 0];
        }

        return [
            hexdec(substr($matches[1], 0, 2)),
            hexdec(substr($matches[1], 2, 2)),
            hexdec(substr($matches[1], 4, 2)),
        ];
    }
}
