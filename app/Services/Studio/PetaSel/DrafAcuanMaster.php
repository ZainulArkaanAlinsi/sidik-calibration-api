<?php

namespace App\Services\Studio\PetaSel;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\WorkbookMaster;
use App\Services\Acuan\AlurVersiAcuan;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Support\JsonKanonik;
use RuntimeException;

/**
 * Master Studio yang lolos [PemeriksaMaster] → DRAF `paket_acuan_versi`
 * (rencana studio-kendali-olah-data fase 1, 22 Okt 2026).
 *
 * ## Dasar
 *
 * Isi draf = isi DASAR dengan jalur yang dipetakan ditimpa nilai master.
 * Dasar = versi data yang BERLAKU hari ini untuk organisasi & profil itu
 * (`PenentuVersiAcuan::untukProfil`), atau berkas JSON repo bila belum ada.
 * Jalur yang tidak dipetakan (nomor formulir, ambang pita, konstanta yang
 * hidup sebagai angka telanjang di rumus) dibawa apa adanya dari dasar dan
 * disebut di `rujukan` — tidak pernah dikarang.
 *
 * ## Yang TIDAK dilakukan kelas ini
 *
 * - Tidak pernah membuat versi `aktif`. Draf hanya bisa menghitung sesi
 *   sesudah alur versi (simulasi → pengesahan orang kedua → `berlaku_mulai`)
 *   — `PenentuVersiAcuan` tidak pernah memulangkan `draf`.
 * - Tidak membuat paket. Versi 1 lahir dari `acuan:impor-awal` (JSON repo
 *   yang terbukti setara master); tanpa paket, impor cuma melaporkan apakah
 *   ekstraksinya sama dengan JSON itu.
 * - Isi sama dengan dasar atau dengan draf yang sudah ada → tidak ada draf
 *   baru (dilaporkan).
 */
final class DrafAcuanMaster
{
    public function __construct(private readonly PenentuVersiAcuan $penentu) {}

    /**
     * @return array{isi: array<array-key, mixed>, sha256: string, versi: ?PaketAcuanVersi, paket: ?PaketAcuan, label: string}
     */
    public function dasar(int $organisasiId, PetaSel $peta): array
    {
        $paket = PaketAcuan::query()
            ->where('organization_id', $organisasiId)
            ->where('kode_profil', $peta->kodeProfil)
            ->first();

        $versi = $paket === null ? null : $this->penentu->untukProfil($organisasiId, $peta->kodeProfil, now());

        if ($versi !== null) {
            return [
                'isi' => $versi->isiTerurai(),
                'sha256' => PenentuVersiAcuan::sha256($versi),
                'versi' => $versi,
                'paket' => $paket,
                'label' => 'versi berlaku '.PenentuVersiAcuan::label($versi),
            ];
        }

        $berkas = database_path('data/'.$peta->isi['sumber_json']);

        if (! is_file($berkas)) {
            throw new RuntimeException("Berkas dasar {$berkas} tidak ada.");
        }

        $kanonik = JsonKanonik::dariTeks((string) file_get_contents($berkas));

        return [
            'isi' => (array) json_decode($kanonik, true, 512, JSON_THROW_ON_ERROR),
            'sha256' => JsonKanonik::sha256($kanonik),
            'versi' => null,
            'paket' => $paket,
            'label' => 'berkas database/data/'.$peta->isi['sumber_json'],
        ];
    }

    /**
     * Dipanggil di DALAM transaksi impor (gagal → baris workbook ikut batal).
     *
     * @param  array{varian: ?string, isi: array<array-key, mixed>, jalur: list<string>}  $hasil
     * @param  array{isi: array<array-key, mixed>, sha256: string, versi: ?PaketAcuanVersi, paket: ?PaketAcuan, label: string}  $dasar
     * @return array<string, mixed> ringkasan untuk respons impor
     */
    public function buat(WorkbookMaster $workbook, PetaSel $peta, array $hasil, array $dasar, ?int $userId): array
    {
        $kanonik = JsonKanonik::serialisasi($hasil['isi']);
        $sha = JsonKanonik::sha256($kanonik);
        $ringkasan = [
            'isi_sha256' => $sha,
            'varian' => $hasil['varian'],
            'dasar' => $dasar['label'],
            'sama_dengan_dasar' => hash_equals($dasar['sha256'], $sha),
            'versi_id' => null,
        ];

        if ($dasar['paket'] === null) {
            return ['status' => 'paket_belum_ada'] + $ringkasan;
        }

        if ($ringkasan['sama_dengan_dasar']) {
            return ['status' => 'sama_dengan_dasar', 'versi_id' => $dasar['versi']?->id] + $ringkasan;
        }

        /** @var PaketAcuan $paket */
        $paket = $dasar['paket'];

        $drafSama = $paket->versi()
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->where('status', PaketAcuanVersi::STATUS_DRAF)
            ->get()
            ->first(static fn (PaketAcuanVersi $v): bool => hash_equals(PenentuVersiAcuan::sha256($v), $sha));

        if ($drafSama !== null) {
            return ['status' => 'draf_sudah_ada', 'versi_id' => $drafSama->id] + $ringkasan;
        }

        $nomor = 1 + (int) $paket->versi()->where('jenis', PaketAcuanVersi::JENIS_DATA)->lockForUpdate()->max('nomor_versi');
        $tidakDipetakan = 'jalur yang tidak dipetakan dibawa dari dasar';

        $versi = PaketAcuanVersi::create([
            'paket_acuan_id' => $paket->id,
            'organization_id' => $paket->organization_id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => $nomor,
            'status' => PaketAcuanVersi::STATUS_DRAF,
            'isi' => $kanonik,
            // isi_sha256 dikunci saat DIAJUKAN (03-SDD §2.2), bukan di draf.
            'skema_versi' => $paket->skema_versi,
            'dasar_versi_id' => $dasar['versi']?->id,
            'alasan' => "Diekstrak dari master Studio #{$workbook->id} ({$workbook->nama}) lewat peta sel {$peta->kodeProfil}.",
            'rujukan' => [
                ['jenis' => 'workbook_master', 'nilai' => '#'.$workbook->id.' '.$workbook->nama, 'catatan' => 'sha256 '.$workbook->sha256.' ('.$workbook->sumber.')'],
                ['jenis' => 'peta_sel', 'nilai' => 'database/data/peta-sel/'.$peta->kodeProfil.'.json', 'catatan' => 'sha256 '.$peta->sha256.($hasil['varian'] !== null ? '; varian '.$hasil['varian'] : '')],
                ['jenis' => 'dasar', 'nilai' => $dasar['label'], 'catatan' => $tidakDipetakan.' (sha256 dasar '.$dasar['sha256'].'); isi draf sha256 '.$sha],
            ],
            'dibuat_oleh' => $userId,
        ]);

        $berubah = self::selisih($dasar['isi'], $hasil['isi']);

        foreach ($berubah as $jalur => [$lama, $baru]) {
            $segmen = explode('/', $jalur);
            $kolom = (string) array_pop($segmen);
            $lembar = (string) (array_shift($segmen) ?? $kolom);

            PaketAcuanSuntingan::create([
                'paket_acuan_versi_id' => $versi->id,
                'lembar' => mb_substr($lembar, 0, 64),
                'kunci_baris' => $segmen === [] ? null : mb_substr(implode('/', $segmen), 0, 128),
                'kolom' => mb_substr($kolom, 0, 64),
                'nilai_lama' => $lama,
                'nilai_baru' => $baru,
                'sumber' => PaketAcuanSuntingan::SUMBER_IMPOR,
                'alasan' => "Master Studio #{$workbook->id}",
                'user_id' => $userId,
            ]);
        }

        // Log metode: sumber draf (master id + sha256) — disalin ke tiap
        // transisi berikutnya oleh `AlurVersiAcuan`.
        AlurVersiAcuan::catat($versi, PaketAcuanLog::AKSI_DIBUAT, null, PaketAcuanVersi::STATUS_DRAF, $userId,
            "Diekstrak dari master Studio #{$workbook->id}.",
            sumber: [
                'workbook_master_id' => $workbook->id,
                'workbook_nama' => $workbook->nama,
                'workbook_sha256' => $workbook->sha256,
                'workbook_sumber' => $workbook->sumber,
                'peta_sel' => $peta->kodeProfil,
                'peta_sel_sha256' => $peta->sha256,
                'varian' => $hasil['varian'],
                'dasar' => $dasar['label'],
                'dasar_sha256' => $dasar['sha256'],
            ],
            rincian: ['sel_berubah' => count($berubah)],
        );

        return ['status' => 'draf_dibuat', 'versi_id' => $versi->id, 'nomor_versi' => $nomor, 'sel_berubah' => count($berubah)] + $ringkasan;
    }

    /**
     * Daun yang beda antara dua isi: jalur => [lama, baru] (JSON kanonik
     * daunnya, null bila tidak ada). Jalur dipisah `/` (kunci balok ukur
     * `19.0` sendiri bertitik), indeks list `[n]`: `cmc[1]/titik[0]/tumpukan_mm[1]`.
     *
     * @param  array<array-key, mixed>  $lama
     * @param  array<array-key, mixed>  $baru
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function selisih(array $lama, array $baru): array
    {
        $a = self::ratakan($lama);
        $b = self::ratakan($baru);
        $hasil = [];

        foreach (array_keys($a + $b) as $jalur) {
            if (($a[$jalur] ?? null) !== ($b[$jalur] ?? null)) {
                $hasil[(string) $jalur] = [$a[$jalur] ?? null, $b[$jalur] ?? null];
            }
        }

        return $hasil;
    }

    /**
     * @param  array<array-key, mixed>  $isi
     * @return array<string, string>
     */
    private static function ratakan(array $isi, string $awalan = ''): array
    {
        $hasil = [];

        foreach ($isi as $k => $v) {
            $jalur = $awalan === '' ? (string) $k : (array_is_list($isi) ? "{$awalan}[{$k}]" : "{$awalan}/{$k}");

            if (is_array($v) && $v !== []) {
                $hasil += self::ratakan($v, $jalur);
            } else {
                $hasil[$jalur] = JsonKanonik::serialisasi($v);
            }
        }

        return $hasil;
    }
}
