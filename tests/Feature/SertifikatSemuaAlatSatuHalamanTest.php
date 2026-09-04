<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Setiap sertifikat, semua alat, dirender BENERAN — dan dua hal dijaga sekaligus.
 *
 * ## Kenapa test ini ada, padahal `SertifikatSatuHalamanTest` sudah ada
 *
 * Yang itu menguji ATURAN PEMILIHANNYA dengan dompdf dipalsukan: kapan
 * memadatkan ulang, kapan menyerah, kapan mencatat error. Cepat, dan memang
 * begitu seharusnya.
 *
 * Yang nggak bisa dijawab test itu: apakah lembarnya BENERAN muat. Itu
 * pertanyaan tentang tinggi kop, ukuran huruf, jumlah baris, dan tinggi ruang
 * tanda tangan — semuanya cuma kelihatan kalau dompdf-nya beneran jalan.
 *
 * ## Yang dijaga #1 — semuanya satu halaman
 *
 * Header sertifikat mencetak `Page : 1 of 1` dari angka yang ditulis mati di
 * snapshot. Lembar yang meluap tetap mencetak "1 of 1" di KEDUA halamannya:
 * dokumen terkendali yang menyatakan hal yang tidak benar tentang dirinya
 * sendiri.
 *
 * ## Yang dijaga #2 — dan ini yang sebenarnya gampang jebol
 *
 * Versi pertama test ini cuma menjaga #1, dan itu **tetap hijau waktu tinggi
 * kotak TTD gw naikin ke 130px buat nguji** — karena `SertifikatSatuHalaman`
 * menyelamatkannya dengan memaksa mode padat. Mode padat punya ruang sisa
 * banyak, jadi #1 nyaris nggak pernah merah.
 *
 * Tapi "diselamatkan mode padat" itu BUKAN netral: hurufnya mengecil, jarak
 * menyempit, dan sertifikat alat itu jadi beda bentuk dari sertifikat alat
 * lain di lab yang sama. Perubahan tata letak yang mendemosikan satu alat ke
 * mode padat nggak menghasilkan error apa pun — persis kelas kegagalan yang
 * bikin repo ini nulis aturan "yang nggak error itu yang paling mahal".
 *
 * Jadi yang dipatok di sini DAFTARNYA: alat mana saja yang butuh mode padat.
 * Nambah satu nama = ada perubahan tata letak yang mengecilkan huruf satu alat.
 *
 * ## Ambang yang berlaku sekarang
 *
 * Kotak normal: yang paling mepet **Conductivity Meter** — masih normal di
 * 86px, kena demosi ke padat di 88px. Nilai yang dipakai 80px.
 *
 * Kotak padat: **Visible Spectrofotometer** meluap ke halaman dua begitu lewat
 * 30px, jadi 24px dipertahankan. Dia lembar terpadat di sistem (24 titik
 * ketidakpastian), dan di bawah mode padat nggak ada jaring pengaman lagi.
 *
 * Ambang kedua itu cuma ketahuan sesudah sapuannya berhenti melewat diam-diam —
 * lihat `terbitkan()`.
 */
class SertifikatSemuaAlatSatuHalamanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Semua sesi bawaan yang harus keterbit, `nomor_sesi (nama alat)`.
     *
     * Dipatok, bukan dihitung. Sapuan yang daftarnya datang dari database punya
     * satu cara gagal yang nggak bersuara: sesi yang berhenti bisa disetujui
     * hilang dari daftar, dan test-nya tetap hijau — cuma memeriksa lebih
     * sedikit. Nama yang HILANG dari daftar ini berarti sertifikat alat itu
     * berhenti terbit, dan itu jauh lebih besar dari urusan tata letak.
     *
     * @var list<string>
     */
    private const DIPERIKSA = [
        '002-UB.P-11-20 (Outside Micrometer)',
        '003-UB.P-11-20 (Digital Outside Micrometer)',
        '0106-CAL-1023 (Micrometer Digital)',
        '011-CAL-525 (Timbangan)',
        '0133-CAL-324 (Centrifuge)',
        '0135-CAL-125 (Thermometer Glass)',
        '0136-CAL-123 (Timbangan Elektronik)',
        '0140-CAL-424 (Digital Tachometer)',
        '015-CAL-424 (Stopwatch)',
        '019-CAL-425 (Moisture Analyzer)',
        '0312-CAL-624 (Thermohygrometer)',
        '0513-CAL-1124 (Thermocouple Thermometer)',
        '2211.11.R (Refractometer)',
        '22506.01.A (Temperature Calibrator)',
        '2405.03.AV (Incubator)',
        '2405.13.A (pH Meter)',
        '2405.32.A.NK (Conductivity Meter)',
        '2406.25.AI (Oven)',
        '2406.32.A (Turbidimeter)',
        '2406.32.C (Chlorine Meter)',
        '2406.50.S (DO Meter)',
        '2406.51.S (Autoclave)',
        '2602.03.A (Multi Gas Detector)',
        '2606.08.C (Temperature Recorder Controller)',
        '2607.59.W (Viscometer)',
        'DEMO-COND-MSCM (Conductivity Meter)',
        'DEMO-SPECTRO-LDC (Visible Spectrofotometer)',
    ];

    /**
     * Alat yang lembarnya memang nggak muat di mode normal, dan sudah begitu
     * SEBELUM kotak TTD digedein — dipastikan dengan menyapu ulang di 46px.
     *
     * @var list<string>
     */
    private const BUTUH_PADAT = [
        // KETIGA sesi Micrometer masuk daftar ini, dan sebabnya jumlah baris:
        // sertifikat masternya mencetak SEBELAS titik ukur, kedua terbanyak
        // sesudah Thermohygrometer. Bukan gejala tata letak yang menyusut —
        // lembarnya memang panjang sejak sesi contohnya lahir.
        '002-UB.P-11-20 (Outside Micrometer)',
        '003-UB.P-11-20 (Digital Outside Micrometer)',
        '0106-CAL-1023 (Micrometer Digital)',
        '0312-CAL-624 (Thermohygrometer)',
        '22506.01.A (Temperature Calibrator)',
        '2406.32.C (Chlorine Meter)',
        '2406.51.S (Autoclave)',
        '2602.03.A (Multi Gas Detector)',
        '2606.08.C (Temperature Recorder Controller)',
    ];

    /**
     * Tiga hal sekaligus, dan namanya cuma menyebut yang pertama:
     *
     *   1. Tiap sesi bawaan BISA diterbitkan — daftarnya dipatok di `DIPERIKSA`.
     *   2. Nggak ada lembar yang meluap, bahkan sesudah mode padat dipaksa.
     *   3. Daftar alat yang BUTUH mode padat nggak berubah.
     *
     * Yang ketiga yang paling gampang jebol tanpa suara, dan alasannya ada di
     * docblock kelas.
     */
    public function test_semua_sertifikat_bawaan_muat_satu_halaman(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $tampilan = app(DataTampilanSertifikat::class);
        $meluap = [];
        $padat = [];
        $diperiksa = [];

        foreach (CalibrationSession::query()->get() as $sesi) {
            $sertifikat = $this->terbitkan($sesi);

            $diperiksa[] = $this->sebut($sesi, $sertifikat);
            $bahan = $tampilan->untuk($sertifikat);
            $alat = $this->sebut($sesi, $sertifikat);

            // Urutan yang sama persis dengan SertifikatSatuHalaman. Disalin —
            // bukan dipanggil — karena yang dicari di sini justru CABANG MANA
            // yang kepakai, dan layanannya cuma mengembalikan isi PDF-nya.
            $halaman = $this->halaman($bahan, false);

            if ($halaman > 1) {
                $padat[] = $alat;
                $halaman = $this->halaman($bahan, true);
            }

            if ($halaman > 1) {
                $meluap[] = $alat;
            }
        }

        sort($diperiksa);

        $this->assertSame(
            self::DIPERIKSA,
            $diperiksa,
            "Daftar sertifikat yang diperiksa berubah.\n"
            .'Nama yang HILANG = sesi itu berhenti bisa diterbitkan, dan sapuan ini jadi diam-diam '
            ."memeriksa lebih sedikit — persis cara test sapuan gagal tanpa bersuara.\n"
            .'Nama BARU = seeder nambah sesi; perbarui DIPERIKSA.',
        );

        $this->assertSame(
            [],
            $meluap,
            'Meluap ke halaman dua walau mode padat dipaksa: '.implode(', ', $meluap),
        );

        sort($padat);

        $this->assertSame(
            self::BUTUH_PADAT,
            $padat,
            "Daftar alat yang butuh mode padat berubah.\n"
            .'Nama BARU di daftar = perubahan tata letak baru saja mengecilkan huruf sertifikat alat itu, '
            ."tanpa error apa pun. Tersangka pertama: tinggi `.ttd .ruang-ttd` atau ukuran huruf.\n"
            .'Kalau perubahannya memang disengaja, perbarui BUTUH_PADAT berikut alasannya.',
        );
    }

    /**
     * Jumlah halaman hasil render — angka dari dompdf, bukan perkiraan.
     *
     * @param  array<string, mixed>  $bahan
     */
    private function halaman(array $bahan, bool $paksaPadat): int
    {
        $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat]);

        // `output()` dipanggil DULU: jumlah halaman baru ada sesudah dompdf
        // benar-benar merender, dan `getCanvas()` sebelum itu balik nol.
        $pdf->output();

        return (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    /** `nomor_sesi (nama alat)` — unik, dan kebaca waktu test-nya merah. */
    private function sebut(CalibrationSession $sesi, Certificate $sertifikat): string
    {
        $alat = $sertifikat->snapshot['header']['equipment_name'] ?? '(alat?)';

        return "{$sesi->nomor_sesi} ({$alat})";
    }

    /**
     * Terbitkan sertifikatnya, dan GAGALKAN test kalau nggak bisa.
     *
     * Versi pertama fungsi ini membungkus `postJson()` dengan try/catch lalu
     * mengembalikan null. Itu dua kesalahan sekaligus, dan CodeRabbit yang
     * menangkapnya: `postJson()` **nggak melempar** pada 422, jadi catch-nya
     * praktis mati — dan yang gagal disetujui dilewat diam-diam, sementara
     * ambang lama (`> 10`) bikin test tetap hijau walau tinggal 11 sertifikat
     * yang benar-benar diperiksa.
     *
     * ## Kenapa `abaikan_peringatan: true`
     *
     * Tujuh sesi bawaan (Viscometer, Spectro, Thermohygrometer, Digital
     * Tachometer, Centrifuge, Temperature Recorder, dan Conductivity kedua)
     * balik 422 `butuh_konfirmasi` — BUKAN karena hitung ulangnya beda, tapi
     * karena punya peringatan domain seperti `pembacaan_di_luar_rentang` atau
     * `centrifuge_di_luar_akreditasi`. Di alur aslinya admin menyetujuinya
     * dengan konfirmasi eksplisit, dan itu yang ditiru di sini.
     *
     * Yang dibeli: sapuannya naik dari 17 jadi 24 sertifikat — tujuh alat yang
     * tadinya nggak pernah dirender sekali pun sekarang ikut dijaga.
     *
     * `boleh_terbit: false` tetap bikin merah, dan memang harus: itu penolakan
     * keras, bukan peringatan yang boleh dikonfirmasi.
     */
    private function terbitkan(CalibrationSession $sesi): Certificate
    {
        $ada = $sesi->certificate()->first();

        if ($ada !== null) {
            return $ada;
        }

        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $sertifikat = $sesi->fresh()->certificate()->first();

        $this->assertInstanceOf(
            Certificate::class,
            $sertifikat,
            "Sesi {$sesi->nomor_sesi} disetujui tapi sertifikatnya nggak kebentuk.",
        );

        return $sertifikat;
    }
}
