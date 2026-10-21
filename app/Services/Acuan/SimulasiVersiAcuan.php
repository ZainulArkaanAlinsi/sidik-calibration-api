<?php

namespace App\Services\Acuan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\PaketAcuanVersi;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\CertificateSnapshotBuilder;
use App\Support\SiapHitungSesi;
use App\Support\TataLetakSertifikat;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Simulasi versi data acuan terhadap SEMUA sesi tersimpan profil itu di
 * organisasi itu (AGENTS.md §Olah data butir 3; 02-SRS FR-12) — tanpa menulis
 * apa pun ke sesi.
 *
 * Per sesi, dua hitungan lewat jalur hitung ulang yang SAMA
 * (`SiapHitungSesi` → `hitungPerGrup()` profil, pola `kalibrasi:hitung-ulang`):
 *
 *  - **lama** = versi yang menghitung sesi itu hari ini
 *    (`PenentuVersiAcuan::untukSesiTersimpan`: stempel, atau versi berlaku pada
 *    tanggal sesi);
 *  - **baru** = versi yang disimulasikan (draf/diajukan).
 *
 * Yang dilaporkan: nilai antara yang berubah (kolom, lama, baru, selisih —
 * presisi penuh, tidak dibulatkan), angka CETAK yang berubah sesudah aturan
 * desimal sertifikat (`CertificateSnapshotBuilder::potonganCetak` +
 * `TataLetakSertifikat::teksSel`, aturan yang sama dengan PDF), keputusan
 * PASS/FAIL yang berubah, dan apakah sesi itu sudah punya sertifikat TERBIT.
 *
 * Simulasi selalu berjalan dalam mode sumber `db` (dipulihkan sesudahnya):
 * versi tidak bisa disimulasikan tanpa lingkup versi. Bila sumber aktif bukan
 * `db`, ringkasan menyebutnya — versi yang disahkan belum menghitung apa pun
 * sampai `DATA_ACUAN_SUMBER=db`.
 */
final class SimulasiVersiAcuan
{
    /** Kolom angka cetak tabel hasil yang dibandingkan. */
    private const KOLOM_CETAK = ['standar', 'uut', 'koreksi', 'u95'];

    /** Kunci hasil hitung yang bukan angka hasil (waktu, id). */
    private const KUNCI_DILEWAT = ['calculated_at', 'created_at', 'updated_at', 'id', 'calibration_session_id'];

    public function __construct(
        private readonly PenentuVersiAcuan $penentu,
        private readonly CalibrationProfileRegistry $registry,
        private readonly CertificateSnapshotBuilder $snapshot,
    ) {}

    /**
     * @return array{ringkasan: array<string, mixed>, sesi: list<array<string, mixed>>}
     */
    public function jalankan(PaketAcuanVersi $versi): array
    {
        $versi->loadMissing('paketAcuan');
        $kode = (string) $versi->paketAcuan?->kode_profil;
        $sumberAsli = config('acuan.sumber');

        config(['acuan.sumber' => 'db']);

        try {
            $laporan = [];

            foreach ($this->sesiProfil((int) $versi->organization_id, $kode) as $sesi) {
                $laporan[] = $this->simulasiSesi($sesi, $versi);
            }
        } finally {
            config(['acuan.sumber' => $sumberAsli]);
        }

        $berubah = array_filter($laporan, static fn (array $s): bool => $s['berubah']);
        $cetak = array_filter($laporan, static fn (array $s): bool => $s['cetak_berubah']);

        return [
            'ringkasan' => [
                'sesi_total' => count($laporan),
                'sesi_berubah' => count($berubah),
                'cetak_berubah' => count($cetak),
                'terbit_cetak_berubah' => count(array_filter($cetak, static fn (array $s): bool => $s['sudah_terbit'])),
                'vonis_berubah' => count(array_filter($laporan, static fn (array $s): bool => $s['vonis_berubah'])),
                'sesi_gagal' => count(array_filter($laporan, static fn (array $s): bool => $s['galat'] !== null)),
                'sumber_acuan_aktif' => $sumberAsli,
                'catatan_sumber' => $sumberAsli === 'db'
                    ? null
                    : 'DATA_ACUAN_SUMBER bukan db: simulasi dihitung dalam mode db, tetapi jalur hitung produksi '
                        .'belum membaca versi DB — versi yang disahkan belum menghitung sesi apa pun sampai sumbernya dipindah.',
            ],
            'sesi' => array_values($laporan),
        ];
    }

    /**
     * Sesi organisasi ini yang alatnya dipetakan ke profil `$kode`.
     *
     * @return Collection<int, CalibrationSession>
     */
    private function sesiProfil(int $organisasiId, string $kode): Collection
    {
        $alat = Equipment::query()
            ->where('organization_id', $organisasiId)
            ->get()
            ->filter(fn (Equipment $e): bool => $this->registry->untukAlat($e)->kode() === $kode)
            ->pluck('id')
            ->all();

        if ($alat === []) {
            return collect();
        }

        return CalibrationSession::query()
            ->where('organization_id', $organisasiId)
            ->whereIn('equipment_id', $alat)
            ->with(['equipment', 'organization', 'rawMeasurements.standard', 'uncertaintyCalculations'])
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, mixed> */
    private function simulasiSesi(CalibrationSession $sesi, PaketAcuanVersi $versi): array
    {
        $terbit = Certificate::query()
            ->where('calibration_session_id', $sesi->id)
            ->where('status', Certificate::STATUS_TERBIT)
            ->orderBy('id')
            ->pluck('nomor')
            ->all();

        $baris = [
            'sesi_id' => $sesi->id,
            'nomor_sesi' => $sesi->nomor_sesi,
            'status_sesi' => $sesi->status,
            'tanggal_kalibrasi' => $sesi->tanggal_kalibrasi?->toDateString(),
            'sudah_terbit' => $terbit !== [],
            'nomor_sertifikat_terbit' => $terbit,
            'versi_pembanding' => null,
            'berubah' => false,
            'cetak_berubah' => false,
            'vonis_berubah' => false,
            'selisih' => [],
            'cetak' => [],
            'titik_tidak_terhitung' => [],
            'galat' => null,
        ];

        try {
            $alat = $sesi->equipment;
            $profil = $this->registry->untukAlat($alat);
            $siap = SiapHitungSesi::dari($sesi);

            if ($siap === []) {
                $baris['galat'] = 'tidak ada titik yang bisa dihitung dari pembacaan mentah';

                return $baris;
            }

            $pilihan = $this->penentu->untukSesiTersimpan($sesi);
            $baris['versi_pembanding'] = $pilihan['versi'] === null ? 'berkas JSON' : PenentuVersiAcuan::label($pilihan['versi']);

            $lama = $this->penentu->jalankanDengan($pilihan['versi'], fn (): ?array => $profil->hitungPerGrup($siap, $alat))['hasil'];
            $baru = $this->penentu->jalankanDengan($versi, fn (): ?array => $profil->hitungPerGrup($siap, $alat))['hasil'];

            if ($lama === null || $baru === null) {
                $baris['galat'] = 'profil alat ini menghitung per titik; simulasi baru mendukung profil ber-hitungPerGrup()';

                return $baris;
            }

            $hLama = collect($lama['hitungan'] ?? [])->keyBy(static fn (array $h): int => (int) $h['titik_ke']);
            $hBaru = collect($baru['hitungan'] ?? [])->keyBy(static fn (array $h): int => (int) $h['titik_ke']);

            foreach ($hLama->keys()->merge($hBaru->keys())->unique()->sort()->values() as $ke) {
                $a = $hLama->get($ke);
                $b = $hBaru->get($ke);

                if ($a === null || $b === null) {
                    $baris['titik_tidak_terhitung'][] = ['titik' => $ke, 'lama' => $a !== null, 'baru' => $b !== null];
                    $baris['berubah'] = true;

                    continue;
                }

                foreach (self::bandingkan(self::ratakan($a), self::ratakan($b)) as $s) {
                    $baris['selisih'][] = ['titik' => $ke, ...$s];
                }

                if (($a['keputusan'] ?? null) !== ($b['keputusan'] ?? null)) {
                    $baris['vonis_berubah'] = true;
                }
            }

            $baris['berubah'] = $baris['berubah'] || $baris['selisih'] !== [];

            if ($baris['berubah']) {
                $baris['cetak'] = $this->bedaCetak($sesi, $hLama->values()->all(), $hBaru->values()->all());
                $baris['cetak_berubah'] = $baris['cetak'] !== [] || $baris['vonis_berubah'];
            }
        } catch (Throwable $e) {
            $baris['galat'] = mb_substr($e->getMessage(), 0, 500);
        }

        return $baris;
    }

    /**
     * Teks cetak per titik & kolom yang beda antara hasil lama dan baru.
     *
     * @param  list<array<string, mixed>>  $lama
     * @param  list<array<string, mixed>>  $baru
     * @return list<array{titik: int, kolom: string, lama: ?string, baru: ?string}>
     */
    private function bedaCetak(CalibrationSession $sesi, array $lama, array $baru): array
    {
        $tLama = $this->teksCetak($sesi, $lama);
        $tBaru = $this->teksCetak($sesi, $baru);
        $hasil = [];

        foreach (array_keys($tLama + $tBaru) as $ke) {
            foreach (array_keys(($tLama[$ke] ?? []) + ($tBaru[$ke] ?? [])) as $kolom) {
                $a = $tLama[$ke][$kolom] ?? null;
                $b = $tBaru[$ke][$kolom] ?? null;

                if ($a !== $b) {
                    $hasil[] = ['titik' => (int) $ke, 'kolom' => (string) $kolom, 'lama' => $a, 'baru' => $b];
                }
            }
        }

        return $hasil;
    }

    /**
     * @param  list<array<string, mixed>>  $hitungan
     * @return array<int, array<string, string>> titik_ke => kolom => teks cetak
     */
    private function teksCetak(CalibrationSession $sesi, array $hitungan): array
    {
        $salinan = clone $sesi;
        $salinan->setRelation('uncertaintyCalculations', new \Illuminate\Database\Eloquent\Collection(array_map(
            static fn (array $h): UncertaintyCalculation => (new UncertaintyCalculation)->forceFill([
                ...array_diff_key($h, array_flip(self::KUNCI_DILEWAT)),
                'calibration_session_id' => $sesi->id,
            ]),
            $hitungan,
        )));

        $potongan = $this->snapshot->potonganCetak($salinan);
        $tl = TataLetakSertifikat::untuk($potongan);
        $hasil = [];

        foreach ($potongan['hasil'] as $b) {
            if (($b['dicetak'] ?? true) === false) {
                continue;
            }

            $ke = (int) $b['titik_ke'];

            foreach (self::KOLOM_CETAK as $kolom) {
                $hasil[$ke][$kolom] = TataLetakSertifikat::teksSel($b, $kolom, $potongan, (int) $potongan['desimal']);
            }

            if (($b['faktor_cakupan_k'] ?? null) !== null) {
                $hasil[$ke]['k'] = TataLetakSertifikat::teksK($b, (float) $b['faktor_cakupan_k'], $tl);
            }
        }

        return $hasil;
    }

    /**
     * Daun hasil hitung satu titik: `error`, `type_b_components/<nama>/u`.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function ratakan(array $data, string $awalan = ''): array
    {
        $hasil = [];

        foreach ($data as $k => $v) {
            if ($awalan === '' && in_array($k, self::KUNCI_DILEWAT, true)) {
                continue;
            }

            $segmen = (string) $k;

            if (is_array($v) && array_is_list($data) && (isset($v['kode']) || isset($v['nama']))) {
                $segmen = (string) ($v['kode'] ?? $v['nama']);
            }

            $jalur = $awalan === '' ? $segmen : $awalan.'/'.$segmen;

            if (is_array($v) && $v !== []) {
                $hasil += self::ratakan($v, $jalur);
            } else {
                $hasil[$jalur] = $v;
            }
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return list<array{kolom: string, lama: mixed, baru: mixed, selisih: ?float}>
     */
    private static function bandingkan(array $a, array $b): array
    {
        $hasil = [];

        foreach (array_keys($a + $b) as $kolom) {
            $x = $a[$kolom] ?? null;
            $y = $b[$kolom] ?? null;
            $angka = is_int($x) || is_float($x) ? (is_int($y) || is_float($y)) : false;

            $sama = $angka ? (float) $x === (float) $y : $x === $y;

            if (! $sama) {
                $hasil[] = [
                    'kolom' => (string) $kolom,
                    'lama' => $x,
                    'baru' => $y,
                    'selisih' => $angka ? (float) $y - (float) $x : null,
                ];
            }
        }

        return $hasil;
    }
}
