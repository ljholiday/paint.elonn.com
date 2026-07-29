<?php

declare(strict_types=1);

use App\Application;
use App\Http\Request;
use App\Paint\DocumentNotFoundException;
use App\Paint\DocumentStore;
use App\Paint\PaintService;
use App\Paint\SourceDocument;
use App\Security\AuthenticatedService;
use App\Security\ServiceIdentity;
use App\Storage\StorageClient;
use App\Storage\StorageClientException;
use Dotenv\Dotenv;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';
$pdo = paint_test_pdo($config);
if ($pdo === null) {
    echo "SKIP: Configured Paint database is not accessible to the configured user.\n";
    exit(0);
}
paint_test_migrate($pdo);
paint_test_cleanup($pdo);

$store = new DocumentStore($pdo);
$storage = paint_test_storage($config);
if ($storage === null) {
    echo "SKIP: Configured Storage service is not accessible to Paint.\n";
    exit(0);
}

$service = new PaintService($store, $storage);
$dataset = $service->create(['title' => 'Sketch', 'width' => 640, 'height' => '480'], new AuthenticatedService('mind.elonn', '99'));
$createdDocumentId = (string) ($dataset['objects'][0]['id'] ?? '');
$persisted = $store->find($createdDocumentId);
$readDataset = $service->read(['document_id' => $createdDocumentId], new AuthenticatedService('mind.elonn', '99'));
$originalSourceResource = (string) ($dataset['objects'][0]['content']['source_resource'] ?? '');
$originalPreviewResource = (string) ($dataset['objects'][0]['content']['preview_resource'] ?? '');
$drawDataset = $service->draw([
    'document_id' => $createdDocumentId,
    'stroke' => [
        'tool' => 'pencil',
        'color' => '#336699',
        'width' => 6,
        'points' => [
            ['x' => 10, 'y' => 20],
            ['x' => 30, 'y' => 40],
        ],
    ],
], new AuthenticatedService('mind.elonn', '99'));
$drawnSourceResource = (string) ($drawDataset['objects'][0]['content']['source_resource'] ?? '');
$drawnPreviewResource = (string) ($drawDataset['objects'][0]['content']['preview_resource'] ?? '');
$drawnSource = SourceDocument::decode($storage->content($drawnSourceResource));
$drawnPreviewBytes = $storage->content($drawnPreviewResource);
$drawPersisted = $store->find($createdDocumentId);
$renameDataset = $service->rename([
    'document_id' => $createdDocumentId,
    'title' => 'Named Sketch',
], new AuthenticatedService('mind.elonn', '99'));
$renamedPersisted = $store->find($createdDocumentId);
$invalidRename = null;
try {
    $service->rename([
        'document_id' => $createdDocumentId,
        'title' => str_repeat('x', 121),
    ], new AuthenticatedService('mind.elonn', '99'));
} catch (InvalidArgumentException $exception) {
    $invalidRename = $exception;
}
$missingTitleRename = null;
try {
    $service->rename([
        'document_id' => $createdDocumentId,
    ], new AuthenticatedService('mind.elonn', '99'));
} catch (InvalidArgumentException $exception) {
    $missingTitleRename = $exception;
}
$missingRename = null;
try {
    $service->rename([
        'document_id' => 'paint.document:00000000000000000000000000000000',
        'title' => 'Missing',
    ], new AuthenticatedService('mind.elonn', '99'));
} catch (DocumentNotFoundException $exception) {
    $missingRename = $exception;
}
$invalidWidth = null;
try {
    $service->create(['width' => 0], new AuthenticatedService('mind.elonn', '99'));
} catch (InvalidArgumentException $exception) {
    $invalidWidth = $exception;
}
$invalidRead = null;
try {
    $service->read(['document_id' => 'paint.document:not-valid'], new AuthenticatedService('mind.elonn', '99'));
} catch (InvalidArgumentException $exception) {
    $invalidRead = $exception;
}
$missingRead = null;
try {
    $service->read(['document_id' => 'paint.document:00000000000000000000000000000000'], new AuthenticatedService('mind.elonn', '99'));
} catch (DocumentNotFoundException $exception) {
    $missingRead = $exception;
}
$invalidDraw = null;
try {
    $service->draw([
        'document_id' => $createdDocumentId,
        'stroke' => [
            'points' => [
                ['x' => 1, 'y' => 1],
            ],
        ],
    ], new AuthenticatedService('mind.elonn', '99'));
} catch (InvalidArgumentException $exception) {
    $invalidDraw = $exception;
}

$app = new Application($config);
$route = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.create',
        'title' => 'Route Sketch',
        'width' => 320,
        'height' => 240,
    ],
]);
$routeDocumentId = (string) ($route['json']['objects'][0]['id'] ?? '');
$routePersisted = $store->find($routeDocumentId);
$routeRead = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.read',
        'document_id' => $routeDocumentId,
    ],
]);
$routeDraw = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.draw',
        'document_id' => $routeDocumentId,
        'stroke' => [
            'tool' => 'pencil',
            'style' => [
                'color' => '#111111',
                'width' => 3,
            ],
            'geometry' => [
                'points' => [
                    ['x' => 5, 'y' => 5],
                    ['x' => 20, 'y' => 12],
                ],
            ],
        ],
    ],
]);
$routeRename = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.rename',
        'document_id' => $routeDocumentId,
        'title' => 'Route Named Sketch',
    ],
]);
$routeRenamePersisted = $store->find($routeDocumentId);
$routeMissingRead = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.read',
        'document_id' => 'paint.document:00000000000000000000000000000000',
    ],
]);
$routeInvalidDraw = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.draw',
        'document_id' => $routeDocumentId,
        'stroke' => [
            'points' => [
                ['x' => 5, 'y' => 5],
            ],
        ],
    ],
]);
$routeInvalidRename = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.rename',
        'document_id' => $routeDocumentId,
        'title' => str_repeat('x', 121),
    ],
]);
$routeMissingRename = json_response($app, 'POST', '/paint/call', service_headers($config), [
    'content' => [
        'operation' => 'paint.rename',
        'document_id' => 'paint.document:00000000000000000000000000000000',
        'title' => 'Missing',
    ],
]);
$createdResourceIds = array_merge(
    resource_ids($dataset),
    resource_ids($readDataset),
    resource_ids($drawDataset),
    resource_ids($renameDataset),
    resource_ids($route['json']),
    resource_ids($routeRead['json']),
    resource_ids($routeDraw['json']),
    resource_ids($routeRename['json'])
);

$checks = [
    'paint.create returns a Service Dataset' => ($dataset['type'] ?? '') === 'service'
        && ($dataset['scope'] ?? '') === 'object'
        && ($dataset['mode'] ?? '') === 'snapshot'
        && ($dataset['context']['operation'] ?? '') === 'paint.create',
    'paint.create persists stable Paint document identity' => $persisted !== null
        && ($persisted['owner'] ?? '') === 'member:99'
        && ($persisted['created_by_service'] ?? '') === 'mind.elonn',
    'paint.create returns paint.document Object with Storage Resources' => count($dataset['objects'] ?? []) === 1
        && ($dataset['objects'][0]['type'] ?? '') === 'paint.document'
        && ($dataset['objects'][0]['title'] ?? '') === 'Sketch'
        && ($dataset['objects'][0]['content']['width'] ?? null) === 640
        && ($dataset['objects'][0]['content']['height'] ?? null) === 480
        && is_resource_id((string) ($dataset['objects'][0]['content']['source_resource'] ?? ''))
        && is_resource_id((string) ($dataset['objects'][0]['content']['preview_resource'] ?? ''))
        && ($dataset['objects'][0]['content']['storage_state'] ?? '') === 'ready'
        && ($dataset['objects'][0]['content']['surface']['mode'] ?? '') === 'hosted'
        && ($dataset['objects'][0]['content']['surface']['service'] ?? '') === 'paint'
        && ($dataset['objects'][0]['content']['surface']['kind'] ?? '') === 'editor'
        && is_resource_id((string) ($dataset['objects'][0]['content']['surface']['resources']['source'] ?? ''))
        && is_resource_id((string) ($dataset['objects'][0]['content']['surface']['resources']['preview'] ?? ''))
        && count($dataset['objects'][0]['resources'] ?? []) === 2
        && count($dataset['resources'] ?? []) === 2
        && count(source_document($dataset)['operations'] ?? []) === 0
        && str_starts_with((string) (preview_resource($dataset)['content']['data_url'] ?? ''), 'data:image/png;base64,'),
    'paint.create returns workspace placement and open Action' => count($dataset['placements'] ?? []) === 1
        && ($dataset['placements'][0]['type'] ?? '') === 'workspace'
        && count($dataset['actions'] ?? []) === 1
        && ($dataset['actions'][0]['type'] ?? '') === 'open',
    'paint.create rejects invalid dimensions' => $invalidWidth instanceof InvalidArgumentException,
    'paint.read returns current paint.document and Resource metadata' => ($readDataset['context']['operation'] ?? '') === 'paint.read'
        && ($readDataset['objects'][0]['id'] ?? '') === $createdDocumentId
        && ($readDataset['objects'][0]['type'] ?? '') === 'paint.document'
        && ($readDataset['objects'][0]['content']['storage_state'] ?? '') === 'ready'
        && ($readDataset['objects'][0]['content']['surface']['mode'] ?? '') === 'hosted'
        && ($readDataset['objects'][0]['content']['surface']['service'] ?? '') === 'paint'
        && ($readDataset['objects'][0]['content']['surface']['resources']['source'] ?? '') === ($dataset['objects'][0]['content']['source_resource'] ?? null)
        && ($readDataset['objects'][0]['content']['surface']['resources']['preview'] ?? '') === ($dataset['objects'][0]['content']['preview_resource'] ?? null)
        && count($readDataset['resources'] ?? []) === 2
        && count(source_document($readDataset)['operations'] ?? []) === 0
        && str_starts_with((string) (preview_resource($readDataset)['content']['data_url'] ?? ''), 'data:image/png;base64,'),
    'paint.read rejects invalid document ids' => $invalidRead instanceof InvalidArgumentException,
    'paint.read reports missing documents' => $missingRead instanceof DocumentNotFoundException,
    'paint.draw appends one stroke to replacement source Resource' => ($drawDataset['context']['operation'] ?? '') === 'paint.draw'
        && ($drawDataset['objects'][0]['id'] ?? '') === $createdDocumentId
        && is_resource_id($drawnSourceResource)
        && $drawnSourceResource !== $originalSourceResource
        && is_resource_id($drawnPreviewResource)
        && $drawnPreviewResource !== $originalPreviewResource
        && ($drawPersisted['source_resource_id'] ?? '') === $drawnSourceResource
        && ($drawPersisted['preview_resource_id'] ?? '') === $drawnPreviewResource
        && count($drawnSource['operations']) === 1
        && ($drawnSource['operations'][0]['type'] ?? '') === 'stroke'
        && ($drawnSource['operations'][0]['tool'] ?? '') === 'pencil'
        && ($drawnSource['operations'][0]['style']['color'] ?? '') === '#336699'
        && ($drawnSource['operations'][0]['style']['width'] ?? null) === 6
        && count($drawnSource['operations'][0]['geometry']['points'] ?? []) === 2
        && count(source_document($drawDataset)['operations'] ?? []) === 1
        && str_starts_with($drawnPreviewBytes, "\x89PNG\r\n\x1a\n")
        && str_starts_with((string) (preview_resource($drawDataset)['content']['data_url'] ?? ''), 'data:image/png;base64,'),
    'paint.draw rejects incomplete strokes' => $invalidDraw instanceof InvalidArgumentException,
    'paint.rename updates document title without replacing Resources' => ($renameDataset['context']['operation'] ?? '') === 'paint.rename'
        && ($renameDataset['objects'][0]['id'] ?? '') === $createdDocumentId
        && ($renameDataset['objects'][0]['title'] ?? '') === 'Named Sketch'
        && ($renameDataset['objects'][0]['content']['name'] ?? '') === 'Named Sketch'
        && ($renameDataset['objects'][0]['content']['source_resource'] ?? '') === $drawnSourceResource
        && ($renameDataset['objects'][0]['content']['preview_resource'] ?? '') === $drawnPreviewResource
        && ($renamedPersisted['title'] ?? '') === 'Named Sketch'
        && count(source_document($renameDataset)['operations'] ?? []) === 1
        && str_starts_with((string) (preview_resource($renameDataset)['content']['data_url'] ?? ''), 'data:image/png;base64,'),
    'paint.rename rejects invalid titles and missing documents' => $invalidRename instanceof InvalidArgumentException
        && $missingTitleRename instanceof InvalidArgumentException
        && $missingRename instanceof DocumentNotFoundException,
    'POST /paint/call routes paint.create through DocumentStore' => ($route['status'] ?? 0) === 201
        && ($route['json']['objects'][0]['type'] ?? '') === 'paint.document'
        && ($route['json']['objects'][0]['title'] ?? '') === 'Route Sketch'
        && $routePersisted !== null
        && ($routePersisted['owner'] ?? '') === 'member:99',
    'POST /paint/call routes paint.read through DocumentStore' => ($routeRead['status'] ?? 0) === 200
        && ($routeRead['json']['context']['operation'] ?? '') === 'paint.read'
        && ($routeRead['json']['objects'][0]['id'] ?? '') === $routeDocumentId
        && count($routeRead['json']['resources'] ?? []) === 2,
    'POST /paint/call routes paint.draw through DocumentStore' => ($routeDraw['status'] ?? 0) === 200
        && ($routeDraw['json']['context']['operation'] ?? '') === 'paint.draw'
        && ($routeDraw['json']['objects'][0]['id'] ?? '') === $routeDocumentId
        && ($routeDraw['json']['objects'][0]['content']['source_resource'] ?? '') !== ($route['json']['objects'][0]['content']['source_resource'] ?? '')
        && ($routeDraw['json']['objects'][0]['content']['preview_resource'] ?? '') !== ($route['json']['objects'][0]['content']['preview_resource'] ?? ''),
    'POST /paint/call routes paint.rename through DocumentStore' => ($routeRename['status'] ?? 0) === 200
        && ($routeRename['json']['context']['operation'] ?? '') === 'paint.rename'
        && ($routeRename['json']['objects'][0]['id'] ?? '') === $routeDocumentId
        && ($routeRename['json']['objects'][0]['title'] ?? '') === 'Route Named Sketch'
        && ($routeRename['json']['objects'][0]['content']['source_resource'] ?? '') === ($routeDraw['json']['objects'][0]['content']['source_resource'] ?? '')
        && ($routeRenamePersisted['title'] ?? '') === 'Route Named Sketch',
    'POST /paint/call rejects invalid paint.draw payloads' => ($routeInvalidDraw['status'] ?? 0) === 422
        && ($routeInvalidDraw['json']['errors'][0]['code'] ?? '') === 'paint.invalid_draw_call',
    'POST /paint/call rejects invalid paint.rename payloads' => ($routeInvalidRename['status'] ?? 0) === 422
        && ($routeInvalidRename['json']['errors'][0]['code'] ?? '') === 'paint.invalid_rename_call',
    'POST /paint/call returns not_found for missing paint.read documents' => ($routeMissingRead['status'] ?? 0) === 404
        && ($routeMissingRead['json']['errors'][0]['code'] ?? '') === 'paint.document_not_found',
    'POST /paint/call returns not_found for missing paint.rename documents' => ($routeMissingRename['status'] ?? 0) === 404
        && ($routeMissingRename['json']['errors'][0]['code'] ?? '') === 'paint.document_not_found',
];

foreach (array_unique($createdResourceIds) as $resourceId) {
    $storage->delete($resourceId);
}
if ($createdDocumentId !== '') {
    $store->delete($createdDocumentId);
}
if ($routeDocumentId !== '') {
    $store->delete($routeDocumentId);
}
paint_test_cleanup($pdo);

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

/** @param array<string, mixed> $config */
function paint_test_storage(array $config): ?StorageClient
{
    $storageConfig = (array) ($config['storage_service'] ?? []);
    $resourceUrl = (string) ($storageConfig['resource_url'] ?? '');
    $token = (string) ($storageConfig['token'] ?? '');
    if ($resourceUrl === '' || $token === '') {
        return null;
    }

    $client = new StorageClient(
        $resourceUrl,
        new ServiceIdentity((string) ($storageConfig['service_name'] ?? 'paint.elonn'), $token),
        (int) ($storageConfig['timeout_seconds'] ?? 8)
    );

    try {
        $resource = $client->create('text/plain', 'Paint Storage availability check.', '99');
        $client->delete((string) ($resource['id'] ?? ''));
    } catch (StorageClientException) {
        return null;
    }

    return $client;
}

function is_resource_id(string $id): bool
{
    return preg_match('/^resource:[a-f0-9]{32}$/', $id) === 1;
}

/** @param array<string, mixed> $dataset @return array<int, string> */
function resource_ids(array $dataset): array
{
    $ids = [];
    foreach (($dataset['resources'] ?? []) as $resource) {
        if (is_array($resource) && is_resource_id((string) ($resource['id'] ?? ''))) {
            $ids[] = (string) $resource['id'];
        }
    }

    return $ids;
}

/** @param array<string, mixed> $dataset @return array<string, mixed> */
function source_document(array $dataset): array
{
    foreach (($dataset['resources'] ?? []) as $resource) {
        if (is_array($resource) && (($resource['content']['kind'] ?? '') === 'paint.source') && is_array($resource['content']['source'] ?? null)) {
            return $resource['content']['source'];
        }
    }

    return [];
}

/** @param array<string, mixed> $dataset @return array<string, mixed> */
function preview_resource(array $dataset): array
{
    foreach (($dataset['resources'] ?? []) as $resource) {
        if (is_array($resource) && (($resource['content']['kind'] ?? '') === 'paint.preview')) {
            return $resource;
        }
    }

    return [];
}

if ($failed > 0) {
    exit(1);
}

/** @param array<string, mixed> $config */
function paint_test_pdo(array $config): ?PDO
{
    $database = $config['database'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $database['host'],
        $database['port'],
        $database['name'],
        $database['charset']
    );

    try {
        return new PDO($dsn, $database['username'], $database['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable) {
        return null;
    }
}

function paint_test_migrate(PDO $pdo): void
{
    $migration = file_get_contents(BASE_PATH . '/migrations/001_create_paint_documents.sql');
    if (is_string($migration)) {
        $pdo->exec($migration);
    }
}

function paint_test_cleanup(PDO $pdo): void
{
    $pdo->exec("DELETE FROM paint_documents WHERE created_by_service = 'mind.elonn'");
}

/**
 * @param array<string, string> $headers
 * @param array<string, mixed> $body
 * @return array{status:int,json:array<string,mixed>}
 */
function json_response(Application $app, string $method, string $path, array $headers = [], array $body = []): array
{
    $request = Request::testing($method, $path, array_merge(['content-type' => 'application/json'], $headers), json_encode($body), $body);
    $response = $app->handleRequest($request);
    $decoded = json_decode($response->body(), true);

    return [
        'status' => $response->status(),
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

/** @param array<string, mixed> $config @return array<string, string> */
function service_headers(array $config): array
{
    return [
        'x-elonn-service' => 'mind.elonn',
        'authorization' => 'Bearer ' . (string) ($config['service_auth']['mind.elonn'] ?? ''),
        'x-elonn-member-id' => '99',
    ];
}
