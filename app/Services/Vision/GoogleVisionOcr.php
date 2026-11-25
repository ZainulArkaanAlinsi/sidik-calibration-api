<?php

namespace App\Services\Vision;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OCR mentah Google Cloud Vision, fitur DOCUMENT_TEXT_DETECTION, untuk SATU foto
 * halaman. Mengembalikan blok dan kata beserta kotak (dinormalkan 0..1 terhadap
 * halaman) dan keyakinan bila Google memberikannya.
 *
 * ## Yang BUKAN tugas kelas ini
 *
 * Tidak memahami lembar kerja: tidak mencocokkan teks ke sel, tidak menafsirkan
 * angka, tidak membersihkan bacaan. Itu urusan lapisan pemetaan (tahap 2) dan
 * teknisi. Kelas ini juga bukan pengekstrak LLM seperti
 * `WorksheetVisionExtractor`: tidak ada prompt dan tidak ada skema, jadi
 * bentuk keluarannya berbeda dan sengaja tidak ditumpangkan ke sana.
 *
 * ## Privasi
 *
 * Gambar dikirim ke Google apa adanya dan TIDAK disimpan di sini. Teks hasil
 * tidak dicatat ke log; yang dicatat pemanggil hanya metadata (waktu, unit,
 * ukuran, durasi, status).
 *
 * ## Unit
 *
 * [UNIT_PER_GAMBAR] adalah ASUMSI dari daftar harga Google (satu fitur untuk satu
 * gambar = satu unit, berapa pun isi teksnya). Belum dibuktikan dengan satu
 * panggilan nyata yang dicocokkan ke laporan tagihan Google Cloud; sampai itu
 * dilakukan, angka biaya hanya estimasi.
 */
final class GoogleVisionOcr
{
    public const MODEL = 'google-vision:DOCUMENT_TEXT_DETECTION';

    public const FITUR = 'DOCUMENT_TEXT_DETECTION';

    /** Asumsi, BELUM terverifikasi. Lihat komentar kelas. */
    public const UNIT_PER_GAMBAR = 1;

    /** Batas Google: 16 gambar per permintaan `images:annotate`. Pemanggil membagi lot di atas ini. */
    public const MAKS_GAMBAR_PER_PANGGILAN = 16;

    /** Pagar ukuran respons ke aplikasi; halaman padat tetap jauh di bawah ini. */
    private const MAKS_BLOK = 800;

    private const MAKS_KATA = 4000;

    public function __construct(private readonly GoogleVisionToken $token) {}

    /**
     * @return array{
     *     ok: bool,
     *     status: string,
     *     error: ?string,
     *     unit: int,
     *     durasi_ms: int,
     *     halaman: ?array{lebar: int, tinggi: int},
     *     blok: list<array<string, mixed>>,
     *     kata: list<array<string, mixed>>,
     *     terpotong: bool
     * }
     */
    public function bacaHalaman(string $isiGambar): array
    {
        $mulai = hrtime(true);

        try {
            $token = $this->token->ambil();
        } catch (GagalMenghubungiGoogle $e) {
            // Google dihubungi saat meminta token dan gagal (jaringan, waktu habis, 5xx/429):
            // kegagalan biasa (502, unit 0, ikut batas kegagalan), bukan salah setup.
            return $this->gagal('gagal', $e->getMessage(), $mulai);
        } catch (RuntimeException $e) {
            // Salah setup server, bukan salah teknisi, dan tidak ada yang sampai ke Vision.
            return $this->gagal('belum_disetel', $e->getMessage(), $mulai);
        }

        try {
            $resp = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.google_vision.timeout', 30))
                ->baseUrl((string) config('services.google_vision.base_url', 'https://vision.googleapis.com'))
                ->post('/v1/images:annotate', [
                    'requests' => [[
                        'image' => ['content' => base64_encode($isiGambar)],
                        'features' => [['type' => self::FITUR]],
                    ]],
                ]);
        } catch (ConnectionException) {
            return $this->gagal('gagal', 'Tidak bisa menghubungi Google Vision (waktu habis atau jaringan).', $mulai);
        }

        if (! $resp->successful()) {
            // Token yang ditolak jangan dipakai lagi sampai kedaluwarsa di cache.
            if ($resp->status() === 401) {
                $this->token->lupakan();
            }

            // Hanya status HTTP dan kode status Google (enum pendek, mis. PERMISSION_DENIED).
            // `error.message` Google TIDAK dicatat: pesan 403/429 lazim memuat nomor proyek
            // dan alamat konsol, dan kolom ini terbaca di log dan layar audit.
            $status = $resp->json('error.status');
            $rinci = is_string($status) && preg_match('/^[A-Z_]{3,40}$/', $status) === 1 ? ' '.$status : '';

            return $this->gagal('gagal', 'Google Vision menolak permintaan (HTTP '.$resp->status().$rinci.').', $mulai);
        }

        $r = $resp->json('responses.0');
        if (! is_array($r)) {
            // "Halaman tanpa teks" datang sebagai `responses: [{}]` (array kosong, lolos di
            // bawah). Yang bukan array bukan jawaban Vision, jadi tidak boleh tercatat satu
            // unit tertagih: itu membuat rekonsiliasi `SUM(unit_vision)` ke tagihan meleset.
            return $this->gagal('gagal', 'Respons Google Vision tidak berbentuk yang diharapkan.', $mulai);
        }

        if (isset($r['error']) && is_array($r['error'])) {
            $kode = $r['error']['code'] ?? null;

            return $this->gagal('ditolak', 'Google Vision tidak bisa membaca gambar ini'.(is_int($kode) ? ' (kode '.$kode.')' : '').'.', $mulai);
        }

        // Panggilan sukses = satu unit tertagih, ADA teks atau tidak.
        $semua = $r['fullTextAnnotation']['pages'] ?? [];
        $jumlahHalaman = is_array($semua) && $semua !== [] ? count($semua) : 1;
        $halaman = is_array($semua) ? ($semua[0] ?? null) : null;
        if (! is_array($halaman)) {
            return $this->hasil([], [], null, false, $mulai, $jumlahHalaman);
        }

        return $this->urai($halaman, $mulai, $jumlahHalaman);
    }

    /**
     * OCR mentah untuk BANYAK potongan sel angka dalam SATU panggilan `images:annotate`
     * (batch, paling banyak [MAKS_GAMBAR_PER_PANGGILAN] gambar). Dipakai
     * `OcrSelAwanController`; jalur foto utuh (`bacaHalaman`) tidak berubah.
     *
     * Kenapa batch, bukan satu panggilan per sel: tagihan Google dihitung per GAMBAR (asumsi,
     * lihat [UNIT_PER_GAMBAR]), jadi batch tidak lebih murah per sel. Yang dihemat putaran
     * jaringan, permintaan token, dan baris log: satu lembar belasan sel = satu panggilan
     * dan satu baris `worksheet_extraction_logs`, bukan belasan yang menghabiskan jatah
     * `maks_per_sesi` lebih dulu.
     *
     * Urutan hasil = urutan gambar yang dikirim; Google mengembalikan `responses` sejajar. Bila
     * jumlah `responses` tidak sama dengan jumlah gambar, SELURUH panggilan dianggap gagal: sel
     * tidak boleh dicocokkan menurut tebakan, karena angka salah-sel lebih buruk daripada kosong.
     *
     * Hasil per gambar hanya teks mentah (kata digabung spasi) dan keyakinan TERENDAH di antara
     * kata-katanya (null bila Google tidak memberi). Tidak ada penafsiran angka di sini, dan teks
     * tidak boleh dicatat oleh pemanggil.
     *
     * Unit = jumlah gambar yang dijawab Google tanpa galat (ada teks atau tidak). Bila tidak satu
     * pun, `ok` false dan unit 0 supaya ikut hitungan kegagalan.
     *
     * @param  list<string>  $isiGambar  isi biner tiap gambar (JPEG/PNG), 1..16
     * @return array{
     *     ok: bool,
     *     status: string,
     *     error: ?string,
     *     unit: int,
     *     durasi_ms: int,
     *     sel: list<array{ok: bool, teks: string, keyakinan: ?float, jumlah_kata: int}>
     * }
     */
    public function bacaSel(array $isiGambar): array
    {
        $mulai = hrtime(true);
        $jumlah = count($isiGambar);

        if ($jumlah < 1 || $jumlah > self::MAKS_GAMBAR_PER_PANGGILAN) {
            return $this->gagalSel('ditolak', 'Jumlah gambar per panggilan harus 1 sampai '.self::MAKS_GAMBAR_PER_PANGGILAN.'.', $mulai);
        }

        try {
            $token = $this->token->ambil();
        } catch (GagalMenghubungiGoogle $e) {
            return $this->gagalSel('gagal', $e->getMessage(), $mulai);
        } catch (RuntimeException $e) {
            return $this->gagalSel('belum_disetel', $e->getMessage(), $mulai);
        }

        $permintaan = [];
        foreach (array_values($isiGambar) as $isi) {
            $permintaan[] = [
                'image' => ['content' => base64_encode($isi)],
                'features' => [['type' => self::FITUR]],
            ];
        }

        try {
            $resp = Http::withToken($token)
                ->acceptJson()
                ->timeout((int) config('services.google_vision.timeout', 30))
                ->baseUrl((string) config('services.google_vision.base_url', 'https://vision.googleapis.com'))
                ->post('/v1/images:annotate', ['requests' => $permintaan]);
        } catch (ConnectionException) {
            return $this->gagalSel('gagal', 'Tidak bisa menghubungi Google Vision (waktu habis atau jaringan).', $mulai);
        }

        if (! $resp->successful()) {
            if ($resp->status() === 401) {
                $this->token->lupakan();
            }

            // Sama seperti `bacaHalaman`: hanya status HTTP dan kode status Google (enum pendek);
            // `error.message` Google tidak dicatat karena bisa memuat nomor proyek.
            $status = $resp->json('error.status');
            $rinci = is_string($status) && preg_match('/^[A-Z_]{3,40}$/', $status) === 1 ? ' '.$status : '';

            return $this->gagalSel('gagal', 'Google Vision menolak permintaan (HTTP '.$resp->status().$rinci.').', $mulai);
        }

        $balasan = $resp->json('responses');
        if (! is_array($balasan) || ! array_is_list($balasan) || count($balasan) !== $jumlah) {
            return $this->gagalSel('gagal', 'Respons Google Vision tidak sejajar dengan gambar yang dikirim.', $mulai);
        }

        $sel = [];
        $unit = 0;
        foreach ($balasan as $r) {
            if (! is_array($r) || (isset($r['error']) && is_array($r['error']))) {
                $sel[] = ['ok' => false, 'teks' => '', 'keyakinan' => null, 'jumlah_kata' => 0];

                continue;
            }

            $unit += self::UNIT_PER_GAMBAR;
            $sel[] = $this->uraiSel($r);
        }

        if ($unit === 0) {
            return $this->gagalSel('ditolak', 'Google Vision tidak bisa membaca satu pun gambar dalam permintaan ini.', $mulai);
        }

        return [
            'ok' => true,
            'status' => 'sukses',
            'error' => null,
            'unit' => $unit,
            'durasi_ms' => $this->durasi($mulai),
            'sel' => $sel,
        ];
    }

    /**
     * Satu respons gambar: kata-kata di halaman pertama digabung spasi (urutan baca Google) dan
     * keyakinan = yang TERENDAH. Spasi di tengah sengaja dipertahankan: pembersihan angka
     * (`NormalisasiAngka`) menolak spasi di tengah, dan itu keputusan di sana, bukan di sini.
     *
     * @param  array<string, mixed>  $r
     * @return array{ok: bool, teks: string, keyakinan: ?float, jumlah_kata: int}
     */
    private function uraiSel(array $r): array
    {
        $halaman = $r['fullTextAnnotation']['pages'][0] ?? null;
        $kata = [];
        $keyakinan = null;

        foreach ((array) (is_array($halaman) ? ($halaman['blocks'] ?? []) : []) as $b) {
            foreach ((array) (is_array($b) ? ($b['paragraphs'] ?? []) : []) as $p) {
                foreach ((array) (is_array($p) ? ($p['words'] ?? []) : []) as $w) {
                    $teks = $this->teksKata($w);
                    if ($teks === '') {
                        continue;
                    }
                    $kata[] = $teks;

                    $k = is_array($w) ? $this->keyakinan($w['confidence'] ?? null) : null;
                    if ($k !== null) {
                        $keyakinan = $keyakinan === null ? $k : min($keyakinan, $k);
                    }
                }
            }
        }

        return ['ok' => true, 'teks' => implode(' ', $kata), 'keyakinan' => $keyakinan, 'jumlah_kata' => count($kata)];
    }

    /** @return array<string, mixed> */
    private function gagalSel(string $status, string $error, int|float $mulai): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $error,
            'unit' => 0,
            'durasi_ms' => $this->durasi($mulai),
            'sel' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $halaman
     * @return array<string, mixed>
     */
    private function urai(array $halaman, int|float $mulai, int $jumlahHalaman): array
    {
        $blokMentah = is_array($halaman['blocks'] ?? null) ? $halaman['blocks'] : [];

        $lebar = (int) ($halaman['width'] ?? 0);
        $tinggi = (int) ($halaman['height'] ?? 0);
        if ($lebar <= 0 || $tinggi <= 0) {
            [$lebar, $tinggi] = $this->ukuranDariKotak($blokMentah);
        }
        if ($lebar <= 0 || $tinggi <= 0) {
            // Tidak ada kotak sama sekali = tidak ada teks yang bisa dipetakan.
            return $this->hasil([], [], null, false, $mulai, $jumlahHalaman);
        }

        $blok = [];
        $kata = [];
        $terpotong = false;

        foreach ($blokMentah as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (count($blok) >= self::MAKS_BLOK) {
                $terpotong = true;
                break;
            }

            $indeks = count($blok);
            $barisBlok = [];

            foreach ((array) ($b['paragraphs'] ?? []) as $p) {
                $katTeks = [];
                foreach ((array) ($p['words'] ?? []) as $w) {
                    $teks = $this->teksKata($w);
                    if ($teks === '') {
                        continue;
                    }
                    $katTeks[] = $teks;

                    if (count($kata) >= self::MAKS_KATA) {
                        $terpotong = true;

                        continue;
                    }
                    $kata[] = [
                        'teks' => $teks,
                        'kotak' => $this->kotak($w['boundingBox'] ?? [], $lebar, $tinggi),
                        'keyakinan' => $this->keyakinan($w['confidence'] ?? null),
                        'blok' => $indeks,
                    ];
                }
                if ($katTeks !== []) {
                    $barisBlok[] = implode(' ', $katTeks);
                }
            }

            $blok[] = [
                'id' => $indeks,
                'teks' => implode("\n", $barisBlok),
                'kotak' => $this->kotak($b['boundingBox'] ?? [], $lebar, $tinggi),
                'keyakinan' => $this->keyakinan($b['confidence'] ?? null),
            ];
        }

        return $this->hasil($blok, $kata, ['lebar' => $lebar, 'tinggi' => $tinggi], $terpotong, $mulai, $jumlahHalaman);
    }

    /**
     * @param  list<array<string, mixed>>  $blok
     * @param  list<array<string, mixed>>  $kata
     * @param  array{lebar: int, tinggi: int}|null  $halaman
     * @return array<string, mixed>
     */
    private function hasil(array $blok, array $kata, ?array $halaman, bool $terpotong, int|float $mulai, int $jumlahHalaman = 1): array
    {
        return [
            'ok' => true,
            'status' => 'sukses',
            'error' => null,
            'unit' => self::UNIT_PER_GAMBAR,
            'jumlah_halaman' => $jumlahHalaman,
            'durasi_ms' => $this->durasi($mulai),
            'halaman' => $halaman,
            'blok' => $blok,
            'kata' => $kata,
            'terpotong' => $terpotong,
        ];
    }

    /** @return array<string, mixed> */
    private function gagal(string $status, string $error, int|float $mulai): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $error,
            // Tidak ada hasil, jadi tidak ada unit yang dicatat tertagih.
            'unit' => 0,
            'jumlah_halaman' => 0,
            'durasi_ms' => $this->durasi($mulai),
            'halaman' => null,
            'blok' => [],
            'kata' => [],
            'terpotong' => false,
        ];
    }

    private function durasi(int|float $mulai): int
    {
        return (int) round((hrtime(true) - $mulai) / 1_000_000);
    }

    /** @param  array<string, mixed>  $kata */
    private function teksKata(mixed $kata): string
    {
        if (! is_array($kata)) {
            return '';
        }

        $teks = '';
        foreach ((array) ($kata['symbols'] ?? []) as $s) {
            $teks .= is_array($s) ? (string) ($s['text'] ?? '') : '';
        }

        return $teks;
    }

    private function keyakinan(mixed $nilai): ?float
    {
        return is_numeric($nilai) ? round((float) $nilai, 4) : null;
    }

    /**
     * Kotak sejajar sumbu (x0,y0,x1,y1), dinormalkan 0..1. Google menghilangkan
     * `x` atau `y` yang bernilai nol dari verteks, jadi yang hilang dibaca 0.
     *
     * @param  array<string, mixed>  $kotak
     * @return array{x0: float, y0: float, x1: float, y1: float}
     */
    private function kotak(mixed $kotak, int $lebar, int $tinggi): array
    {
        $xs = [];
        $ys = [];
        foreach ((array) (is_array($kotak) ? ($kotak['vertices'] ?? []) : []) as $v) {
            $xs[] = (float) ($v['x'] ?? 0);
            $ys[] = (float) ($v['y'] ?? 0);
        }
        if ($xs === []) {
            return ['x0' => 0.0, 'y0' => 0.0, 'x1' => 0.0, 'y1' => 0.0];
        }

        $n = static fn (float $nilai, int $skala): float => round(max(0.0, min(1.0, $nilai / $skala)), 5);

        return [
            'x0' => $n(min($xs), $lebar),
            'y0' => $n(min($ys), $tinggi),
            'x1' => $n(max($xs), $lebar),
            'y1' => $n(max($ys), $tinggi),
        ];
    }

    /**
     * Cadangan bila Google tidak menyebut lebar/tinggi halaman: ambil dari
     * verteks terjauh di antara blok.
     *
     * @param  list<mixed>  $blok
     * @return array{0: int, 1: int}
     */
    private function ukuranDariKotak(array $blok): array
    {
        $lebar = 0;
        $tinggi = 0;
        foreach ($blok as $b) {
            foreach ((array) ($b['boundingBox']['vertices'] ?? []) as $v) {
                $lebar = max($lebar, (int) ($v['x'] ?? 0));
                $tinggi = max($tinggi, (int) ($v['y'] ?? 0));
            }
        }

        return [$lebar, $tinggi];
    }
}
