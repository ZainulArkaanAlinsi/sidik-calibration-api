<?php

namespace App\Services\Studio\Workbook;

use App\Services\Studio\Hitung\ModelHitung;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\DefaultReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Font as SharedFont;
use PhpOffice\PhpSpreadsheet\Shared\OLE;
use PhpOffice\PhpSpreadsheet\Shared\Xlsx\AgileEncryption;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use SensitiveParameter;
use Throwable;

/**
 * `.xlsm` (atau `.xlsx`) workbook master → daftar lembar dengan nilai, rumus,
 * format, gaya ringkas, dan tata letak (Studio S1b, ADR-49-10 Opsi A, revisi).
 *
 * ## Dekripsi: ke temp PRIVAT per impor, bukan temp sistem
 *
 * Spike S0c (13 Okt) membuktikan `Reader\Xlsx::load()` dengan
 * `setEncryptionPassword()` menulis ZIP TERDEKRIPSI (termasuk sheet DATABASE)
 * ke `sys_get_temp_dir()`, dan berkas itu TERTINGGAL bila exception terjadi di
 * tengah load (Windows: ZIP masih dipegang reader saat `@unlink`) atau proses
 * mati. `sys_temp_dir` itu PHP_INI_SYSTEM — tidak bisa dipindah dari dalam
 * proses. Jadi kelas ini TIDAK memakai jalur dekripsi reader. Ia mendekripsi
 * sendiri lewat API publik `AgileEncryption::decryptFile()` ke subfolder acak
 * di `config('studio.temp_workbook')` (storage/app/private/tmp-workbook), lalu
 * memberi reader ZIP polos itu. Urutan pembersihan:
 *
 *   1. reader & spreadsheet hanya hidup di dalam `muat()` — keluar dari fungsi
 *      itu (sukses ATAU exception) melepas ZipArchive-nya;
 *   2. `finally` di `baca()`: `gc_collect_cycles()` lalu hapus subfolder;
 *   3. proses mati (fatal, kehabisan memori): shutdown handler menghapus
 *      subfolder yang masih terdaftar aktif;
 *   4. dibunuh paksa: `studio:sapu-temp-workbook` (terjadwal tiap jam, juga
 *      dipanggil di awal tiap impor) menghapus subfolder berumur > 1 jam.
 *
 * Batas putaran kunci `setMaxEncryptionSpinCount(100000)` (profil workbook
 * lab): berkas ber-spin lebih besar ditolak sebelum penurunan kunci yang mahal.
 *
 * ## Sandi
 *
 * Tidak pernah disimpan, tidak pernah dilog, tidak pernah masuk pesan galat.
 * Parameter yang membawanya bertanda `#[SensitiveParameter]` (jejak exception
 * menyamarkannya), dan exception dari jalur dekripsi TIDAK dirantai ke galat
 * yang dilempar keluar — jejak `decryptFile()` membawa sandi sebagai argumen.
 *
 * ## Yang diambil & yang tidak
 *
 * Nilai CACHE (`getOldCalculatedValue`), bukan hasil hitung ulang: rumus tidak
 * pernah dievaluasi di sini. `s` diformat dari nilai cache dengan number format
 * sel. SEMUA sheet dibaca apa adanya, termasuk DATABASE / SERTIFIKAT / konsep
 * (K-49-12 mencabut pengecualian isi K-49-08); sheet itu hanya ditandai
 * `tautan_tabel`. Shape/tombol form VML tidak terbaca PhpSpreadsheet (S0c) —
 * tidak ada di tata letak.
 *
 * ## Kesetiaan tata letak (format kanonik 3)
 *
 * Semuanya dari model PhpSpreadsheet, tanpa membaca XML sendiri:
 *  - lebar kolom / tinggi baris per kolom/baris + bawaan sheet
 *    (`defaultColWidth`, `defaultRowHeight` ber-`customHeight`); bila sheet
 *    tidak menyebutnya → `Shared\Font` bawaan PhpSpreadsheet untuk font bawaan
 *    workbook. `baseColWidth` TIDAK dibaca PhpSpreadsheet, jadi sheet yang
 *    hanya mengubah `baseColWidth` tanpa `defaultColWidth` memakai lebar
 *    bawaan font (8 karakter);
 *  - freeze dari `xSplit`/`ySplit` pane berstatus frozen (bukan `topLeftCell`,
 *    yang ikut posisi gulir saat master disimpan), cadangan `getFreezePane()`;
 *  - garis kisi (`showGridLines`) dan zoom sheet;
 *  - gaya sel: tebal/miring/warna/isi/rata/vertikal/bungkus/ukuran/font/garis.
 *    Sel kosong disimpan hanya bila ber-isi atau ber-garis (yang digambar
 *    Excel walau kosong). Gaya baris/kolom utuh (`<row s>`, `<col style>`)
 *    tanpa elemen sel tidak ikut — batas yang diketahui;
 *  - proteksi lembar (format 5, K-49-15): `dilindungi` + `sel_tidak_terkunci`
 *    (`ProteksiLembar` — status kunci MEMAKAI gaya baris/kolom utuh).
 */
class PembacaXlsmWorkbook
{
    public const SPIN_MAKS = 100000;

    private const MAGIC_OLE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    private const MAGIC_ZIP = "PK\x03\x04";

    /** @var array<string, true> subfolder temp yang sedang dipakai proses ini */
    private static array $aktif = [];

    private static bool $penutupTerdaftar = false;

    /** @var array<int, array<string, mixed>|null> gaya ringkas per indeks xf */
    private array $cacheGaya = [];

    /** @var array<int, ?string> number format per indeks xf */
    private array $cacheFormat = [];

    /** Font bawaan workbook (gaya xf 0) — pembanding `g.font` / `g.ukuran`. */
    private ?string $fontBawaan = null;

    private ?float $ukuranBawaan = null;

    public function __construct(private readonly PengecualianLembar $pengecualian) {}

    /**
     * `lembar` = data impor per lembar; `model_hitung` = bentuk ringkas untuk
     * mesin `/hitung` (tahap C, dibangun dari muatan yang sama — sandi tidak
     * perlu diminta lagi); `gambar` = byte gambar per id (tahap C, disimpan
     * `ImporWorkbook`, tidak ikut kanonik).
     *
     * @return array{lembar: list<array<string, mixed>>, terenkripsi: bool, model_hitung: ModelHitung, gambar: array<string, array{jenis: string, byte: string}>}
     *
     * @throws GagalBacaWorkbook
     */
    public function baca(string $jalur, #[SensitiveParameter] string $sandi): array
    {
        return $this->denganZipPolos($jalur, $sandi, function (string $zip, bool $terenkripsi): array {
            return ['terenkripsi' => $terenkripsi] + $this->muat($zip);
        });
    }

    /**
     * Model hitung saja (Studio tahap C), dimuat `readDataOnly` — lebih cepat
     * dari `baca()` dan tanpa gaya. Dipakai `SumberModelHitung` (cache kosong)
     * dan harness paritas.
     *
     * @throws GagalBacaWorkbook
     */
    public function muatModelHitung(string $jalur, #[SensitiveParameter] string $sandi): ModelHitung
    {
        return $this->denganZipPolos($jalur, $sandi, function (string $zip): ModelHitung {
            $reader = new XlsxReader;
            $reader->setReadDataOnly(true);
            $reader->setIncludeCharts(false);

            try {
                $ss = $reader->load($zip);
            } catch (Throwable $e) {
                throw new GagalBacaWorkbook(GagalBacaWorkbook::GAGAL_MUAT, 'Workbook gagal dimuat: '.mb_substr($e->getMessage(), 0, 200));
            }

            try {
                return ModelHitung::dariSpreadsheet($ss);
            } finally {
                $ss->disconnectWorksheets();
            }
        });
    }

    /**
     * Jalankan `$fn(zipPolos, terenkripsi)` atas paket OOXML berkas ini: ZIP
     * polos dibaca langsung; berkas terenkripsi didekripsi dulu ke temp
     * PRIVAT yang dihapus di `finally` (lihat kepala kelas).
     *
     * @template T
     *
     * @param  callable(string, bool): T  $fn
     * @return T
     *
     * @throws GagalBacaWorkbook
     */
    public function denganZipPolos(string $jalur, #[SensitiveParameter] string $sandi, callable $fn): mixed
    {
        $kepala = (string) @file_get_contents($jalur, false, null, 0, 8);

        if (str_starts_with($kepala, self::MAGIC_ZIP)) {
            // Tidak terenkripsi: dibaca langsung, tanpa temp sama sekali.
            return $fn($jalur, false);
        }

        if ($kepala !== self::MAGIC_OLE) {
            throw new GagalBacaWorkbook(GagalBacaWorkbook::BUKAN_OOXML, 'Berkas bukan workbook Excel (.xlsm/.xlsx).');
        }

        $dir = $this->buatTempPrivat();

        try {
            $zip = $this->dekripsi($jalur, $sandi, $dir);

            return $fn($zip, true);
        } finally {
            // Reader sudah keluar lingkup bersama `muat()`; siklus Spreadsheet
            // dilepas di sini supaya ZipArchive benar-benar tertutup sebelum
            // berkasnya dihapus (Windows menolak unlink berkas yang terbuka).
            gc_collect_cycles();
            self::hapusTemp($dir);
        }
    }

    /** Berkas OLE (paket OOXML bersandi)? Dicek dari 8 byte kepala, tanpa membuka. */
    public static function bersandi(string $jalur): bool
    {
        return (string) @file_get_contents($jalur, false, null, 0, 8) === self::MAGIC_OLE;
    }

    /**
     * Folder temp privat sekali pakai untuk `$fn(folder)` — dihapus di
     * `finally` (dipakai `SumberModelHitung` untuk berkas `.enc`).
     *
     * @template T
     *
     * @param  callable(string): T  $fn
     * @return T
     */
    public function denganTempPrivat(callable $fn): mixed
    {
        $dir = $this->buatTempPrivat();

        try {
            return $fn($dir);
        } finally {
            gc_collect_cycles();
            self::hapusTemp($dir);
        }
    }

    /** Akar temp privat (subfolder per impor dibuat di bawahnya). */
    public static function akarTemp(): string
    {
        return (string) config('studio.temp_workbook');
    }

    /**
     * Saringan baca: semua sel dibaca (K-49-12). Tetap dipisah supaya test
     * bisa menyuntik kegagalan TEPAT di tengah `load()`.
     */
    protected function saringan(): IReadFilter
    {
        return new DefaultReadFilter;
    }

    private function buatTempPrivat(): string
    {
        $akar = self::akarTemp();

        if (! is_dir($akar) && ! @mkdir($akar, 0700, true) && ! is_dir($akar)) {
            throw new GagalBacaWorkbook(GagalBacaWorkbook::GAGAL_MUAT, 'Folder temp workbook tidak bisa dibuat.');
        }

        $dir = $akar.DIRECTORY_SEPARATOR.'impor-'.bin2hex(random_bytes(16));

        if (! @mkdir($dir, 0700)) {
            throw new GagalBacaWorkbook(GagalBacaWorkbook::GAGAL_MUAT, 'Subfolder temp workbook tidak bisa dibuat.');
        }

        self::$aktif[$dir] = true;

        if (! self::$penutupTerdaftar) {
            self::$penutupTerdaftar = true;
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$aktif) as $sisa) {
                    self::hapusTemp($sisa);
                }
            });
        }

        return $dir;
    }

    private function dekripsi(string $jalur, #[SensitiveParameter] string $sandi, string $dir): string
    {
        if ($sandi === '') {
            throw new GagalBacaWorkbook(GagalBacaWorkbook::SANDI_SALAH, 'Workbook terenkripsi, sandi kosong.');
        }

        $paket = $dir.DIRECTORY_SEPARATOR.'paket.bin';
        $zip = $dir.DIRECTORY_SEPARATOR.'isi.zip';

        try {
            $ole = new OLE;
            $ole->read($jalur);

            if (! $ole->hasDataByName('EncryptionInfo') || ! $ole->hasDataByName('EncryptedPackage')) {
                throw new GagalBacaWorkbook(GagalBacaWorkbook::BUKAN_OOXML, 'Berkas OLE tanpa paket terenkripsi (bukan .xlsm/.xlsx terenkripsi).');
            }

            $info = AgileEncryption::parse($ole->getDataByName('EncryptionInfo'), self::SPIN_MAKS);

            $h = fopen($paket, 'wb');

            if ($h === false) {
                throw new GagalBacaWorkbook(GagalBacaWorkbook::GAGAL_MUAT, 'Temp paket terenkripsi tidak bisa ditulis.');
            }

            try {
                $ole->copyDataByName('EncryptedPackage', $h);
            } finally {
                fclose($h);
            }

            unset($ole);

            AgileEncryption::decryptFile($info, $paket, $zip, $sandi);
        } catch (GagalBacaWorkbook $e) {
            throw $e;
        } catch (Throwable $e) {
            // SENGAJA tidak dirantai: jejak decryptFile() membawa sandi.
            throw new GagalBacaWorkbook(...self::petakanGalatDekripsi($e->getMessage()));
        } finally {
            @unlink($paket);
        }

        return $zip;
    }

    /** @return array{0: string, 1: string} */
    private static function petakanGalatDekripsi(string $pesan): array
    {
        return match (true) {
            str_contains($pesan, 'password is incorrect'), str_contains($pesan, 'password required') => [GagalBacaWorkbook::SANDI_SALAH, 'Sandi workbook salah.'],
            str_contains($pesan, 'Unsupported XLSX encryption profile') => [GagalBacaWorkbook::PROFIL_ENKRIPSI_DITOLAK, 'Profil enkripsi ditolak (bukan Agile AES/SHA, atau putaran kunci > '.self::SPIN_MAKS.').'],
            default => [GagalBacaWorkbook::BERKAS_RUSAK, 'Paket terenkripsi rusak atau tidak bisa dibaca.'],
        };
    }

    /**
     * Reader & Spreadsheet HANYA hidup di fungsi ini.
     *
     * @return array{lembar: list<array<string, mixed>>, model_hitung: ModelHitung, gambar: array<string, array{jenis: string, byte: string}>}
     */
    private function muat(string $zip): array
    {
        $reader = new XlsxReader;
        $reader->setMaxEncryptionSpinCount(self::SPIN_MAKS);
        $reader->setIncludeCharts(false);
        $reader->setReadFilter($this->saringan());

        try {
            $ss = $reader->load($zip);
        } catch (GagalBacaWorkbook $e) {
            throw $e;
        } catch (Throwable $e) {
            // Tidak dirantai: dengan `zend.exception_ignore_args` mati, jejak
            // exception reader menahan argumen objek (Worksheet, XML) — dan
            // selama jejak itu hidup, ZIP terdekripsi bisa tetap terpegang.
            throw new GagalBacaWorkbook(GagalBacaWorkbook::GAGAL_MUAT, 'Workbook gagal dimuat: '.mb_substr($e->getMessage(), 0, 200));
        }

        try {
            return $this->ekstrak($ss);
        } finally {
            $ss->disconnectWorksheets();
            $this->cacheGaya = [];
            $this->cacheFormat = [];
            $this->fontBawaan = null;
            $this->ukuranBawaan = null;
        }
    }

    /** @return array{lembar: list<array<string, mixed>>, model_hitung: ModelHitung, gambar: array<string, array{jenis: string, byte: string}>} */
    private function ekstrak(Spreadsheet $ss): array
    {
        $fontBawaan = $ss->getDefaultStyle()->getFont();
        $this->fontBawaan = $fontBawaan->getName();
        $this->ukuranBawaan = $fontBawaan->getSize();

        $lembar = [];
        $gambar = [];
        $proteksi = new ProteksiLembar($ss);

        foreach ($ss->getAllSheets() as $i => $sheet) {
            $tersembunyi = match ($sheet->getSheetState()) {
                Worksheet::SHEETSTATE_HIDDEN => 'hidden',
                Worksheet::SHEETSTATE_VERYHIDDEN => 'veryHidden',
                default => 'tidak',
            };

            $lembar[] = $this->lembar($ss, $sheet, $i + 1, $tersembunyi, $gambar, $proteksi);
        }

        return ['lembar' => $lembar, 'model_hitung' => ModelHitung::dariSpreadsheet($ss), 'gambar' => $gambar];
    }

    /**
     * @param  array<string, array{jenis: string, byte: string}>  $gambar  diisi byte gambar lembar ini
     * @return array<string, mixed>
     */
    private function lembar(Spreadsheet $ss, Worksheet $sheet, int $urutan, string $tersembunyi, array &$gambar, ProteksiLembar $proteksi): array
    {
        $koleksi = $sheet->getCellCollection();
        $sel = [];
        $rumus = 0;
        $cf = FormatBersyarat::aturan($sheet);
        $gayaDasarCf = [];

        foreach ($koleksi->getCoordinates() as $alamat) {
            $cell = $koleksi->get($alamat);

            if ($cell === null) {
                continue;
            }

            $s = $this->sel($ss, $cell);

            if ($s === null) {
                continue;
            }

            // Tahap C: conditional formatting diterapkan server ke `g` dari nilai
            // cache; gaya sebelum CF disimpan untuk `/hitung`.
            if ($cf !== [] && FormatBersyarat::dalamRentang($cf, $s['b'], $s['k'])) {
                $gayaDasarCf[$s['a']] = $s['g'];
                $s['g'] = FormatBersyarat::terapkan($cf, $s['b'], $s['k'], $this->nilaiMentah($cell), $s['g']);
            }

            $sel[] = $s;

            if ($s['r'] !== null) {
                $rumus++;
            }
        }

        // Rentang terpakai = sel yang ada di koleksi (termasuk sel bergaya tanpa
        // nilai). Lembar tanpa satu sel pun → 0 × 0, bukan 1 × A bawaan.
        $kosong = $koleksi->getCoordinates() === [];
        $jumlahBaris = $kosong ? 0 : $koleksi->getHighestRow();
        $jumlahKolom = $kosong ? 0 : Coordinate::columnIndexFromString($koleksi->getHighestColumn());

        $tata = $this->tataLetak($ss, $sheet, $jumlahBaris, $jumlahKolom);
        $tata['gambar'] = $this->gambar($sheet, $gambar);
        $tata['cf'] = array_map(static fn (array $a): array => array_diff_key($a, ['batas' => true]), $cf);
        $tata['cf_gaya_dasar'] = $gayaDasarCf;
        // Format 5 (K-49-15): proteksi lembar + himpunan sel yang TIDAK terkunci.
        $tata = array_replace($tata, $proteksi->dariSheet($sheet));

        return [
            'urutan' => $urutan,
            'nama' => $sheet->getTitle(),
            'tersembunyi' => $tersembunyi,
            'dikecualikan' => false,
            'alasan_dikecualikan' => null,
            'tautan_tabel' => $this->pengecualian->tautan($sheet->getTitle()),
            'jumlah_baris' => $jumlahBaris,
            'jumlah_kolom' => $jumlahKolom,
            'jumlah_sel' => count($sel),
            'jumlah_rumus' => $rumus,
            'tata_letak' => $tata,
            'sel_kanonik' => KanonikWorkbook::sel($sel),
        ];
    }

    /** Nilai sel apa adanya (cache untuk rumus) — masukan conditional formatting. */
    private function nilaiMentah(Cell $cell): mixed
    {
        $v = $cell->getDataType() === DataType::TYPE_FORMULA ? $cell->getOldCalculatedValue() : $cell->getValue();

        return $v instanceof RichText ? $v->getPlainText() : $v;
    }

    /**
     * Gambar (drawing) lembar: jangkar sel kiri-atas + offset px + ukuran px.
     * Id = 32 hex pertama sha256 byte gambar — gambar yang sama dipakai di
     * banyak tempat cukup disimpan sekali. PNG/JPEG dikenali dari byte
     * ajaibnya; jenis lain (`emf`, `wmf`, …) tetap didaftar sebagai `lain` +
     * `ekstensi`, bytenya tidak disimpan (GET gambar menjawab 415).
     *
     * @param  array<string, array{jenis: string, byte: string}>  $simpan
     * @return list<array<string, mixed>>
     */
    private function gambar(Worksheet $sheet, array &$simpan): array
    {
        $hasil = [];

        foreach ($sheet->getDrawingCollection() as $d) {
            $byte = null;

            if ($d instanceof Drawing) {
                $jalur = $d->getPath();
                $byte = $jalur !== '' ? @file_get_contents($jalur) : false;
                $ekstensi = strtolower((string) $d->getExtension());
            } elseif ($d instanceof MemoryDrawing) {
                $ekstensi = strtolower(pathinfo($d->getIndexedFilename(), PATHINFO_EXTENSION));
            } else {
                continue;
            }

            if (! is_string($byte) || $byte === '') {
                continue;
            }

            $jenis = match (true) {
                str_starts_with($byte, "\x89PNG\r\n\x1A\n") => 'png',
                str_starts_with($byte, "\xFF\xD8\xFF") => 'jpeg',
                default => 'lain',
            };
            $id = substr(hash('sha256', $byte), 0, 32);

            try {
                [$kolom, $baris] = Coordinate::coordinateFromString($d->getCoordinates());
            } catch (Throwable) {
                continue;
            }

            $entri = [
                'id' => $id,
                'dari' => ['kolom' => $kolom, 'baris' => (int) $baris, 'dx' => $d->getOffsetX(), 'dy' => $d->getOffsetY()],
                'lebar' => $d->getWidth(),
                'tinggi' => $d->getHeight(),
                'jenis' => $jenis,
            ];

            if ($jenis === 'lain') {
                $entri['ekstensi'] = $ekstensi !== '' ? $ekstensi : 'tidak-dikenal';
            } else {
                $simpan[$id] = ['jenis' => $jenis, 'byte' => $byte];
            }

            $hasil[] = $entri;
        }

        return $hasil;
    }

    /** @return array<string, mixed>|null */
    private function sel(Spreadsheet $ss, Cell $cell): ?array
    {
        $xf = $cell->getXfIndex();
        $gaya = $this->gaya($ss, $xf);
        $format = $this->cacheFormat[$xf];
        $tipeData = $cell->getDataType();
        $nilai = $cell->getValue();

        if ($nilai instanceof RichText) {
            $nilai = $nilai->getPlainText();
        }

        $rumus = null;

        if ($tipeData === DataType::TYPE_FORMULA && is_string($nilai)) {
            $rumus = str_starts_with($nilai, '=') ? $nilai : '='.$nilai;
            $atribut = $cell->getFormulaAttributes();

            if (is_array($atribut) && ($atribut['t'] ?? null) === 'array') {
                $rumus = '{'.$rumus.'}';
            }

            $nilai = $cell->getOldCalculatedValue();

            if ($nilai instanceof RichText) {
                $nilai = $nilai->getPlainText();
            }
        }

        if (($nilai === null || $nilai === '') && $rumus === null) {
            // Sel tanpa nilai disimpan hanya bila Excel menggambar sesuatu di
            // situ: warna isi atau garis (tabel bergaris, blok berwarna). Gaya
            // huruf/perataan pada sel kosong tidak terlihat — tidak disimpan.
            return isset($gaya['isi']) || isset($gaya['border'])
                ? $this->bentuk($cell, 'kosong', null, '', null, $format, $gaya)
                : null;
        }

        [$tipe, $angka, $teks] = $this->nilai($nilai, $tipeData, $format);

        return $this->bentuk($cell, $rumus !== null ? 'f' : $tipe, $angka, $teks, $rumus, $format, $gaya);
    }

    /**
     * @return array{0: string, 1: ?float, 2: string}
     */
    private function nilai(mixed $nilai, string $tipeData, ?string $format): array
    {
        if (is_bool($nilai)) {
            return ['b', null, $nilai ? 'TRUE' : 'FALSE'];
        }

        if (is_int($nilai) || is_float($nilai)) {
            $angka = (float) $nilai;

            if (! is_finite($angka)) {
                return ['s', null, (string) $nilai];
            }

            return ['n', $angka, $this->format($angka, $format)];
        }

        $teks = is_scalar($nilai) ? (string) $nilai : '';

        if ($tipeData === DataType::TYPE_ERROR || array_key_exists($teks, DataType::getErrorCodes())) {
            return ['e', null, $teks];
        }

        return ['s', null, $teks];
    }

    private function format(float $angka, ?string $format): string
    {
        return self::teksTampil($angka, $format);
    }

    /**
     * Teks tampil angka dengan number format sel — satu fungsi untuk impor dan
     * hasil `/hitung` (tahap C), supaya "beda dari snapshot" tidak pernah
     * muncul hanya karena pemformatnya berbeda.
     */
    public static function teksTampil(float $angka, ?string $format): string
    {
        try {
            return NumberFormat::toFormattedString($angka, $format ?? NumberFormat::FORMAT_GENERAL);
        } catch (Throwable) {
            return (string) $angka;
        }
    }

    /**
     * @param  array<string, mixed>|null  $gaya
     * @return array<string, mixed>
     */
    private function bentuk(Cell $cell, string $tipe, ?float $angka, string $teks, ?string $rumus, ?string $format, ?array $gaya): array
    {
        $kolom = Coordinate::columnIndexFromString($cell->getColumn());

        return [
            'a' => $cell->getCoordinate(),
            'b' => $cell->getRow(),
            'k' => $kolom,
            't' => $tipe,
            'n' => $angka,
            's' => $teks,
            'r' => $rumus,
            'f' => $format,
            'g' => $gaya,
        ];
    }

    /** @return array<string, mixed>|null */
    private function gaya(Spreadsheet $ss, int $xf): ?array
    {
        if (array_key_exists($xf, $this->cacheGaya)) {
            return $this->cacheGaya[$xf];
        }

        $style = $ss->getCellXfByIndexOrNull($xf) ?? $ss->getDefaultStyle();
        $this->cacheFormat[$xf] = self::kodeFormat($style);

        return $this->cacheGaya[$xf] = $this->ringkasGaya($style);
    }

    private static function kodeFormat(Style $style): ?string
    {
        $kode = $style->getNumberFormat()->getFormatCode();

        return $kode === null || $kode === '' || $kode === NumberFormat::FORMAT_GENERAL ? null : $kode;
    }

    /**
     * Gaya ringkas — kunci kontrak mobile `GayaSel.fromJson` (lihat
     * `KanonikWorkbook` §Format sel). Hanya yang bukan bawaan; null = polos.
     *
     * @return array<string, mixed>|null
     */
    private function ringkasGaya(Style $style): ?array
    {
        $g = [];
        $font = $style->getFont();

        if ($font->getBold() === true) {
            $g['tebal'] = true;
        }

        if ($font->getItalic() === true) {
            $g['miring'] = true;
        }

        $warna = self::rgb($font->getColor()->getARGB());

        if ($warna !== null && $warna !== '#000000') {
            $g['teks'] = $warna;
        }

        // Ukuran & nama font hanya bila beda dari font bawaan workbook (xf 0,
        // biasanya gaya "Normal") — kunci kontrak mobile `ukuran` / `font`.
        $ukuran = $font->getSize();

        if ($ukuran !== null && $ukuran > 0 && ($this->ukuranBawaan === null || abs($ukuran - $this->ukuranBawaan) > 1e-9)) {
            $g['ukuran'] = (float) $ukuran;
        }

        $namaFont = $font->getName();

        if ($namaFont !== null && $namaFont !== '' && $namaFont !== $this->fontBawaan) {
            $g['font'] = $namaFont;
        }

        $fill = $style->getFill();

        if ($fill->getFillType() === Fill::FILL_SOLID) {
            $isi = self::rgb($fill->getStartColor()->getARGB());

            if ($isi !== null) {
                $g['isi'] = $isi;
            }
        }

        $perataan = $style->getAlignment();
        $rata = $perataan->getHorizontal();

        if ($rata !== null && $rata !== '' && $rata !== Alignment::HORIZONTAL_GENERAL) {
            $g['rata'] = $rata;
        }

        // Vertikal bawaan Excel = bottom: tidak dikirim (tanpa kunci = bottom).
        $vertikal = $perataan->getVertical();

        if ($vertikal !== null && $vertikal !== '' && $vertikal !== Alignment::VERTICAL_BOTTOM) {
            $g['vertikal'] = $vertikal;
        }

        if ($perataan->getWrapText()) {
            $g['bungkus'] = true;
        }

        $tepi = $style->getBorders();
        $border = [];

        foreach (['atas' => $tepi->getTop(), 'kanan' => $tepi->getRight(), 'bawah' => $tepi->getBottom(), 'kiri' => $tepi->getLeft()] as $sisi => $garis) {
            $gayaGaris = $garis->getBorderStyle();

            if ($gayaGaris !== '' && $gayaGaris !== Border::BORDER_NONE) {
                $border[$sisi] = $gayaGaris;
            }
        }

        if ($border !== []) {
            $g['border'] = $border;
        }

        return $g === [] ? null : $g;
    }

    /** ARGB/RGB PhpSpreadsheet → `#RRGGBB` (kontrak mobile `warnaHex`). */
    private static function rgb(?string $argb): ?string
    {
        if ($argb === null || strlen($argb) < 6) {
            return null;
        }

        return '#'.strtoupper(substr($argb, -6));
    }

    /** @return array<string, mixed> */
    private function tataLetak(Spreadsheet $ss, Worksheet $sheet, int $jumlahBaris, int $jumlahKolom): array
    {
        $tata = KanonikWorkbook::tataLetakKosong();

        $merge = array_values($sheet->getMergeCells());
        sort($merge, SORT_STRING);
        $tata['merge'] = $merge;

        // Dimensi di luar rentang terpakai dibuang: `<col min="1" max="16384">`
        // melahirkan belasan ribu entri yang tidak pernah tampil.
        foreach ($sheet->getColumnDimensions() as $huruf => $dim) {
            $indeks = Coordinate::columnIndexFromString((string) $huruf);

            if ($indeks > max($jumlahKolom, 1)) {
                continue;
            }

            $lebar = $dim->getWidth();

            if ($lebar >= 0) {
                $tata['lebar_kolom'][(string) $huruf] = (float) $lebar;
            }

            if (! $dim->getVisible()) {
                $tata['kolom_tersembunyi'][] = (string) $huruf;
            }
        }

        foreach ($sheet->getRowDimensions() as $nomor => $dim) {
            if ((int) $nomor > max($jumlahBaris, 1)) {
                continue;
            }

            $tinggi = $dim->getRowHeight();

            if ($tinggi >= 0) {
                $tata['tinggi_baris'][(int) $nomor] = (float) $tinggi;
            }

            if (! $dim->getVisible()) {
                $tata['baris_tersembunyi'][] = (int) $nomor;
            }
        }

        // Peta urut kolom (A, B, …, Z, AA) dan baris — urutan isi kolom DB
        // stabil; hash tidak bergantung padanya (JsonKanonik mengurutkan kunci).
        uksort($tata['lebar_kolom'], static fn (string $x, string $y): int => Coordinate::columnIndexFromString($x) <=> Coordinate::columnIndexFromString($y));
        usort($tata['kolom_tersembunyi'], static fn (string $x, string $y): int => Coordinate::columnIndexFromString($x) <=> Coordinate::columnIndexFromString($y));
        ksort($tata['tinggi_baris'], SORT_NUMERIC);
        sort($tata['baris_tersembunyi']);

        // Bawaan sheet (`<sheetFormatPr defaultColWidth / defaultRowHeight>`);
        // tidak disebut → bawaan Excel untuk font bawaan workbook menurut tabel
        // PhpSpreadsheet (Calibri 11 → lebar 9,140625 = 64 px, tinggi 15 pt).
        $fontBawaan = $ss->getDefaultStyle()->getFont();
        $lebarBawaan = $sheet->getDefaultColumnDimension()->getWidth();
        $tinggiBawaan = $sheet->getDefaultRowDimension()->getRowHeight();
        $tata['lebar_kolom_bawaan'] = $lebarBawaan >= 0 ? (float) $lebarBawaan : (float) SharedFont::getDefaultColumnWidthByFont($fontBawaan);
        $tata['tinggi_baris_bawaan'] = $tinggiBawaan >= 0 ? (float) $tinggiBawaan : (float) SharedFont::getDefaultRowHeightByFont($fontBawaan);
        $tata['lebar_perkiraan'] = false;
        $tata['freeze'] = self::freeze($sheet);
        $tata['garis_kisi'] = $sheet->getShowGridlines();
        $zoom = $sheet->getSheetView()->getZoomScale();
        $tata['zoom'] = $zoom !== null && $zoom > 0 && $zoom !== 100 ? $zoom : null;
        $tata['jumlah_komentar'] = count($sheet->getComments());

        return $tata;
    }

    /**
     * Sel kiri-atas area yang TIDAK beku, dari `xSplit`/`ySplit` pane yang
     * berstatus frozen/frozenSplit — jumlah kolom/baris yang dibekukan, tidak
     * ikut posisi gulir. `topLeftCell` pane TIDAK dipakai: itu sel kiri-atas
     * yang terlihat saat master terakhir disimpan, jadi bergeser bila master
     * disimpan dalam keadaan digulir. Cadangan: `getFreezePane()`.
     */
    private static function freeze(Worksheet $sheet): ?string
    {
        $x = $sheet->getXSplit();
        $y = $sheet->getYSplit();

        if (in_array($sheet->getPaneState(), [Worksheet::PANE_FROZEN, Worksheet::PANE_FROZENSPLIT], true) && ($x > 0 || $y > 0)) {
            return Coordinate::stringFromColumnIndex($x + 1).($y + 1);
        }

        $cadangan = $sheet->getFreezePane();

        return $cadangan !== null && $cadangan !== '' ? $cadangan : null;
    }

    /**
     * Hapus satu subfolder temp. Gagal (berkas masih dipegang) → dicatat TANPA
     * isi, dan penyapu terjadwal yang menyelesaikannya.
     */
    public static function hapusTemp(string $dir): void
    {
        if (is_dir($dir)) {
            (new Filesystem)->deleteDirectory($dir);

            if (is_dir($dir)) {
                gc_collect_cycles();
                (new Filesystem)->deleteDirectory($dir);
            }

            if (is_dir($dir)) {
                Log::warning('Temp workbook terdekripsi gagal dihapus; diserahkan ke studio:sapu-temp-workbook.', [
                    'folder' => basename($dir),
                ]);

                return;
            }
        }

        unset(self::$aktif[$dir]);
    }
}
