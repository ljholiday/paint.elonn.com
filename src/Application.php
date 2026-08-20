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
