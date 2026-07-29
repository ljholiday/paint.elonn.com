<?php

declare(strict_types=1);

namespace App\Paint;

interface DocumentRepository
{
    /**
     * @return array<string, mixed>
     */
    public function create(
        string $owner,
        string $title,
        int $width,
        int $height,
        ?string $sourceResourceId,
        ?string $previewResourceId,
        string $createdByService,
    ): array;
}
