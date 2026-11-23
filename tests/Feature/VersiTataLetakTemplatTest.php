<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Penjaga nomor versi templat OCR: tata letak sebuah templat TIDAK BOLEH berubah
 * di bawah nomor versi yang sama.
 *
 * ## Kenapa
 *
 * Pemetaan teks OCR ke sel dikunci per versi formulir cetak. Kalau dua revisi
 * memakai nomor yang sama, foto dari formulir lama dibaca dengan koordinat
 * formulir baru (atau sebaliknya) dan angka mendarat di sel yang salah tanpa
 * satu galat pun. `PemrosesScanLembarKerja` sudah mengingatkan ini: titik ukur
 * dan standar yang dipegang HP harus sama dengan yang dipegang server, kalau
 * tidak "APK-nya megang lembar versi lain walau nomor versinya kebetulan sama".
 * Tes ini menangkapnya di sumbernya, sebelum sampai ke HP.
 *
 * ## Cara kerja
 *
 * `tests/Fixtures/ocr/ledger-tata-letak.json` mencatat sidik (sha256) tata letak
 * tiap versi: kunci versi => sidik. Ledger HANYA BERTAMBAH:
 *
 *  - sidik versi yang sudah tercatat berubah  => GAGAL ("naikkan nomor versi");
 *  - versi tercatat hilang dari repo          => GAGAL (berkas versi lama wajib tetap ada);
 *  - versi baru belum tercatat                => GAGAL (daftarkan, lalu ia diperiksa pemetaannya);
 *  - dua berkas memakai kunci versi yang sama => GAGAL.
 *
 * Templat cetak: SEMUA berkas `{kode}-v{N}.json` dijaga, bukan hanya versi tertinggi, dan sidiknya
 * hanya dari isi berkas (tidak dari kode profil). Himpunan sel yang berubah di profil tanpa menyentuh
 * berkas geometri TIDAK tertangkap di sini.
 *
 * Sidik hanya memuat TATA LETAK. Status verifikasi (`terverifikasi`,
 * `perlu_konfirmasi_lab`), catatan (`_*`), dan sidik berkas sumber PDF tidak ikut,
 * jadi memverifikasi sebuah versi tidak mengubah sidiknya.
 *
 * ## Cara menaikkan versi (revisi formulir cetak baru)
 *
 * 1. JANGAN mengubah berkas versi lama. Buat berkas geometri/peta BARU untuk revisi baru
 *    (formulir asli: peta + draf baru dengan `kode_dokumen` berisi revisi baru, mis.
 *    `_Rev.5`; templat cetak: `{kode}-v{N+1}.json` dengan `versi` naik).
 * 2. Daftarkan ke ledger: `PERBARUI_LEDGER=1 php artisan test --filter=VersiTataLetakTemplatTest`
 *    (hanya MENAMBAH entri; tidak pernah menimpa sidik yang sudah ada).
 * 3. Versi baru belum terverifikasi sampai pemetaannya diuji pada foto nyata formulir versi itu.
 *
 * Catatan: ledger awal (23 Nov 2026) hanya merekam keadaan repo saat itu. Itu BUKAN
 * pernyataan bahwa semua pemetaan sudah terverifikasi (55 dari 55 peta formulir asli
 * masih `terverifikasi: false`).
 */
class VersiTataLetakTemplatTest extends TestCase
{
    use RefreshDatabase;

    private const LEDGER = 'tests/Fixtures/ocr/ledger-tata-letak.json';

    private const KUNCI_STATUS_ASLI = ['terverifikasi', 'perlu_konfirmasi_lab', 'sumber_sha256'];

    // ----------------------------------------------------------------- sidik

    /**
     * Sidik tata letak SATU peta formulir asli: peta (tanpa status verifikasi dan
     * catatan) + isi berkas draf geometrinya.
     *
     * @param  array<string, mixed>  $peta
     */
    private function sidikPeta(array $peta, string $isiDraf): string
    {
        foreach (self::KUNCI_STATUS_ASLI as $k) {
            unset($peta[$k]);
        }
        if (isset($peta['sel']) && is_array($peta['sel'])) {
            usort($peta['sel'], static fn ($a, $b): int => strcmp((string) ($a['kunci'] ?? ''), (string) ($b['kunci'] ?? '')));
        }

        return hash('sha256', (string) json_encode([
            'peta' => $this->kanon($peta),
            'draf' => hash('sha256', str_replace("\r\n", "\n", $isiDraf)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Bentuk kanonik: kunci diurutkan, kunci berawalan `_` (catatan) dibuang,
     * float dibulatkan ke 6 desimal sebagai teks supaya tidak bergantung pada
     * `serialize_precision` mesin.
     */
    private function kanon(mixed $v): mixed
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(fn ($x) => $this->kanon($x), $v);
            }
            $o = [];
            foreach ($v as $k => $x) {
                if (is_string($k) && str_starts_with($k, '_')) {
                    continue;
                }
                $o[$k] = $this->kanon($x);
            }
            ksort($o);

            return $o;
        }

        return is_float($v) ? 'f'.number_format($v, 6, '.', '') : $v;
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>} [kunci versi => sidik, kunci kembar]
     */
    private function sidikSekarang(): array
    {
        $hasil = [];
        $kembar = [];

        foreach (glob(database_path('ocr-templates/asli/*.peta.json')) ?: [] as $berkas) {
            $peta = json_decode((string) file_get_contents($berkas), true);
            $this->assertIsArray($peta, basename($berkas).' bukan JSON yang valid.');

            $draf = database_path('ocr-templates/asli/'.($peta['draf'] ?? ''));
            $this->assertFileExists($draf, basename($berkas).' menunjuk draf yang tidak ada.');

            $kunci = 'asli|'.($peta['template_id'] ?? '?').'|'.($peta['kode_dokumen'] ?? '?');
            if (isset($hasil[$kunci])) {
                $kembar[] = $kunci;
            }
            $hasil[$kunci] = $this->sidikPeta($peta, (string) file_get_contents($draf));
        }

        // Folder di repo, bukan `TemplateLembarKerja::folderTemplate()`: yang itu bergantung pada
        // config/env dan bisa menunjuk folder lain, sedangkan ledger harus menjaga berkas repo.
        [$cetak, $kembarCetak] = $this->sidikCetak(database_path('ocr-templates'));
        $hasil += $cetak;
        $kembar = array_merge($kembar, $kembarCetak);

        ksort($hasil);

        return [$hasil, $kembar];
    }

    /**
     * Sidik SEMUA berkas geometri cetak `{kode}-v{N}.json` di folder itu, bukan
     * hanya versi tertinggi per kode: menambah `-v2` tidak boleh menghilangkan `v1`
     * dari pemeriksaan, karena foto dari formulir lama masih memakai koordinat v1.
     *
     * Sidik hanya dari ISI berkas geometri, tidak dari kode profil: sidik versi lama
     * yang ikut berubah saat profil berubah akan memaksa menyunting ledger append-only.
     * Akibatnya perubahan himpunan sel di PROFIL (tanpa menyentuh berkas geometri)
     * tidak tertangkap di sini; itu urusan tes profil dan paritas.
     *
     * Templat tanpa berkas geometri (belum diukur) tidak punya versi, jadi tidak
     * punya entri.
     *
     * @return array{0: array<string, string>, 1: list<string>} [kunci versi => sidik, kunci kembar]
     */
    private function sidikCetak(string $folder): array
    {
        $hasil = [];
        $kembar = [];

        foreach (glob($folder.'/*-v*.json') ?: [] as $berkas) {
            $nama = basename($berkas);
            // Cocok pola `*-v*.json` tetapi bukan `{kode}-v{N}.json` (mis. `oven-v2b.json`): TIDAK
            // boleh dilewati diam-diam, karena `TemplateLembarKerja::geometri()` memilih berkas
            // dengan glob yang sama lalu `rsort` natural, jadi berkas itu bisa terpilih sebagai
            // versi tertinggi tanpa pernah masuk ledger.
            if (! preg_match('/^(.+)-v(\d+)\.json$/', $nama, $cocok)) {
                $this->fail($nama.': cocok pola `*-v*.json` tetapi bukan `{kode}-v{N}.json` (N angka). Ganti namanya atau pindahkan keluar folder templat.');
            }

            $isi = json_decode((string) file_get_contents($berkas), true);
            $this->assertIsArray($isi, $nama.' bukan JSON yang valid.');
            // Nomor di nama berkas dan di isinya harus sepakat; kalau tidak, ledger
            // mengunci satu nomor sementara aplikasi membaca nomor lain.
            $this->assertSame((int) $cocok[2], (int) ($isi['versi'] ?? 0), $nama.': `versi` di isi berbeda dari nomor di nama berkas.');
            $this->assertSame($cocok[1], $isi['template_id'] ?? null, $nama.': `template_id` di isi berbeda dari nama berkas.');
            unset($isi['terverifikasi'], $isi['versi']);

            $kunci = 'cetak|'.$cocok[1].'|v'.(int) $cocok[2];
            if (isset($hasil[$kunci])) {
                $kembar[] = $kunci;
            }
            $hasil[$kunci] = hash('sha256', (string) json_encode(
                ['geo' => $this->kanon($isi)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        }

        ksort($hasil);

        return [$hasil, $kembar];
    }

    /** @return array<string, string> */
    private function ledger(): array
    {
        $berkas = base_path(self::LEDGER);
        if (! is_file($berkas)) {
            return [];
        }
        $isi = json_decode((string) file_get_contents($berkas), true);

        return is_array($isi['entri'] ?? null) ? $isi['entri'] : [];
    }

    // ------------------------------------------------------------- pembaruan

    /** Hanya jalan dengan PERBARUI_LEDGER=1; hanya MENAMBAH entri baru. */
    public function test_0_perbarui_ledger_hanya_menambah_entri_baru(): void
    {
        if (getenv('PERBARUI_LEDGER') !== '1') {
            $this->markTestSkipped('Setel PERBARUI_LEDGER=1 untuk mendaftarkan versi baru ke ledger.');
        }

        [$sekarang] = $this->sidikSekarang();
        $entri = $this->ledger();
        $ditambah = 0;
        foreach ($sekarang as $kunci => $sidik) {
            if (! isset($entri[$kunci])) {
                $entri[$kunci] = $sidik;
                $ditambah++;
            }
        }
        ksort($entri);

        $berkas = base_path(self::LEDGER);
        if (! is_dir(dirname($berkas))) {
            mkdir(dirname($berkas), 0777, true);
        }
        file_put_contents($berkas, json_encode([
            '_catatan' => 'Ledger APPEND-ONLY sidik tata letak templat OCR per versi. Jangan menyunting sidik yang sudah ada: '
                .'kalau tata letak berubah, naikkan nomor versi (berkas baru) dan daftarkan versi baru. '
                .'Mencatat versi BUKAN berarti pemetaannya terverifikasi. Lihat VersiTataLetakTemplatTest.',
            'entri' => $entri,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->addToAssertionCount(1);
        fwrite(STDERR, "\n[ledger] {$ditambah} entri baru ditambahkan.\n");
    }

    // ----------------------------------------------------------------- tes

    public function test_tata_letak_tidak_berubah_di_bawah_nomor_versi_yang_sama(): void
    {
        [$sekarang] = $this->sidikSekarang();
        $berubah = [];
        foreach ($this->ledger() as $kunci => $sidikLama) {
            if (isset($sekarang[$kunci]) && $sekarang[$kunci] !== $sidikLama) {
                $berubah[] = $kunci;
            }
        }

        $this->assertSame(
            [],
            $berubah,
            'Tata letak templat berubah TETAPI nomor versinya tidak naik: '.implode(', ', $berubah)
            .'. Jangan menyunting berkas versi lama. Buat berkas BARU untuk revisi baru dengan nomor versi baru '
            .'(foto dari formulir lama masih memakai koordinat lama) dan daftarkan ke ledger (lihat kepala tes ini).',
        );
    }

    public function test_berkas_versi_lama_tidak_boleh_hilang(): void
    {
        [$sekarang] = $this->sidikSekarang();
        $hilang = array_values(array_diff(array_keys($this->ledger()), array_keys($sekarang)));

        $this->assertSame(
            [],
            $hilang,
            'Versi yang pernah tercatat hilang dari repo: '.implode(', ', $hilang)
            .'. Berkas versi lama WAJIB tetap disimpan supaya foto dari formulir lama masih bisa diproses dengan koordinat yang benar.',
        );
    }

    public function test_setiap_versi_aktif_tercatat_di_ledger(): void
    {
        [$sekarang] = $this->sidikSekarang();
        $belum = array_values(array_diff(array_keys($sekarang), array_keys($this->ledger())));

        $this->assertSame(
            [],
            $belum,
            'Versi templat belum tercatat di ledger: '.implode(', ', $belum)
            .'. Daftarkan dengan `PERBARUI_LEDGER=1 php artisan test --filter=VersiTataLetakTemplatTest`. '
            .'Versi baru BELUM terverifikasi sampai pemetaannya diuji pada foto nyata formulir versi itu.',
        );
    }

    public function test_tidak_ada_dua_berkas_dengan_kunci_versi_yang_sama(): void
    {
        [, $kembar] = $this->sidikSekarang();

        $this->assertSame(
            [],
            array_values(array_unique($kembar)),
            'Dua berkas memakai kunci versi yang sama (template_id + revisi): '.implode(', ', array_unique($kembar))
            .'. Dua revisi tidak boleh berbagi nomor.',
        );
    }

    /**
     * Jalur yang diajarkan AGENTS.md butir 10: revisi baru = berkas `-v2` BARU,
     * berkas `-v1` tidak disentuh. Tes ini membuktikan menambah v2 tidak mengubah
     * sidik v1 dan v1 tetap ikut dijaga (tidak jatuh dari daftar).
     */
    public function test_menambah_versi_baru_tidak_mengubah_sidik_versi_lama(): void
    {
        $folder = sys_get_temp_dir().'/ledger-uji-'.bin2hex(random_bytes(4));
        mkdir($folder, 0777, true);

        try {
            $v1 = ['template_id' => 'contoh', 'versi' => 1, 'terverifikasi' => false, 'tabel' => ['x' => 0.5]];
            file_put_contents($folder.'/contoh-v1.json', json_encode($v1));
            [$sebelum] = $this->sidikCetak($folder);

            $v2 = ['template_id' => 'contoh', 'versi' => 2, 'terverifikasi' => false, 'tabel' => ['x' => 0.7]];
            file_put_contents($folder.'/contoh-v2.json', json_encode($v2));
            [$sesudah] = $this->sidikCetak($folder);

            $this->assertSame(['cetak|contoh|v1'], array_keys($sebelum));
            $this->assertSame(['cetak|contoh|v1', 'cetak|contoh|v2'], array_keys($sesudah));
            $this->assertSame($sebelum['cetak|contoh|v1'], $sesudah['cetak|contoh|v1'], 'Sidik v1 berubah hanya karena v2 ditambahkan.');
            $this->assertNotSame($sesudah['cetak|contoh|v1'], $sesudah['cetak|contoh|v2']);

            // Menyunting v1 (yang dilarang) tetap tertangkap.
            $v1['tabel']['x'] = 0.51;
            file_put_contents($folder.'/contoh-v1.json', json_encode($v1));
            [$disunting] = $this->sidikCetak($folder);
            $this->assertNotSame($sebelum['cetak|contoh|v1'], $disunting['cetak|contoh|v1']);
        } finally {
            foreach (glob($folder.'/*') ?: [] as $berkas) {
                unlink($berkas);
            }
            rmdir($folder);
        }
    }

    /** Berkas yang cocok pola `*-v*.json` tetapi bernama janggal harus menggagalkan tes, bukan dilewati. */
    public function test_berkas_versi_bernama_janggal_menggagalkan_tes(): void
    {
        $folder = sys_get_temp_dir().'/ledger-janggal-'.bin2hex(random_bytes(4));
        mkdir($folder, 0777, true);

        try {
            // Isi VALID: tes ini harus gagal karena NAMA berkasnya, bukan karena isinya.
            file_put_contents($folder.'/oven-v2b.json', '{"template_id":"oven","versi":2}');

            $this->expectException(AssertionFailedError::class);
            $this->expectExceptionMessage('cocok pola `*-v*.json` tetapi bukan');
            $this->sidikCetak($folder);
        } finally {
            foreach (glob($folder.'/*') ?: [] as $berkas) {
                unlink($berkas);
            }
            rmdir($folder);
        }
    }

    /** Bukti bahwa penjaganya benar-benar menangkap perubahan, bukan hanya lolos. */
    public function test_sidik_peka_pada_tata_letak_dan_tidak_pada_status_verifikasi(): void
    {
        $peta = [
            'template_id' => 'contoh',
            'kode_dokumen' => 'SIDIK-FM-CAL-0000_Rev.1',
            'draf' => 'contoh.draf.json',
            'koordinat' => 'ternormal_0_1',
            'terverifikasi' => false,
            'sumber_sha256' => 'aaa',
            '_catatan' => 'awal',
            'sel' => [
                ['kunci' => 'b|1|1|pembacaan', 'x' => 0.5405, 'y' => 0.388],
                ['kunci' => 'a|1|1|pembacaan', 'x' => 0.6065, 'y' => 0.388],
            ],
        ];
        $dasar = $this->sidikPeta($peta, '{"kotak":[1,2,3]}');

        // Berubah: satu sel bergeser 0,001, sel dihapus, draf berbeda, revisi sama tetapi kunci sel berganti.
        $geser = $peta;
        $geser['sel'][0]['x'] = 0.5415;
        $this->assertNotSame($dasar, $this->sidikPeta($geser, '{"kotak":[1,2,3]}'), 'Pergeseran sel tidak terdeteksi.');

        $kurang = $peta;
        array_pop($kurang['sel']);
        $this->assertNotSame($dasar, $this->sidikPeta($kurang, '{"kotak":[1,2,3]}'), 'Sel yang hilang tidak terdeteksi.');

        $this->assertNotSame($dasar, $this->sidikPeta($peta, '{"kotak":[1,2,4]}'), 'Draf yang berubah tidak terdeteksi.');

        $ganti = $peta;
        $ganti['sel'][0]['kunci'] = 'c|1|1|pembacaan';
        $this->assertNotSame($dasar, $this->sidikPeta($ganti, '{"kotak":[1,2,3]}'), 'Kunci sel yang berganti tidak terdeteksi.');

        // Tetap sama: status verifikasi, catatan, sidik sumber, urutan sel, akhir baris berkas draf.
        $netral = $peta;
        $netral['terverifikasi'] = true;
        $netral['perlu_konfirmasi_lab'] = true;
        $netral['sumber_sha256'] = 'bbb';
        $netral['_catatan'] = 'sudah diverifikasi';
        $netral['sel'] = array_reverse($netral['sel']);
        $this->assertSame($dasar, $this->sidikPeta($netral, '{"kotak":[1,2,3]}'), 'Verifikasi/catatan/urutan tidak boleh mengubah sidik.');
        $this->assertSame(
            $this->sidikPeta($peta, "{\n\"a\":1\n}"),
            $this->sidikPeta($peta, "{\r\n\"a\":1\r\n}"),
            'Akhir baris CRLF vs LF tidak boleh mengubah sidik.',
        );
    }
}
