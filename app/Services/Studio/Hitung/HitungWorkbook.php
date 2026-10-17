<?php

namespace App\Services\Studio\Hitung;

use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Studio\Workbook\FormatBersyarat;
use App\Services\Studio\Workbook\PembacaXlsmWorkbook;
use App\Services\Studio\Workbook\ProteksiLembar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Calculation\Information\ExcelError;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;

/**
 * `POST /api/studio/workbook/{workbook}/hitung` (Studio tahap C, kontrak
 * docs/kontrak-api.md §18). TIDAK menulis apa pun ke DB: stateless, klien
 * mengirim SEMUA edit yang berlaku, server menghitung ulang sel kotor dari
 * nilai cache Excel + edit, lalu memulangkan hanya sel yang nilai/teks
 * tampil/gayanya beda dari snapshot impor (+ semua sel yang diedit), dalam
 * format sel yang sama persis dengan `GET …/lembar/{lembar}`.
 *
 * Angka tidak pernah dibulatkan (`n` presisi penuh); `s` diformat dengan
 * number format sel lewat fungsi yang sama dengan impor
 * (`PembacaXlsmWorkbook::teksTampil`). Conditional formatting diterapkan ulang
 * ke `g` dari nilai baru (`FormatBersyarat`).
 *
 * Proteksi lembar Excel (K-49-15): edit ke sel terkunci di lembar yang
 * dilindungi → 422 `sel_terkunci`, kecuali `$bolehSelTerkunci` (super_admin,
 * `ProteksiLembar::bolehMelewati`). Diperiksa sebelum model hitung dimuat.
 */
final class HitungWorkbook
{
    private const POLA_ALAMAT = '/^\$?([A-Z]{1,3})\$?([1-9][0-9]{0,6})$/';

    public function __construct(
        private readonly SumberModelHitung $sumber,
        private readonly MesinHitung $mesin,
    ) {}

    /**
     * @param  list<array{lembar_id: int, a: string, isi: string|null}>  $editMentah
     * @return array{lembar: list<array{lembar_id: int, sel: list<array<string, mixed>>}>, jumlah_dihitung: int, durasi_ms: int, galat: list<array{lembar_id: int, a: string, pesan: string}>}
     *
     * @throws GagalHitung
     */
    public function jalankan(WorkbookMaster $wb, array $editMentah, ?string $waktuAcuan, bool $bolehSelTerkunci = false): array
    {
        $mulai = microtime(true);
        $tenggat = $mulai + (float) config('studio.hitung.batas_detik', 20);

        if ($wb->sumber !== WorkbookMaster::SUMBER_XLSM || ! $wb->berkasAsliTersimpan()) {
            throw GagalHitung::berkasAsliTidakAda();
        }

        $lembar = $wb->lembar()->select(['id', 'workbook_master_id', 'urutan', 'nama', 'tata_letak'])->orderBy('urutan')->get();
        $perId = $lembar->keyBy('id');
        $perNama = $lembar->keyBy('nama');
        [$edit, $isiAsli, $indeks] = $this->validasi($editMentah, $perId);

        if (! $bolehSelTerkunci) {
            $this->tolakSelTerkunci($edit, $indeks, $perNama);
        }

        $serial = self::serialWaktu($waktuAcuan);

        [$model, $graf] = $this->sumber->ambil($wb);
        $judulModel = array_column($model->lembar, 'judul');

        foreach ($edit as $i => $e) {
            if (! in_array($e['judul'], $judulModel, true)) {
                throw GagalHitung::editTidakValid('Lembar tidak ada di berkas asli workbook ini.', ["edit.{$i}.lembar_id" => ['Lembar tidak ada di berkas asli workbook ini.']]);
            }
        }

        $this->pagar($edit, $indeks, $model);

        $hasil = $this->mesin->hitung($model, $graf, array_values($edit), $serial, false, $tenggat);

        // Sel kandidat per lembar: rumus yang dihitung + sel yang diedit.
        $kandidat = [];

        foreach ($hasil->kotor as $kunci) {
            [$judul, $alamat] = ModelHitung::urai($kunci);
            $kandidat[$judul][$alamat] = ['rumus', $hasil->nilai[$kunci] ?? null];
        }

        foreach ($edit as $i => $e) {
            $kunci = ModelHitung::kunci($e['judul'], $e['alamat']);
            $kandidat[$e['judul']][$e['alamat']] = $e['isi']->tipe === DataType::TYPE_FORMULA
                ? ['edit-rumus', $hasil->nilai[$kunci] ?? null, $isiAsli[$i]]
                : ['edit-konstan', $e['isi']];
        }

        $keluar = [];

        foreach ($kandidat as $judul => $sel) {
            /** @var WorkbookMasterLembar|null $baris */
            $baris = $perNama->get($judul);

            if ($baris === null) {
                continue;
            }

            $keluar[] = ['lembar_id' => (int) $baris->id, 'sel' => $this->selBeda($baris, $sel)];
        }

        $keluar = array_values(array_filter($keluar, static fn (array $l): bool => $l['sel'] !== []));

        $galat = [];

        foreach ($hasil->galat as $kunci => $pesan) {
            [$judul, $alamat] = ModelHitung::urai($kunci);
            $baris = $perNama->get($judul);

            if ($baris !== null) {
                $galat[] = ['lembar_id' => (int) $baris->id, 'a' => $alamat, 'pesan' => $pesan];
            }
        }

        return [
            'lembar' => $keluar,
            'jumlah_dihitung' => count($hasil->kotor),
            'durasi_ms' => (int) round((microtime(true) - $mulai) * 1000),
            'galat' => $galat,
        ];
    }

    /**
     * Waktu acuan NOW()/TODAY() → serial Excel (sistem 1900). ISO-8601 dengan
     * offset → jam dinding di offset itu (seperti Excel memakai jam lokal
     * komputernya); tanpa nilai → sekarang di `studio.hitung.zona_waktu`.
     */
    public static function serialWaktu(?string $iso): float
    {
        $waktu = $iso === null || $iso === ''
            ? CarbonImmutable::now((string) config('studio.hitung.zona_waktu', 'Asia/Jakarta'))
            : CarbonImmutable::parse($iso);

        return (float) SharedDate::dateTimeToExcel($waktu);
    }

    /**
     * @param  Collection<int, WorkbookMasterLembar>  $perId
     * @param  list<array{lembar_id: int, a: string, isi: string|null}>  $editMentah
     * @return array{0: array<int, array{judul: string, alamat: string, isi: IsiSel}>, 1: array<int, string>, 2: array<int, int>}
     */
    private function validasi(array $editMentah, $perId): array
    {
        $maks = (int) config('studio.hitung.maks_panjang_rumus', 8192);
        $edit = [];
        $isiAsli = [];
        $indeks = [];
        $galat = [];

        foreach ($editMentah as $i => $e) {
            $baris = $perId->get((int) $e['lembar_id']);

            if ($baris === null) {
                $galat["edit.{$i}.lembar_id"] = ['Lembar bukan milik workbook ini.'];

                continue;
            }

            $alamat = strtoupper(trim((string) $e['a']));

            if (preg_match(self::POLA_ALAMAT, $alamat, $m) !== 1
                || Coordinate::columnIndexFromString($m[1]) > 16384 || (int) $m[2] > 1048576) {
                $galat["edit.{$i}.a"] = ['Alamat sel tidak valid (contoh: E5).'];

                continue;
            }

            $alamat = $m[1].$m[2];
            $teks = (string) ($e['isi'] ?? '');
            $isi = IsiSel::dariKetikan($teks);

            if ($isi->tipe === DataType::TYPE_FORMULA) {
                if (mb_strlen($teks) > $maks) {
                    $galat["edit.{$i}.isi"] = ["Rumus lebih dari {$maks} karakter."];

                    continue;
                }

                $terlarang = DaftarPutihFungsi::terlarang($teks);

                if ($terlarang === null) {
                    $galat["edit.{$i}.isi"] = ['Rumus tidak bisa diurai.'];

                    continue;
                }

                if ($terlarang !== []) {
                    $galat["edit.{$i}.isi"] = ['Fungsi tidak diizinkan di Studio: '.implode(', ', $terlarang).'.'];

                    continue;
                }

                // Tinjau C #1: mesin memotong baris penuh beda dari Excel (`PagarRumus`).
                if (PagarRumus::adaBarisPenuh($teks)) {
                    $galat["edit.{$i}.isi"] = ['Rujukan baris penuh (mis. 1:1) belum didukung di rumus edit Studio — hasil mesin bisa beda dari Excel. Tulis rentangnya, mis. A1:Z1.'];

                    continue;
                }
            }

            // Edit ganda di sel yang sama: yang terakhir berlaku (seperti mengetik ulang).
            $kunci = $baris->id.'|'.$alamat;
            unset($edit[$kunci], $isiAsli[$kunci], $indeks[$kunci]);
            $edit[$kunci] = ['judul' => (string) $baris->nama, 'alamat' => $alamat, 'isi' => $isi];
            $isiAsli[$kunci] = $teks;
            $indeks[$kunci] = (int) $i;
        }

        if ($galat !== []) {
            throw GagalHitung::editTidakValid((string) reset($galat)[0], $galat);
        }

        return [array_values($edit), array_values($isiAsli), array_values($indeks)];
    }

    /**
     * K-49-15: sel terkunci di lembar yang dilindungi Excel tidak boleh
     * disunting selain super_admin. Semua sel terkunci di request disebut
     * sekaligus (`sel_terkunci`), bukan satu per satu.
     *
     * @param  list<array{judul: string, alamat: string, isi: IsiSel}>  $edit
     * @param  list<int>  $indeks  indeks edit di request (kunci `errors`)
     * @param  Collection<string, WorkbookMasterLembar>  $perNama
     *
     * @throws GagalHitung
     */
    private function tolakSelTerkunci(array $edit, array $indeks, Collection $perNama): void
    {
        $rentang = [];
        $sel = [];
        $label = [];
        $galat = [];

        foreach ($edit as $j => $e) {
            /** @var WorkbookMasterLembar $baris */
            $baris = $perNama->get($e['judul']);
            $id = (int) $baris->id;

            if (! array_key_exists($id, $rentang)) {
                $rentang[$id] = ProteksiLembar::dariTataLetak(is_array($baris->tata_letak) ? $baris->tata_letak : []);
            }

            [$kolom, $nomor] = Coordinate::indexesFromString($e['alamat']);

            if (! ProteksiLembar::terkunci($rentang[$id], (int) $kolom, (int) $nomor)) {
                continue;
            }

            $sel[] = ['lembar_id' => $id, 'a' => $e['alamat']];
            $label[] = $e['judul'].'!'.$e['alamat'];
            $galat['edit.'.($indeks[$j] ?? $j).'.a'] = ["Sel {$e['alamat']} di lembar \"{$e['judul']}\" terkunci (lembar dilindungi di Excel). Hanya akun Studio yang boleh mengubahnya."];
        }

        if ($sel === []) {
            return;
        }

        $sisa = count($label) > 10 ? ' (+'.(count($label) - 10).' lagi)' : '';

        throw GagalHitung::selTerkunci(
            count($sel).' sel terkunci di lembar yang dilindungi Excel: '.implode(', ', array_slice($label, 0, 10)).$sisa.'. Hanya super admin yang boleh mengubahnya.',
            $galat,
            $sel,
        );
    }

    /**
     * Pagar beban rumus edit (tinjau C #1) — sesudah model dimuat, karena
     * kolom penuh ditimbang terhadap area terpakai lembar sesudah edit
     * dan rumus asli sel dibandingkan.
     *
     *  - jumlah sel yang dirujuk (`PagarRumus`) > `studio.hitung.maks_sel_rumus_edit` → 422;
     *  - hasil kali dua rentang terbesar > `studio.hitung.maks_pasangan_rumus_edit` → 422
     *    (biaya kuadratik fungsi larik/pencarian & siaran larik);
     *  - operasi teks per unsur (`&`, fungsi teks) × larik terbesar >
     *    `studio.hitung.maks_larik_teks_rumus_edit` → 422 (memori: tiap unsur
     *    bisa 32.767 huruf, dibangun dalam satu operasi yang tidak bisa diputus
     *    `PagarMemori` — tinjau C-3 #1);
     *  - rujukan dinamis (INDIRECT, OFFSET, rentang yang ujungnya hasil fungsi) → 422.
     *
     * Satu pengecualian: rumus yang SAMA dengan rumus asli sel itu (mengetik
     * ulang rumus workbook) selalu boleh — beban itu memang sudah dijalankan
     * mesin untuk workbook ini, dan pagarnya tetap `KalkulasiBerpagar`.
     * Pengecualian sengaja TIDAK diperluas ke "sel yang rumus aslinya juga
     * dinamis": sel seperti itu lalu bisa diisi `INDIRECT("A1:XFD1048576")`.
     *
     * @param  list<array{judul: string, alamat: string, isi: IsiSel}>  $edit
     * @param  list<int>  $indeks  indeks edit di request (kunci `errors`)
     *
     * @throws GagalHitung
     */
    private function pagar(array $edit, array $indeks, ModelHitung $model): void
    {
        $maks = (int) config('studio.hitung.maks_sel_rumus_edit', 10000);
        $maksPasangan = (int) config('studio.hitung.maks_pasangan_rumus_edit', 250000);
        $maksTeks = (int) config('studio.hitung.maks_larik_teks_rumus_edit', 4000);
        $luas = null;
        $galat = [];

        foreach ($edit as $j => $e) {
            if ($e['isi']->tipe !== DataType::TYPE_FORMULA) {
                continue;
            }

            $teks = (string) $e['isi']->nilai;
            $asli = PagarRumus::rumusAsli($model, $e['judul'], $e['alamat']);

            if ($asli !== null && strcasecmp(trim($teks), trim($asli)) === 0) {
                continue;
            }

            $luas ??= PagarRumus::luas($model, $edit);
            $berat = PagarRumus::timbang($teks, $e['judul'], $model, $luas);
            $kunci = 'edit.'.($indeks[$j] ?? $j).'.isi';

            if ($berat['dinamis']) {
                $galat[$kunci] = ['Rujukan dinamis (INDIRECT, OFFSET, atau rentang yang ujungnya hasil fungsi) tidak diizinkan di rumus edit Studio — kecuali mengetik ulang rumus asli sel ini apa adanya.'];

                continue;
            }

            if ($berat['sel'] > $maks) {
                $galat[$kunci] = ["Rumus merujuk {$berat['sel']} sel (kolom penuh dihitung sampai baris terakhir berisi di lembar); batas rumus edit Studio {$maks} sel."];

                continue;
            }

            if ($berat['pasangan'] > $maksPasangan) {
                [$r1, $r2] = $berat['rentang'];
                $galat[$kunci] = ["Rumus memadukan rentang {$r1} sel dan {$r2} sel (hasil kali {$berat['pasangan']}); batas rumus edit Studio {$maksPasangan}. Perkecil rentangnya."];

                continue;
            }

            // Tinjau C-3 #1: `&`/fungsi teks per unsur atas larik besar = ratusan MB dalam satu operasi.
            $bebanTeks = $berat['teks'] * $berat['larik'];

            if ($berat['teks'] > 0 && $berat['larik'] > 1 && $bebanTeks > $maksTeks) {
                $galat[$kunci] = ["Rumus membangun teks (operator & atau fungsi teks, {$berat['teks']} kali) atas larik sampai {$berat['larik']} unsur (beban {$bebanTeks}); batas rumus edit Studio {$maksTeks}. Perkecil rentangnya."];
            }
        }

        if ($galat !== []) {
            throw GagalHitung::rumusTerlaluBerat((string) reset($galat)[0], $galat);
        }
    }

    /**
     * @param  array<string, array{0: string, 1: mixed, 2?: string}>  $calon
     * @return list<array<string, mixed>>
     */
    private function selBeda(WorkbookMasterLembar $baris, array $calon): array
    {
        $snapshot = [];
        // Daftar lembar dimuat tanpa kolom `sel` (bisa megabyte); sel hanya untuk lembar yang berubah.
        $lengkap = WorkbookMasterLembar::query()->find($baris->id);

        foreach ($lengkap?->daftarSel() ?? [] as $s) {
            if (isset($calon[$s['a']])) {
                $snapshot[$s['a']] = $s;
            }
        }

        $tata = is_array($baris->tata_letak) ? $baris->tata_letak : [];
        $cf = FormatBersyarat::dariTataLetak(is_array($tata['cf'] ?? null) ? $tata['cf'] : []);
        $gayaDasar = is_array($tata['cf_gaya_dasar'] ?? null) ? $tata['cf_gaya_dasar'] : [];
        $hasil = [];

        foreach ($calon as $alamat => $c) {
            $lama = $snapshot[$alamat] ?? null;
            [$kol, $bar] = Coordinate::coordinateFromString((string) $alamat);
            $b = (int) $bar;
            $k = Coordinate::columnIndexFromString($kol);
            $f = $lama['f'] ?? null;
            $diedit = $c[0] !== 'rumus';

            [$t, $n, $s, $mentah] = match ($c[0]) {
                'edit-konstan' => self::bentukKonstan($c[1], $f),
                default => [...self::bentukHasil($c[1], $f), $c[1]],
            };

            $r = match ($c[0]) {
                'rumus' => $lama['r'] ?? null,
                'edit-rumus' => $c[2],
                default => null,
            };

            $g = $lama['g'] ?? null;

            if ($cf !== [] && FormatBersyarat::dalamRentang($cf, $b, $k)) {
                $dasar = array_key_exists($alamat, $gayaDasar) ? $gayaDasar[$alamat] : $g;
                $g = FormatBersyarat::terapkan($cf, $b, $k, $mentah, is_array($dasar) ? $dasar : null);
            }

            $baru = ['a' => (string) $alamat, 'b' => $b, 'k' => $k, 't' => $t, 'n' => $n, 's' => $s, 'r' => $r, 'f' => $f, 'g' => $g];
            // Urutan kunci sama dengan sel kanonik GET lembar (JsonKanonik: urut byte).
            ksort($baru);

            if (! $diedit && $lama !== null
                && $lama['t'] === $t && $lama['n'] === $n && $lama['s'] === $s && $lama['g'] == $g) {
                continue;
            }

            $hasil[] = $baru;
        }

        usort($hasil, static fn (array $x, array $y): int => [$x['b'], $x['k']] <=> [$y['b'], $y['k']]);

        return $hasil;
    }

    /** @return array{0: string, 1: ?float, 2: string} t, n, s untuk hasil rumus */
    private static function bentukHasil(mixed $v, ?string $f): array
    {
        return match (true) {
            $v === null => ['f', 0.0, PembacaXlsmWorkbook::teksTampil(0.0, $f)],
            is_bool($v) => ['f', null, $v ? 'TRUE' : 'FALSE'],
            is_int($v), is_float($v) => is_finite((float) $v)
                ? ['f', (float) $v, PembacaXlsmWorkbook::teksTampil((float) $v, $f)]
                : ['f', null, ExcelError::NAN()],
            default => ['f', null, (string) $v],
        };
    }

    /** @return array{0: string, 1: ?float, 2: string, 3: mixed} t, n, s, nilai mentah untuk edit konstan */
    private static function bentukKonstan(IsiSel $isi, ?string $f): array
    {
        return match ($isi->tipe) {
            IsiSel::KOSONG => ['kosong', null, '', null],
            DataType::TYPE_NUMERIC => ['n', (float) $isi->nilai, PembacaXlsmWorkbook::teksTampil((float) $isi->nilai, $f), (float) $isi->nilai],
            DataType::TYPE_BOOL => ['b', null, $isi->nilai ? 'TRUE' : 'FALSE', (bool) $isi->nilai],
            default => ['s', null, (string) $isi->nilai, (string) $isi->nilai],
        };
    }
}
