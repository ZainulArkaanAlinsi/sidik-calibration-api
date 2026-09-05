<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TIAP sesi ter-seed masih bisa dihitung ulang — bukan cuma alat yang kebetulan
 * punya testnya sendiri.
 *
 * ## Kenapa sapuan, dan kenapa baru sekarang
 *
 * `CalibrationValidator` menyusun `konteks` buat menghitung ulang tiap titik,
 * lalu mengadu hasilnya ke angka yang tersimpan. Konteks itu dirakit dari kunci
 * yang berbeda-beda per bentuk lembar — `alat_bantu` & `titik_es` buat lembar
 * suhu pasangan, `peran_sensor` & `channel` buat grid Enclosure, `spindle` &
 * `rpm` buat Viscometer, dan seterusnya.
 *
 * Tiap kali bentuk lembar BARU mendarat, kunci barunya harus ikut dirakit di
 * sana. Yang lupa **tidak menghasilkan error**: tiap titik pulang
 * `hitung_ulang_gagal`, dan dua hal rusak sekaligus —
 *
 *  1. Pemeriksaan "apakah angka tersimpan masih bisa direproduksi" **mati
 *     total** buat alat itu. Kalau tabel master bergeser sesudah sesinya
 *     disimpan, tidak ada satu pun yang memberi tahu.
 *  2. Peringatan yang selalu muncul melatih admin menekan "setujui tetap"
 *     tanpa membaca — dan begitu itu jadi kebiasaan, peringatan yang
 *     benar-benar menahan sertifikat ikut tenggelam.
 *
 * Pola itu sudah kejadian **lima kali**: Viscometer, Gas Detector, TITS,
 * Enclosure, lalu ketiga alat suhu. Tiap kali ditutup dengan test yang menyebut
 * alatnya SATU PER SATU — dan tiap kali alat berikutnya jatuh ke lubang yang
 * sama, karena tidak ada yang mengingatkan.
 *
 * Berkas ini menutup pengulangannya: daftarnya **seluruh sesi yang ter-seed**,
 * bukan nama alat yang diketik. Sesi alat ke-21 ikut kesapu tanpa ada yang
 * perlu ingat menambahkannya.
 *
 * ## Yang ditegakkan, dan kenapa dua-duanya
 *
 * `hitung_ulang_gagal` nol saja belum cukup — itu cuma membuktikan hitung
 * ulangnya JALAN. `hitung_ulang_beda` & `keputusan_titik_beda` nol menegakkan
 * hasilnya JALAN **dan** SAMA dengan yang tersimpan. Tanpa yang kedua, konteks
 * yang salah ISI (mis. dryblock B dikirim buat sesi dryblock A) tetap lolos:
 * hitung ulangnya sukses, angkanya saja yang meleset.
 *
 * ## Hubungannya dengan test per-alat yang sudah ada
 *
 * `HitungUlangTigaAlatSuhuTest` & `HitungUlangSesiTest` TIDAK diganti berkas
 * ini. Keduanya menegakkan hal yang lebih dalam untuk alatnya masing-masing —
 * bahwa bentuk yang disusun ulang beneran berasal dari baris mentah, dan bahwa
 * perintah `kalibrasi:hitung-ulang` memulihkan angka yang sengaja dirusak. Yang
 * di sini lantainya: **tidak ada sesi yang boleh diam-diam berhenti bisa
 * dihitung ulang.**
 */
class HitungUlangSemuaSesiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sesi yang punya titik terhitung. Sesi tanpa titik tidak diuji — bukan
     * karena tidak penting, tapi karena tidak ada yang bisa dihitung ulang di
     * sana, dan memasukkannya bikin test ini hijau tanpa memeriksa apa pun.
     *
     * @return list<CalibrationSession>
     */
    private function sesiBerhitung(): array
    {
        return CalibrationSession::query()
            ->has('uncertaintyCalculations')
            ->with('equipment')
            ->get()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function temuan(CalibrationSession $sesi): array
    {
        $admin = User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->firstOrFail();

        return $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data.temuan');
    }

    public function test_nggak_ada_sesi_yang_diam_diam_berhenti_bisa_dihitung_ulang(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = $this->sesiBerhitung();

        // Penjaga lantai. Sapuan yang daftarnya datang dari database punya satu
        // cara gagal yang tidak bersuara: seeder-nya menyusut, daftarnya ikut
        // kosong, dan PHPUnit tetap menulis "OK" — cuma tanpa memeriksa apa
        // pun. Nol sesi malah "lolos" paling meyakinkan.
        $this->assertGreaterThanOrEqual(
            5,
            count($sesi),
            'Cuma '.count($sesi).' sesi berhitung yang ter-seed, di bawah lantai 5. '
            .'Sapuan di berkas ini jadi nggak ngecek apa-apa buat yang hilang.',
        );

        $rusak = [];

        foreach ($sesi as $s) {
            $temuan = $this->temuan($s);
            $kode = array_column($temuan, 'kode');

            foreach (['hitung_ulang_gagal', 'hitung_ulang_beda', 'keputusan_titik_beda'] as $buruk) {
                if (! in_array($buruk, $kode, true)) {
                    continue;
                }

                $alat = $s->equipment?->nama_alat_kemampuan ?? $s->equipment?->nama ?? '(alat?)';

                $rusak[] = sprintf(
                    '%s [%s] → %s',
                    $s->nomor_sesi,
                    $alat,
                    $buruk,
                );
            }
        }

        $this->assertSame(
            [],
            $rusak,
            "Sesi berikut nggak bisa dihitung ulang, atau hasilnya meleset dari yang tersimpan:\n  "
            .implode("\n  ", $rusak)
            ."\n\n`hitung_ulang_gagal` = `konteks` yang dirakit `CalibrationValidator` nggak cukup buat "
            ."bentuk lembar itu; datanya ADA di database, yang hilang jalannya.\n"
            .'`hitung_ulang_beda` = konteksnya kebentuk, ISINYA yang salah — hitung ulangnya sukses, '
            .'angkanya yang meleset. Yang kedua lebih berbahaya: dia nggak kelihatan sebagai kegagalan.',
        );
    }
}
