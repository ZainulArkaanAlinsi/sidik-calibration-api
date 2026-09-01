<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\GumCalculator;
use Illuminate\Support\Collection;

/**
 * Satu **profil kalibrasi** = satu jenis alat (pH Meter, Turbidimeter, ...),
 * ditulis sebagai SATU file yang berdiri sendiri.
 *
 * Kenapa ada: lab bakal nambah sampai 48 jenis alat, dan tiap jenis punya
 * bentuk lembar kerja, titik standar, satuan, dan budget ketidakpastian yang
 * beda. Sebelum ini semuanya di-hardcode khusus pH di `LembarKerjaTemplate` &
 * `GumCalculator` — nambah alat berarti nyisipin `if besaran==...` di kelas
 * bersama, dan dalam lima alat aja itu udah nggak kebaca.
 *
 * Dengan profil: nambah alat ke-3..48 = bikin SATU subclass di folder ini +
 * satu seeder CMC-nya. Kelas bersama (`GumCalculator::agregasiBudget`, jalur
 * CMC, `PerhitunganBuilder`) nggak disentuh lagi.
 *
 * Yang TETAP di kelas bersama, bukan di profil: agregasi budget (Uc, Welch–
 * Satterthwaite, k dari t-student), lantai CMC, dan keputusan PASS/FAIL. Itu
 * aturan GUM yang sama buat semua alat — cuma DAFTAR KOMPONEN-nya yang beda,
 * dan itulah yang diserahin ke [komponenBudget].
 */
abstract class CalibrationProfile
{
    /** Kode stabil profil, mis. `ph_meter` / `turbidimeter`. Dipakai routing & log. */
    abstract public function kode(): string;

    /**
     * Nama jenis alat seperti yang tercatat di `equipments.nama_alat_kemampuan`
     * dan `calibration_capabilities.nama_alat` (mis. `pH Meter`, `Turbidimeter`).
     * Ini kunci yang dipakai [CalibrationProfileRegistry] buat nyocokin alat ke
     * profilnya.
     */
    abstract public function namaAlatKemampuan(): string;

    /**
     * Ejaan LAIN nama alat ini yang beneran muncul di data, di luar
     * [namaAlatKemampuan] — dipakai [CalibrationProfileRegistry::kodeProfilDariNama]
     * buat nebak lembar kerja dari teks bebas (`nama_alat` lampiran akreditasi,
     * `nama_alat` alat pelanggan, judul lembar kerja).
     *
     * Kenapa nempel di profil, bukan jadi satu tabel terjemahan terpisah: nambah
     * alat ke-17 itu bikin SATU file di folder ini, dan tabel terpisah adalah
     * tempat yang pasti kelupaan diisi. Ejaan alat hidup bareng profilnya.
     *
     * Isinya cuma ejaan yang PUNYA BUKTI di data (baris CMC, master Excel, atau
     * kolom `equipments.nama_alat`), bukan tebakan — tiap alias ditulis berikut
     * alasannya di profil masing-masing. Dan JANGAN isi singkatan pendek:
     * pencocokannya nerima kunci yang nempel di tengah nama, jadi "TITS" bakal
     * diam-diam ngasih lembar suhu ke alat lain yang kebetulan memuatnya.
     *
     * Default kosong — profil yang cuma punya satu ejaan nggak perlu override.
     *
     * @return list<string>
     */
    public function aliasNama(): array
    {
        return [];
    }

    /** Kode Formula GUM buat besaran ini (`Formula::KODE_GUM_*`). */
    abstract public function kodeFormula(): string;

    /** Nama besaran buat metadata Formula (`ph`, `turbidity`, ...). */
    abstract public function besaran(): string;

    /**
     * Resolusi alat buat satu titik ukur, kalau alatnya beda resolusi per titik
     * (mis. Turbidimeter 0,01/0,1/1). `null` = resolusi seragam — pemakai jatuh
     * ke `equipments.resolusi` yang tunggal. Default null; profil yang perlu
     * override.
     */
    public function resolusiTitik(float $titikUkur): ?float
    {
        return null;
    }

    /**
     * Jumlah desimal buat nampilin nilai di titik ini (turunan resolusi). Dikirim
     * ke lembar perhitungan & sertifikat biar angkanya dipad ke resolusi tanpa
     * buang nol belakang (4,60 tetap 4,60). Default null.
     */
    public function desimalTitik(float $titikUkur): ?int
    {
        return null;
    }

    /**
     * Satuan yang berlaku DI TITIK ini. `null` = alatnya bersatuan seragam,
     * pemanggil jatuh ke `equipments.satuan` yang tunggal.
     *
     * Kebanyakan alat nggak perlu override: pH selalu pH, Turbidimeter selalu
     * NTU. Yang butuh cuma alat yang nyampur satuan dalam satu lembar —
     * Conductivity baca 25 & 1412 dalam µS/cm tapi 111 dalam mS/cm, dan
     * ambang pindahnya beda-beda per alat pelanggan.
     *
     * Ada di kelas induk (bukan cuma di profil Conductivity) supaya pemanggil
     * bersama — `CalibrationResource`, sertifikat, lembar perhitungan — bisa
     * nanya satu cara buat semua alat, tanpa `if (alat == conductivity)`.
     */
    public function satuanTitik(float $titikUkur, ?Equipment $equipment = null): ?string
    {
        return null;
    }

    /**
     * Keterangan kolom "Remark" buat titik ini — nama parameter atau judul
     * kelompoknya. `null` = titiknya nggak punya keterangan, kolomnya kosong.
     *
     * Dipakai dua arah: sertifikat ngelompokin barisnya lewat kolom ini
     * (Chlorine: `Free Chlorine` vs `Total Chlorine`; Spectrophotometer: tiga
     * blok filter), dan layar riwayat/approval butuh label yang SAMA biar tabel
     * di HP nggak beda dari PDF-nya.
     *
     * Ada di kelas induk — bukan cuma di dua profil yang ngisi — supaya
     * pemanggilnya bisa nanya satu cara buat semua alat. Sebelumnya
     * `CertificateSnapshotBuilder` kepaksa `method_exists()`, yang artinya
     * salah ketik nama method di profil baru nggak bakal ketahuan: kolomnya
     * cuma kosong diam-diam.
     */
    public function remarkTitik(float $titikUkur): ?string
    {
        return null;
    }

    /**
     * Baris keterangan yang dicetak DI ATAS tabel `CALIBRATION REPORT`, sebelum
     * kepala kolom Standard/UUT/Correction — beda dari `catatan` di snapshot
     * (dua baris baku DI BAWAH tabel) dan beda dari [remarkTitik] (per baris,
     * bukan sekali per sertifikat). `null` = alat ini nggak punya apa-apa buat
     * dicetak di situ, dan barisnya nggak muncul sama sekali — sertifikat lima
     * alat lain nggak berubah bentuk.
     *
     * Session-aware (bukan cuma `float $titikUkur` kayak [remarkTitik]) karena
     * isinya bisa beda tiap sesi — Viscometer override ini buat `Spindel No. :
     * 1,2,7` / `Speed (rpm) : 63,62,62`, dan spindle/rpm yang dipakai beda tiap
     * kali alat ini dikalibrasi. Lihat `SERTIFIKAT.csv` baris 18.
     */
    public function catatanAtasTabelHasil(CalibrationSession $sesi): ?string
    {
        return null;
    }

    /**
     * Koefisien determinasi (R²) satu KELOMPOK titik tercetak — kolom `R2` di
     * blok `%T` sertifikat Spectrophotometer. `null` = alat ini nggak nyetak
     * kolom itu, dan kolomnya nggak muncul sama sekali (bukan muncul kosong).
     *
     * ## Kenapa se-kelompok, bukan per titik kayak `remarkTitik()`
     *
     * R² itu pernyataan tentang SEKUMPULAN titik: seberapa rapat pasangan
     * (nilai standar, pembacaan alat) jatuh di satu garis. Satu titik nggak
     * punya R², dan nanya "R² titik ke-3 berapa" itu pertanyaan yang nggak ada
     * jawabannya. Makanya yang masuk seluruh baris kelompoknya sekaligus,
     * persis kayak U95 & faktor cakupan yang juga lahir per kelompok.
     *
     * Barisnya dateng udah dikelompokkan sama pemanggilnya (`remark` yang sama
     * = satu tabel tercetak), dan urutannya urutan cetak.
     *
     * @param  list<array{standard_value: float|null, unit_under_test: float|null}>  $baris
     */
    public function koefisienDeterminasi(array $baris): ?float
    {
        return null;
    }

    /**
     * Koreksi negatif yang MEMBULAT KE NOL dicetak pakai tanda minus atau nggak.
     *
     * Ini murni soal cetak — nilai mentahnya nggak kena sama sekali, dan
     * PASS/FAIL tetap diadu ke `koreksi` asli.
     *
     * Bukan aturan metrologi, melainkan format sel di master masing-masing
     * alat, dan masternya emang beda satu sama lain:
     *
     *  - Turbidimeter `0189-CAL-624` nyetak `-0,00` (koreksi -0,004) dan `-0,0`
     *    (koreksi -0,02) — diadu ke kertasnya langsung 10 Agt 2026. pH &
     *    Chlorine ikut pola yang sama.
     *  - Master Conductivity nyimpen angka yang SAMA PERSIS kayak kita
     *    (`SERTIFIKAT STYLE 1` baris 35: `25` · `25.04` · `-0.03999999999999915`)
     *    tapi nyetaknya `0,0`, tanpa minus. Master Spectrophotometer sama:
     *    koreksi titik 0 %T & 100 %T dua-duanya negatif, dua-duanya kecetak
     *    `0,0`.
     *
     * Jadi jawabannya nggak bisa diturunkan dari nalar "tanda minus itu
     * informasi" — dua dokumen resmi lab jawabnya beda buat angka yang sama.
     * Default `true` (tanda dipertahankan) karena itu yang berlaku di empat
     * dari enam alat; yang beda cuma override sendiri.
     */
    public function tandaNolDicetak(): bool
    {
        return true;
    }

    /**
     * Peringatan khas alat ini buat SATU sesi, di luar aturan umum validator.
     *
     * Balikin daftar `[kode, pesan]`; `CalibrationValidator` yang mbungkus jadi
     * temuan tingkat PERINGATAN — nahan approve sekali, dan admin boleh lanjut
     * secara sadar. Bukan ERROR: yang diperingatin di sini hal yang mungkin
     * benar, bukan yang pasti salah.
     *
     * Ada di sini supaya validator bersama nggak perlu tau nama alat mana pun.
     * Alat yang nggak punya peringatan khusus nggak override apa-apa.
     *
     * @return list<array{kode: string, pesan: string}>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        return [];
    }

    /**
     * Apa larutan/standar acuan alat ini PUNYA kurva suhu (nilai acuannya
     * bergeser ikut suhu larutan, kayak buffer pH)?
     *
     * Dipakai [\App\Services\CalibrationValidator] buat mbedain dua hal yang di
     * tabel `standards` kelihatan sama persis — `koefisien_suhu` NULL:
     *
     *  - **data kurvanya belum diisi** → suhu yang susah-susah dicatat teknisi
     *    kebuang percuma, pantas diperingatin; dan
     *  - **standarnya emang nggak berkurva** (turbidity, chlorine) → NULL itu
     *    jawaban yang benar, bukan data yang bolong.
     *
     * Default `true` karena profil pertama (pH) emang berkurva. Profil yang
     * standarnya dibaca nominal apa adanya override jadi `false` — kalau nggak,
     * tiap sertifikatnya ke-flag `valid: false` gara-gara temuan yang sebenernya
     * perilaku yang diharapkan.
     */
    public function standarBerkurvaSuhu(): bool
    {
        return true;
    }

    /**
     * Nama unit pemanas yang KODE-nya tersimpan di
     * `calibration_sessions.alat_bantu`.
     *
     * Kolomnya menyimpan kode (`A`, `B`, `satu`, `dua`), bukan nama — dan kode
     * itu cuma punya arti di daftar `pilihan` yang profil ini sendiri yang
     * menulisnya. Layar detail admin menampilkan pilihan teknisi supaya bisa
     * diadu dengan lembar cetaknya; tanpa hook ini yang tampil huruf `A`, yang
     * sama nggak berartinya dengan tidak menampilkan apa pun.
     *
     * Diresolusi DI SINI, bukan di sisi HP, dan itu bukan selera. Unit yang
     * dipilih menentukan dua komponen budget, dan daftarnya bertambah begitu
     * lab beli dryblock baru. Peta kode→nama yang disalin ke HP bakal
     * ketinggalan diam-diam: kode baru tampil mentah, nol error, dan yang
     * membaca layar itu justru orang yang lagi memutuskan menerbitkan
     * sertifikat.
     *
     * Default null — tujuh belas alat lain nggak punya alat bantu, dan
     * memaksakan mereka mengisi hook ini cuma bikin belasan override kosong.
     */
    public function labelAlatBantu(?string $kode): ?string
    {
        return null;
    }

    /**
     * Bentuk lembar kerja (struktur bagian/field/tabel) — yang dulu di
     * `LembarKerjaTemplate::phMeter()`. Dibaca langsung layar input mobile.
     *
     * @return array<string, mixed>
     */
    abstract public function bentukLembarKerja(bool $untukAdmin = false, ?Equipment $equipment = null): array;

    /**
     * Bentuk KERTASNYA buat pindai foto — bukan isinya.
     *
     * Dikirim ikut bentuk lembar kerja supaya mobile tinggal meneruskannya ke
     * `POST /raw-measurements/extract-from-photo` tanpa perlu hafal alat mana
     * yang kertasnya bentuknya beda.
     *
     *  - `kolom_suhu`: tiap sel isinya sepasang angka (pembacaan + suhu °C).
     *  - `standar_di_baris`: standarnya turun ke bawah & Repeat berjajar ke
     *    kanan (kebalikan lembar pH).
     *
     * Default-nya bentuk lembar pH karena lima profil pertama semuanya begitu.
     * Yang override cuma yang kertasnya beneran beda — dan bedanya bukan soal
     * rapi-rapian: prompt & skema JSON yang dikirim ke model dibangun dari dua
     * penanda ini, jadi salah nilai berarti model diminta membaca kolom suhu
     * yang nggak pernah ada di kertasnya.
     *
     *  - `didukung`: kertasnya masih muat di bentuk yang bisa dituturkan ke
     *    pembaca foto. `false` = jalur pindai AI DITOLAK buat alat ini, bukan
     *    dijalankan dengan bentuk yang salah.
     *
     * Kenapa `didukung` perlu ada padahal dua penanda di atas kelihatan cukup:
     * dua-duanya cuma bisa menggambarkan lembar "titik ukur × Repeat". Lembar
     * Autoklaf bentuknya matriks — tujuh baris besaran campur (suhu, tekanan,
     * jam) × lima titik waktu — dan nggak ada kombinasi `kolom_suhu` /
     * `standar_di_baris` yang menggambarkannya. Tanpa penanda ketiga, lembar
     * itu diam-diam diperlakukan sebagai lembar pH: model diminta membaca tabel
     * yang nggak pernah ada di kertasnya, dan yang balik ke teknisi cuma angka
     * ngawur yang kelihatan wajar.
     *
     * ## `didukung` vs `lokal` — DUA gerbang, dua akibat yang beda
     *
     * Keduanya menjawab pertanyaan yang berbeda, dan menyatukannya pernah
     * melebarkan batas data tanpa ada yang berniat begitu (27 Agt 2026, waktu
     * TIDS dinyalakan):
     *
     *  - **`didukung`** menggerbangi `POST /raw-measurements/extract-from-photo`
     *    — jalur AI Vision CLOUD, yang **mengirim foto lembar kerja pelanggan ke
     *    layanan pihak ketiga** (Gemini/Anthropic). Dia menjawab: "kertas alat
     *    ini muat di bentuk `titik ukur × Repeat` yang bisa dituturkan lewat dua
     *    penanda di atas?" Menyetelnya `true` **melebarkan batas data**, jadi
     *    dia cuma boleh naik kalau jawabannya beneran ya.
     *  - **`lokal`** menggerbangi tombol `FOTO TABEL INI` di aplikasi — ML Kit,
     *    **sepenuhnya di perangkat**, citranya tidak pernah keluar HP. Dia
     *    menjawab pertanyaan yang jauh lebih longgar: "pemeta di HP bisa
     *    menjangkar baris & kolom kertas ini?" Kertas yang barisnya dijangkar
     *    TULISAN (`Set point 1`, `Temp. Disk 1`) memenuhi yang kedua tanpa
     *    memenuhi yang pertama.
     *
     * Bawaannya `lokal` mengikuti `didukung`, jadi tujuh belas profil yang tidak
     * menyebutnya tidak berubah perilakunya. Yang perlu memisahkannya cuma
     * profil yang jalur lokalnya hidup sementara bentuk dua-penandanya tidak —
     * dan profil begitu wajib menyebut **dua-duanya**, supaya pilihan itu
     * tertulis, bukan tersirat.
     *
     * @return array{kolom_suhu: bool, standar_di_baris: bool, didukung?: bool, lokal?: bool}
     */
    public function bentukPindaiFoto(): array
    {
        return ['kolom_suhu' => true, 'standar_di_baris' => false, 'didukung' => true, 'lokal' => true];
    }

    /**
     * Pasangan TERCETAK "titik ukur → larutan standarnya", persis kayak di
     * formulir kertas: baris 7,00 di lembar pH cuma boleh diisi pakai pH Buffer
     * Solution 7, nggak pernah yang lain.
     *
     * Kunci `standar` isinya kunci pencocokan yang sama dipakai
     * `STANDARD_TERCETAK` (nama master ATAU serial), jadi satu sumber kebenaran
     * — bukan daftar kedua yang bisa jalan sendiri.
     *
     * Kenapa ada: sebelum ini titik ukur nggak punya pasangan sama sekali.
     * Teknisi milih sendiri dari dropdown berisi SELURUH master standar, satu
     * dropdown per titik. Salah pilih nggak keliatan salah — sampai muncul di
     * sertifikat: sesi pH 7 Agt 2026 kepilih Buffer 4 di titik 7,00, dan kolom
     * Correction-nya kecetak `-2,99` (= 4,0092 − 7,00) di antara dua angka yang
     * wajar. Pasangannya emang nggak pernah jadi keputusan teknisi; itu
     * tercetak di formulirnya.
     *
     * Balikin `[]` = profil ini nggak punya pasangan tetap, dan layar jatuh ke
     * pilihan manual kayak dulu.
     *
     * @return list<array{titik: float, standar: list<string>}>
     */
    public function standarPerTitik(): array
    {
        return [];
    }

    /**
     * Sedekat apa `titik_ukur` boleh meleset dari nilai di [standarPerTitik]
     * dan masih dianggap titik yang sama.
     *
     * Bukan `===`: titik chlorine kesimpen sebagai nilai standar SESUDAH
     * koreksi suhu (1,7401, bukan 1,74). Dipakai relatif ke nilai titik supaya
     * satu angka ini kepakai baik di 1,74 mg/L maupun 1000 NTU.
     */
    protected const TOLERANSI_PASANGAN_TITIK = 0.02;

    /**
     * Stempel `standard_id` ke tiap baris tabel hasil, dari pasangan tercetak
     * [standarPerTitik]. Dipanggil profil sesudah bentuknya jadi.
     *
     * Yang nggak ketemu pasangannya dibiarin `null` — layar bakal nawarin
     * pilihan manual buat titik itu doang, bukan buat semuanya. Standar yang
     * kepasang tapi belum keseed di master juga `null`, dengan alasan yang sama
     * kayak `tautkanStandar`: baris yang hilang lebih berbahaya daripada baris
     * yang belum ketaut.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    /**
     * Master `standards` yang boleh ditautkan ke lembar alat ini.
     *
     * SATU PINTU buat kedua belas profil. Sebelum ini tiap profil menyalin
     * query yang sama, dan salinannya sama-sama kehilangan dua hal:
     *
     * **1. Saringan organisasi.** Baris standar tercetak bisa tertaut ke master
     * milik lab LAIN — dan yang ikut ke layar (lalu berpotensi ke sertifikat)
     * nomor sertifikat & ketertelusuran lab itu. Buat lab terakreditasi,
     * ketertelusuran yang menunjuk dokumen milik orang lain itu temuan audit
     * yang paling mahal jenisnya. Lebih jauh: `standard_id` yang bocor ikut
     * dipakai menurunkan kalibrator sesi, jadi teknisi lab kedua bakal ditolak
     * sistem dengan pesan menyebut kolom yang nggak pernah dia ketik.
     *
     * **2. Prioritas nama atas serial** — lihat [cocokkanStandar].
     *
     * > **`$equipment` null berarti TANPA saringan organisasi — dan barisnya
     * > membawa lebih dari label.** `when(false, ...)` tidak memasang `where`
     * > apa pun, jadi yang pulang seluruh baris `standards` milik SEMUA lab,
     * > lengkap dengan `no_sertifikat`, `tertelusur_ke`, `serial_number`, dan
     * > `id` yang bisa diklik di dropdown. Pemanggil HTTP WAJIB menyertakan
     * > konteks organisasi: `CalibrationController::lembarKerja()` memakai alat
     * > semu ber-`organization_id` pemanggil waktu `equipment_id` tidak
     * > dikirim. Dijaga `LembarKerjaTidakBocorLintasLabTest`.
     * >
     * > Null tetap diterima buat uji BENTUK lembar — di sana cuma ada satu
     * > organisasi, jadi tidak ada yang bisa bocor ke mana pun.
     *
     * @return Collection<int, Standard>
     */
    protected function masterStandarTertaut(?Equipment $equipment): Collection
    {
        return Standard::query()
            ->whereNull('parameter_kondisi')
            ->when(
                $equipment?->organization_id !== null,
                fn ($q) => $q->where('organization_id', $equipment->organization_id),
            )
            ->get(['id', 'nama', 'merk', 'serial_number', 'no_sertifikat', 'tertelusur_ke']);
    }

    /**
     * Unit THERMOHYGRO milik lab pemilik alat — isian dropdown
     * "Environmental Meter Used".
     *
     * Kembarannya [masterStandarTertaut], dan saringannya kebalikan:
     * thermohygro justru DIKENALI dari `parameter_kondisi` yang terisi (di
     * situlah koreksi suhu/kelembapannya disimpan).
     *
     * Saringan organisasinya sama pentingnya. Dropdown yang menawarkan
     * termohigrometer milik lab lain bukan cuma salah pilihan: `standard_id`
     * yang kepilih masuk ke sesi, koreksi kondisi lingkungannya dibaca dari
     * sertifikat lab itu, dan angkanya kecetak di sertifikat lab ini. Dan
     * `$equipment` null mematikan saringan itu — lihat peringatan di
     * [masterStandarTertaut].
     *
     * @return Collection<int, Standard>
     */
    protected function masterThermohygro(?Equipment $equipment, array $kolom = ['id', 'nama', 'parameter_kondisi']): Collection
    {
        return Standard::query()
            ->whereNotNull('parameter_kondisi')
            ->when(
                $equipment?->organization_id !== null,
                fn ($q) => $q->where('organization_id', $equipment->organization_id),
            )
            ->get($kolom);
    }

    /**
     * Cocokkan satu baris standar tercetak ke master: **NAMA dulu, serial
     * belakangan.**
     *
     * Urutan ini bukan kosmetik, dan pelajarannya sudah dibayar dua kali:
     *
     * - Empat botol gas Rigas di master Gas Detector ber-S/N `WO0125576` yang
     *   SAMA (satu order pengisian, empat campuran). Pencarian yang menerima
     *   serial lebih dulu memulangkan botol paling depan untuk KEEMPAT titik —
     *   seluruh sertifikat mencetak "Carbon Monoxide (CO)" sebagai gas acuan,
     *   termasuk baris oksigen.
     * - Dua baris master lab ini berbagi seri `23P1005`: sensor RTD dan
     *   kalibrator Yokogawa CA 150 yang menempel padanya. Satu `first()` yang
     *   menerima nama ATAU serial sekaligus bikin yang menang cuma yang ID-nya
     *   terkecil, jadi baris Yokogawa di lembar tertaut ke dokumen SENSOR.
     *   Merknya kebetulan sama jadi angkanya nggak salah — yang salah nomor
     *   sertifikat & ketertelusurannya.
     *
     * Nama selalu lebih spesifik. Buat baris yang serialnya memang unik
     * hasilnya identik, karena namanya pun cocok.
     *
     * @param  Collection<int, Standard>  $master
     * @param  list<string>  $kunci
     */
    protected function cocokkanStandar(Collection $master, array $kunci): ?Standard
    {
        return $master->first(fn (Standard $s): bool => in_array($s->nama, $kunci, true))
            ?? $master->first(fn (Standard $s): bool => in_array($s->serial_number, $kunci, true));
    }

    protected function tautkanStandarTitik(array $bentuk, ?Equipment $equipment = null): array
    {
        $pasangan = $this->standarPerTitik();

        if ($pasangan === []) {
            return $bentuk;
        }

        $master = $this->masterStandarTertaut($equipment);

        $cocokkan = static function (float $titikUkur) use ($pasangan, $master): ?Standard {
            foreach ($pasangan as $p) {
                $batas = max(abs($p['titik']), 1.0) * self::TOLERANSI_PASANGAN_TITIK;

                if (abs($titikUkur - $p['titik']) > $batas) {
                    continue;
                }

                // NAMA dulu, serial belakangan — dan urutan ini bukan
                // kosmetik.
                //
                // Serial nggak dijamin unik antar standar. Keempat botol gas
                // Rigas di master Gas Detector ber-S/N `WO0125576` yang SAMA
                // (satu order pengisian, empat campuran), jadi pencarian yang
                // menerima serial lebih dulu memulangkan botol yang kebetulan
                // paling depan di koleksi untuk KEEMPAT titik. Akibatnya
                // seluruh sertifikat mencetak "Carbon Monoxide (CO)" sebagai
                // gas acuan keempat barisnya — termasuk baris oksigen.
                //
                // Nama selalu lebih spesifik. Buat sembilan alat lain yang
                // serialnya memang unik hasilnya identik, karena nama mereka
                // pun cocok.
                return $master->first(fn (Standard $s): bool => in_array($s->nama, $p['standar'], true))
                    ?? $master->first(fn (Standard $s): bool => in_array($s->serial_number, $p['standar'], true));
            }

            return null;
        };

        $stempel = static function (array $baris) use ($cocokkan): array {
            $standar = $cocokkan((float) $baris['titik_ukur']);

            return [
                ...$baris,
                'standard_id' => $standar?->id,
                'standard_nama' => $standar?->nama,
            ];
        };

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['tabel'] ?? [] as $j => $tabel) {
                foreach ($tabel['baris'] ?? [] as $k => $baris) {
                    $bentuk['bagian'][$i]['tabel'][$j]['baris'][$k] = $stempel($baris);
                }

                // `baris_per_satuan` IKUT distempel.
                //
                // Alat yang satuannya bisa dipindah (Refractometer n20D/°Brix)
                // ngirim tabel cadangan per satuan, dan mobile nuker barisnya
                // waktu teknisi milih. Sebelum ini yang distempel cuma `baris`
                // bawaan, jadi begitu teknisi pindah ke °Brix titiknya bener
                // (2,5 & 40) tapi standarnya balik KOSONG — jatuh lagi ke
                // pilih-manual, persis lubang yang `standarPerTitik()` ada buat
                // nutup. Sesi pH 7 Agt 2026 kepilih Buffer 4 di titik 7,00 dan
                // Correction-nya kecetak `-2,99` gara-gara lubang yang sama.
                foreach ($tabel['baris_per_satuan'] ?? [] as $satuan => $barisSatuan) {
                    foreach ($barisSatuan as $k => $baris) {
                        $bentuk['bagian'][$i]['tabel'][$j]['baris_per_satuan'][$satuan][$k] = $stempel($baris);
                    }
                }
            }
        }

        return $bentuk;
    }

    /** Sedikit-dikitnya kolom pengulangan yang masuk akal buat lembar kerja. */
    public const MIN_KOLOM_PENGULANGAN = 2;

    /**
     * Sebanyak-banyaknya. Lembar kerja itu dicetak & diisi tangan — lebih dari
     * ini kolomnya kekecilan buat dibaca, dan nggak ada prosedur lab yang minta
     * sebanyak itu.
     */
    public const MAKS_KOLOM_PENGULANGAN = 10;

    /**
     * Penanda TAMPIL-BERSYARAT di kontrak field — `['kode' => ..., 'nilai' =>
     * [...]]`, dibaca "tampilkan field ini cuma kalau field `kode` isinya salah
     * satu dari `nilai`". `null` (bawaan tiap field) artinya selalu tampil.
     *
     * Ada karena sebelumnya aplikasi teknisi NGE-HARDCODE nama fieldnya
     * (`grup.first.kode == 'lokasi_nama'`) buat mutusin kotak itu digambar apa
     * nggak. Akibatnya field bersyarat berikutnya — nomor Channel yang cuma
     * berlaku buat kalibrator Recorder, misalnya — nggak bisa dipasang dari
     * backend doang: satu field baru = satu rilis APK, dan teknisi yang belum
     * update nggak lihat kotaknya sama sekali. Dengan penanda ini HP cukup
     * punya SATU aturan yang nggak kenal nama field satu pun.
     *
     * APK lama yang belum ngerti kunci ini nggak rusak — dia cuma nampilin
     * kedua kotaknya sekaligus. Itu sebabnya labelnya tetap bawa "(Inlab)" /
     * "(Insitu)": di layar lama, label itu satu-satunya yang ngasih tau teknisi
     * kotak mana yang berlaku buat sesinya.
     */
    public const TAMPIL_KALAU_INSITU = ['kode' => 'lokasi', 'nilai' => ['onsite']];

    /**
     * Pasangannya buat kotak yang cuma berlaku di lab.
     *
     * `nilai` di sini nilai ENUM database (`lab`/`onsite`), BUKAN labelnya.
     * Labelnya yang diseragamkan jadi Inlab/Insitu; ngutak-atik enumnya berarti
     * migrasi kolom `calibration_sessions.lokasi` sekaligus mutusin semua APK
     * yang udah beredar.
     */
    public const TAMPIL_KALAU_INLAB = ['kode' => 'lokasi', 'nilai' => ['lab']];

    /**
     * Ganti jumlah KOLOM pengulangan di bentuk lembar kerja.
     *
     * Tiap profil punya jumlah bawaannya sendiri (5, ngikut form kertas), tapi
     * teknisi kadang cuma perlu 3 — mis. sampelnya terbatas atau alatnya lama
     * banget stabil. Angka ini murni soal berapa kotak yang DIGAMBAR: rumusnya
     * sendiri selalu ngikut berapa kotak yang beneran diisi (lihat
     * `GumCalculator::hitungTitik()`), jadi ngecilin kolom nggak ngubah cara
     * ngitungnya sama sekali.
     *
     * Ditulis ulang di sini — sekali, buat semua profil — bukan dioper sebagai
     * parameter ke tiap `bentukLembarKerja()`. Bentuknya nested dan tiap profil
     * nyusunnya beda; kalau tiap profil harus ngoper sendiri, cepat atau lambat
     * ada satu yang lupa dan kolomnya balik ke 5 diam-diam.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    public static function setelKolomPengulangan(array $bentuk, int $jumlah): array
    {
        $jumlah = max(self::MIN_KOLOM_PENGULANGAN, min(self::MAKS_KOLOM_PENGULANGAN, $jumlah));

        $tulisUlang = static function (array $simpul) use (&$tulisUlang, $jumlah): array {
            foreach ($simpul as $kunci => $nilai) {
                if ($kunci === 'jumlah_pengulangan' && is_int($nilai)) {
                    $simpul[$kunci] = $jumlah;

                    continue;
                }

                // `pengulangan` di tabel hasil isinya nomor kolom (`[1,2,3,4,5]`).
                // Dicek isinya, bukan cuma namanya, biar kunci bernama sama yang
                // isinya lain nggak ikut kegilas.
                if ($kunci === 'pengulangan' && is_array($nilai) && $nilai === range(1, count($nilai))) {
                    $simpul[$kunci] = range(1, $jumlah);

                    continue;
                }

                if (is_array($nilai)) {
                    $simpul[$kunci] = $tulisUlang($nilai);
                }
            }

            return $simpul;
        };

        return $tulisUlang($bentuk);
    }

    /**
     * Daftar komponen budget ketidakpastian buat SATU titik ukur, siap disuap
     * ke `GumCalculator::agregasiBudget()`. Balikin `null` kalau profil ini
     * nggak (atau belum) bisa nyusun budget penuh buat titik ini — pemanggil
     * bakal jatuh ke jalur CMC / generik yang lama, tanpa berubah perilaku.
     *
     * Tiap komponen: `['sumber','keterangan','distribusi','u','ci','vi']`.
     * Komponen pengulangan (Type A) WAJIB `'distribusi' => 't-student'` — itu
     * yang dipakai pemanggil buat misahin Type A dari Type B waktu ngitung
     * `type_b` (RSS Type B doang).
     *
     * @param  float  $typeA  ketidakpastian baku Type A (STDEV/√n) titik ini
     * @param  int  $n  jumlah pengulangan
     * @param  float|null  $suhuRuang  rata-rata suhu ruang MENTAH (awal+akhir)/2,
     *                                 sebelum koreksi sertifikat thermohygro.
     *                                 Refractometer butuh ini buat komponen
     *                                 "Pengaruh Perbedaan Temperature"; profil
     *                                 lain mengabaikannya. Lihat
     *                                 `GumCalculator::hitungTitik()`.
     * @param  array<string, mixed>  $konteksTitik  data per titik & per SESI yang
     *                                              cuma dimengerti profil alatnya, dioper apa
     *                                              adanya dari `GumCalculator::hitungTitik()`
     *                                              — sumber yang sama yang dipakai
     *                                              [toleransiTitik]. Gas Detector butuh ini
     *                                              buat `delta_suhu` & `delta_tekanan`:
     *                                              budget-nya pakai PERGESERAN ruangan
     *                                              (|akhir − awal|), bukan U95 sertifikat
     *                                              thermobarometer kayak delapan alat lain,
     *                                              dan pergeseran itu nggak bisa diturunkan
     *                                              dari `$suhuRuang` yang cuma bawa
     *                                              rata-ratanya. Profil yang nggak butuh
     *                                              nggak pernah bukanya.
     * @return list<array{sumber: string, keterangan: string, distribusi: string, u: float, ci: float, vi: float}>|null
     */
    abstract public function komponenBudget(
        CalibrationCapability $kemampuan,
        Equipment $equipment,
        Standard $standard,
        float $titikUkur,
        float $typeA,
        int $n,
        ?float $suhuRuang = null,
        array $konteksTitik = [],
    ): ?array;

    /**
     * Hitung SEMUA titik satu sesi sekaligus, buat alat yang ketidakpastiannya
     * lahir per KELOMPOK titik, bukan per titik.
     *
     * Default `null` = "profil ini nggak ambil alih" — pemanggil tetap jalan
     * per titik lewat `GumCalculator::hitungTitik()` persis kayak sebelum hook
     * ini ada. Cuma profil yang emang butuh yang nge-override.
     *
     * ## Kenapa hook ini perlu
     *
     * `GumCalculator::hitungTitik()` cuma lihat SATU titik, jadi dia nggak bisa
     * tahu STDEV titik tetangganya. Master Spectrophotometer nyusun satu budget
     * per kelompok filter dari **STDEV terbesar** di kelompok itu, lalu nyetak
     * satu `U95%` yang sama buat semua titik kelompok tersebut. Itu nggak bisa
     * diungkapin lewat [komponenBudget] yang per titik.
     *
     * Implementasi WAJIB balikin satu baris hitungan per titik yang berhasil
     * (bentuknya sama kayak keluaran `GumCalculator::hitungTitik()`), dan
     * ngelaporin titik yang nggak kehitung lewat `belum_dihitung` — bukan
     * ngebuang diam-diam.
     *
     * @param  list<array{titik_ke: int, titik_ukur: float, pembacaan: list<float>, standard: Standard}>  $titik
     * @return array{hitungan: list<array<string, mixed>>, belum_dihitung: list<array{titik_ke: int, alasan: string}>}|null
     */
    public function hitungPerGrup(array $titik, Equipment $equipment): ?array
    {
        return null;
    }

    /**
     * Bagian sertifikat tingkat-SESI yang nggak muat di tabel empat kolom
     * `Standard | UUT | Correction | U95%`, atau `null` kalau alat ini emang
     * nyetak tabel itu apa adanya.
     *
     * Yang balikin non-null cuma alat yang sertifikat MASTER-nya beneran
     * berbentuk lain. Timbangan sejauh ini satu-satunya lewat jalur ini:
     * sertifikatnya DELAPAN bagian (Repeatability, Effect of Tare, Accuracy,
     * Loading Influence, Hysterisis, Limit of Performance, Weighing
     * Uncertainty, Standard Used), dan tujuh dari delapan nggak punya kolom
     * `Standard`/`UUT` sama sekali. Dipaksa masuk tabel empat kolom, tujuh
     * bagian itu hilang tanpa satu pun error — sertifikatnya tetap terbit
     * rapi, bernomor, dan kehilangan sebagian besar isinya.
     *
     * Bedanya dari `hasil_autoclave` (yang bentuknya sama-sama lain): Autoklaf
     * punya kolom sendiri di `calibration_sessions`, Timbangan nggak — blok
     * tingkat-sesinya (keterulangan, eksentrisitas, histeresis) hidup di
     * `spesifikasi_alat` sebagai MASUKAN, dan angka jadinya nggak pernah
     * disimpan. Jadi disusun di sini, waktu sertifikat terbit, lalu DIBEKUKAN
     * ke snapshot — sama persis alasannya kayak `desimal` & `judul_uut`:
     * cetakan ulang tahun depan harus keluar angka yang sama walau
     * kalkulatornya udah berubah.
     *
     * Balikin `null` = alat ini lewat jalur lama tanpa berubah sama sekali,
     * dan itu jawaban yang benar buat dua puluh alat lainnya.
     *
     * @return array<string, mixed>|null
     */
    public function ringkasanSertifikat(CalibrationSession $sesi): ?array
    {
        return null;
    }

    /**
     * Nomor Instruksi Kerja alat ini apa adanya (`SIDIK-IK-CAL-0505_Rev.7`),
     * atau `null` kalau profilnya nggak menyatakan.
     *
     * ## Kenapa perlu, padahal sudah ada cadangan pencocokan nama
     *
     * [\App\Services\CertificateSnapshotBuilder::metodeKalibrasi] mencocokkan
     * NAMA ALAT ke kolom "Jenis Pengukuran" tabel master. Itu jalan selama nama
     * alatnya memuat jenis pengukurannya ("Timbangan Elektronik" memuat
     * "Timbangan"), dan MELESET begitu tidak: timbangan yang di lapangan
     * bernama "Moisture Analyzer" tidak memuat kata "Timbangan" sama sekali,
     * jadi cadangannya tidak kena dan kolom `Calibration Method` sertifikat
     * terbit berisi rujukan pustaka (`NMI Monograph 4`) alih-alih nomor IK lab.
     *
     * Di dokumen terakreditasi itu salah menyebut metode — dan tidak ada error
     * di mana pun, karena kolomnya memang terisi.
     *
     * Yang nyatain di sini profilnya sendiri, bukan nama alatnya: satu profil
     * = satu jenis pengukuran = satu baris IK di master, apa pun nama yang
     * ditulis pelanggan di badan alatnya.
     *
     * Default `null` = alat ini lewat jalur lama tanpa berubah sama sekali.
     */
    public function kodeMetode(): ?string
    {
        // Dibaca dari konstanta `KODE_METODE` yang sudah jadi konvensi sepuluh
        // profil sebelum hook ini ada — jadi menyatakannya cukup dengan menulis
        // konstantanya, dan tidak ada dua cara melakukan hal yang sama.
        //
        // `static::`, bukan `self::`: yang dicari konstanta di kelas ANAK.
        $konstanta = static::class.'::KODE_METODE';

        return defined($konstanta) ? (string) constant($konstanta) : null;
    }

    /**
     * Apakah tiap titik ukur berupa GRID sensor (banyak termokopel × pengulangan
     * + Indikator), bukan satu deret pembacaan datar.
     *
     * Default `false` — sepuluh alat single-channel. Cuma enclosure yang `true`
     * (9 termokopel di dalam chamber). Waktu `true`, `CalibrationController`
     * membaca `measurements[i].sensor_grid` & `.indikator` dan menyimpannya ke
     * `raw_measurements` dengan sumbu `sensor_ke`/`peran_sensor`, alih-alih
     * `measurements[i].pembacaan` yang datar.
     */
    public function butuhGridSensor(): bool
    {
        return false;
    }

    /**
     * Apakah tiap titik ukur berupa DUA deret pembacaan — standar & UUT —
     * bukan satu deret datar yang diadu ke nilai nominal standar.
     *
     * Default `false`. `true` untuk Thermocouple, Termometer Gelas, &
     * Thermohygrometer: ketiganya mencelup UUT dan probe standar berbarengan
     * lalu membaca dua-duanya bergantian, jadi pembacaan STANDAR itu data
     * sesi — bukan konstanta yang bisa diambil dari master `standards`.
     *
     * Waktu `true`, `CalibrationController` membaca `measurements[i].standar` &
     * `.uut` dan menyimpan keduanya ke `raw_measurements` dengan
     * `peran_sensor` `standar`/`uut`, memakai sumbu yang sudah ada sejak
     * enclosure. Tanpa hook ini jalur datar cuma menyimpan satu deret, dan
     * deret standar — yang di master jadi SISI KIRI kolom `Correction` —
     * hilang tanpa error.
     */
    public function butuhPasanganStandarUut(): bool
    {
        return false;
    }

    /**
     * Apakah sesi ini berbentuk TUJUH BLOK lembar Timbangan, bukan tabel titik.
     *
     * Default `false`. `true` cuma untuk Timbangan: satu sesi memuat Scale
     * Observation, Effect of Tare, Accuracy, Repeatability, Loading Influence,
     * Hysterisis, dan Drift — dan cuma Accuracy yang jadi baris titik di
     * sertifikat. Empat blok lain menyumbang ke budget atau ke pernyataan
     * terpisah (LOP), jadi tidak punya `titik_ke` sama sekali.
     *
     * Waktu `true`, `CalibrationController` membaca `measurements[i].nominal`
     * berikut empat pembacaannya (`z1`, `m`, `m_aksen`, `z2`) dan menyimpannya
     * ke `raw_measurements` lewat sumbu `peran_sensor` yang sudah ada, sementara
     * blok tingkat-sesi masuk `spesifikasi_alat`.
     *
     * Tanpa hook ini jalur datar menyimpan SATU deret per titik, dan tiga dari
     * empat pembacaan tiap titik hilang tanpa error — termasuk kedua pembacaan
     * nol yang jadi sisi kiri kolom `Correction`.
     */
    public function butuhBlokTimbangan(): bool
    {
        return false;
    }

    /**
     * Apakah satu titik sesi ini berisi DUA deret waktu — stopwatch standar dan
     * alat pelanggan yang ditekan berbarengan.
     *
     * Default `false`. `true` cuma untuk Timer/Stopwatch. Waktu `true`,
     * `CalibrationController` menyimpan tiap ulangan sebagai dua baris
     * `raw_measurements` ber-`peran_sensor` `waktu_standar`/`waktu_uut`
     * (nilainya total milidetik, lihat `WaktuMentah::keMilidetik()`), dan jalur
     * hitung ulang menyusunnya balik lewat `WaktuMentah::dari()`.
     *
     * Tanpa hook ini jalur datar menyimpan satu deret campuran per titik, dan
     * koreksi yang lahir dari situ — selisih rata-rata standar dan UUT — tidak
     * berarti apa-apa. Tidak ada error yang terbit; yang muncul cuma angka yang
     * salah.
     */
    public function butuhBlokWaktu(): bool
    {
        return false;
    }

    /**
     * Kolom `Standard Value` sertifikat DIHITUNG dari `rata_rata + koreksi`,
     * bukan diambil dari `titik_ukur`.
     *
     * Default `false` — dan buat dua puluh satu alat lain kedua jalur itu
     * memulangkan angka yang SAMA, karena `GumCalculator` menurunkan
     * `koreksi = titik_ukur − rata_rata`. Jadi identitas
     *
     *     Standard Value ≡ rata_rata + koreksi
     *
     * memang berlaku di seluruh sistem; yang beda cuma dari mana angkanya
     * diambil. Default dibiarkan `false` supaya sertifikat yang sudah terbit
     * tidak bergeser satu digit pun karena urutan operasi float.
     *
     * ## Kenapa ada alat yang butuh `true`
     *
     * Sepuluh alat menaruh NILAI ACUAN di `titik_ukur`: buffer pH 4,01 itu
     * konstanta dari sertifikat larutan, dan yang dibaca berulang alat
     * pelanggan. Kelompok Waktu dan Frekuensi kebalikannya — yang dibaca
     * berulang justru STANDARNYA, dan `titik_ukur` menyimpan set point, yaitu
     * penunjukan alat pelanggan.
     *
     * Buat mereka `titik_ukur` bukan nilai acuan, jadi mencetaknya di kolom
     * `Standard Value` menerbitkan tabel yang tidak konsisten dengan dirinya
     * sendiri: master Centrifuge mencetak `59,78 | 60 | −0,22`, sedangkan
     * `titik_ukur` apa adanya menerbitkan `60 | 59,98 | −0,22` — dan
     * 60 − 59,98 bukan −0,22. Tidak ada error yang terbit; yang terbit
     * sertifikat yang angkanya tidak menjumlah.
     */
    public function nilaiStandarDariKoreksi(): bool
    {
        return false;
    }

    /**
     * Normalisasi RATA-RATA pembacaan alat ke suhu acuannya. Default: nggak
     * ngapa-ngapain — pembacaan dipakai apa adanya.
     *
     * ## Beda dari `Standard::nilaiPadaSuhu()` — dua arah yang berlawanan
     *
     * Dua-duanya ngurus suhu, tapi yang digeser BEDA SISI:
     *
     *  - `Standard::nilaiPadaSuhu()` geser **nilai acuan**. Buffer pH "10.01"
     *    yang diukur pada 25,3 °C nilai benernya 9,9451681 — botolnya yang
     *    berubah, pembacaan alatnya sah apa adanya.
     *  - Method ini geser **pembacaan alat**. Refractometer dibaca pada 27 °C
     *    nunjukin 1,3362; kalau larutan yang sama dibaca pada 20 °C (suhu acuan
     *    n20D) alatnya bakal nunjukin 1,33935. Larutan standarnya tetap 1,33659.
     *
     * Kalau kebalik, kolom "Correction" di sertifikat kegeser sebesar dua kali
     * koreksi suhu dan bisa ketuker tanda.
     *
     * ## Kenapa RATA-RATA, bukan tiap pembacaan
     *
     * Master Excel lab ngerata-rata dulu pembacaan DAN suhunya, baru dikoreksi
     * (sheet `PERHITUNGAN` baris Average: `1,3362 | 27`). STDEV-nya dihitung
     * dari pembacaan MENTAH. Kalau tiap pembacaan dikoreksi sendiri-sendiri,
     * satu repeat yang suhunya nyeleneh (35 °C di sesi contoh) bikin STDEV
     * bukan nol dan seluruh budget-nya meleset.
     *
     * @param  float|null  $suhuLarutan  rata-rata suhu larutan titik ini (°C)
     */
    public function rataRataPadaSuhuAcuan(
        float $rataRata,
        ?float $suhuLarutan,
        Equipment $equipment,
    ): float {
        return $rataRata;
    }

    /**
     * Faktor pengali dari satuan yang DILAPORKAN ke satuan KANONIK titik ini.
     * Default `1.0` — hampir semua alat cuma punya satu satuan per titik, jadi
     * satuan laporannya memang satuan kanoniknya.
     *
     * ## Kenapa ada
     *
     * Conductivity bisa baca satu botol dalam dua satuan: `1412 µS/cm` dan
     * `1,412 mS/cm` itu larutan yang SAMA. Cuma jalur µS/cm yang pernah diadu
     * ke master Excel lab; jalur mS/cm nggak pernah keisi di sana.
     *
     * Kalau jalur mS/cm dihitung sendiri, dia jadi jalur kedua yang harus
     * divalidasi sendiri — dan tiap potongan konversi (nilai acuan, U botol,
     * resolusi, lantai CMC) jadi kesempatan salah yang terpisah. Waktu titik
     * ini pertama kali dijalanin, dua di antaranya emang salah sekaligus.
     *
     * Jadi jalannya dibalik: titiknya dinaikin ke satuan kanonik, dihitung
     * lewat jalur yang SUDAH terbukti, baru hasilnya diturunkan lagi. Yang
     * dilaporkan jadi identik dengan jalur µS/cm dibagi 1000 **secara
     * konstruksi**, bukan karena empat konversi kebetulan semuanya benar.
     *
     * Balikin `1.0` kalau titiknya emang udah kanonik.
     */
    public function faktorKanonik(float $titikUkur, Equipment $equipment): float
    {
        return 1.0;
    }

    /**
     * Berapa desimal yang dipakai NYETAK angka hasil di sertifikat, kalau alat
     * ini emang beda dari aturan umum. `null` = ikut aturan umum
     * (`Organization::desimalSertifikat`, yaitu diturunkan dari resolusi alat).
     *
     * Aturan umumnya bener buat hampir semua alat: nulis desimal lebih banyak
     * dari yang bisa dibaca alatnya itu ngaku-ngaku presisi yang nggak ada.
     * Tapi lab-nya sendiri nggak ngikutin itu di semua alat, dan yang menentukan
     * dokumen resminya — bukan prinsipnya.
     */
    public function desimalSertifikat(): ?int
    {
        return null;
    }

    /**
     * Desimal cetak sertifikat yang berlaku DI TITIK ini, kalau alat ini
     * memformat tiap barisnya beda. `null` = ikut [desimalSertifikat] lalu
     * aturan umum, persis perilaku sebelum hook ini ada.
     *
     * ## Kenapa per baris, bukan satu angka per alat
     *
     * Sembilan alat pertama memformat seluruh kolom hasilnya seragam, dan
     * [desimalSertifikat] cukup untuk itu. Master Viscometer terbaru
     * (`5. Viscometer 86068360 terbaru .xlsm`) tidak: sel `SERTIFIKAT` C23:R27
     * diformat `0.00` · `0.0` · `0` · `0.0` · `0.0` — dua desimal di baris
     * 100 cP, nol desimal di baris 3000 cP.
     *
     * Itu bukan kelalaian ketik, itu skala: `79,90 cP` dan `2709,8 cP` dibaca
     * alat yang sama dengan resolusi yang sama, dan menulis `2709,80` berarti
     * mengklaim dua angka penting yang tidak ada. Sama seperti [satuanTitik] &
     * [resolusiTitik] — begitu satu alat mencampur, yang bertanya harus bisa
     * bertanya per baris.
     */
    public function desimalSertifikatTitik(float $titikUkur): ?int
    {
        return null;
    }

    /**
     * Desimal khusus baris `Uncertainty U95% = ±`, kalau alat ini nyetaknya
     * beda dari kolom hasil di atasnya. `null` = ikut desimal titiknya.
     *
     * Ada karena master Spectrophotometer nyetak U95 blok %T sebagai `0,50`
     * sementara kolom UUT & Correction di blok yang sama pakai TIGA desimal
     * (`9,665`). Dua angka, dua format, satu tabel — dan yang menentukan
     * dokumen resminya, bukan konsistensi yang kelihatan lebih rapi.
     *
     * Lima alat lain balik `null` dan sertifikatnya nggak berubah sama sekali.
     */
    public function desimalU95(): ?int
    {
        return null;
    }

    /**
     * Desimal kolom `U95%` yang berlaku DI BARIS ini, kalau alat ini
     * memformat tiap barisnya beda. `null` = ikut [desimalU95] lalu desimal
     * barisnya — persis perilaku sebelum hook ini ada.
     *
     * Pasangan [desimalSertifikatTitik], dan ada karena alasan yang sama:
     * master Gas Detector memformat `SERTIFIKAT` U24:U27 sebagai `0.0` · `0.0`
     * · `0.0` · `0.00` — satu desimal untuk tiga gas pertama, DUA untuk
     * oksigen. Kolom hasil di baris yang sama justru `0`/`0.0`, jadi dua
     * kolom bersebelahan di satu baris memang beda jumlah desimalnya.
     *
     * Bukan kerapian yang bisa diseragamkan: `U95` oksigen 0,887 % runtuh jadi
     * `0,9` pada satu desimal dan `1` pada nol — di kolom yang justru jadi inti
     * sertifikat.
     */
    public function desimalU95Titik(float $titikUkur): ?int
    {
        return null;
    }

    /**
     * Desimal angka `k` di kalimat `… Coverage Factor ( k ) = …`.
     * `null` = perilaku lama, yaitu 2 desimal dengan nol di belakang dibuang
     * (`1,97`, `2`).
     *
     * Ada karena master Spectrophotometer nyimpen `k` presisi penuh
     * (3,182446…; 2,364624…; 2,008559…) tapi SELNYA diformat 0 desimal, jadi
     * yang tercetak di sertifikat `3`, `2`, `2`. Sistem sebelumnya nyetak
     * `3,18` — angka yang bener, tapi bukan angka yang ada di dokumen lab.
     *
     * Sengaja per alat, bukan disamain: master alat lain nyimpen `k` yang
     * mirip-mirip (1,9714 di Turbidimeter, 1,9707 di pH) dan belum pernah diadu
     * ke cetakannya. Dipukul rata 0 desimal, empat sertifikat yang udah beredar
     * berubah bunyi tanpa satu pun bukti kertas.
     */
    public function desimalFaktorCakupan(): ?int
    {
        return null;
    }

    /**
     * Faktor cakupan `k` yang DIKUNCI buat alat ini, atau `null` kalau `k`
     * dihitung dari `v_eff` lewat t-student (lihat
     * `GumCalculator::agregasiBudget()`).
     *
     * Bawaannya `null` — dan itu yang bener buat lima alat pertama. Workbook pH
     * lab nyimpen EMPAT nilai k (1,96856; 1,97066; 1,97076; 2,77645) yang
     * cocok persis sama t-student dipotong ke bawah, termasuk yang 2,77645
     * waktu `v_eff`-nya cuma 4,92. Jadi lembar labnya sendiri emang ngitung k,
     * bukan ngunci 2.
     *
     * Viscometer beda, dan bedanya keputusan lab: sel `k` di
     * `PERHITUNGAN U95%` titik 100 & 1000 cP isinya `2` walau `v_eff`-nya
     * 5,376 (t-student bakal ngasih 2,5706), dan sertifikat masternya nulis
     * `Coverage Factor ( k ) = 2` hitam di atas putih. Keempat baris CMC
     * viscometer di `calibration_capabilities` juga `faktor_cakupan = 2`.
     */
    public function faktorCakupanTetap(): ?float
    {
        return null;
    }

    /**
     * `U95` dicetak sebagai KOLOM sendiri di tiap baris tabel hasil, bukan satu
     * baris ringkas `Uncertainty U95% = ±` di bawah tabel.
     *
     * Bawaannya `false`, dan itu yang bener buat alat yang U95-nya lahir per
     * KELOMPOK: sepuluh baris Holmium Spectrophotometer bawa angka yang sama
     * persis, dan nyetak angka yang sama sepuluh kali kebaca kayak sepuluh
     * hasil yang kebetulan mirip.
     *
     * Viscometer beda: tiap titik punya U95 sendiri (0,49 / 2,71 / 145,72 —
     * beda 300 kali lipat antar titik), dan masternya emang nyetak kolom
     * keempat `U95%, k=2` dengan satu angka per baris. Diringkas jadi satu
     * baris, dua dari tiga angka HILANG dari dokumen.
     */
    public function u95PerTitik(): bool
    {
        return false;
    }

    /**
     * Judul kolom kedua tabel CALIBRATION REPORT.
     *
     * Lima master nulis `Unit Under Test`; master Spectrophotometer nulis
     * `UUT` — dan konsisten begitu di KETIGA bloknya (`SERTIFIKAT!L18`, `L33`,
     * `L47`), jadi itu pilihan lab, bukan sel yang kepotong.
     *
     * Yang NGGAK ditiru: spasi yang ilang di `Correction(nm)`. Master spektro
     * sendiri nulisnya dua cara — `Correction (%T)` di blok %T, `Correction(%T)`
     * di blok SRE — jadi itu kelalaian ketik, bukan aturan. Niru kelalaian
     * bikin dokumen resmi kelihatan salah cetak.
     */
    public function judulKolomUut(): string
    {
        return 'Unit Under Test';
    }

    /**
     * Kolom **Standard Value** nulis nol di belakang koma atau nggak.
     *
     * `true` (bawaan) = nol di belakang dibuang — master Turbidimeter nulis
     * `1` / `100` / `1000`, bukan `1,00` / `100,0`, karena standarnya emang
     * angka bulat dan desimalnya nggak membawa informasi apa pun.
     *
     * `false` = ditulis penuh sebanyak desimal barisnya. Master
     * Spectrophotometer nulis `334,0` · `460,0` · `748,0` · `100,0` — kolomnya
     * ngelapor NILAI ACUAN FILTER, dan di daftar yang tetangganya `287,7` &
     * `637,9`, angka `334` kebaca kayak titik yang beda formatnya, bukan titik
     * yang kebetulan bulat.
     */
    public function nolBelakangStandarDibuang(): bool
    {
        return true;
    }

    /**
     * Desimal NILAI suhu & kelembaban di baris `Env. Condition`.
     * `null` = tulis apa adanya (nol di belakang dibuang), padanan format
     * `General` di Excel.
     *
     * ## Kenapa ini per alat, bukan satu aturan
     *
     * Baris `Env. Condition` udah TIGA KALI digeser dan tiap kali balik lagi,
     * karena tiap kali dipatok ke SATU master lalu dikeluhkan dari master yang
     * lain. 10 Agt 2026 keempat workbook dibuka bareng dan sebabnya ketemu:
     * format selnya emang beda-beda per workbook.
     *
     *   alat           sel T        kecetak    sel %RH      kecetak
     *   pH             `0.0`        21,0       `General`    51,95
     *   Turbidimeter   `General`    23,07      `0`          52
     *   Chlorine       `General`    23,21      `General`    53
     *   Refractometer  `0.0`        22,0       `0`          60
     *   Conductivity   `0.0`        25,8       `0`          51
     *
     * Nggak ada satu aturan yang bener buat keempatnya — jadi jangan dicari.
     * Ketidakpastiannya beda: `0.0` di keempat workbook, jadi ITU tetap dipatok
     * global (1 desimal) di `CertificateSnapshotBuilder`.
     */
    public function desimalSuhuEnv(): ?int
    {
        return null;
    }

    /** Lihat [desimalSuhuEnv]. */
    public function desimalKelembabanEnv(): ?int
    {
        return null;
    }

    /**
     * Apa jenis alat ini PUNYA batas toleransi yang jadi dasar vonis PASS/FAIL?
     *
     * Dipakai `CalibrationValidator` buat mbedain dua hal yang di tabel
     * `equipments` kelihatan sama persis — `toleransi` NULL:
     *
     *  - **belum diisi** → sertifikat bakal terbit tanpa vonis padahal
     *    mestinya ada, dan itu pantas ditahan; dan
     *  - **alatnya emang nggak divonis** → NULL itu jawaban yang benar.
     *
     * Conductivity Meter masuk yang kedua: seluruh master-nya nggak punya satu
     * pun sel yang mbandingin hasil sama batas keberterimaan, dan kedua sheet
     * sertifikatnya cuma nyetak `Correction` + `U95%` lalu berhenti.
     *
     * Default `true` — keempat profil yang lebih dulu ada semuanya punya
     * toleransi, jadi perilaku mereka nggak berubah sama sekali.
     */
    /**
     * Pembacaan lembar ini boleh diadu ke `equipments.resolusi`?
     *
     * Pemeriksa `pembacaan_bukan_kelipatan_resolusi` berdiri di atas satu
     * premis: angka yang dicatat dibaca di LAYAR alat, dan layar itu punya satu
     * daya baca yang tetap. Buat sepuluh lembar pertama premis itu benar.
     *
     * Buat TITS nggak — lihat [TitsProfile::pembacaanDiadukeResolusi].
     *
     * Kalau premisnya nggak berlaku, pemeriksanya dimatikan, BUKAN angkanya
     * yang diubah. `equipments.resolusi` kecetak di sertifikat dan ikut ngitung
     * budget ketidakpastian; membetulkan kebisingan layar dengan menggeser
     * angka master berarti menggeser hasil yang sekarang cocok dengan Excel
     * lab. Perlindungan yang beneran penting — salah ketik yang komanya kegeser
     * — tetap dipegang `pembacaan_di_luar_rentang`, yang nggak ikut dimatikan.
     */
    public function pembacaanDiadukeResolusi(): bool
    {
        return true;
    }

    /**
     * Berapa pengulangan minimum sebelum satu titik bisa dihitung.
     *
     * Dipakai buat MENJELASKAN kenapa satu titik nggak kehitung, bukan buat
     * menahannya — yang menahan tetap kalkulatornya masing-masing. Ada di sini
     * supaya pesannya nyebut ambang yang beneran berlaku: GUM minta 2, grid
     * Enclosure minta 4 pembacaan per sensor karena master memetakan kolom
     * `[1,2,3,3,4]` dan di bawah 4 kolom yang hilang harus ditebak.
     *
     * Pesan yang nyebut angka yang salah bikin teknisi melengkapi sampai 2 lalu
     * heran kenapa titiknya tetap nggak muncul di sertifikat.
     */
    public function minPengulanganPerTitik(): int
    {
        return GumCalculator::MIN_PENGULANGAN;
    }

    public function punyaToleransi(): bool
    {
        return true;
    }

    /**
     * Batas keberterimaan yang berlaku DI TITIK ini, kalau alatnya nggak punya
     * satu angka toleransi yang berlaku buat seluruh lembar. `null` = ikut
     * `equipments.toleransi` seperti biasa.
     *
     * ## Kenapa ada
     *
     * Enam alat pertama mbandingin hasil ke satu kolom `equipments.toleransi`
     * yang diisi admin — satu angka per alat, berlaku di semua titik. Itu benar
     * buat mereka: batas alat pH memang 0,2 pH di titik mana pun.
     *
     * Viscometer nggak begitu. Batasnya (MPE Brookfield) LAHIR dari cara alat
     * itu dipakai di titik tersebut:
     *
     *   Fullscale = TK × SMC × 10000 / RPM
     *   MPE       = 1 % × Fullscale + 1 % × pembacaan
     *
     * dan `SMC` (dari spindle) serta `RPM` beda per titik — sesi contoh master
     * pakai SMC 1 / 4 / 400 dengan 63 / 62 / 62 rpm dalam satu lembar. Batasnya
     * ikut beda: 4,14 / 22,08 / 1921,84 cP. Dipaksa jadi satu angka, dua dari
     * tiga titik divonis pakai batas yang bukan miliknya.
     *
     * `$konteks` isinya data per titik yang nggak muat di parameter lain —
     * buat Viscometer `spindle`, `rpm`, dan `tk`. Sengaja array bebas, bukan
     * parameter bernama: isinya beda per jenis alat, dan alat yang nggak butuh
     * nggak boleh kepaksa tau bentuknya.
     *
     * @param  float  $rataRata  rata-rata pembacaan titik ini (sesudah normalisasi suhu)
     * @param  array<string, mixed>  $konteks
     */
    /**
     * Pita angka yang MASUK AKAL buat satu sel pembacaan di lembar pindai —
     * kalau alat ini nggak bisa dilayani aturan umum.
     *
     * Aturan umumnya (`TemplateLembarKerja::aturanPembacaan()`): nominal titik
     * ±10 %, dengan penjaga rasio 0,5–2,0× nominal. Itu benar buat enam alat
     * pertama karena nilai acuannya DIAM — buffer pH 7 selalu ~7, standar
     * turbidity 100 NTU selalu ~100.
     *
     * Viscometer nggak. Nilai acuannya diinterpolasi dari tabel sertifikat
     * larutan pada suhu terukur, dan tabelnya curam: larutan 1000 cP itu
     * 1504 cP di 20 °C dan 419,5 cP di 37,78 °C. Pita ±10 % di sekitar nominal
     * 25 °C (1018 cP) jadi 916,2–1119,8 — dan pembacaan master yang paling
     * kecil 916,3, cuma 0,1 cP di atas batasnya. Sesi yang sama diukur di
     * 30 °C bakal ditolak SELURUH barisnya, dengan alasan yang kelihatan
     * seperti kegagalan baca kamera padahal angkanya benar.
     *
     * Balik `null` (bawaan) = pakai aturan umum. Yang override wajib bawa
     * alasan fisik, bukan sekadar melonggarkan penjaga.
     *
     * @return array{min: float, maks: float, rasio_min: float, rasio_maks: float}|null
     */
    public function pitaPembacaan(float $titikUkur): ?array
    {
        return null;
    }

    /**
     * Batas keberterimaannya dibaca dari kolom `equipments.toleransi`?
     *
     * Enam alat pertama: ya. Satu angka di master alat, dipakai semua titik,
     * dan kalau kolomnya kosong lembar kerjanya MEMANG belum bisa dihitung —
     * PASS/FAIL tanpa batas itu vonis tanpa dasar.
     *
     * Viscometer: nggak. Batasnya MPE, dan MPE lahir dari spindle & RPM titik
     * itu (`Fullscale = TK × SMC × 10000 / RPM`), jadi kolom alatnya sengaja
     * NULL. Tanpa hook ini penjaga di `CalibrationController::
     * alasanBelumBisaDihitung()` nolak SETIAP sesi Viscometer dengan alasan
     * "Toleransi alat masih kosong" — dan nolaknya rapi: sesinya tersimpan,
     * pengukurannya tersimpan, cuma nggak ada satu titik pun yang dihitung.
     *
     * Titik yang spindle/RPM-nya nggak keisi tetap dihitung, cuma nggak
     * divonis — lihat `toleransiTitik()`, yang balik `null` di situ. Itu benar:
     * angkanya ada, vonisnya yang nggak ada dasarnya.
     */
    /**
     * Turunkan standar acuan SESI dari baris standar yang dicentang teknisi.
     *
     * Default `null` — sepuluh lembar bertabel datar mengirim `standard_id`
     * secara eksplisit (pH malah per titik), jadi menebaknya di situ cuma bikin
     * pilihan teknisi bisa ketimpa diam-diam.
     *
     * Yang butuh ini lembar ENCLOSURE. Perhitungannya membaca `standard_id`
     * tingkat sesi buat tahu tabel koreksi mana yang dipakai
     * (Constant/Yokogawa/Recorder), tapi lembar kerjanya sama sekali NGGAK
     * punya kotak buat itu — yang ada cuma kolom centang "Dipakai", dan
     * centangan itu mendarat di tabel pivot yang nggak pernah dibaca jalur
     * hitung. Jadi tiap sesi Enclosure berakhir nol titik, dengan pesan yang
     * justru berbunyi "belum kebaca dari standar yang dicentang" — menyalahkan
     * kotak yang SUDAH dicentang teknisi.
     *
     * Diturunkan di server, bukan ditambah jadi kotak baru di HP, karena dua
     * alasan: teknisi sudah menyatakan alat mana yang dia pakai (mencentangnya),
     * dan menambah kotak kedua yang menanyakan hal yang sama membuka jalan
     * keduanya berbeda — di lab terakreditasi, dua jawaban buat satu pertanyaan
     * ketertelusuran itu temuan audit.
     *
     * @param  Collection<int, Standard>  $dicentang  Standar yang `dipakai`-nya true.
     */
    public function standarSesiDariCentang(Collection $dicentang): ?Standard
    {
        return null;
    }

    public function toleransiDariKolomAlat(): bool
    {
        return true;
    }

    public function toleransiTitik(
        float $titikUkur,
        float $rataRata,
        Equipment $equipment,
        array $konteks = [],
    ): ?float {
        return null;
    }

    /**
     * Ubah satu pembacaan ke satuan yang dipakai `equipments.range_min/max`,
     * biar pengecekan "pembacaan di luar rentang" mbandingin dua angka yang
     * SEBANDING.
     *
     * Default: nggak ngapa-ngapain. Hampir semua alat cuma punya satu satuan
     * di seluruh lembar, jadi pembacaan dan rentang alat udah otomatis
     * sebanding — dan buat mereka method ini nggak ngubah apa pun.
     *
     * Yang butuh cuma alat yang lembarnya NYAMPUR satuan. Conductivity Meter
     * nyatet titik 25 & 1412 dalam µS/cm tapi rentang alatnya `0–100 mS/cm`;
     * tanpa konversi, pembacaan 1413 µS/cm (= 1,413 mS/cm, jelas di dalam
     * rentang) ke-flag "jauh di luar rentang, komanya kegeser".
     *
     * @param  string|null  $satuanTitik  satuan yang kecatat di baris pembacaan
     */
    public function nilaiDalamSatuanAlat(float $nilai, ?string $satuanTitik, Equipment $equipment): float
    {
        return $nilai;
    }
}
