<?php

namespace Tests\Unit;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\FormatMasterSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\Concerns\GerbangHurufSertifikat as Gerbang;
use Tests\Concerns\PengukurHurufCanvas;
use Tests\TestCase;

/**
 * Gerbang huruf sertifikat (Sieve 13 Nov 2026), bagian CEPAT: aturan penilai dan
 * keutuhan berkas baseline, tanpa merender sertifikat.
 *
 * Sapuan merender yang menerapkannya ke semua profil ada di
 * `Feature\SertifikatSemuaAlatSatuHalamanTest` (memakai sesi yang sudah
 * diterbitkan sapuan itu, jadi tidak menambah render). Di sini yang dikunci
 * adalah PENILAINYA — supaya melonggarkan ambang, menghapus pemeriksaan
 * kelengkapan, atau menaikkan baseline dengan tangan membuat tes ini merah
 * sebelum sapuan 20 menit sempat jalan.
 */
class GerbangHurufSertifikatTest extends TestCase
{
    /**
     * sha256 `tests/Fixtures/sertifikat-huruf/master-pt.json` (akhir baris LF).
     *
     * Berkas itu tolok ukur gerbang huruf: mengubah satu angkanya berarti
     * mengubah apa yang disebut "sepadan master". Tes ini sengaja menolak
     * perubahan diam-diam — kalau memang ekstraksi masternya diulang, perbarui
     * hash ini dan tulis di deskripsi PR master mana yang diekstraksi ulang.
     */
    private const HASH_MASTER_PT = '5ef53a5353cd52ed5383b0a212ae09ede8615592cc5662df116c610187b9650f';

    /** @return array<string, mixed> */
    private function baseline(array $profil, array $pengecualian = []): array
    {
        return ['pengecualian' => $pengecualian, 'profil' => $profil];
    }

    private function entri(float $master, ?float $rasio): array
    {
        return ['master_pt' => $master, 'app_pt' => $rasio === null ? null : round($master * $rasio, 1), 'rasio' => $rasio];
    }

    /** @return array<string, array{app_pt: float, sesi: string}> */
    private function ukur(array $perProfil): array
    {
        return array_map(fn (float $pt): array => ['app_pt' => $pt, 'sesi' => 'S-1'], $perProfil);
    }

    // ---------------------------------------------------------------- alat ukur

    public function test_median_sama_dengan_median_per_karakter(): void
    {
        $this->assertSame(0.0, PengukurHurufCanvas::median([]));
        $this->assertSame(7.1, PengukurHurufCanvas::median(['7.1' => 3]));
        // Genap: rata-rata dua tengah (statistics.median).
        $this->assertSame(7.65, PengukurHurufCanvas::median(['7.1' => 2, '8.2' => 2]));
        // Ganjil, tak terurut masuk: 4 x 6,0 + 5 x 8,2 -> elemen ke-5 = 8,2.
        $this->assertSame(8.2, PengukurHurufCanvas::median(['8.2' => 5, '6.0' => 4]));
        $this->assertSame(6.0, PengukurHurufCanvas::median(['8.2' => 4, '6.0' => 5]));
        $this->assertSame(7.1, PengukurHurufCanvas::median(['9.5' => 1, '7.1' => 1, '6.0' => 1]));
    }

    public function test_pengukur_mencatat_ukuran_huruf_render_dompdf_sungguhan_dan_cocok_lewat_md5(): void
    {
        PengukurHurufCanvas::pasang();

        $html = '<html><body style="font-family: DejaVu Sans; font-size: 10px">abc def'
            .'<p style="font-size: 12px">gh &nbsp; i</p></body></html>';
        $isi = Pdf::loadHTML($html)->output();

        $catatan = PengukurHurufCanvas::catatan($isi);

        $this->assertNotNull($catatan, 'Canvas pengukur tidak terpasang — render tidak tercatat.');
        $this->assertSame(1, $catatan['halaman']);
        // 10 px = 7,5 pt: a b c d e f; 12 px = 9,0 pt: g h i. Spasi (termasuk &nbsp;) tidak dihitung.
        $this->assertSame(['7.5' => 6, '9.0' => 3], $catatan['ukuran']);
        $this->assertSame(7.5, PengukurHurufCanvas::median($catatan['ukuran']));

        $this->assertNull(PengukurHurufCanvas::catatan($isi.'x'), 'PDF yang bukan keluaran canvas ini tidak boleh punya catatan.');
    }

    // ---------------------------------------------------------------- penilai

    public function test_lolos_bila_rasio_sama_dengan_baseline(): void
    {
        $baseline = $this->baseline(['a' => $this->entri(8.0, 0.95), 'b' => $this->entri(7.0, 1.0)]);

        $this->assertSame([], Gerbang::periksa($this->ukur(['a' => 7.6, 'b' => 7.0]), ['a', 'b'], $baseline));
    }

    public function test_rasio_turun_dari_baseline_gagal(): void
    {
        $baseline = $this->baseline(['a' => $this->entri(8.0, 1.0)]);
        $masalah = Gerbang::periksa($this->ukur(['a' => 7.6]), ['a'], $baseline);

        $this->assertCount(1, $masalah);
        $this->assertStringContainsString('TURUN dari baseline', $masalah[0]);
    }

    public function test_rasio_naik_tanpa_baseline_baru_gagal_dengan_pesan_perbarui(): void
    {
        $baseline = $this->baseline(['a' => $this->entri(8.0, 0.93)]);
        $masalah = Gerbang::periksa($this->ukur(['a' => 8.0]), ['a'], $baseline);

        $this->assertCount(1, $masalah);
        $this->assertStringContainsString('NAIK di atas baseline', $masalah[0]);
        $this->assertStringContainsString('SIDIK_TULIS_BASELINE=1', $masalah[0]);
    }

    public function test_baseline_tidak_bisa_menerima_rasio_di_bawah_ambang(): void
    {
        // Baseline "diturunkan" ke 0,83 supaya tes hijau: ambang mutlak tetap menolak (kasus Sieve).
        $baseline = $this->baseline(['sieve' => $this->entri(8.52, 0.83)]);
        $masalah = Gerbang::periksa($this->ukur(['sieve' => 7.1]), ['sieve'], $baseline);

        $this->assertCount(1, $masalah);
        $this->assertStringContainsString('MENYUSUT di bawah', $masalah[0]);
    }

    public function test_ambang_bawah_berhenti_tepat_di_0_90_dan_atas_di_1_10(): void
    {
        $this->assertSame(0.90, Gerbang::AMBANG_BAWAH);
        $this->assertSame(1.10, Gerbang::AMBANG_ATAS);

        // 0,90 persis lolos; 0,89 tidak. Master 10 pt: 9,0 dan 8,9 pt.
        $this->assertSame([], Gerbang::periksa($this->ukur(['a' => 9.0]), ['a'], $this->baseline(['a' => $this->entri(10.0, 0.9)])));
        $this->assertNotSame([], Gerbang::periksa($this->ukur(['a' => 8.9]), ['a'], $this->baseline(['a' => $this->entri(10.0, 0.89)])));
        $this->assertSame([], Gerbang::periksa($this->ukur(['a' => 11.0]), ['a'], $this->baseline(['a' => $this->entri(10.0, 1.1)])));
        $this->assertNotSame([], Gerbang::periksa($this->ukur(['a' => 11.1]), ['a'], $this->baseline(['a' => $this->entri(10.0, 1.11)])));
    }

    public function test_membengkak_butuh_pengecualian_beralasan_dan_tetap_dikunci(): void
    {
        $profil = ['thermohygro' => $this->entri(4.8, 1.48)];

        $tanpa = Gerbang::periksa($this->ukur(['thermohygro' => 7.1]), ['thermohygro'], $this->baseline($profil));
        $this->assertStringContainsString('MEMBENGKAK', implode("\n", $tanpa));

        $alasan = 'Master dicetak skala 50 persen untuk dua puluh baris tetap; menyamakannya tak terbaca.';
        $pengecualian = ['thermohygro' => ['alasan' => $alasan]];

        $this->assertSame([], Gerbang::periksa($this->ukur(['thermohygro' => 7.1]), ['thermohygro'], $this->baseline($profil, $pengecualian)));

        // Pengecualian TIDAK melepas ratchet: bergeser dari 1,48 tetap gagal.
        $geser = Gerbang::periksa($this->ukur(['thermohygro' => 6.0]), ['thermohygro'], $this->baseline($profil, $pengecualian));
        $this->assertStringContainsString('TURUN dari baseline', implode("\n", $geser));

        // Alasan kosong/pendek = pengecualian tidak sah.
        $pendek = Gerbang::periksa($this->ukur(['thermohygro' => 7.1]), ['thermohygro'], $this->baseline($profil, ['thermohygro' => ['alasan' => 'sengaja']]));
        $this->assertStringContainsString('wajib punya `alasan`', implode("\n", $pendek));
    }

    public function test_profil_yang_dikeluarkan_dari_gerbang_gagal(): void
    {
        $baseline = $this->baseline(['a' => $this->entri(8.0, 1.0), 'b' => $this->entri(8.0, 1.0)]);

        // Tidak terukur.
        $masalah = Gerbang::periksa($this->ukur(['a' => 8.0]), ['a', 'b'], $baseline);
        $this->assertStringContainsString('b: terdaftar tetapi TIDAK terukur', implode("\n", $masalah));

        // Dihapus dari baseline.
        $masalah = Gerbang::periksa($this->ukur(['a' => 8.0, 'b' => 8.0]), ['a', 'b'], $this->baseline(['a' => $this->entri(8.0, 1.0)]));
        $this->assertStringContainsString('b: terdaftar di registry tetapi TIDAK ada di baseline', implode("\n", $masalah));

        // Baseline menyimpan profil yang sudah tidak terdaftar.
        $masalah = Gerbang::periksa($this->ukur(['a' => 8.0]), ['a'], $baseline);
        $this->assertStringContainsString('b: ada di baseline huruf tetapi tidak terdaftar', implode("\n", $masalah));
    }

    public function test_entri_yang_belum_dibekukan_gagal(): void
    {
        $masalah = Gerbang::periksa($this->ukur(['a' => 8.0]), ['a'], $this->baseline(['a' => $this->entri(8.0, null)]));

        $this->assertCount(1, $masalah);
        $this->assertStringContainsString('BELUM dibekukan', $masalah[0]);
    }

    public function test_terburuk_memilih_ukuran_terkecil_antar_sesi(): void
    {
        $hasil = Gerbang::terburuk([
            'b' => [['app_pt' => 8.2, 'sesi' => 'B-2'], ['app_pt' => 7.1, 'sesi' => 'B-1']],
            'a' => [['app_pt' => 6.0, 'sesi' => 'A-1']],
        ]);

        $this->assertSame(['a', 'b'], array_keys($hasil));
        $this->assertSame(['app_pt' => 7.1, 'sesi' => 'B-1'], $hasil['b']);
    }

    public function test_tulis_menolak_turun_atau_keluar_ambang_dan_hanya_menulis_yang_sama_atau_membaik(): void
    {
        $berkas = tempnam(sys_get_temp_dir(), 'huruf');

        try {
            $baseline = $this->baseline(['a' => $this->entri(8.0, 0.93), 'b' => $this->entri(8.0, null)]);

            // a turun: menolak, berkas tak disentuh.
            file_put_contents($berkas, 'asli');
            $tolak = Gerbang::tulis($this->ukur(['a' => 7.3, 'b' => 8.0]), ['a', 'b'], $baseline, $berkas);
            $this->assertStringContainsString('TURUN dari baseline', implode("\n", $tolak));
            $this->assertSame('asli', file_get_contents($berkas));

            // b di luar ambang tanpa pengecualian: menolak.
            $tolak = Gerbang::tulis($this->ukur(['a' => 8.0, 'b' => 5.0]), ['a', 'b'], $baseline, $berkas);
            $this->assertStringContainsString('b: rasio 0.63 di luar', implode("\n", $tolak));

            // a naik, b dibekukan pertama kali: ditulis.
            $this->assertSame([], Gerbang::tulis($this->ukur(['a' => 8.0, 'b' => 7.6]), ['a', 'b'], $baseline, $berkas));
            $tertulis = json_decode((string) file_get_contents($berkas), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1.0, $tertulis['profil']['a']['rasio']);
            $this->assertSame(0.95, $tertulis['profil']['b']['rasio']);
            $this->assertSame(7.6, $tertulis['profil']['b']['app_pt']);
        } finally {
            @unlink($berkas);
        }
    }

    // ---------------------------------------------------------------- per sesi

    /** @return array<string, mixed> baseline satu profil dua sesi */
    private function baselineDuaSesi(): array
    {
        return $this->baseline(['micrometer' => [
            'master_pt' => 8.0, 'app_pt' => 7.2, 'rasio' => 0.9,
            'sesi' => ['M-1' => 7.2, 'M-2' => 8.0],
        ]]);
    }

    /** @return array<string, list<array{app_pt: float, sesi: string}>> */
    private function duaSesi(float $m1, float $m2): array
    {
        return ['micrometer' => [['app_pt' => $m1, 'sesi' => 'M-1'], ['app_pt' => $m2, 'sesi' => 'M-2']]];
    }

    public function test_sesi_yang_bukan_terburuk_juga_dikunci(): void
    {
        $baseline = $this->baselineDuaSesi();
        $perSesi = $this->duaSesi(7.2, 8.0);

        $this->assertSame([], Gerbang::periksa(Gerbang::terburuk($perSesi), ['micrometer'], $baseline, $perSesi));

        // M-2 mengecil 8,0 -> 7,6 (0,95), masih di atas M-1 (yang terburuk) dan di atas ambang: tanpa pagar per sesi lolos.
        $turun = $this->duaSesi(7.2, 7.6);
        $this->assertSame([], Gerbang::periksa(Gerbang::terburuk($turun), ['micrometer'], $baseline), 'Tanpa data per sesi, penurunan M-2 tidak terlihat.');

        $masalah = Gerbang::periksa(Gerbang::terburuk($turun), ['micrometer'], $baseline, $turun);
        $this->assertCount(1, $masalah);
        $this->assertStringContainsString('sesi M-2', $masalah[0]);
        $this->assertStringContainsString('TURUN dari baseline sesi ini', $masalah[0]);

        // Sesi membaik tanpa baseline baru: merah dengan perintah mengunci.
        $naik = Gerbang::periksa(Gerbang::terburuk($this->duaSesi(7.2, 8.0)), ['micrometer'], $this->baseline(['micrometer' => [
            'master_pt' => 8.0, 'app_pt' => 7.2, 'rasio' => 0.9, 'sesi' => ['M-1' => 7.2, 'M-2' => 7.6],
        ]]), $perSesi);
        $this->assertStringContainsString('sesi M-2', implode("\n", $naik));
        $this->assertStringContainsString('NAIK di atas baseline sesi ini', implode("\n", $naik));
    }

    public function test_sesi_hilang_baru_atau_belum_dibekukan_per_sesi_gagal(): void
    {
        $baseline = $this->baselineDuaSesi();

        $hilang = ['micrometer' => [['app_pt' => 7.2, 'sesi' => 'M-1']]];
        $this->assertStringContainsString('sesi M-2 ada di baseline tetapi TIDAK terukur', implode("\n", Gerbang::periksa(Gerbang::terburuk($hilang), ['micrometer'], $baseline, $hilang)));

        $baru = ['micrometer' => [...$this->duaSesi(7.2, 8.0)['micrometer'], ['app_pt' => 8.0, 'sesi' => 'M-3']]];
        $this->assertStringContainsString('sesi M-3 terukur tetapi BELUM dibekukan', implode("\n", Gerbang::periksa(Gerbang::terburuk($baru), ['micrometer'], $baseline, $baru)));

        $tanpa = $this->baseline(['micrometer' => $this->entri(8.0, 0.9)]);
        $perSesi = $this->duaSesi(7.2, 8.0);
        $this->assertStringContainsString('BELUM dibekukan per sesi', implode("\n", Gerbang::periksa(Gerbang::terburuk($perSesi), ['micrometer'], $tanpa, $perSesi)));
    }

    public function test_tulis_membekukan_sesi_dan_menolak_sesi_yang_turun(): void
    {
        $berkas = tempnam(sys_get_temp_dir(), 'huruf');

        try {
            $baseline = $this->baselineDuaSesi();

            $turun = $this->duaSesi(7.2, 7.6);
            file_put_contents($berkas, 'asli');
            $tolak = Gerbang::tulis(Gerbang::terburuk($turun), ['micrometer'], $baseline, $berkas, $turun);
            $this->assertStringContainsString('sesi M-2 TURUN dari baseline sesinya', implode("\n", $tolak));
            $this->assertSame('asli', file_get_contents($berkas));

            $hilang = ['micrometer' => [['app_pt' => 7.2, 'sesi' => 'M-1']]];
            $tolak = Gerbang::tulis(Gerbang::terburuk($hilang), ['micrometer'], $baseline, $berkas, $hilang);
            $this->assertStringContainsString('sesi M-2 ada di baseline tetapi tidak terukur', implode("\n", $tolak));

            $naik = $this->duaSesi(7.6, 8.4);
            $this->assertSame([], Gerbang::tulis(Gerbang::terburuk($naik), ['micrometer'], $baseline, $berkas, $naik));
            $tertulis = json_decode((string) file_get_contents($berkas), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['M-1' => 7.6, 'M-2' => 8.4], $tertulis['profil']['micrometer']['sesi']);
            $this->assertSame(7.6, $tertulis['profil']['micrometer']['app_pt']);
        } finally {
            @unlink($berkas);
        }
    }

    // ---------------------------------------------------------------- master_pt terikat

    public function test_master_pt_baseline_wajib_sama_dengan_fixture_ekstraksi_master(): void
    {
        $fixture = Gerbang::masterPt();
        $baseline = Gerbang::baseline();

        $this->assertSame([], Gerbang::periksaMasterPt($baseline, $fixture));

        // Mutan Jangka Sorong: huruf yang sungguh mengecil disembunyikan dengan menyunting
        // master_pt, app_pt, dan rasio bersama di baseline (konsisten satu sama lain).
        $baseline['profil']['jangka_sorong'] = [...$baseline['profil']['jangka_sorong'], 'master_pt' => 5.7, 'app_pt' => 5.4, 'rasio' => 0.95];
        $masalah = Gerbang::periksaMasterPt($baseline, $fixture);

        $this->assertCount(1, $masalah);
        $this->assertStringContainsString('jangka_sorong: master_pt baseline 5.7 berbeda dari master-pt.json', $masalah[0]);
    }

    public function test_fixture_master_pt_wajib_sama_dengan_median_dibagi_lebar_dan_profilnya_lengkap(): void
    {
        $fixture = Gerbang::masterPt();
        $baseline = Gerbang::baseline();

        // Angka master_pt di fixture disunting tanpa menyentuh median/lebar ekstraksinya.
        $diubah = $fixture;
        $diubah['profil']['jangka_sorong']['master_pt'] = 5.7;
        $baseline['profil']['jangka_sorong']['master_pt'] = 5.7;
        $this->assertStringContainsString('tidak sama dengan median', implode("\n", Gerbang::periksaMasterPt($baseline, $diubah)));

        // Profil dikeluarkan dari fixture, atau fixture memuat profil yang tak ada di baseline.
        $tanpa = $fixture;
        unset($tanpa['profil']['sieve']);
        $this->assertStringContainsString('sieve: master_pt baseline tidak punya sumber', implode("\n", Gerbang::periksaMasterPt(Gerbang::baseline(), $tanpa)));

        $lebih = $fixture;
        $lebih['profil']['alat_baru'] = ['master' => 'x', 'median_pdf_pt' => 10.0, 'lebar_halaman_pt' => 595.3, 'master_pt' => 10.0];
        $this->assertStringContainsString('alat_baru: ada di master-pt.json tetapi tidak di baseline', implode("\n", Gerbang::periksaMasterPt(Gerbang::baseline(), $lebih)));
    }

    public function test_fixture_master_pt_dipaku_hash_sha256_sehingga_mengubahnya_keputusan_sadar(): void
    {
        $isi = str_replace("\r\n", "\n", (string) file_get_contents(base_path(Gerbang::BERKAS_MASTER_PT)));

        $this->assertSame(
            self::HASH_MASTER_PT,
            hash('sha256', $isi),
            'master-pt.json berubah. Itu TOLOK UKUR gerbang huruf: menyuntingnya berarti mengubah arti "sepadan master". '
            .'Kalau ekstraksi masternya memang diulang, perbarui HASH_MASTER_PT di tes ini dan sebut master mana yang diekstraksi ulang di deskripsi PR.',
        );
    }

    // ---------------------------------------------------------------- berkas baseline asli

    public function test_baseline_memuat_semua_profil_terdaftar_dan_selaras_dengan_font_median_pt(): void
    {
        $baseline = Gerbang::baseline();
        $terdaftar = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $terdaftar[$profil->kode()] = true;
        }

        $terdaftar = array_keys($terdaftar);
        sort($terdaftar);
        $dicatat = array_keys($baseline['profil']);
        sort($dicatat);

        $this->assertSame($terdaftar, $dicatat, 'Profil terdaftar dan baseline huruf harus sama persis.');
        $this->assertSame([], Gerbang::selarasDenganFontMedian($baseline));
    }

    public function test_sieve_memakai_ukuran_master_dan_tidak_kembali_ke_tangga_kasar(): void
    {
        // Regresi 13 Nov 2026: tanpa entri ini Sieve jatuh ke 7,1 pt (0,83 x master 8,52 pt).
        $this->assertArrayHasKey('sieve', FormatMasterSertifikat::FONT_MEDIAN_PT);
        $this->assertEqualsWithDelta(8.52, FormatMasterSertifikat::fontMasterPt('sieve'), 0.01);
    }

    public function test_entri_baseline_utuh_dan_dalam_ambang_kecuali_pengecualian_beralasan(): void
    {
        $baseline = Gerbang::baseline();
        $pengecualian = array_keys($baseline['pengecualian']);

        $this->assertSame(['thermohygro'], $pengecualian, 'Pengecualian baru harus disengaja dan dibahas di PR.');

        foreach ($baseline['profil'] as $kode => $entri) {
            $this->assertGreaterThan(0, $entri['master_pt'], $kode);
            $this->assertIsNumeric($entri['app_pt'], "{$kode}: belum dibekukan.");
            $this->assertIsNumeric($entri['rasio'], "{$kode}: belum dibekukan.");

            // `rasio` yang diedit tangan tanpa `app_pt` (atau sebaliknya) ketahuan di sini.
            $this->assertSame(
                Gerbang::rasio((float) $entri['app_pt'], (float) $entri['master_pt']),
                (float) $entri['rasio'],
                "{$kode}: rasio tidak sama dengan app_pt / master_pt — baseline disunting tangan?",
            );

            // `app_pt` profil = sesi TERBURUKnya; semua sesi terkunci.
            $this->assertIsArray($entri['sesi'] ?? null, "{$kode}: sesi belum dibekukan per sesi.");
            $this->assertNotEmpty($entri['sesi'], $kode);
            $this->assertSame((float) $entri['app_pt'], (float) min($entri['sesi']), "{$kode}: app_pt harus sama dengan sesi terburuknya.");

            if (! in_array($kode, $pengecualian, true)) {
                $this->assertGreaterThanOrEqual(Gerbang::AMBANG_BAWAH, $entri['rasio'], "{$kode}: baseline di bawah ambang.");
                $this->assertLessThanOrEqual(Gerbang::AMBANG_ATAS, $entri['rasio'], "{$kode}: baseline di atas ambang.");
            }
        }
    }
}
