<?php

declare(strict_types=1);

use App\Application;
use App\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'elonn_paint',
        'username' => 'elonn_paint',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'storage_service' => [
        'resource_url' => 'https://storage.elonn.local/resources',
        'token' => '',
    ],
    'service_auth' => [
        'mind.elonn' => 'test-token',
    ],
];

$app = new Application($config);

$health = json_response($app, 'GET', '/health');
$ready = json_response($app, 'GET', '/ready');
$root = json_response($app, 'GET', '/');
$unauthenticated = json_response($app, 'POST', '/paint/call', [], ['content' => ['operation' => 'paint.create']]);
$missingOperation = json_response($app, 'POST', '/paint/call', service_headers(), ['content' => []]);
$createUnavailable = json_response($app, 'POST', '/paint/call', service_headers(), ['content' => ['operation' => 'paint.create']]);
$unsupported = json_response($app, 'POST', '/paint/call', service_headers(), ['content' => ['operation' => 'paint.draw']]);
$tokenHeader = json_response($app, 'POST', '/paint/call', [
    'x-elonn-service' => 'mind.elonn',
    'x-elonn-service-token' => 'test-token',
    'x-elonn-member-id' => '99',
], ['content' => ['operation' => 'paint.draw']]);

$checks = [
    'Health identifies Paint' => ($health['json']['service'] ?? '') === 'elonn_paint'
        && ($health['json']['status'] ?? '') === 'ok',
    'Ready exposes skeleton dependencies' => ($ready['status'] ?? 0) === 500
        && ($ready['json']['status'] ?? '') === 'not_ready'
        && ($ready['json']['dependencies']['mind_service_auth'] ?? '') === 'configured'
        && ($ready['json']['dependencies']['storage_service_config'] ?? '') === 'configured'
        && array_key_exists('database', $ready['json']['dependencies'] ?? [])
        && array_key_exists('document_store', $ready['json']['dependencies'] ?? []),
    'Root describes Paint ownership' => ($root['json']['service'] ?? '') === 'elonn_paint'
        && in_array('Paint document identity', $root['json']['owns'] ?? [], true)
        && in_array('Resource byte persistence', $root['json']['does_not_own'] ?? [], true),
    'Paint call requires service authentication' => ($unauthenticated['status'] ?? 0) === 401
        && has_error($unauthenticated['json'], 'paint.service_auth_failed'),
    'Paint call requires content.operation' => ($missingOperation['status'] ?? 0) === 400
        && has_error($missingOperation['json'], 'paint.operation_required')
        && ($missingOperation['json']['context']['caller'] ?? '') === 'mind.elonn',
    'Paint create reports document store dependency when database is missing' => ($createUnavailable['status'] ?? 0) === 503
        && has_error($createUnavailable['json'], 'paint.document_store_unavailable')
        && ($createUnavailable['json']['context']['caller'] ?? '') === 'mind.elonn',
    'Paint skeleton returns canonical unsupported operation' => ($unsupported['status'] ?? 0) === 422
        && has_error($unsupported['json'], 'paint.unsupported_operation')
        && ($unsupported['json']['type'] ?? '') === 'service'
        && ($unsupported['json']['context']['service'] ?? '') === 'paint',
    'Paint accepts X-Elonn-Service-Token and member context' => ($tokenHeader['status'] ?? 0) === 422
        && has_error($tokenHeader['json'], 'paint.unsupported_operation')
        && ($tokenHeader['json']['context']['caller'] ?? '') === 'mind.elonn'
        && ($tokenHeader['json']['context']['owner'] ?? '') === 'member:99',
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
    ];
}

/** @param array<string, mixed> $json */
function has_error(array $json, string $code): bool
{
    foreach (($json['errors'] ?? []) as $error) {
        if (is_array($error) && ($error['code'] ?? '') === $code) {
            return true;
        }
    }

    return false;
}
