<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Services\Calibration\Profiles\PistonVolumeProfile;
use App\Services\Calibration\Profiles\SieveProfile;
use App\Services\Calibration\SieveCalculator;
use App\Services\Calibration\TabelStandarSieve;
use App\Services\DataTampilanSertifikat;
use App\Support\SieveMentah;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tahap B2 perbaikan isi sertifikat (audit 11 Nov 2026, R4 "gambar/blok khas"):
 * blok yang dicetak lembar `SERTIFIKAT` master tapi belum ada di sertifikat.
 * Tiap butir diuji sendiri lewat sesi contoh seeder yang diterbitkan dan
 * dirender sungguhan:
 *
 * 1. Note Kerataan/Kesejajaran Muka Ukur micrometer (`165:167!B31:G33`);
 * 2. Class/Permitted Error piston/dispensett/buret digital (`160/164!O14:U14`);
 * 3. Minimum Number of Apertures Examined sieve (`169!B25:M25`);
 * 4. Tekanan Udara glassware Graduated + Fixed non-Rev.7 (`143/144!T13:Y13`).
 *
 * Kolom Measured Flowrate flowmeter (`148!K20:K25`) DITAHAN: lihat
 * `docs/pertanyaan-lab-sertifikat-b2.md` §4.
 *
 * Hanya snapshot BARU yang memuatnya: sertifikat lama dijaga
 * `SertifikatBekuTidakBerubahTest` (blade tak berubah satu byte).
 */
class SertifikatTahapB2Test extends TestCase
{
    use RefreshDatabase;

    private function siap(): void
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    /**
     * @param  array<string, mixed>  $spekTambahan  digabung ke `spesifikasi_alat` SEBELUM terbit
     */
    private function terbitkan(string $nomorSesi, array $spekTambahan = []): Certificate
    {
        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();

        if ($spekTambahan !== []) {
            $sesi->update(['spesifikasi_alat' => array_replace_recursive((array) $sesi->spesifikasi_alat, $spekTambahan)]);
        }

        $this->denganOrderNumber($sesi->id)->sebagaiPemeriksaLain($sesi)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    private function html(Certificate $sertifikat): string
    {
        return view('sertifikat.pdf', [...app(DataTampilanSertifikat::class)->untuk($sertifikat), 'paksaPadat' => false])->render();
    }

    /** Teks polos satu spasi: urutan blok mudah diperiksa. */
    private function teks(string $html): string
    {
        $tanpaGaya = preg_replace('#<style>.*?</style>#su', '', $html);

        return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($tanpaGaya)));
    }

    /** @param  list<string>  $urutan */
    private function assertUrutan(array $urutan, string $teks, string $pesan = ''): void
    {
        $posisi = -1;

        foreach ($urutan as $potongan) {
            $ketemu = mb_strpos($teks, $potongan, $posisi + 1);
            $this->assertNotFalse($ketemu, "{$pesan} `{$potongan}` tidak ditemukan sesudah posisi {$posisi}.");
            $posisi = $ketemu;
        }
    }

    /** Halaman terkecil: normal dulu, padat kalau meluap — urutan `SertifikatSatuHalaman`. */
    private function halamanTerbaik(Certificate $sertifikat): int
    {
        $bahan = app(DataTampilanSertifikat::class)->untuk($sertifikat);
        $halaman = PHP_INT_MAX;

        foreach ([false, true] as $padat) {
            $pdf = Pdf::loadView('sertifikat.pdf', [...$bahan, 'paksaPadat' => $padat]);
            $pdf->output();
            $halaman = min($halaman, (int) $pdf->getDomPDF()->getCanvas()->get_page_count());

            if ($halaman === 1) {
                break;
            }
        }

        return $halaman;
    }

    /**
     * Urutan kunci objek JSON tidak bermakna, dan kolom JSON MySQL menyusunnya
     * ulang (panjang kunci, lalu abjad) sedangkan SQLite menyimpan teks apa
     * adanya. Jadi snapshot yang dibaca balik dari DB tidak boleh dibandingkan
     * `assertSame` mentah: kunci peta diurutkan di kedua sisi, sedangkan urutan
     * daftar (baris, sel, blok) TETAP dibandingkan, begitu juga nilai dan tipenya.
     */
    private function kanonik(mixed $nilai): mixed
    {
        if (! is_array($nilai)) {
            return $nilai;
        }

        $nilai = array_map(fn (mixed $isi): mixed => $this->kanonik($isi), $nilai);

        if (! array_is_list($nilai)) {
            ksort($nilai);
        }

        return $nilai;
    }

    // ---- 1. Micrometer: Note Kerataan / Kesejajaran Muka Ukur ------------------

    public function test_micrometer_mencetak_note_kerataan_dan_kesejajaran_hanya_dari_isian(): void
    {
        $this->siap();

        $sertifikat = $this->terbitkan('0106-CAL-1023', ['micrometer' => [
            'kerataan_muka_ukur' => 'baik', 'kesejajaran_muka_ukur' => 'buruk',
        ]]);

        $this->assertSame($this->kanonik([[
            'posisi' => 'bawah_hasil', 'judul' => 'Note :', 'baris' => [],
            'tabel' => [
                ['Kerataan Muka Ukur dalam Kondisi', ':', 'Baik'],
                ['Kesejajaran Muka Ukur dalam Kondisi', ':', 'Buruk'],
            ],
        ]]), $this->kanonik($sertifikat->snapshot['catatan_blok']));

        $html = $this->html($sertifikat);
        $this->assertMatchesRegularExpression('#<td>Kerataan Muka Ukur dalam Kondisi</td>\s*<td>:</td>\s*<td>Baik</td>#u', $html);
        $this->assertMatchesRegularExpression('#<td>Kesejajaran Muka Ukur dalam Kondisi</td>\s*<td>:</td>\s*<td>Buruk</td>#u', $html);

        // Letak master: sesudah kalimat k (`P30`), sebelum penafian (`D35`) dan Standard used.
        $this->assertUrutan(
            ['Coverage Factor', 'Note :', 'Kerataan Muka Ukur dalam Kondisi', 'Kesejajaran Muka Ukur dalam Kondisi', 'Calibration results are not to be announced', 'Standard used'],
            $this->teks($html),
        );
        $this->assertSame(1, $this->halamanTerbaik($sertifikat), 'Wajib satu halaman.');

        // Tanpa isian teknisi: tidak ada "Baik" karangan, blok tidak dicetak.
        $kosong = $this->terbitkan('002-UB.P-11-20');
        $this->assertSame([], $kosong->snapshot['catatan_blok'] ?? []);
        $this->assertStringNotContainsString('Kerataan Muka Ukur', $this->html($kosong));
    }

    // ---- 2. Class/Permitted Error: piston, dispensett, buret digital -----------

    public function test_piston_dispensett_buret_digital_mencetak_kotak_class_permitted_error(): void
    {
        $this->siap();

        // Satu diisi teknisi, dua dibiarkan kosong -> bawaan sel master `- /± -`.
        $kasus = [
            'DEMO-PP-FIX-001' => 'A/±0,5',
            'DEMO-DS-FIX-001' => null,
            'DEMO-BD-GRD-001' => null,
        ];

        foreach ($kasus as $nomor => $ketik) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $satuan = ($sesi->spesifikasi_alat['piston']['satuan'] ?? null) ?: ($sesi->equipment->satuan ?: 'ml');
            // Teks blok/kepala ditulis koma lalu dibakukan ke titik desimal tata letak
            // master (`CertificateSnapshotBuilder::$teksAngka`), seperti kotak Volumetrik.
            $harapan = str_replace(',', '.', $ketik ?? PistonVolumeProfile::KELAS_TOLERANSI_KOSONG).' '.$satuan;
            $sertifikat = $this->terbitkan($nomor, $ketik === null ? [] : ['piston' => ['kelas_toleransi' => $ketik]]);

            $this->assertSame($harapan, $sertifikat->snapshot['header_tambahan']['kelas_toleransi'], $nomor);
            $this->assertContains(
                ['kunci' => 'kelas_toleransi', 'label' => 'Class/Permitted Error'],
                array_merge(...$sertifikat->snapshot['tata_letak']['kepala']),
                "{$nomor}: sel kepala `O14`.",
            );

            $teks = $this->teks($this->html($sertifikat));
            $this->assertUrutan(['Environmental Condition', 'Tekanan Udara', 'Class/Permitted Error', ':', $harapan, 'Calibration Report'], $teks, $nomor);
            $this->assertSame(1, $this->halamanTerbaik($sertifikat), "{$nomor}: wajib satu halaman.");
        }
    }

    // ---- 3. Sieve: Minimum Number of Apertures Examined ------------------------

    public function test_sieve_mencetak_minimum_number_of_apertures_dari_tabel_mpe(): void
    {
        $this->siap();

        $sesi = CalibrationSession::where('nomor_sesi', '0736-CAL-526')->firstOrFail();
        $blok = SieveMentah::blokSesi($sesi->spesifikasi_alat);
        $mpe = (new TabelStandarSieve)->barisMpe((float) $blok['nominal'], $blok['satuan']);
        $harapan = SieveCalculator::minimumOpening($mpe, (string) $blok['tipe'], $blok['jumlah_opening_total']);

        // Master `169` sesi contoh: sieve 19 mm tipe Inspection -> `F16` = 15.
        $this->assertSame(15, $harapan, 'Angka acuan master `L25`.');

        $sertifikat = $this->terbitkan('0736-CAL-526');
        $html = $this->html($sertifikat);

        $this->assertStringContainsString('Minimum Number of Apertures Examined (openings and diameter) : 15 Sample Openings Per Sieves', $this->teks($html));
        // Letak master: sesudah kalimat k (`B23`), sebelum Standard Deviation (`B26`).
        $this->assertUrutan(
            ['Coverage Factor', 'Minimum Number of Apertures Examined', 'Standard Deviation of Warp', 'Standard Deviation of Weft', 'Standard Deviation of Φ'],
            $this->teks($html),
        );
        $this->assertSame(1, $this->halamanTerbaik($sertifikat), 'Wajib satu halaman.');
    }

    public function test_sieve_minimum_all_memakai_jumlah_total_dan_tanpa_angka_tidak_dikarang(): void
    {
        $mpe = (new TabelStandarSieve)->barisMpe(19.0, 'mm');
        $this->assertSame(15, SieveCalculator::minimumOpening($mpe, 'inspection', null));

        $semua = ['min_opening' => ['inspection' => 'all', 'compliance' => 'all']];
        $this->assertSame(40, SieveCalculator::minimumOpening($semua, 'inspection', 40));
        $this->assertNull(SieveCalculator::minimumOpening($semua, 'inspection', null), '`all` tanpa jumlah total: tak ada angka.');
        $this->assertNull(SieveCalculator::minimumOpening($semua, 'inspection', 0));
        $this->assertNull(SieveCalculator::minimumOpening(['min_opening' => []], 'calibration', 10));
    }

    public function test_sieve_tipe_calibration_mencetak_kolom_calibration_dan_mencatat_selisih_f16(): void
    {
        $mpe = (new TabelStandarSieve)->barisMpe(19.0, 'mm');
        // Sieve 19 mm: master `INPUT DATA!F16` memakai kolom Inspection (15) untuk tipe
        // Calibration, Tabel_MPE kolom Calibration menuntut 30 (`pertanyaan-lab-sieve.md` §5).
        $this->assertSame(15, SieveCalculator::minimumOpening($mpe, 'inspection'));
        $this->assertSame(30, SieveCalculator::minimumOpening($mpe, 'calibration'));

        // Yang dicetak = yang ditegakkan (30), bukan F16 master (15).
        $sesi = new CalibrationSession;
        $sesi->spesifikasi_alat = ['sieve' => ['tipe' => 'calibration', 'nominal' => 19, 'satuan' => 'mm', 'standar_dipakai' => 'caliper']];
        $sesi->setRelation('uncertaintyCalculations', collect());
        $profil = new SieveProfile;
        $blok = $profil->catatanSertifikat($sesi);

        $this->assertSame(
            ['Minimum Number of Apertures Examined (openings and diameter) : 30 Sample Openings Per Sieves'],
            $blok[1]['baris'],
        );
        $this->assertSame('bawah_hasil', $blok[1]['posisi']);

        // Jejak sesi: sumber sel master, selisih, dasar (AGENTS.md Olah data butir 4).
        $hasil = ['mpe' => $mpe, 'minimum_opening' => 30, 'nominal_mm' => 19.0];
        $baris = $profil->jejakMinimumOpeningMaster($hasil, 'calibration');

        $this->assertSame('penyimpangan_master', $baris['sumber']);
        $this->assertStringContainsString('INPUT DATA!F16', $baris['keterangan']);
        $this->assertStringContainsString('= 30', $baris['keterangan']);
        $this->assertStringContainsString('= 15', $baris['keterangan']);
        $this->assertStringContainsString('selisih 15 opening', $baris['keterangan']);
        $this->assertStringContainsString('pertanyaan-lab-sieve.md §5', $baris['keterangan']);

        // Tipe Inspection (sama dengan master) dan tipe tanpa selisih: tanpa baris.
        $this->assertNull($profil->jejakMinimumOpeningMaster(['mpe' => $mpe, 'minimum_opening' => 15, 'nominal_mm' => 19.0], 'inspection'));
    }

    // ---- 4. Tekanan Udara: buret, gelas ukur, pipet ukur, picnometer ------------

    public function test_glassware_graduated_dan_picnometer_mencetak_baris_tekanan_udara(): void
    {
        $this->siap();

        // `U13 = PERHITUNGAN!G19 = AVERAGE(E19:F19)` = (933,2 + 933,1) / 2 = 933,15 -> format `0`.
        foreach (['DEMO-VOL-003' => 'picnometer', 'DEMO-VOL-004' => 'gelas ukur', 'DEMO-VOL-005' => 'buret', 'DEMO-VOL-006' => 'pipet ukur'] as $nomor => $alat) {
            $sertifikat = $this->terbitkan($nomor);
            $env = $sertifikat->snapshot['header']['env_condition'];

            $this->assertMatchesRegularExpression('/^T \d+(\.\d)? °C( ± [\d.]+ °C)?\n%RH \d+ %( ± [\d.]+ %)?\nTekanan Udara 933 hPa/u', $env, "{$alat}: tiga baris bertumpuk.");

            $teks = $this->teks($this->html($sertifikat));
            $this->assertUrutan(['Environmental Condition', 'T ', '%RH', 'Tekanan Udara 933 hPa'], $teks, $alat);
            $this->assertStringNotContainsString('#REF!', $teks, $alat);
            $this->assertSame(1, $this->halamanTerbaik($sertifikat), "{$alat}: wajib satu halaman.");
        }
    }
}
