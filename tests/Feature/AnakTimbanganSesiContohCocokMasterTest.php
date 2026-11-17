<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Standard;
use App\Models\User;
use App\Services\DataTampilanSertifikat;
use App\Support\AnakTimbanganMentah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MenegakkanCetakMaster;
use Tests\Concerns\MenerbitkanSesiContoh;
use Tests\Concerns\PembandingCetakMaster;
use Tests\TestCase;

/**
 * Angka CETAK sertifikat Anak Timbangan F1 dan F2 diadu ke lembar `SERTIFIKAT`
 * workbook master lewat renderer asli. PK-4, 17 Nov 2026.
 *
 * ## F1 (`1.1 Anak Timbangan F1 1mg-500 g ... imp.xlsx`)
 *
 * Sesi `DEMO-AT-001` (seeder, 14 dari 20 keping). Enam keping master sengaja
 * tidak ikut (`pertanyaan-lab-anak-timbangan.md` §3 & §4): lima mencetak
 * `#VALUE!` di master, satu (10 g) terbit 5,5002 g karena salah ketik. Tiga
 * belas dari empat belas keping yang ikut mencetak **massa konvensional dan U
 * (mg) persis sama** dengan master; satu keping (0,1 g) beda:
 *
 *  - **0,1 g**: master 0,0988 g, aplikasi 0,1000 g. Aplikasi meniru rumus sel
 *    master per NOMOR KOTAK (kotak 2-4 koreksi apung 0, kotak 5+ faktor
 *    keping itu x massa keping kotak 1; keputusan pemilik 8 Okt 2026,
 *    §2 & §5). Di master keping 0,1 g adalah kotak 9; sesi yang memuat 14
 *    keping menempatkannya di kotak 4 karena lima kotak `#VALUE!` di depannya
 *    tidak ikut. Artefak sampel, bukan salah hitung — tetapi inilah mengapa
 *    angka cetak keping bergantung pada URUTAN kotak (§2 tetap terbuka).
 *
 * ## F2 (`Anak timbangan F2_IMTE-LQ-197 hal 1/2/3.xlsx`)
 *
 * Tiga halaman master = satu set 12 keping di tiga neraca. Sesinya dibangun
 * lewat API dari masukan `INPUT DATA` master (`docs/skrip/gen-cetak-master-
 * massa-volume.py`, blok 1-8 hal 1, 1-3 hal 2, 1 hal 3) persis jalur HP.
 * Dua belas keping itu mencetak massa konvensional dan U persis master.
 * Baris master di bawahnya (hal 1 baris 25-36, hal 2 baris 20-36, hal 3
 * baris 18-36) adalah salinan sesi lain (§30) dan tidak diadu.
 *
 * ## PK4-8: bintang nominal kembar
 *
 * Pencocokan angka membuang `*` (master `200*`, `20*`); aplikasi tidak mencetaknya sama
 * sekali. Selisih itu bukan "diabaikan" — `assertBintangKembarTidakDicetak()` mengunci
 * keadaannya sekarang (lihat docblock-nya) dan `docs/pertanyaan-lab-adu-cetak-massa-volume.md`
 * PK4-8 mencatatnya, merujuk `pertanyaan-lab-anak-timbangan.md` §26.
 */
class AnakTimbanganSesiContohCocokMasterTest extends TestCase
{
    use MenegakkanCetakMaster;
    use MenerbitkanSesiContoh;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSesiContoh();
    }

    public function test_f1_empat_belas_keping_sama_dengan_master_kecuali_nomor_kotak(): void
    {
        [, $html] = $this->terbitkanDanRender('DEMO-AT-001');

        $this->assertCetakSamaMaster(
            'anak_timbangan/f1',
            $html,
            jumlahCocok: 14,
            selisihDiketahui: [
                25 => ['Keping 0,1 g: kotak 4 di sesi 14 keping (koreksi apung 0), kotak 9 di master. Artefak sampel, §2/§5.', ['0.1', '0.1000', '0.12']],
                33 => ['Keping 10 g: master terbit 5,5002 g (salah ketik `T1` 0,9998, §3); keping tidak ikut sesi contoh.', null],
            ],
            barisGalat: [20, 21, 22, 23, 24],
            akhiran: true,
        );
    }

    public function test_f2_dua_belas_keping_tiga_neraca_sama_dengan_master(): void
    {
        $sesi = $this->bangunSesiF2();
        $this->assertCount(12, $sesi->uncertaintyCalculations, 'Dua belas keping F2 harus terhitung semua.');

        $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        $sertifikat = $sesi->fresh()->certificate()->firstOrFail();
        $html = view('sertifikat.pdf', [...app(DataTampilanSertifikat::class)->untuk($sertifikat), 'paksaPadat' => false])->render();

        // hal 1: baris 17-24 keping nyata + `k`; baris 25-36 salinan sesi lain (§30).
        $this->assertCetakSamaMaster('anak_timbangan/f2_hal1', $html, 9, barisDibuang: range(25, 36), akhiran: true);
        // hal 2: baris 17-19; sisanya salinan.
        $this->assertCetakSamaMaster('anak_timbangan/f2_hal2', $html, 3, barisDibuang: [...range(20, 36), 38], akhiran: true);
        // hal 3: baris 17; sisanya salinan.
        $this->assertCetakSamaMaster('anak_timbangan/f2_hal3', $html, 1, barisDibuang: [...range(18, 36), 38], akhiran: true);

        $this->assertBintangKembarTidakDicetak($sesi, $html);
    }

    /**
     * PK4-8 — selisih TERBUKA (`pertanyaan-lab-anak-timbangan.md` §26): master mencetak
     * nominal kembar BERBINTANG (F1 `F19` `200*`; F2 hal 1 `F23` `20*`, hal 2 `F19` `200*`),
     * sertifikat aplikasi TIDAK mencetak bintang — dua baris bernominal sama tampil tanpa
     * pembeda bila `ID Number` kosong. Pencocokan angka membuang `*` dengan sengaja
     * (generator dan `PembandingCetakMaster::token`), jadi selisih ini dikunci DI SINI.
     *
     * Ini penanda keadaan sekarang, BUKAN perilaku yang diinginkan: begitu §26 dijawab dan
     * bintang dicetak, assert di bawah harus DIBALIK (cetak bintang, dan jumlah baris
     * kembar tanpa pembeda menjadi nol), bukan dihapus.
     */
    private function assertBintangKembarTidakDicetak(CalibrationSession $sesi, string $html): void
    {
        // Master memang mencetak bintang (tercatat di fixture, tidak ikut token angka).
        $this->assertSame(['F19'], PembandingCetakMaster::selBerbintang('anak_timbangan/f1'));
        $this->assertSame(['F23'], PembandingCetakMaster::selBerbintang('anak_timbangan/f2_hal1'));
        $this->assertSame(['F19'], PembandingCetakMaster::selBerbintang('anak_timbangan/f2_hal2'));

        // Sesinya MENANDAI tiga keping kembar berbintang (kotak 3, 7, 11)...
        $bintang = $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI]['bintang'] ?? [];
        $this->assertCount(3, array_filter((array) $bintang), 'Sesi F2 harus menandai tiga keping kembar berbintang.');

        // ...tetapi tidak satu pun sel tabel sertifikat memuat `*` (PK4-8).
        $this->assertDoesNotMatchRegularExpression(
            '#<td>[^<]*\*[^<]*</td>#u',
            $html,
            'Sertifikat sekarang mencetak bintang nominal kembar — PK4-8/§26 tertutup: balik assert ini dan perbarui dokumen.',
        );

        // Dua keping 2 g tampil sebagai dua baris `- | 2 | ...` tanpa pembeda.
        $this->assertSame(
            2,
            preg_match_all('#<tr>\s*<td>-</td>\s*<td>2</td>#u', $html),
            'Dua baris nominal 2 g tanpa pembeda (ID `-`, tanpa bintang) berubah.',
        );
    }

    /** Jalur HP: simpan sesi 12 keping F2 dari masukan master (ketiga halaman). */
    private function bangunSesiF2(): CalibrationSession
    {
        $f1 = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $keping = [];
        $bintang = [];
        $urut = 0;

        foreach (['f2_hal1', 'f2_hal2', 'f2_hal3'] as $halaman) {
            $lihat = [];

            foreach (PembandingCetakMaster::master("anak_timbangan/{$halaman}")['masukan']['keping'] as $k) {
                $urut++;
                // Keping KEDUA bernominal sama = baris berbintang (`2*`, `20*`, `200*`).
                if (isset($lihat[(string) $k['nominal']])) {
                    $bintang[$urut] = true;
                }
                $lihat[(string) $k['nominal']] = true;

                $keping[] = [
                    'titik_ukur' => (float) $k['nominal'],
                    'at_s1' => [$k['s1']], 'at_t1' => [$k['t1']], 'at_t2' => [$k['t2']], 'at_s2' => [$k['s2']],
                ];
            }
        }

        $lingkungan = PembandingCetakMaster::master('anak_timbangan/f2_hal1')['masukan']['lingkungan'];
        $centang = fn (string $seri): array => ['standard_id' => Standard::where('serial_number', $seri)->firstOrFail()->id, 'dipakai' => true];

        $id = $this->actingAs($teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $f1->equipment_id,
            'thermohygro_standard_id' => $f1->thermohygro_standard_id,
            'tanggal_kalibrasi' => '2026-10-05',
            'suhu_awal' => $lingkungan['suhu'][0], 'suhu_akhir' => $lingkungan['suhu'][1],
            'kelembaban_awal' => $lingkungan['kelembaban'][0], 'kelembaban_akhir' => $lingkungan['kelembaban'][1],
            'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => [
                'kelas_uut' => $lingkungan['kelas_uut'], 'kelas_standar' => $lingkungan['kelas_standar'],
                'meter_lingkungan' => 'Thermobarometer',
                'kapasitas_min_g' => 1, 'kapasitas_g' => 500,
                'suhu_awal' => $lingkungan['suhu'][0], 'suhu_akhir' => $lingkungan['suhu'][1],
                'kelembaban_awal' => $lingkungan['kelembaban'][0], 'kelembaban_akhir' => $lingkungan['kelembaban'][1],
                'tekanan_awal' => $lingkungan['tekanan'][0], 'tekanan_akhir' => $lingkungan['tekanan'][1],
                'bintang' => $bintang,
            ]],
            // Tiga neraca tercentang: Semi Micro (hal 1), Analytical (hal 2), Fujitsu (hal 3).
            'standar_dicek' => array_map($centang, ['C543502629', '1129063525', 'SIDIK/134/2024']),
            'measurements' => $keping,
        ])->assertCreated()->json('data.id');

        return CalibrationSession::findOrFail($id);
    }
}
