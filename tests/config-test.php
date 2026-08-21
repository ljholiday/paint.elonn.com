<?php

declare(strict_types=1);

// Regression coverage for a real production incident: CONDUCTOR_SERVICE_KEYS_URL's default must
// resolve to the correct environment automatically -- derived via paint_peer_url(), not a
// hand-typed literal that can be left stale.

$root = dirname(__DIR__);
$localKeysUrl = config_value($root, '$_SERVER["APP_ENV"]="local";', 'service_auth.conductor_keys_url');
$productionKeysUrl = config_value($root, '$_SERVER["APP_ENV"]="production";', 'service_auth.conductor_keys_url');

$checks = [
    'Local Paint defaults to local Conductor keys URL' =>
        $localKeysUrl === 'https://conductor.elonn.local/.well-known/elonn-service-keys.json',
    'Production Paint defaults to production Conductor keys URL' =>
        $productionKeysUrl === 'https://conductor.elonn.com/.well-known/elonn-service-keys.json',
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}

exit($failed === 0 ? 0 : 1);

function config_value(string $root, string $setup, string $path): string
{
    $code = $setup . '$config=require ' . var_export($root . '/config/config.php', true) . ';'
        . '$value=$config; foreach (explode(".", ' . var_export($path, true) . ') as $key) { $value=$value[$key]; } echo $value;';
    $command = 'php -r ' . escapeshellarg($code);
    exec($command, $output, $status);
    if ($status !== 0) {
        return '';
    }

    return trim(implode("\n", $output));
}
