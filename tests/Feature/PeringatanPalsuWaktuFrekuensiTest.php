<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Services\CalibrationValidator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sesi master kelompok Waktu dan Frekuensi tidak boleh memuntahkan peringatan
 * PALSU — dua pemeriksa yang penggarisnya salah.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * Dua pemeriksa `CalibrationValidator` berdiri di atas premis yang sama:
 * **angka yang dicatat adalah penunjukan layar alat pelanggan**. Buat sepuluh
 * lembar pertama premis itu benar. Buat kelompok ini tidak, dan tidak ada satu
 * pun error yang terbit — yang terbit banjir peringatan di sesi yang datanya
 * disalin apa adanya dari workbook master lab.
 *
 * Diukur di sistem yang berjalan, pada ketiga sesi master ter-seed:
 *
 * | Sesi | Peringatan | Sebelum | Sesudah |
 * |---|---|---|---|
 * | Tachometer `0140-CAL-424` | `pembacaan_bukan_kelipatan_resolusi` | 53 | 1 |
 * | Centrifuge `0133-CAL-324` | `pembacaan_bukan_kelipatan_resolusi` | 47 | 1 |
 * | Timer `015-CAL-424` | `pembacaan_di_luar_rentang` | 30 dari 30 | 0 |
 *
 *  - **Putaran.** Yang dicatat lima kali baca TACHOMETER STANDAR (daya baca
 *    0,1 rpm), sementara pemeriksanya memakai `equipments.resolusi` — daya baca
 *    centrifuge pelanggan, 1 rpm. Jadi `59,9 rpm` dilaporkan "layarnya nggak
 *    mungkin nunjukin angka itu" di setiap baris.
 *  - **Timer.** Pembacaan tersimpan dalam MILIDETIK sementara
 *    `equipments.range_min/max` bersatuan alatnya. `60123 ms` diadu ke rentang
 *    `0–3600 s` seolah dia 60123 detik.
 *
 * Peringatan palsu yang selalu muncul melatih admin menekan "SETUJUI TETAP"
 * tanpa membaca — lalu peringatan yang benar-benar penting ikut tenggelam.
 * Itu sebabnya ini diuji sebagai cacat, bukan sebagai kebisingan yang bisa
 * ditunggu.
 */
class PeringatanPalsuWaktuFrekuensiTest extends TestCase
{
    use RefreshDatabase;

    /** Di atas nominal ini sertifikat kalibrator mencatat bilangan bulat. */
    private const AMBANG_RESOLUSI_STANDAR = 10_000.0;

    /**
     * @return array<string, array{string}>
     */
    public static function sesiPutaran(): array
    {
        return [
            'Tachometer' => ['0140-CAL-424'],
            'Centrifuge' => ['0133-CAL-324'],
        ];
    }

    /**
     * Pembacaan STANDAR tidak boleh dilaporkan "bukan kelipatan resolusi" cuma
     * karena alat pelanggan berdaya baca lebih kasar.
     *
     * Yang tersisa sah: di atas 10000 rpm sertifikat kalibrator mencatat
     * bilangan bulat, sementara data master di 15000 rpm justru berdesimal satu
     * (`15000,4`). Itu kejanggalan master yang memang pantas ditanyakan, bukan
     * kebisingan — dan jumlahnya satu, bukan lima puluh tiga.
     */
    #[DataProvider('sesiPutaran')]
    public function test_pembacaan_standar_tidak_dianggap_salah_resolusi(string $nomorSesi): void
    {
        $sesi = $this->sesi($nomorSesi);
        $setPoint = $sesi->rawMeasurements->pluck('titik_ukur', 'titik_ke');

        foreach ($this->temuan($sesi, 'pembacaan_bukan_kelipatan_resolusi') as $t) {
            $titikKe = (int) ($t['konteks']['titik_ke'] ?? 0);
            $sp = (float) ($setPoint[$titikKe] ?? 0.0);

            $this->assertGreaterThan(
                self::AMBANG_RESOLUSI_STANDAR, $sp,
                sprintf(
                    'Sesi %s titik %d (set point %s rpm): pembacaan %s dilaporkan bukan kelipatan '
                    .'resolusi %s. Di bawah %s rpm standarnya membaca 0,1 rpm, jadi ini peringatan '
                    .'palsu — penggarisnya resolusi alat pelanggan, bukan standarnya.',
                    $nomorSesi, $titikKe, $sp,
                    $t['konteks']['nilai'] ?? '?', $t['konteks']['resolusi'] ?? '?',
                    self::AMBANG_RESOLUSI_STANDAR,
                ),
            );
        }
    }

    /**
     * Pembacaan Timer tersimpan dalam milidetik; rentang alatnya bersatuan
     * alat. Nol peringatan "di luar rentang" — bukan "sedikit".
     */
    public function test_pembacaan_timer_tidak_dianggap_di_luar_rentang(): void
    {
        $sesi = $this->sesi('015-CAL-424');
        $temuan = $this->temuan($sesi, 'pembacaan_di_luar_rentang');

        $this->assertSame(
            [], $temuan,
            'Pembacaan Timer dilaporkan di luar rentang: '
            .($temuan[0]['pesan'] ?? '').' — milidetik diadu ke rentang bersatuan detik.',
        );
    }

    /**
     * Rentang alat ter-seed harus MEMUAT set point lembarnya sendiri.
     *
     * `kapasitas` master Stopwatch bernilai 60 dalam satuan `min`, sementara
     * `equipments.satuan` menyimpan detik. Disalin apa adanya, alatnya terdaftar
     * "0–60 detik" padahal lembarnya sendiri mengukur sampai 1800 detik.
     */
    public function test_rentang_alat_memuat_set_point_lembarnya(): void
    {
        $sesi = $this->sesi('015-CAL-424');
        $alat = $sesi->equipment;
        $tertinggi = (float) $sesi->uncertaintyCalculations->max('titik_ukur');

        $this->assertGreaterThanOrEqual(
            $tertinggi, (float) $alat->range_max,
            sprintf(
                'Rentang alat 0–%s %s nggak memuat set point tertinggi lembarnya sendiri (%s %s).',
                $alat->range_max, $alat->satuan, $tertinggi, $alat->satuan,
            ),
        );
    }

    /**
     * Kondisi lingkungan ketiga sesi contoh WAJIB terisi.
     *
     * Ketiga master mencatat suhu & kelembapan ruang (21,3/21,5 °C dan 53/56
     * %RH untuk Timer), tapi seeder-nya sempat lupa memanggil
     * `KondisiLingkungan::terapkan()` — pola yang sudah dipakai tiga seeder
     * lain dan oleh `POST /calibrations` sendiri. Akibatnya blok "Environmental
     * Condition" sertifikat contohnya kosong dan validator memunculkan
     * `env_condition` di SETIAP sesi. Data contoh yang memunculkan temuan palsu
     * melatih pembacanya mengabaikan temuan.
     */
    #[DataProvider('sesiKelompokLengkap')]
    public function test_kondisi_lingkungan_terisi(string $nomorSesi): void
    {
        $sesi = $this->sesi($nomorSesi);

        $this->assertNotNull($sesi->suhu_ruang, "Sesi {$nomorSesi} nggak punya suhu ruang.");
        $this->assertNotNull($sesi->kelembaban, "Sesi {$nomorSesi} nggak punya kelembaban.");

        $this->assertSame(
            [], $this->temuan($sesi, 'env_condition'),
            "Sesi {$nomorSesi} masih memunculkan `env_condition`.",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sesiKelompokLengkap(): array
    {
        return [
            'Tachometer' => ['0140-CAL-424'],
            'Centrifuge' => ['0133-CAL-324'],
            'Timer/Stopwatch' => ['015-CAL-424'],
        ];
    }

    /** Ketiga sesi master harus BERSIH dari kedua peringatan itu digabung. */
    public function test_ketiga_sesi_master_nyaris_bersih(): void
    {
        $total = 0;

        foreach (['0140-CAL-424', '0133-CAL-324', '015-CAL-424'] as $nomor) {
            $sesi = $this->sesi($nomor);
            $total += count($this->temuan($sesi, 'pembacaan_bukan_kelipatan_resolusi'))
                + count($this->temuan($sesi, 'pembacaan_di_luar_rentang'));
        }

        // Dua yang tersisa: satu per alat putaran, di titik 15000 rpm yang
        // masternya sendiri berdesimal satu di atas ambang bilangan bulat.
        // Angkanya dipatok supaya kebisingan yang kembali ketahuan — dulu 130.
        $this->assertLessThanOrEqual(
            2, $total,
            "Ketiga sesi master memuntahkan {$total} peringatan pembacaan. Sebelum perbaikan 130 — "
            .'kalau angkanya naik lagi, penggaris salah satu pemeriksa balik salah.',
        );
    }

    private function sesi(string $nomorSesi): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', $nomorSesi)
            ->with(['equipment', 'rawMeasurements.standard', 'uncertaintyCalculations', 'standard'])
            ->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function temuan(CalibrationSession $sesi, string $kode): array
    {
        $hasil = app(CalibrationValidator::class)->periksa($sesi);

        return array_values(array_filter(
            (array) ($hasil['temuan'] ?? $hasil),
            static fn ($t): bool => is_array($t) && ($t['kode'] ?? null) === $kode,
        ));
    }
}
