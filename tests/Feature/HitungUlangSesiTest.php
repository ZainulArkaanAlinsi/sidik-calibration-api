<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `kalibrasi:hitung-ulang` — satu-satunya jalan membetulkan angka sesi yang
 * sudah disetujui, dan satu-satunya perintah di repo ini yang MENGHAPUS
 * `uncertainty_calculations`.
 *
 * ## Kenapa tes ini ada
 *
 * Penulisannya `delete()` seluruh baris lalu `create()` ulang per titik. Selama
 * hitung ulangnya menghasilkan titik, itu aman. Yang tidak aman: hitung ulang
 * yang pulang KOSONG — sesi berubah dari "punya sertifikat" jadi "tidak punya
 * angka sama sekali", `raw_measurements`-nya masih utuh, dan tidak ada satu pun
 * pesan yang menyebut sesuatu hilang.
 *
 * Persis itu yang kejadian ke sesi enclosure: grid-nya disimpan lewat
 * `sensor_ke`/`peran_sensor`, sementara perintah ini menyusun `konteks` cuma
 * dari `mode_tits`/`tipe_sensor`. Profil enclosure tidak menemukan grid,
 * memindahkan semua set point ke `belum_dihitung`, dan pulang dengan `hitungan`
 * kosong — yang lalu menimpa empat baris hasil yang benar. `--dry-run` pun tidak
 * memberi tanda.
 *
 * Dua hal yang dijaga di sini: hitung ulang enclosure MENGHASILKAN angka yang
 * sama (grid tersusun ulang dengan benar), dan hasil kosong TIDAK PERNAH menimpa
 * hasil yang ada.
 */
class HitungUlangSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 1e-7;

    /** Sesi Inkubator/Yokogawa/Type N dari `EnclosureSeeder` — 4 set point. */
    private const SESI_ENCLOSURE = '2405.03.AV';

    private function sesi(string $nomor): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
    }

    /**
     * Hitung ulang sesi enclosure menghasilkan angka yang SAMA — bukan nol baris.
     *
     * Ini tes regresi langsung: sebelum grid disusun ulang ke `konteks`, sesi ini
     * turun dari 4 baris jadi 0.
     */
    public function test_enclosure_hitung_ulang_mempertahankan_hasil(): void
    {
        $sesi = $this->sesi(self::SESI_ENCLOSURE);

        $sebelum = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()
            ->map(fn ($b): array => [
                'titik_ke' => (int) $b->titik_ke,
                'titik_ukur' => (float) $b->titik_ukur,
                'uc' => (float) $b->ketidakpastian_gabungan,
                'u95' => (float) $b->ketidakpastian_diperluas,
            ])
            ->all();

        $this->assertCount(4, $sebelum, 'sesi contoh harus punya 4 set point sebelum dihitung ulang');

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [self::SESI_ENCLOSURE]])
            ->assertSuccessful();

        $sesudah = $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(4, $sesudah, 'hitung ulang enclosure nggak boleh mengosongkan hasilnya');

        foreach ($sesudah as $i => $b) {
            $this->assertSame($sebelum[$i]['titik_ke'], (int) $b->titik_ke);
            $this->assertEqualsWithDelta($sebelum[$i]['titik_ukur'], (float) $b->titik_ukur, self::TOLERANSI);
            $this->assertEqualsWithDelta($sebelum[$i]['uc'], (float) $b->ketidakpastian_gabungan, self::TOLERANSI, "Uc titik {$b->titik_ke}");
            $this->assertEqualsWithDelta($sebelum[$i]['u95'], (float) $b->ketidakpastian_diperluas, self::TOLERANSI, "U95 titik {$b->titik_ke}");
        }
    }

    /**
     * Hitung ulang yang pulang KOSONG dibatalkan sebelum menghapus apa pun.
     *
     * Dipicu dengan mengosongkan `tipe_sensor` sesi: tanpa itu profil enclosure
     * memindahkan semua set point ke `belum_dihitung` — sama bentuk kegagalannya
     * dengan bug aslinya, tapi lewat sebab yang bisa dibuat di tes.
     */
    public function test_hasil_kosong_nggak_menimpa_hasil_yang_ada(): void
    {
        $sesi = $this->sesi(self::SESI_ENCLOSURE);
        $sesi->forceFill(['tipe_sensor' => null])->save();

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [self::SESI_ENCLOSURE]])
            ->expectsOutputToContain('DIBATALKAN')
            ->assertFailed();

        $this->assertSame(
            4,
            $sesi->fresh()->uncertaintyCalculations()->count(),
            'hasil lama harus utuh — hitung ulang kosong nggak boleh menghapusnya',
        );
    }

    /** `--dry-run` nggak menulis apa pun, dan tetap melewati penjagaan yang sama. */
    public function test_dry_run_nggak_menulis(): void
    {
        $sesi = $this->sesi(self::SESI_ENCLOSURE);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [self::SESI_ENCLOSURE], '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(4, $sesi->fresh()->uncertaintyCalculations()->count());
    }
}
