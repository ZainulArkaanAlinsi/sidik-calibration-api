<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\DataTampilanSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Sertifikat cuma boleh membawa klaim "Terakreditasi … No. LK-285-IDN" untuk
 * jenis alat yang BENAR-BENAR ada di lampiran akreditasi.
 *
 * ## Kenapa test ini ada
 *
 * Sampai 7 Sep 2026 klaim itu dicetak **tanpa syarat** di tingkat organisasi:
 * `CertificateSnapshotBuilder` membekukan `organization.no_akreditasi` ke SETIAP
 * snapshot tanpa satu pun pemeriksaan alat, dan blade mencetaknya. Akibatnya
 * Gas Detector — di luar lampiran sejak alat ke-10 — terbit dengan klaim yang
 * sama persis dengan alat terakreditasi, tanpa satu pun error.
 *
 * Mencetak klaim akreditasi untuk lingkup yang tidak diakreditasi adalah temuan
 * audit KAN. Bentuk kegagalannya juga yang paling sunyi yang ada di repo ini:
 * yang keliru bukan angkanya, bukan tata letaknya, melainkan **satu kalimat di
 * kop** yang justru terlihat benar.
 *
 * ## Yang dijaga di sini, dan kenapa semuanya perlu
 *
 * 1. **Daftarnya tidak menyimpang.** `dalamLingkupAkreditasi()` dan
 *    `CmcSemuaProfilTest::DILUAR_LAMPIRAN` menjawab pertanyaan yang sama dari
 *    dua tempat. Dua daftar yang tidak diadu selalu berakhir berbeda.
 * 2. **Snapshot-nya bersih.** Itu yang dibaca cetak ulang tahun depan.
 * 3. **HTML-nya bersih.** Snapshot yang benar tetap bisa mencetak klaim kalau
 *    blade-nya memasang bawaan sendiri — versi lama melakukannya persis begitu
 *    (`?? 'KAN'`, `?? '—'`).
 */
class KlaimAkreditasiIkutLingkupTest extends TestCase
{
    use RefreshDatabase;

    /** Sesi contoh Height Gauge — di LUAR lampiran. */
    private const SESI_LUAR = '001-UBLK-05.26';

    /** Sesi contoh Micrometer — DI DALAM lampiran (no. 34). */
    private const SESI_DALAM = '0106-CAL-1023';

    /**
     * Nama alat yang tercetak di lampiran akreditasi.
     *
     * @return list<string>
     */
    private function namaDiLampiran(): array
    {
        $data = json_decode(
            (string) file_get_contents(base_path('database/data/kemampuan-kalibrasi.json')),
            true,
        );

        $nama = [];

        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            foreach ($kelompok['alat'] as $alat) {
                $nama[] = $alat['nama_alat'];
            }
        }

        return $nama;
    }

    /**
     * Tiap profil yang mengaku DI LUAR lingkup memang tidak ada di lampiran.
     *
     * Arahnya cuma satu, dan itu disengaja. Kebalikannya — "yang mengaku di
     * dalam harus ada di lampiran" — TIDAK bisa diuji lewat pencocokan nama:
     * lampiran menulis ejaan Indonesia `Spektrofotometer` sementara profilnya
     * `Spectrophotometer`, jadi arah itu bakal merah untuk alat yang sah
     * terakreditasi. Yang menjaga arah itu `CmcSemuaProfilTest`, yang memang
     * memelihara daftar pengecualian ejaannya sendiri.
     *
     * Arah yang diuji di sini yang berbahaya: profil yang mengaku di luar
     * lingkup padahal sebenarnya terakreditasi berarti pelanggan kehilangan
     * klaim yang sudah dibayar.
     */
    public function test_profil_yang_mengaku_di_luar_lingkup_memang_tidak_ada_di_lampiran(): void
    {
        $lampiran = $this->namaDiLampiran();
        $diLuar = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            if (! $profil->dalamLingkupAkreditasi()) {
                $diLuar[$profil->kode()] = $profil->namaAlatKemampuan();
            }
        }

        $this->assertNotSame([], $diLuar, 'Daftarnya kosong — berarti hook-nya tidak dipakai siapa pun.');

        foreach ($diLuar as $kode => $nama) {
            $this->assertNotContains(
                $nama,
                $lampiran,
                "Profil `{$kode}` mengaku DI LUAR lingkup akreditasi, tapi `{$nama}` ADA di "
                .'lampiran `database/data/kemampuan-kalibrasi.json`. Kalau alatnya memang sudah '
                .'diakreditasi, cabut `dalamLingkupAkreditasi()` dari profilnya — kalau tidak, '
                .'pelanggan kehilangan klaim akreditasi yang jadi haknya.',
            );
        }
    }

    /**
     * Daftar profil di luar lingkup SAMA dengan `CmcSemuaProfilTest::DILUAR_LAMPIRAN`.
     *
     * Dua daftar yang menjawab pertanyaan yang sama dari dua tempat selalu
     * berakhir berbeda kalau tidak pernah diadu. Yang menyimpang di sini tidak
     * menerbitkan error — dia cuma membuat satu alat baru diam-diam membawa
     * klaim akreditasi, atau kehilangannya.
     *
     * `Spectrophotometer` DIKECUALIKAN dari perbandingan: dia ada di
     * `DILUAR_LAMPIRAN` karena EJAANNYA berbeda (lampiran menulis
     * "Spektrofotometer"), bukan karena tidak diakreditasi. Alatnya sah
     * terakreditasi, jadi klaimnya harus tetap tercetak.
     */
    public function test_daftar_di_luar_lingkup_sama_dengan_daftar_cmc(): void
    {
        $cerminan = new ReflectionClass(CmcSemuaProfilTest::class);
        $diluarLampiran = array_keys($cerminan->getConstant('DILUAR_LAMPIRAN'));

        // Cuma soal ejaan, bukan soal lingkup — lihat docblock.
        $diluarLampiran = array_values(array_diff($diluarLampiran, ['Spectrophotometer']));

        $diLuarLingkup = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            if (! $profil->dalamLingkupAkreditasi()) {
                $diLuarLingkup[] = $profil->namaAlatKemampuan();
            }
        }

        sort($diluarLampiran);
        sort($diLuarLingkup);

        $this->assertSame(
            $diluarLampiran,
            $diLuarLingkup,
            "Dua daftar yang menjawab pertanyaan yang sama sudah menyimpang.\n"
            .'`CmcSemuaProfilTest::DILUAR_LAMPIRAN` (di luar `Spectrophotometer`, yang cuma beda '
            ."ejaan) harus sama dengan profil yang `dalamLingkupAkreditasi()`-nya false.\n\n"
            .'Nama yang cuma ada di satu sisi = alat itu diperlakukan berbeda oleh dua jalur, dan '
            .'yang muncul bukan error melainkan sertifikat yang klaimnya salah.',
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(string $nomorSesi): array
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail()->snapshot;
    }

    public function test_sesi_di_luar_lingkup_snapshotnya_tanpa_nomor_akreditasi(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $luar = $this->snapshot(self::SESI_LUAR)['meta']['organization'];
        $dalam = $this->snapshot(self::SESI_DALAM)['meta']['organization'];

        $this->assertNull($luar['no_akreditasi'], 'Height Gauge di luar lampiran.');
        $this->assertFalse($luar['dalam_lingkup_akreditasi']);

        // Sisi PEMBANDINGnya ikut diuji — tanpa dia, mencabut klaim dari SEMUA
        // alat juga bikin test ini hijau.
        $this->assertSame(
            'LK-285-IDN',
            $dalam['no_akreditasi'],
            'Micrometer ADA di lampiran (no. 34) — klaimnya wajib tetap terbit.',
        );
        $this->assertTrue($dalam['dalam_lingkup_akreditasi']);
    }

    /**
     * Yang TERCETAK, bukan cuma yang tersimpan.
     *
     * Snapshot yang benar tetap bisa melahirkan HTML yang salah: versi lama
     * blade memasang bawaannya sendiri (`?? 'KAN'`, `?? '—'`), jadi nomor
     * akreditasi yang null tetap mencetak "Terakreditasi KAN · No. —" — klaim
     * yang sama, cuma tanpa nomornya.
     *
     * Dirender lewat `DataTampilanSertifikat` yang sama dengan produksi, bukan
     * dengan data view karangan: penyetop kop banner hidup DI SITU, dan
     * `kop-surat.png` memuat `LK-285-IDN` di dalam gambarnya. Merender dengan
     * `'kop' => null` yang ditulis tangan bakal hijau tanpa membuktikan
     * penyetopnya jalan.
     */
    public function test_html_sesi_di_luar_lingkup_tidak_menyebut_akreditasi(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $this->snapshot(self::SESI_LUAR);

        $sertifikat = CalibrationSession::where('nomor_sesi', self::SESI_LUAR)
            ->firstOrFail()
            ->certificate()
            ->firstOrFail();

        $data = app(DataTampilanSertifikat::class)->untuk($sertifikat);

        // Kop banner KAN (`kop-surat.png`) memuat LK-285-IDN DI DALAM gambarnya,
        // jadi dia wajib diganti. Sejak 15 Sep 2026 penggantinya varian kop yang
        // sama dengan blok KAN dihapus — bukan kop teks (keluhan pemilik proyek
        // atas `CAL/2026/09/0007`).
        $nonKan = public_path('images/kop-surat-non-kan.png');
        $kan = public_path('images/kop-surat.png');

        $this->assertNotNull($data['kop'], 'Sesi di luar lingkup tetap berkop — varian tanpa KAN.');
        $this->assertStringEndsWith(base64_encode((string) file_get_contents($nonKan)), $data['kop']);
        $this->assertStringNotContainsString(
            base64_encode((string) file_get_contents($kan)),
            $data['kop'],
            'Kop banner KAN wajib DISETOP di luar lingkup.',
        );

        $html = view('sertifikat.pdf', $data)->render();

        $this->assertStringNotContainsString('LK-285-IDN', $html);
        $this->assertStringNotContainsString('Terakreditasi', $html);

        // Identitas penerbitnya TETAP tercetak — dia bukan klaim akreditasi,
        // dan sertifikat tanpa nama lab tidak berguna buat siapa pun. Nama lab
        // ada DI DALAM gambar kop non-KAN, jadi yang dibuktikan kopnya terpasang.
        $this->assertStringContainsString($data['kop'], $html);
    }

    /**
     * Sisi pembandingnya: alat DI DALAM lingkup tetap mencetak klaimnya.
     *
     * Diperiksa DUA jalur kop, karena keduanya membawa klaim dengan cara yang
     * berbeda:
     *
     *  - **Kop banner** (`kop-surat.png`) — nomornya tercetak DI DALAM
     *    gambarnya, jadi yang dibuktikan cukup banner-nya masih terpasang.
     *    Mencari string `LK-285-IDN` di HTML-nya bakal merah walau tidak ada
     *    yang rusak.
     *  - **Kop teks** (jalur cadangan buat organisasi tanpa berkas kop) —
     *    di situ klaimnya memang teks, dan di situlah bawaan berbahaya
     *    `?? 'KAN'` dulu tinggal.
     */
    public function test_html_sesi_di_dalam_lingkup_tetap_menyebut_akreditasi(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $this->snapshot(self::SESI_DALAM);

        $sertifikat = CalibrationSession::where('nomor_sesi', self::SESI_DALAM)
            ->firstOrFail()
            ->certificate()
            ->firstOrFail();

        $data = app(DataTampilanSertifikat::class)->untuk($sertifikat);

        $this->assertNotNull(
            $data['kop'],
            'Micrometer ADA di lampiran — kop banner-nya (yang memuat LK-285-IDN di dalam '
            .'gambarnya) tidak boleh ikut disetop.',
        );

        // Jalur kop TEKS: banner dimatikan supaya cabang yang klaimnya berupa
        // teks yang dirender. Di situ bawaan `?? 'KAN'` dulu tinggal.
        $html = view('sertifikat.pdf', ['kop' => null] + $data)->render();

        $this->assertStringContainsString(
            'LK-285-IDN',
            $html,
            'Kop teks berhenti mencetak klaim untuk alat yang HAKNYA ada — pelanggan kehilangan '
            .'bukti keterlusuran yang sudah dibayar.',
        );
        $this->assertStringContainsString('Terakreditasi', $html);
    }
}
