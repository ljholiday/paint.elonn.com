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
            null,
            $caller->name
        );

        $source = null;
        $preview = null;
        $graphics = null;

        try {
            $sourceBytes = SourceDocument::empty($width, $height);
            $previewBytes = PreviewImage::fromSource($sourceBytes);
            $graphicsBytes = SvgProjection::fromSource($sourceBytes);
            $source = $this->storage->create(SourceDocument::MEDIA_TYPE, $sourceBytes, $caller->memberId);
            $preview = $this->storage->create('image/png', $previewBytes, $caller->memberId);
            $graphics = $this->storage->create(SvgProjection::MEDIA_TYPE, $graphicsBytes, $caller->memberId);
            $updated = $this->documents->updateResources(
                (string) $document['id'],
                (string) $source['id'],
                (string) $preview['id'],
                (string) $graphics['id']
            );
            if ($updated === null) {
                throw new RuntimeException('Paint document Resource links could not be saved.');
            }
            $this->indexDocument($updated, $sourceBytes);

            return $this->dataset($updated, $caller, 'paint.create', [
                $this->sourceResourceObject($source, $sourceBytes),
                $this->previewResourceObject($preview, $previewBytes),
                $this->graphicsResourceObject($graphics, $graphicsBytes),
            ]);
        } catch (Throwable $throwable) {
            if (is_array($source ?? null)) {
                $this->storage->delete((string) ($source['id'] ?? ''));
            }
            if (is_array($preview ?? null)) {
                $this->storage->delete((string) ($preview['id'] ?? ''));
            }
            if (is_array($graphics ?? null)) {
                $this->storage->delete((string) ($graphics['id'] ?? ''));
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

        $sourceBytes = $this->storage->content($sourceResourceId);
        $previewBytes = $this->storage->content($previewResourceId);
        $source = $this->storage->metadata($sourceResourceId);
        $preview = $this->storage->metadata($previewResourceId);
        [$graphics, $graphicsBytes, $document] = $this->ensureGraphicsResource($document, $sourceBytes, $caller->memberId);

        return $this->dataset($document, $caller, 'paint.read', [
            $this->sourceResourceObject($source, $sourceBytes),
            $this->previewResourceObject($preview, $previewBytes),
            $this->graphicsResourceObject($graphics, $graphicsBytes),
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
        // A document drawn on before the SVG Profile projection existed has no
        // graphics_resource_id yet -- create its first one here rather than requiring it to
        // already exist (see ensureGraphicsResource for the read-path equivalent).
        $graphicsResourceId = (string) ($document['graphics_resource_id'] ?? '');

        $sourceBytes = $this->storage->content($sourceResourceId);
        $nextSourceBytes = SourceDocument::appendStroke($sourceBytes, $stroke);
        $nextPreviewBytes = PreviewImage::fromSource($nextSourceBytes);
        $nextGraphicsBytes = SvgProjection::fromSource($nextSourceBytes);
        $source = null;
        $preview = null;
        $graphics = null;

        try {
            $source = $this->storage->replace($sourceResourceId, SourceDocument::MEDIA_TYPE, $nextSourceBytes, $caller->memberId);
            $preview = $this->storage->replace($previewResourceId, 'image/png', $nextPreviewBytes, $caller->memberId);
            $graphics = $graphicsResourceId !== ''
                ? $this->storage->replace($graphicsResourceId, SvgProjection::MEDIA_TYPE, $nextGraphicsBytes, $caller->memberId)
                : $this->storage->create(SvgProjection::MEDIA_TYPE, $nextGraphicsBytes, $caller->memberId);

            $updated = $this->documents->updateResources(
                $documentId,
                (string) $source['id'],
                (string) $preview['id'],
                (string) $graphics['id']
            );
            if ($updated === null) {
                throw new RuntimeException('Paint document Resource links could not be saved.');
            }
            $this->indexDocument($updated, $nextSourceBytes);

            return $this->dataset($updated, $caller, 'paint.draw', [
                $this->sourceResourceObject($source, $nextSourceBytes),
                $this->previewResourceObject($preview, $nextPreviewBytes),
                $this->graphicsResourceObject($graphics, $nextGraphicsBytes),
            ]);
        } catch (Throwable $throwable) {
            if (is_array($source ?? null)) {
                $this->storage->delete((string) ($source['id'] ?? ''));
            }
            if (is_array($preview ?? null)) {
                $this->storage->delete((string) ($preview['id'] ?? ''));
            }
            if (is_array($graphics ?? null)) {
                $this->storage->delete((string) ($graphics['id'] ?? ''));
            }

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function rename(array $content, AuthenticatedService $caller): array
    {
        $documentId = $this->documentId($content['document_id'] ?? null);
        $title = $this->renameTitle($content['title'] ?? null);

        $document = $this->documents->rename($documentId, $title);
        if ($document === null) {
            throw new DocumentNotFoundException('Paint document was not found.');
        }
        $sourceResourceId = (string) ($document['source_resource_id'] ?? '');
        if ($sourceResourceId !== '') {
            $this->indexDocument($document, $this->storage->content($sourceResourceId));
        }

        return $this->datasetWithCurrentResources($document, $caller, 'paint.rename');
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function search(array $content, AuthenticatedService $caller): array
    {
        $text = trim((string) ($content['text'] ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException('Paint search requires text.');
        }

        $limit = $this->limit($content['limit'] ?? null);
        $documents = $this->documents->search($caller->owner(), $text, $limit);
        $normalizedText = $this->normalizedSearchText($text);
        if ($documents === [] && $normalizedText !== '' && $normalizedText !== mb_strtolower($text)) {
            $documents = $this->documents->search($caller->owner(), $normalizedText, $limit);
        }

        return $this->searchDataset(
            $documents,
            $caller,
            'paint.search',
            $text
        );
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    public function list(array $content, AuthenticatedService $caller): array
    {
        return $this->searchDataset(
            $this->documents->recentForOwner($caller->owner(), $this->limit($content['limit'] ?? null)),
            $caller,
            'paint.list',
            ''
        );
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

    private function renameTitle(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Paint document title must be a string.');
        }

        $title = trim($value);
        if ($title === '') {
            throw new InvalidArgumentException('Paint document title is required.');
        }

        return $title;
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

    private function limit(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 10;
        }

        return max(1, min(25, (int) $value));
    }

    private function documentId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^paint\.document:[a-f0-9]{32}$/', $value) !== 1) {
            throw new InvalidArgumentException('Paint document id is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function datasetWithCurrentResources(array $document, AuthenticatedService $caller, string $operation): array
    {
        $sourceResourceId = (string) ($document['source_resource_id'] ?? '');
        $previewResourceId = (string) ($document['preview_resource_id'] ?? '');
        if ($sourceResourceId === '' || $previewResourceId === '') {
            throw new RuntimeException('Paint document Resource links are incomplete.');
        }

        $sourceBytes = $this->storage->content($sourceResourceId);
        $previewBytes = $this->storage->content($previewResourceId);
        $source = $this->storage->metadata($sourceResourceId);
        $preview = $this->storage->metadata($previewResourceId);
        [$graphics, $graphicsBytes, $document] = $this->ensureGraphicsResource($document, $sourceBytes, $caller->memberId);

        return $this->dataset($document, $caller, $operation, [
            $this->sourceResourceObject($source, $sourceBytes),
            $this->previewResourceObject($preview, $previewBytes),
            $this->graphicsResourceObject($graphics, $graphicsBytes),
        ]);
    }

    /**
     * A document read or renamed before the SVG Profile projection existed has no
     * graphics_resource_id yet -- not a broken document, just one that predates the field.
     * Generates and persists its first graphics.svg Resource here, the first time it's
     * touched again, instead of treating the missing id as an error forever. Mirrors draw()'s
     * own create-vs-replace branch for the same case.
     *
     * @param array<string, mixed> $document
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, mixed>}
     */
    private function ensureGraphicsResource(array $document, string $sourceBytes, ?string $memberId): array
    {
        $graphicsResourceId = (string) ($document['graphics_resource_id'] ?? '');
        if ($graphicsResourceId !== '') {
            return [$this->storage->metadata($graphicsResourceId), $this->storage->content($graphicsResourceId), $document];
        }

        $graphicsBytes = SvgProjection::fromSource($sourceBytes);
        $graphics = $this->storage->create(SvgProjection::MEDIA_TYPE, $graphicsBytes, $memberId);
        $updated = $this->documents->updateResources(
            (string) $document['id'],
            (string) $document['source_resource_id'],
            (string) $document['preview_resource_id'],
            (string) $graphics['id']
        );
        if ($updated === null) {
            throw new RuntimeException('Paint document Resource links could not be saved.');
        }

        return [$graphics, $graphicsBytes, $updated];
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
            $document['graphics_resource_id'] ?? null,
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
                    'description' => (string) ($document['semantic_summary'] ?? 'Paint document'),
                    'width' => (int) $document['width'],
                    'height' => (int) $document['height'],
                    'source_resource' => $document['source_resource_id'] ?? null,
                    'preview_resource' => $document['preview_resource_id'] ?? null,
                    'graphics_resource' => $document['graphics_resource_id'] ?? null,
                    'search' => [
                        'confidence' => $document['search_confidence'] ?? null,
                        'semantic_summary' => $document['semantic_summary'] ?? null,
                        'semantic_labels' => $document['semantic_labels'] ?? [],
                        'index_status' => $document['index_status'] ?? null,
                        'indexed_at' => $document['indexed_at'] ?? null,
                    ],
                    'storage_state' => $resources === [] ? 'pending_resources' : 'ready',
                    // Service-neutral presentation hint (dev.elonn canonical/object.md,
                    // Recognized content format: drawing_surface) -- a Runtime renders this
                    // generically for any Service's drawing surface, not just Paint's.
                    'format' => 'drawing_surface',
                ],
                'resources' => $resources,
            ]],
            // A real "open" action -- operation_invocation calling paint.read -- the same
            // shape every other Service's Objects already carry (e.g. find.elonn's
            // find.open). Reopening this document (from a paint.search Finding, which never
            // carries this document's Resources) routes back through Paint for current state
            // instead of World's free world.focus placement, which only reuses whatever the
            // current Dataset happens to already hold. A Runtime already hides this action
            // generically whenever it renders the Object it targets as already-open (it does
            // the same for every other Service's open action) -- unconditionally including it
            // here needs no Paint-specific suppression logic for create/read/draw/rename.
            // Draw and rename are real Actions, bare (no arguments declared here) -- Conductor
            // attaches each operation's real argument schema from Paint's published Contract
            // generically, the same way every other Service's operations are enriched. A
            // Runtime renders the drawing surface and the rename field from that schema alone;
            // it needs no Paint-specific code to know what these actions do (dev.elonn
            // canonical/object.md, Recognized content format: drawing_surface).
            'actions' => [
                [
                    'id' => 'action:' . $documentId . ':open',
                    'type' => 'open_object',
                    'target' => $documentId,
                    'content' => [
                        'label' => 'Open',
                        'operation_invocation' => [
                            'service' => 'paint',
                            'operation' => 'paint.read',
                            'object_id' => $documentId,
                            'payload' => [],
                        ],
                        'availability' => [
                            'state' => 'enabled',
                        ],
                    ],
                ],
                [
                    'id' => 'action:' . $documentId . ':draw',
                    'type' => 'operation',
                    'target' => $documentId,
                    'content' => [
                        'label' => 'Draw',
                        'operation_invocation' => [
                            'service' => 'paint',
                            'operation' => 'paint.draw',
                            'object_id' => $documentId,
                            'payload' => [],
                        ],
                        'availability' => [
                            'state' => 'enabled',
                        ],
                    ],
                ],
                [
                    'id' => 'action:' . $documentId . ':rename',
                    'type' => 'operation',
                    'target' => $documentId,
                    'content' => [
                        'label' => 'Rename',
                        'operation_invocation' => [
                            'service' => 'paint',
                            'operation' => 'paint.rename',
                            'object_id' => $documentId,
                            'payload' => [],
                        ],
                        'availability' => [
                            'state' => 'enabled',
                        ],
                    ],
                ],
            ],
            'relationships' => [],
            'collections' => [],
            'resources' => $resourceObjects,
            // paint.read / create / draw / rename open a single document: it is placed on Carry
            // (an opened Object -- see dev.elonn canonical/layout.md).
            'placements' => [[
                'id' => 'placement:' . $documentId . ':carry',
                'type' => 'carry',
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

    /**
     * @param array<int, array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    private function searchDataset(array $documents, AuthenticatedService $caller, string $operation, string $text): array
    {
        $objects = $this->matchesPaintWorkspace($text) ? [$this->paintWorkspaceObject()] : [];
        $actions = [];
        if ($objects !== []) {
            $actions[] = $this->paintWorkspaceAction();
        }
        foreach ($documents as $document) {
            $dataset = $this->dataset($document, $caller, $operation, []);
            $object = $dataset['objects'][0] ?? null;
            if (is_array($object)) {
                $objects[] = $object;
                $actions = array_merge($actions, $dataset['actions']);
            }
        }

        $collections = [];
        if (count($objects) !== 1) {
            $collectionId = 'collection:paint.search:' . bin2hex(random_bytes(8));
            $summary = count($objects) === 0
                ? ($text !== '' ? 'No Paint documents matched "' . $text . '".' : 'No Paint documents are available.')
                : count($objects) . ' Paint results matched.';
            $collections[] = [
                'id' => $collectionId,
                'type' => 'paint.results',
                'title' => $text !== '' ? 'Paint results for ' . $text : 'Recent Paint documents',
                'summary' => $summary,
                'items' => array_map(static fn (array $object): string => (string) $object['id'], $objects),
                'content' => [
                    'description' => $summary,
                    'query' => $text,
                    'count' => count($objects),
                ],
            ];
        }

        return [
            'id' => 'dataset:service:paint:' . bin2hex(random_bytes(16)),
            'type' => 'service',
            'scope' => count($objects) === 1 ? 'object' : 'collection',
            'mode' => 'snapshot',
            'created' => gmdate('c'),
            'objects' => $objects,
            'actions' => $actions,
            'relationships' => [],
            'collections' => $collections,
            'resources' => [],
            // A search lists Paint documents as unplaced Findings; focusing one opens it.
            'placements' => [],
            'errors' => [],
            'context' => [
                'service' => 'paint',
                'operation' => $operation,
                'caller' => $caller->name,
                'owner' => $caller->owner(),
                'search' => [
                    'text' => $text,
                    'count' => count($objects),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function paintWorkspaceObject(): array
    {
        return [
            'id' => 'paint.workspace',
            'type' => 'paint.workspace',
            'title' => 'Paint',
            'summary' => 'Paint drawing workspace',
            'content' => [
                'name' => 'Paint',
                'description' => 'Create a new Paint drawing document.',
                'service' => 'paint',
                'kind' => 'drawing_workspace',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function paintWorkspaceAction(): array
    {
        return [
            'id' => 'action:paint.workspace:create',
            'type' => 'operation',
            'target' => 'paint.workspace',
            'content' => [
                'label' => 'Create drawing',
                'operation_invocation' => [
                    'service' => 'paint',
                    'operation' => 'paint.create',
                    'object_id' => 'paint.workspace',
                    'payload' => [],
                ],
                'availability' => [
                    'state' => 'enabled',
                ],
            ],
        ];
    }

    private function matchesPaintWorkspace(string $text): bool
    {
        $normalized = $this->normalizedSearchText($text);

        return in_array($normalized, ['paint', 'draw', 'sketch', 'canvas', 'artwork'], true);
    }

    private function normalizedSearchText(string $text): string
    {
        $tokens = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];
        $normalized = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $normalized[] = $this->stemToken($token);
        }

        return trim(implode(' ', array_values(array_filter($normalized))));
    }

    private function stemToken(string $token): string
    {
        foreach (['ings', 'ing', 'ies', 'es', 's'] as $suffix) {
            if (strlen($token) > strlen($suffix) + 2 && str_ends_with($token, $suffix)) {
                if ($suffix === 'ies') {
                    return substr($token, 0, -3) . 'y';
                }

                return substr($token, 0, -strlen($suffix));
            }
        }

        return $token;
    }

    private function indexDocument(array $document, string $sourceBytes): void
    {
        $source = SourceDocument::decode($sourceBytes);
        $operations = is_array($source['operations'] ?? null) ? $source['operations'] : [];
        $labels = ['paint document'];
        if ($operations === []) {
            $labels[] = 'empty drawing';
            $summary = 'Empty Paint document';
        } else {
            $labels[] = 'drawing';
            $labels[] = 'sketch';
            $labels[] = 'pencil';
            $colors = [];
            foreach ($operations as $operation) {
                $style = is_array($operation['style'] ?? null) ? $operation['style'] : [];
                $color = strtolower((string) ($style['color'] ?? ''));
                if ($color !== '') {
                    $colors[] = $color;
                }
            }
            foreach (array_unique($colors) as $color) {
                $labels[] = $color;
            }
            $summary = 'Paint drawing with ' . count($operations) . ' operation' . (count($operations) === 1 ? '' : 's');
        }

        $title = trim((string) ($document['title'] ?? ''));
        if ($title !== '' && strtolower($title) !== 'untitled paint') {
            $labels[] = $title;
            $summary .= ': ' . $title;
        }

        $this->documents->indexDocument($document, $summary, $labels);
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

    /** @param array<string, mixed> $resource @return array<string, mixed> */
    private function sourceResourceObject(array $resource, string $sourceBytes): array
    {
        $object = $this->resourceObject($resource, 'drawing.marks', 'Drawing marks');
        $object['content']['source'] = SourceDocument::decode($sourceBytes);

        return $object;
    }

    /** @param array<string, mixed> $resource @return array<string, mixed> */
    private function previewResourceObject(array $resource, string $previewBytes): array
    {
        $object = $this->resourceObject($resource, 'drawing.preview', 'Drawing preview');
        $object['content']['data_url'] = 'data:image/png;base64,' . base64_encode($previewBytes);

        return $object;
    }

    /**
     * A `graphics.svg` Resource (dev.elonn canonical/svg-profile.md) — the rendered SVG
     * projection of this document's Drawing Operations. A Runtime renders this markup
     * directly; it does not reconstruct graphics semantics from `drawing.marks` itself
     * (svg-profile.md, Ownership).
     *
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function graphicsResourceObject(array $resource, string $svgBytes): array
    {
        $object = $this->resourceObject($resource, 'graphics.svg', 'Drawing graphics');
        $object['content']['svg'] = $svgBytes;

        return $object;
    }
}
