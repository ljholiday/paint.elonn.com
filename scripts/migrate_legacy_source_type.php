<?php

declare(strict_types=1);

/*
 * One-time data migration: rewrites every paint_documents row whose stored `drawing.marks`
 * Resource still carries the pre-rename internal type marker ("paint.source", from before
 * that marker was renamed to "drawing.marks") into the current canonical shape.
 *
 * SourceDocument::decode() intentionally stays strict (requires type === 'drawing.marks') --
 * the fix for a legacy document is to migrate its data once, not to make the live service
 * permanently tolerate two formats. This script is that one-time fix, safe to re-run: a
 * document already on the canonical marker is left untouched.
 *
 * A Resource is immutable, so "fixing" one is: decode the legacy bytes leniently (not via
 * SourceDocument::decode, which would reject them), re-encode with the corrected marker,
 * store that as a new Resource (StorageClient::replace, the same immutable-replace path
 * paint.draw already uses), and point the document row at the new Resource id.
 *
 * Usage: php scripts/migrate_legacy_source_type.php [--dry-run]
 */

use App\Paint\Database;
use App\Paint\DocumentStore;
use App\Paint\SourceDocument;
use App\Security\ServiceIdentity;
use App\Storage\StorageClient;
use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

$dryRun = in_array('--dry-run', $argv, true);

$pdo = Database::pdo($config);
$documents = new DocumentStore($pdo);

$storageConfig = (array) ($config['storage_service'] ?? []);
$storage = new StorageClient(
    (string) ($storageConfig['resource_url'] ?? ''),
    new ServiceIdentity((string) ($storageConfig['service_name'] ?? 'paint.elonn'), (string) ($storageConfig['token'] ?? '')),
    (int) ($storageConfig['timeout_seconds'] ?? 8)
);

const LEGACY_TYPE = 'paint.source';
const CURRENT_TYPE = 'drawing.marks';

$rows = $pdo->query(
    "SELECT id, source_resource_id, preview_resource_id, graphics_resource_id
     FROM paint_documents
     WHERE deleted_at IS NULL AND source_resource_id IS NOT NULL AND source_resource_id != ''"
)->fetchAll(PDO::FETCH_ASSOC);

$migrated = 0;
$alreadyCurrent = 0;
$unrecognized = 0;
$failed = 0;

foreach ($rows as $row) {
    $documentId = (string) $row['id'];
    $sourceResourceId = (string) $row['source_resource_id'];

    try {
        $bytes = $storage->content($sourceResourceId);
    } catch (Throwable $e) {
        echo "FAIL  {$documentId}: could not fetch {$sourceResourceId}: {$e->getMessage()}\n";
        $failed++;
        continue;
    }

    $decoded = json_decode($bytes, true);
    if (!is_array($decoded)) {
        echo "FAIL  {$documentId}: {$sourceResourceId} is not valid JSON, skipped\n";
        $failed++;
        continue;
    }

    $type = $decoded['type'] ?? null;
    if ($type === CURRENT_TYPE) {
        $alreadyCurrent++;
        continue;
    }
    if ($type !== LEGACY_TYPE) {
        echo "SKIP  {$documentId}: {$sourceResourceId} has unrecognized type " . var_export($type, true) . ", left untouched\n";
        $unrecognized++;
        continue;
    }

    $decoded['type'] = CURRENT_TYPE;
    $rewritten = json_encode($decoded, JSON_UNESCAPED_SLASHES);
    if (!is_string($rewritten)) {
        echo "FAIL  {$documentId}: could not re-encode rewritten source document\n";
        $failed++;
        continue;
    }

    // Confirms the rewritten bytes actually satisfy the live service's own strict decoder
    // before this migration commits to them -- never persist a "fix" that would itself be
    // rejected the next time this document is drawn on or read.
    try {
        SourceDocument::decode($rewritten);
    } catch (Throwable $e) {
        echo "FAIL  {$documentId}: rewritten source document still not canonical: {$e->getMessage()}\n";
        $failed++;
        continue;
    }

    if ($dryRun) {
        echo "WOULD MIGRATE  {$documentId}: {$sourceResourceId} ({$type} -> " . CURRENT_TYPE . ")\n";
        $migrated++;
        continue;
    }

    try {
        $newSource = $storage->replace($sourceResourceId, SourceDocument::MEDIA_TYPE, $rewritten, null);
        $updated = $documents->updateResources(
            $documentId,
            (string) $newSource['id'],
            (string) ($row['preview_resource_id'] ?? ''),
            $row['graphics_resource_id'] !== null ? (string) $row['graphics_resource_id'] : null
        );
        if ($updated === null) {
            echo "FAIL  {$documentId}: updateResources returned null\n";
            $failed++;
            continue;
        }
        echo "MIGRATED  {$documentId}: {$sourceResourceId} -> {$newSource['id']}\n";
        $migrated++;
    } catch (Throwable $e) {
        echo "FAIL  {$documentId}: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n";
echo ($dryRun ? "Dry run. " : '') . "migrated={$migrated} already_current={$alreadyCurrent} unrecognized={$unrecognized} failed={$failed}\n";

if ($failed > 0) {
    exit(1);
}
