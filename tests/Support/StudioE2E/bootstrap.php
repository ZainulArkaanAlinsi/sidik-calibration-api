<?php

declare(strict_types=1);

namespace Tests\Support\StudioE2E;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Test-only bootstrap. Requiring this file does not load Laravel or connect to a DB. */
final class Harness
{
    public const RUN_PARENT = 'C:/Users/USER/sidik-e2e-sintetis';

    public const CACHE_FILES = [
        'APP_CONFIG_CACHE' => 'config.php',
        'APP_SERVICES_CACHE' => 'services.php',
        'APP_PACKAGES_CACHE' => 'packages.php',
        'APP_ROUTES_CACHE' => 'routes.php',
        'APP_EVENTS_CACHE' => 'events.php',
    ];

    /** @var list<string> */
    public const DOMAIN_TABLES = [
        'organizations', 'users', 'workbook_master', 'workbook_master_lembar',
        'calibration_sessions', 'raw_measurements', 'uncertainty_calculations',
        'certificates', 'paket_acuan', 'paket_acuan_versi',
    ];

    public static function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new RuntimeException('E2E_'.$code);
        }
    }

    public static function path(string $path): string
    {
        self::require((bool) preg_match('~^[A-Za-z]:[/\\\\]~', $path), 'absolute_path_required');
        $path = str_replace('\\', '/', $path);
        self::require(! preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path), 'dot_segment');
        self::require(! str_contains($path, "\0") && ! str_contains(substr($path, 2), ':'), 'invalid_path');

        return rtrim($path, '/');
    }

    public static function samePath(string $left, string $right): bool
    {
        return strcasecmp(self::path($left), self::path($right)) === 0;
    }

    public static function runRoot(string $root): string
    {
        $root = self::path($root);
        self::require(self::samePath(dirname($root), self::RUN_PARENT), 'run_root_outside_parent');
        self::require((bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,100}$/D', basename($root)), 'invalid_run_id');
        self::require(is_dir($root), 'run_root_missing');
        self::noReparse([$root]);
        $real = realpath($root);
        self::require(is_string($real) && self::samePath($real, $root), 'run_root_not_real');

        return $root;
    }

    /** NTFS junctions need the native ReparsePoint attribute, not only PHP is_link(). */
    public static function noReparse(array $paths): void
    {
        self::require(PHP_OS_FAMILY === 'Windows', 'windows_only');
        foreach ($paths as $path) {
            self::path($path);
        }
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
foreach ($path in (ConvertFrom-Json $env:SIDIK_E2E_CHECK_PATHS)) {
    $cursor = $path
    while ($cursor) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 9 }
        }
        $parent = [IO.Directory]::GetParent($cursor)
        if ($null -eq $parent) { break }
        $cursor = $parent.FullName
    }
}
exit 0
PS;
        $encoded = base64_encode(implode("\0", str_split($script))."\0");
        $before = getenv('SIDIK_E2E_CHECK_PATHS');
        putenv('SIDIK_E2E_CHECK_PATHS='.json_encode(array_values($paths), JSON_THROW_ON_ERROR));
        try {
            $powershell = rtrim((string) getenv('SystemRoot'), '\\/').'/System32/WindowsPowerShell/v1.0/powershell.exe';
            self::require(is_file($powershell), 'powershell_missing');
            self::command([$powershell, '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded]);
        } finally {
            putenv($before === false ? 'SIDIK_E2E_CHECK_PATHS' : 'SIDIK_E2E_CHECK_PATHS='.$before);
        }
    }

    /** argv execution only; no shell interpolation and no child output on failure. */
    public static function command(array $argv): string
    {
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        self::require(is_resource($process), 'child_start_failed');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::require(proc_close($process) === 0, 'child_guard_failed');

        return trim((string) $output);
    }

    public static function basePath(): string
    {
        $base = self::path(dirname(__DIR__, 3));
        $vendor = $base.'/vendor';
        self::noReparse([$base, $vendor.'/autoload.php', $vendor.'/composer/ClassLoader.php']);
        foreach ([$base, $vendor, $vendor.'/autoload.php', $vendor.'/composer/ClassLoader.php'] as $path) {
            $real = realpath($path);
            self::require(is_string($real) && self::samePath($real, $path), 'base_or_vendor_not_real');
        }

        return $base;
    }

    public static function sha(string $base): string
    {
        $sha = self::command(['git', '-C', $base, 'rev-parse', 'HEAD']);
        self::require((bool) preg_match('/^[a-f0-9]{40}$/D', $sha), 'invalid_api_sha');

        return $sha;
    }

    public static function port(mixed $value): int
    {
        self::require(is_string($value) || is_int($value), 'invalid_port');
        self::require((bool) preg_match('/^\d{4,5}$/D', (string) $value), 'invalid_port');
        $port = (int) $value;
        self::require($port >= 1024 && $port <= 65535 && ! in_array($port, [8000, 8010], true), 'invalid_port');

        return $port;
    }

    public static function settings(string $root, int $port, string $email, string $key): array
    {
        $settings = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => $key,
            'APP_URL' => 'http://127.0.0.1:'.$port, 'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $root.'/studio-synthetic.sqlite', 'DB_URL' => '',
            'DB_FOREIGN_KEYS' => 'true', 'FILESYSTEM_DISK' => 'local',
            'STUDIO_DISK_BERKAS' => 'local', 'ARSIP_DRIVER' => 'local',
            'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'log', 'BROADCAST_CONNECTION' => 'log',
            'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'warning',
            'LARAVEL_STORAGE_PATH' => $root.'/storage',
            'VIEW_COMPILED_PATH' => $root.'/storage/framework/views',
            'STUDIO_AKUN' => $email, 'STUDIO_VERSI_MINIMUM' => '0.0.0',
            'DATA_ACUAN_SUMBER' => 'json',
        ];
        foreach (self::CACHE_FILES as $key => $file) {
            $settings[$key] = $root.'/cache/'.$file;
        }

        return $settings;
    }

    public static function validateSettings(array $settings, string $root, int $port): void
    {
        $email = $settings['STUDIO_AKUN'] ?? '';
        $key = $settings['APP_KEY'] ?? '';
        self::require(is_string($email) && (bool) preg_match('/^studio-e2e-[a-f0-9]{16}@sidik\.test$/D', $email), 'invalid_fixture_email');
        self::require(is_string($key) && str_starts_with($key, 'base64:') && strlen((string) base64_decode(substr($key, 7), true)) === 32, 'invalid_fixture_key');
        self::require($settings === self::settings($root, $port, $email, $key), 'unsafe_environment');
        self::noReparse([$root.'/.env', $root.'/studio-synthetic.sqlite', $root.'/storage', $root.'/cache']);
    }

    public static function guardInheritedEnvironment(string $root): void
    {
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_URL' => '', 'DATABASE_URL' => ''] as $key => $expected) {
            $value = getenv($key);
            self::require($value === false || $value === '' || $value === $expected, 'unsafe_inherited_environment');
        }
        $database = getenv('DB_DATABASE');
        self::require($database === false || $database === '' || self::samePath($database, $root.'/studio-synthetic.sqlite'), 'unsafe_inherited_database');
        foreach (self::CACHE_FILES as $key => $file) {
            $value = getenv($key);
            self::require($value === false || $value === '' || self::samePath($value, $root.'/cache/'.$file), 'unsafe_inherited_cache');
        }
        $storage = getenv('LARAVEL_STORAGE_PATH');
        self::require($storage === false || $storage === '' || self::samePath($storage, $root.'/storage'), 'unsafe_inherited_storage');
    }

    public static function assertFresh(string $root): void
    {
        foreach (['.env', 'studio-synthetic.sqlite', 'manifest.json', 'manifest.sha256', 'defines.json'] as $file) {
            self::require(! file_exists($root.'/'.$file) && ! is_link($root.'/'.$file), 'seed_target_exists');
        }
    }

    public static function writeNew(string $path, string $bytes): void
    {
        $path = self::path($path);
        self::require(str_starts_with(strtolower($path), strtolower(self::RUN_PARENT).'/'), 'write_outside_run_parent');
        $relative = substr($path, strlen(self::RUN_PARENT) + 1);
        self::require((bool) preg_match('~^[A-Za-z0-9][A-Za-z0-9_-]{7,100}/.+$~D', $relative), 'write_outside_run_root');
        self::noReparse([$path]);
        $handle = @fopen($path, 'xb');
        self::require(is_resource($handle), 'exclusive_create_failed');
        try {
            self::require(fwrite($handle, $bytes) === strlen($bytes), 'write_failed');
        } finally {
            fclose($handle);
        }
    }

    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
    }

    public static function application(string $root, array $settings): Application
    {
        $root = self::runRoot($root);
        self::validateSettings($settings, $root, self::port((string) parse_url($settings['APP_URL'], PHP_URL_PORT)));
        self::guardInheritedEnvironment($root);
        $base = self::basePath();
        foreach ($settings as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        require_once $base.'/vendor/autoload.php';
        $loaderPath = (new \ReflectionClass(ClassLoader::class))->getFileName();
        self::require(is_string($loaderPath) && self::samePath($loaderPath, $base.'/vendor/composer/ClassLoader.php'), 'wrong_autoloader');
        $app = require $base.'/bootstrap/app.php';
        $app->useEnvironmentPath($root);
        $app->useStoragePath($root.'/storage');
        self::require(self::samePath($app->basePath(), $base) && self::samePath($app->storagePath(), $root.'/storage'), 'wrong_application_path');
        self::isolateCachePaths($app, $root, $settings);
        // Validate resolved config before any service provider can open a connection.
        $app->afterBootstrapping(LoadConfiguration::class,
            static fn (Application $application) => self::resolvedSettings($application, $root, $settings));
        $app->make(Kernel::class)->bootstrap();
        self::resolvedSettings($app, $root, $settings);

        return $app;
    }

    public static function isolateCachePaths(Application $app, string $root, array $settings): void
    {
        // Laravel only recognizes slash-prefixed absolute paths by default.
        // Register this owned Windows root before resolving any cached path.
        $app->addAbsoluteCachePathPrefix($root.'/');
        foreach (['Config', 'Services', 'Packages', 'Routes', 'Events'] as $type) {
            $method = 'getCached'.$type.'Path';
            self::require(self::samePath($app->$method(), $settings['APP_'.strtoupper($type).'_CACHE']), 'wrong_cache_path');
        }
        foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            self::require(! file_exists($settings[$key]), 'unexpected_executable_cache');
        }
    }

    public static function resolvedSettings(Application $app, string $root, array $settings): void
    {
        $config = $app->make('config');
        self::require($app->environment() === 'testing' && $config->get('database.default') === 'sqlite', 'wrong_resolved_environment');
        self::require(self::samePath((string) $config->get('database.connections.sqlite.database'), $root.'/studio-synthetic.sqlite'), 'wrong_resolved_database');
        self::require(empty($config->get('database.connections.sqlite.url')), 'database_url_not_empty');
        foreach (['local', 'arsip'] as $disk) {
            self::require($config->get('filesystems.disks.'.$disk.'.driver') === 'local'
                && self::samePath((string) $config->get('filesystems.disks.'.$disk.'.root'), $root.'/storage/app/private'), 'wrong_local_disk');
        }
        foreach (['cache.default' => 'file', 'session.driver' => 'array', 'queue.default' => 'sync', 'mail.default' => 'log', 'broadcasting.default' => 'log'] as $key => $value) {
            self::require($config->get($key) === $value, 'wrong_resolved_driver');
        }
        self::require($config->get('studio.akun') === $settings['STUDIO_AKUN'], 'wrong_studio_account');
    }

    /** Load only the owned environment; never the repository's .env. */
    public static function environment(string $root): array
    {
        $settings = @parse_ini_file($root.'/.env', false, INI_SCANNER_RAW);
        self::require(is_array($settings), 'environment_missing');

        return $settings;
    }

    public static function manifest(string $root): array
    {
        self::noReparse([$root.'/manifest.json', $root.'/manifest.sha256', $root.'/.env', $root.'/studio-synthetic.sqlite']);
        $bytes = @file_get_contents($root.'/manifest.json');
        $digest = @file_get_contents($root.'/manifest.sha256');
        self::require(is_string($bytes) && is_string($digest) && hash_equals(trim($digest), hash('sha256', $bytes)), 'manifest_changed');
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        self::require(is_array($manifest) && ($manifest['schema_version'] ?? null) === 1, 'invalid_manifest');
        self::require(($manifest['run_id'] ?? null) === basename($root), 'wrong_manifest_run');
        self::require(self::samePath((string) ($manifest['database_path'] ?? ''), $root.'/studio-synthetic.sqlite'), 'wrong_manifest_database');
        self::require(is_file($root.'/studio-synthetic.sqlite'), 'database_missing');
        self::require(($manifest['api_sha'] ?? null) === self::sha(self::basePath()), 'api_sha_changed');
        $port = self::port((string) parse_url((string) ($manifest['api_base_url'] ?? ''), PHP_URL_PORT));
        self::require($manifest['api_base_url'] === 'http://127.0.0.1:'.$port.'/api', 'wrong_manifest_api_url');

        return $manifest;
    }

    public static function assertHost(string $host, int $port): void
    {
        self::require($host === '127.0.0.1:'.$port, 'wrong_host');
    }

    public static function snapshot(): array
    {
        $counts = [];
        foreach (self::DOMAIN_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $rows = [];
        foreach (['organizations', 'workbook_master', 'workbook_master_lembar'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $rows['users'] = DB::table('users')->orderBy('id')->get(['id', 'organization_id', 'employee_id', 'email', 'role', 'status'])->map(fn ($row) => (array) $row)->all();

        return [
            'counts' => $counts,
            'fixture_sha256' => hash('sha256', self::json($rows)),
            'audit_log_count' => DB::table('audit_logs')->count(),
            'token_count' => DB::table('personal_access_tokens')->count(),
        ];
    }

    public static function output(array $data): void
    {
        echo self::json($data);
    }

    /** Do not print framework exception messages: SQL bindings may contain credentials. */
    public static function failure(Throwable $error): void
    {
        $message = $error instanceof RuntimeException && str_starts_with($error->getMessage(), 'E2E_')
            ? $error->getMessage() : 'E2E_operation_failed';
        fwrite(STDERR, self::json(['status' => 'error', 'code' => $message, 'exception' => $error::class]));
    }
}
