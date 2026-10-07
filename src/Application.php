<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Paint\Database;
use App\Paint\DocumentNotFoundException;
use App\Paint\DocumentStore;
use App\Paint\PaintService;
use App\Security\AuthenticatedService;
use App\Security\ServiceAuthenticator;
use App\Security\ServiceIdentity;
use App\Security\SignedRequestVerifier;
use App\Storage\StorageClient;
use App\Storage\StorageClientException;
use InvalidArgumentException;
use Throwable;

/**
 * Wires Paint service routes.
 */
final class Application
{
    private Router $router;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->router = new Router();
        $this->routes();
    }

    public function handle(): void
    {
        $this->handleRequest(Request::fromGlobals())->send();
    }

    public function handleRequest(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    private function routes(): void
    {
        $this->router->get('/health', fn (): Response => Response::json([
            'status' => 'ok',
            'service' => 'elonn_paint',
        ]));
        $this->router->get('/descriptor', fn (): Response => Response::json(ServiceDescriptor::payload()));

        $this->router->get('/ready', function (): Response {
            $storage = (array) ($this->config['storage_service'] ?? []);
            $serviceAuth = (array) ($this->config['service_auth'] ?? []);
            $dependencies = [
                'database' => 'error',
                'mind_service_auth' => ((string) ($serviceAuth['mind.elonn'] ?? '')) !== '' ? 'configured' : 'missing',
                'storage_service_config' => ((string) ($storage['resource_url'] ?? '')) !== '' ? 'configured' : 'error',
                'storage_service_auth' => ((string) ($storage['token'] ?? '')) !== '' ? 'configured' : 'missing',
                'storage_service' => 'error',
                'document_store' => 'error',
            ];

            try {
                $pdo = Database::pdo($this->config);
                $pdo->query('SELECT 1');
                $dependencies['database'] = Database::schemaReady($pdo) ? 'connected' : 'schema_missing';
                $dependencies['document_store'] = $dependencies['database'] === 'connected' ? 'connected' : 'schema_missing';
            } catch (Throwable $throwable) {
                error_log('[paint] /ready database check failed: ' . $throwable->getMessage());
            }

            $dependencies['storage_service'] = $this->storageReady($storage);

            $ready = $dependencies['mind_service_auth'] === 'configured'
                && $dependencies['storage_service_config'] === 'configured'
                && $dependencies['storage_service_auth'] === 'configured'
                && $dependencies['storage_service'] === 'ready'
                && $dependencies['database'] === 'connected'
                && $dependencies['document_store'] === 'connected';

            return Response::json([
                'status' => $ready ? 'ready' : 'not_ready',
                'service' => 'elonn_paint',
                'dependencies' => $dependencies,
            ], $ready ? 200 : 500);
        });

        $this->router->get('/metrics', function (Request $request): Response {
            $startedAt = microtime(true);
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'paint.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $database = 'error';
            try {
                $pdo = Database::pdo($this->config);
                $pdo->query('SELECT 1');
                $database = Database::schemaReady($pdo) ? 'connected' : 'schema_missing';
            } catch (Throwable $throwable) {
                error_log('[paint] /metrics database check failed: ' . $throwable->getMessage());
            }

            return Response::json([
                'contract_version' => '1.0',
                'service' => 'paint.elonn',
                'status' => $database === 'connected' ? 'ok' : 'degraded',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                'response_time_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'custom_metrics' => [
                    'database' => $database,
                ],
            ]);
        });

        $this->router->get('/administrative-interface', function (Request $request): Response {
            // canonical/administrative-interface.json: Paint's own administrative status and
            // capability baseline, for Admin's observation only. configuration.mutable,
            // diagnostics, and control.{restart,drain} are honestly reported as unavailable --
            // Paint implements none of them yet.
            $caller = $this->authenticatedService($request);
            if ($caller === null || $caller->name !== 'admin.elonn') {
                return Response::json([
                    'errors' => [[
                        'code' => 'paint.service_auth_failed',
                        'class' => 'auth',
                        'message' => 'Authenticated admin service request is required.',
                    ]],
                ], 401);
            }

            $storage = (array) ($this->config['storage_service'] ?? []);
            $checks = [];
            $healthy = true;

            try {
                $pdo = Database::pdo($this->config);
                $pdo->query('SELECT 1');
                $schemaReady = Database::schemaReady($pdo);
                $checks[] = ['name' => 'database', 'state' => 'healthy', 'detail' => 'Connection and SELECT 1 succeeded.'];
                $checks[] = $schemaReady
                    ? ['name' => 'schema', 'state' => 'healthy', 'detail' => 'Required schema is present.']
                    : ['name' => 'schema', 'state' => 'unhealthy', 'detail' => 'Required schema is missing.'];
                $healthy = $healthy && $schemaReady;
            } catch (Throwable $throwable) {
                $healthy = false;
                $checks[] = ['name' => 'database', 'state' => 'unhealthy', 'detail' => $throwable->getMessage()];
            }

            $storageReady = $this->storageReady($storage) === 'ready';
            $checks[] = [
                'name' => 'storage_service',
                'state' => $storageReady ? 'healthy' : 'unhealthy',
                'detail' => $storageReady ? 'Storage service is reachable.' : 'Storage service is not reachable.',
            ];
            $healthy = $healthy && $storageReady;

            return Response::json([
                'component' => 'paint.elonn',
                'component_version' => '3',
                'deployment_id' => '',
                'status' => $healthy ? 'running' : 'degraded',
                'health' => [
                    'state' => $healthy ? 'healthy' : 'unhealthy',
                    'checks' => $checks,
                ],
                'configuration' => [
                    'inspectable' => [
                        'database.name' => (string) ($this->config['database']['name'] ?? ''),
                        'storage_service.base_url' => (string) ($storage['base_url'] ?? ''),
                        'storage_service.resource_url' => (string) ($storage['resource_url'] ?? ''),
                        'storage_service.timeout_seconds' => (int) ($storage['timeout_seconds'] ?? 0),
                    ],
                    'mutable' => [],
                ],
                'maintenance' => ['state' => 'normal', 'reason' => ''],
                'diagnostics' => ['available' => []],
                'control' => [
                    'restart' => ['state' => 'unavailable', 'reason' => 'Paint does not implement an administrative restart operation.'],
                    'drain' => ['state' => 'unavailable', 'reason' => 'Paint does not implement an administrative drain operation.'],
                ],
                'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'metadata' => (object) [],
            ]);
        });

        $this->router->get('/', fn (): Response => Response::json([
            'service' => 'elonn_paint',
            'description' => 'Elonn Paint document service.',
            'owns' => [
                'Paint document identity',
                'Paint document records',
                'Paint document operations',
                'Paint document search',
                'Paint document lifecycle',
            ],
            'does_not_own' => [
                'Resource byte persistence',
                'Runtime presentation',
                'World placement',
                'Member authentication',
            ],
            'routes' => [
                'GET /health',
                'GET /ready',
                'POST /paint/call',
            ],
        ]));

        $this->router->post('/paint/call', function (Request $request): Response {
            $caller = $this->authenticatedService($request);
            if ($caller === null) {
                return $this->datasetError('paint.service_auth_failed', 'auth', 'Paint service authentication failed.', 401);
            }

            $operation = (string) (($request->parsedBody()['content']['operation'] ?? ''));
            if ($operation === '') {
                return $this->datasetError('paint.operation_required', 'invalid_call', 'Paint Call content.operation is required.', 400, $caller);
            }

            if ($operation === 'paint.create') {
                try {
                    return Response::json($this->paintService()->create(
                        is_array($request->parsedBody()['content'] ?? null) ? $request->parsedBody()['content'] : [],
                        $caller
                    ), 201);
                } catch (InvalidArgumentException $exception) {
                    return $this->datasetError('paint.invalid_create_call', 'invalid_call', $exception->getMessage(), 422, $caller);
                } catch (StorageClientException $exception) {
                    error_log('[paint] paint.create storage failed: ' . $exception->getMessage());
                    return $this->datasetError($exception->errorCode, $exception->errorClass, $exception->getMessage(), $exception->httpStatus, $caller);
                } catch (Throwable $throwable) {
                    error_log('[paint] paint.create failed: ' . $throwable->getMessage());
                    return $this->datasetError('paint.document_store_unavailable', 'dependency', 'Paint document store is unavailable.', 503, $caller);
                }
            }

            if ($operation === 'paint.read') {
                try {
                    return Response::json($this->paintService()->read(
                        is_array($request->parsedBody()['content'] ?? null) ? $request->parsedBody()['content'] : [],
                        $caller
                    ));
                } catch (InvalidArgumentException $exception) {
                    return $this->datasetError('paint.invalid_read_call', 'invalid_call', $exception->getMessage(), 422, $caller);
                } catch (DocumentNotFoundException $exception) {
                    return $this->datasetError('paint.document_not_found', 'not_found', $exception->getMessage(), 404, $caller);
                } catch (StorageClientException $exception) {
                    error_log('[paint] paint.read storage failed: ' . $exception->getMessage());
                    return $this->datasetError($exception->errorCode, $exception->errorClass, $exception->getMessage(), $exception->httpStatus, $caller);
                } catch (Throwable $throwable) {
                    error_log('[paint] paint.read failed: ' . $throwable->getMessage());
                    return $this->datasetError('paint.document_store_unavailable', 'dependency', 'Paint document store is unavailable.', 503, $caller);
                }
            }

            if ($operation === 'paint.draw') {
                try {
                    return Response::json($this->paintService()->draw(
                        is_array($request->parsedBody()['content'] ?? null) ? $request->parsedBody()['content'] : [],
                        $caller
                    ));
                } catch (InvalidArgumentException $exception) {
                    return $this->datasetError('paint.invalid_draw_call', 'invalid_call', $exception->getMessage(), 422, $caller);
                } catch (DocumentNotFoundException $exception) {
                    return $this->datasetError('paint.document_not_found', 'not_found', $exception->getMessage(), 404, $caller);
                } catch (StorageClientException $exception) {
                    error_log('[paint] paint.draw storage failed: ' . $exception->getMessage());
                    return $this->datasetError($exception->errorCode, $exception->errorClass, $exception->getMessage(), $exception->httpStatus, $caller);
                } catch (Throwable $throwable) {
                    error_log('[paint] paint.draw failed: ' . $throwable->getMessage());
                    return $this->datasetError('paint.document_store_unavailable', 'dependency', 'Paint document store is unavailable.', 503, $caller);
                }
            }

            if ($operation === 'paint.rename') {
                try {
                    return Response::json($this->paintService()->rename(
                        is_array($request->parsedBody()['content'] ?? null) ? $request->parsedBody()['content'] : [],
                        $caller
                    ));
                } catch (InvalidArgumentException $exception) {
                    return $this->datasetError('paint.invalid_rename_call', 'invalid_call', $exception->getMessage(), 422, $caller);
                } catch (DocumentNotFoundException $exception) {
                    return $this->datasetError('paint.document_not_found', 'not_found', $exception->getMessage(), 404, $caller);
                } catch (StorageClientException $exception) {
                    error_log('[paint] paint.rename storage failed: ' . $exception->getMessage());
                    return $this->datasetError($exception->errorCode, $exception->errorClass, $exception->getMessage(), $exception->httpStatus, $caller);
                } catch (Throwable $throwable) {
                    error_log('[paint] paint.rename failed: ' . $throwable->getMessage());
                    return $this->datasetError('paint.document_store_unavailable', 'dependency', 'Paint document store is unavailable.', 503, $caller);
                }
            }

            if ($operation === 'paint.search') {
                try {
                    return Response::json($this->paintService()->search(
                        is_array($request->parsedBody()['content'] ?? null) ? $request->parsedBody()['content'] : [],
                        $caller
                    ));
                } catch (InvalidArgumentException $exception) {
                    return $this->datasetError('paint.invalid_search_call', 'invalid_call', $exception->getMessage(), 422, $caller);
                } catch (Throwable $throwable) {
                    error_log('[paint] paint.search failed: ' . $throwable->getMessage());
                    return $this->datasetError('paint.document_store_unavailable', 'dependency', 'Paint document store is unavailable.', 503, $caller);
                }
            }

            if ($operation === 'paint.list') {
                try {
                    return Response::json($this->paintService()->list(
                        is_array($request->parsedBody()['content'] ?? null) ? $request->parsedBody()['content'] : [],
                        $caller
                    ));
                } catch (Throwable $throwable) {
                    error_log('[paint] paint.list failed: ' . $throwable->getMessage());
                    return $this->datasetError('paint.document_store_unavailable', 'dependency', 'Paint document store is unavailable.', 503, $caller);
                }
            }

            return $this->datasetError('paint.unsupported_operation', 'invalid_call', 'Paint operation is not supported yet.', 422, $caller);
        });
    }

    private function authenticatedService(Request $request): ?AuthenticatedService
    {
        /** @var array<string, string> $tokens */
        $tokens = $this->config['service_auth'] ?? [];
        $verifier = new SignedRequestVerifier((string) ($tokens['conductor_keys_url'] ?? ''));

        return (new ServiceAuthenticator($tokens, $verifier))->authenticate($request);
    }

    private function documentStore(): DocumentStore
    {
        return new DocumentStore(Database::pdo($this->config));
    }

    private function paintService(): PaintService
    {
        return new PaintService($this->documentStore(), $this->storageClient());
    }

    private function storageClient(): StorageClient
    {
        $storage = (array) ($this->config['storage_service'] ?? []);

        return new StorageClient(
            (string) ($storage['resource_url'] ?? ''),
            new ServiceIdentity(
                (string) ($storage['service_name'] ?? 'paint.elonn'),
                (string) ($storage['token'] ?? '')
            ),
            (int) ($storage['timeout_seconds'] ?? 8)
        );
    }

    /** @param array<string, mixed> $storage */
    private function storageReady(array $storage): string
    {
        $baseUrl = trim((string) ($storage['base_url'] ?? ''));
        if ($baseUrl === '') {
            return 'error';
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => (int) ($storage['timeout_seconds'] ?? 8),
                'ignore_errors' => true,
                'header' => 'Accept: application/json',
            ],
        ]);

        $body = @file_get_contents(rtrim($baseUrl, '/') . '/ready', false, $context);
        if ($body === false) {
            return 'unavailable';
        }

        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        $decoded = json_decode($body, true);
        if ($status === 200 && is_array($decoded) && ($decoded['status'] ?? '') === 'ready') {
            return 'ready';
        }

        return 'not_ready';
    }

    private function datasetError(string $code, string $class, string $message, int $status, ?AuthenticatedService $caller = null): Response
    {
        $context = [
            'service' => 'paint',
        ];
        if ($caller !== null) {
            $context['caller'] = $caller->name;
            $context['owner'] = $caller->owner();
        }

        return Response::json([
            'id' => 'dataset:service:paint:' . bin2hex(random_bytes(16)),
            'type' => 'service',
            'scope' => 'object',
            'mode' => 'snapshot',
            'created' => gmdate('c'),
            'objects' => [],
            'actions' => [],
            'relationships' => [],
            'collections' => [],
            'resources' => [],
            'placements' => [],
            'errors' => [[
                'code' => $code,
                'class' => $class,
                'message' => $message,
            ]],
            'context' => $context,
        ], $status);
    }
}
