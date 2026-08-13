<?php

namespace App\Services\Ocr;

use App\Models\Equipment;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;

/**
 * Definisi TEMPLATE OCR satu lembar kerja: daftar sel yang boleh dibaca dari
 * foto, kuncinya, dan aturan angka tiap sel.
 *
 * Diturunin OTOMATIS dari `CalibrationProfile::bentukLembarKerja()` — bukan
 * ditulis ulang per alat. Itu keputusan pokoknya: bentuk lembar kerja sudah
 * jadi satu-satunya sumber kebenaran buat layar input teknisi, dan kalau
 * template OCR-nya disalin jadi daftar kedua, dua daftar itu pasti berbeda
 * suatu hari — dan bedanya bakal muncul sebagai angka yang mendarat di baris
 * yang salah. Lab ini bakal punya sampai 48 jenis alat; nyalin 48 kali sama
 * dengan 48 kesempatan geser satu baris.
 *
 * Konsekuensinya, alat baru (profil ke-7, ke-8, ...) otomatis kebagian template
 * OCR-nya tanpa nyentuh file ini. Yang masih harus dibikin manual per alat cuma
 * satu: GEOMETRI-nya (koordinat sel di kertas), karena itu cuma bisa diukur dari
 * formulir cetak yang asli — lihat [geometri].
 *
 * Yang NGGAK ada di sini: piksel. Kelas ini nggak pernah nyentuh gambar.
 */
class TemplateLembarKerja
{
    public function __construct(private readonly CalibrationProfileRegistry $registry) {}

    /**
     * Template buat satu jenis alat, dari kode profilnya (`ph_meter`, ...).
     *
     * @param  int|null  $jumlahPengulangan  kalau teknisi ngecilin kolom Repeat di lembarnya
     * @return array<string, mixed>|null null = kode alatnya nggak dikenal
     */
    public function untukKode(
        string $kode,
        ?Equipment $alat = null,
        ?int $jumlahPengulangan = null,
    ): ?array {
        $profil = $this->registry->untukKode($kode);

        if ($profil === null) {
            return null;
        }

        return $this->dariProfil($profil, $alat, $jumlahPengulangan);
    }

    /**
     * Semua template yang dikenal sistem, ringkas — buat layar "pilih lembar
     * kerja" di HP & buat ngecek mana yang geometrinya belum diukur.
     *
     * @return list<array<string, mixed>>
     */
    public function daftar(): array
    {
        $hasil = [];

        foreach ($this->registry->semua() as $profil) {
            $template = $this->dariProfil($profil);

            $hasil[] = [
                'template_id' => $template['template_id'],
                'versi' => $template['versi'],
                'kode_dokumen' => $template['kode_dokumen'],
                'judul' => $template['judul'],
                'jumlah_sel' => count($template['sel']),
                'siap_pindai' => $template['siap_pindai'],
                'alasan_belum_siap' => $template['alasan_belum_siap'],
            ];
        }

        return $hasil;
    }

    /**
     * @return array<string, mixed>
     */
    public function dariProfil(
        CalibrationProfile $profil,
        ?Equipment $alat = null,
        ?int $jumlahPengulangan = null,
    ): array {
        $bentuk = $profil->bentukLembarKerja(false, $alat);

        if ($jumlahPengulangan !== null) {
            $bentuk = CalibrationProfile::setelKolomPengulangan($bentuk, $jumlahPengulangan);
        }

        $geometri = $this->geometri($profil->kode());
        $tabel = [];
        $sel = [];

        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $definisiTabel) {
                $satu = $this->tabel($profil, $alat, $definisiTabel);
                $tabel[] = $satu['tabel'];
                $sel = [...$sel, ...$satu['sel']];
            }
        }

        [$siap, $alasanBelumSiap] = $this->kesiapan($geometri, array_keys($sel));

        return [
            'template_id' => $profil->kode(),
            // Versi template = versi FORMULIR CETAKNYA, bukan versi kode. Yang
            // bikin sel geser itu revisi kertas, dan itu yang harus dicocokin
            // sama QR di lembar yang lagi difoto.
            'versi' => $geometri['versi'] ?? 1,
            'kode_dokumen' => $bentuk['kode_dokumen'] ?? null,
            'judul' => $bentuk['judul'] ?? null,
            'satuan' => $bentuk['satuan'] ?? null,
            'jumlah_pengulangan' => $bentuk['jumlah_pengulangan'] ?? null,
            'tabel' => $tabel,
            'sel' => $sel,
            'jangkar' => $geometri['jangkar'] ?? [],
            'geometri' => $geometri,
            'siap_pindai' => $siap,
            'alasan_belum_siap' => $alasanBelumSiap,
            'pipeline_versi' => (string) config('ocr.pipeline_versi'),
            'aturan_versi' => (string) config('ocr.aturan_versi'),
        ];
    }

    /**
     * Satu tabel hasil (Before/After adjustment, atau satu kelompok filter buat
     * Spectrophotometer) → daftar sel berkunci.
     *
     * @param  array<string, mixed>  $definisi
     * @return array{tabel: array<string, mixed>, sel: array<string, array<string, mixed>>}
     */
    private function tabel(CalibrationProfile $profil, ?Equipment $alat, array $definisi): array
    {
        // IDENTITAS tabel bukan `tahap` doang. Spectrophotometer punya tiga
        // tabel yang `tahap`-nya SAMA (`sesudah_adjustment`) dan cuma dibedain
        // `grup` — kalau `tahap` yang dipakai sebagai kunci, tiga tabel itu
        // saling nimpa dan angka blok %T mendarat di blok absorbansi.
        $tabelId = (string) ($definisi['grup'] ?? $definisi['tahap'] ?? 'hasil');

        $baris = $this->barisTabel($definisi);
        $pengulangan = array_values($definisi['pengulangan'] ?? []);
        $sel = [];
        $barisRingkas = [];

        foreach ($baris as $i => $b) {
            $barisKe = $i + 1;
            $titik = (float) $b['titik_ukur'];
            $resolusi = $this->resolusi($profil, $alat, $b, $titik);
            $desimal = $this->desimal($profil, $b, $titik, $resolusi);
            $satuan = $b['satuan'] ?? $definisi['satuan'] ?? null;

            $barisRingkas[] = [
                'baris_ke' => $barisKe,
                'titik_ukur' => $titik,
                'label' => $b['label'] ?? null,
                'standard_id' => $b['standard_id'] ?? null,
                'satuan' => $satuan,
                'resolusi' => $resolusi,
                'desimal' => $desimal,
            ];

            foreach ($definisi['kolom'] ?? [] as $kolom) {
                $fieldId = (string) ($kolom['kode'] ?? '');

                if ($fieldId === '') {
                    continue;
                }

                foreach ($pengulangan as $repeat) {
                    $kunci = $this->kunci($tabelId, $barisKe, (int) $repeat, $fieldId);

                    $sel[$kunci] = [
                        'kunci' => $kunci,
                        'tabel_id' => $tabelId,
                        'tahap' => (string) ($definisi['tahap'] ?? 'sesudah_adjustment'),
                        'grup' => $definisi['grup'] ?? null,
                        'baris_ke' => $barisKe,
                        'repeat_no' => (int) $repeat,
                        'field_id' => $fieldId,
                        // Dua ini BUKAN bagian kunci — dia bukti pembanding.
                        // Kalau HP ngirim titik_ukur/standard_id yang beda dari
                        // template, itu tanda APK-nya megang lembar versi lain,
                        // dan seluruh pemetaan harus dibatalin. Kalau dijadiin
                        // kunci, ketidakcocokannya malah jadi kunci baru yang
                        // "nggak ketemu" — gejalanya jauh lebih samar.
                        'titik_ukur' => $titik,
                        'standard_id' => $b['standard_id'] ?? null,
                        'aturan' => $fieldId === 'suhu'
                            ? $this->aturanSuhu()
                            : $this->aturanPembacaan($titik, $resolusi, $desimal, $satuan),
                    ];
                }
            }
        }

        return [
            'tabel' => [
                'tabel_id' => $tabelId,
                'tahap' => (string) ($definisi['tahap'] ?? 'sesudah_adjustment'),
                'grup' => $definisi['grup'] ?? null,
                'judul' => $definisi['judul'] ?? null,
                'baris' => $barisRingkas,
                'kolom' => array_values(array_map(
                    static fn (array $k): array => [
                        'field_id' => $k['kode'] ?? null,
                        'label' => $k['label'] ?? null,
                        'satuan' => $k['satuan'] ?? null,
                    ],
                    $definisi['kolom'] ?? [],
                )),
                'pengulangan' => array_map('intval', $pengulangan),
            ],
            'sel' => $sel,
        ];
    }

    /**
     * Baris tabel, dari bentuk mana pun yang dipakai profilnya.
     *
     * `baris_per_satuan` dipakai Conductivity waktu alat pelanggannya belum
     * dikenal (satu titik dikirim dua versi satuan). Diratakan jadi satu daftar
     * di sini — urutannya tetap urutan aslinya, jadi `baris_ke` tetap cocok sama
     * yang digambar layar.
     *
     * @param  array<string, mixed>  $definisi
     * @return list<array<string, mixed>>
     */
    private function barisTabel(array $definisi): array
    {
        if (isset($definisi['baris']) && is_array($definisi['baris'])) {
            return array_values($definisi['baris']);
        }

        $rata = [];

        foreach ($definisi['baris_per_satuan'] ?? [] as $satuan => $barisSatuan) {
            foreach ($barisSatuan as $b) {
                $rata[] = [...$b, 'satuan' => $b['satuan'] ?? $satuan];
            }
        }

        return $rata;
    }

    /**
     * Kunci sel — SATU-SATUNYA cara sel dirujuk di seluruh pipeline.
     *
     * Nggak ada satu pun tahap yang boleh mengandalkan urutan array: hasil OCR
     * dari HP boleh dateng dalam urutan apa pun, dan tetap mendarat di sel yang
     * sama.
     */
    public function kunci(string $tabelId, int $barisKe, int $repeatNo, string $fieldId): string
    {
        return "{$tabelId}|{$barisKe}|{$repeatNo}|{$fieldId}";
    }

    /**
     * @param  array<string, mixed>  $baris
     */
    private function resolusi(CalibrationProfile $profil, ?Equipment $alat, array $baris, float $titik): ?float
    {
        $resolusi = $baris['resolusi']
            ?? $profil->resolusiTitik($titik)
            ?? ($alat?->resolusi === null ? null : (float) $alat->resolusi);

        return $resolusi === null ? null : (float) $resolusi;
    }

    /**
     * @param  array<string, mixed>  $baris
     */
    private function desimal(CalibrationProfile $profil, array $baris, float $titik, ?float $resolusi): ?int
    {
        $desimal = $baris['desimal'] ?? $profil->desimalTitik($titik);

        if ($desimal !== null) {
            return (int) $desimal;
        }

        if ($resolusi === null || $resolusi <= 0) {
            return null;
        }

        // Turunin dari resolusi: 0,01 → 2 desimal. Dipakai kalau profilnya nggak
        // nyebut sendiri (alat bersatuan seragam).
        $desimal = (int) max(0, -floor(log10($resolusi) + 1e-9));

        return min($desimal, 8);
    }

    /**
     * Aturan angka buat kolom pembacaan di satu titik.
     *
     * @return array<string, mixed>
     */
    private function aturanPembacaan(float $titik, ?float $resolusi, ?int $desimal, ?string $satuan): array
    {
        $pitaResolusi = $resolusi === null
            ? null
            : $resolusi * (float) config('ocr.angka.pita_kelipatan_resolusi', 20);
        $pitaPersen = abs($titik) * (float) config('ocr.angka.pita_persen', 0.10);

        $delta = max($pitaResolusi ?? 0.0, $pitaPersen);

        if ($delta <= 0.0) {
            // Nggak ada resolusi & titiknya nol — nggak ada dasar buat mita
            // rentang. Lebih baik nggak punya batas daripada punya batas ngarang
            // yang nolak angka benar.
            $delta = null;
        }

        return [
            'tipe' => 'desimal',
            'satuan' => $satuan,
            'nominal' => $titik,
            'resolusi' => $resolusi,
            'desimal' => $desimal,
            'min' => $delta === null ? null : max(0.0, $titik - $delta),
            'maks' => $delta === null ? null : $titik + $delta,
            'rasio_min' => (float) config('ocr.angka.rasio_min', 0.5),
            'rasio_maks' => (float) config('ocr.angka.rasio_maks', 2.0),
            'maks_digit' => (int) config('ocr.angka.maks_digit', 9),
            // Pembacaan alat di lembar-lembar ini nggak pernah negatif. Koreksi
            // yang negatif itu HASIL HITUNG, bukan yang ditulis teknisi.
            'izinkan_minus' => false,
            // Sel kosong SELALU sah — lihat `LembarKerjaTemplate`: nggak ada
            // kolom yang nahan tombol kirim.
            'boleh_kosong' => true,
            'tulisan_tangan' => (bool) config('ocr.tulisan_tangan.aktif', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aturanSuhu(): array
    {
        return [
            'tipe' => 'suhu',
            'satuan' => '°C',
            'nominal' => null,
            'resolusi' => (float) config('ocr.suhu.resolusi', 0.1),
            'desimal' => (int) config('ocr.suhu.desimal', 1),
            'min' => (float) config('ocr.suhu.min', 5),
            'maks' => (float) config('ocr.suhu.maks', 45),
            'rasio_min' => null,
            'rasio_maks' => null,
            'maks_digit' => 4,
            'izinkan_minus' => false,
            'boleh_kosong' => true,
            'tulisan_tangan' => (bool) config('ocr.tulisan_tangan.aktif', true),
        ];
    }

    /**
     * Geometri = koordinat tiap sel di lembar CETAK, hasil ukur dari formulir
     * asli. Disimpen sebagai file JSON per template per versi.
     *
     * Kenapa file, bukan diturunin dari kode: tinggi baris & lebar kolom di
     * kertas nggak bisa ditebak dari struktur data. Salah tebak = seluruh grid
     * geser, dan itu persis kegagalan yang paling mahal di fitur ini.
     *
     * Belum ada filenya = template ini belum bisa dipindai (`siap_pindai`
     * false). Sengaja: lebih baik tombol kameranya mati daripada nyala dengan
     * koordinat karangan.
     *
     * @return array<string, mixed>|null
     */
    public function geometri(string $templateId): ?array
    {
        $berkas = glob(self::folderTemplate().'/'.$templateId.'-v*.json') ?: [];

        if ($berkas === []) {
            return null;
        }

        // Versi tertinggi yang ada — formulir yang paling baru dicetak.
        rsort($berkas, SORT_NATURAL);

        $isi = json_decode((string) file_get_contents($berkas[0]), true);

        return is_array($isi) ? $isi : null;
    }

    /**
     * Folder file geometri. Path relatif dihitung dari `database/`; path absolut
     * dipakai apa adanya — supaya lab yang naruh dokumen mutunya di luar repo
     * (dan test yang pakai fixture sendiri) nggak perlu nyentuh kode.
     */
    public static function folderTemplate(): string
    {
        $folder = (string) config('ocr.folder_template', 'ocr-templates');

        return str_starts_with($folder, '/') ? $folder : database_path($folder);
    }

    /**
     * @param  array<string, mixed>|null  $geometri
     * @param  list<string>  $kunciSel
     * @return array{0: bool, 1: string|null}
     */
    private function kesiapan(?array $geometri, array $kunciSel): array
    {
        if ($geometri === null) {
            return [false, 'geometri_belum_diukur'];
        }

        if (($geometri['terverifikasi'] ?? false) !== true) {
            // File geometrinya ada tapi belum diadu ke formulir cetak asli.
            // Angka di file itu masih rancangan, dan rancangan nggak boleh jadi
            // dasar naruh angka di sertifikat.
            return [false, 'geometri_belum_diverifikasi'];
        }

        $kotak = [];

        foreach ($geometri['tabel'] ?? [] as $tabel) {
            $kotak = [...$kotak, ...array_keys($tabel['sel'] ?? [])];
        }

        $kurang = array_diff($kunciSel, $kotak);

        if ($kurang !== []) {
            // Ada sel di lembar kerja yang nggak punya kotak koordinat. Kalau
            // dibiarin, sel itu diam-diam nggak pernah kebaca — dan sel yang
            // hilang tanpa suara jauh lebih berbahaya daripada scan yang ditolak.
            return [false, 'geometri_kurang_'.count($kurang).'_sel'];
        }

        return [true, null];
    }
}
