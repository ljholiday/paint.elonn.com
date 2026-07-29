<?php

declare(strict_types=1);

namespace App;

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Paint\Database;
use App\Security\ServiceAuthenticator;
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

        $this->router->get('/ready', function (): Response {
            $storage = (array) ($this->config['storage_service'] ?? []);
            $dependencies = [
                'database' => 'error',
                'storage_service_config' => ((string) ($storage['resource_url'] ?? '')) !== '' ? 'configured' : 'error',
                'storage_service_auth' => ((string) ($storage['token'] ?? '')) !== '' ? 'configured' : 'missing',
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

            $ready = $dependencies['storage_service_config'] === 'configured'
                && $dependencies['storage_service_auth'] === 'configured'
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
            if ($this->authenticatedService($request) === null) {
                return $this->datasetError('paint.service_auth_failed', 'auth', 'Paint service authentication failed.', 401);
            }

            $operation = (string) (($request->parsedBody()['content']['operation'] ?? ''));
            if ($operation === '') {
                return $this->datasetError('paint.operation_required', 'invalid_call', 'Paint Call content.operation is required.', 400);
            }

            return $this->datasetError('paint.unsupported_operation', 'invalid_call', 'Paint operation is not supported yet.', 422);
        });
    }

    private function authenticatedService(Request $request): ?string
    {
        /** @var array<string, string> $tokens */
        $tokens = $this->config['service_auth'] ?? [];

        return (new ServiceAuthenticator($tokens))->authenticate($request);
    }

    private function datasetError(string $code, string $class, string $message, int $status): Response
    {
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
            'context' => [
                'service' => 'paint',
            ],
        ], $status);
    }
}
