<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penanda bentuk kertas yang dikirim ke pembaca foto wajib cocok dengan tabel
 * yang BENERAN ada di lembarnya.
 *
 * ## Kenapa ini perlu dijaga sapuan, bukan per profil
 *
 * `bentukPindaiFoto()` memulangkan tiga penanda, dan bawaannya menuruti bentuk
 * lima lembar pertama (pH & saudaranya): tiap sel memuat SEPASANG angka —
 * pembacaan + suhu °C yang dicatat bersamaan. Profil yang lembarnya bukan begitu
 * harus meng-override; yang lupa **tidak menghasilkan error di mana pun**.
 *
 * Yang terjadi kalau lupa sudah tertulis di docblock bawaannya, dan bunyinya
 * sama dengan yang bikin `didukung` lahir: prompt & skema JSON yang dikirim ke
 * pembaca foto dibangun dari penanda ini, jadi modelnya diminta membaca kolom
 * yang tidak pernah ada di kertasnya. Yang balik ke teknisi bukan "gagal baca",
 * tapi angka yang dikarang supaya kolomnya kelihatan terisi — dan angka karangan
 * yang kelihatan wajar itu yang lolos sampai sertifikat.
 *
 * Waktu penjaga ini ditulis, **lima** profil sedang salah: `tits`,
 * `gas_detector`, `thermocouple`, `thermometer_glass`, dan `thermohygro`.
 * Kelimanya lembar bertabel satu kolom (`pembacaan`) yang mengaku punya kolom
 * suhu per sel. Seperti biasa yang bolong justru yang paling baru — dan tidak
 * satu pun test yang ada waktu itu menyentuhnya, karena penanda ini cuma pernah
 * diuji di dua tempat: lembar pH (yang memang benar) dan lembar grid Enclosure.
 *
 * Jadi daftarnya diambil dari REGISTRY, bukan diketik di sini: profil ke-21 ikut
 * kesapu tanpa ada yang perlu ingat menambahkannya.
 */
class BentukPindaiFotoCocokTabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Semua profil berlembar yang terdaftar.
     *
     * @return array<string, array{CalibrationProfile}>
     */
    public static function semuaProfil(): array
    {
        $hasil = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $p) {
            $hasil[$p->kode()] = [$p];
        }

        // Penjaga lantai — sama alasannya dengan sapuan lain di repo ini: daftar
        // yang datang dari luar bisa menyusut tanpa bersuara, dan PHPUnit tetap
        // menulis "OK" cuma dengan lebih sedikit yang diperiksa.
        if (count($hasil) < 20) {
            throw new \RuntimeException(
                'Registry cuma memulangkan '.count($hasil).' profil, di bawah lantai 20. '
                .'Sapuan di berkas ini jadi nggak ngecek apa-apa buat yang hilang.',
            );
        }

        return $hasil;
    }

    /**
     * Kolom yang benar-benar dikirim tiap tabel lembar ini.
     *
     * @return list<string>
     */
    private function kolomTabel(CalibrationProfile $profil): array
    {
        $kolom = [];

        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $tabel) {
                foreach ($tabel['kolom'] ?? [] as $k) {
                    $kolom[$k['kode']] = true;
                }
            }
        }

        return array_keys($kolom);
    }

    private function jumlahTabel(CalibrationProfile $profil): int
    {
        $jumlah = 0;

        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            $jumlah += count($bagian['tabel'] ?? []);
        }

        return $jumlah;
    }

    /**
     * `kolom_suhu` itu pernyataan soal KERTAS: tiap sel memuat sepasang angka.
     *
     * Diturunkan dari kolom tabel yang benar-benar dikirim, bukan dari daftar
     * nama alat — daftar tulis tangan ketinggalan tanpa bunyi begitu lembar
     * baru mendarat, dan itu persis cara kelima profil di docblock kelas ini
     * jadi salah.
     */
    #[DataProvider('semuaProfil')]
    public function test_kolom_suhu_ikut_kolom_tabel_yang_beneran_ada(CalibrationProfile $profil): void
    {
        Organization::factory()->create();

        $bentuk = $profil->bentukPindaiFoto();

        // Lembar yang KEDUA jalur fotonya ditolak nggak pernah menyusun prompt
        // maupun menggambar tombol, jadi penandanya nggak menentukan apa-apa.
        //
        // Dua gerbang diperiksa, bukan cuma `didukung`: sejak keduanya dipisah
        // (27 Agt 2026), lembar bisa punya kamera LOKAL sambil jalur cloud-nya
        // tetap ditolak — TIDS begitu. Diperiksa cuma `didukung` di sini, TIDS
        // diam-diam keluar dari sapuan ini justru waktu kameranya menyala.
        $cloud = ($bentuk['didukung'] ?? true) === true;
        $lokal = ($bentuk['lokal'] ?? $bentuk['didukung'] ?? true) === true;

        if (! $cloud && ! $lokal) {
            $this->assertTrue(true);

            return;
        }

        $kolom = $this->kolomTabel($profil);

        $this->assertSame(
            in_array('suhu', $kolom, true),
            $bentuk['kolom_suhu'] ?? true,
            "Lembar `{$profil->kode()}` bilang `kolom_suhu = "
            .var_export($bentuk['kolom_suhu'] ?? true, true).'` sementara kolom tabelnya `'
            .implode(',', $kolom).'`. Penanda ini yang membangun prompt & skema JSON buat '
            .'pembaca foto: salah nilai berarti modelnya diminta membaca kolom yang nggak '
            .'ada di kertasnya, dan yang balik bukan error tapi angka karangan yang wajar.',
        );
    }

    /**
     * Lembar tanpa satu pun tabel nggak bisa ditawarkan ke jalur "foto tabel
     * ini" — nggak ada tabel buat difoto.
     *
     * Kelima lembar Enclosure begitu: bentuknya cuma mengirim kolom identitas
     * plus `grid_sensor`. Sudah `didukung: false` hari ini karena kertasnya
     * berbentuk grid; penjaga ini yang bikin lembar bertabel-nol berikutnya
     * nggak bisa lahir dengan `didukung` bawaan `true`.
     */
    #[DataProvider('semuaProfil')]
    public function test_lembar_tanpa_tabel_nggak_ngaku_bisa_difoto(CalibrationProfile $profil): void
    {
        Organization::factory()->create();

        if ($this->jumlahTabel($profil) > 0) {
            $this->assertTrue(true);

            return;
        }

        $bentuk = $profil->bentukPindaiFoto();

        $this->assertFalse(
            $bentuk['didukung'] ?? true,
            "Lembar `{$profil->kode()}` nggak mengirim satu pun tabel, tapi masih ngaku muat "
            .'di jalur foto CLOUD. Selain nggak ada tabelnya, `didukung: true` melebarkan '
            .'batas data: dia yang menggerbangi endpoint yang mengirim foto lembar kerja '
            .'pelanggan ke layanan pihak ketiga.',
        );

        $this->assertFalse(
            $bentuk['lokal'] ?? $bentuk['didukung'] ?? true,
            "Lembar `{$profil->kode()}` nggak mengirim satu pun tabel, tapi tombol foto "
            .'tabelnya bakal digambar. Layarnya nggak punya tabel buat diisi hasilnya.',
        );
    }

    /**
     * Tulisan kepala kolom pengulangan wajib UNIK di dalam satu tabel.
     *
     * Kepala kolom itu satu-satunya jangkar sumbu mendatar yang dipunya jalur
     * foto: aplikasi mencari tulisannya di citra, lalu menaruh angka ke kolom
     * yang kepalanya paling segaris. Dua kolom yang tulisannya sama bikin
     * jangkar itu jadi undian beberapa piksel — dan yang kalah undian bukan
     * error, tapi pembacaan yang mendarat di kolom sebelahnya.
     *
     * Lembar TITS berdiri paling dekat ke tepi ini: dia nyetak `UP X1` … `DOWN
     * X3`, jadi UNIK sebagai tulisan utuh — tapi `X1`-nya sendiri muncul dua
     * kali. Yang menjaga sisi HP-nya `foto_tabel_kepala_tercetak_test.dart` di
     * repo mobile; yang dijaga di sini sumbernya, supaya lembar berikutnya
     * nggak lahir dengan dua kolom bernama sama.
     */
    #[DataProvider('semuaProfil')]
    public function test_kepala_kolom_pengulangan_unik_per_tabel(CalibrationProfile $profil): void
    {
        Organization::factory()->create();

        $diperiksa = 0;

        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $tabel) {
                $label = [];

                foreach ($tabel['pengulangan_arah'] ?? [] as $arah) {
                    if (isset($arah['label'])) {
                        $label[] = $arah['label'];
                    }
                }

                if ($label === []) {
                    continue;
                }

                $diperiksa++;

                $this->assertSame(
                    count($label),
                    count(array_unique($label)),
                    'Tabel `'.($tabel['grup'] ?? $tabel['tahap'] ?? '?')."` lembar `{$profil->kode()}` "
                    .'punya dua kolom pengulangan bertulisan sama ('.implode(', ', $label).'). '
                    .'Jangkar kolomnya jadi undian, dan yang kalah undian pembacaan yang mendarat '
                    .'di kolom sebelahnya — tanpa satu pun error.',
                );
            }
        }

        // Profil yang nggak mengirim `pengulangan_arah` sama sekali memang boleh;
        // kolomnya dijangkar `Xn` / nomor polos di sisi HP.
        $this->assertGreaterThanOrEqual(0, $diperiksa);
    }
}
