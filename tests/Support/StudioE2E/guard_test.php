<?php

declare(strict_types=1);

use Tests\Support\StudioE2E\Harness;

require __DIR__.'/bootstrap.php';

// Standalone, opt-in guard checks: no Laravel bootstrap, SQL, server, or migration.
try {
    Harness::require(PHP_SAPI === 'cli', 'cli_required');
    $runId = 'guard-'.bin2hex(random_bytes(8));
    $root = Harness::RUN_PARENT.'/'.$runId;
    Harness::noReparse([Harness::RUN_PARENT]);
    Harness::require(is_dir(Harness::RUN_PARENT) || mkdir(Harness::RUN_PARENT, 0700, true), 'parent_create_failed');
    Harness::require(mkdir($root, 0700), 'guard_directory_failed');
    $passed = [];
    $reject = static function (string $name, callable $action) use (&$passed): void {
        try {
            $action();
        } catch (RuntimeException $error) {
            Harness::require(str_starts_with($error->getMessage(), 'E2E_'), 'unexpected_guard_exception');
            $passed[] = $name;

            return;
        }
        throw new RuntimeException('E2E_expected_rejection');
    };
    $reject('relative run root', fn () => Harness::runRoot('relative/path'));
    $reject('outside run parent', fn () => Harness::runRoot('C:/Users/USER/not-synthetic/owned-run'));
    $reject('dot segment', fn () => Harness::runRoot(Harness::RUN_PARENT.'/../other-run'));
    Harness::require(Harness::runRoot($root) === $root, 'valid_run_rejected');
    $passed[] = 'new real run accepted';
    $settings = Harness::settings($root, 48123, 'studio-e2e-0123456789abcdef@sidik.test', 'base64:'.base64_encode(str_repeat('x', 32)));
    Harness::validateSettings($settings, $root, 48123);
    $passed[] = 'isolated settings accepted';
    foreach ([
        'APP_ENV' => 'production',
        'DB_CONNECTION' => 'mysql',
        'DB_URL' => 'sqlite:///outside.sqlite',
        'DB_DATABASE' => 'C:/Users/USER/studio-lokal.sqlite',
        'LARAVEL_STORAGE_PATH' => 'C:/Users/USER/existing-storage',
        'APP_CONFIG_CACHE' => 'C:/Users/USER/existing-config.php',
        'APP_SERVICES_CACHE' => 'C:/Users/USER/existing-services.php',
        'APP_PACKAGES_CACHE' => 'C:/Users/USER/existing-packages.php',
        'APP_ROUTES_CACHE' => 'C:/Users/USER/existing-routes.php',
        'APP_EVENTS_CACHE' => 'C:/Users/USER/existing-events.php',
        'ARSIP_DRIVER' => 's3',
    ] as $key => $unsafe) {
        $changed = $settings;
        $changed[$key] = $unsafe;
        $reject('reject '.$key, fn () => Harness::validateSettings($changed, $root, 48123));
    }
    $reject('wrong Host', fn () => Harness::assertHost('localhost:48123', 48123));
    $reject('wrong port', fn () => Harness::assertHost('127.0.0.1:8000', 48123));
    $reject('legacy port', fn () => Harness::port(8000));
    Harness::assertHost('127.0.0.1:48123', 48123);
    $passed[] = 'loopback Host accepted';
    $reject('write outside run parent', fn () => Harness::writeNew('C:/Users/USER/not-synthetic.txt', ''));
    $reject('write outside run root', fn () => Harness::writeNew(Harness::RUN_PARENT.'/not-a-run-file.txt', ''));
    Harness::assertFresh($root);
    Harness::writeNew($root.'/manifest.json', '{"synthetic_guard_only":true}');
    $reject('stale seed target', fn () => Harness::assertFresh($root));
    $reject('exclusive create', fn () => Harness::writeNew($root.'/manifest.json', '{}'));

    // Create an owned junction solely to prove the native ancestor guard rejects it.
    $target = $root.'/target';
    $junction = $root.'/junction';
    Harness::require(mkdir($target, 0700), 'junction_target_failed');
    putenv('SIDIK_E2E_JUNCTION='.$junction);
    putenv('SIDIK_E2E_JUNCTION_TARGET='.$target);
    $script = "\$ErrorActionPreference='Stop'; New-Item -ItemType Junction -Path \$env:SIDIK_E2E_JUNCTION -Target \$env:SIDIK_E2E_JUNCTION_TARGET | Out-Null";
    $encoded = base64_encode(implode("\0", str_split($script))."\0");
    try {
        Harness::command([rtrim((string) getenv('SystemRoot'), '\\/').'/System32/WindowsPowerShell/v1.0/powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded]);
    } finally {
        putenv('SIDIK_E2E_JUNCTION');
        putenv('SIDIK_E2E_JUNCTION_TARGET');
    }
    $reject('junction ancestor', fn () => Harness::noReparse([$junction.'/not-created.sqlite']));
    Harness::output([
        'status' => 'guard_tests_passed', 'count' => count($passed), 'checks' => $passed,
        'artifacts_path' => $root, 'database_connections' => 0,
    ]);
} catch (Throwable $error) {
    Harness::failure($error);
    exit(1);
}
