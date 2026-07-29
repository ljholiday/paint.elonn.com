<?php

declare(strict_types=1);

namespace App\Paint;

use App\Security\AuthenticatedService;
use App\Storage\StorageClient;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Executes Paint-owned operations and produces canonical Service Datasets.
 */
final class PaintService
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly StorageClient $storage,
    )
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

        $source = null;
        $preview = null;

        try {
            $source = $this->storage->create(SourceDocument::MEDIA_TYPE, SourceDocument::empty($width, $height), $caller->memberId);
            $preview = $this->storage->create('image/png', PreviewImage::transparentPng(), $caller->memberId);
            $updated = $this->documents->updateResources(
                (string) $document['id'],
                (string) $source['id'],
                (string) $preview['id']
            );
            if ($updated === null) {
                throw new RuntimeException('Paint document Resource links could not be saved.');
            }

            return $this->dataset($updated, $caller, 'paint.create', [
                $this->resourceObject($source, 'paint.source', 'Paint source'),
                $this->resourceObject($preview, 'paint.preview', 'Paint preview'),
            ]);
        } catch (Throwable $throwable) {
            if (is_array($source ?? null)) {
                $this->storage->delete((string) ($source['id'] ?? ''));
            }
            if (is_array($preview ?? null)) {
                $this->storage->delete((string) ($preview['id'] ?? ''));
            }
            $this->documents->delete((string) $document['id']);

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function read(array $content, AuthenticatedService $caller): array
    {
        $documentId = $this->documentId($content['document_id'] ?? null);
        $document = $this->documents->find($documentId);
        if ($document === null) {
            throw new DocumentNotFoundException('Paint document was not found.');
        }

        $sourceResourceId = (string) ($document['source_resource_id'] ?? '');
        $previewResourceId = (string) ($document['preview_resource_id'] ?? '');
        if ($sourceResourceId === '' || $previewResourceId === '') {
            throw new RuntimeException('Paint document Resource links are incomplete.');
        }

        $source = $this->storage->metadata($sourceResourceId);
        $preview = $this->storage->metadata($previewResourceId);

        return $this->dataset($document, $caller, 'paint.read', [
            $this->resourceObject($source, 'paint.source', 'Paint source'),
            $this->resourceObject($preview, 'paint.preview', 'Paint preview'),
        ]);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function draw(array $content, AuthenticatedService $caller): array
    {
        $documentId = $this->documentId($content['document_id'] ?? null);
        $stroke = $content['stroke'] ?? null;
        if (!is_array($stroke)) {
            throw new InvalidArgumentException('Paint draw requires a stroke.');
        }

        $document = $this->documents->find($documentId);
        if ($document === null) {
            throw new DocumentNotFoundException('Paint document was not found.');
        }

        $sourceResourceId = (string) ($document['source_resource_id'] ?? '');
        $previewResourceId = (string) ($document['preview_resource_id'] ?? '');
        if ($sourceResourceId === '' || $previewResourceId === '') {
            throw new RuntimeException('Paint document Resource links are incomplete.');
        }

        $sourceBytes = $this->storage->content($sourceResourceId);
        $nextSourceBytes = SourceDocument::appendStroke($sourceBytes, $stroke);
        $preview = $this->storage->metadata($previewResourceId);
        $source = $this->storage->replace($sourceResourceId, SourceDocument::MEDIA_TYPE, $nextSourceBytes, $caller->memberId);

        $updated = $this->documents->updateResources(
            $documentId,
            (string) $source['id'],
            $previewResourceId
        );
        if ($updated === null) {
            $this->storage->delete((string) ($source['id'] ?? ''));
            throw new RuntimeException('Paint document Resource links could not be saved.');
        }

        return $this->dataset($updated, $caller, 'paint.draw', [
            $this->resourceObject($source, 'paint.source', 'Paint source'),
            $this->resourceObject($preview, 'paint.preview', 'Paint preview'),
        ]);
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

    private function documentId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^paint\.document:[a-f0-9]{32}$/', $value) !== 1) {
            throw new InvalidArgumentException('Paint document id is required.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<int, array<string, mixed>> $resourceObjects
     * @return array<string, mixed>
     */
    private function dataset(array $document, AuthenticatedService $caller, string $operation, array $resourceObjects): array
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
                    'surface' => [
                        'mode' => 'hosted',
                        'service' => 'paint',
                        'kind' => 'editor',
                        'resources' => [
                            'source' => $document['source_resource_id'] ?? null,
                            'preview' => $document['preview_resource_id'] ?? null,
                        ],
                    ],
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
            'resources' => $resourceObjects,
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
                'operation' => $operation,
                'caller' => $caller->name,
                'owner' => $caller->owner(),
            ],
        ];
    }

    /** @param array<string, mixed> $resource @return array<string, mixed> */
    private function resourceObject(array $resource, string $kind, string $label): array
    {
        return [
            'id' => (string) $resource['id'],
            'type' => (string) $resource['type'],
            'length' => (int) $resource['length'],
            'sha256' => (string) $resource['sha256'],
            'owner' => (string) $resource['owner'],
            'created' => (string) $resource['created'],
            'modified' => (string) $resource['modified'],
            'url' => $resource['url'] ?? null,
            'replaces' => $resource['replaces'] ?? null,
            'content' => [
                'kind' => $kind,
                'label' => $label,
            ],
        ];
    }
}
