<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Tests\Support\StudioE2E\Harness;

require __DIR__.'/bootstrap.php';

try {
    Harness::require(PHP_SAPI === 'cli-server', 'builtin_server_required');
    $root = Harness::runRoot((string) getenv('SIDIK_E2E_RUN_ROOT'));
    $manifest = Harness::manifest($root);
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    register_shutdown_function(static function () use ($root, $method, $uri): void {
        // Include early rejections, but never query, body, auth headers, or token.
        file_put_contents($root.'/http-access.log', json_encode(
            Harness::accessEntry($method, $uri, (int) http_response_code()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        )."\n", FILE_APPEND | LOCK_EX);
    });
    $port = Harness::port((string) parse_url($manifest['api_base_url'], PHP_URL_PORT));
    Harness::assertHost((string) ($_SERVER['HTTP_HOST'] ?? ''), $port);
    Harness::require((string) ($_SERVER['SERVER_PORT'] ?? '') === (string) $port, 'wrong_bound_port');
    Harness::require(($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1', 'loopback_required');
    $path = parse_url($uri, PHP_URL_PATH);
    Harness::require(is_string($path), 'invalid_request_path');
    if (! Harness::allowedRequest($method, $path)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"message":"Not in the synthetic smoke scope."}';

        return;
    }
    Harness::require(hash_equals((string) $manifest['environment_sha256'], (string) hash_file('sha256', $root.'/.env')), 'environment_changed');
    $settings = Harness::environment($root);
    $app = Harness::application($root, $settings);
    if ($path === '/__sidik_e2e') {
        header('Content-Type: application/json');
        Harness::output([
            'schema_version' => 1, 'run_id' => $manifest['run_id'],
            'api_sha' => $manifest['api_sha'], 'mobile_sha' => $manifest['mobile_sha'],
            'app_env' => $app->environment(), 'db_driver' => config('database.default'),
            'manifest_sha256' => hash_file('sha256', $root.'/manifest.json'),
        ]);

        return;
    }
    $app->handleRequest(Request::capture());
} catch (Throwable $error) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"message":"Synthetic harness guard failed."}';
    // Never pass potentially sensitive framework messages to the built-in server log.
}
