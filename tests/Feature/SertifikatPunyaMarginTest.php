<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tiap sertifikat harus MASIH muat satu halaman sesudah ditambah ruang kosong.
 *
 * ## Kenapa "muat" saja tidak cukup
 *
 * `SertifikatSemuaAlatSatuHalamanTest` menjawab "muat atau tidak". Jawaban itu
 * hijau terus sampai hari dia tidak hijau — dan hari itu sertifikatnya sudah di
 * tangan pelanggan. Lembar yang muat dengan sisa 2 px dan lembar yang muat
 * dengan sisa 100 px sama-sama "muat", padahal yang pertama akan jatuh ke
 * halaman dua begitu nama pelanggannya satu baris lebih panjang.
 *
 * Dan jatuhnya TIDAK menghasilkan error: header mencetak `Page : 1 of 1` dari
 * angka yang ditulis mati di snapshot, jadi lembar dua halaman tetap mengaku
 * satu halaman. Dokumen terkendali yang menyatakan hal yang tidak benar tentang
 * dirinya sendiri.
 *
 * ## Caranya
 *
 * Lembarnya dirender apa adanya, lalu satu kotak kosong setinggi [MARGIN_MIN]
 * disisipkan sebelum `</body>`. Kalau masih satu halaman, marginnya minimal
 * segitu. Blade-nya TIDAK disentuh — HTML-nya dirender dulu lalu disisipi, jadi
 * tidak ada kait khusus-test yang tertinggal di lembar produksi.
 *
 * ## Angka yang diukur, 10 Sep 2026
 *
 * Margin sesungguhnya tiap sesi contoh (px, hasil pencarian biner 0..160):
 *
 *     8   DEMO-FM-GRAV-TOT-001   <- paling mepet di seluruh sistem
 *     15  DEMO-SPECTRO-NIAGA
 *     20  0135-CAL-125
 *     23  2607.59.W
 *     33  DEMO-FM-FLW-001, DEMO-FM-TOT-001
 *     45+ sisanya
 *     160 kesebelas sesi mode padat (batas pencarian, jadi >= 160)
 *
 * [MARGIN_MIN] dipatok **6 px** — di bawah yang paling mepet, supaya sapuan ini
 * hijau hari ini dan tetap menggigit besok. Menaikkannya berarti menuntut
 * perubahan tata letak lebih dulu, bukan sekadar menyetel angka.
 *
 * Kalau sapuan ini merah, yang berubah BUKAN test-nya: ada lembar yang tumbuh,
 * dan dia sekarang berdiri lebih dekat ke tepi daripada sebelumnya.
 */
class SertifikatPunyaMarginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ruang kosong (px) yang harus MASIH tertampung tiap lembar.
     *
     * Enam, bukan angka bulat yang enak dilihat: sertifikat paling mepet
     * (`DEMO-FM-GRAV-TOT-001`) punya 8 px, dan ambang yang menyentuhnya membuat
     * sapuan ini merah sejak hari pertama tanpa ada yang rusak.
     */
    private const MARGIN_MIN = 6;

    /**
     * Sertifikat paling mepet, dipatok supaya penyusutannya kebaca.
     *
     * Bukan sekadar catatan: kalau suatu saat ada lembar LAIN yang lebih mepet
     * dari ini, berarti ada tata letak yang menyusut tanpa ada yang menyadarinya.
     */
    private const PALING_MEPET = 'DEMO-FM-GRAV-TOT-001';

    /**
     * Dua hal sekaligus, dan sengaja SATU test.
     *
     * Merender lembar sertifikat itu mahal (~5 detik untuk 31 sesi sekali
     * sapu). Dipecah jadi dua test, tiap lembar dirender dua kali untuk
     * pertanyaan yang jawabannya datang dari render yang sama — dan suite
     * penuh membayarnya tiap kali jalan.
     */
    public function test_tiap_sertifikat_punya_ruang_sisa(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());

        $tampilan = app(DataTampilanSertifikat::class);
        $mepet = [];
        $lebihMepetDariJuara = [];

        foreach (CalibrationSession::query()->get() as $sesi) {
            $sertifikat = $this->terbitkan($sesi);

            if ($sertifikat === null) {
                continue;
            }

            $bahan = $tampilan->untuk($sertifikat);
            // Mode padat dipilih dengan aturan yang sama seperti produksi:
            // dicoba normal dulu, dipadatkan cuma kalau meluap.
            $padat = $this->halaman($bahan, false, 0) > 1;

            if ($this->halaman($bahan, $padat, self::MARGIN_MIN) > 1) {
                // Sisa ruang SESUNGGUHNYA ikut dilaporkan, bukan cuma namanya.
                //
                // Sapuan ini pernah merah sekali di suite penuh untuk
                // `0136-CAL-123`, lalu hijau di jalan penuh berikutnya, hijau
                // sendirian, dan hijau bersama test tetangganya. Yang bikin
                // penyebabnya tidak ketemu: pesannya cuma menyebut nama, jadi
                // tidak ada cara membedakan "lembarnya memang berdiri di tepi"
                // dari "ada yang menambah lima baris".
                //
                // Angkanya menjawab itu dalam sekali lihat. Diukur 11 Sep 2026
                // di KEDUA mesin, hasilnya identik — jadi selisih apa pun dari
                // baseline di bawah berarti isinya yang berubah, bukan
                // presisinya:
                //
                //     8   DEMO-FM-GRAV-TOT-001    46  2405.32.A.NK
                //     15  DEMO-SPECTRO-NIAGA      46  DEMO-COND-MSCM
                //     20  0135-CAL-125            50  2405.03.AV
                //     23  2607.59.W               66  2405.13.A
                //     34  DEMO-FM-TOT-001         86  DEMO-TIDS-001 · 015-CAL-424
                //     34  DEMO-FM-FLW-001         87  0136-CAL-123 · 011-CAL-525
                //     46  2406.32.A              112+ sisanya
                //
                // Pencarian binernya cuma jalan waktu GAGAL, jadi sapuan yang
                // hijau tidak ikut membayar.
                $mepet[] = sprintf(
                    '%s (%s) — sisa %d px, baseline 11 Sep lihat docblock',
                    $sesi->nomor_sesi,
                    $padat ? 'padat' : 'normal',
                    $this->ukurMargin($bahan, $padat),
                );

                continue;
            }

            // Ambang di ATAS margin si juara (8 px). Yang tidak lolos di sini
            // berarti berdiri lebih dekat ke tepi daripada dia.
            if ($sesi->nomor_sesi !== self::PALING_MEPET && $this->halaman($bahan, $padat, 10) > 1) {
                $lebihMepetDariJuara[] = $sesi->nomor_sesi;
            }
        }

        $this->assertSame([], $mepet, sprintf(
            'Sertifikat berikut muat satu halaman TAPI tanpa ruang sisa %d px — satu baris tambahan '
            .'(nama pelanggan lebih panjang, satu titik ukur lagi, satu baris standar) menjatuhkannya '
            .'ke halaman dua, dan header-nya tetap mencetak `Page : 1 of 1`:'.PHP_EOL.'  %s',
            self::MARGIN_MIN,
            implode(PHP_EOL.'  ', $mepet),
        ));

        $this->assertSame([], $lebihMepetDariJuara, sprintf(
            'Sertifikat ini sekarang lebih mepet daripada `%s` yang selama ini paling mepet: %s. '
            .'Ada tata letak yang tumbuh — periksa apa yang bertambah sebelum menyetel ambangnya.',
            self::PALING_MEPET,
            implode(', ', $lebihMepetDariJuara),
        ));
    }

    /**
     * Sisa ruang sesungguhnya (px), dicari biner 0..200.
     *
     * Dipanggil CUMA dari jalur gagal — merender lembar dua puluh kali untuk
     * tiap sertifikat yang sehat akan melipatgandakan waktu sapuan ini tanpa
     * menjawab apa pun.
     *
     * @param  array<string, mixed>  $bahan
     */
    private function ukurMargin(array $bahan, bool $padat): int
    {
        $bawah = 0;
        $atas = 200;

        while ($bawah < $atas) {
            $tengah = (int) ceil(($bawah + $atas) / 2);

            if ($this->halaman($bahan, $padat, $tengah) <= 1) {
                $bawah = $tengah;
            } else {
                $atas = $tengah - 1;
            }
        }

        return $bawah;
    }

    private function terbitkan(CalibrationSession $sesi): ?object
    {
        $ada = $sesi->certificate()->first();

        if ($ada !== null) {
            return $ada;
        }

        $this->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true]);

        return $sesi->fresh()->certificate()->first();
    }

    /**
     * Lembarnya dirender, lalu disisipi kotak kosong setinggi `$sisipPx`.
     *
     * `loadHTML()` dan bukan `loadView()`: HTML-nya dirender dulu supaya
     * sisipannya bisa ditaruh tanpa menambahkan kait khusus-test ke
     * `sertifikat/pdf.blade.php`. Lembar produksi tetap bersih.
     *
     * @param  array<string, mixed>  $bahan
     */
    private function halaman(array $bahan, bool $paksaPadat, int $sisipPx): int
    {
        $html = view('sertifikat.pdf', [...$bahan, 'paksaPadat' => $paksaPadat])->render();

        if ($sisipPx > 0) {
            $html = str_replace(
                '</body>',
                sprintf('<div style="height:%dpx"></div></body>', $sisipPx),
                $html,
            );
        }

        $pdf = Pdf::loadHTML($html);
        // `output()` dipanggil DULU: jumlah halaman baru ada sesudah dompdf
        // benar-benar merender, dan `getCanvas()` sebelum itu balik nol.
        $pdf->output();

        return (int) $pdf->getDomPDF()->getCanvas()->get_page_count();
    }
}
