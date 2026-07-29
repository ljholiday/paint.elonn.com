<?php

declare(strict_types=1);

use App\Application;
use App\Http\Request;
use App\Paint\DocumentRepository;
use App\Paint\PaintService;
use App\Security\AuthenticatedService;

require dirname(__DIR__) . '/vendor/autoload.php';

final class FakeDocumentRepository implements DocumentRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $created = [];

    public function create(
        string $owner,
        string $title,
        int $width,
        int $height,
        ?string $sourceResourceId,
        ?string $previewResourceId,
        string $createdByService,
    ): array {
        $document = [
            'id' => 'paint.document:' . str_repeat((string) (count($this->created) + 1), 32),
            'owner' => $owner,
            'title' => $title,
            'width' => $width,
            'height' => $height,
            'source_resource_id' => $sourceResourceId,
            'preview_resource_id' => $previewResourceId,
            'created_by_service' => $createdByService,
            'created_at' => '2026-07-29T00:00:00Z',
            'modified_at' => '2026-07-29T00:00:00Z',
        ];
        $this->created[] = $document;

        return $document;
    }
}

$repo = new FakeDocumentRepository();
$service = new PaintService($repo);
$dataset = $service->create(['title' => 'Sketch', 'width' => 640, 'height' => '480'], new AuthenticatedService('mind.elonn', '99'));
$invalidWidth = null;
try {
    $service->create(['width' => 0], new AuthenticatedService('mind.elonn', '99'));
} catch (InvalidArgumentException $exception) {
    $invalidWidth = $exception;
}

$app = new Application([
    'database' => [],
    'storage_service' => [
        'resource_url' => 'https://storage.elonn.local/resources',
        'token' => '',
    ],
    'service_auth' => [
        'mind.elonn' => 'test-token',
    ],
], new FakeDocumentRepository());
$route = json_response($app, 'POST', '/paint/call', service_headers(), [
    'content' => [
        'operation' => 'paint.create',
        'title' => 'Route Sketch',
        'width' => 320,
        'height' => 240,
    ],
]);

$checks = [
    'paint.create returns a Service Dataset' => ($dataset['type'] ?? '') === 'service'
        && ($dataset['scope'] ?? '') === 'object'
        && ($dataset['mode'] ?? '') === 'snapshot'
        && ($dataset['context']['operation'] ?? '') === 'paint.create',
    'paint.create persists stable Paint document identity' => count($repo->created) === 1
        && ($repo->created[0]['owner'] ?? '') === 'member:99'
        && ($repo->created[0]['created_by_service'] ?? '') === 'mind.elonn',
    'paint.create returns paint.document Object shell without fake Resources' => count($dataset['objects'] ?? []) === 1
        && ($dataset['objects'][0]['type'] ?? '') === 'paint.document'
        && ($dataset['objects'][0]['title'] ?? '') === 'Sketch'
        && ($dataset['objects'][0]['content']['width'] ?? null) === 640
        && ($dataset['objects'][0]['content']['height'] ?? null) === 480
        && array_key_exists('source_resource', $dataset['objects'][0]['content'] ?? [])
        && array_key_exists('preview_resource', $dataset['objects'][0]['content'] ?? [])
        && $dataset['objects'][0]['content']['source_resource'] === null
        && $dataset['objects'][0]['content']['preview_resource'] === null
        && ($dataset['objects'][0]['content']['storage_state'] ?? '') === 'pending_resources'
        && ($dataset['objects'][0]['resources'] ?? ['fake']) === [],
    'paint.create returns workspace placement and open Action' => count($dataset['placements'] ?? []) === 1
        && ($dataset['placements'][0]['type'] ?? '') === 'workspace'
        && count($dataset['actions'] ?? []) === 1
        && ($dataset['actions'][0]['type'] ?? '') === 'open',
    'paint.create rejects invalid dimensions' => $invalidWidth instanceof InvalidArgumentException,
    'POST /paint/call routes paint.create' => ($route['status'] ?? 0) === 201
        && ($route['json']['objects'][0]['type'] ?? '') === 'paint.document'
        && ($route['json']['objects'][0]['title'] ?? '') === 'Route Sketch',
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

if ($failed > 0) {
    exit(1);
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

/** @return array<string, string> */
function service_headers(): array
{
    return [
        'x-elonn-service' => 'mind.elonn',
        'authorization' => 'Bearer test-token',
        'x-elonn-member-id' => '99',
    ];
}
