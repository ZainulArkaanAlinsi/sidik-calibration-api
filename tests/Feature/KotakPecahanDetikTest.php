<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Rules\PenunjukanWaktu;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\WaktuMentah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Kotak keempat lembar Timer/Stopwatch punya DUA satuan yang sah.
 *
 * Kertas FM-0512 mencetak `0.01 S`, workbook master memakai milidetik, dan
 * dua-duanya benar — untuk alat yang berbeda. Yang menentukan layar alat
 * pelanggan (jawaban lab 16 Sep 2026 §3.1).
 *
 * Yang dijaga di sini: satuannya dibaca dari NAMA kotak, tidak pernah ditebak
 * dari besar angkanya. `12` itu penunjukan sah di kedua satuan — tebakan apa
 * pun bakal meleset 10× di sebagian sesi tanpa satu pun error, dan 0,12 detik
 * yang tercatat 0,012 detik tetap terlihat wajar di sertifikat.
 */
class KotakPecahanDetikTest extends TestCase
{
    use RefreshDatabase;

    public function test_sentidetik_dikali_sepuluh_milidetik_apa_adanya(): void
    {
        // 1 menit 0,12 detik, ditulis dari layar 1/100 detik.
        $this->assertSame(60_120.0, WaktuMentah::kotakKeMilidetik([
            'jam' => 0, 'menit' => 1, 'detik' => 0, 'sentidetik' => 12,
        ]));

        // Angka yang sama di kotak milidetik berarti 0,012 detik — dan itu
        // memang penunjukan lain, bukan salah baca.
        $this->assertSame(60_012.0, WaktuMentah::kotakKeMilidetik([
            'jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 12,
        ]));

        // Kotak pecahan kosong: sisanya tetap terhitung.
        $this->assertSame(61_000.0, WaktuMentah::kotakKeMilidetik([
            'jam' => 0, 'menit' => 1, 'detik' => 1,
        ]));
    }

    public function test_dua_nama_kotak_sekaligus_ditolak(): void
    {
        $gagal = Validator::make(
            ['p' => ['menit' => 1, 'sentidetik' => 12, 'milidetik' => 120]],
            ['p' => [new PenunjukanWaktu(bolehObjek: true)]],
        );

        $this->assertTrue($gagal->fails());
        $this->assertStringContainsString('Pilih satu', $gagal->errors()->first('p'));

        // Salah satu saja: lolos.
        foreach ([['sentidetik' => 12], ['milidetik' => 120]] as $kotak) {
            $lolos = Validator::make(
                ['p' => ['menit' => 1] + $kotak],
                ['p' => [new PenunjukanWaktu(bolehObjek: true)]],
            );

            $this->assertFalse($lolos->fails(), json_encode($kotak));
        }
    }

    public function test_kolom_keempat_lembar_ikut_resolusi_alat(): void
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode('timer_stopwatch');

        $kolom = fn (?Equipment $alat): array => collect(
            $profil->bentukLembarKerja(false, $alat)['bagian']
        )->firstWhere('kode', 'hasil')['tabel'][0]['kolom'][3];

        $this->assertSame('sentidetik', $kolom(new Equipment(['resolusi' => 0.01]))['kode']);
        $this->assertSame('0.01 S', $kolom(new Equipment(['resolusi' => 0.01]))['label']);

        $this->assertSame('milidetik', $kolom(new Equipment(['resolusi' => 0.001]))['kode']);

        // Alat tanpa resolusi tercatat: milidetik, persis perilaku lama.
        $this->assertSame('milidetik', $kolom(null)['kode']);
    }
}
