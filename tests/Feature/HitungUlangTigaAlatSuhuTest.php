<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Support\PasanganStandarUutMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sesi tersimpan ketiga alat suhu masih bisa DIHITUNG ULANG.
 *
 * ## Kenapa ini bukan soal layar yang rapi
 *
 * Sebelum berkas ini ada, SETIAP titik di SETIAP sesi Thermocouple, Termometer
 * Gelas, dan Thermohygrometer pulang sebagai `hitung_ulang_gagal`:
 *
 *     0513-CAL-1124  titik 1,2,3   "Dryblock yang dipakai belum dipilih"
 *     0135-CAL-125   titik 1..5    "Oilbath yang dipakai belum dipilih"
 *     0312-CAL-624   titik 1..10   "baru punya 0 pembacaan standar"
 *
 * Datanya ada semua di database — `alat_bantu` terisi, `peran_sensor`
 * standar/UUT lengkap, `sensor_ke` menempel di sisi standar. Yang tidak ada
 * cuma jalannya: `CalibrationValidator` menyusun `konteks` buat hitung ulang
 * dan ketiga alat ini tidak kebagian kuncinya.
 *
 * Dua hal yang hilang karena itu, dan yang kedua jauh lebih mahal:
 *
 *  1. Pemeriksaan "apakah angka tersimpan masih bisa direproduksi" **mati
 *     total** buat ketiga alat. Kalau tabel master bergeser sesudah sesi
 *     disimpan, tidak ada satu pun yang memberi tahu.
 *  2. Peringatan yang SELALU muncul melatih admin menekan "setujui tetap"
 *     tanpa membaca — dan begitu itu jadi kebiasaan, peringatan yang
 *     benar-benar menahan sertifikat ikut tenggelam. Bahayanya bukan teori:
 *     kalimat itu ditulis di `CalibrationValidator` sendiri, sesudah pola yang
 *     sama kejadian di Viscometer, Gas Detector, TITS, lalu Enclosure.
 *
 * Ini kejadian kelima. Yang membedakan: sekarang ada yang menjaganya.
 *
 * ## Yang ditegakkan, dan kenapa dua-duanya
 *
 * `hitung_ulang_gagal` nol saja belum cukup — itu cuma membuktikan hitung
 * ulangnya JALAN. Yang juga ditegakkan `hitung_ulang_beda` & `keputusan_titik_beda`
 * nol: hasilnya JALAN **dan** SAMA dengan yang tersimpan. Tanpa yang kedua,
 * konteks yang salah isi (mis. dryblock B dikirim buat sesi dryblock A) tetap
 * lolos — hitung ulangnya sukses, angkanya saja yang meleset.
 */
class HitungUlangTigaAlatSuhuTest extends TestCase
{
    use RefreshDatabase;

    /** Sesi contoh ketiga alat, dari `Suhu3AlatSeeder`. */
    private const SESI = [
        'thermocouple' => '0513-CAL-1124',
        'thermometer_glass' => '0135-CAL-125',
        'thermohygro' => '0312-CAL-624',
    ];

    /** @return list<array<string, mixed>> */
    private function temuan(CalibrationSession $sesi): array
    {
        $admin = User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->firstOrFail();

        return $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data.temuan');
    }

    public function test_tiap_titik_ketiga_alat_masih_bisa_dihitung_ulang(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (self::SESI as $profil => $nomor) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $temuan = $this->temuan($sesi);

            $kode = array_column($temuan, 'kode');

            // Sanity: sesinya memang punya titik terhitung, jadi kalau nanti
            // seeder-nya berubah dan sesinya kosong, test ini nggak hijau
            // karena "nggak ada yang diperiksa".
            $this->assertGreaterThan(
                0,
                $sesi->uncertaintyCalculations()->count(),
                "{$profil} ({$nomor}): sesinya nggak punya titik terhitung — test ini nggak nguji apa pun.",
            );

            $gagal = array_values(array_filter(
                $temuan,
                static fn (array $t): bool => $t['kode'] === 'hitung_ulang_gagal',
            ));

            $this->assertSame(
                [],
                array_column($gagal, 'pesan'),
                "{$profil} ({$nomor}): hitung ulang gagal — `konteks` yang dikirim `CalibrationValidator` "
                .'nggak cukup buat profil ini. Datanya ADA di database; yang hilang jalannya.',
            );

            foreach (['hitung_ulang_beda', 'keputusan_titik_beda'] as $bedanya) {
                $this->assertNotContains(
                    $bedanya,
                    $kode,
                    "{$profil} ({$nomor}): hitung ulangnya jalan tapi hasilnya MELESET dari yang tersimpan. "
                    .'Konteksnya kebentuk, isinya yang salah.',
                );
            }
        }
    }

    /**
     * Arah sebaliknya: kunci pasangannya beneran DIPAKAI, bukan kebetulan lewat.
     *
     * Tanpa ini, penegakan di atas bisa hijau lewat jalan lain — mis. kalau
     * suatu saat pemeriksaan hitung-ulangnya sendiri dimatikan buat alat
     * ber-budget-kelompok. Yang diadu di sini bentuk yang disusun ulang, lawan
     * baris mentah yang beneran tersimpan.
     */
    public function test_pasangan_standar_uut_disusun_ulang_dari_baris_tersimpan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', self::SESI['thermocouple'])->firstOrFail();
        $baris = $sesi->rawMeasurements->where('tahap', 'sesudah_adjustment')->groupBy('titik_ke');

        $pasangan = PasanganStandarUutMentah::dari($baris->first());

        $this->assertNotSame([], $pasangan, 'Baris ber-`peran_sensor` standar/UUT harus kesusun jadi pasangan.');
        $this->assertCount(5, $pasangan['standar'], 'Lima pembacaan standar (`0″`…`80″`).');
        $this->assertCount(5, $pasangan['uut'], 'Lima pembacaan UUT (`10″`…`90″`).');

        // Nomor probe cuma menempel ke sisi STANDAR — sisi UUT memakai probe
        // bawaan alat pelanggan, yang justru sedang diukur penyimpangannya.
        $this->assertSame(
            (int) $baris->first()->firstWhere('peran_sensor', 'standar')->sensor_ke,
            $pasangan['no_probe'],
            'No. probe wajib datang dari baris STANDAR.',
        );

        // Alat yang nggak punya peran standar/UUT nggak boleh dapat kunci ini
        // sama sekali — kunci yang muncul kosong lebih berbahaya daripada kunci
        // yang nggak ada.
        $ph = CalibrationSession::where('nomor_sesi', '2405.13.A')->firstOrFail();
        $this->assertSame(
            [],
            PasanganStandarUutMentah::dari($ph->rawMeasurements->groupBy('titik_ke')->first()),
            'Lembar pH nggak punya pasangan standar/UUT; kuncinya nggak boleh muncul.',
        );
    }

    /**
     * Thermohygro membelah titiknya jadi grup suhu & kelembapan, dan yang
     * menentukannya `parameter`. `raw_measurements` nggak punya kolom itu —
     * jalur simpan menuliskannya sebagai `satuan`, jadi di situ dibaca balik.
     *
     * Salah menebak di sini nggak bikin error: profilnya jatuh ke `suhu` kalau
     * kuncinya hilang, jadi sepuluh titik masuk satu grup dan U95 kelembapan
     * nggak pernah lahir — salah yang rapi, tanpa keluhan.
     */
    public function test_parameter_thermohygro_diturunkan_dari_satuan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', self::SESI['thermohygro'])->firstOrFail();

        $parameter = $sesi->rawMeasurements
            ->where('tahap', 'sesudah_adjustment')
            ->groupBy('titik_ke')
            ->map(static fn ($baris): string => PasanganStandarUutMentah::dari($baris)['parameter'])
            ->all();

        // `INPUT DATA`: lima titik suhu (15…50 °C) lalu lima titik kelembapan.
        $this->assertSame(
            ['suhu', 'suhu', 'suhu', 'suhu', 'suhu', 'kelembaban', 'kelembaban', 'kelembaban', 'kelembaban', 'kelembaban'],
            array_values($parameter),
            'Titik %RH kebaca sebagai suhu — U95 kelembapan nggak akan pernah lahir.',
        );
    }

    /**
     * Jalur KEDUA yang menghitung ulang sesi tersimpan: `kalibrasi:hitung-ulang`.
     *
     * Dua salinan rekonstruksi berarti dua bentuk yang bisa berbeda diam-diam,
     * dan buat lab terakreditasi selisih antara "yang divalidasi sebelum
     * approve" dan "yang ditulis ke database" itu temuan audit. Makanya
     * [PasanganStandarUutMentah] dipakai dua-duanya, dan dua-duanya diadu di
     * sini.
     *
     * Sebelum perbaikannya, perintah ini bukan cuma salah buat ketiga alat —
     * dia BERHENTI: hitung ulang pulang kosong, dan penjaga "hasil kosong nggak
     * boleh menimpa" membatalkan seluruh perintah. Jadi satu-satunya jalan
     * membetulkan angka sesi yang terlanjur terbit memang tertutup buat ketiga
     * alat suhu.
     */
    public function test_perintah_hitung_ulang_mempertahankan_angka_ketiga_alat(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (self::SESI as $profil => $nomor) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();

            $sebelum = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()
                ->map(static fn ($b): array => [
                    'titik_ke' => (int) $b->titik_ke,
                    'uc' => (float) $b->ketidakpastian_gabungan,
                    'u95' => (float) $b->ketidakpastian_diperluas,
                ])
                ->all();

            $this->assertNotSame([], $sebelum, "{$profil}: nggak ada angka awal buat dibandingkan.");

            // Satu angka SENGAJA dirusak sebelum perintahnya jalan, dan ini
            // bukan hiasan: tanpa itu test ini pernah hijau justru waktu
            // perintahnya rusak. Sebabnya perintah ini MELEWATI titik yang
            // nggak bisa disusun ulang — diam-diam, tanpa menaikkan status
            // gagal. Jadi "angkanya nggak berubah" bisa berarti dua hal yang
            // berlawanan: dihitung ulang dan hasilnya sama, ATAU nggak pernah
            // disentuh sama sekali. Angka yang dirusak membedakannya —
            // dia cuma balik ke asalnya kalau perintahnya beneran menghitung.
            $rusak = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->first();
            $titikRusak = (int) $rusak->titik_ke;
            $rusak->forceFill(['ketidakpastian_gabungan' => 99.9])->save();

            $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$nomor]])
                ->assertSuccessful();

            // Dibaca ulang lewat `titik_ke`, bukan lewat model tadi: hitung
            // ulang MENGGANTI barisnya, jadi `->fresh()` pulang null.
            $dibetulkan = $sesi->fresh()->uncertaintyCalculations()
                ->where('titik_ke', $titikRusak)
                ->firstOrFail();

            $this->assertEqualsWithDelta(
                $sebelum[0]['uc'],
                (float) $dibetulkan->ketidakpastian_gabungan,
                1e-6,
                "{$profil}: angka yang dirusak nggak dibetulkan — perintahnya melewati sesi ini "
                .'tanpa menghitung apa pun.',
            );

            $sesudah = $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get();

            $this->assertCount(
                count($sebelum),
                $sesudah,
                "{$profil}: jumlah titiknya berubah sesudah dihitung ulang.",
            );

            foreach ($sesudah as $i => $b) {
                $this->assertSame($sebelum[$i]['titik_ke'], (int) $b->titik_ke);
                $this->assertEqualsWithDelta(
                    $sebelum[$i]['uc'],
                    (float) $b->ketidakpastian_gabungan,
                    1e-6,
                    "{$profil} titik {$b->titik_ke}: Uc bergeser sesudah dihitung ulang.",
                );
                $this->assertEqualsWithDelta(
                    $sebelum[$i]['u95'],
                    (float) $b->ketidakpastian_diperluas,
                    1e-6,
                    "{$profil} titik {$b->titik_ke}: U95 bergeser sesudah dihitung ulang.",
                );
            }
        }
    }
}
