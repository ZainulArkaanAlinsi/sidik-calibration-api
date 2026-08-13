<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Support\Angka;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuknya dikunci sama docs/kontrak-api.md bagian 4 (repo mobile).
 *
 * @mixin CalibrationSession
 */
class CalibrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $penentu = $this->titikPenentu();

        return [
            'id' => $this->id,
            'nomor_sesi' => $this->nomor_sesi,
            'nomor_order' => $this->nomor_order,
            // Berapa desimal angka di `titik[]` mesti ditulis, biar layar mobile
            // membulatkan persis sama dengan yang bakal kecetak di sertifikat.
            // Tanpa ini mobile kepaksa nebak dari `equipment.resolusi` — cocok
            // buat kasus biasa, tapi meleset kalau organisasi nyetel timpaan yang
            // mobile nggak tau ada.
            //
            // Nilainya HIDUP (dihitung sekarang), beda dari `desimal` di
            // CertificateResource yang beku dari snapshot. Di sesi belum ada
            // sertifikat buat dibaca, dan angkanya masih boleh berubah sampai
            // sertifikatnya terbit.
            //
            // Timpaan profil alat menang di sini, SAMA kayak di
            // `CertificateSnapshotBuilder` — kalau nggak, sesi Refractometer
            // kebaca 4 desimal di layar tapi kecetak 5 di PDF-nya.
            'desimal' => self::desimalAlat(
                $this->resource->equipment,
                $this->organization ?? $request->user()?->organization,
                $this->equipment?->resolusi !== null ? (float) $this->equipment->resolusi : null,
            ),
            'equipment' => [
                'id' => $this->equipment?->id,
                'nama_alat' => $this->equipment?->nama_alat,
                'serial_number' => $this->equipment?->serial_number,
            ],

            // Identitas alat & pemilik VERSI TEKNISI (lembar kerja poin 3-5 &
            // OWNER 1-2). Wajib ikut di respons, bukan cuma tersimpan: waktu
            // sesi dikembalikan buat revisi, mobile ngisi ulang formulirnya dari
            // sini. Tanpa ini kolomnya balik kosong dan teknisi ngetik ulang
            // semuanya cuma buat mbenerin satu hal yang diminta admin.
            'alat_model' => $this->alat_model,
            'alat_serial_number' => $this->alat_serial_number,
            'alat_merk' => $this->alat_merk,
            // Dipulangin apa adanya biar draft yang dibuka lagi keisi persis
            // kayak waktu ditinggal.
            'spesifikasi_alat' => $this->spesifikasi_alat,
            'pemilik_nama' => $this->pemilik_nama,
            'pemilik_alamat' => $this->pemilik_alamat,
            // Pelanggan pemilik alat — dipakai layar antrean approval buat
            // ngelompokkin kiriman per PT. Admin mikirnya per perusahaan
            // ("beresin punya Maju Jaya dulu"), bukan per teknisi.
            //
            // `pemilik_nama` isian teknisi menang, sama kayak di sertifikat —
            // biar nama yang dilihat admin di antrean sama dengan yang bakal
            // kecetak. Kalau beda, admin mikir itu dua PT.
            'pelanggan' => [
                'id' => $this->equipment?->customer?->id,
                'nama' => $this->pemilik_nama ?: $this->equipment?->customer?->nama,
            ],
            'teknisi' => [
                'id' => $this->teknisi?->id,
                'nama' => $this->teknisi?->name,
                // ID pegawai buat login (SDK-0002). BUKAN yang dicetak di kolom
                // "Technician ID" lembar kerja — itu `kode_teknisi` di bawah.
                'employee_id' => $this->teknisi?->employee_id,
                // Kolom "Technician ID" di lembar kerja & sertifikat isinya kode
                // pendek (`DR`), bukan nama panjang atau ID pegawai. Kalau
                // `kode_teknisi` belum diisi, jatuh ke inisial nama — lebih baik
                // inisial daripada kolom kosong di dokumen resmi.
                'kode_teknisi' => $this->teknisi?->kodeTeknisi(),
                'department' => $this->teknisi?->department,
            ],

            // "Checked by" di lembar kerja — admin yang approve/reject sesi ini.
            // Orang yang sama nanti jadi penanda tangan sertifikat.
            'reviewer' => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'nama' => $this->reviewer->name,
                'kode_teknisi' => $this->reviewer->kodeTeknisi(),
            ] : null,
            // Tanggal POLOS (`2024-05-26`), jangan dibalikin ke ISO — kolomnya cast
            // `date` dan di zona Jakarta ISO-nya mundur sehari. Lihat komentar
            // panjangnya di `LaporanKalibrasiResource`.
            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toDateString(),
            'tanggal_terima' => $this->tanggal_terima?->toDateString(),

            // JAM-nya ikut, dan ini SENGAJA beda perlakuan dari dua tanggal di
            // atas: yang di atas kolom `date` (nggak punya jam sama sekali),
            // yang di bawah `datetime` — titik waktu beneran.
            //
            // Kenapa perlu: admin nggak bisa mbedain mana kiriman terbaru waktu
            // beberapa sesi masuk di HARI yang sama. Yang kelihatan cuma
            // `10 Agt 2026` tiga kali, padahal urutannya yang menentukan mana
            // yang mesti diperiksa duluan.
            //
            // ISO 8601 penuh (UTC), bukan string yang udah diformat — biar
            // mobile yang mutusin tampilannya, dan zona waktunya nggak ketebak
            // dua kali. Beda dari `tanggal_kalibrasi` yang justru HARUS polos.
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'status' => $this->status,
            'input_method' => $this->input_method,

            // Sesi bisa punya banyak titik ukur, tapi kontrak minta SATU objek
            // `hasil`. Yang dikirim adalah titik penentu — yang paling mepet ke
            // batas toleransi. Rincian tiap titik ada di `titik` di bawah.
            'hasil' => self::petakanHasil($penentu, $this->keputusan),

            'catatan_revisi' => $this->catatan_revisi,
            // Kode kolom yang diminta admin dibetulin. Layar teknisi nyorot
            // persis kolom ini, jadi dia nggak perlu nyisir formulir nyari
            // mana yang salah. Null = ditolak tanpa nunjuk kolom tertentu.
            'revisi_field' => $this->revisi_field ?? [],
            'certificate_id' => $this->certificate?->id,

            // Tambahan di luar kontrak (superset, aman diabaikan mobile) —
            // dibutuhin buat nampilin worksheet & rincian ketidakpastian.

            // Sertifikat sesi ini, kalau udah terbit. `pdf_url` siap-pakai biar
            // layar detail sesi bisa langsung nawarin unduh tanpa nyusun URL
            // sendiri dari `certificate_id`. null selama belum `terbit`.
            'sertifikat' => $this->certificate ? [
                'id' => $this->certificate->id,
                'nomor' => $this->certificate->nomor,
                'status' => $this->certificate->status,
                'pdf_url' => $this->certificate->status === Certificate::STATUS_TERBIT
                    ? route('certificates.download', $this->certificate)
                    : null,
                // "Issuance Date" di sertifikat — beda dari `tanggal_kalibrasi`
                // (kalibrasi 26 Mei, sertifikat terbit 30 Mei).
                'diterbitkan_pada' => $this->certificate->diterbitkan_pada?->toDateString(),
                'berlaku_sampai' => $this->certificate->berlaku_sampai?->toDateString(),
                // QR buat layar sertifikat. `qr_payload` udah berupa URL siap
                // di-render jadi QR (`.../verify/{token}`) — mobile nggak perlu
                // nyusun URL-nya sendiri, jadi domainnya nggak bisa salah.
                'qr_token' => $this->certificate->qr_token,
                'qr_payload' => $this->certificate->qr_payload,
                // Penanda tangan yang kecetak di PDF ini (fase-2 §3c). Beku dari
                // snapshot — lihat `Certificate::penandaTangan()`.
                'penanda_tangan' => $this->certificate->penandaTangan(),
            ] : null,

            'suhu_ruang' => $this->suhu_ruang,
            'suhu_ketidakpastian' => $this->suhu_ketidakpastian,
            'kelembaban' => $this->kelembaban,
            'kelembaban_ketidakpastian' => $this->kelembaban_ketidakpastian,
            // "Env. Condition" di lembar kerja: dicatat di awal & akhir kerja.
            'suhu_awal' => $this->suhu_awal,
            'suhu_akhir' => $this->suhu_akhir,
            'kelembaban_awal' => $this->kelembaban_awal,
            'kelembaban_akhir' => $this->kelembaban_akhir,
            // Kolom `Time` di tabel Env. Condition, selalu `H:i` (lihat
            // CalibrationSession::jam()).
            'waktu_awal' => $this->waktu_awal,
            'waktu_akhir' => $this->waktu_akhir,
            'catatan_teknisi' => $this->catatan_teknisi,
            'lokasi' => $this->lokasi,
            'lokasi_nama' => $this->lokasi_nama,
            'ruangan' => $this->room ? [
                'id' => $this->room->id,
                'kode' => $this->room->kode,
                'nama' => $this->room->nama,
            ] : null,
            'standar_acuan' => self::petakanStandar($this->standard),

            // Banner merah di kepala lembar kerja ("ONE OR MORE STANDARD EXPIRED")
            // + badge per standar. Statusnya TERBURUK dari semua standar sesi ini:
            // satu yang kadaluarsa udah cukup nahan penerbitan sertifikat, jadi
            // banner-nya nggak boleh kalem cuma karena yang lain masih valid.
            'status_standar' => $this->statusStandar(
                $request->user()?->organization?->ambangPeringatanHari()
                    ?? Organization::DEFAULT_AMBANG_HARI,
            ),

            // Field administratif — diisi admin, dihapus dari layar teknisi
            // (spesifikasi poin 1). Tetap dikirim ke semua role: layar teknisi
            // yang milih nggak nampilin, bukan API yang nyembunyiin. Yang
            // dijaga backend itu siapa yang boleh NGUBAH (lihat
            // CalibrationRequest::prepareForValidation).
            'metode_kalibrasi' => $this->calibrationMethod ? [
                'id' => $this->calibrationMethod->id,
                'kode' => $this->calibrationMethod->kodeLengkap(),
                'nama' => $this->calibrationMethod->nama,
            ] : null,
            'thermohygro' => $this->thermohygro ? [
                'id' => $this->thermohygro->id,
                'nama' => $this->thermohygro->nama,
                'serial_number' => $this->thermohygro->serial_number,
            ] : null,

            // Kolom "Usage Check" di lembar kerja.
            'standar_dicek' => $this->whenLoaded(
                'standarDicek',
                fn () => $this->standarDicek
                    ->map(fn (Standard $s): array => [
                        'standard_id' => $s->id,
                        'nama' => $s->nama,
                        'serial_number' => $s->serial_number,
                        'dipakai' => (bool) $s->pivot->dipakai,
                        'keterangan' => $s->pivot->keterangan,
                    ])
                    ->values(),
            ),
            'titik' => $this->uncertaintyCalculations
                ->sortBy('titik_ke')
                ->values()
                ->map(fn (UncertaintyCalculation $titik): array => self::petakanTitik(
                    $titik,
                    $this->equipment,
                    $this->organization ?? $request->user()?->organization,
                )),

            // Status verifikasi pembacaan — cuma ikut waktu detail sesi dibuka
            // (whenLoaded), biar daftar sesi nggak kebanjiran baris pembacaan.
            // Mobile pakai ini buat tau baris OCR mana yang masih perlu
            // dikonfirmasi, walau device-nya beda dari yang nginput.
            'perlu_verifikasi' => $this->whenLoaded(
                'rawMeasurements',
                fn (): bool => $this->rawMeasurements->contains('is_verified', false),
            ),
            'pembacaan_mentah' => $this->whenLoaded(
                'rawMeasurements',
                fn () => $this->rawMeasurements
                    ->sortBy([['titik_ke', 'asc'], ['pembacaan_ke', 'asc']])
                    ->values()
                    ->map(fn (RawMeasurement $m): array => [
                        'id' => $m->id,
                        'titik_ke' => $m->titik_ke,
                        // Nilai titiknya, bukan cuma nomor barisnya. Lembar
                        // kerja di HP nyusun ulang tabelnya per TITIK UKUR —
                        // `titik_ke` itu posisi, dan posisi bisa geser begitu
                        // bentuk lembarnya berubah (varian satuan Conductivity
                        // nyusut sesudah alatnya dipilih). Tanpa kolom ini,
                        // teknisi yang mbuka draft-nya lagi nggak punya cara
                        // naruh angka balik ke baris yang benar.
                        'titik_ukur' => $m->titik_ukur,
                        // Centang standar acuan baris ini. Null = teknisi
                        // belum milih, dan itu sah buat draft.
                        'standard_id' => $m->standard_id,
                        'pembacaan_ke' => $m->pembacaan_ke,
                        'tahap' => $m->tahap,
                        'pembacaan' => $m->pembacaan,
                        // Suhu larutan waktu pembacaan diambil — kolom °C di
                        // sebelah kolom pH di lembar kerja.
                        'suhu' => $m->suhu,
                        'input_source' => $m->input_source,
                        'is_verified' => $m->is_verified,
                        'photo_path' => $m->photo_path,
                        'ocr_confidence' => $m->ocr_confidence,
                        'ocr_raw_text' => $m->ocr_raw_text,
                    ]),
            ),
        ];
    }

    /**
     * Ringkasan satu sesi: titik PENENTU (yang paling mepet batas toleransi) +
     * keputusan sesi. Kontrak minta satu objek `hasil`, walau sesinya punya
     * banyak titik ukur — rinciannya ada di `titik`.
     *
     * Dipisah jadi static supaya `POST /calibrations/preview` bisa balikin key
     * `hasil` dengan ARTI YANG SAMA. Ini penting: bentuk yang sama tapi arti beda
     * di endpoint berbeda itu jebakan paling mahal buat sisi frontend.
     *
     * @return array<string, mixed>|null
     */
    public static function petakanHasil(?UncertaintyCalculation $penentu, ?string $keputusanSesi): ?array
    {
        if ($penentu === null) {
            return null;
        }

        return [
            'rata_rata' => $penentu->rata_rata,
            'error' => $penentu->error,
            'ketidakpastian_gabungan' => $penentu->ketidakpastian_gabungan,
            'faktor_cakupan_k' => $penentu->faktor_cakupan_k,
            'ketidakpastian_diperluas' => $penentu->ketidakpastian_diperluas,
            'keputusan' => $keputusanSesi,
        ];
    }

    /**
     * Bentuk satu titik hasil hitung GUM.
     *
     * Dipisah jadi static supaya `POST /calibrations/preview` bisa balikin bentuk
     * yang SAMA PERSIS tanpa nyalin daftar fieldnya. Preview jalan di atas objek
     * `UncertaintyCalculation` yang belum disimpen, jadi jangan sentuh `$titik->id`
     * atau apa pun yang cuma ada sesudah insert.
     *
     * @return array<string, mixed>
     */
    public static function petakanTitik(UncertaintyCalculation $titik, ?Equipment $alat = null, ?Organization $organisasi = null): array
    {
        return [
            'titik_ke' => $titik->titik_ke,
            'titik_ukur' => $titik->titik_ukur,
            // Standar acuan yang dipakai NGITUNG titik ini.
            //
            // Sesi yang dikirim sebelum `raw_measurements.standard_id` ada
            // cuma nyimpen pilihan standarnya di sini. Buat sesi lama yang
            // dibalikin admin, ini satu-satunya sumber buat mulangin centangnya
            // ke lembar kerja teknisi.
            'standard_id' => $titik->standard_id,
            // Desimal KHUSUS titik ini — nol kalau alatnya nggak dikirim.
            //
            // Alat yang resolusinya berubah per rentang (Turbidimeter: 0,01 di
            // bawah 10 NTU, 0,1 di 10–100, 1 di atas itu) nggak bisa diwakili
            // satu angka `desimal` di level sesi. Waktu dipaksa satu, titik
            // 100 NTU kecetak `101,00` — dua digit yang alatnya nggak bisa
            // tampilkan, dan di sertifikat terakreditasi itu ngaku-ngaku
            // ketelitian. Excel master lab nulisnya `101`.
            //
            // `desimal` di level sesi TETAP dikirim buat kompatibilitas; mobile
            // versi lama yang belum baca field ini jalan seperti biasa.
            //
            // Alat yang resolusinya SERAGAM tetap kirim `null` dan ikut
            // `desimal` level sesi — kecuali profilnya nyatain angka sendiri
            // (Refractometer 5), yang di level sesi belum tentu kebaca mobile
            // versi lama.
            'desimal' => $alat?->resolusi_rentang
                ? self::desimalAlat($alat, $organisasi, $alat->resolusiPada((float) $titik->titik_ukur))
                : ($alat !== null ? self::profil($alat)?->desimalSertifikat() : null),
            // Satuan DI TITIK INI, buat alat yang nyampur satuan dalam satu
            // lembar (Conductivity: 25 & 1412 µS/cm, 111 mS/cm).
            //
            // Sebelumnya cuma `desimal` yang per titik, satuannya nggak ikut —
            // jadi layar detail, approval, & sertifikat nggak punya cara nulis
            // `25 µS/cm` vs `111 mS/cm` selain nebak dari besar angkanya.
            // Bentuk lembar kerja udah lama ngirim `baris[].satuan`; yang
            // ketinggalan jalur hasil.
            //
            // `null` buat alat bersatuan seragam — mobile jatuh ke
            // `equipment.satuan` kayak biasa, jadi pH/Turbidimeter/Chlorine/
            // Refractometer nggak berubah perilakunya.
            'satuan' => $alat !== null
                ? self::profil($alat)?->satuanTitik((float) $titik->titik_ukur, $alat)
                : null,
            // Koreksi negatif yang membulat ke nol dicetak `-0,0` atau `0,0` —
            // beda per alat, dibaca dari master masing-masing (lihat
            // `CalibrationProfile::tandaNolDicetak()`).
            //
            // Ikut dikirim ke sini, bukan cuma ke snapshot sertifikat: layar
            // riwayat & approval di HP nampilin tabel Calibration Report yang
            // SAMA, dan kalau angkanya beda dari PDF-nya, yang ketahuan duluan
            // justru teknisi yang lagi mriksa hasilnya sendiri.
            'tanda_nol' => $alat !== null
                ? (self::profil($alat)?->tandaNolDicetak() ?? true)
                : true,
            'rata_rata' => $titik->rata_rata,
            'error' => $titik->error,
            'koreksi' => $titik->koreksi,
            'standar_deviasi' => $titik->standar_deviasi,
            'jumlah_pengulangan' => $titik->jumlah_pengulangan,
            'type_a' => $titik->type_a,
            'type_b' => $titik->type_b,
            // Urutan key dinormalisasi, JANGAN dikirim apa adanya.
            //
            // Kolomnya JSON, dan MySQL nyimpen JSON dalam format biner yang
            // ngurutin ulang key-nya — jadi baris yang udah lewat DB balik dengan
            // urutan beda dari yang baru dihitung di memori (SQLite nyimpen apa
            // adanya, makanya ini nggak kelihatan di test). Efeknya: respons
            // endpoint yang sama bisa beda urutan key tergantung driver, dan
            // urutan di kontrak-api.md jadi nggak bisa dipegang.
            //
            // Buat konsumen JSON biasa (Dart Map) urutan nggak ngaruh, tapi ini
            // bikin `POST /calibrations/preview` mustahil byte-identik sama sesi
            // tersimpan — padahal itu justru jaminan yang dijual endpoint itu.
            'type_b_components' => array_map(fn (array $k): array => [
                'sumber' => $k['sumber'] ?? null,
                'keterangan' => $k['keterangan'] ?? null,
                'distribusi' => $k['distribusi'] ?? null,
                'nilai' => $k['nilai'] ?? null,
            ], $titik->type_b_components ?? []),
            'ketidakpastian_gabungan' => $titik->ketidakpastian_gabungan,
            'faktor_cakupan_k' => $titik->faktor_cakupan_k,
            // Derajat kebebasan efektif (Welch–Satterthwaite) — angka yang
            // NENTUIN k lewat `TINV(0,05; veff)`. Dikirim biar layar detail
            // bisa nunjukin rantai hitungnya utuh: tanpa veff, `k = 3,18`
            // muncul tanpa asal-usul dan nggak ada yang bisa ngecek ulang.
            'derajat_kebebasan_efektif' => $titik->derajat_kebebasan_efektif === null
                ? null
                : (float) $titik->derajat_kebebasan_efektif,
            'ketidakpastian_diperluas' => $titik->ketidakpastian_diperluas,
            'toleransi' => $titik->toleransi,
            'keputusan' => $titik->keputusan,
            'metode' => $titik->metode,
            // Titik yang standarnya beda dari standar default sesi (mis.
            // buffer pH 4/7/10) nampilin punyanya sendiri di sini.
            'standar_acuan' => self::petakanStandar($titik->standard),
        ];
    }

    /**
     * Standar acuan yang di-embed — dipakai di level sesi DAN di tiap titik.
     *
     * Isinya empat kolom tabel "Standard used" di sertifikat: Name, Merk/Type,
     * Serial Number, Traceable to. Sebelumnya cuma `nama` + `no_sertifikat`, jadi
     * layar pencocokan sertifikat kepaksa nembak `GET /standards/{id}` lagi cuma
     * buat ngisi dua kolom.
     *
     * Dipisah jadi static supaya level sesi & per-titik nggak bisa beda bentuk —
     * pernah kejadian dua-duanya disalin lalu cuma satu yang diperbarui.
     *
     * @return array<string, mixed>|null
     */
    public static function petakanStandar(?Standard $standar): ?array
    {
        if ($standar === null) {
            return null;
        }

        return [
            'id' => $standar->id,
            'nama' => $standar->nama,
            'no_sertifikat' => $standar->no_sertifikat,
            'merk' => $standar->merk,
            'model' => $standar->model,
            // Kolom "Merk/Type" di sertifikat itu satu kolom, dua data. Digabung
            // di sini — bukan di mobile — biar dua klien nggak bikin dua gaya
            // penulisan buat kolom yang sama di dokumen resmi. Yang kosong
            // dilewat, jadi nggak ada "Supelco/" atau "/Merck" yang menggantung.
            'merk_type' => collect([$standar->merk, $standar->model])
                ->filter(fn (?string $bagian): bool => filled($bagian))
                ->implode('/') ?: null,
            'serial_number' => $standar->serial_number,
            'tertelusur_ke' => $standar->tertelusur_ke,
        ];
    }

    /**
     * Berapa desimal angka sesi ditulis — aturan yang SAMA PERSIS dipakai
     * `CertificateSnapshotBuilder::bangun()`.
     *
     * Dipisah jadi satu tempat karena pernah kepisah: builder ngikutin timpaan
     * profil alat, resource ini nggak. Akibatnya sesi Refractometer `2211.11.R`
     * kebaca `desimal: 4` lewat API — `0,00053` runtuh jadi `0,0005` di layar —
     * sementara PDF-nya bener nyetak 5 desimal. Angka yang sama, dua tampilan,
     * dan yang salah justru yang dipegang teknisi waktu ngecek.
     */
    private static function desimalAlat(?Equipment $alat, ?Organization $organisasi, ?float $resolusi): ?int
    {
        $timpaan = $alat !== null ? self::profil($alat)?->desimalSertifikat() : null;

        if ($timpaan !== null) {
            return $timpaan;
        }

        return $organisasi
            ? $organisasi->desimalSertifikat($resolusi)
            : ($resolusi !== null ? Angka::desimalDariResolusi($resolusi) : null);
    }

    private static function profil(Equipment $alat): ?CalibrationProfile
    {
        return app(CalibrationProfileRegistry::class)->untukAlat($alat);
    }
}
