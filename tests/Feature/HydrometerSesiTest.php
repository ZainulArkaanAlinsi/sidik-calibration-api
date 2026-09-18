<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CalibrationValidator;
use App\Support\HydrometerMentah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur penuh lembar **Hydrometer**: payload HP → `raw_measurements` → hitung
 * ulang, dan angkanya sama di ketiga titik itu.
 *
 * ## Kenapa test ini ada
 *
 * Pola "alat yang satu titiknya bukan satu deret datar" sudah menggigit
 * **sepuluh kali** di repo ini, dan bentuknya selalu sama dan selalu **tanpa
 * error**: jalur simpan menaruh bentuknya benar, jalur hitung ulang tidak tahu
 * cara menyusunnya balik, dan tiap titik pulang `hitung_ulang_gagal` sampai
 * admin belajar menekan "setujui tetap" tanpa membaca.
 *
 * Hydrometer bentuk kesebelas, dan yang paling mahal kalau lolos: di alat ini
 * TIDAK ADA satu pun angka di sertifikat yang pernah diketik manusia. Kolom
 * `Actual Value`-nya hasil olahan metode Cuckow, jadi tidak ada yang bisa
 * dibandingkan sekilas dengan lembar kertas waktu angkanya salah.
 *
 * Test ini ditulis bareng profilnya, bukan ditemukan belakangan.
 */
class HydrometerSesiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sesi master `Master Olah Data Hydrometer 0.600-0.650` (8 Sep 2025),
     * varian beban tambahan. Angka yang harus keluar sudah dibuktikan di
     * `tests/Unit/HydrometerMasterTest.php`.
     */
    private const DENSITAS_MASTER = [0.6039099485606535, 0.6176250479037468, 0.6446329414723625];

    /**
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $ganti = []): array
    {
        return [
            'equipment_id' => $alat->id,
            // Standar sesi — neraca analitik yang menimbang hydrometer-nya.
            // Dikirim eksplisit, bukan diturunkan: dia yang diperiksa gerbang
            // `standar_kadaluarsa`.
            'standard_id' => Standard::query()->value('id'),
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-09-04',
            'suhu_awal' => 20.4, 'suhu_akhir' => 20.5,
            'kelembaban_awal' => 56, 'kelembaban_akhir' => 55,
            // Tekanan udara — tidak tercetak di kertas Rev.2 tapi rantai
            // hitungnya butuh. Lihat docs/pertanyaan-lab-hydrometer.md §9.
            'tekanan_awal' => 933.2, 'tekanan_akhir' => 933.1,
            // Tiap titik DUA deret: tiga kali timbang dan tiga kali baca suhu.
            // HP menggabung kedua tabel per POSISI, sama seperti lembar Jangka
            // Sorong.
            'measurements' => [
                [
                    'titik_ukur' => 0.610,
                    'hydro_massa' => [21.2727, 21.2726, 21.2856],
                    'hydro_suhu' => [20.6, 20.6, 20.6],
                ],
                [
                    'titik_ukur' => 0.625,
                    'hydro_massa' => [22.7483, 22.7446, 22.7491],
                    'hydro_suhu' => [20.6, 20.6, 20.6],
                ],
                [
                    'titik_ukur' => 0.650,
                    'hydro_massa' => [25.4602, 25.4608, 25.4621],
                    'hydro_suhu' => [20.7, 20.7, 20.7],
                ],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '0.600-0.650',
                HydrometerMentah::KUNCI_SESI => [
                    'pakai_beban_tambahan' => true,
                    'beban_tambahan' => 54.0052,
                    'massa_udara' => 39.9327,
                    'tegangan_permukaan' => 17.5,
                    'satuan_tegangan' => 'mN/m',
                    'suhu_acuan_alat' => 15,
                    'suhu_acuan_faktor' => 20,
                    'diameter_stem' => [0.708, 0.710, 0.709],
                    'resolusi' => 0.0005,
                    'satuan_densitas' => 'g/ml',
                ],
            ],
            ...$ganti,
        ];
    }

    /** @return array{Equipment, User} */
    private function siapkan(): array
    {
        $org = Organization::factory()->create();

        // Kategori + DUA pita CMC lampiran akreditasi (kelompok Densitas no. 32).
        // Tanpa ini lantai CMC tidak ada sama sekali dan test di bawah cuma
        // mengadu U hitung — bukan angka yang benar-benar tercetak sertifikat.
        $kategori = EquipmentCategory::factory()->create([
            'organization_id' => $org->id,
            'kode' => 'densitas',
            'nama' => 'Densitas',
        ]);

        foreach ([[1.10, 1.70, 0.00070], [0.60, 1.00, 0.00051]] as [$min, $maks, $cmc]) {
            CalibrationCapability::factory()->create([
                'organization_id' => $org->id,
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Hydrometer',
                'range_min' => $min,
                'range_max' => $maks,
                'satuan' => 'g/mL',
                'ketidakpastian_terbaik' => $cmc,
                'satuan_ketidakpastian' => 'g/mL',
                'faktor_cakupan' => 2,
                'metode' => 'SIDIK-IK-CAL-0525 (Metode Cuckcow)',
            ]);
        }

        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Hydrometer Alla France L50',
            'nama_alat_kemampuan' => 'Hydrometer',
            'serial_number' => '350015',
            'satuan' => 'g/ml',
        ]);

        Standard::factory()->create([
            'organization_id' => $org->id,
            'nama' => 'Analytical Balance Fujitsu',
            'satuan_ketidakpastian' => 'g',
        ]);

        return [$alat, User::factory()->create([
            'organization_id' => $org->id,
            'role' => User::ROLE_TEKNISI,
            'status' => User::STATUS_AKTIF,
        ])];
    }

    /**
     * Kedua deret tersimpan sebagai baris ber-`peran_sensor` yang TERPISAH.
     *
     * Deret datar tidak bisa dipakai di alat ini: massa (21 g) dan suhu
     * (20,6 °C) ber-orde mirip, dan begitu perannya hilang tidak ada satu pun
     * yang bisa membedakannya. Rumus Cuckow yang membacanya tertukar tetap
     * memulangkan densitas ber-orde wajar.
     */
    public function test_payload_hp_tersimpan_sebagai_dua_peran(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)->where('titik_ke', 1)->get();

        $massa = $baris->where('peran_sensor', HydrometerMentah::PERAN_MASSA)
            ->sortBy('sensor_ke')->pluck('pembacaan')->map('floatval')->values()->all();
        $suhu = $baris->where('peran_sensor', HydrometerMentah::PERAN_SUHU)
            ->sortBy('sensor_ke')->pluck('pembacaan')->map('floatval')->values()->all();

        $this->assertSame([21.2727, 21.2726, 21.2856], $massa);
        $this->assertSame([20.6, 20.6, 20.6], $suhu);

        // Tiap baris menyebutkan satuannya sendiri — dan di alat ini keduanya
        // BEDA, tidak seperti sepuluh lembar dua-deret lainnya.
        $this->assertSame(
            ['g'],
            $baris->where('peran_sensor', HydrometerMentah::PERAN_MASSA)->pluck('satuan')->unique()->values()->all(),
        );
        $this->assertSame(
            ['°C'],
            $baris->where('peran_sensor', HydrometerMentah::PERAN_SUHU)->pluck('satuan')->unique()->values()->all(),
        );
    }

    /** Densitas yang tersimpan sama dengan master, lewat jalur HTTP penuh. */
    public function test_densitas_tersimpan_sama_dengan_master(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $hitungan = CalibrationSession::findOrFail($id)
            ->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(3, $hitungan);

        foreach (self::DENSITAS_MASTER as $i => $harap) {
            // `rata_rata` = kolom `Actual Value` sertifikat. Toleransinya
            // longgar di sini, bukan 1e-12: kolom database `decimal` memang
            // membulatkan, dan yang dijaga presisi penuh
            // `HydrometerMasterTest` di sisi kalkulator.
            $this->assertEqualsWithDelta(
                $harap,
                (float) $hitungan[$i]->rata_rata,
                1e-6,
                'densitas titik ke-'.($i + 1).' tidak sama dengan master',
            );
        }

        // Ketiga skala file ringan jatuh ke lantai CMC — dan lantainya
        // **0,00051**, pita 0,60-1,00 lampiran akreditasi, bukan 0,0007 yang
        // dicetak masternya (itu angka pita 1,10-1,70, pita yang alat ini tidak
        // ada di dalamnya). Lihat docs/pertanyaan-lab-hydrometer.md §7.
        foreach ($hitungan as $h) {
            $this->assertEqualsWithDelta(0.00051, (float) $h->ketidakpastian_diperluas, 1e-9);
        }
    }

    /**
     * Lantai CMC diambil dari pita lampiran yang MEMUAT titiknya — bukan baris
     * pertama yang kebetulan cocok nama alatnya.
     *
     * Ini gerbang buat kekeliruan yang dilakukan masternya sendiri.
     * `CalibrationProfile::kemampuanSesi()` memulangkan SATU baris, yang
     * pertama ketemu, dan itu benar buat tiga puluh dua alat yang pita CMC-nya
     * cuma satu. Hydrometer punya DUA (0,60-1,00 → 0,00051 dan 1,10-1,70 →
     * 0,00070), dan dipakai apa adanya, hydrometer 0,600-0,650 dilantai
     * 0,0007 — 37 % lebih besar daripada yang diakreditasi buat pita itu.
     *
     * Gejalanya nol: sertifikatnya terbit rapi, angkanya masuk akal, dan yang
     * salah cuma angka `U95%` yang justru jadi isi utama dokumennya.
     */
    public function test_lantai_cmc_dari_pita_yang_memuat_titiknya(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        foreach (CalibrationSession::findOrFail($id)->uncertaintyCalculations as $h) {
            $this->assertEqualsWithDelta(
                0.00051,
                (float) $h->ketidakpastian_diperluas,
                1e-9,
                sprintf(
                    'titik %s g/ml ada di pita 0,60-1,00 (CMC 0,00051), tapi dilantai %s',
                    $h->titik_ukur,
                    $h->ketidakpastian_diperluas,
                ),
            );
        }
    }

    /**
     * Titik di LUAR semua pita lampiran tetap terbit — U95-nya telanjang — tapi
     * sesinya kehilangan klaim akreditasi.
     *
     * Bukan kasus teoretis: hydrometer contoh rentang berat (1,800-2,000 g/mL)
     * ada di atas pita tertinggi (1,70), dan sertifikatnya sudah terbit
     * 7 Nov 2025. Menahan sesinya berarti aplikasi tidak bisa mencetak ulang
     * dokumen yang sudah ada di tangan pelanggan; preseden perlakuannya Jangka
     * Sorong (caliper 600 mm) dan Height Gauge.
     */
    public function test_titik_di_luar_pita_terbit_tanpa_klaim_akreditasi(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);

        // Digeser ke rentang berat; angkanya tidak perlu realistis, yang diuji
        // perlakuan terhadap titik di luar pita.
        foreach ([1.800, 1.900, 2.000] as $i => $titik) {
            $payload['measurements'][$i]['titik_ukur'] = $titik;
        }

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $hitungan = $sesi->uncertaintyCalculations;

        $this->assertCount(3, $hitungan, 'sesi di luar pita mestinya tetap terbit');

        foreach ($hitungan as $h) {
            $this->assertLessThan(
                0.00051,
                (float) $h->ketidakpastian_diperluas,
                'tanpa pita yang memuatnya, U95 mestinya telanjang — bukan dilantai pita mana pun',
            );
        }

        $this->assertFalse(
            app(CalibrationProfileRegistry::class)->untukAlat($alat)->dalamLingkupAkreditasiSesi($sesi),
            'sesi di luar pita lampiran tidak boleh membawa klaim akreditasi',
        );
    }

    /**
     * Jalur simpan dan jalur HITUNG ULANG memberi angka yang sama.
     *
     * Ini gerbang yang sepuluh alat sebelumnya lewati diam-diam. Kalau
     * `HydrometerMentah::dari()` tidak menyusun balik kedua deret, tiap titik
     * pulang `hitung_ulang_gagal` di SETIAP approve — dan admin belajar
     * menekan "setujui tetap" tanpa membaca.
     */
    public function test_hitung_ulang_memberi_angka_yang_sama(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $temuan = app(CalibrationValidator::class)->periksa(CalibrationSession::findOrFail($id))['temuan'];

        $kode = array_column($temuan, 'kode');

        $pesan = implode(' | ', array_column($temuan, 'pesan'));
        $this->assertNotContains('hitung_ulang_gagal', $kode, 'jalur hitung ulang tidak bisa menyusun balik lembarnya: '.$pesan);
        $this->assertNotContains('hitung_ulang_beda', $kode, 'jalur simpan & jalur hitung ulang memberi angka berbeda: '.$pesan);
    }

    /**
     * Dua tabel yang TIDAK sinkron ditolak, bukan disimpan separuh.
     *
     * Menyimpan yang terisi saja berarti sesi membawa titik yang massanya ada
     * dan suhunya tidak — dan jalur hitung ulang cuma bisa melaporkannya
     * sebagai "belum dihitung", setiap kali, selamanya.
     */
    public function test_dua_tabel_tidak_sinkron_ditolak(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        // Titik 2 kehilangan tabel suhunya — bentuk yang paling mungkin datang
        // dari lapangan: teknisi mengisi seluruh tabel massa dulu, lalu tabel
        // suhunya baru terisi sebagian waktu tombol kirim ditekan.
        unset($payload['measurements'][1]['hydro_suhu']);

        $balasan = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload);

        // Bukan 422: aturan `size:3` cuma jalan kalau kuncinya ADA. Yang
        // menahan di sini penjaga sinkronisasi di `susunBlokHydrometer`, dan
        // titiknya pulang sebagai `belum_dihitung` dengan alasan yang kebaca.
        $balasan->assertSuccessful();

        $id = $balasan->json('data.id');
        $titikKe = CalibrationSession::findOrFail($id)
            ->uncertaintyCalculations()->pluck('titik_ke')->all();

        $this->assertSame([1, 3], $titikKe, 'titik ke-2 mestinya tidak terbit');
        $this->assertSame(
            0,
            RawMeasurement::where('calibration_session_id', $id)->where('titik_ke', 2)->count(),
            'titik yang ditolak mestinya tidak menyimpan satu baris pun — bukan menyimpan massanya saja',
        );
    }

    /** Ulangan yang bukan tiga ditolak aturan request, sebelum satu baris pun tersimpan. */
    public function test_ulangan_bukan_tiga_ditolak_422(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['measurements'][0]['hydro_massa'] = [21.2727, 21.2726];

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.hydro_massa']);
    }

    /** `tr` di luar 15 / 20 / 27,5 ditolak — salah ketik menggeser seluruh koreksi. */
    public function test_suhu_acuan_di_luar_daftar_ditolak_422(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI]['suhu_acuan_alat'] = 25;

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['spesifikasi_alat.hydrometer.suhu_acuan_alat']);
    }

    /** Diameter stem yang bukan tiga ukuran ditolak aturan request. */
    public function test_diameter_stem_bukan_tiga_ditolak_422(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI]['diameter_stem'] = [0.708, 0.710];

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['spesifikasi_alat.hydrometer.diameter_stem']);
    }

    /**
     * Angka berkoma dari keyboard HP Indonesia diterima dan dibaca benar.
     *
     * §8.2 butir 1: `"21,2727"` harus tersimpan `21.2727` — bukan `212727`
     * (koma dibuang) dan bukan `21` (dipotong di koma). Dua-duanya lolos
     * `(float)` PHP tanpa satu pun error.
     */
    public function test_angka_berkoma_diterima_dan_densitasnya_sama(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['measurements'][0]['hydro_massa'] = ['21,2727', '21,2726', '21,2856'];
        $payload['measurements'][0]['hydro_suhu'] = ['20,6', '20,6', '20,6'];
        $payload['tekanan_awal'] = '933,2';

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $massa = RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 1)
            ->where('peran_sensor', HydrometerMentah::PERAN_MASSA)
            ->orderBy('sensor_ke')->pluck('pembacaan')->map('floatval')->all();

        $this->assertSame([21.2727, 21.2726, 21.2856], $massa);

        $this->assertEqualsWithDelta(
            self::DENSITAS_MASTER[0],
            (float) CalibrationSession::findOrFail($id)
                ->uncertaintyCalculations()->where('titik_ke', 1)->value('rata_rata'),
            1e-6,
        );
    }

    /**
     * Sesi tanpa tekanan udara tidak menerbitkan satu pun titik.
     *
     * Kertas `SIDIK-FM-CAL-0533_Rev.2` tidak mencetak kotaknya sama sekali,
     * jadi ini bukan bentuk yang mustahil — justru yang paling mungkin datang.
     */
    public function test_tanpa_tekanan_udara_tidak_ada_titik_terbit(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, [
                'tekanan_awal' => null, 'tekanan_akhir' => null,
            ]))
            ->assertSuccessful()
            ->json('data.id');

        $this->assertSame(
            0,
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
            'sesi tanpa tekanan mestinya tidak menerbitkan satu pun titik',
        );
    }

    /**
     * Standar yang sertifikatnya kedaluwarsa MEMBLOKIR, bukan jadi label.
     *
     * Master Excel-nya cuma memasang label: `'INPUT DATA'!K3` menulis "ONE OR
     * MORE STANDARD EXPIRED" dan lembarnya tetap menghitung sampai selesai —
     * kedua file contoh bahkan TERBIT dengan status itu menyala. Lihat
     * `docs/pertanyaan-lab-hydrometer.md` §10.
     *
     * Yang menahannya di sini penjaga yang SUDAH ADA untuk semua alat, bukan
     * gerbang khusus hydrometer — dan letaknya bahkan lebih awal daripada yang
     * dituntut §8.2 butir 6: sesinya ditolak di PINTU MASUK (422), sebelum satu
     * baris pun tersimpan, bukan cuma tertahan waktu admin menyetujui.
     *
     * Test ini membuktikan penjaga itu memang menjangkau lembar ini juga. Kalau
     * suatu saat jalur hydrometer memotong jalan pintas ke `susunBlok…` tanpa
     * lewat aturan request, yang terbit sertifikat densitas berketertelusuran
     * putus — temuan asesor, dan sertifikatnya bisa ditarik.
     */
    public function test_standar_kedaluwarsa_ditolak_di_pintu_masuk(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        Standard::query()->update(['berlaku_sampai' => now()->subMonth()]);

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['standard_id']);

        $this->assertSame(
            0,
            CalibrationSession::query()->count(),
            'sesi berstandar kedaluwarsa mestinya tidak tersimpan sama sekali',
        );
    }
}
