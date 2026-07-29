<?php

declare(strict_types=1);

namespace App\Storage;

use App\Security\ServiceIdentity;

/**
 * Talks to the Storage Resource API on Paint's behalf.
 */
final class StorageClient
{
    public function __construct(
        private readonly string $resourceUrl,
        private readonly ServiceIdentity $identity,
        private readonly int $timeoutSeconds,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(string $mediaType, string $bytes, ?string $memberId): array
    {
        $headers = array_merge($this->identity->headers(), [
            'Accept: application/json',
            'Content-Type: ' . $mediaType,
        ]);

        $memberId = trim((string) $memberId);
        if ($memberId !== '') {
            $headers[] = 'X-Elonn-Member-Id: ' . $memberId;
        }

        $response = $this->request('POST', $this->resourceUrl, $headers, $bytes);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            if ($response['status'] === 401) {
                throw new StorageClientException(
                    'paint.storage_service_auth_failed',
                    'dependency',
                    'Storage rejected Paint service authentication.',
                    503
                );
            }

            throw new StorageClientException(
                'paint.storage_resource_create_failed',
                'dependency',
                $this->messageFromBody($response['body'], 'Storage could not create the Resource.'),
                503
            );
        }

        $decoded = json_decode($response['body'], true);
        $resource = is_array($decoded) && is_array($decoded['resource'] ?? null) ? $decoded['resource'] : null;
        if ($resource === null || !$this->validResourceId((string) ($resource['id'] ?? ''))) {
            throw new StorageClientException(
                'paint.storage_invalid_response',
                'dependency',
                'Storage did not return a valid Resource.',
                503
            );
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    public function replace(string $resourceId, string $mediaType, string $bytes, ?string $memberId): array
    {
        if (!$this->validResourceId($resourceId)) {
            throw new StorageClientException(
                'paint.storage_resource_invalid',
                'dependency',
                'Paint document references an invalid Resource.',
                503
            );
        }

        $headers = array_merge($this->identity->headers(), [
            'Accept: application/json',
            'Content-Type: ' . $mediaType,
        ]);

        $memberId = trim((string) $memberId);
        if ($memberId !== '') {
            $headers[] = 'X-Elonn-Member-Id: ' . $memberId;
        }

        $response = $this->request('PUT', $this->resourceUrl . '/' . rawurlencode($resourceId), $headers, $bytes);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            if ($response['status'] === 401) {
                throw new StorageClientException(
                    'paint.storage_service_auth_failed',
                    'dependency',
                    'Storage rejected Paint service authentication.',
                    503
                );
            }

            throw new StorageClientException(
                'paint.storage_resource_replace_failed',
                'dependency',
                $this->messageFromBody($response['body'], 'Storage could not replace the Resource.'),
                503
            );
        }

        $decoded = json_decode($response['body'], true);
        $resource = is_array($decoded) && is_array($decoded['resource'] ?? null) ? $decoded['resource'] : null;
        if ($resource === null || !$this->validResourceId((string) ($resource['id'] ?? ''))) {
            throw new StorageClientException(
                'paint.storage_invalid_response',
                'dependency',
                'Storage did not return a valid Resource.',
                503
            );
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    public function metadata(string $resourceId): array
    {
        if (!$this->validResourceId($resourceId)) {
            throw new StorageClientException(
                'paint.storage_resource_invalid',
                'dependency',
                'Paint document references an invalid Resource.',
                503
            );
        }

        $response = $this->request('GET', $this->resourceUrl . '/' . rawurlencode($resourceId) . '/metadata', array_merge($this->identity->headers(), [
            'Accept: application/json',
        ]), '');

        if ($response['status'] < 200 || $response['status'] >= 300) {
            if ($response['status'] === 401) {
                throw new StorageClientException(
                    'paint.storage_service_auth_failed',
                    'dependency',
                    'Storage rejected Paint service authentication.',
                    503
                );
            }

            throw new StorageClientException(
                'paint.storage_resource_metadata_failed',
                'dependency',
                $this->messageFromBody($response['body'], 'Storage could not return Resource metadata.'),
                503
            );
        }

        $decoded = json_decode($response['body'], true);
        $resource = is_array($decoded) && is_array($decoded['resource'] ?? null) ? $decoded['resource'] : null;
        if ($resource === null || !$this->validResourceId((string) ($resource['id'] ?? ''))) {
            throw new StorageClientException(
                'paint.storage_invalid_response',
                'dependency',
                'Storage did not return a valid Resource.',
                503
            );
        }

        return $resource;
    }

    public function content(string $resourceId): string
    {
        if (!$this->validResourceId($resourceId)) {
            throw new StorageClientException(
                'paint.storage_resource_invalid',
                'dependency',
                'Paint document references an invalid Resource.',
                503
            );
        }

        $response = $this->request('GET', $this->resourceUrl . '/' . rawurlencode($resourceId) . '/content', array_merge($this->identity->headers(), [
            'Accept: application/vnd.elonn.paint+json',
        ]), '');

        if ($response['status'] < 200 || $response['status'] >= 300) {
            if ($response['status'] === 401) {
                throw new StorageClientException(
                    'paint.storage_service_auth_failed',
                    'dependency',
                    'Storage rejected Paint service authentication.',
                    503
                );
            }

            throw new StorageClientException(
                'paint.storage_resource_content_failed',
                'dependency',
                $this->messageFromBody($response['body'], 'Storage could not return Resource content.'),
                503
            );
        }

        return $response['body'];
    }

    public function delete(string $resourceId): void
    {
        if (!$this->validResourceId($resourceId)) {
            return;
        }

        try {
            $this->request('DELETE', $this->resourceUrl . '/' . rawurlencode($resourceId), array_merge($this->identity->headers(), [
                'Accept: application/json',
            ]), '');
        } catch (StorageClientException) {
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array{status:int,body:string}
     */
    private function request(string $method, string $url, array $headers, string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new StorageClientException(
                'paint.storage_unavailable',
                'dependency',
                'Storage endpoint is unavailable.',
                503
            );
        }

        return [
            'status' => $this->statusFromHeaders($http_response_header ?? []),
            'body' => $responseBody,
        ];
    }

    /** @param array<int, string> $headers */
    private function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function messageFromBody(string $body, string $fallback): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_string($error) && trim($error) !== '') {
                return trim($error);
            }

            $errors = $decoded['errors'] ?? null;
            if (is_array($errors) && is_array($errors[0] ?? null)) {
                $message = $errors[0]['message'] ?? null;
                if (is_string($message) && trim($message) !== '') {
                    return trim($message);
                }
            }
        }

        return $fallback;
    }

    private function validResourceId(string $id): bool
    {
        return preg_match('/^resource:[a-f0-9]{32}$/', $id) === 1;
    }
}
