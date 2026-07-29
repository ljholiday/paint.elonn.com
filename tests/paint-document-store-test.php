<?php

declare(strict_types=1);

use App\Paint\DocumentStore;
use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

final class PaintDocumentStoreTest
{
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function run(): void
    {
        echo "Running Paint document store tests...\n\n";
        $this->testValidationRequiresOwner();
        $this->testCreateFindUpdateDeleteWhenDatabaseConfigured();
        $this->report();
    }

    private function testValidationRequiresOwner(): void
    {
        echo "Testing Paint document validation requires owner... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Paint database is not configured for integration tests.');
            return;
        }

        try {
            try {
                $store->create('', 'Sketch', 1024, 768, null, null, 'paint.test');
            } catch (InvalidArgumentException $exception) {
                $this->pass('Owner validation rejected empty owner.');
                return;
            }

            $this->fail('Empty owner was accepted.');
        } finally {
            $cleanup();
        }
    }

    private function testCreateFindUpdateDeleteWhenDatabaseConfigured(): void
    {
        echo "Testing Paint document persistence... ";
        [$store, $cleanup] = $this->store();
        if ($store === null) {
            $this->skip('Paint database is not configured for integration tests.');
            return;
        }

        try {
            $source = 'resource:' . str_repeat('a', 32);
            $preview = 'resource:' . str_repeat('b', 32);
            $nextSource = 'resource:' . str_repeat('c', 32);
            $nextPreview = 'resource:' . str_repeat('d', 32);
            $document = $store->create('42', '', 1024, 768, $source, $preview, 'paint.test');
            $found = $store->find($document['id']);
            $updated = $store->updateResources($document['id'], $nextSource, $nextPreview);
            $deleted = $store->delete($document['id']);

            if ($found !== null
                && $updated !== null
                && $deleted
                && str_starts_with($document['id'], 'paint.document:')
                && $document['owner'] === 'member:42'
                && $document['title'] === 'Untitled Paint'
                && $document['width'] === 1024
                && $document['height'] === 768
                && $found['source_resource_id'] === $source
                && $updated['source_resource_id'] === $nextSource
                && $updated['preview_resource_id'] === $nextPreview
                && $store->find($document['id']) === null
            ) {
                $this->pass('Paint document record kept stable identity and current Resource references.');
                return;
            }

            $this->fail('Paint document persistence did not match expected shape.');
        } finally {
            $cleanup();
        }
    }

    /**
     * @return array{0:DocumentStore|null,1:callable():void}
     */
    private function store(): array
    {
        $config = $this->config();

        try {
            $database = $config['database'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $database['host'],
                $database['port'],
                $database['name'],
                $database['charset']
            );
            $pdo = new PDO($dsn, $database['username'], $database['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $migration = file_get_contents(BASE_PATH . '/migrations/001_create_paint_documents.sql');
            if (is_string($migration)) {
                $pdo->exec($migration);
            }
            $pdo->exec("DELETE FROM paint_documents WHERE created_by_service = 'paint.test'");
        } catch (Throwable) {
            return [null, static function (): void {
            }];
        }

        return [
            new DocumentStore($pdo),
            static function () use ($pdo): void {
                $pdo->exec("DELETE FROM paint_documents WHERE created_by_service = 'paint.test'");
            },
        ];
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        if ($this->config === null) {
            Dotenv::createImmutable(BASE_PATH)->safeLoad();
            $this->config = require BASE_PATH . '/config/config.php';
        }

        return $this->config;
    }

    private function pass(string $message): void
    {
        $this->passed++;
        echo "PASS: {$message}\n";
    }

    private function fail(string $message): void
    {
        $this->failed++;
        echo "FAIL: {$message}\n";
    }

    private function skip(string $message): void
    {
        $this->skipped++;
        echo "SKIP: {$message}\n";
    }

    private function report(): void
    {
        echo "\nPassed: {$this->passed}  Failed: {$this->failed}  Skipped: {$this->skipped}\n";
        if ($this->failed > 0) {
            exit(1);
        }
    }
}

(new PaintDocumentStoreTest())->run();
