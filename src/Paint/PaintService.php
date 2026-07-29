<?php

declare(strict_types=1);

namespace App\Paint;

use App\Security\AuthenticatedService;
use InvalidArgumentException;

/**
 * Executes Paint-owned operations and produces canonical Service Datasets.
 */
final class PaintService
{
    public function __construct(private readonly DocumentRepository $documents)
    {
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function create(array $content, AuthenticatedService $caller): array
    {
        $title = $this->title($content['title'] ?? null);
        $width = $this->dimension($content['width'] ?? null, 1024, 'width');
        $height = $this->dimension($content['height'] ?? null, 768, 'height');

        $document = $this->documents->create(
            $caller->owner(),
            $title,
            $width,
            $height,
            null,
            null,
            $caller->name
        );

        return $this->dataset($document, $caller);
    }

    private function title(mixed $value): string
    {
        if ($value === null) {
            return 'Untitled Paint';
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Paint document title must be a string.');
        }

        $title = trim($value);
        return $title === '' ? 'Untitled Paint' : $title;
    }

    private function dimension(mixed $value, int $default, string $name): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Paint document ' . $name . ' must be a positive integer.');
        }

        $dimension = (int) $value;
        if ($dimension < 1 || $dimension > 8192) {
            throw new InvalidArgumentException('Paint document ' . $name . ' is outside the supported range.');
        }

        return $dimension;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function dataset(array $document, AuthenticatedService $caller): array
    {
        $documentId = (string) $document['id'];
        $resources = array_values(array_filter([
            $document['source_resource_id'] ?? null,
            $document['preview_resource_id'] ?? null,
        ], 'is_string'));

        return [
            'id' => 'dataset:service:paint:' . bin2hex(random_bytes(16)),
            'type' => 'service',
            'scope' => 'object',
            'mode' => 'snapshot',
            'created' => gmdate('c'),
            'objects' => [[
                'id' => $documentId,
                'type' => 'paint.document',
                'title' => (string) $document['title'],
                'summary' => 'Paint document',
                'content' => [
                    'name' => (string) $document['title'],
                    'description' => 'Paint document',
                    'width' => (int) $document['width'],
                    'height' => (int) $document['height'],
                    'source_resource' => $document['source_resource_id'] ?? null,
                    'preview_resource' => $document['preview_resource_id'] ?? null,
                    'storage_state' => $resources === [] ? 'pending_resources' : 'ready',
                ],
                'resources' => $resources,
            ]],
            'actions' => [[
                'id' => 'action:' . $documentId . ':open',
                'type' => 'open',
                'target' => $documentId,
                'content' => [
                    'label' => 'Open',
                ],
            ]],
            'relationships' => [],
            'collections' => [],
            'resources' => [],
            'placements' => [[
                'id' => 'placement:' . $documentId . ':workspace',
                'type' => 'workspace',
                'content' => [
                    'object' => $documentId,
                ],
            ]],
            'errors' => [],
            'context' => [
                'service' => 'paint',
                'operation' => 'paint.create',
                'caller' => $caller->name,
                'owner' => $caller->owner(),
            ],
        ];
    }
}
