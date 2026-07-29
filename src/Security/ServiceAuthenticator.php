<?php

declare(strict_types=1);

namespace App\Security;

use App\Http\Request;

/**
 * Verifies first-party service callers at Paint's internal boundary.
 */
final class ServiceAuthenticator
{
    /** @param array<string, string> $tokensByService */
    public function __construct(private readonly array $tokensByService)
    {
    }

    public function authenticate(Request $request): ?string
    {
        $service = trim($request->header('x-elonn-service'));
        $token = $this->bearerToken($request->header('authorization')) ?? trim($request->header('x-elonn-service-token'));
        if ($service === '' || $token === null) {
            return null;
        }

        $expected = $this->tokensByService[$service] ?? '';
        if ($expected === '' || !hash_equals($expected, $token)) {
            return null;
        }

        return $service;
    }

    private function bearerToken(string $authorization): ?string
    {
        $authorization = trim($authorization);
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));
        return $token === '' ? null : $token;
    }
}
