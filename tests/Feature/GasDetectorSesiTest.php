<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\GasDetectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesi Gas Detector **001-CAL-226** dari ujung ke ujung: seeder → hitung GUM →
 * snapshot sertifikat, diadu ke `Gas Detector Uli Skin (std Rigaz).xlsm`.
 *
 * Beda dari `GasDetectorBudgetTest` (unit, cuma agregasi budget): yang diuji di
 * sini rantai lengkapnya lewat database — termasuk hal-hal yang cuma muncul
 * kalau datanya beneran disimpan dan dibaca balik: satuan per baris, `koreksi`
 * yang arahnya kebalik dari `error`, dan `keputusan` yang harus NULL.
 *
 * Jalankan juga di MySQL (`php artisan test -c phpunit.mysql.xml`): kolom
 * `decimal(20,8)` dibaca float oleh SQLite tapi string oleh MySQL, dan
 * keempat angka U95 di bawah lewat kolom itu.
 */
class GasDetectorSesiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Angka master, sheet `SERTIFIKAT` C24:W27 & `PERHITUNGAN U95%`.
     *
     * @var list<array{gas: string, satuan: string, standard: float, uut: float, koreksi: float, u95: float, k: float}>
     */
    private const MASTER = [
        ['gas' => 'CO', 'satuan' => 'ppm', 'standard' => 101.0,
            'uut' => 100.33333333333333, 'koreksi' => 0.6666666666666714,
            'u95' => 5.054017734585925, 'k' => 1.9715466694452266],
        ['gas' => 'H2S', 'satuan' => 'ppm', 'standard' => 25.0,
            'uut' => 23.666666666666668, 'koreksi' => 1.3333333333333321,
            'u95' => 1.538040117315391, 'k' => 2.0106347576242314],
        ['gas' => 'CH4', 'satuan' => '%LEL', 'standard' => 50.0,
            'uut' => 49.666666666666664, 'koreksi' => 0.3333333333333357,
            'u95' => 2.617253866363179, 'k' => 1.9743577636580343],
        ['gas' => 'O2', 'satuan' => '%', 'standard' => 17.9,
            'uut' => 16.733333333333334, 'koreksi' => 1.1666666666666643,
            'u95' => 0.886710701052061, 'k' => 1.9717188484634527],
    ];

    private function sesi(): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', '2602.03.A')
            ->with(['uncertaintyCalculations', 'rawMeasurements'])
            ->firstOrFail();
    }

    /**
     * Keempat baris hasil diadu ke sertifikat master — nilai standar, rata-rata
     * UUT, koreksi, U95, dan `k` masing-masing.
     *
     * Toleransinya 1e-7, bukan 1e-12: kolom `uncertainty_calculations` presisi
     * 8 desimal, jadi yang dibaca balik dari database memang sudah dibulatkan
     * ke situ. Presisi penuhnya diuji di `GasDetectorBudgetTest`.
     */
    public function test_empat_gas_reproduksi_sertifikat_master(): void
    {
        $titik = $this->sesi()->uncertaintyCalculations->sortBy('titik_ke')->values();

        $this->assertCount(4, $titik);

        foreach (self::MASTER as $i => $m) {
            /** @var UncertaintyCalculation $t */
            $t = $titik[$i];

            $this->assertEqualsWithDelta($m['standard'], (float) $t->titik_ukur, 1e-7, "standard {$m['gas']}");
            $this->assertEqualsWithDelta($m['uut'], (float) $t->rata_rata, 1e-7, "uut {$m['gas']}");
            $this->assertEqualsWithDelta($m['koreksi'], (float) $t->koreksi, 1e-7, "koreksi {$m['gas']}");
            $this->assertEqualsWithDelta($m['u95'], (float) $t->ketidakpastian_diperluas, 1e-7, "U95 {$m['gas']}");
            $this->assertEqualsWithDelta($m['k'], (float) $t->faktor_cakupan_k, 1e-7, "k {$m['gas']}");
        }
    }

    /**
     * `koreksi` = kebalikan `error`, dan tandanya IKUT master.
     *
     * Master menulis `R24 = J24-N24` (Standard − UUT) — positif 0,667 untuk CO
     * yang membaca 100,33 padahal standarnya 101. Alat membaca RENDAH, jadi
     * angkanya perlu DITAMBAH: koreksi positif. Kalau suatu saat tandanya
     * kebalik, seluruh sertifikat gas detector menyuruh teknisi mengoreksi ke
     * arah yang salah.
     */
    public function test_koreksi_positif_saat_alat_membaca_rendah(): void
    {
        foreach ($this->sesi()->uncertaintyCalculations as $t) {
            $this->assertEqualsWithDelta(-(float) $t->error, (float) $t->koreksi, 1e-9);
            $this->assertGreaterThan(0, (float) $t->koreksi, "titik ke-{$t->titik_ke} mestinya koreksi positif");
        }
    }

    /**
     * Tiap baris membawa satuannya sendiri — empat gas, tiga satuan berbeda.
     *
     * Ini yang paling gampang rusak diam-diam: kalau `satuanTitik()` jatuh ke
     * satuan alat (`ppm`), sertifikat mencetak kadar oksigen "16,7 ppm" alih-alih
     * "16,7 %". Angkanya benar, artinya beda sepuluh ribu kali.
     */
    public function test_tiap_baris_bawa_satuannya_sendiri(): void
    {
        $sesi = $this->sesi();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        foreach (self::MASTER as $i => $m) {
            $titikUkur = (float) $sesi->uncertaintyCalculations->sortBy('titik_ke')->values()[$i]->titik_ukur;

            $this->assertSame($m['satuan'], $profil->satuanTitik($titikUkur, $sesi->equipment), $m['gas']);
        }

        // Baris pembacaan mentah juga — dari situ lembar perhitungan ambil
        // satuannya.
        $satuanMentah = $sesi->rawMeasurements->sortBy('titik_ke')->pluck('satuan')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['ppm', '%LEL', '%'], $satuanMentah);
    }

    /**
     * Snapshot sertifikat membawa satuan, remark, dan U95 per baris.
     *
     * Snapshot itu yang dibekukan waktu sertifikat terbit — kalau kolomnya
     * kosong di sini, dokumen yang sudah dipegang pelanggan yang kosong, dan
     * itu tidak bisa dibetulkan tanpa menerbitkan ulang.
     */
    public function test_snapshot_sertifikat_bawa_satuan_dan_remark_per_baris(): void
    {
        $sesi = $this->sesi();
        $snapshot = app(CertificateSnapshotBuilder::class)->bangun(
            $sesi,
            new Certificate(['nomor' => 'UJI', 'qr_token' => 'uji']),
        );

        $baris = collect($snapshot['hasil'] ?? [])->sortBy('titik_ke')->values();

        $this->assertCount(4, $baris);

        $this->assertSame('ppm', $baris[0]['satuan']);
        $this->assertSame('%LEL', $baris[2]['satuan']);
        $this->assertSame('%', $baris[3]['satuan']);

        $this->assertSame('Carbon Monoxide (CO)', $baris[0]['remark']);
        $this->assertSame('Oxygen (O2)', $baris[3]['remark']);

        $this->assertEqualsWithDelta(0.886710701052061, (float) $baris[3]['u95'], 1e-7);
    }

    /**
     * Gas Detector tidak divonis PASS/FAIL — master tidak punya batas
     * keberterimaan dan sertifikatnya berhenti di kolom U95%.
     *
     * `null`, bukan `'FAIL'`: `equipments.toleransi` yang kosong pernah
     * ke-cast jadi 0,0 dan membuat tiap alat tanpa toleransi divonis FAIL
     * diam-diam. Dijaga di dua tempat — kolom alatnya sendiri dan hasil
     * vonisnya.
     */
    public function test_tidak_divonis_pass_atau_fail(): void
    {
        $sesi = $this->sesi();

        $this->assertNull($sesi->keputusan);
        $this->assertNull($sesi->equipment->toleransi);

        foreach ($sesi->uncertaintyCalculations as $t) {
            $this->assertNull($t->keputusan, "titik ke-{$t->titik_ke}");
        }
    }

    /**
     * Tekanan udara ikut tersimpan & terkoreksi — tanpa itu dua dari lima
     * komponen budget tidak bisa disusun.
     *
     * Nilai terkoreksi 924,15 hPa cocok master (`PERHITUNGAN!G21 + N21`), dan
     * U95-nya √(Δ² + U95_std²) = √(0,7² + 2²) = 2,1190 (`Q21`).
     */
    public function test_tekanan_udara_tersimpan_dan_terkoreksi(): void
    {
        $sesi = $this->sesi();

        $this->assertSame(923.5, $sesi->tekanan_awal);
        $this->assertSame(922.8, $sesi->tekanan_akhir);
        $this->assertEqualsWithDelta(924.15, $sesi->tekanan_udara, 1e-6);
        $this->assertEqualsWithDelta(2.118962010041724, $sesi->tekanan_ketidakpastian, 1e-4);
    }

    /**
     * Barometer yang dipakai HARUS punya kalibrasi tekanan.
     *
     * TH-1..TH-7 tidak punya, dan sesi Gas Detector yang memakai salah satunya
     * akan kehilangan baris tekanan tanpa error apa pun — cuma `tekanan_udara`
     * yang diam-diam null.
     */
    public function test_barometer_sesi_punya_kalibrasi_tekanan(): void
    {
        $sesi = $this->sesi();

        $this->assertNotNull($sesi->thermohygro);
        $this->assertSame('Thermobarometer Lutron', $sesi->thermohygro->nama);
        $this->assertNotNull($sesi->thermohygro->parameterKondisi('tekanan', 923.15));

        // Unit lain memang tidak punya — dan itu yang bikin daftar pilihan
        // "Environmental Meter Used" di lembar kerja cuma berisi Lutron.
        $th2 = Standard::where('nama', 'TH-2')->firstOrFail();
        $this->assertNull($th2->parameterKondisi('tekanan', 923.15));
    }

    /**
     * Tiap baris tabel tertaut ke BOTOL GASNYA SENDIRI.
     *
     * ## Bug yang dijaga di sini
     *
     * Keempat botol Rigas ber-S/N `WO0125576` yang SAMA — satu order
     * pengisian, empat campuran. Pencocokan titik-ke-standar dulu menerima
     * nama ATAU serial dalam satu `first()`, jadi serial yang sama itu
     * memulangkan botol yang paling depan di koleksi untuk KEEMPAT titik.
     *
     * Akibatnya rapi dan karena itu berbahaya: lembar kerjanya kelihatan
     * normal, tiap baris punya `standard_id`, tidak ada error di mana pun —
     * tapi sertifikat mencetak "Carbon Monoxide (CO) 101 Ppm" sebagai gas
     * acuan di baris oksigen, lengkap dengan lot dan ketertelusurannya.
     *
     * Dijaga di dua tempat: lembar kerja (di bawah) dan baris pengukuran
     * tersimpan, karena dari situ sertifikat mengambil ketertelusurannya.
     */
    public function test_tiap_baris_tertaut_ke_botol_gasnya_sendiri(): void
    {
        $sesi = $this->sesi();

        // Baris pengukuran tersimpan: empat titik, empat standar BERBEDA.
        $standarPerTitik = $sesi->rawMeasurements
            ->groupBy('titik_ke')
            ->map(fn ($g) => $g->pluck('standard_id')->unique()->values()->all());

        foreach ($standarPerTitik as $titikKe => $ids) {
            $this->assertCount(1, $ids, "Titik ke-{$titikKe} punya lebih dari satu standar.");
        }

        $this->assertCount(
            4,
            $standarPerTitik->flatten()->unique(),
            'Keempat titik mestinya pakai botol yang berbeda-beda.',
        );

        // Lembar kerja: `standard_id` per baris juga harus berbeda.
        $bentuk = app(CalibrationProfileRegistry::class)
            ->untukAlat($sesi->equipment)
            ->bentukLembarKerja();

        $baris = collect($bentuk['bagian'])
            ->firstWhere('kode', 'hasil')['tabel'][0]['baris'];

        $this->assertSame(
            ['Standar Gas Mixture (CO)', 'Standar Gas Mixture (H₂S)',
                'Standar Gas Mixture (CH4)', 'Standar Gas Mixture (O2)'],
            array_column($baris, 'standard_nama'),
        );
        $this->assertCount(4, collect($baris)->pluck('standard_id')->unique());
    }

    /**
     * Nilai nominal botol di seeder WAJIB sama dengan yang dipakai profil buat
     * mencocokkan titik ke gas. Kalau keduanya geser sendiri-sendiri, satuan
     * sertifikat ikut geser tanpa ada yang error.
     */
    public function test_nominal_botol_seeder_sama_dengan_profil(): void
    {
        $this->assertSame(
            array_map(fn (array $s): float => $s['nominal'], GasDetectorSeeder::standar()),
            GasDetectorSeeder::nominalProfil(),
        );
    }

    /**
     * Alat contoh terdaftar dengan `nama_alat_kemampuan` yang cocok registry —
     * tanpa itu `GumCalculator` jatuh ke profil default (pH) dan seluruh
     * budgetnya salah bentuk.
     */
    public function test_alat_terpetakan_ke_profil_gas_detector(): void
    {
        $alat = $this->sesi()->equipment;

        $this->assertSame('KA419-1086675', $alat->serial_number);
        $this->assertSame('Gas Detector', $alat->nama_alat_kemampuan);
        $this->assertSame(
            'gas_detector',
            app(CalibrationProfileRegistry::class)->untukAlat($alat)->kode(),
        );
    }
}
