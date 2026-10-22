<?php

namespace App\Services\Studio\PetaSel;

use App\Models\PaketAcuanVersi;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Support\JsonKanonik;
use RuntimeException;
use Throwable;

/**
 * Data acuan yang DIBAWA BERSAMA oleh semua varian master satu profil
 * (temuan paritas independen Micrometer, 22 Okt 2026).
 *
 * Keempat master Micrometer (0-25/25-50/50-75/75-100) masing-masing membawa
 * `balok_ukur` (Standar_GB!Q10:R132), pita `cmc[A-D].u95_um/label`
 * (DATABASE!S5:T8), dan identitas standar (DATABASE!S13:W13). Generator lama
 * (`gen-tabel-standar-micrometer.py`) menuntut keempatnya SEPAKAT;
 * [PemeriksaMaster] memeriksa satu master saja. Jadi mengimpor satu varian bisa
 * menulis ulang data yang dipakai tiga varian lain — tanpa satu pun yang
 * memberi tahu.
 *
 * Kelas ini tidak memblokir impor. Dia menandai daun bersama yang berubah
 * (`bersama_antar_varian`) dan menyebut master AKTIF varian lain yang isinya
 * tidak sepakat dengan draf; pengesahan menuntut pengakuan + alasan kalau
 * daftar pertama tidak kosong (`AlurVersiAcuan::sahkan`).
 *
 * "Bersama" = jalur `ekstrak` peta yang tidak memuat `{varian}` — diturunkan
 * dari peta, bukan daftar tulis tangan.
 */
final class DataBersamaVarian
{
    public function __construct(private readonly PemeriksaMaster $pemeriksa) {}

    /**
     * Jalur ekstrak peta yang dipakai bersama semua varian.
     *
     * @return list<string>
     */
    public static function jalurBersama(PetaSel $peta): array
    {
        if (! isset($peta->isi['varian'])) {
            return [];
        }

        $hasil = [];

        foreach ($peta->isi['ekstrak'] as $e) {
            $jalur = (string) $e['jalur'];

            // Butir ber-`varian` milik varian tertentu saja — bukan data bersama.
            if (! str_contains($jalur, '{varian}') && ! str_contains($jalur, '{i}') && ! isset($e['varian'])) {
                $hasil[] = $jalur;
            }
        }

        return array_values(array_unique($hasil));
    }

    /**
     * @param  array<array-key, mixed>|null  $isiDasar  isi versi dasar draf (null = tidak ada dasar)
     * @param  array{workbook_master_id?: ?int, varian?: ?string}|null  $sumber  sumber draf dari log `dibuat`
     * @return array{
     *     berlaku: bool,
     *     varian_draf: ?string,
     *     berubah: list<array{jalur: string, lama: ?string, baru: ?string}>,
     *     master_lain: list<array<string, mixed>>
     * }
     */
    public function periksa(PaketAcuanVersi $draf, string $kodeProfil, ?array $isiDasar, ?array $sumber): array
    {
        $peta = PetaSel::untuk($kodeProfil);
        $kosong = ['berlaku' => false, 'varian_draf' => $sumber['varian'] ?? null, 'berubah' => [], 'master_lain' => []];

        if ($peta === null) {
            return $kosong;
        }

        $jalur = self::jalurBersama($peta);

        if ($jalur === []) {
            return $kosong;
        }

        $isiDraf = $draf->isiTerurai();
        $berubah = $isiDasar === null ? [] : self::beda($jalur, $isiDasar, $isiDraf);

        $masterLain = [];
        $sumberId = isset($sumber['workbook_master_id']) ? (int) $sumber['workbook_master_id'] : null;

        $daftar = WorkbookMaster::query()
            ->where('organization_id', $draf->organization_id)
            ->where('kode_profil', $kodeProfil)
            ->where('aktif', true)
            ->whereNull('diarsipkan_pada')
            ->when($sumberId !== null, fn ($q) => $q->where('id', '!=', $sumberId))
            ->orderBy('id')
            ->get();

        foreach ($daftar as $w) {
            $baris = [
                'workbook_master_id' => $w->id,
                'nama' => $w->nama,
                'sha256' => $w->sha256,
                'varian' => null,
                'beda' => [],
            ];

            try {
                $lembar = WorkbookMasterLembar::query()
                    ->where('workbook_master_id', $w->id)
                    ->get()
                    ->map(static fn (WorkbookMasterLembar $l): array => ['nama' => $l->nama, 'sel_kanonik' => (string) $l->sel])
                    ->all();

                $hasil = $this->pemeriksa->periksa($peta, (string) $w->nama, $lembar, $isiDraf, (string) $w->sumber);
                $baris['varian'] = $hasil['varian'];

                if ($hasil['varian'] !== null && $hasil['varian'] === ($sumber['varian'] ?? null)) {
                    // Varian yang sama dengan sumber draf (mis. csv & xlsm satu varian) bukan "varian lain".
                    continue;
                }

                $baris['beda'] = array_map(
                    static fn (array $b): array => ['jalur' => $b['jalur'], 'master' => $b['lama'], 'draf' => $b['baru']],
                    self::beda($jalur, $hasil['isi'], $isiDraf),
                );
            } catch (Throwable $e) {
                $baris['galat'] = mb_substr($e->getMessage(), 0, 500);
            }

            $masterLain[] = $baris;
        }

        return [
            'berlaku' => true,
            'varian_draf' => $sumber['varian'] ?? null,
            'berubah' => $berubah,
            'master_lain' => $masterLain,
        ];
    }

    /**
     * Daun jalur bersama yang beda antara dua isi. Peta (objek) diturunkan per
     * kunci: `balok_ukur/19.0`.
     *
     * @param  list<string>  $jalur
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     * @return list<array{jalur: string, lama: ?string, baru: ?string}>
     */
    private static function beda(array $jalur, array $a, array $b): array
    {
        $hasil = [];

        foreach ($jalur as $j) {
            $na = self::ambil($a, $j);
            $nb = self::ambil($b, $j);

            if (is_array($na) && is_array($nb) && ! array_is_list($na) && ! array_is_list($nb)) {
                foreach (array_keys($na + $nb) as $k) {
                    $la = array_key_exists($k, $na) ? JsonKanonik::serialisasi($na[$k]) : null;
                    $lb = array_key_exists($k, $nb) ? JsonKanonik::serialisasi($nb[$k]) : null;

                    if ($la !== $lb) {
                        $hasil[] = ['jalur' => $j.'/'.$k, 'lama' => $la, 'baru' => $lb];
                    }
                }

                continue;
            }

            $la = $na === null ? null : JsonKanonik::serialisasi($na);
            $lb = $nb === null ? null : JsonKanonik::serialisasi($nb);

            if ($la !== $lb) {
                $hasil[] = ['jalur' => $j, 'lama' => $la, 'baru' => $lb];
            }
        }

        return $hasil;
    }

    /** @param  array<array-key, mixed>  $isi */
    private static function ambil(array $isi, string $jalur): mixed
    {
        try {
            return JalurIsi::ambil($isi, $jalur);
        } catch (RuntimeException) {
            return null;
        }
    }
}
