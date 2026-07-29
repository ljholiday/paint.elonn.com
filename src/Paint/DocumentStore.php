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

        $stmt = $this->pdo->prepare('SELECT * FROM paint_documents WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->canonical($row) : null;
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
        ];
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
        if (preg_match('/^resource:[a-f0-9]{32}$/', $id) !== 1) {
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
