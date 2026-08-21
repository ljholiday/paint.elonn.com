<?php

declare(strict_types=1);

namespace App\Paint;

use InvalidArgumentException;
use PDO;

/**
 * Persists stable Paint document records separate from immutable Resources.
 */
final class DocumentStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function create(
        string $owner,
        string $title,
        int $width,
        int $height,
        ?string $sourceResourceId,
        ?string $previewResourceId,
        string $createdByService,
    ): array {
        $owner = $this->normalizeOwner($owner);
        $title = $this->normalizeTitle($title);
        $this->validateDimensions($width, $height);
        $this->validateResourceId($sourceResourceId, 'Source Resource id');
        $this->validateResourceId($previewResourceId, 'Preview Resource id');
        $createdByService = $this->normalizeService($createdByService);

        $now = gmdate('Y-m-d H:i:s');
        $row = [
            'id' => $this->newDocumentId(),
            'owner' => $owner,
            'title' => $title,
            'width' => $width,
            'height' => $height,
            'source_resource_id' => $sourceResourceId,
            'preview_resource_id' => $previewResourceId,
            'created_by_service' => $createdByService,
            'created_at' => $now,
            'modified_at' => $now,
            'deleted_at' => null,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO paint_documents
                (id, owner, title, width, height, source_resource_id, preview_resource_id, created_by_service, created_at, modified_at, deleted_at)
             VALUES
                (:id, :owner, :title, :width, :height, :source_resource_id, :preview_resource_id, :created_by_service, :created_at, :modified_at, :deleted_at)'
        );
        $stmt->execute($row);

        return $this->canonical($row);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        if (!$this->validDocumentId($id)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT d.*, i.semantic_summary, i.semantic_labels, i.index_status, i.indexed_at
             FROM paint_documents d
             LEFT JOIN paint_document_search_index i ON i.document_id = d.id
             WHERE d.id = :id AND d.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->canonical($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentForOwner(string $owner, int $limit): array
    {
        $owner = $this->normalizeOwner($owner);
        $limit = max(1, min(50, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT d.*, i.semantic_summary, i.semantic_labels, i.index_status, i.indexed_at
             FROM paint_documents d
             LEFT JOIN paint_document_search_index i ON i.document_id = d.id
             WHERE d.owner = :owner AND d.deleted_at IS NULL
             ORDER BY d.modified_at DESC, d.created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['owner' => $owner]);

        return array_map(fn (array $row): array => $this->canonical($row), $stmt->fetchAll());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $owner, string $text, int $limit): array
    {
        $owner = $this->normalizeOwner($owner);
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($text)) . '%';

        $stmt = $this->pdo->prepare(
            'SELECT d.*, i.semantic_summary, i.semantic_labels, i.index_status, i.indexed_at,
                    CASE
                        WHEN LOWER(d.title) = :exact THEN 1.0
                        WHEN LOWER(d.title) LIKE :prefix THEN 0.88
                        WHEN LOWER(d.title) LIKE :like_score_title THEN 0.72
                        WHEN LOWER(COALESCE(i.search_text, "")) LIKE :like_score_text THEN 0.62
                        WHEN LOWER(COALESCE(i.semantic_summary, "")) LIKE :like_score_summary THEN 0.56
                        WHEN LOWER(COALESCE(JSON_UNQUOTE(i.semantic_labels), "")) LIKE :like_score_labels THEN 0.52
                        ELSE 0.0
                    END AS search_confidence
             FROM paint_documents d
             LEFT JOIN paint_document_search_index i ON i.document_id = d.id
             WHERE d.owner = :owner
               AND d.deleted_at IS NULL
               AND (
                    LOWER(d.title) LIKE :like_title
                    OR LOWER(COALESCE(i.search_text, "")) LIKE :like_text
                    OR LOWER(COALESCE(i.semantic_summary, "")) LIKE :like_summary
                    OR LOWER(COALESCE(JSON_UNQUOTE(i.semantic_labels), "")) LIKE :like_labels
               )
             ORDER BY search_confidence DESC, d.modified_at DESC, d.created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'owner' => $owner,
            'exact' => mb_strtolower($text),
            'prefix' => mb_strtolower($text) . '%',
            'like_score_title' => $like,
            'like_score_text' => $like,
            'like_score_summary' => $like,
            'like_score_labels' => $like,
            'like_title' => $like,
            'like_text' => $like,
            'like_summary' => $like,
            'like_labels' => $like,
        ]);

        return array_map(fn (array $row): array => $this->canonical($row), $stmt->fetchAll());
    }

    /**
     * @param array<int, string> $labels
     */
    public function indexDocument(array $document, string $summary, array $labels): void
    {
        $labelsJson = json_encode(array_values(array_unique(array_filter($labels))), JSON_UNESCAPED_SLASHES);
        if ($labelsJson === false) {
            $labelsJson = '[]';
        }
        $searchText = trim(implode(' ', array_filter([
            (string) ($document['title'] ?? ''),
            $summary,
            implode(' ', $labels),
        ])));

        $stmt = $this->pdo->prepare(
            'INSERT INTO paint_document_search_index
                (document_id, owner, object_type, search_text, semantic_summary, semantic_labels, source_resource_id, preview_resource_id, index_status, indexed_at)
             VALUES
                (:document_id, :owner, :object_type, :search_text, :semantic_summary, :semantic_labels, :source_resource_id, :preview_resource_id, :index_status, :indexed_at)
             ON DUPLICATE KEY UPDATE
                owner = VALUES(owner),
                search_text = VALUES(search_text),
                semantic_summary = VALUES(semantic_summary),
                semantic_labels = VALUES(semantic_labels),
                source_resource_id = VALUES(source_resource_id),
                preview_resource_id = VALUES(preview_resource_id),
                index_status = VALUES(index_status),
                indexed_at = VALUES(indexed_at)'
        );
        $stmt->execute([
            'document_id' => (string) $document['id'],
            'owner' => (string) $document['owner'],
            'object_type' => 'paint.document',
            'search_text' => $searchText,
            'semantic_summary' => $summary,
            'semantic_labels' => $labelsJson,
            'source_resource_id' => $document['source_resource_id'] ?? null,
            'preview_resource_id' => $document['preview_resource_id'] ?? null,
            'index_status' => 'ready',
            'indexed_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function updateResources(string $id, string $sourceResourceId, string $previewResourceId): ?array
    {
        if (!$this->validDocumentId($id)) {
            return null;
        }
        $this->validateResourceId($sourceResourceId, 'Source Resource id');
        $this->validateResourceId($previewResourceId, 'Preview Resource id');

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE paint_documents
             SET source_resource_id = :source_resource_id,
                 preview_resource_id = :preview_resource_id,
                 modified_at = :modified_at
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'source_resource_id' => $sourceResourceId,
            'preview_resource_id' => $previewResourceId,
            'modified_at' => $now,
            'id' => $id,
        ]);

        return $stmt->rowCount() === 1 ? $this->find($id) : null;
    }

    /** @return array<string, mixed>|null */
    public function rename(string $id, string $title): ?array
    {
        if (!$this->validDocumentId($id)) {
            return null;
        }

        $title = $this->normalizeTitle($title);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE paint_documents
             SET title = :title,
                 modified_at = :modified_at
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'title' => $title,
            'modified_at' => $now,
            'id' => $id,
        ]);

        return $stmt->rowCount() === 1 ? $this->find($id) : null;
    }

    public function delete(string $id): bool
    {
        if (!$this->validDocumentId($id)) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE paint_documents
             SET deleted_at = :deleted_at,
                 modified_at = :modified_at
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'deleted_at' => $now,
            'modified_at' => $now,
            'id' => $id,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function canonical(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'owner' => (string) $row['owner'],
            'title' => (string) $row['title'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
            'source_resource_id' => $row['source_resource_id'] === null ? null : (string) $row['source_resource_id'],
            'preview_resource_id' => $row['preview_resource_id'] === null ? null : (string) $row['preview_resource_id'],
            'created_by_service' => (string) $row['created_by_service'],
            'created_at' => $this->isoTime((string) $row['created_at']),
            'modified_at' => $this->isoTime((string) $row['modified_at']),
            'search_confidence' => isset($row['search_confidence']) ? (float) $row['search_confidence'] : null,
            'semantic_summary' => $row['semantic_summary'] ?? null,
            'semantic_labels' => $this->labels($row['semantic_labels'] ?? null),
            'index_status' => $row['index_status'] ?? null,
            'indexed_at' => isset($row['indexed_at']) && $row['indexed_at'] !== null ? $this->isoTime((string) $row['indexed_at']) : null,
        ];
    }

    /** @return array<int, string> */
    private function labels(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    private function normalizeOwner(string $owner): string
    {
        $owner = trim($owner);
        if ($owner === '') {
            throw new InvalidArgumentException('Paint document owner is required.');
        }

        return str_starts_with($owner, 'member:') || str_starts_with($owner, 'service:')
            ? $owner
            : 'member:' . $owner;
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return 'Untitled Paint';
        }

        if (mb_strlen($title) > 120) {
            throw new InvalidArgumentException('Paint document title is too long.');
        }

        return $title;
    }

    private function validateDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width > 8192 || $height > 8192) {
            throw new InvalidArgumentException('Paint document dimensions are invalid.');
        }
    }

    private function validateResourceId(?string $id, string $label): void
    {
        if ($id === null) {
            return;
        }
        $valid = preg_match('/^resource:[a-f0-9]{32}$/', $id) === 1
            || preg_match('/^storage\.elonn:sha256:[a-f0-9]{64}$/', $id) === 1;
        if (!$valid) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }

    private function normalizeService(string $service): string
    {
        $service = trim($service);
        if ($service === '') {
            throw new InvalidArgumentException('Creating service is required.');
        }

        return $service;
    }

    private function newDocumentId(): string
    {
        return 'paint.document:' . bin2hex(random_bytes(16));
    }

    private function validDocumentId(string $id): bool
    {
        return preg_match('/^paint\.document:[a-f0-9]{32}$/', $id) === 1;
    }

    private function isoTime(string $time): string
    {
        return str_replace(' ', 'T', $time) . 'Z';
    }
}
