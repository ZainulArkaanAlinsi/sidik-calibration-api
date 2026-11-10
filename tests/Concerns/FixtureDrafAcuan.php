<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Models\WorkbookMaster;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Acuan\SumberAcuan;
use App\Services\Studio\PetaSel\PetaSel;
use App\Support\JsonKanonik;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/**
 * Perkakas bersama test API draf data acuan Studio (rencana A1, B4):
 * `StudioDrafAcuanBuatLanjutTest`, `...PerbaruiTest`, `...MuatTest`, `...DasarTest`.
 * (`StudioDrafAcuanBuatTest` B3 menyimpan salinan helper-nya sendiri.)
 *
 * Fixture sintetis terisolasi per lab: workbook master micrometer (varian B) +
 * paket + versi 1 AKTIF dari `database/data/tabel-standar-micrometer.json`
 * (byte kanonik). Peta sel = `database/data/peta-sel/micrometer.json` ASLI: empat
 * sel angka sah `DATABASE!T5..T8` -> `cmc[kode=A..D].u95_um` (0.83 / 0.87 / 0.91 / 0.91).
 *
 * Pemakai memanggil [siapkanDrafAcuan] dari `setUp()`.
 */
trait FixtureDrafAcuan
{
    protected Organization $lab;

    protected User $studio;

    protected WorkbookMaster $wb;

    protected PaketAcuan $paket;

    protected PaketAcuanVersi $v1;

    protected PetaSel $peta;

    protected function siapkanDrafAcuan(): void
    {
        SumberAcuan::lupakanCache();
        config(['studio.peta_sel.direktori' => database_path('data/peta-sel'), 'studio.draf_acuan_aktif' => true]);

        $this->peta = PetaSel::untuk('micrometer') ?? $this->fail('peta micrometer tidak ada');
        ['lab' => $this->lab, 'user' => $this->studio, 'wb' => $this->wb, 'paket' => $this->paket, 'v1' => $this->v1] = $this->siapkanLab('a');
        $this->sebagaiStudio($this->studio);
    }

    protected function teksDasar(): string
    {
        return JsonKanonik::dariTeks((string) file_get_contents(database_path('data/tabel-standar-micrometer.json')));
    }

    /** @return array{lab: Organization, user: User, wb: WorkbookMaster, paket: ?PaketAcuan, v1: ?PaketAcuanVersi} */
    protected function siapkanLab(string $kode, ?string $teks = null, string $nama = 'Master_Olah_Data_Micrometer_25-50mm', bool $denganPaket = true, string $kodeProfil = 'micrometer'): array
    {
        $lab = Organization::factory()->create();
        $user = User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'status' => User::STATUS_AKTIF,
            'email' => "studio.{$kode}@sidik.test",
            'organization_id' => $lab->id,
        ]);
        $wb = WorkbookMaster::create([
            'organization_id' => $lab->id,
            'kode_profil' => $kodeProfil,
            'nama' => $nama,
            'sumber' => WorkbookMaster::SUMBER_CSV,
            'sha256' => hash('sha256', "wb|{$kode}|{$nama}"),
            'ukuran' => 100,
            'jumlah_lembar' => 1,
            'aktif' => true,
        ]);
        $paket = null;
        $v1 = null;

        if ($denganPaket) {
            $teks ??= $this->teksDasar();
            $paket = PaketAcuan::create(['organization_id' => $lab->id, 'kode_profil' => 'micrometer', 'nama' => 'Micrometer', 'skema_versi' => 1]);
            $v1 = $this->buatVersi($paket, 1, PaketAcuanVersi::STATUS_AKTIF, $teks, ['berlaku_mulai' => '2020-01-01']);
        }

        return ['lab' => $lab, 'user' => $user, 'wb' => $wb, 'paket' => $paket, 'v1' => $v1];
    }

    /**
     * Versi `data` langsung lewat model (isi_sha256 diisi bila status bukan draf,
     * seperti `AlurVersiAcuan::ajukan`).
     *
     * @param  array<string, mixed>  $tambah
     */
    protected function buatVersi(PaketAcuan $paket, int $nomor, string $status, string $teks, array $tambah = []): PaketAcuanVersi
    {
        return PaketAcuanVersi::create(array_replace([
            'paket_acuan_id' => $paket->id,
            'organization_id' => $paket->organization_id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => $nomor,
            'status' => $status,
            'isi' => $teks,
            'isi_sha256' => $status === PaketAcuanVersi::STATUS_DRAF ? null : JsonKanonik::sha256($teks),
            'skema_versi' => 1,
        ], $tambah));
    }

    /** @param  array<string, mixed>  $ganti */
    protected function badan(array $ganti = [], ?WorkbookMaster $wb = null, ?PaketAcuanVersi $dasar = null): array
    {
        $wb ??= $this->wb;
        $dasar ??= $this->v1;

        return array_replace([
            'workbook_sha256' => $wb->sha256,
            'peta_sel_sha256' => $this->peta->sha256,
            'base_isi_sha256' => PenentuVersiAcuan::sha256($dasar),
            'alasan' => 'Koreksi CMC pita sesuai lampiran akreditasi',
            'suntingan' => [['sel' => 'DATABASE!T5', 'nilai' => '0.84']],
        ], $ganti);
    }

    /** @param  array<string, mixed>  $ganti */
    protected function badanPut(string $expected, array $ganti = [], ?WorkbookMaster $wb = null): array
    {
        $wb ??= $this->wb;

        return array_replace([
            'workbook_sha256' => $wb->sha256,
            'peta_sel_sha256' => $this->peta->sha256,
            'expected_isi_sha256' => $expected,
            'alasan' => 'Koreksi lanjutan CMC pita B sesuai lampiran',
            'suntingan' => [['sel' => 'DATABASE!T6', 'nilai' => '0.88']],
        ], $ganti);
    }

    /** @param  array<string, mixed>  $badan */
    protected function kirim(array $badan, ?WorkbookMaster $wb = null): TestResponse
    {
        return $this->postJson('/api/studio/workbook/'.($wb ?? $this->wb)->id.'/draf', $badan);
    }

    /** @param  array<string, mixed>  $badan */
    protected function kirimPut(int|string $versiId, array $badan, ?WorkbookMaster $wb = null): TestResponse
    {
        return $this->putJson('/api/studio/workbook/'.($wb ?? $this->wb)->id.'/draf/'.$versiId, $badan);
    }

    protected function bacaDraf(int|string $versiId, ?WorkbookMaster $wb = null): TestResponse
    {
        return $this->getJson('/api/studio/workbook/'.($wb ?? $this->wb)->id.'/draf/'.$versiId);
    }

    protected function bacaDasar(?WorkbookMaster $wb = null): TestResponse
    {
        return $this->getJson('/api/studio/workbook/'.($wb ?? $this->wb)->id.'/draf-dasar');
    }

    /**
     * Buat draf lewat POST (T5 = 0.84 secara bawaan).
     *
     * @param  list<array{sel: string, nilai: string}>|null  $suntingan
     * @return array{id: int, hash: string, respons: TestResponse}
     */
    protected function buatDraf(?array $suntingan = null): array
    {
        $res = $this->kirim($this->badan($suntingan === null ? [] : ['suntingan' => $suntingan]))->assertCreated();

        return ['id' => (int) $res->json('data.versi.id'), 'hash' => (string) $res->json('data.hash.isi'), 'respons' => $res];
    }

    /** @return array<string, int> */
    protected function hitung(): array
    {
        return [
            'paket' => PaketAcuan::withTrashed()->count(),
            'versi' => PaketAcuanVersi::count(),
            'suntingan' => PaketAcuanSuntingan::count(),
            'log' => PaketAcuanLog::count(),
            'audit' => DB::table('audit_logs')->count(),
            'hitungan' => DB::table('uncertainty_calculations')->count(),
        ];
    }

    /** @return array<array-key, mixed> */
    protected function isiV1(): array
    {
        return json_decode((string) $this->v1->isi, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<array-key, mixed>  $isi */
    protected function indeksPita(array $isi, string $kode): int
    {
        foreach ($isi['cmc'] as $i => $c) {
            if (($c['kode'] ?? null) === $kode) {
                return $i;
            }
        }

        $this->fail("pita {$kode} tidak ada");
    }

    /** Semua kolom (mentah) satu versi, dari DB. @return array<string, mixed> */
    protected function atributVersi(int $id): array
    {
        return PaketAcuanVersi::query()->findOrFail($id)->getAttributes();
    }

    protected function hashIsi(int $id): string
    {
        return PenentuVersiAcuan::sha256(PaketAcuanVersi::query()->findOrFail($id));
    }

    /**
     * Ubah baris workbook `$this->wb` di DB SESUDAH `authorize()` membacanya (event
     * `retrieved` pertama) dan SEBELUM service membaca ulang di dalam transaksi.
     * Model yang dipegang request tetap bernilai lama; hanya pembacaan ulang yang
     * melihat perubahan. Pasangannya [lepasHookWorkbook].
     *
     * @param  array<string, mixed>  $kolom
     */
    protected function ubahWorkbookSetelahDibaca(array $kolom): void
    {
        $sudah = false;
        $id = (int) $this->wb->id;

        WorkbookMaster::retrieved(static function (WorkbookMaster $m) use (&$sudah, $kolom, $id): void {
            if ($sudah || (int) $m->id !== $id) {
                return;
            }

            $sudah = true;
            DB::table('workbook_master')->where('id', $id)->update($kolom);
        });
    }

    protected function lepasHookWorkbook(): void
    {
        Event::forget('eloquent.retrieved: '.WorkbookMaster::class);
    }

    /**
     * Jalankan `$aksi` dan kembalikan SQL yang berjalan DI DALAM transaksi layanan
     * (level transaksi lebih dalam daripada saat tes mulai; `RefreshDatabase` sendiri
     * membungkus tes dalam satu transaksi). SQL pertama menunjukkan pembacaan
     * pertama transaksi: MySQL REPEATABLE READ membentuk snapshot pada SELECT
     * konsisten pertama, jadi yang boleh pertama hanyalah pembacaan pengunci.
     *
     * @return list<string>
     */
    protected function sqlDalamTransaksi(callable $aksi): array
    {
        $dasar = DB::transactionLevel();
        $sql = [];
        $aktif = true;
        DB::listen(static function ($q) use (&$sql, &$aktif, $dasar): void {
            if ($aktif && DB::transactionLevel() > $dasar) {
                $sql[] = (string) $q->sql;
            }
        });

        try {
            $aksi();
        } finally {
            $aktif = false;
        }

        return $sql;
    }

    /** Pasang perekam kunci ke PaketAcuan dan PaketAcuanVersi. */
    protected function pasangPerekamKunci(): void
    {
        PerekamKunci::reset();
        PaketAcuan::addGlobalScope(new PerekamKunci);
        PaketAcuanVersi::addGlobalScope(new PerekamKunci);
    }
}
