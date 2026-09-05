<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolom `Calibration Method` sertifikat wajib menyebut nomor IK lab yang UTUH,
 * di SEMUA alat.
 *
 * ## Kegagalan yang dijaga di sini
 *
 * `CertificateSnapshotBuilder::metodeKalibrasi` punya cadangan: kalau admin
 * tidak memilih metode, nama alat dicocokkan ke kolom "Jenis Pengukuran" tabel
 * master. Cadangan itu jalan selama nama alatnya memuat jenis pengukurannya —
 * dan meleset diam-diam begitu tidak. Sapuan ke seluruh sesi contoh menemukan
 * empat sertifikat dengan kolom metode KOSONG dan satu tanpa revisi:
 *
 *   Temperature Calibrator      -> (kosong)   jenisnya "TITS"
 *   Temperature Recorder Contr. -> (kosong)   jenisnya "TITS"
 *   Incubator                   -> (kosong)   jenisnya "Enclosure"
 *   Oven                        -> (kosong)   jenisnya "Enclosure"
 *   Turbidimeter                -> tanpa Rev. master mengejanya "Turbidy Meter"
 *   Moisture Analyzer           -> rujukan pustaka, jenisnya "Timbangan"
 *
 * Tidak satu pun menghasilkan error: kolomnya memang terisi (atau memang
 * kosong), lembarnya terbit rapi, dan yang salah cuma kelihatan kalau ada yang
 * mencocokkan ke dokumen mutu. Di lembar terakreditasi itu salah menyebut
 * metode.
 *
 * Obatnya profil menyatakan nomornya sendiri lewat konstanta `KODE_METODE`,
 * dan berkas ini menuntut SETIAP profil melakukannya — jadi alat ke-22 yang
 * lupa menyatakannya merah di sini, bukan terbit dengan kolom kosong.
 */
class MetodeKalibrasiSemuaProfilTest extends TestCase
{
    use RefreshDatabase;

    /** `SIDIK-IK-CAL-0505_Rev.7` — nomor DAN revisinya, keduanya wajib. */
    private const POLA = '/^SIDIK-IK-CAL-\d{4}_Rev\.\d+$/';

    /** Tiap profil yang terdaftar wajib menyatakan nomor IK-nya. */
    public function test_setiap_profil_menyatakan_nomor_ik_lengkap(): void
    {
        $profil = app(CalibrationProfileRegistry::class)->semua();

        $this->assertNotEmpty($profil, 'Registry profil kosong — sapuan ini jadi nggak menguji apa pun.');

        foreach ($profil as $p) {
            $kode = $p->kodeMetode();

            $this->assertNotNull(
                $kode,
                "Profil `{$p->kode()}` nggak menyatakan `KODE_METODE`. Tanpa itu kolom "
                .'`Calibration Method` sertifikatnya bergantung pada nama alat yang diketik '
                .'pelanggan, dan terbit kosong begitu namanya di luar kosakata master.',
            );

            $this->assertMatchesRegularExpression(
                self::POLA,
                $kode,
                "Nomor IK profil `{$p->kode()}` nggak lengkap — revisinya ikut dicetak di sertifikat.",
            );
        }
    }

    /**
     * Dan yang beneran mendarat di snapshot juga lengkap.
     *
     * Menyatakan konstantanya belum cukup: yang dicetak lewat
     * `metodeKalibrasi()`, dan di situ ada dua jalur lain (metode pilihan admin
     * dan cadangan pencocokan nama) yang bisa mendahuluinya.
     */
    public function test_semua_sesi_contoh_terbit_dengan_nomor_ik_lengkap(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::with('equipment')->get();

        $this->assertGreaterThan(15, $sesi->count(), 'Sesi contohnya kurang — sapuannya jadi sempit.');

        foreach ($sesi as $s) {
            $sertifikat = new Certificate([
                'organization_id' => $s->organization_id,
                'calibration_session_id' => $s->id,
                'nomor' => 'UJI-'.$s->id,
                'qr_token' => substr(md5('uji'.$s->id), 0, 10),
            ]);

            $metode = app(CertificateSnapshotBuilder::class)
                ->bangun($s, $sertifikat)['header']['calibration_method'] ?? null;

            $this->assertMatchesRegularExpression(
                self::POLA,
                (string) $metode,
                sprintf(
                    'Sesi %s (alat "%s") terbit dengan metode %s.',
                    $s->nomor_sesi,
                    $s->equipment?->nama_alat,
                    var_export($metode, true),
                ),
            );
        }
    }
}
