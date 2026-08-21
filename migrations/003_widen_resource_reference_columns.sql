ALTER TABLE paint_documents
    MODIFY source_resource_id VARCHAR(128) NULL,
    MODIFY preview_resource_id VARCHAR(128) NULL;

ALTER TABLE paint_document_search_index
    MODIFY source_resource_id VARCHAR(128) NULL,
    MODIFY preview_resource_id VARCHAR(128) NULL;
