ALTER TABLE paint_documents
    ADD COLUMN graphics_resource_id VARCHAR(128) NULL AFTER preview_resource_id,
    ADD INDEX idx_paint_documents_graphics_resource (graphics_resource_id);
