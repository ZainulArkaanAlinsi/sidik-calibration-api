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
 * Disapu 1 Sep 2026 dengan tinggi `.ttd .ruang-ttd` 46 -> 136px. Yang paling
 * mepet **Conductivity Meter**: masih normal di 86px, kena demosi ke padat di
 * 88px. Nilai yang dipakai 80px.
 */
class SertifikatSemuaAlatSatuHalamanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Alat yang lembarnya memang nggak muat di mode normal, dan sudah begitu
     * SEBELUM kotak TTD digedein — dipastikan dengan menyapu ulang di 46px.
     *
     * @var list<string>
     */
    private const BUTUH_PADAT = [
        'Autoclave',
        'Chlorine Meter',
        'Multi Gas Detector',
        'Temperature Calibrator',
    ];

    public function test_semua_sertifikat_bawaan_muat_satu_halaman(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $tampilan = app(DataTampilanSertifikat::class);
        $meluap = [];
        $padat = [];
        $diperiksa = 0;

        foreach (CalibrationSession::query()->get() as $sesi) {
            $sertifikat = $this->terbitkan($sesi);

            if (! $sertifikat instanceof Certificate) {
                continue;
            }

            $diperiksa++;
            $bahan = $tampilan->untuk($sertifikat);
            $alat = (string) ($sertifikat->snapshot['header']['equipment_name'] ?? "sertifikat #{$sertifikat->getKey()}");

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

        $this->assertGreaterThan(
            10,
            $diperiksa,
            'Sesi bawaannya nggak keterbit — test ini jadi hijau tanpa memeriksa apa pun.',
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

    /** @param  array<string, mixed>  $bahan */
    private function halaman(array $bahan, bool $paksaPadat): int
    {
        $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat]);

        // `output()` dipanggil DULU: jumlah halaman baru ada sesudah dompdf
        // benar-benar merender, dan `getCanvas()` sebelum itu balik nol.
        $pdf->output();

        return (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
    }

    private function terbitkan(CalibrationSession $sesi): ?Certificate
    {
        $ada = $sesi->certificate()->first();

        if ($ada !== null) {
            return $ada;
        }

        // Sesi bawaan nggak semuanya siap terbit (ada yang sengaja ditinggal
        // draft). Yang nggak bisa disetujui dilewat, bukan bikin test merah.
        try {
            $this->postJson("/api/calibrations/{$sesi->id}/approve");
        } catch (\Throwable $e) {
            return null;
        }

        return $sesi->fresh()->certificate()->first();
    }
}
