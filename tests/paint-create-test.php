<?php

declare(strict_types=1);

use App\Application;
use App\Http\Request;
use App\Paint\DocumentStore;
use App\Paint\PaintService;
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
$invalidWidth = null;
try {
    $service->create(['width' => 0], new AuthenticatedService('mind.elonn', '99'));
} catch (InvalidArgumentException $exception) {
    $invalidWidth = $exception;
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
$createdResourceIds = array_merge(resource_ids($dataset), resource_ids($route['json']));

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
        && count($dataset['resources'] ?? []) === 2,
    'paint.create returns workspace placement and open Action' => count($dataset['placements'] ?? []) === 1
        && ($dataset['placements'][0]['type'] ?? '') === 'workspace'
        && count($dataset['actions'] ?? []) === 1
        && ($dataset['actions'][0]['type'] ?? '') === 'open',
    'paint.create rejects invalid dimensions' => $invalidWidth instanceof InvalidArgumentException,
    'POST /paint/call routes paint.create through DocumentStore' => ($route['status'] ?? 0) === 201
        && ($route['json']['objects'][0]['type'] ?? '') === 'paint.document'
        && ($route['json']['objects'][0]['title'] ?? '') === 'Route Sketch'
        && $routePersisted !== null
        && ($routePersisted['owner'] ?? '') === 'member:99',
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
