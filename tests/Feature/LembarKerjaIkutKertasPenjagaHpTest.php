<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\ProfilGenerik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua syarat GAMBAR di HP yang tidak memunculkan error di server mana pun kalau
 * dilanggar — ketahuan waktu tinjauan W1 "lembar kerja ikut kertas" (9 Okt 2026).
 *
 * Revisi ikut-kertas memindah field antar bagian dan memasang kartu di lebih
 * banyak bagian. Dua-duanya "cuma tampilan" di server, tapi HP punya dua aturan
 * gambar yang bisa membuat isian HILANG DARI LAYAR tanpa satu pun error:
 *
 *  1. **Kondisi lingkungan** (`lembar_kerja_screen.dart`, `_kodeKondisiLingkungan`
 *     & `_kondisiLingkungan()`): tabel Env. Condition hanya digambar kalau suhu &
 *     RH awal/akhir ada di bagian yang SAMA, tapi keenam kodenya (termasuk
 *     `tekanan_awal`/`tekanan_akhir`) SELALU dilewati di daftar field biasa.
 *     Kode itu di bagian lain = kotaknya tidak tergambar sama sekali. Untuk
 *     Hydrometer itu berarti tekanan udara tidak bisa diisi dan hitungannya
 *     tertahan (HydrometerProfile: tekanan null → titik tidak dihitung).
 *  2. **Kartu** (`lembar_kerja_kartu_baris.dart`): jumlah kartu dan kepala tiap
 *     kartu dibaca dari `tabel.first`; tabel lain di bagian itu diambil PER
 *     INDEKS. Tabel yang barisnya beda jumlah kehilangan baris ekornya di layar,
 *     dan yang labelnya beda tergambar di bawah kepala titik yang salah.
 *
 * Daftarnya dari REGISTRY, dua bentuk (teknisi & admin) — profil baru ikut
 * tanpa ada yang perlu ingat.
 */
class LembarKerjaIkutKertasPenjagaHpTest extends TestCase
{
    use RefreshDatabase;

    /** Salinan `_kodeKondisiLingkungan` di HP — kode yang dilewati daftar field. */
    private const KODE_LINGKUNGAN = [
        'suhu_awal', 'kelembaban_awal', 'suhu_akhir', 'kelembaban_akhir', 'tekanan_awal', 'tekanan_akhir',
    ];

    /** Empat kode yang WAJIB lengkap supaya HP menggambar tabelnya. */
    private const SUHU_RH = ['suhu_awal', 'kelembaban_awal', 'suhu_akhir', 'kelembaban_akhir'];

    /** `tampilan` yang digambar `LembarKerjaKartuBaris` (BagianLembarKerja.kartuPerBaris). */
    private const TAMPILAN_KARTU = ['kartu_per_baris', 'kartu_per_set_point'];

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    /**
     * Semua bentuk lembar: profil berlembar × (teknisi, admin).
     *
     * @return array<string, array<string, mixed>>
     */
    private function semuaBentuk(): array
    {
        $hasil = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            if ($profil instanceof ProfilGenerik) {
                continue;
            }

            $hasil[$profil->kode().' (teknisi)'] = $profil->bentukLembarKerja();
            $hasil[$profil->kode().' (admin)'] = $profil->bentukLembarKerja(untukAdmin: true);
        }

        // Lantai, pola SemuaProfilLembarKerjaTest: sapuan yang daftarnya
        // menyusut tetap menulis OK.
        $this->assertGreaterThanOrEqual(34, count($hasil), 'Registry menyusut — sapuan ini jadi tidak memeriksa yang hilang.');

        return $hasil;
    }

    public function test_kondisi_lingkungan_selalu_sebagian_dengan_suhu_dan_rh(): void
    {
        $langgar = [];

        foreach ($this->semuaBentuk() as $nama => $bentuk) {
            foreach ($bentuk['bagian'] ?? [] as $bagian) {
                $kode = array_column($bagian['field'] ?? [], 'kode');
                $lingkungan = array_values(array_intersect($kode, self::KODE_LINGKUNGAN));

                if ($lingkungan === []) {
                    continue;
                }

                $kurang = array_values(array_diff(self::SUHU_RH, $kode));
                if ($kurang !== []) {
                    $langgar[] = "{$nama} bagian {$bagian['kode']}: memuat ".implode(', ', $lingkungan)
                        .' tanpa '.implode(', ', $kurang).' — HP tidak menggambar kotaknya.';
                }

                // Tekanan dipasangkan: HP cuma menggambar kolom tekanan kalau
                // awal DAN akhir ada; separuh pasang dilewati diam-diam.
                $tekanan = array_intersect(['tekanan_awal', 'tekanan_akhir'], $kode);
                if (count($tekanan) === 1) {
                    $langgar[] = "{$nama} bagian {$bagian['kode']}: tekanan cuma separuh pasang (".implode(', ', $tekanan).').';
                }
            }
        }

        $this->assertSame([], $langgar, implode("\n", $langgar));
    }

    /**
     * Tiap bagian berkartu: semua tabel yang tampil bersamaan punya baris
     * identik per indeks — jumlah, `label`, `titik_ukur` — di `baris` dan di
     * tiap `baris_per_satuan` (Refractometer n20D/°Brix), plus
     * `titik_bisa_diubah` yang sama (titik kustom HP disimpan per bagian).
     */
    public function test_tabel_sebagian_berkartu_punya_baris_identik(): void
    {
        $langgar = [];
        $diperiksa = 0;

        foreach ($this->semuaBentuk() as $nama => $bentuk) {
            foreach ($bentuk['bagian'] ?? [] as $bagian) {
                if (! in_array($bagian['tampilan'] ?? null, self::TAMPILAN_KARTU, true)) {
                    continue;
                }

                foreach ($this->kelompokTampilBersama($bagian['tabel'] ?? []) as $syarat => $tabel) {
                    $diperiksa++;
                    $tempat = "{$nama} bagian {$bagian['kode']}".($syarat === '' ? '' : " [{$syarat}]");

                    if ($tabel === []) {
                        $langgar[] = "{$tempat}: berkartu tanpa tabel.";

                        continue;
                    }

                    $acuan = $tabel[0];
                    $satuan = array_unique(array_merge(...array_map(
                        fn (array $t): array => array_keys($t['baris_per_satuan'] ?? []),
                        $tabel,
                    )));

                    foreach (array_slice($tabel, 1) as $t) {
                        $judul = $t['judul'] ?? $t['tahap'] ?? '?';

                        if (($t['titik_bisa_diubah'] ?? false) !== ($acuan['titik_bisa_diubah'] ?? false)) {
                            $langgar[] = "{$tempat}: `{$judul}` beda titik_bisa_diubah dengan tabel pertama.";
                        }

                        foreach (['' => null, ...array_fill_keys($satuan, null)] as $s => $_) {
                            $a = $this->barisEfektif($acuan, (string) $s);
                            $b = $this->barisEfektif($t, (string) $s);

                            if ($a !== $b) {
                                $langgar[] = "{$tempat}: `{$judul}` barisnya beda dari tabel pertama"
                                    .($s === '' ? '' : " (satuan {$s})").': '
                                    .json_encode($b, JSON_UNESCAPED_UNICODE).' ≠ '.json_encode($a, JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $diperiksa, 'Tidak ada satu pun bagian berkartu — sapuan ini tidak memeriksa apa-apa.');
        $this->assertSame([], $langgar, implode("\n", $langgar));
    }

    /**
     * Kartu belum punya pemilih/centang standar per titik (`titikBisaDiisi`,
     * `eksklusif_dengan`), sementara kartu jadi tampilan AWAL. Untuk empat alat
     * yang standar per titiknya dipilih teknisi, tampilan awal tetap tabel
     * sampai kartu HP mendukungnya (tinjauan W1 temuan 3).
     */
    public function test_alat_berstandar_per_titik_tidak_berkartu(): void
    {
        $registry = app(CalibrationProfileRegistry::class);

        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'do_meter'] as $kode) {
            foreach ([false, true] as $admin) {
                foreach ($registry->untukKode($kode)->bentukLembarKerja($admin)['bagian'] as $bagian) {
                    $this->assertArrayNotHasKey(
                        'tampilan',
                        $bagian,
                        "{$kode} bagian {$bagian['kode']}: kartu belum bisa memilih standar per titik.",
                    );
                }
            }
        }
    }

    /**
     * Tabel dikelompokkan menurut yang TAMPIL BERSAMAAN di HP
     * (`LembarKerjaState.tabelTampil`): tabel tanpa `tampil_kalau` selalu
     * tampil; yang bersyarat tampil bersama tabel lain bersyarat sama.
     *
     * @param  list<array<string, mixed>>  $tabel
     * @return array<string, list<array<string, mixed>>>
     */
    private function kelompokTampilBersama(array $tabel): array
    {
        $syarat = [];

        foreach ($tabel as $t) {
            if (isset($t['tampil_kalau'])) {
                $syarat[json_encode($t['tampil_kalau'], JSON_UNESCAPED_UNICODE)] = true;
            }
        }

        if ($syarat === []) {
            return ['' => array_values($tabel)];
        }

        $hasil = [];
        foreach (array_keys($syarat) as $kunci) {
            // Urutan aslinya dipertahankan: HP mengambil `tabelTampil.first`.
            $hasil[$kunci] = array_values(array_filter(
                $tabel,
                fn (array $t): bool => ! isset($t['tampil_kalau']) || json_encode($t['tampil_kalau'], JSON_UNESCAPED_UNICODE) === $kunci,
            ));
        }

        return $hasil;
    }

    /**
     * Baris yang digambar HP untuk satuan [$satuan] (`TabelHasil.barisUntuk`):
     * `baris_per_satuan[$satuan] ?? baris`, diringkas ke yang dibaca kartu.
     *
     * @param  array<string, mixed>  $tabel
     * @return list<array{label: mixed, titik_ukur: mixed}>
     */
    private function barisEfektif(array $tabel, string $satuan): array
    {
        $baris = ($satuan !== '' ? ($tabel['baris_per_satuan'][$satuan] ?? null) : null) ?? ($tabel['baris'] ?? []);

        return array_map(
            fn (array $b): array => ['label' => $b['label'] ?? null, 'titik_ukur' => $b['titik_ukur'] ?? null],
            array_values($baris),
        );
    }
}
