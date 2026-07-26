<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\RawMeasurement;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
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
            'equipment' => [
                'id' => $this->equipment?->id,
                'nama_alat' => $this->equipment?->nama_alat,
                'serial_number' => $this->equipment?->serial_number,
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
            'status' => $this->status,
            'input_method' => $this->input_method,

            // Sesi bisa punya banyak titik ukur, tapi kontrak minta SATU objek
            // `hasil`. Yang dikirim adalah titik penentu — yang paling mepet ke
            // batas toleransi. Rincian tiap titik ada di `titik` di bawah.
            'hasil' => self::petakanHasil($penentu, $this->keputusan),

            'catatan_revisi' => $this->catatan_revisi,
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
            'catatan_teknisi' => $this->catatan_teknisi,
            'lokasi' => $this->lokasi,
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
                ->map(fn (UncertaintyCalculation $titik): array => self::petakanTitik($titik)),

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
    public static function petakanTitik(UncertaintyCalculation $titik): array
    {
        return [
            'titik_ke' => $titik->titik_ke,
            'titik_ukur' => $titik->titik_ukur,
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
}
