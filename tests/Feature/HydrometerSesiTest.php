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
            // Rentang alat SUNGGUHAN, dan itu penting buat lebih dari kerapian:
            // `EquipmentFactory` membiarkan `range_min`/`range_max` null, dan
            // `CalibrationValidator::diLuarRentang()` langsung pulang `false`
            // kalau salah satunya null. Dibiarkan begitu, seluruh berkas ini
            // memeriksa alat yang rentangnya tidak ada — dan penjaga pembacaan
            // di luar rentang tidak pernah kesentuh sekali pun.
            'range_min' => 0.600,
            'range_max' => 0.650,
            'resolusi' => 0.0005,
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
     * `kalibrasi:hitung-ulang` benar-benar MENGHITUNG sesi hydrometer — bukan
     * melewatinya diam-diam.
     *
     * Yang dijaga di sini bukan exit code-nya. Waktu cacat ini hidup,
     * perintahnya pulang **exit 0** dengan satu baris
     * "nggak ada titik yang bisa dihitung, dilewat" dan NOL baris ditulis —
     * jadi `assertSuccessful()` sendirian lolos tanpa memeriksa apa pun.
     *
     * Sebabnya: baris hydrometer punya `peran_sensor`, jadi
     * `GridSensorMentah::dari()` memulangkan `['sensor_grid' => [],
     * 'indikator' => []]` yang secara PHP bukan `[]`. Tanpa cabangnya sendiri,
     * sesi hydrometer jatuh ke cabang Enclosure terakhir, ketemu grid kosong,
     * lalu di-`continue`.
     *
     * Akibatnya bukan satu perintah yang diam: ini SATU-SATUNYA jalan
     * membetulkan angka sesi yang sudah tersimpan, jadi sesi hydrometer yang
     * salah hitung tidak punya jalan pulang sama sekali.
     */
    public function test_perintah_hitung_ulang_tidak_melewati_sesi_hydrometer(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$id], '--dry-run' => true])
            ->doesntExpectOutputToContain('dilewat')
            ->assertSuccessful();

        // Dan angkanya tidak bergeser: hitung ulang memberi hasil yang sama
        // dengan yang tersimpan, jadi dry-run tidak melaporkan satu pun beda.
        $sesudah = CalibrationSession::findOrFail($id)
            ->uncertaintyCalculations()->orderBy('titik_ke')
            ->pluck('rata_rata')->map(fn ($v): float => (float) $v)->all();

        foreach (self::DENSITAS_MASTER as $i => $harap) {
            $this->assertEqualsWithDelta($harap, $sesudah[$i], 1e-6);
        }
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

    /**
     * Keempat ejaan "tanpa sinker" diterima, dan semuanya memilih varian yang
     * sama.
     *
     * ## Kegagalan yang dijaga
     *
     * Aturannya `in:ya,tidak,true,false,1,0` dan kelihatan sudah memuat semua.
     * Tapi `in` membandingkan nilai yang sudah di-STRING-kan, dan di PHP
     * `(string) false` itu string KOSONG — bukan `'false'`. Jadi boolean asli
     * `true` lolos (jadi `'1'`) sementara `false` kena **422**, dengan pesan
     * yang menyebut daftar yang jelas-jelas memuat `false`.
     *
     * Asimetrisnya jatuh persis di sisi yang paling dipakai: `false` itu varian
     * TANPA sinker — variannya master `1.800-2.000`. Dan payload test di berkas
     * ini memakai `true`, jadi seluruh berkas ini pun lolos tanpa pernah
     * menyentuhnya.
     *
     * Yang diperiksa bukan cuma "tidak 422": keempatnya harus memilih varian
     * rumus yang SAMA. Ejaan yang lolos validasi tapi terbaca sebagai varian
     * lain memulangkan densitas yang tampak wajar dan meleset beberapa persen.
     */
    public function test_semua_ejaan_toggle_sinker_diterima_dan_artinya_sama(): void
    {
        $densitas = [];

        // `siapkan()` SEKALI di luar perulangan: dia membentuk organisasi +
        // standar sendiri tiap dipanggil, dan `payload()` mengambil standar
        // dengan `Standard::query()->value('id')` — baris pertama, yang mulai
        // panggilan kedua sudah milik organisasi lain. Yang gagal bukan yang
        // diuji: 422 `standard_id` dari data test, bukan dari togglenya.
        [$alat, $teknisi] = $this->siapkan();

        foreach ([false, 'tidak', 0, '0'] as $ejaan) {
            $payload = $this->payload($alat);
            $blok = &$payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI];
            $blok['pakai_beban_tambahan'] = $ejaan;
            // Varian tanpa sinker tidak memakai `Sl` sama sekali; dibiarkan
            // terisi, gerbang "toggle nyala tapi Sl kosong" tidak kesentuh dan
            // yang diuji jadi bukan togglenya.
            $blok['beban_tambahan'] = null;
            unset($blok);

            $id = $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $payload)
                ->assertSuccessful()
                ->json('data.id');

            $densitas[var_export($ejaan, true)] = CalibrationSession::findOrFail($id)
                ->uncertaintyCalculations()
                ->orderBy('titik_ke')
                ->get()
                ->map(static fn ($h) => round((float) $h->rata_rata, 6))
                ->all();
        }

        $pertama = reset($densitas);

        $this->assertNotSame([], $pertama, 'varian tanpa sinker tidak menerbitkan satu titik pun');

        foreach ($densitas as $ejaan => $nilai) {
            $this->assertSame(
                $pertama,
                $nilai,
                "Ejaan {$ejaan} memilih varian rumus yang berbeda dari ejaan lain.",
            );
        }
    }

    /** Dan varian DENGAN sinker beneran beda hasilnya — togglenya bukan hiasan. */
    public function test_ejaan_boolean_true_sama_dengan_ya(): void
    {
        $densitas = [];

        // Sekali di luar perulangan — alasannya sama dengan test di atas.
        [$alat, $teknisi] = $this->siapkan();

        foreach ([true, 'ya'] as $ejaan) {
            $payload = $this->payload($alat);
            $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI]['pakai_beban_tambahan'] = $ejaan;

            $id = $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $payload)
                ->assertSuccessful()
                ->json('data.id');

            $densitas[] = CalibrationSession::findOrFail($id)
                ->uncertaintyCalculations()
                ->orderBy('titik_ke')
                ->get()
                ->map(static fn ($h) => round((float) $h->rata_rata, 6))
                ->all();
        }

        $this->assertSame($densitas[0], $densitas[1], '`true` dan `ya` harus satu varian');
    }

    /**
     * Sesi yang benar TIDAK memunculkan satu pun peringatan palsu.
     *
     * ## Kegagalan yang dijaga
     *
     * `pembacaan_di_luar_rentang` mengadu tiap pembacaan ke
     * `equipments.range_min..range_max`. Buat tiga puluh dua alat lain itu
     * benar: yang diketik teknisi memang besaran yang sama dengan rentang
     * alatnya.
     *
     * Hydrometer alat pertama yang tidak begitu. Rentangnya **g/ml**
     * (0,600-0,650), sementara yang dipungut kertas itu **gram** (21,27) dan
     * **°C** (20,6) — densitasnya lahir belakangan dari metode Cuckow, tidak
     * pernah diketik siapa pun. Jadi tiap satu dari 18 pembacaan sesi yang
     * SEMPURNA dilaporkan "jauh di luar rentang ukur alat, kemungkinan besar
     * komanya kegeser".
     *
     * Dan yang rusak bukan cuma kerapian. Komentar di `CalibrationValidator`
     * sendiri sudah menulis alasannya waktu peringatan palsu serupa ditambal
     * buat suhu ruang autoklaf: peringatan palsu yang SELALU muncul melatih
     * admin menekan "SETUJUI TETAP" tanpa membaca — lalu peringatan yang
     * benar-benar penting ikut tenggelam. Di lembar ini peringatan palsunya
     * bukan satu-dua, tapi SEMUA.
     */
    public function test_sesi_benar_tidak_memunculkan_peringatan_palsu(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        // `/validasi` cuma buat admin (`kalibrasi.periksa`) — dan memang
        // admin yang membaca temuannya waktu memutuskan approve/reject.
        $admin = User::factory()->admin()->create(['organization_id' => $alat->organization_id]);

        $temuan = $this->actingAs($admin)
            ->getJson("/api/calibrations/{$id}/validasi")
            ->assertSuccessful()
            ->json('data.temuan') ?? [];

        $palsu = array_values(array_filter(
            $temuan,
            static fn (array $t): bool => ($t['kode'] ?? '') === 'pembacaan_di_luar_rentang',
        ));

        $this->assertSame(
            [],
            $palsu,
            sprintf(
                'Sesi yang angkanya sama persis dengan master memunculkan %d peringatan '
                .'`pembacaan_di_luar_rentang` — massa (gram) & suhu (°C) diadu ke rentang '
                .'alat yang bersatuan g/ml.',
                count($palsu),
            ),
        );
    }

    /**
     * Sesi ber-`kg/m3` memberi angka yang SAMA dengan sesi ber-`g/ml`.
     *
     * ## Kegagalan yang dijaga
     *
     * Dropdown `Satuan Densitas` mengubah arti DUA kotak — titik skala dan
     * resolusi — dan komentar di kotaknya sendiri sudah menulis begitu. Tapi
     * yang dikonversi cuma titik skalanya; resolusinya masuk budget apa adanya.
     *
     * Jadi teknisi yang memilih `kg/m3` dan mengetik resolusi `0,5` (setara
     * 0,0005 g/ml) membuat komponen `Resolution of Hydrometer` menerima **0,5
     * g/ml** — seribu kali terlalu besar, di komponen yang labelnya sendiri
     * sudah bertuliskan `g/ml`. Ketidakpastiannya membengkak, sertifikatnya
     * tetap terbit, dan tidak ada satu pun error.
     *
     * Yang diadu di sini bukan cuma densitasnya (itu sudah benar sejak awal)
     * tapi juga `U` — di situlah resolusinya masuk.
     */
    public function test_satuan_kg_per_m3_memberi_angka_yang_sama(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $ambil = function (array $payload) use ($teknisi): array {
            $id = $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $payload)
                ->assertSuccessful()
                ->json('data.id');

            return CalibrationSession::findOrFail($id)
                ->uncertaintyCalculations()
                ->orderBy('titik_ke')
                ->get()
                ->map(static fn ($h): array => [
                    'densitas' => round((float) $h->rata_rata, 6),
                    'u95' => round((float) $h->ketidakpastian_diperluas, 8),
                ])
                ->all();
        };

        $gPerMl = $ambil($this->payload($alat));

        // Sesi yang SAMA, cuma dinyatakan dalam kg/m3: titik skala ×1000 dan
        // resolusi ×1000. Massa (gram) & suhu (°C) tidak ikut — keduanya bukan
        // besaran densitas.
        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI]['satuan_densitas'] = 'kg/m3';
        $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI]['resolusi'] = 0.5;

        foreach ($payload['measurements'] as $i => $m) {
            $payload['measurements'][$i]['titik_ukur'] = $m['titik_ukur'] * 1000;
        }

        $this->assertSame(
            $gPerMl,
            $ambil($payload),
            'sesi yang sama dinyatakan dalam kg/m3 memberi angka berbeda — '
                .'ada kotak densitas yang satuannya tidak ikut dikonversi',
        );
    }

    /**
     * Deret massa & suhu TERTUKAR ketahuan sebelum sertifikatnya disetujui.
     *
     * ## Kegagalan yang dijaga
     *
     * Massa hasil timbang (21,27 g) dan suhu air (20,6 °C) ber-orde mirip.
     * Tertukar, tidak ada satu pun gerbang yang menahannya: dua-duanya angka
     * positif yang masuk akal, ulangannya tetap tiga, blok Pre Condition-nya
     * tetap utuh. Sesi contoh master yang deretnya dibalik **terbit dengan 201**
     * dan memulangkan densitas 0,5977 / 0,5975 / 0,5979 — ketiganya kelihatan
     * wajar buat hydrometer 0,600-0,650 — dengan `U95` 0,007041, yaitu 13,8 kali
     * lebih besar daripada 0,00051 yang benar.
     *
     * Yang membongkarnya bukan besar angkanya tapi bahwa ketiganya HAMPIR SAMA
     * padahal tanda skala yang diukur menyebar 0,040. Hydrometer membaca
     * skalanya sendiri; densitas di tanda 0,650 wajib lebih besar daripada di
     * tanda 0,610. Deret yang tertukar kehilangan sifat itu — yang tersisa cuma
     * suhu air, yang memang nyaris tetap sepanjang sesi.
     */
    public function test_deret_massa_dan_suhu_tertukar_kena_peringatan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);

        foreach ($payload['measurements'] as $i => $m) {
            $payload['measurements'][$i]['hydro_massa'] = $m['hydro_suhu'];
            $payload['measurements'][$i]['hydro_suhu'] = $m['hydro_massa'];
        }

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $admin = User::factory()->admin()->create(['organization_id' => $alat->organization_id]);

        $kode = array_column(
            $this->actingAs($admin)
                ->getJson("/api/calibrations/{$id}/validasi")
                ->assertSuccessful()
                ->json('data.temuan') ?? [],
            'kode',
        );

        $this->assertContains(
            'hydrometer_densitas_tidak_mengikuti_skala',
            $kode,
            'sesi yang deret massa & suhunya tertukar lolos tanpa satu pun peringatan',
        );
    }

    /** Dan sesi yang BENAR tidak ikut kena — ambangnya bukan asal ketat. */
    public function test_sesi_benar_tidak_kena_peringatan_deret_tertukar(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $admin = User::factory()->admin()->create(['organization_id' => $alat->organization_id]);

        $kode = array_column(
            $this->actingAs($admin)
                ->getJson("/api/calibrations/{$id}/validasi")
                ->assertSuccessful()
                ->json('data.temuan') ?? [],
            'kode',
        );

        $this->assertNotContains('hydrometer_densitas_tidak_mengikuti_skala', $kode);
    }

    /** Kode temuan yang muncul buat sesi ini, lewat endpoint validasi sungguhan. */
    private function kodeTemuan(int $id, int $organizationId): array
    {
        $admin = User::factory()->admin()->create(['organization_id' => $organizationId]);

        return array_column(
            $this->actingAs($admin)
                ->getJson("/api/calibrations/{$id}/validasi")
                ->assertSuccessful()
                ->json('data.temuan') ?? [],
            'kode',
        );
    }

    /**
     * Koma kegeser di kolom Weight KETAHUAN — penjaganya nggak boleh hilang.
     *
     * ## Kegagalan yang dijaga
     *
     * Hydrometer alat pertama yang pembacaan mentahnya (gram, °C) bukan besaran
     * alatnya (g/ml), jadi `CalibrationValidator` sengaja melewatkan kedua deret
     * itu dari `pembacaan_di_luar_rentang` — kalau tidak, sesi yang sempurna
     * memuntahkan 18 peringatan palsu.
     *
     * Harganya: alat ini kehilangan SATU-SATUNYA penjaga "koma kegeser" yang
     * dipunyai tiga puluh dua alat lain. `diLuarRentang()` cuma punya satu
     * pemanggil, dan itu blok yang baru saja dilewati.
     *
     * Diukur: satu koma kegeser di kolom Weight titik pertama (21,2727 →
     * 2,12727 g) menerbitkan densitas **0,468497** g/ml buat tanda skala 0,610 —
     * angka yang alatnya sendiri tidak punya tandanya, karena skalanya cuma
     * 0,600-0,650. Sesinya lolos `valid = true`, `boleh_terbit = true`, nol
     * temuan, `U95` tetap 0,00051 dari lantai CMC. Sertifikat terakreditasi
     * terbit dengan densitas yang mustahil.
     */
    public function test_koma_kegeser_di_kolom_weight_ketahuan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['measurements'][0]['hydro_massa'] = array_map(
            static fn (float $m): float => $m / 10,
            $payload['measurements'][0]['hydro_massa'],
        );

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $this->assertContains(
            'hydrometer_koreksi_tidak_masuk_akal',
            $this->kodeTemuan($id, (int) $alat->organization_id),
            'koma kegeser di kolom Weight lolos tanpa satu pun temuan',
        );
    }

    /**
     * Deret yang dipetakan TERBALIK ke titiknya ketahuan.
     *
     * Gerbang rasio `hydrometer_densitas_tidak_mengikuti_skala` tidak bisa
     * menangkap ini, dan itu bukan kelalaian melainkan batas bentuknya: yang
     * diukur di sana SEBARAN (`max − min`), dan deret yang dibalik punya sebaran
     * yang persis sama — rasionya 1,0181, mulus lolos.
     *
     * Yang membongkarnya besar KOREKSINYA: tanda 0,610 menerima hasil timbang
     * cairan tanda 0,650, jadi koreksinya +0,0346 g/ml pada alat yang seluruh
     * skalanya cuma selebar 0,050 — 69% lebar skala.
     */
    public function test_deret_dipetakan_terbalik_ketahuan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $massa = array_column($payload['measurements'], 'hydro_massa');
        $suhu = array_column($payload['measurements'], 'hydro_suhu');

        foreach ($payload['measurements'] as $i => $m) {
            $payload['measurements'][$i]['hydro_massa'] = $massa[count($massa) - 1 - $i];
            $payload['measurements'][$i]['hydro_suhu'] = $suhu[count($suhu) - 1 - $i];
        }

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        $this->assertContains(
            'hydrometer_koreksi_tidak_masuk_akal',
            $this->kodeTemuan($id, (int) $alat->organization_id),
            'deret yang dipetakan terbalik lolos — sebarannya sama, jadi gerbang rasio diam',
        );
    }

    /** Dan sesi yang BENAR tetap bersih dari kedua gerbang itu. */
    public function test_sesi_benar_bersih_dari_gerbang_koreksi(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $kode = $this->kodeTemuan($id, (int) $alat->organization_id);

        $this->assertNotContains('hydrometer_koreksi_tidak_masuk_akal', $kode);
        $this->assertNotContains('hydrometer_densitas_tidak_mengikuti_skala', $kode);
    }

    /**
     * BENTUK PAYLOAD ASLI HP diterima — bukan bentuk datar yang cuma dipakai test.
     *
     * ## Kegagalan yang dijaga
     *
     * Tabel "Diameter Stem" menyatakan `simpan_ke:
     * spesifikasi_alat.hydrometer.diameter_stem`, dan buat tiap tabel semacam
     * itu HP SELALU merakit cerminan tabelnya
     * (`LembarKerjaState._tanamTabelSpesifikasi()`:
     * `induk[jalur.last] = {'baris': isi}`) — bukan deret datar.
     *
     * Aturannya `array` + `size:3` + `.*` `required|numeric|gt:0`, jadi bentuk
     * itu ditolak **422** dengan tiga pesan sekaligus. Artinya tidak ada satu
     * pun sesi Hydrometer yang bisa dikirim dari aplikasi — alatnya mati total
     * di lapangan.
     *
     * Dan itu lolos 68 test hydrometer tanpa satu pun merah, karena seeder dan
     * seluruh berkas ini mengirim bentuk DATAR yang memang diterima — bentuk
     * yang tidak pernah dipakai HP. Pelajarannya: test yang menyusun payloadnya
     * sendiri cuma menguji bentuk yang dibayangkan penulisnya.
     */
    public function test_bentuk_payload_asli_hp_diterima(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);

        // Persis yang dirakit `_tanamTabelSpesifikasi()` di HP.
        $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI]['diameter_stem'] = [
            'baris' => [
                ['titik_ukur' => null, 'pembacaan' => [0.708, 0.710, 0.709]],
            ],
        ];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');

        // Dan angkanya sama persis dengan sesi bentuk datar — perataannya tidak
        // boleh mengubah hasil, cuma bentuknya.
        $hitungan = CalibrationSession::findOrFail($id)
            ->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get();

        $this->assertCount(3, $hitungan);

        foreach (self::DENSITAS_MASTER as $i => $harap) {
            $this->assertEqualsWithDelta(
                $harap,
                (float) $hitungan[$i]->rata_rata,
                1e-6,
                'densitas titik ke-'.($i + 1).' berubah gara-gara bentuk payloadnya',
            );
        }
    }

    /** Dua ukuran diameter stem tetap DITOLAK, walau dikirim bentuk tabel. */
    public function test_bentuk_hp_dengan_dua_ukuran_tetap_ditolak(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI]['diameter_stem'] = [
            'baris' => [
                ['titik_ukur' => null, 'pembacaan' => [0.708, 0.710]],
            ],
        ];

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['spesifikasi_alat.hydrometer.diameter_stem']);
    }

    /**
     * Kotak Pre Condition yang dibiarkan kosong KETAHUAN satu per satu.
     *
     * `HydrometerMentah::blokSesi()` menjatuhkan kotak kosong ke `0.0`, dan nol
     * itu angka yang sah buat rumusnya — jadi sesinya terbit, cuma dengan angka
     * yang salah, tanpa satu pun error. Diukur dari master ringan:
     *
     *   `yx` kosong       → densitas bergeser **0,0005992** g/ml dan `U95` turun 33%
     *   `resolusi` kosong → densitas tetap, `U95` turun 19%
     *
     * Yang paling mahal `yx`: geseran densitasnya lebih besar daripada lantai
     * CMC-nya sendiri (0,00051), jadi satu kotak yang lupa diisi menggeser angka
     * yang tercetak lebih jauh daripada seluruh ketidakpastian yang diklaim
     * dokumen itu — sambil membuat ketidakpastiannya tampak lebih kecil.
     */
    public function test_kotak_pre_condition_kosong_ketahuan(): void
    {
        // `siapkan()` SEKALI: dia membentuk organisasi + standar sendiri tiap
        // dipanggil, dan `payload()` mengambil standar dengan
        // `Standard::query()->value('id')` — baris pertama, yang mulai panggilan
        // kedua sudah milik organisasi lain. Yang gagal jadi bukan yang diuji.
        [$alat, $teknisi] = $this->siapkan();

        foreach ([
            'tegangan_permukaan' => 'hydrometer_tegangan_permukaan_kosong',
            'resolusi' => 'hydrometer_resolusi_kosong',
        ] as $kunci => $kode) {
            $payload = $this->payload($alat);
            $payload['spesifikasi_alat'][HydrometerMentah::KUNCI_SESI][$kunci] = null;

            $id = $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $payload)
                ->assertSuccessful()
                ->json('data.id');

            $this->assertContains(
                $kode,
                $this->kodeTemuan($id, (int) $alat->organization_id),
                "kotak `{$kunci}` kosong lolos tanpa peringatan",
            );
        }
    }

    /** Sesi yang lengkap tidak kena satu pun peringatan kotak kosong. */
    public function test_sesi_lengkap_bersih_dari_peringatan_kotak_kosong(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertSuccessful()
            ->json('data.id');

        $kode = $this->kodeTemuan($id, (int) $alat->organization_id);

        $this->assertNotContains('hydrometer_tegangan_permukaan_kosong', $kode);
        $this->assertNotContains('hydrometer_resolusi_kosong', $kode);
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
