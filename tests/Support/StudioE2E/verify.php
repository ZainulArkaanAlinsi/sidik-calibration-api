<?php

declare(strict_types=1);

use Tests\Support\StudioE2E\Harness;

require __DIR__.'/bootstrap.php';

try {
    $options = getopt('', ['run-root:']);
    Harness::require(is_array($options), 'invalid_arguments');
    $root = Harness::runRoot((string) ($options['run-root'] ?? ''));
    $manifest = Harness::manifest($root);
    Harness::require(hash_equals((string) $manifest['environment_sha256'], (string) hash_file('sha256', $root.'/.env')), 'environment_changed');
    Harness::application($root, Harness::environment($root));
    $after = Harness::snapshot();
    $before = $manifest['baseline'];
    Harness::require($after['counts'] === $before['counts'], 'domain_counts_changed');
    Harness::require(hash_equals($before['fixture_sha256'], $after['fixture_sha256']), 'fixture_changed');
    Harness::require($after['token_count'] === 0, 'tokens_remaining');
    Harness::output([
        'status' => 'verified', 'run_id' => $manifest['run_id'],
        'counts' => $after['counts'], 'fixture_sha256' => $after['fixture_sha256'],
        'token_count' => $after['token_count'],
        'audit_log_count_before' => $before['audit_log_count'],
        'audit_log_count_after' => $after['audit_log_count'],
    ]);
} catch (Throwable $error) {
    Harness::failure($error);
    exit(1);
}
