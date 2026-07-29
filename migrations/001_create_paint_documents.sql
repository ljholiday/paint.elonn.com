CREATE TABLE IF NOT EXISTS paint_documents (
    id VARCHAR(80) NOT NULL PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    source_resource_id VARCHAR(80) NULL,
    preview_resource_id VARCHAR(80) NULL,
    created_by_service VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    modified_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_paint_documents_owner (owner),
    INDEX idx_paint_documents_source_resource (source_resource_id),
    INDEX idx_paint_documents_preview_resource (preview_resource_id),
    INDEX idx_paint_documents_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
