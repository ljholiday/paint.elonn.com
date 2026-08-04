CREATE TABLE IF NOT EXISTS paint_document_search_index (
    document_id VARCHAR(80) NOT NULL PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    object_type VARCHAR(64) NOT NULL DEFAULT 'paint.document',
    search_text TEXT NOT NULL,
    semantic_summary TEXT NULL,
    semantic_labels JSON NULL,
    source_resource_id VARCHAR(80) NULL,
    preview_resource_id VARCHAR(80) NULL,
    index_status VARCHAR(32) NOT NULL DEFAULT 'ready',
    indexed_at DATETIME NOT NULL,
    KEY idx_paint_document_search_owner (owner),
    KEY idx_paint_document_search_object_type (object_type),
    KEY idx_paint_document_search_status (index_status),
    CONSTRAINT paint_document_search_document_fk
        FOREIGN KEY (document_id) REFERENCES paint_documents(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
