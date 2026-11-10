<?php

namespace App\Services\Studio\Draf;

use App\Models\PaketAcuan;
use App\Models\PaketAcuanLog;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\WorkbookMaster;
use App\Services\Acuan\AlurVersiAcuan;
use App\Services\Acuan\PenentuVersiAcuan;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Studio\PetaSel\DrafAcuanMaster;
use App\Services\Studio\PetaSel\JalurIsi;
use App\Services\Studio\PetaSel\PetaSel;
use App\Services\Studio\Workbook\PetaProfilWorkbook;
use App\Services\Studio\WorkbookScalarEditPreparer;
use App\Support\JsonKanonik;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Simpan/muat ulang DRAF data acuan dari Studio (rencana A1). Metode:
 *  - [buat] `POST .../draf` (B3), [perbarui] `PUT .../draf/{versi}` (B4): tulis;
 *  - [muat] `GET .../draf/{versi}` dan [dasar] `GET .../draf-dasar` (B4): baca saja.
 *
 * ## Workbook tidak aktif (keputusan produk B4)
 *
 * Tulis (POST/PUT) dan `dasar` membaca ULANG workbook di dalam transaksi:
 * diarsipkan = 404; `aktif` false (riwayat hasil `--ganti`) = 422
 * `workbook_tidak_aktif` -- fail-closed, karena hash riwayat tidak lagi
 * menggambarkan master yang dipakai Studio. Membaca satu draf ([muat]) tidak
 * menuntut workbook aktif.
 *
 * ## Respons 409
 *
 * `workbook_berubah`, `peta_berubah`, `dasar_berubah`, `draf_berubah` membawa
 * `rincian.hash_terbaru` ({workbook, peta, dasar} untuk POST; {workbook, peta,
 * isi} untuk PUT) supaya klien memuat ulang tanpa menebak. Hanya hash -- tidak
 * ada isi lain.
 *
 * ## `POST .../draf` (B3)
 *
 * Buat DRAF data
 * acuan baru dari DASAR (versi berlaku, atau berkas JSON repo) + suntingan
 * angka scalar. SATU transaksi; setiap penolakan ([DrafAcuanDitolak]) membatalkan
 * seluruh tulis. Tidak menyentuh versi lain, master, sesi, atau sertifikat.
 *
 * ## Urutan (sengaja)
 *
 *  1. peta sel dipilih seperti impor (`PetaProfilWorkbook::kodePaket` +
 *     `tidakBerlakuUntuk`), BUKAN dari `$workbook->kode_profil` mentah;
 *  2. cek hash yang dilihat klien: `workbook_sha256`, `peta_sel_sha256`;
 *  3. kunci baris `paket_acuan` (seperti `AlurVersiAcuan::kunci()`: paket dulu,
 *     lalu versi, supaya tak deadlock dengan ajukan/sahkan). Paket TIDAK dibuat
 *     di sini -- tanpa paket (versi 1 lahir dari `acuan:impor-awal`) 422;
 *  4. `DrafAcuanMaster::dasar()` dihitung ULANG di dalam kunci, lalu adu
 *     `base_isi_sha256` (CAS);
 *  5. isi dasar harus STABIL kanonik (lihat di bawah);
 *  6. pemetaan sel -> jalur dari peta server, disaring ke daun angka yang ADA di
 *     dasar; `WorkbookScalarEditPreparer::prepare()`;
 *  7. nol perubahan ditolak; draf dengan isi persis sama -> `draf_sudah_ada`;
 *  8..10. versi, baris suntingan, log `dibuat`;
 *  11. proyeksi dihitung DI DALAM transaksi dari isi yang dibaca ulang dari DB.
 *
 * ## `workbook_sha256` = `WorkbookMaster::sha256`
 *
 * Itu yang dilihat klien di `WorkbookMasterResource` (`sha256`), dan yang
 * dicatat impor di `rujukan`/log. Bukan `sha256_hidup`, yang NULL untuk riwayat
 * hasil `--ganti` dan tidak pernah dikirim ke klien.
 *
 * ## Stabilitas kanonik isi dasar (D3, `isi_tidak_stabil`)
 *
 * Draf disimpan sebagai teks `JsonKanonik::serialisasi($isi)` dari array hasil
 * overlay, tetapi array PHP tidak membedakan `{}` dari `[]` maupun objek
 * berkunci "0","1",.. dari list, dan bilangan bulat di luar int64 menjadi
 * float lewat `json_decode`. Karena itu dasar hanya dipakai bila
 * `serialisasi(json_decode(teks, true)) === teks` (versi: teks tersimpan;
 * berkas: kanonik `dariTeks`, dibandingkan lewat sha256). Selisih sekecil apa
 * pun = 409 tanpa tulis: daun yang TIDAK disunting tidak boleh bergeser
 * diam-diam. Int di luar int64 -> float -> teks beda -> tertangkap pemeriksaan
 * ini juga.
 *
 * ## Proyeksi gagal = 500, bukan 422
 *
 * Pemetaan sudah disaring ke daun yang ada, jadi `UnexpectedValueException`
 * dari [ProyeksiDraf] berarti ketidakkonsistenan sisi server (bukan salah
 * klien). Ditangkap DI DALAM transaksi -> rollback total, klien menerima 500
 * `proyeksi_gagal` dan tidak ada draf yang "tersimpan tapi dilaporkan gagal".
 */
class SuntinganDrafService
{
    /** Penanda `sumber_ui` di log `dibuat` dan `sunting`. */
    public const SUMBER_UI = 'studio';

    /** Batas daftar draf di respons `draf-dasar` (terbaru dulu). */
    public const MAKS_DAFTAR_DRAF = 20;

    public function __construct(
        private readonly DrafAcuanMaster $draf,
        private readonly WorkbookScalarEditPreparer $preparer,
        private readonly CalibrationProfileRegistry $registry,
    ) {}

    /**
     * @param  array{workbook_sha256: string, peta_sel_sha256: string, base_isi_sha256: string, alasan: string, suntingan: list<array{sel: string, nilai: string}>}  $data
     * @return array{baru: bool, data: array{status: string, versi: array<string, mixed>, hash: array<string, string>, suntingan: list<array{sel: string, jalur: string, nilai: string}>}}
     *
     * @throws DrafAcuanDitolak
     */
    public function buat(WorkbookMaster $workbook, int $userId, array $data): array
    {
        try {
            return DB::transaction(fn (): array => $this->dalamTransaksi($workbook, $userId, $data));
        } catch (UniqueConstraintViolationException) {
            // Penyimpan lain mengambil nomor_versi yang sama (di luar kunci paket).
            throw new DrafAcuanDitolak('draf_berubah', 'Draf lain baru saja dibuat untuk paket ini. Muat ulang lalu simpan lagi.', ['hash_terbaru' => $this->hashTerbaruSetelahGagal($workbook)], 409);
        }
    }

    /**
     * Pecah jalur ratakan `DrafAcuanMaster::selisih()` jadi kolom
     * `paket_acuan_suntingan`. SAMA dengan konvensi baris impor
     * (`DrafAcuanMaster::buat()`): kolom = segmen terakhir, lembar = segmen
     * pertama (atau kolom bila hanya satu), kunci_baris = sisanya; dipotong
     * 64/128/64 karakter. Duplikasi kecil dikunci tes kesetaraan.
     *
     * @return array{lembar: string, kunci_baris: ?string, kolom: string}
     */
    public static function pecahJalur(string $jalur): array
    {
        $segmen = explode('/', $jalur);
        $kolom = (string) array_pop($segmen);
        $lembar = (string) (array_shift($segmen) ?? $kolom);

        return [
            'lembar' => mb_substr($lembar, 0, 64),
            'kunci_baris' => $segmen === [] ? null : mb_substr(implode('/', $segmen), 0, 128),
            'kolom' => mb_substr($kolom, 0, 64),
        ];
    }

    /**
     * Seam tes (rollback bila proyeksi gagal); produksi memakai [ProyeksiDraf].
     *
     * @param  array<array-key, mixed>  $isi
     * @param  list<array{sel: string, jalur: string, tipe: string, bulat: int}>  $pemetaan
     * @return list<array{sel: string, jalur: string, nilai: string}>
     *
     * @throws UnexpectedValueException
     */
    protected function proyeksi(array $isi, array $pemetaan): array
    {
        return ProyeksiDraf::dari($isi, $pemetaan);
    }

    /** @param  array<string, mixed>  $data */
    private function dalamTransaksi(WorkbookMaster $dariRequest, int $userId, array $data): array
    {
        // 0: KUNCI paket PERTAMA -- pembacaan pertama di dalam transaksi harus pembacaan pengunci.
        // MySQL REPEATABLE READ membentuk snapshot pada SELECT konsisten pertama; kalau workbook
        // dibaca lebih dulu, dasar()/cek draf kembar/max nomor sesudah menunggu kunci akan membaca
        // snapshot LAMA (POST ganda -> dua draf identik; CAS dasar lolos terhadap dasar yang baru
        // diganti sahkan). Org + kode paket diambil dari model request dan peta (tanpa DB lain).
        // Paket TIDAK dibuat; tak ada paket = ditolak sesudah hash dicek (urutan galat B3).
        $petaAwal = $this->petaUntuk($dariRequest);
        $paket = $this->paketUntuk((int) $dariRequest->organization_id, $petaAwal, kunci: true);

        // 1: baca ULANG workbook di dalam transaksi (yang dari request dibaca di authorize(),
        // di luar transaksi): arsip/non-aktif/sha256 yang berubah sesudahnya tertangkap.
        $workbook = $this->segarkanWorkbook($dariRequest);
        $organisasiId = (int) $workbook->organization_id;

        // 2: peta + hash yang dilihat klien.
        $peta = $this->petaUntuk($workbook);

        if ($peta->kodeProfil !== $petaAwal->kodeProfil) {
            // Profil workbook bergeser di antara dua bacaan: paket yang terkunci bukan paket yang dimaksud.
            throw new DrafAcuanDitolak('workbook_berubah', 'Workbook master berubah sejak Anda membukanya. Muat ulang workbook lalu ulangi.', ['hash_terbaru' => $this->hashTerbaruBuat($workbook, $peta)], 409);
        }

        if (! hash_equals((string) $workbook->sha256, (string) $data['workbook_sha256'])) {
            throw new DrafAcuanDitolak('workbook_berubah', 'Workbook master berubah sejak Anda membukanya. Muat ulang workbook lalu ulangi.', ['hash_terbaru' => $this->hashTerbaruBuat($workbook, $peta)], 409);
        }

        if (! hash_equals($peta->sha256, (string) $data['peta_sel_sha256'])) {
            throw new DrafAcuanDitolak('peta_berubah', 'Peta sel server berubah sejak Anda membukanya. Muat ulang lalu ulangi.', ['hash_terbaru' => $this->hashTerbaruBuat($workbook, $peta)], 409);
        }

        // 3: paket (sudah terkunci di langkah 0) harus ada.
        if ($paket === null) {
            throw new DrafAcuanDitolak('paket_belum_ada', 'Paket data acuan profil ini belum ada. Versi 1 harus diimpor dulu (acuan:impor-awal); draf tidak dibuat.');
        }

        // 4: dasar dihitung ULANG di bawah kunci.
        $dasar = $this->draf->dasar($organisasiId, $peta);

        if (! hash_equals($dasar['sha256'], (string) $data['base_isi_sha256'])) {
            throw new DrafAcuanDitolak('dasar_berubah', 'Data acuan dasar berubah sejak Anda membukanya. Muat ulang lalu ulangi.', ['hash_terbaru' => ['workbook' => (string) $workbook->sha256, 'peta' => $peta->sha256, 'dasar' => $dasar['sha256']]], 409);
        }

        // 5: dasar harus stabil kanonik.
        $this->pastikanStabil($dasar);

        // 6: pemetaan tepercaya, disaring ke daun angka yang ada di dasar.
        $pemetaan = array_values(array_filter(
            PemetaanSuntingan::untukMaster($peta, $workbook),
            static fn (array $m): bool => self::daunAngkaHingga($dasar['isi'], $m['jalur']),
        ));
        $suntingan = array_values($data['suntingan']);

        $hasil = $this->terapkan($dasar['isi'], $pemetaan, $suntingan);

        // 7: nol perubahan / draf kembar.
        if ($hasil['perubahan'] === []) {
            throw new DrafAcuanDitolak('tidak_ada_perubahan', 'Nilai yang dikirim sama dengan isi dasar. Tidak ada yang perlu disimpan.');
        }

        $kanonik = JsonKanonik::serialisasi($hasil['isi']);
        $sha = JsonKanonik::sha256($kanonik);

        $kembar = PaketAcuanVersi::query()
            ->where('paket_acuan_id', $paket->id)
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->where('status', PaketAcuanVersi::STATUS_DRAF)
            ->get()
            ->first(static fn (PaketAcuanVersi $v): bool => hash_equals(PenentuVersiAcuan::sha256($v), $sha));

        if ($kembar !== null) {
            return ['baru' => false, 'data' => $this->respons('draf_sudah_ada', $kembar, $dasar, $workbook, $peta, $pemetaan)];
        }

        // 8: versi draf. isi_sha256 TETAP null (dikunci saat diajukan, 03-SDD §2.2).
        $varian = PemetaanSuntingan::varian($peta, (string) $workbook->nama);
        $nomor = 1 + (int) PaketAcuanVersi::query()
            ->where('paket_acuan_id', $paket->id)
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->lockForUpdate()
            ->max('nomor_versi');

        $versi = PaketAcuanVersi::create([
            'paket_acuan_id' => $paket->id,
            'organization_id' => $paket->organization_id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => $nomor,
            'status' => PaketAcuanVersi::STATUS_DRAF,
            'isi' => $kanonik,
            'skema_versi' => $paket->skema_versi,
            'dasar_versi_id' => $dasar['versi']?->id,
            'alasan' => $data['alasan'],
            'rujukan' => [
                ['jenis' => 'workbook_master', 'nilai' => '#'.$workbook->id.' '.$workbook->nama, 'catatan' => 'sha256 '.$workbook->sha256.' ('.$workbook->sumber.')'],
                ['jenis' => 'peta_sel', 'nilai' => 'database/data/peta-sel/'.$peta->kodeProfil.'.json', 'catatan' => 'sha256 '.$peta->sha256.($varian !== null ? '; varian '.$varian : '')],
                ['jenis' => 'dasar', 'nilai' => $dasar['label'], 'catatan' => 'suntingan angka dari Studio (sha256 dasar '.$dasar['sha256'].'); isi draf sha256 '.$sha],
            ],
            'dibuat_oleh' => $userId,
        ]);

        // 9: baris suntingan dari selisih isi (konvensi baris impor).
        foreach (DrafAcuanMaster::selisih($dasar['isi'], $hasil['isi']) as $jalur => [$lama, $baru]) {
            PaketAcuanSuntingan::create([
                'paket_acuan_versi_id' => $versi->id,
                ...self::pecahJalur($jalur),
                'nilai_lama' => $lama,
                'nilai_baru' => $baru,
                'sumber' => PaketAcuanSuntingan::SUMBER_KETIK,
                'alasan' => $data['alasan'],
                'user_id' => $userId,
            ]);
        }

        // 10: log metode.
        AlurVersiAcuan::catat($versi, PaketAcuanLog::AKSI_DIBUAT, null, PaketAcuanVersi::STATUS_DRAF, $userId, $data['alasan'],
            sumber: [
                'workbook_master_id' => $workbook->id,
                'workbook_sha256' => $workbook->sha256,
                'workbook_nama' => $workbook->nama,
                'workbook_sumber' => $workbook->sumber,
                'peta_sel' => $peta->kodeProfil,
                'peta_sel_sha256' => $peta->sha256,
                'varian' => $varian,
                'dasar' => $dasar['label'],
                'dasar_sha256' => $dasar['sha256'],
                'sumber_ui' => self::SUMBER_UI,
            ],
            rincian: [
                'sel_berubah' => count($hasil['perubahan']),
                'sel' => array_map(static fn (array $p): array => ['sel' => $p['sel'], 'jalur' => $p['jalur']], $hasil['perubahan']),
            ],
        );

        // 11: respons dari isi yang dibaca ULANG dari DB, masih di dalam transaksi.
        $tersimpan = PaketAcuanVersi::query()->findOrFail($versi->id);

        return ['baru' => true, 'data' => $this->respons('draf_dibuat', $tersimpan, $dasar, $workbook, $peta, $pemetaan)];
    }

    /**
     * `GET /api/studio/workbook/{workbook}/draf-dasar` -- semua yang klien butuh
     * SEBELUM draf pertama ada: hash `workbook`/`peta`/`dasar` (tiga hash yang
     * diminta POST), proyeksi sel yang bisa disunting dari DASAR, dan ringkasan
     * draf `data` berstatus draf pada paket itu (maks 20 terbaru). Tidak menulis
     * apa pun, tanpa kunci. Dasar yang tidak stabil kanonik (D3) dijawab 409
     * `isi_tidak_stabil`, sama dengan POST: hash yang tak bisa dipakai POST
     * tidak ditawarkan.
     *
     * `dasar_versi_id` = id versi berlaku yang menjadi dasar (null = berkas JSON repo).
     * `draf_total` = jumlah draf `data` di paket; `draf_terpotong` = daftar `draf`
     * memuat kurang dari total (maks [MAKS_DAFTAR_DRAF] terbaru).
     *
     * @return array{hash: array<string, string>, dasar_versi_id: ?int, suntingan: list<array{sel: string, jalur: string, nilai: string}>, draf: list<array<string, mixed>>, draf_total: int, draf_terpotong: bool}
     *
     * @throws DrafAcuanDitolak
     */
    public function dasar(WorkbookMaster $workbook): array
    {
        $workbook = $this->segarkanWorkbook($workbook);
        $organisasiId = (int) $workbook->organization_id;
        $peta = $this->petaUntuk($workbook);

        $paket = $this->paketUntuk($organisasiId, $peta);

        if ($paket === null) {
            throw new DrafAcuanDitolak('paket_belum_ada', 'Paket data acuan profil ini belum ada. Versi 1 harus diimpor dulu (acuan:impor-awal); draf tidak bisa dibuat.');
        }

        $dasar = $this->draf->dasar($organisasiId, $peta);
        $this->pastikanStabil($dasar);
        $pemetaan = $this->pemetaanTersaring($peta, $workbook, $dasar['isi']);

        try {
            $proyeksi = $this->proyeksi($dasar['isi'], $pemetaan);
        } catch (UnexpectedValueException) {
            throw new DrafAcuanDitolak('proyeksi_gagal', 'Dasar tidak bisa ditampilkan utuh. Hubungi admin.', status: 500);
        }

        $drafQuery = PaketAcuanVersi::query()
            ->where('paket_acuan_id', $paket->id)
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->where('status', PaketAcuanVersi::STATUS_DRAF);
        $total = (clone $drafQuery)->count();
        $draf = $drafQuery
            ->orderByDesc('nomor_versi')
            ->limit(self::MAKS_DAFTAR_DRAF)
            ->get()
            ->map(static fn (PaketAcuanVersi $v): array => [
                'id' => (int) $v->id,
                'nomor_versi' => (int) $v->nomor_versi,
                'hash' => ['isi' => PenentuVersiAcuan::sha256($v)],
                'dasar_versi_id' => $v->dasar_versi_id === null ? null : (int) $v->dasar_versi_id,
                'alasan' => $v->alasan,
                'dibuat_pada' => $v->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'hash' => ['dasar' => $dasar['sha256'], 'peta' => $peta->sha256, 'workbook' => (string) $workbook->sha256],
            'dasar_versi_id' => $dasar['versi'] === null ? null : (int) $dasar['versi']->id,
            'suntingan' => $proyeksi,
            'draf' => $draf,
            'draf_total' => $total,
            'draf_terpotong' => $total > count($draf),
        ];
    }

    /**
     * `GET /api/studio/workbook/{workbook}/draf/{versi}` -- muat ulang satu versi
     * `data` milik paket workbook ini (status APA PUN boleh dibaca; hanya PUT
     * yang menuntut draf). Versi tak ada / milik lab lain / milik paket lain /
     * bukan jenis `data` = `abort(404)` yang sama. Tidak menulis, tanpa kunci.
     *
     * `hash.dasar` = dasar SEKARANG (basis POST berikutnya), bukan dasar asal
     * draf; asal draf = `versi.dasar_versi_id`.
     *
     * @return array{status: string, versi: array<string, mixed>, hash: array<string, string>, suntingan: list<array{sel: string, jalur: string, nilai: string}>}
     *
     * @throws DrafAcuanDitolak
     */
    public function muat(WorkbookMaster $workbook, int $versiId): array
    {
        $organisasiId = (int) $workbook->organization_id;
        $peta = $this->petaUntuk($workbook);
        $paket = $this->paketUntuk($organisasiId, $peta);
        abort_if($paket === null, 404);

        $versi = $this->versiDataPaket($paket, $versiId, kunci: false);
        $isi = $this->isiTerurai($versi);
        $this->pastikanStabil(['isi' => $isi, 'sha256' => PenentuVersiAcuan::sha256($versi), 'versi' => $versi, 'paket' => $paket, 'label' => '']);

        return $this->respons('draf_dimuat', $versi, $this->draf->dasar($organisasiId, $peta), $workbook, $peta, $this->pemetaanTersaring($peta, $workbook, $isi));
    }

    /**
     * `PUT /api/studio/workbook/{workbook}/draf/{versi}` -- suntingan angka ke
     * draf yang SUDAH ada. Satu transaksi; setiap penolakan membatalkan semuanya.
     *
     * Urutan (sengaja): baca ulang workbook (arsip 404, non-aktif 422) -> peta ->
     * hash workbook/peta (409) -> kunci `paket_acuan` -> kunci versi (urutan
     * `AlurVersiAcuan::kunci()`: paket dulu) -> versi harus milik org + paket +
     * jenis `data` (404 yang sama dengan "tidak ada") -> status harus `draf`
     * (409 `draf_beku`; isi diajukan/aktif/pensiun/ditolak TIDAK PERNAH ditimpa)
     * -> isi sekarang stabil kanonik (D3) -> adu `expected_isi_sha256`.
     *
     * Hash cocok: terapkan suntingan ke isi sekarang. Tak cocok (draf berubah
     * sejak klien memuatnya): coba terapkan ke isi SEKARANG; bila hasilnya nol
     * perubahan (nilainya sudah ada -- mis. retry setelah respons hilang) = 200
     * `tidak_berubah`, tanpa tulis/audit; selain itu 409 `draf_berubah`.
     * Perubahan kosong dengan hash cocok juga 200 `tidak_berubah`.
     *
     * Yang ditulis: HANYA kolom `isi` (teks kanonik; `updated_at` ikut otomatis).
     * `isi_sha256`, `status`, `lock_versi`, `berlaku_*`, `dasar_versi_id`,
     * `alasan` versi tidak disentuh -- hash dikunci saat `ajukan`. Jejak: baris
     * `paket_acuan_suntingan` dari selisih isi lama/baru (sumber `ketik`) dan satu
     * baris log `sunting` (hash sebelum/sesudah). Kunci workbook TIDAK diambil:
     * yang dilindungi hanya urutan paket -> versi.
     *
     * @param  array{workbook_sha256: string, peta_sel_sha256: string, expected_isi_sha256: string, alasan: string, suntingan: list<array{sel: string, nilai: string}>}  $data
     * @return array{status: string, versi: array<string, mixed>, hash: array<string, string>, suntingan: list<array{sel: string, jalur: string, nilai: string}>}
     *
     * @throws DrafAcuanDitolak
     */
    public function perbarui(WorkbookMaster $workbook, int $versiId, int $userId, array $data): array
    {
        return DB::transaction(fn (): array => $this->perbaruiDalamTransaksi($workbook, $versiId, $userId, $data));
    }

    /** @param  array<string, mixed>  $data */
    private function perbaruiDalamTransaksi(WorkbookMaster $dariRequest, int $versiId, int $userId, array $data): array
    {
        // Kunci paket PERTAMA (alasan di dalamTransaksi POST: snapshot REPEATABLE READ), baru workbook dibaca ulang.
        $petaAwal = $this->petaUntuk($dariRequest);
        $paket = $this->paketUntuk((int) $dariRequest->organization_id, $petaAwal, kunci: true);

        $workbook = $this->segarkanWorkbook($dariRequest);
        $organisasiId = (int) $workbook->organization_id;
        $peta = $this->petaUntuk($workbook);

        if ($peta->kodeProfil !== $petaAwal->kodeProfil) {
            throw new DrafAcuanDitolak('workbook_berubah', 'Workbook master berubah sejak Anda membukanya. Muat ulang workbook lalu ulangi.', ['hash_terbaru' => $this->hashTerbaruPerbarui($workbook, $peta, $versiId)], 409);
        }

        if (! hash_equals((string) $workbook->sha256, (string) $data['workbook_sha256'])) {
            throw new DrafAcuanDitolak('workbook_berubah', 'Workbook master berubah sejak Anda membukanya. Muat ulang workbook lalu ulangi.', ['hash_terbaru' => $this->hashTerbaruPerbarui($workbook, $peta, $versiId)], 409);
        }

        if (! hash_equals($peta->sha256, (string) $data['peta_sel_sha256'])) {
            throw new DrafAcuanDitolak('peta_berubah', 'Peta sel server berubah sejak Anda membukanya. Muat ulang lalu ulangi.', ['hash_terbaru' => $this->hashTerbaruPerbarui($workbook, $peta, $versiId)], 409);
        }

        // Paket sudah terkunci di atas; sekarang VERSI (AlurVersiAcuan::kunci(): paket dulu, lalu versi).
        abort_if($paket === null, 404);
        $versi = $this->versiDataPaket($paket, $versiId, kunci: true);

        if ($versi->status !== PaketAcuanVersi::STATUS_DRAF) {
            throw new DrafAcuanDitolak('draf_beku', 'Versi ini bukan draf lagi (status '.$versi->status.'), isinya dibekukan dan tidak bisa disunting. Buat draf baru dari dasar.', ['status' => (string) $versi->status], 409);
        }

        $sekarang = $this->isiTerurai($versi);
        $hashSekarang = PenentuVersiAcuan::sha256($versi);
        $this->pastikanStabil(['isi' => $sekarang, 'sha256' => $hashSekarang, 'versi' => $versi, 'paket' => $paket, 'label' => '']);

        $pemetaan = $this->pemetaanTersaring($peta, $workbook, $sekarang);
        $suntingan = array_values($data['suntingan']);
        $cocok = hash_equals($hashSekarang, (string) $data['expected_isi_sha256']);
        $beda = static fn (): DrafAcuanDitolak => new DrafAcuanDitolak('draf_berubah', 'Draf ini sudah berubah sejak Anda memuatnya. Muat ulang lalu ulangi suntingan Anda.', ['hash_terbaru' => ['workbook' => (string) $workbook->sha256, 'peta' => $peta->sha256, 'isi' => $hashSekarang]], 409);

        try {
            $hasil = $this->terapkan($sekarang, $pemetaan, $suntingan);
        } catch (DrafAcuanDitolak $e) {
            // Isi sudah bergeser: penolakan input tak bisa dipercaya sebagai jawaban atas draf yang klien lihat.
            throw $cocok ? $e : $beda();
        }

        if ($hasil['perubahan'] === []) {
            return $this->respons('tidak_berubah', $versi, $this->draf->dasar($organisasiId, $peta), $workbook, $peta, $pemetaan);
        }

        if (! $cocok) {
            throw $beda();
        }

        $kanonik = JsonKanonik::serialisasi($hasil['isi']);
        $hashSesudah = JsonKanonik::sha256($kanonik);

        // HANYA kolom isi.
        $versi->forceFill(['isi' => $kanonik])->save();

        foreach (DrafAcuanMaster::selisih($sekarang, $hasil['isi']) as $jalur => [$nilaiLama, $nilaiBaru]) {
            PaketAcuanSuntingan::create([
                'paket_acuan_versi_id' => $versi->id,
                ...self::pecahJalur($jalur),
                'nilai_lama' => $nilaiLama,
                'nilai_baru' => $nilaiBaru,
                'sumber' => PaketAcuanSuntingan::SUMBER_KETIK,
                'alasan' => $data['alasan'],
                'user_id' => $userId,
            ]);
        }

        // Dasar asal draf dicatat dari log `dibuat` (bila ada); tidak dikarang ulang.
        $asal = AlurVersiAcuan::sumber($versi) ?? [];
        AlurVersiAcuan::catat($versi, PaketAcuanLog::AKSI_SUNTING, PaketAcuanVersi::STATUS_DRAF, PaketAcuanVersi::STATUS_DRAF, $userId, $data['alasan'],
            sumber: [
                'workbook_master_id' => $workbook->id,
                'workbook_sha256' => $workbook->sha256,
                'workbook_nama' => $workbook->nama,
                'workbook_sumber' => $workbook->sumber,
                'peta_sel' => $peta->kodeProfil,
                'peta_sel_sha256' => $peta->sha256,
                'varian' => PemetaanSuntingan::varian($peta, (string) $workbook->nama),
                'dasar' => $asal['dasar'] ?? null,
                'dasar_sha256' => $asal['dasar_sha256'] ?? null,
                'sumber_ui' => self::SUMBER_UI,
            ],
            rincian: [
                'sel_berubah' => count($hasil['perubahan']),
                'hash_sebelum' => $hashSekarang,
                'hash_sesudah' => $hashSesudah,
                'sel' => array_map(static fn (array $p): array => ['sel' => $p['sel'], 'jalur' => $p['jalur']], $hasil['perubahan']),
            ],
        );

        // Respons dari isi yang dibaca ULANG dari DB, masih di dalam transaksi.
        $tersimpan = PaketAcuanVersi::query()->findOrFail($versi->id);

        return $this->respons('draf_diperbarui', $tersimpan, $this->draf->dasar($organisasiId, $peta), $workbook, $peta, $pemetaan);
    }

    /**
     * Baca ulang workbook di dalam transaksi (kunci utama yang sama, jadi organisasinya
     * pasti sama dengan yang sudah diperiksa `authorize()`). Hilang / diarsipkan =
     * `abort(404)`; riwayat hasil `--ganti` (`aktif` false)
     * = 422 `workbook_tidak_aktif` (fail-closed: draf hanya boleh dari master
     * yang sedang berlaku -- hash riwayat tidak lagi menggambarkan Studio).
     */
    private function segarkanWorkbook(WorkbookMaster $dariRequest): WorkbookMaster
    {
        $baru = WorkbookMaster::query()->find($dariRequest->getKey());

        abort_if($baru === null || $baru->diarsipkan_pada !== null, 404);

        if (! $baru->aktif) {
            throw new DrafAcuanDitolak('workbook_tidak_aktif', 'Workbook ini bukan master aktif lagi (sudah digantikan versi lain). Buka master yang aktif lalu ulangi.');
        }

        return $baru;
    }

    private function paketUntuk(int $organisasiId, PetaSel $peta, bool $kunci = false): ?PaketAcuan
    {
        $q = PaketAcuan::query()
            ->where('organization_id', $organisasiId)
            ->where('kode_profil', $peta->kodeProfil);

        return ($kunci ? $q->lockForUpdate() : $q)->first();
    }

    /** Versi `data` milik paket ini (dan organisasinya) atau `abort(404)`. */
    private function versiDataPaket(PaketAcuan $paket, int $versiId, bool $kunci): PaketAcuanVersi
    {
        $q = PaketAcuanVersi::query()
            ->whereKey($versiId)
            ->where('paket_acuan_id', $paket->id)
            ->where('organization_id', $paket->organization_id)
            ->where('jenis', PaketAcuanVersi::JENIS_DATA);

        $versi = ($kunci ? $q->lockForUpdate() : $q)->first();
        abort_if($versi === null, 404);

        return $versi;
    }

    /**
     * Isi versi sebagai array; isi rusak (bukan JSON/objek) = 409 `isi_tidak_stabil`,
     * bukan 500.
     *
     * @return array<array-key, mixed>
     */
    private function isiTerurai(PaketAcuanVersi $versi): array
    {
        try {
            return $versi->isiTerurai();
        } catch (JsonException|UnexpectedValueException) {
            throw new DrafAcuanDitolak('isi_tidak_stabil', 'Isi versi ini bukan JSON yang sah, sehingga tidak bisa dibaca atau disunting.', status: 409);
        }
    }

    /**
     * Pemetaan tepercaya (dari peta server) yang daunnya ADA dan berupa angka
     * hingga di `$isi`; entri lain disaring, bukan membuat seluruh gelombang gagal.
     *
     * @param  array<array-key, mixed>  $isi
     * @return list<array{sel: string, jalur: string, tipe: string, bulat: int}>
     */
    private function pemetaanTersaring(PetaSel $peta, WorkbookMaster $workbook, array $isi): array
    {
        return array_values(array_filter(
            PemetaanSuntingan::untukMaster($peta, $workbook),
            static fn (array $m): bool => self::daunAngkaHingga($isi, $m['jalur']),
        ));
    }

    /** @return array{workbook: string, peta: string, dasar: ?string} */
    private function hashTerbaruBuat(WorkbookMaster $workbook, PetaSel $peta): array
    {
        return [
            'workbook' => (string) $workbook->sha256,
            'peta' => $peta->sha256,
            'dasar' => $this->hashDasarAtauNull((int) $workbook->organization_id, $peta),
        ];
    }

    /** Sesudah transaksi POST dibatalkan: baca keadaan terbaru dari awal. @return array{workbook: string, peta: ?string, dasar: ?string} */
    private function hashTerbaruSetelahGagal(WorkbookMaster $dariRequest): array
    {
        $workbook = WorkbookMaster::query()->find($dariRequest->getKey()) ?? $dariRequest;

        try {
            $peta = $this->petaUntuk($workbook);
        } catch (DrafAcuanDitolak) {
            $peta = null;
        }

        return [
            'workbook' => (string) $workbook->sha256,
            'peta' => $peta?->sha256,
            'dasar' => $peta === null ? null : $this->hashDasarAtauNull((int) $workbook->organization_id, $peta),
        ];
    }

    /** @return array{workbook: string, peta: string, isi: ?string} */
    private function hashTerbaruPerbarui(WorkbookMaster $workbook, PetaSel $peta, int $versiId): array
    {
        // Pencarian dibatasi organisasi + paket + jenis `data`: versi lab/paket lain tampak sama dengan "tidak ada" (null).
        $paket = $this->paketUntuk((int) $workbook->organization_id, $peta);
        $versi = $paket === null ? null : PaketAcuanVersi::query()
            ->whereKey($versiId)
            ->where('paket_acuan_id', $paket->id)
            ->where('organization_id', $paket->organization_id)
            ->where('jenis', PaketAcuanVersi::JENIS_DATA)
            ->first();

        return [
            'workbook' => (string) $workbook->sha256,
            'peta' => $peta->sha256,
            'isi' => $versi === null ? null : PenentuVersiAcuan::sha256($versi),
        ];
    }

    private function hashDasarAtauNull(int $organisasiId, PetaSel $peta): ?string
    {
        try {
            return $this->draf->dasar($organisasiId, $peta)['sha256'];
        } catch (RuntimeException|JsonException) {
            return null;
        }
    }

    private function petaUntuk(WorkbookMaster $workbook): PetaSel
    {
        // Cara memilih peta SAMA dengan impor (ImporWorkbook): paket dari profil/keluarga.
        $kodePaket = PetaProfilWorkbook::kodePaket(
            ['kode_profil' => $workbook->kode_profil, 'keluarga' => $workbook->keluarga],
            $this->registry,
        );
        $peta = PetaSel::untuk($kodePaket);

        if ($peta === null || $peta->tidakBerlakuUntuk((string) $workbook->nama)) {
            throw new DrafAcuanDitolak('profil_belum_terpetakan', 'Workbook ini belum punya peta sel, sehingga isinya belum bisa disimpan sebagai draf data acuan.');
        }

        return $peta;
    }

    /** @param  array{isi: array<array-key, mixed>, sha256: string, versi: ?PaketAcuanVersi, paket: ?PaketAcuan, label: string}  $dasar */
    private function pastikanStabil(array $dasar): void
    {
        $ulang = JsonKanonik::serialisasi($dasar['isi']);
        $versi = $dasar['versi'];
        $stabil = $versi !== null
            ? $ulang === (string) $versi->isi && JsonKanonik::sha256((string) $versi->isi) === $dasar['sha256']
            : JsonKanonik::sha256($ulang) === $dasar['sha256'];

        if (! $stabil) {
            throw new DrafAcuanDitolak('isi_tidak_stabil', 'Isi dasar tidak bisa disimpan ulang dengan byte yang sama (objek kosong, kunci numerik, bilangan di luar jangkauan, atau hash tidak cocok). Draf tidak dibuat supaya angka lain tidak bergeser diam-diam.', status: 409);
        }
    }

    /**
     * @param  array<array-key, mixed>  $isi
     */
    private static function daunAngkaHingga(array $isi, string $jalur): bool
    {
        try {
            $daun = JalurIsi::ambil($isi, $jalur);
        } catch (RuntimeException) {
            return false;
        }

        return is_int($daun) || (is_float($daun) && is_finite($daun));
    }

    /**
     * @param  array<array-key, mixed>  $isi
     * @param  list<array{sel: string, jalur: string, tipe: string, bulat: int}>  $pemetaan
     * @param  list<array{sel: string, nilai: string}>  $suntingan
     * @return array{isi: array<array-key, mixed>, perubahan: list<array{sel: string, jalur: string, nilai_lama: int|float, nilai_baru: float}>}
     */
    private function terapkan(array $isi, array $pemetaan, array $suntingan): array
    {
        $dilihat = [];

        foreach ($suntingan as $i => $s) {
            if (isset($dilihat[$s['sel']])) {
                throw new DrafAcuanDitolak('suntingan_ditolak', 'Sel yang sama muncul lebih dari sekali (suntingan ke-'.($i + 1).'). Kirim satu nilai per sel.', ['indeks' => $i, 'sel' => mb_substr($s['sel'], 0, 80)]);
            }

            $dilihat[$s['sel']] = true;
        }

        $didukung = array_flip(array_column($pemetaan, 'sel'));
        $tak = [];

        foreach ($suntingan as $s) {
            if (! isset($didukung[$s['sel']])) {
                $tak[] = mb_substr($s['sel'], 0, 80);
            }
        }

        if ($tak !== []) {
            throw new DrafAcuanDitolak('sel_tidak_didukung', 'Sel berikut tidak bisa disunting di draf: '.implode(', ', array_slice($tak, 0, 10)).(count($tak) > 10 ? ', ...' : '').'. Hanya sel angka tunggal yang dipetakan server yang boleh disunting.', ['sel' => array_slice($tak, 0, 20), 'jumlah' => count($tak)]);
        }

        try {
            return $this->preparer->prepare($isi, $pemetaan, $suntingan);
        } catch (InvalidArgumentException) {
            throw $this->suntinganDitolak($isi, $pemetaan, $suntingan);
        }
    }

    /**
     * Cari suntingan pertama yang ditolak sendirian (indeks 0-basis), supaya
     * pesan menunjuk sel, bukan "gelombang ditolak". Pesan preparer sendiri
     * (Inggris, menyebut struktur internal) tidak diteruskan ke klien.
     *
     * @param  array<array-key, mixed>  $isi
     * @param  list<array{sel: string, jalur: string, tipe: string, bulat: int}>  $pemetaan
     * @param  list<array{sel: string, nilai: string}>  $suntingan
     */
    private function suntinganDitolak(array $isi, array $pemetaan, array $suntingan): DrafAcuanDitolak
    {
        try {
            $this->preparer->prepare($isi, $pemetaan, []);
        } catch (InvalidArgumentException) {
            return new DrafAcuanDitolak('suntingan_ditolak', 'Pemetaan sel di server tidak konsisten dengan isi dasar. Hubungi admin; tidak ada yang disimpan.', ['sebab' => 'pemetaan_server']);
        }

        foreach ($suntingan as $i => $s) {
            try {
                $this->preparer->prepare($isi, $pemetaan, [$s]);
            } catch (InvalidArgumentException) {
                return new DrafAcuanDitolak(
                    'suntingan_ditolak',
                    'Nilai untuk sel '.mb_substr($s['sel'], 0, 80).' (suntingan ke-'.($i + 1).') ditolak. Pakai angka desimal biasa: maksimal 10 angka di belakang koma, tanpa spasi, tanda +, atau eksponen, dan tidak kehilangan ketelitian.',
                    ['indeks' => $i, 'sel' => mb_substr($s['sel'], 0, 80)],
                );
            }
        }

        return new DrafAcuanDitolak('suntingan_ditolak', 'Gelombang suntingan ditolak. Periksa nilai dan sel yang dikirim.');
    }

    /**
     * @param  array{isi: array<array-key, mixed>, sha256: string, versi: ?PaketAcuanVersi, paket: ?PaketAcuan, label: string}  $dasar
     * @param  list<array{sel: string, jalur: string, tipe: string, bulat: int}>  $pemetaan
     * @return array{status: string, versi: array<string, mixed>, hash: array<string, string>, suntingan: list<array{sel: string, jalur: string, nilai: string}>}
     */
    private function respons(string $status, PaketAcuanVersi $versi, array $dasar, WorkbookMaster $workbook, PetaSel $peta, array $pemetaan): array
    {
        try {
            $proyeksi = $this->proyeksi($versi->isiTerurai(), $pemetaan);
        } catch (UnexpectedValueException) {
            throw new DrafAcuanDitolak('proyeksi_gagal', 'Draf tidak bisa ditampilkan utuh; tidak ada yang disimpan. Hubungi admin.', status: 500);
        }

        $sekarangId = $dasar['versi'] === null ? null : (int) $dasar['versi']->id;

        return [
            'status' => $status,
            'versi' => [
                'id' => (int) $versi->id,
                'nomor_versi' => (int) $versi->nomor_versi,
                'status' => (string) $versi->status,
                'dasar_versi_id' => $versi->dasar_versi_id === null ? null : (int) $versi->dasar_versi_id,
                'dasar_versi_id_sekarang' => $sekarangId,
                // Hanya bermakna untuk DRAF: dasar asalnya bukan dasar berlaku lagi (mis. versi baru sudah
                // disahkan sesudah draf dibuat). Untuk versi bukan-draf selalu false (bukan null: tipe tetap bool) --
                // v1 hasil impor awal (dasar_versi_id null, sudah aktif) atau versi pensiun bukan "draf basi".
                'dasar_basi' => $versi->status === PaketAcuanVersi::STATUS_DRAF
                    && ($versi->dasar_versi_id === null ? null : (int) $versi->dasar_versi_id) !== $sekarangId,
                'alasan' => $versi->alasan,
                'dibuat_pada' => $versi->created_at?->toIso8601String(),
            ],
            'hash' => [
                'isi' => PenentuVersiAcuan::sha256($versi),
                'dasar' => $dasar['sha256'],
                'workbook' => (string) $workbook->sha256,
                'peta' => $peta->sha256,
            ],
            'suntingan' => $proyeksi,
        ];
    }
}
