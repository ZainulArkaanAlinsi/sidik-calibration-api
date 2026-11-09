<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Studio\Workbook\ImporWorkbook;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Tests\Support\StudioE2E\Harness;

require __DIR__.'/bootstrap.php';

try {
    $options = getopt('', ['run-root:', 'port:', 'mobile-sha:']);
    Harness::require(is_array($options), 'invalid_arguments');
    $root = Harness::runRoot((string) ($options['run-root'] ?? ''));
    $port = Harness::port($options['port'] ?? null);
    $mobileSha = $options['mobile-sha'] ?? '';
    Harness::require(is_string($mobileSha) && (bool) preg_match('/^[a-f0-9]{40}$/D', $mobileSha), 'invalid_mobile_sha');
    Harness::guardInheritedEnvironment($root);
    Harness::assertFresh($root);
    $base = Harness::basePath();
    $apiSha = Harness::sha($base);
    $runId = basename($root);
    $email = 'studio-e2e-'.substr(hash('sha256', $runId), 0, 16).'@sidik.test';
    $password = bin2hex(random_bytes(24));
    $settings = Harness::settings($root, $port, $email, 'base64:'.base64_encode(random_bytes(32)));
    Harness::validateSettings($settings, $root, $port);

    foreach (['cache', 'screenshots', 'fixture', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
        Harness::noReparse([$root.'/'.$directory]);
        Harness::require(is_dir($root.'/'.$directory) || mkdir($root.'/'.$directory, 0700, true), 'directory_create_failed');
    }
    $environment = '';
    foreach ($settings as $key => $value) {
        $environment .= $key.'="'.$value.'"'."\n";
    }
    Harness::writeNew($root.'/.env', $environment);
    Harness::writeNew($root.'/studio-synthetic.sqlite', '');

    $app = Harness::application($root, $settings);
    $kernel = $app->make(Kernel::class);
    $migrationExit = $kernel->call('migrate', ['--force' => true, '--no-interaction' => true]);
    Harness::require($migrationExit === 0, 'migration_failed');

    $organization = Organization::query()->create([
        'nama' => 'E2E SYNTHETIC '.$runId,
        'alamat' => 'Synthetic fixture only',
        'email' => 'organization@sidik.test',
    ]);
    $user = User::query()->create([
        'organization_id' => $organization->id,
        'employee_id' => 'E2E-'.substr(hash('sha256', $runId), 0, 12),
        'name' => 'Synthetic Studio User',
        'department' => 'Synthetic E2E',
        'email' => $email,
        'email_verified_at' => now(),
        'password' => Hash::make($password),
        'role' => User::ROLE_ADMIN,
        'status' => User::STATUS_AKTIF,
    ]);

    $name = 'E2E_SYNTHETIC_'.$runId;
    // Deliberately unmapped: this is a display/storage fixture, not a calibration master.
    Harness::writeNew($root.'/fixture/INPUT_SYNTHETIC.csv', "E2E INPUT $runId,\r\nSynthetic input,3 [=1+2]\r\n");
    Harness::writeNew($root.'/fixture/DISPLAY_SYNTHETIC.csv', "E2E DISPLAY $runId,\r\nSynthetic display,2.5\r\n");
    $import = $app->make(ImporWorkbook::class)->imporCsv(
        $root.'/fixture', $name, 'synthetic-e2e', $organization, (int) $user->id, false,
    );
    Harness::require(($import['status'] ?? null) === 'baru', 'fixture_import_failed');
    $workbook = WorkbookMaster::query()->where('organization_id', $organization->id)->where('nama', $name)->sole();
    Harness::require($workbook->kode_profil === null && $workbook->keluarga === null, 'fixture_mapped_to_profile');
    $sheets = [];
    foreach ([
        'INPUT_SYNTHETIC' => [
            ['address' => 'A1', 'text' => 'E2E INPUT '.$runId],
            ['address' => 'A2', 'text' => 'Synthetic input'],
            ['address' => 'B2', 'text' => '3', 'formula' => '=1+2'],
        ],
        'DISPLAY_SYNTHETIC' => [
            ['address' => 'A1', 'text' => 'E2E DISPLAY '.$runId],
            ['address' => 'A2', 'text' => 'Synthetic display'],
            ['address' => 'B2', 'text' => '2.5'],
        ],
    ] as $sheetName => $cells) {
        $sheet = $workbook->lembar()->where('nama', $sheetName)->sole();
        $stored = array_column($sheet->daftarSel(), null, 'a');
        foreach ($cells as $expected) {
            $actual = $stored[$expected['address']] ?? null;
            Harness::require(is_array($actual) && $actual['s'] === $expected['text']
                && $actual['r'] === ($expected['formula'] ?? null), 'stored_fixture_mismatch');
        }
        $sheets[] = ['id' => (int) $sheet->id, 'name' => $sheetName, 'expected_cells' => $cells];
    }
    $baseline = Harness::snapshot();
    Harness::require($baseline['counts']['organizations'] === 1 && $baseline['counts']['users'] === 1, 'unexpected_fixture_accounts');
    Harness::require($baseline['counts']['workbook_master'] === 1 && $baseline['counts']['workbook_master_lembar'] === 2, 'unexpected_fixture_workbooks');
    Harness::require($baseline['token_count'] === 0, 'unexpected_seed_token');
    $manifest = [
        'schema_version' => 1, 'run_id' => $runId, 'api_sha' => $apiSha,
        'mobile_sha' => $mobileSha, 'api_base_url' => $settings['APP_URL'].'/api',
        'database_path' => $settings['DB_DATABASE'], 'organization_id' => (int) $organization->id,
        'user_email' => $email, 'workbook' => ['id' => (int) $workbook->id, 'name' => $name, 'sheets' => $sheets],
        'baseline' => $baseline, 'environment_sha256' => hash('sha256', $environment),
    ];
    $manifestBytes = Harness::json($manifest);
    Harness::writeNew($root.'/manifest.json', $manifestBytes);
    Harness::writeNew($root.'/manifest.sha256', hash('sha256', $manifestBytes)."\n");
    Harness::writeNew($root.'/defines.json', Harness::json([
        'API_BASE_URL' => $manifest['api_base_url'], 'USE_MOCK' => 'false',
        'E2E_ID' => $email, 'E2E_SANDI' => $password, 'E2E_RUN_ID' => $runId,
        'E2E_MANIFEST' => $root.'/manifest.json', 'E2E_POTRET' => $root.'/screenshots',
    ]));
    unset($password, $settings, $environment);
    Harness::output(['status' => 'seeded', 'run_id' => $runId, 'manifest_path' => $root.'/manifest.json']);
} catch (Throwable $error) {
    if (isset($root) && is_string($root) && is_dir($root)) {
        // Private, argument-free diagnostics: never serialize exception bindings/config.
        @file_put_contents($root.'/seed-diagnostic.json', json_encode([
            'exception' => $error::class, 'file' => $error->getFile(), 'line' => $error->getLine(),
            'trace' => array_map(static fn (array $frame): array => array_intersect_key($frame,
                array_flip(['file', 'line', 'class', 'function'])), $error->getTrace()),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
    Harness::failure($error);
    exit(1);
}
