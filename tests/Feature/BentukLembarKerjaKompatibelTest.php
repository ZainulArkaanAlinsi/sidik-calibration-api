<?php

namespace Tests\Feature;

use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pagar KONTRAK bentuk lembar kerja: tipe tiap kunci harus tetap seperti yang
 * dimengerti aplikasi teknisi, buat SEMUA profil sekaligus.
 *
 * ## Kenapa test ini ada
 *
 * Aplikasi teknisi (`sidik-calibration-mobile`) mem-parse bentuk lembar kerja
 * dengan cast Dart yang keras, dan dua di antaranya gagal dengan cara yang
 * tidak kelihatan dari sisi backend:
 *
 *  - `judul_nilai: json['judul_nilai'] as String?` — dikirim peta, cast-nya
 *    melempar `TypeError`. Yang gagal bukan satu kolom melainkan SELURUH
 *    lembar: `LembarKerja.fromJson` ada di luar jangkauan `parseListAman`,
 *    jadi layar kalibrasi kosong sebelum satu baris pun sempat digambar.
 *  - `pengulangan: (…).whereType<num>()` — dikirim daftar objek, tidak ada
 *    error sama sekali: filternya membuang semuanya dan tabelnya terbuka rapi
 *    dengan NOL kolom pembacaan. Teknisi melihat lembar yang tidak bisa diisi.
 *
 * Dua-duanya sempat terjadi waktu TITS ditambahkan (alat ke-11), dan
 * dua-duanya lolos seluruh suite backend — tidak ada satu pun test yang
 * memeriksa TIPE kunci-kunci ini. Test ini yang menutupnya, dan dia menyapu
 * semua profil supaya alat ke-12 tidak mengulanginya.
 *
 * Kalau suatu saat aplikasi teknisi memang perlu bentuk baru, tambahkan
 * KUNCI BARU (`judul_nilai_per_mode`, `pengulangan_arah`) — jangan mengubah
 * tipe kunci lama.
 */
class BentukLembarKerjaKompatibelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kunci yang WAJIB string (atau null) di tiap tabel hasil.
     *
     * @var list<string>
     */
    private const KUNCI_STRING = [
        'judul',
        'judul_nilai',
        'judul_pengulangan',
        'prefiks_pengulangan',
        'satuan',
        'catatan',
        'sumbu_pengulangan',
    ];

    public function test_semua_profil_mengirim_bentuk_yang_kebaca_aplikasi(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $bentuk = $profil->bentukLembarKerja();
            $kode = $profil->kode();

            $this->assertIsArray($bentuk, "profil {$kode}: bentuk lembar kerja bukan array");

            // `satuan` boleh null (alat bersatuan campuran) tapi tidak boleh
            // jadi tipe lain — aplikasi membacanya `as String? ?? ''`.
            if (array_key_exists('satuan', $bentuk)) {
                $this->assertTrue(
                    $bentuk['satuan'] === null || is_string($bentuk['satuan']),
                    "profil {$kode}: `satuan` level lembar harus string atau null",
                );
            }

            foreach ($bentuk['bagian'] ?? [] as $bagian) {
                foreach ($bagian['tabel'] ?? [] as $i => $tabel) {
                    $this->periksaTabel($kode, $i, $tabel);
                }
            }
        }
    }

    /**
     * Alat bersatuan campuran WAJIB menandainya, bukan cuma mengosongkan
     * `satuan`.
     *
     * Tanpa penanda itu aplikasi membaca `satuan: null` sebagai string kosong
     * dan tidak punya cara membedakan "alat ini memang campur satuan" dari
     * "backend-nya belum mengisi". Gas Detector sempat begitu.
     */
    public function test_satuan_null_selalu_disertai_penanda_campuran(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $bentuk = $profil->bentukLembarKerja();

            // Profil yang TIDAK punya kunci `satuan` sama sekali dilewati, bukan
            // dianggap null. Autoklaf begitu: lembarnya bentuk lain (`besaran:
            // ['Suhu','Tekanan']` + `satuan_suhu` + `satuan_tekanan_pilihan`)
            // dan punya layar sendiri di aplikasi, jadi aturan satuan-tunggal
            // di sini memang tidak berlaku untuknya.
            if (! array_key_exists('satuan', $bentuk) || $bentuk['satuan'] !== null) {
                continue;
            }

            $penanda = $bentuk['satuan_campuran'] ?? null;

            $this->assertTrue(
                $penanda === true || (is_array($penanda) && $penanda !== []),
                sprintf(
                    'profil %s: `satuan` null tapi `satuan_campuran` nggak diisi — '
                    .'aplikasi nggak bisa bedain alat campur satuan dari backend yang lupa ngisi.',
                    $profil->kode(),
                ),
            );
        }
    }

    /**
     * TITS mengirim bentuk barunya sebagai KUNCI TAMBAHAN, bukan dengan
     * mengubah tipe kunci lama. Ini yang membuat aplikasi versi lama tetap bisa
     * membuka lembarnya (judulnya saja yang salah kalau modenya source).
     */
    public function test_tits_mengirim_bentuk_baru_sebagai_kunci_tambahan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $profil = collect(app(CalibrationProfileRegistry::class)->semua())
            ->firstOrFail(fn (CalibrationProfile $p): bool => $p->kode() === 'tits');

        $tabel = collect($profil->bentukLembarKerja()['bagian'])
            ->firstWhere('kode', 'hasil')['tabel'][0];

        $this->assertSame('Standard Indication', $tabel['judul_nilai']);
        $this->assertSame('Reading Unit Under Test', $tabel['judul_pengulangan']);

        $this->assertSame(
            ['measure' => 'Standard Indication', 'source' => 'UUT Indication'],
            $tabel['judul_nilai_per_mode'],
        );
        $this->assertSame(
            ['measure' => 'Reading Unit Under Test', 'source' => 'Reading Standard'],
            $tabel['judul_pengulangan_per_mode'],
        );

        $this->assertSame([1, 2, 3, 4, 5, 6], $tabel['pengulangan']);
        $this->assertTrue($tabel['titik_bisa_diubah']);

        $arah = $tabel['pengulangan_arah'];
        $this->assertCount(6, $arah);
        $this->assertSame(['ke' => 1, 'arah' => 'UP', 'label' => 'UP X1'], $arah[0]);
        $this->assertSame(['ke' => 4, 'arah' => 'DOWN', 'label' => 'DOWN X1'], $arah[3]);
        $this->assertSame(['ke' => 6, 'arah' => 'DOWN', 'label' => 'DOWN X3'], $arah[5]);
    }

    /**
     * @param  array<string, mixed>  $tabel
     */
    private function periksaTabel(string $kode, int $i, array $tabel): void
    {
        foreach (self::KUNCI_STRING as $kunci) {
            if (! array_key_exists($kunci, $tabel) || $tabel[$kunci] === null) {
                continue;
            }

            $this->assertIsString(
                $tabel[$kunci],
                sprintf(
                    'profil %s tabel #%d: `%s` harus string — aplikasi teknisi nge-cast-nya '
                    .'`as String?` dan tipe lain bikin SELURUH lembar gagal kebuka.',
                    $kode,
                    $i,
                    $kunci,
                ),
            );
        }

        if (! array_key_exists('pengulangan', $tabel)) {
            return;
        }

        $this->assertIsArray($tabel['pengulangan'], "profil {$kode} tabel #{$i}: `pengulangan` harus array");

        foreach ($tabel['pengulangan'] as $n) {
            $this->assertIsInt(
                $n,
                sprintf(
                    'profil %s tabel #%d: isi `pengulangan` harus angka — aplikasi nyaringnya '
                    .'`whereType<num>()`, jadi objek kebuang diam-diam dan tabelnya nggak punya '
                    .'kolom pembacaan sama sekali.',
                    $kode,
                    $i,
                ),
            );
        }
    }
}
