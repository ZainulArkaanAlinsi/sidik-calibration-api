<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Support\JsonKanonik;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Lahirkan versi 1 data acuan dari berkas `database/data/*.json` yang dipakai
 * hari ini — rollout Studio Data Acuan tahap 2 (`07-Migrasi-Rollout.md` §2–3).
 *
 *   php artisan acuan:impor-awal micrometer
 *   php artisan acuan:impor-awal micrometer --organisasi=1
 *   php artisan acuan:impor-awal              # semua profil yang punya skemaAcuan()
 *
 * Per organisasi: paket dibuat bila belum ada, lalu versi 1 `data` berstatus
 * `aktif` berisi JSON berkas yang dikanonikkan (`JsonKanonik::dariTeks`) —
 * strukturnya apa adanya, angkanya literal yang sama. Karena itu sha256
 * kanonik yang dicetak bisa dicocokkan siapa pun dengan berkas di repo.
 *
 * ## Idempoten, dan TIDAK PERNAH menimpa
 *
 * Paket yang sudah punya versi `data` tidak disentuh. Versi 1-nya diadu:
 * sha256 sama → "sudah ada, sama"; beda → error yang menyebut kedua sha256
 * dan exit 1. Isi yang beda bukan hal yang boleh dibereskan perintah impor —
 * itu berarti berkas di repo bergeser dari versi yang mungkin sudah menstempel
 * hasil hitung, dan yang memutuskan manusia.
 *
 * ## `berlaku_mulai` versi 1
 *
 * 07 §3: tanggal kalibrasi sesi TERTUA organisasi yang alatnya dihitung profil
 * ini, atau 2024-01-01 kalau belum ada. Profil sesi dicari lewat
 * `CalibrationProfileRegistry::untukAlat()` — aturan yang sama dengan jalur
 * hitung, bukan menebak nama alat. Bukan berlaku mundur dalam arti FR-18:
 * isinya identik dengan yang dipakai sesi-sesi itu.
 *
 * Versi 1 TIDAK diisi `disahkan_oleh`/`disahkan_pada`: dia impor, bukan
 * pengesahan, dan perintah ini tidak mengarang persetujuan siapa pun.
 */
class ImporAwalAcuan extends Command
{
    protected $signature = 'acuan:impor-awal
        {profil? : Kode profil, mis. micrometer; kosong = semua profil yang punya skemaAcuan()}
        {--organisasi= : Batasi ke satu organization_id; kosong = semua organisasi}';

    protected $description = 'Buat versi 1 data acuan (aktif) dari database/data/*.json per organisasi — idempoten, tidak pernah menimpa';

    /** 07 §3 — dipakai bila organisasi belum punya sesi untuk profil itu. */
    public const BERLAKU_MULAI_BAWAAN = '2024-01-01';

    public function handle(CalibrationProfileRegistry $registry): int
    {
        $daftarProfil = $this->profilDituju($registry);
        $daftarOrganisasi = $this->organisasiDituju();

        if ($daftarProfil === null || $daftarOrganisasi === null) {
            return self::FAILURE;
        }

        if ($daftarProfil === []) {
            $this->warn('Belum ada profil yang punya skemaAcuan(). Tidak ada yang diimpor.');

            return self::SUCCESS;
        }

        if ($daftarOrganisasi->isEmpty()) {
            $this->warn('Belum ada organisasi. Tidak ada yang diimpor.');

            return self::SUCCESS;
        }

        $gagal = false;

        foreach ($daftarProfil as $profil) {
            /** @var array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>} $skema */
            $skema = $profil->skemaAcuan();
            $relatif = 'database/data/'.$skema['sumber_json'];
            $berkas = database_path('data/'.$skema['sumber_json']);

            $this->line('');
            $this->line("kode_profil    : {$profil->kodePaketAcuan()}");
            $this->line("berkas         : {$relatif}");

            if (! is_file($berkas)) {
                $this->error("Berkas {$relatif} tidak ada. Profil {$profil->kodePaketAcuan()} dilewati.");
                $gagal = true;

                continue;
            }

            try {
                $kanonik = JsonKanonik::dariTeks((string) file_get_contents($berkas));
            } catch (JsonException $e) {
                $this->error("Berkas {$relatif} bukan JSON sah ({$e->getMessage()}). Profil {$profil->kodePaketAcuan()} dilewati.");
                $gagal = true;

                continue;
            }

            $sha = JsonKanonik::sha256($kanonik);
            $this->line("sha256 kanonik : {$sha}");

            foreach ($daftarOrganisasi as $organisasi) {
                $berhasil = $this->imporSatu($registry, $profil, $skema, $organisasi, $relatif, $kanonik, $sha);
                $gagal = $gagal || ! $berhasil;
            }
        }

        return $gagal ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Satu paket, satu transaksi. `true` = dibuat atau sudah ada dan sama.
     *
     * @param  array{skema_versi: int, sumber_json: string, lembar: list<array<string, mixed>>}  $skema
     */
    private function imporSatu(
        CalibrationProfileRegistry $registry,
        CalibrationProfile $profil,
        array $skema,
        Organization $organisasi,
        string $relatif,
        string $kanonik,
        string $sha,
    ): bool {
        $label = "[{$profil->kodePaketAcuan()}] organisasi #{$organisasi->id} {$organisasi->nama}";

        return DB::transaction(function () use ($registry, $profil, $skema, $organisasi, $relatif, $kanonik, $sha, $label): bool {
            $paket = PaketAcuan::withTrashed()
                ->where('organization_id', $organisasi->id)
                ->where('kode_profil', $profil->kodePaketAcuan())
                ->lockForUpdate()
                ->first();

            // Unique (organization_id, kode_profil) tetap memegang baris yang
            // soft delete, jadi paket baru tidak bisa dibuat di sampingnya —
            // dan memulihkannya diam-diam bukan keputusan perintah impor.
            if ($paket !== null && $paket->trashed()) {
                $this->error("{$label}: paket sudah dihapus (soft delete). Pulihkan atau putuskan dulu; tidak diimpor.");

                return false;
            }

            if ($paket !== null && $paket->versi()->where('jenis', PaketAcuanVersi::JENIS_DATA)->exists()) {
                return $this->aduVersiSatu($paket, $label, $sha);
            }

            $paket ??= PaketAcuan::create([
                'organization_id' => $organisasi->id,
                'kode_profil' => $profil->kodePaketAcuan(),
                'nama' => $profil->namaPaketAcuan(),
                'skema_versi' => $skema['skema_versi'],
            ]);

            $berlakuMulai = $this->berlakuMulai($registry, $profil, $organisasi);

            PaketAcuanVersi::create([
                'paket_acuan_id' => $paket->id,
                'organization_id' => $organisasi->id,
                'jenis' => PaketAcuanVersi::JENIS_DATA,
                'nomor_versi' => 1,
                'status' => PaketAcuanVersi::STATUS_AKTIF,
                'isi' => $kanonik,
                'isi_sha256' => $sha,
                'skema_versi' => $skema['skema_versi'],
                'berlaku_mulai' => $berlakuMulai,
                'alasan' => "Impor awal dari {$relatif}",
                'rujukan' => [[
                    'jenis' => 'berkas_repo',
                    'nilai' => $relatif,
                    'catatan' => $sha,
                ]],
            ]);

            $this->info("{$label}: versi 1 dibuat (aktif, berlaku_mulai {$berlakuMulai}), sha256 {$sha}.");

            return true;
        });
    }

    /** Paket sudah punya versi data: jangan menimpa, cukup adu versi 1-nya. */
    private function aduVersiSatu(PaketAcuan $paket, string $label, string $sha): bool
    {
        $v1 = $paket->versi()
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->where('nomor_versi', 1)
            ->first();

        if ($v1 === null) {
            $this->error("{$label}: paket sudah punya versi data tapi tanpa nomor 1. Tidak disentuh; periksa manual.");

            return false;
        }

        // Diadu dengan hash BYTE isinya, bukan cuma kolom isi_sha256: hash
        // tersimpan yang tidak cocok dengan isinya sendiri juga temuan.
        $shaIsi = JsonKanonik::sha256((string) $v1->isi);

        if ($v1->isi_sha256 !== null && ! hash_equals($v1->isi_sha256, $shaIsi)) {
            $this->error(
                "{$label}: isi versi 1 (sha256 {$shaIsi}) tidak cocok dengan isi_sha256-nya "
                ."({$v1->isi_sha256}). Tidak disentuh; periksa manual.",
            );

            return false;
        }

        if (hash_equals($shaIsi, $sha)) {
            $this->info("{$label}: versi 1 sudah ada, sama (sha256 {$sha}).");

            return true;
        }

        $this->error(
            "{$label}: versi 1 sudah ada dan BEDA — database sha256 {$shaIsi}, berkas sha256 {$sha}. "
            .'Tidak ditimpa.',
        );

        return false;
    }

    /**
     * Tanggal kalibrasi sesi tertua organisasi yang alatnya dihitung profil
     * ini, atau [BERLAKU_MULAI_BAWAAN].
     *
     * Alat yang sudah soft delete ikut dihitung: sesinya tetap ada dan tetap
     * bisa dihitung ulang, jadi tetap butuh versi yang berlaku di tanggalnya.
     */
    private function berlakuMulai(
        CalibrationProfileRegistry $registry,
        CalibrationProfile $profil,
        Organization $organisasi,
    ): string {
        $alatId = Equipment::withTrashed()
            ->whereIn(
                'id',
                CalibrationSession::query()
                    ->where('organization_id', $organisasi->id)
                    ->select('equipment_id'),
            )
            ->get()
            ->filter(fn (Equipment $alat): bool => $registry->untukAlat($alat)->kodePaketAcuan() === $profil->kodePaketAcuan())
            ->modelKeys();

        if ($alatId === []) {
            return self::BERLAKU_MULAI_BAWAAN;
        }

        $tertua = CalibrationSession::query()
            ->where('organization_id', $organisasi->id)
            ->whereIn('equipment_id', $alatId)
            ->whereNotNull('tanggal_kalibrasi')
            ->min('tanggal_kalibrasi');

        return $tertua === null
            ? self::BERLAKU_MULAI_BAWAAN
            : Carbon::parse($tertua)->toDateString();
    }

    /**
     * Profil yang punya skemaAcuan(), atau satu profil dari argumen.
     * `null` = argumen salah (error sudah dicetak).
     *
     * @return list<CalibrationProfile>|null
     */
    private function profilDituju(CalibrationProfileRegistry $registry): ?array
    {
        $kode = $this->argument('profil');

        if ($kode === null || $kode === '') {
            // Satu wakil per paket: profil sepaket berbagi satu tabel acuan.
            return array_values($registry->paketAcuan());
        }

        // Kode profil ATAU kode paket.
        $profil = $registry->untukKode((string) $kode) ?? $registry->untukPaketAcuan((string) $kode);

        if ($profil === null) {
            $this->error("Profil '{$kode}' tidak dikenal.");

            return null;
        }

        if ($profil->skemaAcuan() === null) {
            $this->error("Profil '{$kode}' belum punya skemaAcuan() — data acuannya belum dipindah ke Studio.");

            return null;
        }

        return [$profil];
    }

    /**
     * Semua organisasi, atau satu dari `--organisasi`. `null` = opsi salah.
     *
     * @return Collection<int, Organization>|null
     */
    private function organisasiDituju(): ?Collection
    {
        $opsi = $this->option('organisasi');

        if ($opsi === null || $opsi === '') {
            return Organization::query()->orderBy('id')->get();
        }

        if (! ctype_digit((string) $opsi)) {
            $this->error("--organisasi harus organization_id (angka), diberi '{$opsi}'.");

            return null;
        }

        $organisasi = Organization::query()->whereKey((int) $opsi)->get();

        if ($organisasi->isEmpty()) {
            $this->error("Organisasi #{$opsi} tidak ada.");

            return null;
        }

        return $organisasi;
    }
}
