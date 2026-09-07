<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\Calibration\Profiles\HeightGaugeProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Yang TERCETAK di sertifikat Height Gauge — bukan hitungannya, itu urusan
 * `HeightGaugeMasterTest`.
 *
 * ## Kenapa terpisah dari test hitungan
 *
 * Di Micrometer, seluruh budget cocok master sampai 5·10⁻⁶ sementara
 * sertifikatnya mencetak `0,00027` (mm) dan `0,871` (µm) di KOLOM YANG SAMA,
 * dengan kepala kolom telanjang tanpa satuan. Nol error di seluruh jalur;
 * ketahuan cuma waktu snapshot-nya beneran diperiksa.
 *
 * Di sini taruhannya sama tapi arahnya kebalik: budget Height Gauge memang
 * **mm**, jadi yang berbahaya justru kalau ada yang menyeret konversi ÷1000
 * dari `MicrometerProfile` — U95 tercetak seribu kali terlalu kecil, dan tidak
 * ada lantai CMC yang menahannya.
 */
class HeightGaugeSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /** Sesi contoh yang ditanam `HeightGaugeSeeder` (`INPUT DATA!Q5`). */
    private const NOMOR_SESI = '001-UBLK-05.26';

    /**
     * Kesepuluh baris `SERTIFIKAT!D24:L33` master: standar, UUT, koreksi.
     *
     * Yang TIDAK diadu di sini U95-nya, karena angka itu memang SENGAJA
     * berbeda: master menghitung drift dari `NOW()`-nya sendiri sementara kita
     * dari tanggal kalibrasi sesi. Lihat
     * [test_satu_baris_u95_untuk_sepuluh_titik].
     *
     * @return list<array{float, float, float}>
     */
    private static function master(): array
    {
        return [
            [25.0008, 24.986666666666665, 0.014133333333337106],
            [50.0004, 49.98666666666667, 0.01373333333332738],
            [100.0005, 99.98, 0.02049999999999841],
            [150.0003, 149.97666666666666, 0.023633333333350492],
            [199.9993, 199.98333333333335, 0.015966666666656693],
            [299.99955, 299.9766666666667, 0.022883333333311384],
            [399.9982, 399.97333333333336, 0.024866666666639503],
            [499.99865, 499.97, 0.028649999999970532],
            [549.9978, 549.9666666666667, 0.031133333333286828],
            [599.9973, 599.9633333333334, 0.03396666666662895],
        ];
    }

    /** @return array{CalibrationSession, array<string, mixed>} */
    private function terbitkan(): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $sesi = CalibrationSession::where('nomor_sesi', self::NOMOR_SESI)->firstOrFail();

        // `abaikan_peringatan` memang diperlukan, dan itu BUKAN kelemahan sesi
        // contohnya: `HeightGaugeProfile::peringatanSesi()` selalu memunculkan
        // `height_gauge_diluar_akreditasi` karena alat ini memang di luar
        // lampiran LK-285-IDN. Peringatan itu benar dan sengaja tidak
        // dihilangkan.
        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        return [$sesi->fresh(), $sesi->fresh()->certificate()->firstOrFail()->snapshot];
    }

    /** Kesepuluh baris cocok `SERTIFIKAT!D24:L33` master. */
    public function test_sepuluh_baris_cocok_sertifikat_master(): void
    {
        [, $snapshot] = $this->terbitkan();

        $this->assertCount(10, $snapshot['hasil'], 'Sertifikat master mencetak SEPULUH titik.');

        foreach ($snapshot['hasil'] as $i => $baris) {
            [$standar, $uut, $koreksi] = self::master()[$i];

            $this->assertEqualsWithDelta($standar, $baris['standard_value'], self::TOLERANSI, "Standar baris {$i}");
            $this->assertEqualsWithDelta($uut, $baris['unit_under_test'], self::TOLERANSI, "UUT baris {$i}");
            $this->assertEqualsWithDelta($koreksi, $baris['correction'], self::TOLERANSI, "Koreksi baris {$i}");
        }
    }

    /**
     * SATU baris `Uncertainty U95% = ±` untuk sepuluh titik.
     *
     * Budget alat ini lahir sekali per SESI (sheet `PERHITUNGAN U95%` cuma
     * punya satu kolom), jadi kesepuluh baris wajib membawa angka yang sama
     * persis. Kalau berbeda-beda, berarti ada yang menghitungnya per titik —
     * dan itu bentuk yang tidak ada di master.
     */
    public function test_satu_baris_u95_untuk_sepuluh_titik(): void
    {
        [, $snapshot] = $this->terbitkan();

        $u95 = array_map(static fn (array $b): float => (float) $b['u95'], $snapshot['hasil']);

        $this->assertCount(1, array_unique($u95), 'U95 wajib SAMA di kesepuluh titik — dia lahir per sesi.');

        // 0,0156260 mm — angka sesi kita, yang memakai umur drift dari TANGGAL
        // KALIBRASI SESI (116 hari). Master mencetak 0,0156680 mm karena
        // menghitungnya dari `NOW()`-nya sendiri (153,66 hari); selisihnya
        // 0,27 % dan seluruhnya berasal dari tanggal, bukan pengukuran.
        $this->assertEqualsWithDelta(0.0156260, $u95[0], self::TOLERANSI);
    }

    /**
     * U95 tercetak dalam **mm**, bukan µm — dan kepala kolomnya menyebutkannya.
     *
     * Orde besarannya ikut dijaga: nilai dalam µm bakal ~1000× lebih besar dan
     * tetap lolos ambang mana pun yang cuma "lebih besar dari nol".
     */
    public function test_satuan_mm_tercetak_dan_u95_bukan_mikrometer(): void
    {
        [, $snapshot] = $this->terbitkan();

        $this->assertSame(
            'mm',
            $snapshot['hasil'][0]['satuan'] ?? null,
            'Kepala kolom tanpa satuan bikin koreksi (0,014) dan U95 (0,0156) terbaca tanpa acuan.',
        );

        $this->assertLessThan(
            0.1,
            (float) $snapshot['hasil'][0]['u95'],
            'U95 tercetak dalam µm — di kolom yang sama dengan koreksi, angka itu terbaca seribu '
            .'kali lebih besar dari sebenarnya.',
        );
    }

    /**
     * TIDAK ada lantai CMC: yang tercetak `U` hitung telanjang.
     *
     * Kalau suatu saat ada yang memungut `CMC_UTM` master (`CMC 0-300mm =
     * 15 µm`) sebagai lantai, sertifikatnya mengklaim kemampuan untuk lingkup
     * yang TIDAK diakreditasi.
     */
    public function test_u95_tidak_dinaikkan_lantai_cmc(): void
    {
        [$sesi, $snapshot] = $this->terbitkan();

        $hitungan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->firstOrFail();

        $this->assertEqualsWithDelta(
            (float) $hitungan->ketidakpastian_gabungan * (float) $hitungan->faktor_cakupan_k,
            (float) $snapshot['hasil'][0]['u95'],
            self::TOLERANSI,
            'U95 yang tercetak wajib sama persis dengan `k · uc` — tidak ada `max(U, CMC)` di alat ini.',
        );
    }

    /**
     * Lembar KERJA-nya tidak membawa nomor lingkup akreditasi.
     *
     * Empat profil lain memasang `LK-285-IDN` di kop lembarnya. Height Gauge
     * tidak boleh, karena alatnya di luar lampiran itu.
     */
    public function test_lembar_kerja_tidak_membawa_nomor_lingkup(): void
    {
        $bentuk = (new HeightGaugeProfile)->bentukLembarKerja();

        $this->assertArrayNotHasKey(
            'nomor_lingkup',
            $bentuk,
            'Height Gauge di luar lampiran LK-285-IDN — kop lembarnya tidak boleh mencetak nomor '
            .'lingkup akreditasi.',
        );
        $this->assertNull($bentuk['kode_dokumen'], 'Kertas lembar kerjanya belum turun dari lab.');
    }

    /**
     * Sertifikatnya TIDAK membawa klaim akreditasi.
     *
     * Sampai 7 Sep 2026 dia membawanya: klaim akreditasi dicetak TANPA SYARAT
     * di tingkat organisasi, tanpa satu pun pemeriksaan alat, dan Gas Detector
     * sudah kena hal yang sama sejak alat ke-10. Mencetak "Terakreditasi ... No.
     * LK-285-IDN" untuk lingkup yang tidak diakreditasi adalah temuan audit
     * KAN — bukan bug tampilan.
     *
     * Yang diperiksa di sini SNAPSHOT-nya, bukan HTML-nya, dan itu disengaja:
     * snapshot yang beku itu satu-satunya yang dibaca cetak ulang tahun depan.
     * Sisi HTML-nya dijaga `KlaimAkreditasiIkutLingkupTest`.
     */
    public function test_sertifikat_tidak_membawa_klaim_akreditasi(): void
    {
        [, $snapshot] = $this->terbitkan();

        // `meta.organization` — blok yang dibekukan
        // `CertificateSnapshotBuilder` ke tiap snapshot.
        $organisasi = $snapshot['meta']['organization'] ?? [];

        $this->assertNull(
            $organisasi['no_akreditasi'] ?? null,
            'Height Gauge di luar lampiran LK-285-IDN — nomor akreditasi tidak boleh ikut '
            .'dibekukan ke snapshot-nya.',
        );
        $this->assertNull($organisasi['standar_akreditasi'] ?? null);
        $this->assertFalse(
            $organisasi['dalam_lingkup_akreditasi'] ?? null,
            'Penanda lingkupnya wajib ikut dibekukan — dia yang membedakan "di luar lingkup" '
            .'dari "organisasinya belum mengisi nomor akreditasi".',
        );

        // Nama lab TETAP tercetak — dia bukan klaim akreditasi, dan sertifikat
        // tanpa identitas penerbit tidak berguna buat siapa pun.
        $this->assertNotEmpty($organisasi['nama'] ?? null);
    }
}
