<?php

namespace App\Console\Commands;

use App\Services\Studio\Hitung\GrafDependensi;
use App\Services\Studio\Hitung\ModelHitung;
use App\Services\Studio\Workbook\GagalBacaWorkbook;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Bangkitkan skenario edit SINTETIS untuk harness paritas (Studio tahap C).
 * Generik: sel input = sel KONSTAN yang dirujuk rumus (langsung atau lewat
 * rentang), diurut menurut banyaknya rumus di hilirnya. Nilai edit dibangkitkan
 * deterministik dari nilai lama (geser relatif kecil/besar, bilangan bulat +1),
 * jadi tidak ada angka lab yang dikarang sebagai "bacaan" — hanya gangguan.
 *
 *   php artisan studio:paritas-skenario "X.xlsx" --lembar="INPUT DATA" --lembar="Data Sens Semi Micro" \
 *       --ganti-teks=E2,F1,M1 --jumlah=8 --keluar=skenario.json
 *
 * `--ganti-teks`: untuk sel input TEKS yang isinya salah satu nilai daftar,
 * dibuat satu skenario per nilai pengganti (mis. kelas anak timbangan).
 * Keluaran hanya alamat + angka/kode sintetis; teks sel lain tidak ditulis.
 */
class ParitasSkenarioStudio extends Command
{
    protected $signature = 'studio:paritas-skenario
        {berkas : Workbook .xlsx/.xlsm}
        {--lembar=* : Batasi sel input ke lembar ini (bawaan: semua lembar tampil)}
        {--jumlah=8 : Banyak skenario angka}
        {--ganti-teks= : Daftar nilai teks yang boleh saling ganti, dipisah koma}
        {--keluar= : Jalur JSON skenario}';

    protected $description = 'Bangkitkan skenario edit sintetis (generik) untuk studio:paritas-excel';

    public function handle(PembacaXlsmWorkbook $pembaca): int
    {
        ini_set('memory_limit', '1500M');
        $berkas = (string) $this->argument('berkas');
        $sandi = PembacaXlsmWorkbook::bersandi($berkas)
            ? (string) (getenv('STUDIO_SANDI_WORKBOOK') ?: $this->secret('Sandi workbook (tidak ditampilkan, tidak disimpan)'))
            : '';

        try {
            $model = $pembaca->muatModelHitung($berkas, $sandi);
        } catch (GagalBacaWorkbook $e) {
            $this->error("{$e->kode}: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            unset($sandi);
        }

        $graf = GrafDependensi::bangun($model);
        $batasLembar = array_map('strval', (array) $this->option('lembar'));
        $daftarTeks = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('ganti-teks')))));

        $angka = [];
        $teks = [];

        foreach ($model->lembar as $l) {
            if ($batasLembar !== [] ? ! in_array($l['judul'], $batasLembar, true) : $l['status'] !== 'visible') {
                continue;
            }

            foreach ($l['sel'] as $a => $s) {
                if ($s[0] === DataType::TYPE_FORMULA) {
                    continue;
                }

                $kunci = ModelHitung::kunci($l['judul'], (string) $a);
                $langsung = $graf->pengguna($kunci);

                if ($langsung === []) {
                    continue;
                }

                $hilir = count($graf->penutupan($langsung)) + count($langsung);

                if ($s[0] === DataType::TYPE_NUMERIC) {
                    $angka[] = ['lembar' => $l['judul'], 'a' => (string) $a, 'nilai' => (float) $s[1], 'hilir' => $hilir];
                } elseif ($s[0] === DataType::TYPE_STRING && in_array((string) $s[1], $daftarTeks, true)) {
                    $teks[] = ['lembar' => $l['judul'], 'a' => (string) $a, 'nilai' => (string) $s[1], 'hilir' => $hilir];
                }
            }
        }

        usort($angka, static fn (array $x, array $y): int => [$y['hilir'], $x['lembar'], $x['a']] <=> [$x['hilir'], $y['lembar'], $y['a']]);

        $skenario = [['nama' => 'a-tanpa-edit', 'edit' => []]];
        $faktor = [1.005, 0.98, 1.0001, 1.05, 0.999, 1.02, 0.95, 1.001, 1.1, 0.9];
        $jumlah = max(1, (int) $this->option('jumlah'));
        $pilih = array_slice($angka, 0, 40);
        mt_srand(20261014);

        for ($i = 0; $i < $jumlah && $pilih !== []; $i++) {
            $banyak = [1, 1, 2, 3, 5, 8, 13, 20][$i % 8];
            $edit = [];

            for ($j = 0; $j < $banyak; $j++) {
                $sel = $pilih[($i * 7 + $j * 3) % count($pilih)];
                $f = $faktor[($i + $j) % count($faktor)];
                $baru = self::geser($sel['nilai'], $f);
                $edit[$sel['lembar'].'!'.$sel['a']] = ['lembar' => $sel['lembar'], 'a' => $sel['a'], 'isi' => self::teksAngka($baru)];
            }

            $skenario[] = ['nama' => 'b'.($i + 1).'-angka-'.count($edit).'sel', 'edit' => array_values($edit)];
        }

        foreach ($teks as $t) {
            foreach ($daftarTeks as $ganti) {
                if ($ganti !== $t['nilai']) {
                    $skenario[] = ['nama' => 'c-teks-'.$t['a'].'-'.$ganti, 'edit' => [['lembar' => $t['lembar'], 'a' => $t['a'], 'isi' => $ganti]]];
                }
            }
        }

        $keluar = (string) ($this->option('keluar') ?: 'skenario.json');
        file_put_contents($keluar, json_encode(['skenario' => $skenario], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info(sprintf('%d sel input angka, %d sel input teks bertukar; %d skenario → %s', count($angka), count($teks), count($skenario), $keluar));

        return self::SUCCESS;
    }

    private static function geser(float $nilai, float $faktor): float
    {
        if ($nilai === 0.0) {
            return $faktor > 1 ? 0.001 : -0.001;
        }

        if (floor($nilai) === $nilai && abs($nilai) < 1e6 && abs($nilai) >= 1) {
            return $nilai + ($faktor >= 1 ? 1 : -1);
        }

        return $nilai * $faktor;
    }

    /** Angka bertitik desimal 15 digit bermakna — seperti diketik orang. */
    private static function teksAngka(float $x): string
    {
        $t = sprintf('%.15G', $x);

        if (str_contains($t, 'E') || ! str_contains($t, '.')) {
            return $t;
        }

        return rtrim(rtrim($t, '0'), '.');
    }
}
