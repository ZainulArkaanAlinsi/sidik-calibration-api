<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Koordinat GRID ikut pulang ke HP di `pembacaan_mentah`.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * Sesi yang dikembalikan admin dibuka ulang teknisi, dan `terapkanPembacaan()`
 * di HP mengisi tabelnya lagi dari `pembacaan_mentah`. Buat sepuluh lembar
 * bertabel datar itu jalan sejak lama: dicocokkan per titik ukur, angkanya
 * masuk ke kolom `pembacaan`/`suhu`.
 *
 * Lembar Enclosure nggak punya kolom itu. Angkanya duduk di sel
 * (sensor ke-N, repeat ke-M) di dalam grid termokopel, dan yang membedakan satu
 * baris dari yang lain justru `sensor_ke` — kunci yang sampai 26 Agt 2026
 * NGGAK pernah ikut di respons.
 *
 * Barisnya sendiri tersimpan lengkap sejak ingest. Yang hilang cuma jalan
 * pulangnya. Akibatnya teknisi yang lembarnya dikembalikan dapat grid KOSONG
 * dan mengetik ulang 9 termokopel × 5 repeat × tiap set point — 180 sel buat
 * sesi Inkubator 4 set point, termasuk angka yang sudah benar.
 *
 * Bahayanya bukan cuma capek: yang dikirim balik ke admin cuma sisa yang
 * sempat diketik ulang, dan sel yang kelewat nggak meninggalkan jejak apa pun.
 *
 * ## Kenapa `peran_sensor` ikut dijaga
 *
 * Grid Enclosure nggak cuma termokopel — baris Suhu Ruang duduk di tabel yang
 * sama dengan peran yang beda. Memulihkan tanpa membedakan peran bikin angka
 * suhu ruang mendarat di kotak termokopel: bentuknya wajar, tempatnya salah,
 * dan nggak ada yang meneriakkan apa pun.
 */
class EnclosureGridPulangKeHpTest extends TestCase
{
    use RefreshDatabase;

    /** Sesi Inkubator dari master `..._Constant_Yokogawa.xlsm`. */
    private const NOMOR_SESI = '2405.03.AV';

    /** Sesi Oven dari master `..._Recorder.xlsm` — satu-satunya yang berkanal. */
    private const NOMOR_SESI_RECORDER = '2406.25.AI';

    private function sesiEnclosure(string $nomor = self::NOMOR_SESI): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
    }

    private function teknisi(CalibrationSession $sesi): User
    {
        return User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function pembacaan(CalibrationSession $sesi): array
    {
        return $this->actingAs($this->teknisi($sesi))
            ->getJson("/api/calibrations/{$sesi->id}")
            ->assertOk()
            ->json('data.pembacaan_mentah');
    }

    /**
     * INTI: tiap baris membawa `sensor_ke` dan `peran_sensor`.
     *
     * Yang diuji ADA-nya kunci, bukan isinya — baris lembar datar sah
     * bernilai null. Yang nggak boleh kuncinya hilang, karena HP nggak punya
     * cara membedakan "bukan grid" dari "grid yang koordinatnya nggak dikirim".
     */
    public function test_baris_membawa_koordinat_grid(): void
    {
        $sesi = $this->sesiEnclosure();
        $baris = $this->pembacaan($sesi);

        $this->assertNotEmpty($baris, 'Sesi Enclosure acuan nggak punya pembacaan sama sekali.');

        foreach ($baris as $i => $b) {
            $this->assertArrayHasKey(
                'sensor_ke',
                $b,
                "Baris ke-{$i} nggak bawa `sensor_ke`.\n\n"
                .'Tanpa kunci ini grid Enclosure yang dikembalikan admin pulang KOSONG, dan '
                .'teknisi mengetik ulang 180 sel buat sesi 4 set point.',
            );
            $this->assertArrayHasKey('peran_sensor', $b, "Baris ke-{$i} nggak bawa `peran_sensor`.");
        }
    }

    /**
     * Nilainya BENERAN terisi, bukan cuma kuncinya ada.
     *
     * Kunci yang selalu null sama nggak bergunanya dengan kunci yang hilang:
     * HP tetap nggak tahu sel mana yang harus diisi, dan gridnya tetap kosong.
     */
    public function test_koordinat_grid_beneran_terisi(): void
    {
        $sesi = $this->sesiEnclosure();
        $baris = $this->pembacaan($sesi);

        $bergrid = array_values(array_filter(
            $baris,
            static fn (array $b): bool => ($b['sensor_ke'] ?? null) !== null,
        ));

        $this->assertNotEmpty(
            $bergrid,
            'Nol baris yang bawa `sensor_ke` terisi di sesi Enclosure — koordinat gridnya '
            .'nggak pernah nyampe ke HP walau kuncinya ada.',
        );

        $peran = array_unique(array_column($bergrid, 'peran_sensor'));

        $this->assertContains(
            'termokopel',
            $peran,
            'Nggak ada baris ber-peran `termokopel`. Peran yang kebaca: '.implode(', ', $peran),
        );
    }

    /**
     * Sepasang (sensor, repeat) cukup buat menunjuk satu sel — nggak kembar.
     *
     * Kalau kembar, HP nggak punya cara memilih mana yang benar, dan yang
     * dipilih diam-diam bisa angka set point lain.
     */
    public function test_koordinat_sel_nggak_kembar_dalam_satu_titik(): void
    {
        $sesi = $this->sesiEnclosure();

        $terlihat = [];

        foreach ($this->pembacaan($sesi) as $b) {
            if (($b['sensor_ke'] ?? null) === null) {
                continue;
            }

            $kunci = sprintf(
                '%s|%s|%s|%s',
                $b['tahap'] ?? '?',
                $b['titik_ukur'] ?? '?',
                $b['sensor_ke'],
                $b['pembacaan_ke'] ?? '?',
            );

            $this->assertArrayNotHasKey(
                $kunci,
                $terlihat,
                "Dua baris menunjuk sel yang sama: {$kunci}. HP nggak punya cara memilih.",
            );

            $terlihat[$kunci] = true;
        }

        $this->assertNotEmpty($terlihat);
    }

    /**
     * Sesi kalibrator RECORDER pulang lengkap dengan nomor kanalnya.
     *
     * Kanal bukan hiasan: koreksi meter GL840 dibaca per kanal, jadi tanpa
     * nomor itu sesinya nggak bisa dihitung ulang. Layar HP pun menuntutnya —
     * `GridSensorBentuk.butuhChannel('recorder')` bernilai true, dan set point
     * tanpa kanal langsung diberi peringatan "Channel wajib diisi".
     *
     * Jadi sesi Recorder yang dikembalikan tanpa kanal bikin teknisi mengetik
     * ulang sembilan nomor yang sudah benar. Dan kalau salah ketik, nggak ada
     * satu pun error: koreksi kanal lain yang kepakai, dan sertifikatnya
     * terbit dengan angka yang kelihatan wajar.
     */
    public function test_kanal_recorder_ikut_pulang(): void
    {
        $sesi = $this->sesiEnclosure(self::NOMOR_SESI_RECORDER);

        $termokopel = array_values(array_filter(
            $this->pembacaan($sesi),
            static fn (array $b): bool => ($b['peran_sensor'] ?? null) === 'termokopel',
        ));

        $this->assertNotEmpty($termokopel, 'Sesi Recorder acuan nggak punya baris termokopel.');

        foreach ($termokopel as $i => $b) {
            $this->assertArrayHasKey('channel', $b, "Baris termokopel ke-{$i} nggak bawa `channel`.");
        }

        $kanal = array_values(array_unique(array_filter(
            array_column($termokopel, 'channel'),
            static fn (mixed $c): bool => $c !== null,
        )));

        $this->assertNotEmpty(
            $kanal,
            'Semua `channel` null di sesi Recorder — nomor kanalnya nggak pernah nyampe ke HP '
            .'walau kuncinya ada, dan teknisi diteriakin "Channel wajib diisi" buat baris yang '
            .'sudah dia isi benar.',
        );
    }
}
