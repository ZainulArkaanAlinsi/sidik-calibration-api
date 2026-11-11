<?php

namespace Tests\Unit;

use App\Services\Calibration\PutaranCalculator;
use App\Services\Calibration\TabelStandarPutaran;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jejak audit penyimpangan SADAR kelompok Putaran (Centrifuge + Tachometer) dari
 * master — AGENTS.md "Olah data" butir 4.
 *
 * Tiga hal yang dikunci:
 *
 *  1. Catatannya ADA, menyebut sel master + selisih + dasar, dan angka "versi
 *     master"-nya benar: dihitung ulang ke nilai sel workbook (`U` di fixture
 *     `waktu-frekuensi-master.json`), bukan sekadar teks.
 *  2. Catatan itu HANYA catatan: seluruh angka hasil hitung (titik, budget,
 *     uc, veff, k, U, U95 tercetak) identik bit-per-bit dengan keluaran SEBELUM
 *     catatan ada (`putaran-angka-sebelum-jejak-audit.json`, direkam dari kode
 *     main b1b1d10e).
 *  3. Arah aplikasi tidak pernah lebih kecil dari master.
 *
 * Merekam ulang golden (HANYA bila angka memang sengaja berubah lewat PR rumus
 * dan simulasi, bukan untuk menghijaukan tes ini):
 *
 *     REKAM_ANGKA_PUTARAN=1 php artisan test --filter=PutaranJejakPenyimpanganTest
 */
class PutaranJejakPenyimpanganTest extends TestCase
{
    private const GOLDEN = 'tests/Fixtures/putaran-angka-sebelum-jejak-audit.json';

    /** Toleransi pembanding ke sel master — sama dengan `WaktuFrekuensiMasterTest`. */
    private const TOL = 5e-6;

    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            // Bukan `base_path()`: dipanggil juga dari data provider, sebelum aplikasi hidup.
            (string) file_get_contents(dirname(__DIR__).'/Fixtures/waktu-frekuensi-master.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function hitung(string $alat, array $b, bool $denganKonteksAlat = true, ?TabelStandarPutaran $tabel = null): array
    {
        $titik = collect(self::fixture()[$alat]['titik'])
            ->where('baris', $b['baris_ukur'])
            ->map(static fn (array $t): array => [
                'titik_ke' => $t['titik_ke'],
                'titik_ukur' => $t['set_point'],
                'pembacaan' => $t['pembacaan'],
            ])
            ->values()
            ->all();

        $konteks = [
            'resolusi_uut' => (float) $b['resolusi_uut'],
            'cmc' => (float) $b['harap']['cmc'],
            'satuan' => 'rpm',
        ];

        if ($denganKonteksAlat) {
            // Indeks blok = urutan di fixture (1-based), sama dengan
            // `ProfilPutaran::hitungPerGrup()` yang memotong tiap tiga `titik_ke`.
            $konteks['alat'] = $alat;
            $konteks['blok_ke'] = 1 + array_search(
                $b['baris'],
                array_column(self::fixture()[$alat]['blok'], 'baris'),
                true,
            );
        }

        return ($tabel === null ? new PutaranCalculator : new PutaranCalculator($tabel))->hitungBlok($titik, $konteks);
    }

    /** @return iterable<string, array{string, int}> alat + baris blok di master */
    public static function semuaBlok(): iterable
    {
        foreach (['tachometer', 'centrifuge'] as $alat) {
            foreach (self::fixture()[$alat]['blok'] as $b) {
                yield "{$alat} blok r{$b['baris']}" => [$alat, $b['baris']];
            }
        }
    }

    /** @return array<string, mixed> */
    private function blokDi(string $alat, int $baris): array
    {
        return collect(self::fixture()[$alat]['blok'])->firstWhere('baris', $baris);
    }

    /** @param  array<string, mixed>  $hasil */
    private function indeks(array $hasil): array
    {
        return collect($hasil['catatan_audit'])->keyBy('kode')->all();
    }

    // ------------------------------------------------------------ angka tidak berubah

    /**
     * Seluruh angka hasil hitung identik dengan keluaran SEBELUM catatan ada.
     */
    public function test_angka_hasil_hitung_tidak_berubah_dibanding_sebelum_jejak_audit(): void
    {
        $sekarang = [];

        foreach (['tachometer', 'centrifuge'] as $alat) {
            foreach (self::fixture()[$alat]['blok'] as $b) {
                $hasil = $this->hitung($alat, $b);
                $sekarang["{$alat}:r{$b['baris']}"] = [
                    'titik' => $hasil['titik'],
                    'budget' => $hasil['budget'],
                    'ketidakpastian_gabungan' => $hasil['ketidakpastian_gabungan'],
                    'derajat_kebebasan_efektif' => $hasil['derajat_kebebasan_efektif'],
                    'faktor_cakupan_k' => $hasil['faktor_cakupan_k'],
                    'ketidakpastian_diperluas' => $hasil['ketidakpastian_diperluas'],
                    'u95_sertifikat' => $hasil['u95_sertifikat'],
                    'type_b' => $hasil['type_b'],
                    'ditolak' => $hasil['ditolak'],
                ];
            }
        }

        $json = json_encode($sekarang, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if (getenv('REKAM_ANGKA_PUTARAN') === '1') {
            file_put_contents(base_path(self::GOLDEN), $json."\n");
            $this->markTestSkipped('Mode rekam: golden ditulis ulang.');
        }

        $golden = (string) file_get_contents(base_path(self::GOLDEN));

        $this->assertSame(
            json_decode($golden, true, flags: JSON_THROW_ON_ERROR),
            json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            'Angka hasil hitung Putaran bergeser dibanding sebelum jejak audit — catatan audit tidak boleh mengubah angka apa pun.',
        );
    }

    /** Konteks `alat`/`blok_ke` hanya memengaruhi catatan: angkanya sama dengan pemanggil lama; tanpa `alat` tidak ada catatan. */
    public function test_tanpa_konteks_alat_angka_sama_dan_tanpa_catatan(): void
    {
        $b = $this->blokDi('centrifuge', 9);

        $dengan = $this->hitung('centrifuge', $b);
        $tanpa = $this->hitung('centrifuge', $b, denganKonteksAlat: false);

        foreach (['titik', 'budget', 'ketidakpastian_gabungan', 'derajat_kebebasan_efektif', 'faktor_cakupan_k', 'ketidakpastian_diperluas', 'u95_sertifikat', 'type_b'] as $kunci) {
            $this->assertSame($dengan[$kunci], $tanpa[$kunci], "Konteks alat mengubah `{$kunci}`.");
        }

        // Tanpa konteks alat, catatan penyimpangan Putaran TIDAK dibuat: kode catatan
        // hanya berlaku untuk centrifuge/tachometer yang dikenali eksplisit.
        $this->assertSame([], $tanpa['catatan_audit'] ?? []);
    }

    // ------------------------------------------------------------ isi catatan

    /**
     * Setiap blok yang bukan blok rusak: hanya drift. Dan versi-master yang
     * dihitung catatan = nilai `U` di sel master (bukan angka karangan).
     */
    #[DataProvider('semuaBlok')]
    public function test_u95_versi_master_di_catatan_sama_dengan_sel_master(string $alat, int $baris): void
    {
        $b = $this->blokDi($alat, $baris);
        $hasil = $this->hitung($alat, $b);
        $catatan = $this->indeks($hasil);

        $this->assertArrayHasKey('putaran_ringkasan_penyimpangan_master', $catatan);

        $ringkas = $catatan['putaran_ringkasan_penyimpangan_master'];
        $rusak = (bool) ($b['rusak'] ?? false);

        if ($alat === 'tachometer' && $rusak) {
            // Blok 5 Tachometer diadu di test_blok5_tachometer_* di bawah.
            $this->assertCount(5, $hasil['catatan_audit']);

            return;
        }

        $this->assertEqualsWithDelta(
            (float) $b['harap']['U'], $ringkas['u95_blok_master_semua'], self::TOL,
            "{$alat} r{$baris}: U95 'versi master' di catatan harus sama dengan sel U master.",
        );
        $this->assertEqualsWithDelta(
            (float) $b['harap']['u95_sertifikat'], $ringkas['u95_cetak_master'], self::TOL,
            "{$alat} r{$baris}: U95 tercetak 'versi master' harus sama dengan sel U95 sertifikat master.",
        );
        $this->assertEqualsWithDelta(
            $hasil['u95_sertifikat'], $ringkas['u95_cetak_aplikasi'], 1e-12,
            'Ringkasan harus memuat U95 tercetak aplikasi yang sebenarnya.',
        );
        $this->assertGreaterThanOrEqual(
            $ringkas['u95_cetak_master'] - 1e-12, $ringkas['u95_cetak_aplikasi'],
            'Arah aplikasi tidak boleh lebih kecil dari master.',
        );
    }

    public function test_blok1_centrifuge_mencatat_drift_dan_sel_tunggal_g34(): void
    {
        $b = $this->blokDi('centrifuge', 9);
        $hasil = $this->hitung('centrifuge', $b);
        $catatan = $this->indeks($hasil);

        $this->assertSame(
            [
                'putaran_drift_standar_kolom_k_tidak_lengkap',
                'putaran_pengulangan_blok1_sel_tunggal_g34',
                'putaran_ringkasan_penyimpangan_master',
            ],
            array_keys($catatan),
        );

        $g34 = $catatan['putaran_pengulangan_blok1_sel_tunggal_g34'];
        $this->assertStringContainsString('PERHITUNGAN!G34', $g34['pesan']);
        $this->assertStringContainsString('MAX(G34:L34)', $g34['pesan']);
        $this->assertStringContainsString('WaktuFrekuensiMasterTest', $g34['pesan']);
        $this->assertEqualsWithDelta(0.02, $g34['u_baku_master'], self::TOL, 'G34 master = 0,02 rpm (fixture).');
        $this->assertEqualsWithDelta(0.04, $g34['u_baku_aplikasi'], self::TOL, 'MAX sebaris = 0,04 rpm.');
        $this->assertGreaterThan(
            $g34['u95_blok_master_terisolasi'], $g34['u95_blok_aplikasi'],
            'Selisih terisolasi G34 harus positif (aplikasi lebih besar).',
        );

        $drift = $catatan['putaran_drift_standar_kolom_k_tidak_lengkap'];
        $this->assertStringContainsString('K35', $drift['pesan']);
        $this->assertStringContainsString('2.25', $drift['pesan']);
        $this->assertStringContainsString('2.75', $drift['pesan']);
        $this->assertStringContainsString('5 dari 15', $drift['pesan']);
        $this->assertStringContainsString('selisih +0.5', $drift['pesan']);
        $this->assertEqualsWithDelta(2.25 / sqrt(3), $drift['u_baku_master'], 1e-9);
        $this->assertEqualsWithDelta(2.75 / sqrt(3), $drift['u_baku_aplikasi'], 1e-9);
    }

    public function test_blok_centrifuge_lain_hanya_mencatat_drift(): void
    {
        foreach ([40, 60, 79, 98] as $baris) {
            $hasil = $this->hitung('centrifuge', $this->blokDi('centrifuge', $baris));
            $this->assertSame(
                ['putaran_drift_standar_kolom_k_tidak_lengkap', 'putaran_ringkasan_penyimpangan_master'],
                array_column($hasil['catatan_audit'], 'kode'),
            );
        }
    }

    public function test_blok5_tachometer_mencatat_kerusakan_master_dan_bukan_drift_k35(): void
    {
        $b = $this->blokDi('tachometer', 98);
        $hasil = $this->hitung('tachometer', $b);
        $catatan = $this->indeks($hasil);

        $this->assertSame(
            [
                'putaran_pita_sertifikat_blok5_f15',
                'putaran_resolusi_standar_blok5_ambang',
                'putaran_drift_blok5_tautan_luar_k54',
                'putaran_pengulangan_blok5_baris_kosong',
                'putaran_ringkasan_penyimpangan_master',
            ],
            array_keys($catatan),
        );

        $this->assertStringContainsString("'SERTIFIKAT KALIBRATOR'!F15", $catatan['putaran_pita_sertifikat_blok5_f15']['pesan']);
        $this->assertEqualsWithDelta(1.6 / 2, $catatan['putaran_pita_sertifikat_blok5_f15']['u_baku_master'], self::TOL);
        $this->assertEqualsWithDelta(3.1 / 2, $catatan['putaran_pita_sertifikat_blok5_f15']['u_baku_aplikasi'], self::TOL);
        $this->assertStringContainsString("'[1]Drift Std Kalibrator'!K54", $catatan['putaran_drift_blok5_tautan_luar_k54']['pesan']);
        $this->assertSame(0.0, $catatan['putaran_drift_blok5_tautan_luar_k54']['u_baku_master']);
        $this->assertStringContainsString('G113:L113', $catatan['putaran_pengulangan_blok5_baris_kosong']['pesan']);
        $this->assertSame(0.0, $catatan['putaran_pengulangan_blok5_baris_kosong']['u_baku_master']);

        $this->assertEqualsWithDelta(0.1 / 2 / sqrt(3), $catatan['putaran_resolusi_standar_blok5_ambang']['u_baku_master'], 1e-9);
        $this->assertEqualsWithDelta(1.0 / 2 / sqrt(3), $catatan['putaran_resolusi_standar_blok5_ambang']['u_baku_aplikasi'], 1e-9);

        foreach (['pita_sertifikat_blok5_f15', 'resolusi_standar_blok5_ambang', 'drift_blok5_tautan_luar_k54', 'pengulangan_blok5_baris_kosong'] as $k) {
            $this->assertGreaterThan(
                $catatan["putaran_{$k}"]['u95_blok_master_terisolasi'], $catatan["putaran_{$k}"]['u95_blok_aplikasi'],
                "{$k}: efek terisolasi harus membuat aplikasi LEBIH BESAR dari master.",
            );
        }

        // Master mencetak 5,0 (lantai CMC) dan aplikasi juga: angka cetak sama,
        // tapi selisih sebelum lantai (1,69 vs ±4) tetap tercatat.
        $ringkas = $catatan['putaran_ringkasan_penyimpangan_master'];
        // Rekonsiliasi lengkap: dengan keempat penyimpangan disamakan, hasilnya
        // sama dengan sel U master (1,6949) — tidak ada penyimpangan yang terlewat.
        $this->assertEqualsWithDelta((float) $b['harap']['U'], $ringkas['u95_blok_master_semua'], self::TOL);
        $this->assertEqualsWithDelta(5.0, $ringkas['u95_cetak_master'], self::TOL);
        $this->assertEqualsWithDelta(5.0, $ringkas['u95_cetak_aplikasi'], self::TOL);
        $this->assertGreaterThan($ringkas['u95_blok_master_semua'], $hasil['ketidakpastian_diperluas']);
    }

    /** Dengan tabel drift versi master (subkelas sekali pakai) tidak ada catatan drift. */
    public function test_tabel_versi_master_tidak_melahirkan_catatan_drift(): void
    {
        $hasil = $this->hitung('tachometer', $this->blokDi('tachometer', 40), tabel: new TabelStandarPutaranVersiMasterJejak);

        $this->assertSame([], $hasil['catatan_audit']);
    }
}

/** Tabel putaran berdrift versi master — padanan di `WaktuFrekuensiMasterTest`. */
class TabelStandarPutaranVersiMasterJejak extends TabelStandarPutaran
{
    public function driftSetengahLebar(bool $lengkap = true): float
    {
        return parent::driftSetengahLebar(lengkap: false);
    }
}
