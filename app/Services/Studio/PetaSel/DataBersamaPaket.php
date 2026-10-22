<?php

namespace App\Services\Studio\PetaSel;

use App\Models\PaketAcuanVersi;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\JsonKanonik;
use RuntimeException;
use Throwable;

/**
 * Data milik PAKET LAIN yang ikut dibawa sebuah master (keputusan
 * orkestrator 22 Okt 2026): master Dial Indicator membawa balok ukur
 * Micrometer (`Standar_GB`), master Jangka Sorong membawa Caliper Checker
 * Height Gauge (`Std_CaliperCek`). Satu set fisik, satu sertifikat — generator
 * lama (`gen-tabel-standar-dial-indicator.py`, `...-jangka-sorong.py`) MENOLAK
 * menulis kalau keduanya beda.
 *
 * Peta menyebutnya di `paket_lain[]`. Kelas ini mengekstraknya dari master
 * dan mengadunya ke versi yang BERLAKU hari ini untuk paket lain itu (atau
 * berkas JSON-nya bila paket belum diimpor). Tidak pernah menulis ke paket
 * lain. Beda = `bersama_antar_paket`: dicatat di hasil impor dan catatan
 * master, dan simulasi draf menjadikannya butir yang WAJIB diakui saat
 * pengesahan (`AlurVersiAcuan::AKUI_BERSAMA_PAKET`) — tidak pernah diam.
 */
final class DataBersamaPaket
{
    public function __construct(
        private readonly PemeriksaMaster $pemeriksa,
        private readonly PenentuVersiAcuan $penentu,
        private readonly CalibrationProfileRegistry $registry,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lembar  keluaran pembaca master (sudah lolos [PemeriksaMaster::periksa])
     * @return list<array{paket: string, dasar: string, jalur: string, acuan: ?string, master: ?string}>
     */
    public function periksa(PetaSel $peta, array $lembar, int $organisasiId, string $format = PemeriksaMaster::FORMAT_CSV): array
    {
        $beda = [];

        foreach ($peta->isi['paket_lain'] ?? [] as $pl) {
            $kode = (string) $pl['paket'];
            [$dasar, $label] = $this->dasar($organisasiId, $kode);
            $hasil = $this->pemeriksa->ekstrakLain($peta, $lembar, $pl['ekstrak'], $dasar, $format);

            foreach (array_values(array_unique($hasil['jalur'])) as $jalur) {
                foreach (self::bandingJalur($dasar, $hasil['isi'], $jalur) as $b) {
                    $beda[] = ['paket' => $kode, 'dasar' => $label] + $b;
                }
            }
        }

        return $beda;
    }

    /**
     * [periksa] atas master Studio sumber sebuah draf (id dari log `dibuat`).
     * Master yang hilang/gagal dibaca dilaporkan sebagai satu baris `galat`,
     * bukan dianggap sepakat.
     *
     * @return list<array<string, mixed>>
     */
    public function periksaSumberDraf(PaketAcuanVersi $draf, ?int $workbookMasterId): array
    {
        $peta = PetaSel::untuk((string) $draf->paketAcuan?->kode_profil);

        if ($peta === null || ($peta->isi['paket_lain'] ?? []) === [] || $workbookMasterId === null) {
            return [];
        }

        try {
            $w = WorkbookMaster::query()->findOrFail($workbookMasterId);
            $lembar = WorkbookMasterLembar::query()
                ->where('workbook_master_id', $w->id)
                ->get()
                ->map(static fn (WorkbookMasterLembar $l): array => ['nama' => $l->nama, 'sel_kanonik' => (string) $l->sel])
                ->all();

            return $this->periksa($peta, $lembar, (int) $draf->organization_id, (string) $w->sumber);
        } catch (Throwable $e) {
            return [['paket' => '*', 'dasar' => '-', 'jalur' => '-', 'acuan' => null, 'master' => null, 'galat' => mb_substr($e->getMessage(), 0, 500)]];
        }
    }

    /**
     * Isi paket lain yang berlaku hari ini, atau berkas JSON-nya.
     *
     * @return array{0: array<array-key, mixed>, 1: string}
     */
    private function dasar(int $organisasiId, string $kode): array
    {
        $versi = $this->penentu->untukProfil($organisasiId, $kode, now());

        if ($versi !== null) {
            return [$versi->isiTerurai(), 'versi berlaku '.PenentuVersiAcuan::label($versi)];
        }

        $skema = $this->registry->untukPaketAcuan($kode)?->skemaAcuan()
            ?? throw new RuntimeException("Paket lain '{$kode}' tidak punya skemaAcuan().");
        $berkas = database_path('data/'.$skema['sumber_json']);

        return [
            (array) json_decode(JsonKanonik::dariTeks((string) file_get_contents($berkas)), true, 512, JSON_THROW_ON_ERROR),
            'berkas database/data/'.$skema['sumber_json'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $a
     * @param  array<array-key, mixed>  $b
     * @return list<array{jalur: string, acuan: ?string, master: ?string}>
     */
    private static function bandingJalur(array $a, array $b, string $jalur): array
    {
        $na = self::ambil($a, $jalur);
        $nb = self::ambil($b, $jalur);

        if (is_array($na) && is_array($nb)) {
            $hasil = [];

            foreach (DrafAcuanMaster::selisih($na, $nb) as $sub => [$lama, $baru]) {
                $hasil[] = ['jalur' => $jalur.'/'.$sub, 'acuan' => $lama, 'master' => $baru];
            }

            return $hasil;
        }

        $la = $na === null ? null : JsonKanonik::serialisasi($na);
        $lb = $nb === null ? null : JsonKanonik::serialisasi($nb);

        return $la === $lb ? [] : [['jalur' => $jalur, 'acuan' => $la, 'master' => $lb]];
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
