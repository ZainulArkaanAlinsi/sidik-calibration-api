<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Services\Calibration\CalibrationProfileRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tiap baris `STANDARD USED` yang tercetak di lembar kerja harus ketemu
 * padanannya di master `standards` — kecuali yang memang sudah dicabut lab.
 *
 * ## Gejalanya ada di HP, bukan di server
 *
 * `CalibrationProfile::tautkanStandarTercetak()` mengirim `terdaftar => false`
 * untuk baris yang tidak ketemu, dan layar lembar kerja menuliskannya merah:
 * **"belum terdaftar di master standar"**. Kotak centangnya mati, jadi teknisi
 * tidak bisa menyatakan standar mana yang dia pakai — dan sertifikat terbit
 * tanpa baris ketertelusuran yang seharusnya ada di situ.
 *
 * Server tidak pernah error. Itu sebabnya penjaganya harus di sini.
 *
 * ## Daftarnya dari REGISTRY, bukan diketik
 *
 * Sapuan yang menyebut nama alat satu per satu akan ketinggalan begitu profil
 * ke-29 mendarat, dan yang ketinggalan tidak bersuara. Di sini profilnya disapu
 * dari `CalibrationProfileRegistry`, jadi alat baru ikut terjaring tanpa
 * menyentuh berkas ini.
 */
class StandarTercetakTerdaftarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Baris yang BOLEH tidak terdaftar, berikut alasannya.
     *
     * Bukan daftar "yang belum sempat" — daftar "yang memang tidak seharusnya
     * ada". Menambah nama ke sini artinya menyatakan alatnya pensiun, dan itu
     * keputusan lab, bukan kerapian kode.
     *
     * @var array<string, string>
     */
    private const BOLEH_TIDAK_TERDAFTAR = [
        // `FORM VALIDASI` TITS rev. 11 (24 Mei 2024): "Remove std. Victor / Add
        // std kalibrator yokogawa". Tabel koreksinya sudah `#REF!` semua dan
        // penggantinya Yokogawa CA 150 (23P1005) — yang justru TERDAFTAR.
        // Barisnya tetap dicetak karena kertas Rev.3 masih memuatnya, jadi label
        // merahnya JUJUR: alatnya memang tidak dipakai lagi. Lihat
        // `EnclosureProfileBase` untuk ceritanya.
        '992613877' => 'Victor 14+ dicabut lab 24 Mei 2024, diganti Yokogawa CA 150',
    ];

    /**
     * Nol baris tercetak yang tidak terdaftar, di luar yang memang pensiun.
     *
     * Pesan gagalnya sengaja menyebut profil DAN labelnya: yang membacanya orang
     * yang harus memasukkan standarnya ke master, dan "ada yang belum terdaftar"
     * tanpa menyebut apa tidak bisa ditindaklanjuti siapa pun.
     */
    public function test_semua_standar_tercetak_ada_di_master(): void
    {
        $this->seed(DatabaseSeeder::class);

        $belum = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            // Alat contoh dipakai supaya saringan `organization_id` hidup —
            // tanpa `$equipment`, `masterStandarTertaut()` menyapu SELURUH lab
            // dan sapuan ini jadi lebih longgar daripada kenyataannya.
            $alat = Equipment::where('nama_alat_kemampuan', $profil->namaAlatKemampuan())->first();

            foreach ($profil->bentukLembarKerja(false, $alat)['bagian'] ?? [] as $bagian) {
                foreach ($bagian['baris'] ?? [] as $b) {
                    if (! is_array($b) || ! array_key_exists('terdaftar', $b) || $b['terdaftar']) {
                        continue;
                    }

                    if ($this->memangPensiun((string) $b['label'])) {
                        continue;
                    }

                    $belum[] = sprintf('%s -> %s', $profil->kode(), $b['label']);
                }
            }
        }

        $this->assertSame([], $belum, sprintf(
            "Baris `STANDARD USED` tercetak tapi tidak ada di master `standards` — di HP baris ini "
            ."muncul merah dan kotaknya mati:\n  %s\n\nKalau alatnya memang sudah dicabut lab, "
            .'tambahkan serialnya ke BOLEH_TIDAK_TERDAFTAR berikut alasannya. Kalau tidak, seed '
            .'standarnya.',
            implode("\n  ", $belum),
        ));
    }

    /**
     * Penjaganya MENGGIGIT: Victor 14+ memang tidak terdaftar.
     *
     * Tanpa test ini, `BOLEH_TIDAK_TERDAFTAR` bisa berisi nama yang sebenarnya
     * sudah terdaftar — dan pengecualian yang tidak pernah dipakai membuat
     * sapuan di atas terlihat lebih ketat daripada yang sebenarnya.
     */
    public function test_pengecualiannya_memang_terpakai(): void
    {
        $this->seed(DatabaseSeeder::class);

        $ketemu = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $alat = Equipment::where('nama_alat_kemampuan', $profil->namaAlatKemampuan())->first();

            foreach ($profil->bentukLembarKerja(false, $alat)['bagian'] ?? [] as $bagian) {
                foreach ($bagian['baris'] ?? [] as $b) {
                    if (is_array($b) && ($b['terdaftar'] ?? true) === false) {
                        $ketemu[] = (string) $b['label'];
                    }
                }
            }
        }

        foreach (self::BOLEH_TIDAK_TERDAFTAR as $penanda => $alasan) {
            $cocok = array_filter($ketemu, static fn (string $l): bool => str_contains($l, $penanda));

            $this->assertNotEmpty($cocok, sprintf(
                'Pengecualian `%s` (%s) tidak pernah terpakai — standarnya ternyata SUDAH terdaftar. '
                .'Cabut dari BOLEH_TIDAK_TERDAFTAR; pengecualian mati bikin sapuan ini terlihat '
                .'lebih ketat daripada yang sebenarnya.',
                $penanda,
                $alasan,
            ));
        }
    }

    private function memangPensiun(string $label): bool
    {
        foreach (array_keys(self::BOLEH_TIDAK_TERDAFTAR) as $penanda) {
            if (str_contains($label, $penanda)) {
                return true;
            }
        }

        return false;
    }
}
