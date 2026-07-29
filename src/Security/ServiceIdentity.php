<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Outbound service credential for one first-party service boundary.
 */
final class ServiceIdentity
{
    public function __construct(
        public readonly string $serviceName,
        public readonly string $token,
    ) {
    }

    /** @return array<int, string> */
    public function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->token,
            'X-Elonn-Service: ' . $this->serviceName,
            'X-Elonn-Service-Token: ' . $this->token,
        ];
    }
}
