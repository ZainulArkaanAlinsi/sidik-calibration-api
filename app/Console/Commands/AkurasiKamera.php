<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Models\RawMeasurement;
use App\Services\Ocr\NormalisasiAngka;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ukur akurasi jalur kamera yang BENERAN dipakai teknisi (`FOTO TABEL INI`),
 * per kolom, dari angka yang akhirnya dikirim.
 *
 * ## Bedanya sama `ocr:akurasi`
 *
 * `ocr:akurasi` mengukur jalur lembar bermarker: sumbernya
 * `worksheet_scan_cells.nilai_final`, yang cuma keisi lewat
 * `POST /worksheet-scans/{scan}/koreksi`. Jalur itu **dicabut permanen dari
 * layar 26 Agt 2026**, jadi perintah itu tidak akan pernah punya data baru
 * lagi — lihat `docs/temuan-gerbang0-ocr-model-lokal.md`.
 *
 * Yang di sini mengukur jalur yang tersisa. Sumbernya `raw_measurements`:
 * `ocr_raw_text` menyimpan tebakan mesin, `pembacaan` menyimpan angka yang
 * teknisi kirim. Pasangan itu yang jadi ground truth-nya.
 *
 * ## Kenapa pasangannya harus disimpan, bukan dihitung belakangan
 *
 * Teknisi mengoreksi angka hasil foto DI KOTAK YANG SAMA. Sebelum HP mengirim
 * `measurements[].ocr[]`, tebakan mesin tertimpa waktu dia mengetik ulang, dan
 * yang sampai server cuma angka akhir. Akibatnya bukan "kurang lengkap" — tidak
 * ada satu pun cara menghitung akurasinya, karena tidak ada pembanding.
 *
 * ## Angka yang paling penting di sini: HIJAU PALSU
 *
 * Sama persis alasannya dengan `ocr:akurasi`. Sel yang keisi otomatis dengan
 * keyakinan tinggi padahal salah itu satu-satunya kegagalan yang **tidak ada
 * yang lihat sampai sertifikatnya terbit**. Satu hijau palsu lebih gawat dari
 * lima puluh merah palsu, jadi dia dihitung & ditampilkan terpisah, bukan
 * dilebur ke rata-rata.
 */
class AkurasiKamera extends Command
{
    protected $signature = 'ocr:akurasi-kamera
        {--kategori= : batasi ke satu kategori alat, misal suhu}
        {--hari=30 : rentang pengiriman yang dihitung (hari ke belakang)}';

    protected $description = 'Akurasi jalur FOTO TABEL per kolom, dihitung dari angka yang dikirim teknisi';

    public function __construct(private readonly NormalisasiAngka $normalisasi)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hari = (int) $this->option('hari');

        $baris = RawMeasurement::query()
            // Cuma baris yang mesinnya BENERAN menebak sesuatu. Baris manual
            // nggak punya pembanding, dan memasukkannya bikin akurasinya
            // kelihatan naik tiap teknisi mengetik tangan.
            ->whereNotNull('ocr_raw_text')
            ->where('created_at', '>=', now()->subDays($hari))
            ->when($this->option('kategori'), fn ($q) => $q->whereHas(
                'session.equipment.category',
                fn ($c) => $c->where('kode', $this->option('kategori')),
            ))
            ->with('session.equipment.category')
            ->get();

        // Autoklaf punya sumber SENDIRI: dia nggak pernah menulis
        // `raw_measurements` sama sekali — hasil ukurnya snapshot JSON
        // `hasil_autoclave`. Ditarik terpisah, lalu dilebur ke tabel yang sama.
        $autoclave = $this->dariAutoclave($hari);

        if ($baris->isEmpty() && $autoclave->isEmpty()) {
            $this->warn("Belum ada pembacaan hasil kamera dalam {$hari} hari terakhir — nggak ada yang bisa diukur.");
            $this->line('Kalau jalur fotonya dipakai tapi angkanya nol, yang pertama dicek: '
                .'HP-nya sudah mengirim `measurements[].ocr[]` apa belum. Tanpa itu tebakan '
                .'mesinnya tertimpa waktu teknisi mengetik ulang, dan yang sampai sini cuma hasil akhir.');

            // Bukan kegagalan: lab yang belum memotret memang belum punya data.
            // Yang gagal itu kalau angkanya dikarang biar ada isinya.
            return self::SUCCESS;
        }

        $vonis = $baris
            ->map(fn (RawMeasurement $m): array => $this->adu($m))
            ->concat($autoclave);

        $this->info("Pembacaan hasil kamera {$hari} hari terakhir: {$vonis->count()}");

        // Ditulis terpisah karena artinya beda: yang belum diverifikasi masih
        // ditahan gerbang approve, jadi dia bukan "lolos diam-diam" — dia belum
        // selesai. Yang menyesatkan kalau dua-duanya dilebur jadi satu angka.
        $terverifikasi = $baris->where('is_verified', true)->count();
        $this->line("Sudah lewat gerbang verifikasi teknisi: {$terverifikasi} dari {$baris->count()}");

        if ($autoclave->isNotEmpty()) {
            // Autoklaf nggak punya kolom `is_verified` sama sekali — angkanya
            // nggak lewat `raw_measurements`. Ditulis terus terang biar
            // pembagi di baris atas nggak dikira menghitung semuanya.
            $this->line("Sel Autoklaf (di luar gerbang itu, sumbernya `hasil_autoclave`): {$autoclave->count()}");
        }
        $this->newLine();

        $this->tabelPerKolom($vonis);
        $this->newLine();
        $this->hijauPalsu($vonis);

        return self::SUCCESS;
    }

    /**
     * Sel Autoklaf, dari `hasil_autoclave` — bukan `raw_measurements`.
     *
     * Autoklaf disimpan sebagai snapshot JSON utuh (`simpanAutoclave`), jadi
     * tanpa pembaca kedua ini seluruh lembarnya nggak akan pernah kehitung —
     * dan diamnya bakal kebaca sebagai "kameranya bagus di Autoklaf", padahal
     * artinya nol data.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function dariAutoclave(int $hari): Collection
    {
        $sesi = CalibrationSession::query()
            ->whereNotNull('hasil_autoclave')
            ->where('created_at', '>=', now()->subDays($hari))
            ->when($this->option('kategori'), fn ($q) => $q->whereHas(
                'equipment.category',
                fn ($c) => $c->where('kode', $this->option('kategori')),
            ))
            ->with('equipment.category')
            ->get();

        $vonis = collect();

        foreach ($sesi as $s) {
            $hasil = (array) $s->hasil_autoclave;
            $tebakan = $this->ratakan((array) ($hasil['ocr'] ?? []));
            $lembar = (array) ($hasil['lembar'] ?? []);
            $kategori = $s->equipment?->category?->kode ?? '(tanpa kategori)';

            foreach ($tebakan as $jalur => $deret) {
                // Jalur nilainya SAMA PERSIS, cuma tanpa awalan `ocr.` — itu
                // yang bikin pasangannya nggak perlu ditebak.
                $nilai = (array) (data_get($lembar, $jalur) ?? []);

                foreach ($deret as $urutan => $meta) {
                    if (! is_array($meta)) {
                        continue;
                    }

                    $final = $nilai[$urutan] ?? null;
                    $hasilBaca = $this->normalisasi->proses($meta['raw_text'] ?? null);
                    $terbaca = $hasilBaca['ok'] && ! $hasilBaca['kosong'] && $hasilBaca['nilai'] !== null;

                    $vonis->push([
                        // Dikelompokkan per BARIS matriks, bukan per Repeat:
                        // di kertas Autoklaf yang membedakan besarannya
                        // (Temp. Disk 1, Indikator Pressure), bukan kolomnya.
                        'kolom' => $kategori.' / '.$jalur,
                        'terbaca' => $terbaca,
                        'cocok' => $terbaca && $final !== null
                            && abs($hasilBaca['nilai'] - (float) $final) < 1e-9,
                        'skor' => isset($meta['confidence']) ? (float) $meta['confidence'] : null,
                        'mentah' => (string) ($meta['raw_text'] ?? ''),
                        'final' => $final === null ? null : (float) $final,
                        'sesi' => (int) $s->id,
                    ]);
                }
            }
        }

        return $vonis;
    }

    /**
     * Ratakan blok `ocr` bersarang jadi `jalur => deret tebakan`.
     *
     * Sengaja MENELUSURI, bukan memakai daftar jalur yang dipatok: baris baru
     * di matriks Autoklaf bakal ikut terbaca sendiri. Daftar yang diketik
     * tangan adalah daftar yang pasti kelupaan, dan yang kelupaan di sini
     * nggak bikin error — cuma bikin sampelnya diam-diam mengecil.
     *
     * @param  array<string, mixed>  $simpul
     * @return array<string, list<mixed>>
     */
    private function ratakan(array $simpul, string $awalan = ''): array
    {
        // Daun = deret yang isinya null atau objek tebakan. Cabang = apa pun
        // yang isinya deret lain (`suhu.disk` berisi tiga deret).
        $daun = array_is_list($simpul) && ! array_filter(
            $simpul,
            static fn ($v): bool => $v !== null
                && ! (is_array($v) && (array_key_exists('raw_text', $v) || array_key_exists('confidence', $v))),
        );

        if ($daun) {
            return $awalan === '' ? [] : [$awalan => array_values($simpul)];
        }

        $hasil = [];

        foreach ($simpul as $kunci => $isi) {
            if (is_array($isi)) {
                $hasil = [...$hasil, ...$this->ratakan($isi, $awalan === '' ? (string) $kunci : $awalan.'.'.$kunci)];
            }
        }

        return $hasil;
    }

    /**
     * Adu satu tebakan mesin ke angka yang akhirnya dikirim.
     *
     * Teksnya dinormalisasi pakai `NormalisasiAngka` yang SAMA dengan jalur
     * lembar bermarker — bukan `(float)` mentah. Dua alasannya: "50,02" dan
     * "50.02" itu bacaan yang sama benarnya, dan angkanya jadi bisa
     * dibandingkan lurus dengan `ocr:akurasi` waktu dua jalur itu diadu.
     *
     * @return array{kolom: string, cocok: bool, terbaca: bool, skor: float|null, mentah: string, final: float|null, sesi: int}
     */
    private function adu(RawMeasurement $m): array
    {
        $hasil = $this->normalisasi->proses($m->ocr_raw_text);
        $final = $m->pembacaan;

        $terbaca = $hasil['ok'] && ! $hasil['kosong'] && $hasil['nilai'] !== null;

        return [
            // Kategori alat + nomor Repeat. "Akurasi 94%" nggak berarti apa-apa
            // kalau yang 6% selalu Repeat 5 — dan pola itu cuma kelihatan kalau
            // angkanya dipisah per kolom sejak awal.
            'kolom' => ($m->session?->equipment?->category?->kode ?? '(tanpa kategori)')
                .' / Repeat '.$m->pembacaan_ke,
            'terbaca' => $terbaca,
            'cocok' => $terbaca && $final !== null
                && abs($hasil['nilai'] - $final) < 1e-9,
            'skor' => $m->ocr_confidence,
            'mentah' => (string) $m->ocr_raw_text,
            'final' => $final,
            'sesi' => (int) $m->calibration_session_id,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $vonis
     */
    private function tabelPerKolom(Collection $vonis): void
    {
        $baris = $vonis
            ->groupBy('kolom')
            ->map(function (Collection $kelompok, string $nama): array {
                $total = $kelompok->count();
                $cocok = $kelompok->where('cocok', true)->count();

                return [
                    $nama,
                    $total,
                    $cocok,
                    // Dibulatin 1 desimal: presisi lebih dari itu ngasih kesan
                    // angkanya lebih pasti dari sampelnya.
                    number_format($cocok / $total * 100, 1).'%',
                    // Dipisah dari "meleset": teks yang nggak bisa jadi angka
                    // sama sekali itu kegagalan yang beda — dan yang ini
                    // KELIHATAN sama teknisi, jadi jauh lebih murah.
                    $kelompok->where('terbaca', false)->count(),
                    $kelompok->whereNull('skor')->count(),
                ];
            })
            ->sortBy(fn (array $b): string => $b[0])
            ->values()
            ->all();

        $this->table(
            ['Kategori / Kolom', 'Dari kamera', 'Cocok', 'Akurasi', 'Tak terbaca', 'Tanpa skor'],
            $baris,
        );

        $tanpaSkor = $vonis->whereNull('skor')->count();

        if ($tanpaSkor === $vonis->count()) {
            // Ini bukan detail teknis: tanpa skor, ambang hijau/kuning di
            // `config/ocr.php` nggak punya masukan sama sekali, dan bagian
            // HIJAU PALSU di bawah bakal kosong karena nggak ada yang bisa
            // dinilai — BUKAN karena tidak ada yang salah.
            $this->warn('Nggak ada satu pun pembacaan yang bawa skor keyakinan. '
                .'ML Kit cuma menyetel `confidence` di sebagian versi & perangkat.');
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $vonis
     */
    private function hijauPalsu(Collection $vonis): void
    {
        $ambang = (float) config('ocr.ambang.hijau', 0.85);

        $hijau = $vonis->filter(
            fn (array $v): bool => $v['skor'] !== null && $v['skor'] >= $ambang,
        );

        if ($hijau->isEmpty()) {
            $this->line("Pembacaan berskor >= {$ambang}: belum ada. "
                .'Kalau kolom "Tanpa skor" di atas penuh, itu sebabnya — dan artinya '
                .'hijau palsu belum terukur, bukan nol.');

            return;
        }

        $palsu = $hijau->where('cocok', false);
        $persen = number_format($palsu->count() / $hijau->count() * 100, 2);

        $this->line("Pembacaan berskor >= {$ambang}: {$hijau->count()}, yang ternyata beda dari angka teknisi: {$palsu->count()} ({$persen}%)");

        if ($palsu->isEmpty()) {
            return;
        }

        // Ditampilin satu-satu, bukan diringkas: tiap baris di sini itu angka
        // yang HAMPIR mendarat di sertifikat tanpa ada yang periksa.
        $this->error('HIJAU PALSU — mesin yakin, dan mesin salah:');
        $this->table(
            ['Sesi', 'Kolom', 'Dibaca', 'Dikirim teknisi', 'Skor'],
            $palsu->take(20)->map(fn (array $v): array => [
                $v['sesi'],
                $v['kolom'],
                $v['mentah'],
                $v['final'],
                $v['skor'],
            ])->values()->all(),
        );

        if ($palsu->count() > 20) {
            $this->line('… dan '.($palsu->count() - 20).' lagi.');
        }
    }
}
