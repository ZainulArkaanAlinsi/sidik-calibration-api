<?php

namespace App\Console\Commands;

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

        if ($baris->isEmpty()) {
            $this->warn("Belum ada pembacaan hasil kamera dalam {$hari} hari terakhir — nggak ada yang bisa diukur.");
            $this->line('Kalau jalur fotonya dipakai tapi angkanya nol, yang pertama dicek: '
                .'HP-nya sudah mengirim `measurements[].ocr[]` apa belum. Tanpa itu tebakan '
                .'mesinnya tertimpa waktu teknisi mengetik ulang, dan yang sampai sini cuma hasil akhir.');

            // Bukan kegagalan: lab yang belum memotret memang belum punya data.
            // Yang gagal itu kalau angkanya dikarang biar ada isinya.
            return self::SUCCESS;
        }

        $vonis = $baris->map(fn (RawMeasurement $m): array => $this->adu($m));

        $this->info("Pembacaan hasil kamera {$hari} hari terakhir: {$baris->count()}");

        // Ditulis terpisah karena artinya beda: yang belum diverifikasi masih
        // ditahan gerbang approve, jadi dia bukan "lolos diam-diam" — dia belum
        // selesai. Yang menyesatkan kalau dua-duanya dilebur jadi satu angka.
        $terverifikasi = $baris->where('is_verified', true)->count();
        $this->line("Sudah lewat gerbang verifikasi teknisi: {$terverifikasi} dari {$baris->count()}");
        $this->newLine();

        $this->tabelPerKolom($vonis);
        $this->newLine();
        $this->hijauPalsu($vonis);

        return self::SUCCESS;
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
