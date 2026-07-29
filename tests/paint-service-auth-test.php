<?php

declare(strict_types=1);

use App\Http\Request;
use App\Security\ServiceAuthenticator;
use App\Security\ServiceIdentity;

require dirname(__DIR__) . '/vendor/autoload.php';

$authenticator = new ServiceAuthenticator([
    'mind.elonn' => 'mind-token',
    'admin.elonn' => 'admin-token',
]);

$bearer = $authenticator->authenticate(Request::testing('POST', '/paint/call', [
    'x-elonn-service' => 'mind.elonn',
    'authorization' => 'Bearer mind-token',
    'x-elonn-member-id' => '123',
]));
$fallback = $authenticator->authenticate(Request::testing('POST', '/paint/call', [
    'x-elonn-service' => 'admin.elonn',
    'x-elonn-service-token' => 'admin-token',
]));
$wrong = $authenticator->authenticate(Request::testing('POST', '/paint/call', [
    'x-elonn-service' => 'mind.elonn',
    'authorization' => 'Bearer wrong',
]));
$unknown = $authenticator->authenticate(Request::testing('POST', '/paint/call', [
    'x-elonn-service' => 'unknown.elonn',
    'authorization' => 'Bearer mind-token',
]));
$invalidMember = $authenticator->authenticate(Request::testing('POST', '/paint/call', [
    'x-elonn-service' => 'mind.elonn',
    'authorization' => 'Bearer mind-token',
    'x-elonn-member-id' => 'bad member',
]));
$storageIdentity = new ServiceIdentity('paint.elonn', 'storage-token');

$checks = [
    'Bearer token authenticates first-party caller' => $bearer !== null
        && $bearer->name === 'mind.elonn'
        && $bearer->memberId === '123'
        && $bearer->owner() === 'member:123',
    'X-Elonn-Service-Token authenticates first-party caller' => $fallback !== null
        && $fallback->name === 'admin.elonn'
        && $fallback->memberId === null
        && $fallback->owner() === 'service:admin.elonn',
    'Mismatched service token is rejected' => $wrong === null,
    'Unknown service caller is rejected' => $unknown === null,
    'Malformed member context is rejected' => $invalidMember === null,
    'Outbound service identity emits Elonn service auth headers' => $storageIdentity->headers() === [
        'Authorization: Bearer storage-token',
        'X-Elonn-Service: paint.elonn',
        'X-Elonn-Service-Token: storage-token',
    ],
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

if ($failed > 0) {
    exit(1);
}
