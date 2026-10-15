<?php

namespace App\Console\Commands;

use App\Services\Studio\Hitung\GrafDependensi;
use App\Services\Studio\Hitung\HitungWorkbook;
use App\Services\Studio\Hitung\IsiSel;
use App\Services\Studio\Hitung\MesinHitung;
use App\Services\Studio\Hitung\ModelHitung;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Harness paritas mesin hitung Studio ↔ Excel (tahap C). Generik: workbook
 * kalibrasi apa pun, skenario apa pun — tidak ada nama lembar/sel yang ditanam.
 *
 *   php artisan studio:paritas-excel "D:\master\X.xlsx" --excel=hasil-excel.json \
 *       --skenario=skenario.json --waktu-acuan=46309.5 --keluar=folder-laporan
 *
 * Sisi Excel dihasilkan `docs/skrip/paritas-excel-com.ps1` (SALINAN berkas,
 * Excel COM, CalculateFull, NOW/TODAY dibekukan ke serial yang sama);
 * `docs/skrip/paritas-excel.ps1` menjalankan keduanya dengan satu perintah.
 *
 * Per skenario mesin dijalankan DUA kali — mode kotor (yang dipakai `/hitung`)
 * dan mode penuh (semua rumus) — lalu:
 *  - kotor vs penuh: harus sama persis (bukti "hitung sel kotor saja" tidak
 *    mengubah hasil). Diukur pada model yang cache-nya = hasil mesin penuh
 *    tanpa edit, supaya beda mesin-vs-Excel (baris `cache`) tidak tercampur;
 *  - kotor vs Excel: per sel rumus identik / beda / galat mesin, angka diadu
 *    BIT (selisih ULP dilaporkan), teks lewat sha256 (sama dengan skrip COM —
 *    laporan tidak memuat teks sel);
 *  - sel yang bergantung NOW/TODAY dilaporkan terpisah;
 *  - beda dikelompokkan: AKAR (tidak ada pendahulu yang ikut beda) vs TURUNAN.
 * Skenario `cache` (nilai tersimpan di berkas): mesin penuh tanpa edit diadu
 * ke nilai cache Excel di berkas.
 *
 * Berkas bersandi: sandi dari env `STUDIO_SANDI_WORKBOOK` (diisi pembungkus,
 * dihapus sesudahnya) atau prompt tersembunyi. Tidak pernah dicetak/ditulis.
 */
class ParitasExcelStudio extends Command
{
    protected $signature = 'studio:paritas-excel
        {berkas : Workbook .xlsx/.xlsm (dibaca saja)}
        {--excel= : JSON hasil paritas-excel-com.ps1}
        {--skenario= : JSON skenario edit (format yang sama dengan skrip COM)}
        {--waktu-acuan= : Serial Excel untuk NOW()/TODAY() (samakan dengan skrip COM)}
        {--keluar= : Folder laporan (JSON rinci + ringkasan .md)}
        {--label= : Label workbook di laporan (bawaan: nama berkas)}';

    protected $description = 'Paritas mesin hitung Studio vs Excel COM untuk satu workbook (generik)';

    public function handle(PembacaXlsmWorkbook $pembaca, MesinHitung $mesin): int
    {
        ini_set('memory_limit', '1500M');
        $berkas = (string) $this->argument('berkas');

        if (! is_file($berkas)) {
            $this->error('Berkas tidak ada.');

            return self::FAILURE;
        }

        $sandi = '';

        if (PembacaXlsmWorkbook::bersandi($berkas)) {
            $sandi = (string) (getenv('STUDIO_SANDI_WORKBOOK') ?: $this->secret('Sandi workbook (tidak ditampilkan, tidak disimpan)'));
        }

        $label = (string) ($this->option('label') ?: pathinfo($berkas, PATHINFO_FILENAME));
        $t = microtime(true);

        try {
            $model = $pembaca->muatModelHitung($berkas, $sandi);
        } catch (GagalBacaWorkbook $e) {
            $this->error("{$e->kode}: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            unset($sandi);
        }

        $msMuat = (int) ((microtime(true) - $t) * 1000);
        $t = microtime(true);
        $graf = GrafDependensi::bangun($model);
        $msGraf = (int) ((microtime(true) - $t) * 1000);
        $waktu = $this->option('waktu-acuan') !== null ? (float) $this->option('waktu-acuan') : HitungWorkbook::serialWaktu(null);

        $excel = $this->option('excel') ? json_decode((string) file_get_contents((string) $this->option('excel')), true) : null;
        $excelPer = [];

        foreach ((array) ($excel['skenario'] ?? []) as $sk) {
            $excelPer[(string) $sk['nama']] = (array) $sk['sel'];
        }

        $daftar = $this->option('skenario')
            ? (array) (json_decode((string) file_get_contents((string) $this->option('skenario')), true)['skenario'] ?? [])
            : [];

        $cache = $this->cacheModel($model);
        $bergantungWaktu = $graf->volatil === [] ? [] : array_fill_keys($graf->volatil, true) + $graf->penutupan($graf->volatil);
        $laporan = [
            'label' => $label,
            'berkas_sha256' => hash_file('sha256', $berkas),
            'excel_versi' => $excel['versi_excel'] ?? null,
            'waktu_acuan' => $waktu,
            'rumus' => count($graf->rumus),
            'dinamis' => count($graf->dinamis),
            'volatil' => count($graf->volatil),
            'bergantung_waktu' => count($bergantungWaktu),
            'terlarang' => count($graf->terlarang),
            'ms_muat' => $msMuat,
            'ms_graf' => $msGraf,
            'skenario' => [],
        ];

        // (a) tanpa edit, mode penuh, lawan nilai cache yang tersimpan di berkas.
        $penuh = $mesin->hitung($model, $graf, [], $waktu, true, microtime(true) + 600);
        $laporan['skenario'][] = $this->banding('cache', 'mesin penuh tanpa edit vs nilai cache berkas', $penuh->nilai, $penuh->galat, $cache, $graf, $bergantungWaktu, $model, null, null);

        if (isset($excelPer['cache'])) {
            // Kewarasan: cache yang dibaca PhpSpreadsheet = cache yang dibaca Excel COM.
            $laporan['skenario'][] = $this->banding('cache-excel', 'nilai cache dibaca mesin vs dibaca Excel COM', $cache, [], $excelPer['cache'], $graf, [], $model, null, null);
        }

        // Model "konsisten": cache Excel diganti hasil mesin penuh tanpa edit. Dipakai untuk
        // kotor-vs-penuh, supaya yang diuji HANYA logika sel kotor (bukan beda mesin vs Excel,
        // yang sudah terukur di baris `cache`).
        $lembarKonsisten = $model->lembar;

        foreach ($lembarKonsisten as $i => $l) {
            foreach ($l['sel'] as $a => $s) {
                if ($s[0] === DataType::TYPE_FORMULA) {
                    $lembarKonsisten[$i]['sel'][$a][2] = $penuh->nilai[ModelHitung::kunci($l['judul'], (string) $a)] ?? $s[2];
                }
            }
        }

        $modelKonsisten = new ModelHitung($lembarKonsisten, $model->nama, $model->kalender);
        $cacheKonsisten = $this->cacheModel($modelKonsisten);
        unset($lembarKonsisten);

        foreach ($daftar as $sk) {
            $nama = (string) $sk['nama'];
            $edit = [];

            foreach ((array) ($sk['edit'] ?? []) as $e) {
                $edit[] = ['judul' => (string) $e['lembar'], 'alamat' => strtoupper((string) $e['a']), 'isi' => IsiSel::dariKetikan((string) $e['isi'])];
            }

            $t = microtime(true);
            $kotor = $mesin->hitung($model, $graf, $edit, $waktu, false, microtime(true) + 600);
            $msKotor = (int) ((microtime(true) - $t) * 1000);
            $penuhS = $mesin->hitung($modelKonsisten, $graf, $edit, $waktu, true, microtime(true) + 600);
            $kotorK = $mesin->hitung($modelKonsisten, $graf, $edit, $waktu, false, microtime(true) + 600);

            // Nilai lengkap mode kotor = cache ditimpa hasil sel kotor (sel diedit jadi konstan dibuang).
            $diedit = array_map(static fn (array $e): string => ModelHitung::kunci($e['judul'], $e['alamat']), array_filter($edit, static fn (array $e): bool => $e['isi']->tipe !== DataType::TYPE_FORMULA));
            $lengkapKotor = array_diff_key($kotor->nilai + $cache, array_flip($diedit));
            $lengkapKonsisten = array_diff_key($kotorK->nilai + $cacheKonsisten, array_flip($diedit));
            $selisihMode = 0;

            foreach ($penuhS->nilai as $k => $v) {
                if (! self::sama(self::bentuk($v), self::bentuk($lengkapKonsisten[$k] ?? null))) {
                    $selisihMode++;
                }
            }

            unset($penuhS, $kotorK, $lengkapKonsisten);

            $ref = $excelPer[$nama] ?? null;
            $baris = $this->banding($nama, 'mesin kotor vs Excel CalculateFull', $lengkapKotor, $kotor->galat, $ref, $graf, $bergantungWaktu, $model, $msKotor, count($kotor->kotor));
            $baris['kotor_vs_penuh_beda'] = $selisihMode;
            $baris['jumlah_edit'] = count($edit);
            $laporan['skenario'][] = $baris;
            $this->line(sprintf('[%s] dihitung %d, %d ms, kotor≠penuh %d, identik %s/%s', $nama, count($kotor->kotor), $msKotor, $selisihMode, $baris['identik'] ?? '-', $baris['total'] ?? '-'));
        }

        $keluar = (string) ($this->option('keluar') ?: storage_path('app/private/paritas'));

        if (! is_dir($keluar)) {
            mkdir($keluar, 0700, true);
        }

        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label);
        file_put_contents($keluar.DIRECTORY_SEPARATOR.$slug.'.paritas.json', json_encode($laporan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        file_put_contents($keluar.DIRECTORY_SEPARATOR.$slug.'.paritas.md', $this->ringkasan($laporan));
        $this->info("Laporan: {$keluar}".DIRECTORY_SEPARATOR."{$slug}.paritas.(json|md)");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $nilai  hasil mesin (kunci → nilai mentah)
     * @param  array<string, string>  $galat
     * @param  array<string, mixed>|null  $acuan  kunci → ['n','..'] (Excel) atau nilai mentah (cache model)
     * @param  array<string, true>  $bergantungWaktu
     * @return array<string, mixed>
     */
    private function banding(string $nama, string $keterangan, array $nilai, array $galat, ?array $acuan, GrafDependensi $graf, array $bergantungWaktu, ModelHitung $model, ?int $ms, ?int $dihitung): array
    {
        $hasil = ['nama' => $nama, 'keterangan' => $keterangan, 'ms_mesin' => $ms, 'dihitung' => $dihitung];

        if ($acuan === null) {
            return $hasil + ['total' => null, 'identik' => null];
        }

        $rumusModel = array_fill_keys($graf->rumus, true);
        $total = $identik = $galatN = $wIdentik = $wTotal = 0;
        $tidakTerbaca = [];
        $beda = [];
        $histogram = [];

        foreach ($acuan as $k => $refMentah) {
            $ref = is_array($refMentah) && isset($refMentah[0]) && is_string($refMentah[0]) && count($refMentah) === 2 ? $refMentah : self::bentuk($refMentah);

            if (! array_key_exists($k, $nilai) && ! isset($rumusModel[$k])) {
                // Teks konstan yang diawali "=" (skrip COM lama membacanya sebagai rumus) bukan rumus.
                if (! isset($this->teksSamaDengan($model)[$k])) {
                    // Sel berumus menurut Excel yang tidak ada sebagai rumus di model mesin.
                    $tidakTerbaca[] = $k;
                }

                continue;
            }

            $milik = self::bentuk($nilai[$k] ?? null);
            $waktuSel = isset($bergantungWaktu[$k]);

            if ($waktuSel) {
                $wTotal++;
                $wIdentik += self::sama($milik, $ref) ? 1 : 0;

                continue;
            }

            $total++;

            if (self::sama($milik, $ref)) {
                $identik++;

                continue;
            }

            if (isset($galat[$k])) {
                $galatN++;
            }

            $ulp = ($milik[0] === 'n' && $ref[0] === 'n') ? self::ulp((float) $milik[1], (float) $ref[1]) : null;
            $kelas = match (true) {
                isset($galat[$k]) => 'galat_mesin',
                $ulp !== null && $ulp <= 4 => 'ulp_1_4',
                $ulp !== null && $ulp <= 1024 => 'ulp_5_1024',
                $ulp !== null => 'angka_jauh',
                default => 'tipe_atau_teks_beda',
            };
            $histogram[$kelas] = ($histogram[$kelas] ?? 0) + 1;
            $beda[$k] = ['kelas' => $kelas, 'mesin' => $milik, 'acuan' => $ref, 'ulp' => $ulp, 'galat' => $galat[$k] ?? null];
        }

        // Akar vs turunan: beda yang tidak berada di hilir beda lain.
        $hilir = $beda === [] ? [] : $graf->penutupan(array_keys($beda));
        $peta = $this->petaRumus($model);

        foreach ($beda as $k => &$b) {
            $b['akar'] = ! isset($hilir[$k]);
            $b['rumus'] = isset($peta[$k]) ? preg_replace('/"(?:[^"]|"")*"/', '"…"', $peta[$k]) : null;
            $b['fungsi'] = isset($peta[$k]) ? self::fungsi($peta[$k]) : [];
        }

        unset($b);
        $akar = array_filter($beda, static fn (array $b): bool => $b['akar']);
        $perFungsi = [];

        foreach ($akar as $b) {
            foreach ($b['fungsi'] as $f) {
                $perFungsi[$f] = ($perFungsi[$f] ?? 0) + 1;
            }
        }

        arsort($perFungsi);

        return $hasil + [
            'total' => $total,
            'identik' => $identik,
            'beda' => count($beda),
            'galat_mesin' => $galatN,
            'kelas' => $histogram,
            'akar' => count($akar),
            'fungsi_di_akar' => $perFungsi,
            'bergantung_waktu' => ['total' => $wTotal, 'identik' => $wIdentik],
            'rumus_excel_tak_terbaca_mesin' => count($tidakTerbaca),
            'contoh_tak_terbaca' => array_slice($tidakTerbaca, 0, 20),
            'rinci' => $beda,
        ];
    }

    /** @var array<string, true>|null */
    private ?array $teksSamaDengan = null;

    /** @return array<string, true> sel teks konstan yang diawali "=" */
    private function teksSamaDengan(ModelHitung $model): array
    {
        if ($this->teksSamaDengan !== null) {
            return $this->teksSamaDengan;
        }

        $this->teksSamaDengan = [];

        foreach ($model->lembar as $l) {
            foreach ($l['sel'] as $a => $s) {
                if ($s[0] === DataType::TYPE_STRING && is_string($s[1]) && str_starts_with($s[1], '=')) {
                    $this->teksSamaDengan[ModelHitung::kunci($l['judul'], (string) $a)] = true;
                }
            }
        }

        return $this->teksSamaDengan;
    }

    /** @return array<string, mixed> */
    private function cacheModel(ModelHitung $model): array
    {
        $hasil = [];

        foreach ($model->lembar as $l) {
            foreach ($l['sel'] as $a => $s) {
                if ($s[0] === DataType::TYPE_FORMULA) {
                    $hasil[ModelHitung::kunci($l['judul'], (string) $a)] = $s[2];
                }
            }
        }

        return $hasil;
    }

    /** @return array<string, string> */
    private function petaRumus(ModelHitung $model): array
    {
        $hasil = [];

        foreach ($model->lembar as $l) {
            foreach ($l['sel'] as $a => $s) {
                if ($s[0] === DataType::TYPE_FORMULA) {
                    $hasil[ModelHitung::kunci($l['judul'], (string) $a)] = (string) $s[1];
                }
            }
        }

        return $hasil;
    }

    /** @return list<string> */
    private static function fungsi(string $rumus): array
    {
        $tanpa = preg_replace('/"(?:[^"]|"")*"/', '""', $rumus) ?? $rumus;
        $tanpa = preg_replace("/'(?:[^']|'')*'!/", 'S!', $tanpa) ?? $tanpa;
        preg_match_all('/(?:_xlfn\.)?([A-Z][A-Z0-9.]*)\s*\(/i', $tanpa, $m);

        return array_values(array_unique(array_map('strtoupper', $m[1])));
    }

    /**
     * Nilai mentah → bentuk pembanding yang sama dengan skrip COM.
     *
     * @return array{0: string, 1: string}
     */
    public static function bentuk(mixed $v): array
    {
        return match (true) {
            $v === null => ['k', ''],
            is_bool($v) => ['b', $v ? '1' : '0'],
            is_int($v), is_float($v) => ['n', sprintf('%.17G', (float) $v)],
            is_string($v) && in_array($v, ExcelError::ERROR_CODES, true) => ['e', $v],
            $v === '' => ['s', ''],
            default => ['s', 'h:'.substr(hash('sha256', (string) $v), 0, 16).':'.mb_strlen((string) $v, 'UTF-8')],
        };
    }

    /** @param  array{0: string, 1: string}  $a */
    private static function sama(array $a, array $b): bool
    {
        if ($a[0] === 'n' && $b[0] === 'n') {
            return (float) $a[1] === (float) $b[1];
        }

        // Excel COM: rumus yang hasilnya sel kosong dibaca 0 oleh Value2? Tidak —
        // Value2 rumus selalu bernilai; 'k' hanya dari sisi mesin (null).
        if ($a[0] === 'k' && $b[0] === 'n') {
            return (float) $b[1] === 0.0;
        }

        return $a === $b;
    }

    private static function ulp(float $a, float $b): int
    {
        $ia = unpack('q', pack('d', $a))[1];
        $ib = unpack('q', pack('d', $b))[1];

        if (($ia < 0) !== ($ib < 0)) {
            return PHP_INT_MAX;
        }

        return abs($ia - $ib);
    }

    /** @param  array<string, mixed>  $l */
    private function ringkasan(array $l): string
    {
        $md = "# Paritas mesin Studio vs Excel — {$l['label']}\n\n";
        $md .= "sha256 berkas `{$l['berkas_sha256']}` · Excel {$l['excel_versi']} · waktu acuan (serial) {$l['waktu_acuan']}\n\n";
        $md .= "Rumus {$l['rumus']} · dinamis {$l['dinamis']} · volatil {$l['volatil']} (hilirnya {$l['bergantung_waktu']}) · fungsi terlarang {$l['terlarang']} · muat {$l['ms_muat']} ms · graf {$l['ms_graf']} ms\n\n";
        $md .= "| skenario | edit | dihitung | ms mesin | total | identik | beda | galat mesin | akar beda | kelas | bergantung NOW (identik/total) | kotor≠penuh | rumus Excel tak terbaca |\n|---|---|---|---|---|---|---|---|---|---|---|---|---|\n";

        foreach ($l['skenario'] as $s) {
            $kelas = isset($s['kelas']) ? implode(', ', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($s['kelas']), $s['kelas'])) : '';
            $w = isset($s['bergantung_waktu']) ? "{$s['bergantung_waktu']['identik']}/{$s['bergantung_waktu']['total']}" : '-';
            $md .= sprintf("| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                $s['nama'], $s['jumlah_edit'] ?? 0, $s['dihitung'] ?? '-', $s['ms_mesin'] ?? '-', $s['total'] ?? '-', $s['identik'] ?? '-',
                $s['beda'] ?? '-', $s['galat_mesin'] ?? '-', $s['akar'] ?? '-', $kelas, $w, $s['kotor_vs_penuh_beda'] ?? '-',
                $s['rumus_excel_tak_terbaca_mesin'] ?? '-');
        }

        $md .= "\n## Beda akar per skenario (alamat, kelas, ULP, fungsi; teks disamarkan)\n";

        foreach ($l['skenario'] as $s) {
            $akar = array_filter($s['rinci'] ?? [], static fn (array $b): bool => $b['akar']);

            if ($akar === []) {
                continue;
            }

            $md .= "\n### {$s['nama']}\n\n";

            foreach (array_slice($akar, 0, 40, true) as $k => $b) {
                $md .= "- `{$k}` {$b['kelas']}".($b['ulp'] !== null ? " ulp {$b['ulp']}" : '').' · '.implode(',', $b['fungsi']).($b['galat'] ? ' · '.$b['galat'] : '')."\n";
            }
        }

        return $md;
    }
}
