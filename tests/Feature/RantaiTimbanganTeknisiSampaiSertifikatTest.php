<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RANTAI PENUH Timbangan: HP teknisi -> admin -> sertifikat di tangan pelanggan.
 *
 * ## Kenapa ada, padahal tiap potongannya sudah ditest
 *
 * `TimbanganMasterTest` membuktikan angkanya, `TimbanganSesiTest` membuktikan
 * payload HP tersimpan, `TimbanganSertifikatTest` membuktikan sertifikatnya
 * kecetak — tiga berkas yang masing-masing memasang panggungnya sendiri
 * (seeder, sesi buatan, sertifikat buatan). Tidak satu pun menjalankan
 * SAMBUNGANNYA dari ujung ke ujung.
 *
 * Yang bocor di sambungan itu justru yang paling mahal, dan sudah kejadian di
 * repo ini: sesi tersimpan rapi tapi tidak bisa disetujui, atau disetujui tapi
 * PDF-nya tidak pernah jadi. Dua-duanya lolos test per-potongan.
 *
 * Jadi berkas ini melangkah persis seperti orangnya:
 *
 *   1. teknisi minta bentuk lembar kerja ke server (bukan bentuk yang diketik
 *      ulang di test — kalau servernya berubah, langkah ini ikut berubah);
 *   2. teknisi mengirim satu sesi UTUH dalam bentuk yang beneran dikirim HP
 *      (deret `pembacaan` menurut posisi kolom, keterulangan sebagai cerminan
 *      tabel `baris[]`, lima blok tingkat-sesi lengkap);
 *   3. admin membuka sesi itu dan menyetujuinya;
 *   4. sertifikatnya terbit, dan PDF, Excel, serta halaman QR-nya beneran bisa
 *      dibuka — dengan kedelapan bagian masternya, bukan tabel empat kolom.
 */
class RantaiTimbanganTeknisiSampaiSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'TB-100')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
            User::where('role', User::ROLE_ADMIN)->firstOrFail(),
            Standard::where('nama', 'Anak Timbangan F1')->firstOrFail(),
        ];
    }

    /**
     * Payload satu sesi UTUH dalam bentuk kiriman HP.
     *
     * Sepuluh titik akurasi 10..100 kg, plus kelima blok tingkat-sesi yang
     * lembar ini punya. Sengaja lengkap: sesi setengah isi tetap tersimpan dan
     * tetap bisa disetujui, jadi kalau di sini cuma diisi sebagian, bagian
     * sertifikat yang bergantung pada blok yang kosong tidak akan pernah
     * teruji.
     */
    private function payloadHp(Equipment $alat, Standard $standar): array
    {
        // Susunan kepingnya MENGIKAT, bukan angka bulat yang enak dibaca:
        // tabel anak timbangan lab (`STANDAR_AT`, kelas F1) memuat 10 · 20 · 40
        // · 60 · 80 · 100 kg — 30/50/70/90 TIDAK ADA sebagai keping tunggal dan
        // memang ditumpuk di lapangan. Menyodorkan nominal yang tidak ada di
        // tabel bikin titiknya DIBLOKIR (bukan dihitung dengan massa nol), dan
        // itu perilaku yang benar — lihat `TimbanganCalculator::titikAkurasi`.
        $susunan = [
            10 => [10.0],
            20 => [20.0],
            30 => [20.0, 10.0],
            40 => [40.0],
            50 => [40.0, 10.0],
            60 => [60.0],
            70 => [60.0, 10.0],
            80 => [80.0],
            90 => [80.0, 10.0],
            100 => [100.0],
        ];

        $titik = [];

        foreach ($susunan as $beban => $keping) {
            $baca = $beban + 0.02;

            $titik[] = [
                'titik_ukur' => (float) $beban,
                'nominal' => $keping,
                // Deret menurut POSISI kolom: z, m, m', z'.
                'pembacaan' => [0, $baca, $baca, 0],
            ];
        }

        return [
            'equipment_id' => $alat->id,
            // Anak timbangan yang dipakai. Tanpa ini hitung ulang dilewati dan
            // admin disambut sepuluh peringatan `standar_titik_hilang` — dan
            // peringatan yang selalu muncul melatih orang menekan "setujui
            // tetap" tanpa membaca.
            'standard_id' => $standar->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toDateString(),
            'suhu_awal' => 24.6, 'suhu_akhir' => 24.9,
            'kelembaban_awal' => 54, 'kelembaban_akhir' => 55,
            'measurements' => $titik,
            'spesifikasi_alat' => [
                'rentang_ukur' => '100', 'kapasitas' => '100', 'resolusi' => '0.02',
                'varian_master' => 'kg', 'tipe_display' => 'Digital',
                'tipe_timbangan' => 'Non-Analytical', 'satuan' => 'kg',
                // Cerminan TABEL, bukan kosakata master `{mid, maks}` — itu
                // yang beneran dikirim layar HP.
                'keterulangan' => ['baris' => [
                    ['titik_ukur' => 50, 'zero' => array_fill(0, 10, 0), 'pembacaan' => array_fill(0, 10, 50.02)],
                    ['titik_ukur' => 100, 'zero' => array_fill(0, 10, 0), 'pembacaan' => array_fill(0, 10, 100.04)],
                ]],
                'eksentrisitas' => ['beban' => 20, 'baca' => [
                    'center' => 20, 'front' => 20, 'back' => 19.98, 'left' => 20, 'right' => 20,
                ]],
                'histeresis' => [
                    'm' => 20, 'm_aksen' => 20,
                    'baca1' => [20, 40, 20, 0, 40, 20, 0.02, 20],
                    'baca2' => [20, 40, 20, 0.02, 40, 20, 0, 20],
                ],
                'scale_observation' => [
                    'sebelum_adjustment' => ['standar' => 20, 'z1' => 0, 'm1' => 20.02, 'm2' => 20.02, 'z2' => 0],
                    'sesudah_adjustment' => ['standar' => 20, 'z1' => 0, 'm1' => 20, 'm2' => 20, 'z2' => 0],
                    'sd_tahun_lalu' => 0.0063,
                ],
                'effect_of_tare' => [
                    'standar' => 20, 'm1' => 20.02, 'm2' => 20,
                    'bentuk_pan' => 'kotak', 'ukuran_pan' => '30 x 30 cm',
                ],
            ],
        ];
    }

    /**
     * Satu jalan lurus dari HP teknisi sampai lembar yang dipegang pelanggan.
     *
     * Sengaja SATU test, bukan enam: yang diuji rantainya, dan rantai yang
     * potongannya dijalankan terpisah tidak membuktikan sambungannya.
     */
    public function test_dari_hp_teknisi_sampai_sertifikat_bisa_diunduh(): void
    {
        [$alat, $teknisi, $admin, $standar] = $this->siapkan();

        // --- 1. Teknisi buka lembar kerjanya dari server ------------------
        $bentuk = $this->actingAs($teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$alat->id)
            ->assertOk()
            ->json('data');

        $this->assertSame('timbangan', $bentuk['profil']);

        $judul = array_column($bentuk['bagian'], 'judul');

        foreach (['1. SCALE OBSERVATION', '2. EFFECT OF TARE', '3. ACCURACY'] as $blok) {
            $this->assertContains($blok, $judul, "Blok `{$blok}` nggak ada di lembar yang diterima HP.");
        }

        // --- 2. Teknisi kirim sesinya -------------------------------------
        $sesiId = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payloadHp($alat, $standar))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($sesiId);

        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);
        $this->assertCount(10, $sesi->uncertaintyCalculations, 'Ada titik yang nggak kehitung.');

        // Blok tingkat-sesi mendarat dalam bentuk BAKU, bukan cerminan tabel —
        // jalur hitung ulang membaca kunci `mid`/`maks` apa adanya.
        $this->assertArrayHasKey('mid', $sesi->spesifikasi_alat['keterulangan']);
        $this->assertArrayNotHasKey('baris', $sesi->spesifikasi_alat['keterulangan']);

        // --- 3. Admin buka lalu setujui -----------------------------------
        $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesiId}")
            ->assertOk()
            ->assertJsonPath('data.id', $sesiId);

        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesiId}/approve")
            ->assertOk();

        $sertifikat = Certificate::where('calibration_session_id', $sesiId)->firstOrFail();

        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);
        $this->assertTrue(Storage::disk('arsip')->exists($sertifikat->pdf_path));

        // --- 4. Sertifikatnya beneran bisa dibuka -------------------------
        $this->actingAs($admin)
            ->get("/api/certificates/{$sertifikat->id}/download")
            ->assertOk();

        // Tanpa login — persis pelanggan yang nyecan QR di lembarnya.
        $this->get("/verify/{$sertifikat->qr_token}/download?format=pdf")->assertOk();
        $this->get("/verify/{$sertifikat->qr_token}/download?format=xlsx")->assertOk();

        $halaman = $this->get("/verify/{$sertifikat->qr_token}")->assertOk()->getContent();

        // Kedelapan bagian master, bukan tabel `Standard | UUT | Correction`.
        foreach ([
            '1. REPEATABILITY',
            '2. EFFECT OF TARE',
            '3. ACCURACY',
            '4. LOADING INFLUENCE ON SEVERAL POSITION',
            '5. HYSTERISIS',
            '6. LIMIT OF PERFORMANCE',
            '7. WEIGHING UNCERTAINTY',
            'STANDARD USED',
        ] as $bagian) {
            $this->assertStringContainsString($bagian, $halaman, "Bagian `{$bagian}` nggak kecetak.");
        }

        $this->assertStringNotContainsString('Unit Under Test', $halaman);

        // Nomor IK lab, bukan rujukan pustaka.
        $this->assertStringContainsString('SIDIK-IK-CAL-0505_Rev.7', $halaman);

        // §2 = |m1 − m2| = |20,02 − 20| = 0,02 — blok yang diisi teknisi tadi
        // beneran nyampe ke lembarnya, bukan terbit sebagai tanda pisah.
        $this->assertEqualsWithDelta(
            0.02,
            $sertifikat->snapshot['timbangan']['effect_of_tare'],
            5e-6,
        );
    }

    /**
     * Sesi yang DITOLAK admin nggak menerbitkan sertifikat.
     *
     * Sisi lain dari rantai yang sama, dan yang paling gampang bocor: kalau
     * penolakan diam-diam tetap menerbitkan, lembar buat alat yang datanya
     * dianggap salah ikut sampai ke pelanggan.
     */
    public function test_sesi_yang_ditolak_nggak_menerbitkan_sertifikat(): void
    {
        [$alat, $teknisi, $admin, $standar] = $this->siapkan();

        $sesiId = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payloadHp($alat, $standar))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesiId}/reject", [
                'catatan_revisi' => 'Beban eksentrisitas belum dicatat.',
            ])
            ->assertOk();

        $this->assertFalse(
            Certificate::where('calibration_session_id', $sesiId)->exists(),
            'Sesi yang ditolak tetap menerbitkan sertifikat.',
        );
    }
}
