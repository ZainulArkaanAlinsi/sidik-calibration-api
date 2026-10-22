<?php

namespace App\Services\Studio\Workbook;

use App\Models\Organization;
use App\Models\WorkbookMaster;
use App\Models\WorkbookMasterLembar;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Studio\Hitung\ModelHitung;
use App\Services\Studio\Hitung\SumberModelHitung;
use App\Services\Studio\PetaSel\DataBersamaPaket;
use App\Services\Studio\PetaSel\DrafAcuanMaster;
use App\Services\Studio\PetaSel\PemeriksaMaster;
use App\Services\Studio\PetaSel\PetaSel;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Simpan hasil baca workbook ke `workbook_master` + `workbook_master_lembar`
 * (Studio S1b). Satu workbook = satu transaksi.
 *
 * ## Idempoten per sha256 per lab
 *
 * csv: sha256 isi kanonik (`KanonikWorkbook::workbook`). xlsm: sha256 BYTE
 * berkas asli — dicek SEBELUM dekripsi, jadi berkas yang sudah ada tidak
 * meminta sandi dibuka ulang. Sama → `sudah_ada`, tidak ada yang ditulis.
 * Isi beda dengan nama sama → baris BARU (riwayat), tidak menimpa.
 *
 * ## Satu master aktif per jenis (K-49-13, 15 Okt 2026)
 *
 * Setiap baris punya `kunci_jenis` (`KunciJenisWorkbook`, atau dari
 * pemanggil: `--kunci-jenis` / `--gantikan`). Impor baru di jenis yang sudah
 * punya master aktif MENGGANTINYA: yang lama jadi riwayat (`aktif` NULL,
 * `digantikan_oleh_id` = baris baru), TIDAK dihapus (`JenisWorkbook`).
 * Pengecualian: csv tidak pernah menggantikan xlsm aktif — csv lahir sebagai
 * riwayat xlsm itu (csv = ekspor nilai dari master yang sama).
 *
 * ## `$ganti` (perintah `--ganti`) — ekstrak ulang SATU baris di tempat
 *
 * Dipakai sesudah `KanonikWorkbook::FORMAT` naik. Satu baris dengan sumber,
 * nama, dan organisasi yang sama DIGANTIKAN di transaksi yang sama dengan
 * penulisan penggantinya — TIDAK dihapus (ADR-49-07, 22 Okt 2026): baris lama
 * beserta lembarnya tetap ada sebagai riwayat (`aktif` NULL,
 * `sha256_hidup` NULL, `digantikan_oleh_id` = pengganti, audit `diubah`), dan
 * pengganti mencatat "Menggantikan …". Pengganti MEWARISI status jenisnya
 * (kunci, aktif/riwayat/arsip, versi, penunjuk riwayat). Versi lain di jenis
 * yang sama tidak disentuh. Tidak pernah menyeberang sumber:
 *  - csv: sha kanonik ikut berubah, jadi sha sama = hasil ekstraksi sama →
 *    tetap `sudah_ada`; yang diganti = baris csv bernama sama (di Studio dulu,
 *    lalu yang aktif, lalu terbaru).
 *  - xlsm: sha = byte berkas, TIDAK ikut format. Berkas yang sha-nya sudah ada
 *    di baris xlsm bernama sama didekripsi & diekstrak ulang lalu menggantikan
 *    baris itu. Berkas asli lama di disk TIDAK dihapus (K-49-06).
 *
 * ## Berkas asli `.xlsm` (K-49-11)
 *
 * Disalin APA ADANYA — byte terenkripsi, tidak pernah versi terdekripsi — ke
 * disk privat `local` di `workbook-master/{organization_id}/{sha256}.xlsm`,
 * dibaca balik dan diadu sha256-nya sebelum barisnya ditulis. Transaksi DB
 * gagal → berkas yang baru ditulis dihapus lagi. Sumber `.xlsm` yang ternyata
 * TIDAK terenkripsi (ZIP polos) tetap disimpan apa adanya, tetapi ditandai
 * `berkas_terenkripsi = false` + catatan — tidak pernah tampil sebagai
 * terenkripsi.
 *
 * ## Tahap C (14 Okt 2026): sumber tanpa sandi, gambar, model hitung
 *
 *  - Sumber ZIP polos (`.xlsx` tanpa sandi, atau `.xlsm` polos) TIDAK lagi
 *    disalin polos: byte aslinya dienkripsi kunci aplikasi (`Crypt`) ke
 *    `{sha256}.{ext}.enc`, diverifikasi dengan dekripsi + sha256 sebelum baris
 *    ditulis. `berkas_terenkripsi` tetap berarti "sumbernya bersandi" (false).
 *  - Byte gambar PNG/JPEG lembar disimpan terenkripsi kunci aplikasi di
 *    `workbook-master/{org}/{sha256}-gambar/{id}.bin` (dibaca `GET …/gambar/{id}`),
 *    SEBELUM transaksi seperti berkas asli (tinjau C #5): gagal tulis → impor
 *    batal tanpa baris; transaksi gagal → gambar yang baru ditulis dihapus.
 *    Folder `-gambar` tanpa baris disapu `studio:sapu-temp-workbook`.
 *  - Model hitung `/hitung` dibangun dari muatan yang sama dan disimpan ke
 *    cache (`SumberModelHitung`) — workbook bersandi bisa dihitung tanpa
 *    meminta sandi lagi selama cache-nya hidup.
 *
 * ## Peta sel (22 Okt 2026, rencana studio-kendali-olah-data fase 1-2)
 *
 * Bila profil workbook punya `database/data/peta-sel/<kode>.json`, struktur
 * master diperiksa SEBELUM apa pun ditulis (termasuk `kering`): lembar wajib,
 * jangkar label, tipe sel angka. Tidak cocok → `GagalBacaWorkbook`
 * `struktur_master_berubah` dengan daftar persis apa yang hilang/bergeser;
 * tidak ada baris, berkas, maupun draf yang lahir. Lolos → master yang jadi
 * AKTIF jenisnya diekstrak jadi DRAF `paket_acuan_versi` di transaksi yang
 * sama (`DrafAcuanMaster`); draf tidak pernah menghitung sesi sebelum alur
 * versi mengesahkannya. Profil tanpa peta: perilaku lama, ditandai
 * `belum_terpetakan` di ringkasan & `catatan`.
 */
final class ImporWorkbook
{
    /**
     * Disk penyimpanan berkas asli + gambar: `config('studio.disk_berkas')`
     * (bawaan `arsip` = disk AWET, R2 di produksi — disk container Render
     * terhapus tiap deploy dan `/hitung` kehilangan berkas aslinya). Baris
     * lama tetap dibaca dari kolom `berkas_disk`-nya sendiri.
     */
    public static function diskBerkas(): string
    {
        return (string) config('studio.disk_berkas', 'arsip');
    }

    /** Akhiran berkas asli yang dienkripsi kunci aplikasi (sumber tanpa sandi). */
    public const AKHIRAN_TERENKRIPSI_APLIKASI = '.enc';

    public function __construct(
        private readonly PembacaCsvWorkbook $csv,
        private readonly PembacaXlsmWorkbook $xlsm,
        private readonly PetaProfilWorkbook $peta,
        private readonly SumberModelHitung $sumberHitung,
        private readonly JenisWorkbook $jenis,
        private readonly PemeriksaMaster $pemeriksa,
        private readonly DrafAcuanMaster $draf,
    ) {}

    /**
     * @return array<string, mixed> ringkasan (lihat `ringkasan()`)
     */
    public function imporCsv(string $folder, string $nama, string $asal, Organization $organisasi, ?int $userId, bool $kering, bool $ganti = false, ?string $kunciJenis = null, ?string $alasan = null): array
    {
        $hasil = $this->csv->baca($folder);
        $kanonik = KanonikWorkbook::workbook($hasil['lembar']);
        $sha = KanonikWorkbook::sha256($kanonik);
        $peta = $this->peta->cocokkan($nama);

        $catatan = trim('Sumber CSV: '.$asal.'. Posisi sel = posisi CSV (bisa bergeser dari .xlsm asli). '.($peta['catatan'] ?? '').self::kalimatAlasan($alasan));

        return $this->simpan($organisasi, $userId, $kering, [
            'nama' => $nama,
            'sumber' => WorkbookMaster::SUMBER_CSV,
            'sha256' => $sha,
            'ukuran' => $hasil['ukuran'],
            'kode_profil' => $peta['kode_profil'],
            'keluarga' => $peta['keluarga'],
            'kunci_jenis' => $kunciJenis ?? KunciJenisWorkbook::dari($peta['kode_profil'], $peta['keluarga'], $nama),
            'catatan' => $catatan,
            'berkas_terenkripsi' => null,
        ], $hasil['lembar'], null, $ganti);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws GagalBacaWorkbook
     */
    public function imporXlsm(string $berkas, #[SensitiveParameter] string $sandi, Organization $organisasi, ?int $userId, bool $kering, bool $ganti = false, ?string $kunciJenis = null, ?string $alasan = null): array
    {
        if (! is_file($berkas) || ! is_readable($berkas)) {
            throw new GagalBacaWorkbook(GagalBacaWorkbook::GAGAL_MUAT, 'Berkas tidak ada atau tidak bisa dibaca.');
        }

        $sha = (string) hash_file('sha256', $berkas);
        $nama = (string) preg_replace('/\.(xlsm|xlsx)$/i', '', basename($berkas));
        $ada = $this->cariAda($organisasi, $sha);

        // Dicek sebelum dekripsi: berkas yang sudah ada tidak dibuka ulang —
        // kecuali `--ganti` meminta ekstrak ulang baris xlsm bernama sama.
        if ($ada !== null && ! self::bolehEkstrakUlang($ada, WorkbookMaster::SUMBER_XLSM, $nama, $ganti)) {
            return $this->ringkasan('sudah_ada', $ada->nama, WorkbookMaster::SUMBER_XLSM, $sha, $ada->kode_profil, $ada->keluarga, [], $ada, kunci: (string) $ada->kunci_jenis);
        }

        $hasil = $this->xlsm->baca($berkas, $sandi);
        $peta = $this->peta->cocokkan($nama);
        $catatan = $peta['catatan'];

        if ($hasil['terenkripsi'] !== true) {
            $catatan = trim('Berkas asli TIDAK terenkripsi sandi (ZIP polos) — salinannya disimpan terenkripsi kunci aplikasi (K-49-11, tahap C). '.($catatan ?? ''));
        }

        $catatan = trim((string) $catatan.self::kalimatAlasan($alasan));

        return $this->simpan($organisasi, $userId, $kering, [
            'nama' => $nama,
            'sumber' => WorkbookMaster::SUMBER_XLSM,
            'sha256' => $sha,
            'ukuran' => (int) filesize($berkas),
            'kode_profil' => $peta['kode_profil'],
            'keluarga' => $peta['keluarga'],
            'kunci_jenis' => $kunciJenis ?? KunciJenisWorkbook::dari($peta['kode_profil'], $peta['keluarga'], $nama),
            'catatan' => $catatan,
            'berkas_terenkripsi' => $hasil['terenkripsi'] === true,
        ], $hasil['lembar'], $berkas, $ganti, $hasil['gambar'], $hasil['model_hitung']);
    }

    /** Alasan impor (unggah lewat API) ikut tercatat di `catatan` baris baru → jejak audit `dibikin`. */
    private static function kalimatAlasan(?string $alasan): string
    {
        $alasan = trim((string) $alasan);

        return $alasan === '' ? '' : ' Alasan impor: '.$alasan;
    }

    /**
     * Baris `$ada` (sha sama) boleh diekstrak ulang & diganti: hanya xlsm
     * dengan `--ganti`, sumber dan nama sama. csv tidak pernah — sha kanonik
     * yang sama berarti hasil ekstraksinya memang sama.
     */
    private static function bolehEkstrakUlang(WorkbookMaster $ada, string $sumber, string $nama, bool $ganti): bool
    {
        return $ganti
            && $sumber === WorkbookMaster::SUMBER_XLSM
            && $ada->sumber === WorkbookMaster::SUMBER_XLSM
            && $ada->nama === $nama;
    }

    public static function jalurBerkasAsli(int $organisasiId, string $sha256, string $ekstensi = 'xlsm', bool $kunciAplikasi = false): string
    {
        return "workbook-master/{$organisasiId}/{$sha256}.{$ekstensi}".($kunciAplikasi ? self::AKHIRAN_TERENKRIPSI_APLIKASI : '');
    }

    /** Byte gambar (terenkripsi kunci aplikasi) — tahap C. */
    public static function jalurGambar(int $organisasiId, string $sha256, string $id): string
    {
        return "workbook-master/{$organisasiId}/{$sha256}-gambar/{$id}.bin";
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $lembar
     * @param  array<string, array{jenis: string, byte: string}>  $gambar
     * @return array<string, mixed>
     */
    private function simpan(Organization $organisasi, ?int $userId, bool $kering, array $meta, array $lembar, ?string $berkasAsli, bool $ganti = false, array $gambar = [], ?ModelHitung $model = null): array
    {
        $ada = $this->cariAda($organisasi, $meta['sha256']);

        if ($ada !== null && ! self::bolehEkstrakUlang($ada, $meta['sumber'], $meta['nama'], $ganti)) {
            return $this->ringkasan('sudah_ada', $ada->nama, $meta['sumber'], $meta['sha256'], $ada->kode_profil, $ada->keluarga, $lembar, $ada, kunci: (string) $ada->kunci_jenis);
        }

        // `--ganti`: SATU baris sumber sama, nama sama, lab sama — diekstrak ulang di tempat.
        $lama = $ganti ? $this->barisEkstrakUlang($organisasi, $meta, $ada) : null;

        // Peta sel: struktur diperiksa sebelum apa pun ditulis (melempar bila berubah).
        // Peta per PAKET data acuan: workbook keluarga (Volumetric Fixed/Graduated,
        // DRUCK, ...) memetakan ke paket profil-profil yang berbagi satu tabel.
        $peta = PetaSel::untuk(PetaProfilWorkbook::kodePaket($meta, app(CalibrationProfileRegistry::class)));
        // Peta ber-`varian.lainnya = lewati` tidak berlaku untuk master di luar
        // variannya (mis. Flowmeter Gravimetri vs peta Ultrasonic): belum terpetakan, bukan ditolak.
        $petaDilewati = $peta !== null && $peta->tidakBerlakuUntuk((string) $meta['nama']) ? $peta->kodeProfil : null;
        $peta = $petaDilewati !== null ? null : $peta;
        $dasar = $peta !== null ? $this->draf->dasar((int) $organisasi->id, $peta) : null;
        $hasilPeta = $peta !== null ? $this->pemeriksa->periksa($peta, (string) $meta['nama'], $lembar, $dasar['isi'], (string) $meta['sumber']) : null;
        $statusPeta = $peta !== null ? 'tervalidasi' : 'belum_terpetakan';
        // Data paket lain yang ikut dibawa master (balok ukur Micrometer di
        // master Dial, ...): diadu, tidak ditulis; beda = ditandai, tidak diam.
        $bedaPaket = $peta !== null ? app(DataBersamaPaket::class)->periksa($peta, $lembar, (int) $organisasi->id, (string) $meta['sumber']) : [];
        $meta['catatan'] = trim($meta['catatan'].($peta !== null
            ? ' Peta sel '.$peta->kodeProfil.': struktur tervalidasi'.($hasilPeta['varian'] !== null ? ' (varian '.$hasilPeta['varian'].')' : '').'.'
            : ' Peta sel: belum terpetakan'.($petaDilewati !== null ? " (bukan varian peta {$petaDilewati})" : '').' — struktur master ini belum divalidasi dan isinya TIDAK dipakai jalur hitung/sertifikat/CMC.')
            .($bedaPaket === [] ? '' : ' PERINGATAN bersama_antar_paket: '.count($bedaPaket).' daun data paket lain ('
                .implode(', ', array_unique(array_column($bedaPaket, 'paket'))).') di master ini beda dari versi berlakunya.'));

        if ($kering) {
            $aktif = $lama === null
                ? WorkbookMaster::query()->where('organization_id', $organisasi->id)->where('kunci_jenis', $meta['kunci_jenis'])->where('aktif', true)->first()
                : null;

            return $this->ringkasan('kering', $meta['nama'], $meta['sumber'], $meta['sha256'], $meta['kode_profil'], $meta['keluarga'], $lembar, null, $lama !== null ? 1 : 0, kunci: (string) ($lama->kunci_jenis ?? $meta['kunci_jenis']), menggantikan: $aktif?->id, petaSel: $statusPeta);
        }

        if ($lama !== null) {
            // Jejak sebelum → sesudah: baris lama tercatat `diubah` (jadi
            // riwayat, Diaudit), baris baru menyebut id & sha256 yang digantikannya.
            $meta['catatan'] = trim($meta['catatan'].' Menggantikan impor '.$meta['sumber'].' lama #'.$lama->id.' (sha256 '.$lama->sha256.') lewat --ganti (format kanonik '.KanonikWorkbook::FORMAT.').');
        }

        $jalurDisk = null;
        $ditulisBaru = false;

        if ($berkasAsli !== null) {
            $ekstensi = strtolower(pathinfo($berkasAsli, PATHINFO_EXTENSION)) === 'xlsx' ? 'xlsx' : 'xlsm';
            $kunciAplikasi = $meta['berkas_terenkripsi'] !== true;
            $jalurDisk = self::jalurBerkasAsli($organisasi->id, $meta['sha256'], $ekstensi, $kunciAplikasi);
            $ditulisBaru = $this->simpanBerkasAsli($berkasAsli, $jalurDisk, $meta['sha256'], $kunciAplikasi);
        }

        // Tahap C — gambar ditulis SEBELUM baris (tinjau C #5): baris yang
        // menunjuk gambar yang tidak pernah tertulis = 404 permanen, dan impor
        // ulang berkas yang sama cuma menjawab `sudah_ada`.
        $gambarBaru = [];

        try {
            $gambarBaru = $this->simpanGambar($organisasi->id, $meta['sha256'], $gambar);
        } catch (Throwable $e) {
            if ($ditulisBaru && $jalurDisk !== null) {
                Storage::disk(self::diskBerkas())->delete($jalurDisk);
            }

            throw $e;
        }

        $menggantikan = null;
        $acuan = null;

        try {
            $workbook = DB::transaction(function () use ($organisasi, $userId, $meta, $lembar, $jalurDisk, $lama, &$menggantikan, $peta, $dasar, $hasilPeta, $bedaPaket, &$acuan): WorkbookMaster {
                $impor = null;
                $penunjuk = [];

                if ($lama !== null) {
                    // Status jenis diwarisi pengganti; riwayat yang menunjuk baris lama dialihkan.
                    $jenis = [
                        'kunci_jenis' => $lama->kunci_jenis,
                        'aktif' => $lama->aktif,
                        'versi' => $lama->versi,
                        'digantikan_oleh_id' => $lama->digantikan_oleh_id,
                        'diarsipkan_pada' => $lama->diarsipkan_pada,
                    ];
                    $penunjuk = WorkbookMaster::query()->where('digantikan_oleh_id', $lama->id)->pluck('id')->all();
                    // TIDAK dihapus (ADR-49-07): baris lama + lembarnya jadi
                    // riwayat pengganti. Dilepas dulu dari unik aktif-per-jenis
                    // dan unik sha hidup supaya pengganti bisa ditulis; lewat
                    // model supaya Diaudit mencatat `diubah` (sebelum → sesudah).
                    $lama->aktif = null;
                    $lama->sha256_hidup = null;
                    $lama->save();
                } else {
                    $impor = $this->jenis->untukImpor((int) $organisasi->id, (string) $meta['kunci_jenis'], (string) $meta['sumber']);
                    $jenis = ['kunci_jenis' => $meta['kunci_jenis'], 'aktif' => $impor['aktif'], 'versi' => $impor['versi']];
                }

                $workbook = WorkbookMaster::create($jenis + [
                    'organization_id' => $organisasi->id,
                    'kode_profil' => $meta['kode_profil'],
                    'keluarga' => $meta['keluarga'],
                    'nama' => $meta['nama'],
                    'sumber' => $meta['sumber'],
                    'sha256' => $meta['sha256'],
                    'ukuran' => $meta['ukuran'],
                    'jumlah_lembar' => count($lembar),
                    'berkas_disk' => $jalurDisk !== null ? self::diskBerkas() : null,
                    'berkas_path' => $jalurDisk,
                    'berkas_terenkripsi' => $jalurDisk !== null ? $meta['berkas_terenkripsi'] : null,
                    'catatan' => $meta['catatan'],
                    'diimpor_oleh' => $userId,
                ]);

                foreach ($penunjuk as $id) {
                    $r = WorkbookMaster::query()->findOrFail($id);
                    $r->digantikan_oleh_id = $workbook->id;
                    $r->save();
                }

                if ($lama !== null) {
                    $lama->digantikan_oleh_id = $workbook->id;
                    $lama->catatan = trim((string) $lama->catatan.' Diekstrak ulang lewat --ganti → #'.$workbook->id.' (format kanonik '.KanonikWorkbook::FORMAT.'); baris ini riwayat, tidak dihapus.');
                    $lama->save();
                }

                if ($impor !== null && $impor['lama'] !== null) {
                    $this->jenis->catatPengganti($impor['lama'], $workbook, $impor['aktif']);
                    $menggantikan = $impor['aktif'] ? $impor['lama']->id : null;
                }

                foreach ($lembar as $l) {
                    WorkbookMasterLembar::create([
                        'workbook_master_id' => $workbook->id,
                        'urutan' => $l['urutan'],
                        'nama' => $l['nama'],
                        'tersembunyi' => $l['tersembunyi'],
                        'dikecualikan' => $l['dikecualikan'],
                        'alasan_dikecualikan' => $l['alasan_dikecualikan'],
                        'tautan_tabel' => $l['tautan_tabel'],
                        'jumlah_baris' => $l['jumlah_baris'],
                        'jumlah_kolom' => $l['jumlah_kolom'],
                        'jumlah_sel' => $l['jumlah_sel'],
                        'jumlah_rumus' => $l['jumlah_rumus'],
                        'tata_letak' => $l['tata_letak'],
                        // K-49-12: semua lembar ditulis apa adanya, termasuk
                        // DATABASE/SERTIFIKAT (terkurung organisasi lewat induk).
                        'sel' => $l['sel_kanonik'],
                    ]);
                }

                // Hanya master AKTIF jenisnya yang mengendalikan data acuan;
                // riwayat (mis. csv di bawah xlsm aktif) tidak membuat draf.
                if ($peta !== null) {
                    $acuan = ($workbook->fresh()?->aktif === true
                        ? $this->draf->buat($workbook, $peta, $hasilPeta, $dasar, $userId)
                        : ['status' => 'bukan_master_aktif'])
                        + ['bersama_antar_paket' => $bedaPaket];
                }

                return $workbook;
            });
        } catch (Throwable $e) {
            if ($ditulisBaru && $jalurDisk !== null) {
                Storage::disk(self::diskBerkas())->delete($jalurDisk);
            }

            if ($gambarBaru !== []) {
                Storage::disk(self::diskBerkas())->delete($gambarBaru);
            }

            throw $e;
        }

        // Model hitung: turunan berkas asli yang bisa dibangun ulang kapan saja
        // (`SumberModelHitung::ambil`), jadi boleh sesudah transaksi.
        if ($model !== null) {
            $this->sumberHitung->simpan($meta['sha256'], $model);
        }

        return $this->ringkasan($lama !== null ? 'diganti' : 'baru', $workbook->nama, $meta['sumber'], $meta['sha256'], $meta['kode_profil'], $meta['keluarga'], $lembar, $workbook->fresh() ?? $workbook, $lama !== null ? 1 : 0, kunci: (string) $workbook->kunci_jenis, menggantikan: $menggantikan, petaSel: $statusPeta, acuan: $acuan);
    }

    /**
     * Baris yang diekstrak ulang `--ganti`. xlsm: baris ber-sha sama (sudah
     * diperiksa `bolehEkstrakUlang`); tanpa itu impor biasa. csv: baris csv
     * bernama sama di lab ini — di Studio dulu, lalu yang aktif, lalu terbaru.
     *
     * @param  array<string, mixed>  $meta
     */
    private function barisEkstrakUlang(Organization $organisasi, array $meta, ?WorkbookMaster $ada): ?WorkbookMaster
    {
        if ($meta['sumber'] === WorkbookMaster::SUMBER_XLSM) {
            return $ada;
        }

        return WorkbookMaster::query()
            ->where('organization_id', $organisasi->id)
            ->where('sumber', $meta['sumber'])
            ->where('nama', $meta['nama'])
            ->whereNotNull('sha256_hidup')
            ->orderByRaw('case when diarsipkan_pada is null then 0 else 1 end')
            ->orderByRaw('case when aktif = 1 then 0 else 1 end')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Tulis byte gambar (terenkripsi kunci aplikasi). Gagal satu → yang baru
     * ditulis panggilan ini dihapus lagi, lalu pengecualian (impor batal).
     *
     * @param  array<string, array{jenis: string, byte: string}>  $gambar
     * @return list<string> jalur yang BELUM ada sebelum panggilan ini (boleh dihapus bila transaksi gagal)
     */
    private function simpanGambar(int $organisasiId, string $sha256, array $gambar): array
    {
        $disk = Storage::disk(self::diskBerkas());
        $baru = [];

        foreach ($gambar as $id => $g) {
            $jalur = self::jalurGambar($organisasiId, $sha256, (string) $id);
            $sudahAda = $disk->exists($jalur);

            if ($disk->put($jalur, Crypt::encryptString($g['byte'])) !== true || ! $disk->exists($jalur)) {
                if ($baru !== []) {
                    $disk->delete($baru);
                }

                throw new RuntimeException('Gambar workbook tidak bisa ditulis ke disk privat; impor dibatalkan.');
            }

            if (! $sudahAda) {
                $baru[] = $jalur;
            }
        }

        return $baru;
    }

    /** `true` = ditulis oleh panggilan ini (boleh dihapus bila transaksi gagal). */
    private function simpanBerkasAsli(string $sumber, string $jalurDisk, string $sha, bool $kunciAplikasi = false): bool
    {
        $disk = Storage::disk(self::diskBerkas());

        if ($kunciAplikasi) {
            // Sumber tanpa sandi: dienkripsi kunci aplikasi, diverifikasi
            // dengan dekripsi + sha256 byte asli.
            if ($disk->exists($jalurDisk) && $this->shaTerenkripsiAplikasi($jalurDisk) === $sha) {
                return false;
            }

            $disk->put($jalurDisk, Crypt::encryptString((string) file_get_contents($sumber)));

            if ($this->shaTerenkripsiAplikasi($jalurDisk) !== $sha) {
                $disk->delete($jalurDisk);

                throw new RuntimeException('Salinan terenkripsi berkas asli tidak identik dengan sumbernya; impor dibatalkan.');
            }

            return true;
        }

        if ($disk->exists($jalurDisk) && $this->shaDisk($jalurDisk) === $sha) {
            return false;
        }

        $h = fopen($sumber, 'rb');

        if ($h === false) {
            throw new RuntimeException('Berkas asli tidak bisa dibuka untuk disalin.');
        }

        try {
            $tertulis = $disk->writeStream($jalurDisk, $h);
        } finally {
            if (is_resource($h)) {
                fclose($h);
            }
        }

        if ($tertulis !== true || $this->shaDisk($jalurDisk) !== $sha) {
            $disk->delete($jalurDisk);

            throw new RuntimeException('Salinan berkas asli di disk privat tidak identik dengan sumbernya; impor dibatalkan.');
        }

        return true;
    }

    private function shaTerenkripsiAplikasi(string $jalurDisk): ?string
    {
        try {
            return hash('sha256', Crypt::decryptString((string) Storage::disk(self::diskBerkas())->get($jalurDisk)));
        } catch (Throwable) {
            return null;
        }
    }

    private function shaDisk(string $jalurDisk): ?string
    {
        $h = Storage::disk(self::diskBerkas())->readStream($jalurDisk);

        if (! is_resource($h)) {
            return null;
        }

        try {
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $h);

            return hash_final($ctx);
        } finally {
            fclose($h);
        }
    }

    private function cariAda(Organization $organisasi, string $sha): ?WorkbookMaster
    {
        // Riwayat hasil `--ganti` (sha256_hidup NULL) bukan "isi yang sudah ada":
        // penggantinya yang ber-sha sama yang dijawab.
        return WorkbookMaster::query()
            ->where('organization_id', $organisasi->id)
            ->where('sha256', $sha)
            ->whereNotNull('sha256_hidup')
            ->first();
    }

    /**
     * @param  list<array<string, mixed>>  $lembar
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>|null  $acuan
     */
    private function ringkasan(string $status, string $nama, string $sumber, string $sha, ?string $kode, ?string $keluarga, array $lembar, ?WorkbookMaster $workbook, int $diganti = 0, string $kunci = '', ?int $menggantikan = null, ?string $petaSel = null, ?array $acuan = null): array
    {
        if ($lembar === [] && $workbook !== null) {
            $agregat = $workbook->lembar()
                ->selectRaw('count(*) as lembar, coalesce(sum(jumlah_sel),0) as sel, coalesce(sum(jumlah_rumus),0) as rumus, coalesce(sum(case when dikecualikan then 1 else 0 end),0) as dikecualikan')
                ->first();

            $jumlah = [
                'lembar' => (int) ($agregat->lembar ?? 0),
                'sel' => (int) ($agregat->sel ?? 0),
                'rumus' => (int) ($agregat->rumus ?? 0),
                'dikecualikan' => (int) ($agregat->dikecualikan ?? 0),
            ];
        } else {
            $jumlah = [
                'lembar' => count($lembar),
                'sel' => array_sum(array_column($lembar, 'jumlah_sel')),
                'rumus' => array_sum(array_column($lembar, 'jumlah_rumus')),
                'dikecualikan' => count(array_filter($lembar, static fn (array $l): bool => $l['dikecualikan'] === true)),
            ];
        }

        return [
            'status' => $status,
            'id' => $workbook?->id,
            'nama' => $nama,
            'sumber' => $sumber,
            'sha256' => $sha,
            'kode_profil' => $kode,
            'keluarga' => $keluarga,
            // Baris lama yang diekstrak ulang (atau, bila kering, AKAN) oleh --ganti.
            'diganti' => $diganti,
            'kunci_jenis' => $kunci,
            'versi' => $workbook?->versi,
            // aktif | riwayat | arsip (null bila kering: belum ada baris).
            'status_jenis' => $workbook !== null ? JenisWorkbook::status($workbook) : null,
            // Master aktif yang jadi riwayat karena impor ini (kering: yang AKAN).
            'menggantikan' => $menggantikan,
            // tervalidasi | belum_terpetakan (null: `sudah_ada`, tidak diperiksa ulang).
            'peta_sel' => $petaSel,
            // Draf data acuan dari master ini (DrafAcuanMaster::buat), null bila tidak berpeta.
            'acuan' => $acuan,
        ] + $jumlah;
    }
}
