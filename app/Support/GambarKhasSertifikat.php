<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Gambar khas alat di lembar `SERTIFIKAT` master (G2, R4): diagram posisi
 * termokopel yang berupa OBJEK GAMBAR di dalam print area (bukan sel), mis.
 * silinder 1-2-3 autoclave dan kubus 1-9 enclosure.
 *
 * Mekanisme data-driven: profil menyatakan `tata_letak.gambar_khas` (daftar
 * entri di bawah), dibekukan ke snapshot BARU lewat
 * `CalibrationProfile::tataLetakSertifikat`; `pdf.blade.php` mencetaknya lewat
 * `_gambar-khas.blade.php` hanya bila kunci ada. Snapshot lama tidak punya
 * kunci itu -> bawaan `null` -> tidak ada satu byte pun yang berubah.
 *
 * ## Yang BUKAN gambar khas (jangan ditambahkan di sini)
 *
 * Gambar di print area Flowmeter x3, Gas Detector, dan Caliper (`image9/19/14/1/16.png`)
 * ternyata TANDA TANGAN tulisan tangan (penandatangan master), bukan diagram.
 * Tanda tangan dicetak lewat mekanisme tanda tangan aplikasi (`tandaTangan`,
 * `posisiTtd`, `ukuranTtd`) dari berkas yang diunggah penandatangan sah — tidak
 * pernah dari aset repo. Lihat laporan G2.
 *
 * ## Entri `gambar_khas`
 *
 * - `berkas`: kunci di [BERKAS] (aset `resources/sertifikat/gambar/`).
 * - `posisi`: salah satu `TataLetakSertifikat::POSISI_*` (sekarang `atas_hasil`
 *   saja yang dicetak: sesudah `Calibration Report :`, sebelum tabel pertama).
 * - `kiri`, `lebar`, `tinggi`: kotak gambar sebagai PECAHAN lebar isi halaman
 *   (lebar tabel hasil), dari jangkar master + diukur ulang di PDF master.
 *   `tinggi` terpisah dari `lebar` karena master merentangkan gambar enclosure
 *   (rasio kotak 0,827 vs berkas 0,888).
 * - `teks`: baris label di kiri gambar (`Thermocouple Position :`), `kiri_teks`
 *   pecahan lebar tempat label mulai.
 * - `turun_teks_pt`, `turun_gambar_pt`: selisih vertikal label/gambar (pt).
 *
 * Sumber angka (sheet!sel jangkar, `<lembar>.objek.json`; fraksi diukur di
 * `*.terang.pdf`): autoclave `SERTIFIKAT!J19` (label `F19:F20`), enclosure
 * Constant/Yokogawa `J17` (label `F18`), enclosure Recorder `I17` (label `F18`).
 */
final class GambarKhasSertifikat
{
    /** Lebar isi halaman A4 (21 cm - 2 x 1,05 cm margin `@page`) dalam pt. */
    public const LEBAR_ISI_PT = 535.7;

    /** Kunci -> berkas aset di `resources/sertifikat/gambar/`. */
    public const BERKAS = [
        'posisi-termokopel-autoclave' => 'posisi-termokopel-autoclave.png',
        'posisi-termokopel-enclosure' => 'posisi-termokopel-enclosure.png',
    ];

    public const KUNCI_ENTRI = ['berkas', 'posisi', 'kiri', 'lebar', 'tinggi', 'teks', 'kiri_teks', 'turun_teks_pt', 'turun_gambar_pt'];

    /**
     * Entri per kode varian master.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public const PER_VARIAN = [
        // `155` SERTIFIKAT!J19 (ext 2422072 x 932665 EMU); label F19:F20.
        'autoclave' => [[
            'berkas' => 'posisi-termokopel-autoclave', 'posisi' => TataLetakSertifikat::POSISI_ATAS_HASIL,
            'kiri' => 0.3935, 'lebar' => 0.2507, 'tinggi' => 0.0965,
            'teks' => ['Thermocouple Position :', '(For Temperature)'], 'kiri_teks' => 0.211,
            'turun_teks_pt' => 0, 'turun_gambar_pt' => 9,
        ]],
        // `171` Constant Yokogawa SERTIFIKAT!J17 (ext 2476500 x 2047192 EMU); label F18.
        'enclosure_constant_yokogawa' => [[
            'berkas' => 'posisi-termokopel-enclosure', 'posisi' => TataLetakSertifikat::POSISI_ATAS_HASIL,
            'kiri' => 0.410, 'lebar' => 0.2844, 'tinggi' => 0.2351,
            'teks' => ['Thermocouple Position :'], 'kiri_teks' => 0.266,
            'turun_teks_pt' => 2, 'turun_gambar_pt' => 0,
        ]],
        // `172` Recorder SERTIFIKAT!I17 (ext 1952625 x 1617109 EMU); label F18.
        'enclosure_recorder' => [[
            'berkas' => 'posisi-termokopel-enclosure', 'posisi' => TataLetakSertifikat::POSISI_ATAS_HASIL,
            'kiri' => 0.3965, 'lebar' => 0.2269, 'tinggi' => 0.1879,
            'teks' => ['Thermocouple Position :'], 'kiri_teks' => 0.277,
            'turun_teks_pt' => 0, 'turun_gambar_pt' => 0,
        ]],
    ];

    /**
     * @return list<array<string, mixed>>|null `null` bila varian tidak punya gambar
     */
    public static function untuk(string $varian): ?array
    {
        return self::PER_VARIAN[$varian] ?? null;
    }

    public static function jalur(string $kunci): string
    {
        return resource_path('sertifikat/gambar/'.self::BERKAS[$kunci]);
    }

    /** Data URI PNG (dompdf tidak perlu mengakses berkas/URL lain). */
    public static function dataUri(string $kunci): string
    {
        $isi = file_get_contents(self::jalur($kunci));

        if ($isi === false) {
            throw new InvalidArgumentException("Aset gambar khas sertifikat `{$kunci}` tidak terbaca.");
        }

        return 'data:image/png;base64,'.base64_encode($isi);
    }

    /**
     * Rapikan & periksa `tata_letak.gambar_khas`. Salah ketik kunci/berkas
     * DILEMPAR, bukan diabaikan (sama dengan kunci tata letak lain).
     *
     * @return list<array<string, mixed>>
     */
    public static function normalisasi(mixed $nilai): array
    {
        if (! is_array($nilai) || $nilai === [] || ! array_is_list($nilai)) {
            throw new InvalidArgumentException('Tata letak sertifikat `gambar_khas` harus daftar entri tidak kosong atau null.');
        }

        $hasil = [];

        foreach ($nilai as $i => $e) {
            if (! is_array($e) || array_diff(array_keys($e), self::KUNCI_ENTRI) !== [] || array_diff(self::KUNCI_ENTRI, array_keys($e)) !== []) {
                throw new InvalidArgumentException("Tata letak sertifikat `gambar_khas` #{$i}: kunci harus persis ".implode(', ', self::KUNCI_ENTRI).'.');
            }

            if (! is_string($e['berkas']) || ! isset(self::BERKAS[$e['berkas']])) {
                throw new InvalidArgumentException("Tata letak sertifikat `gambar_khas` #{$i}: berkas `".(is_string($e['berkas']) ? $e['berkas'] : gettype($e['berkas'])).'` tidak terdaftar.');
            }

            if (! in_array($e['posisi'], [TataLetakSertifikat::POSISI_ATAS_HASIL], true)) {
                throw new InvalidArgumentException("Tata letak sertifikat `gambar_khas` #{$i}: posisi hanya `atas_hasil`.");
            }

            foreach (['kiri', 'lebar', 'tinggi', 'kiri_teks'] as $k) {
                if (! is_numeric($e[$k]) || $e[$k] < 0 || $e[$k] > 1) {
                    throw new InvalidArgumentException("Tata letak sertifikat `gambar_khas` #{$i}: `{$k}` harus pecahan 0-1.");
                }
            }

            if ($e['kiri'] + $e['lebar'] > 1.0001 || $e['lebar'] <= 0 || $e['tinggi'] <= 0) {
                throw new InvalidArgumentException("Tata letak sertifikat `gambar_khas` #{$i}: kotak gambar keluar dari lebar isi.");
            }

            foreach (['turun_teks_pt', 'turun_gambar_pt'] as $k) {
                if (! is_numeric($e[$k]) || $e[$k] < 0 || $e[$k] > 60) {
                    throw new InvalidArgumentException("Tata letak sertifikat `gambar_khas` #{$i}: `{$k}` harus 0-60 pt.");
                }
            }

            if (! is_array($e['teks']) || ! array_is_list($e['teks']) || array_filter($e['teks'], fn ($t) => ! is_string($t) || trim($t) === '') !== []) {
                throw new InvalidArgumentException("Tata letak sertifikat `gambar_khas` #{$i}: `teks` harus daftar string tidak kosong.");
            }

            $hasil[] = [
                'berkas' => $e['berkas'],
                'posisi' => $e['posisi'],
                'kiri' => (float) $e['kiri'],
                'lebar' => (float) $e['lebar'],
                'tinggi' => (float) $e['tinggi'],
                'teks' => $e['teks'],
                'kiri_teks' => (float) $e['kiri_teks'],
                'turun_teks_pt' => $e['turun_teks_pt'] + 0,
                'turun_gambar_pt' => $e['turun_gambar_pt'] + 0,
            ];
        }

        return $hasil;
    }
}
