<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            // Field "langkah awal" yang diisi manual/dari foto worksheet.
            'nomor_sertifikat' => $this->nomor_sertifikat,
            'dihitung_oleh' => $this->dihitung_oleh,
            'metode_kalibrasi' => $this->metode_kalibrasi,
            // Digemukin biar layar pencocokan & cetak sertifikat nggak perlu
            // nembak GET /equipments lagi cuma buat ngisi kop.
            'equipment' => [
                'id' => $this->equipment?->id,
                'nama_alat' => $this->equipment?->nama_alat,
                'serial_number' => $this->equipment?->serial_number,
                'merk' => $this->equipment?->merk,
                'model' => $this->equipment?->model,
                'no_identifikasi' => $this->equipment?->no_identifikasi,
                'range_min' => $this->equipment?->range_min,
                'range_max' => $this->equipment?->range_max,
                'satuan' => $this->equipment?->satuan,
                'rentang_ukur' => $this->equipment?->rentangUkur(),
                'resolusi' => $this->equipment?->resolusi,
                'toleransi' => $this->equipment?->toleransi,
                'pelanggan' => [
                    'id' => $this->equipment?->customer?->id,
                    'nama' => $this->equipment?->customer?->nama,
                    'alamat' => $this->equipment?->customer?->alamat,
                ],
            ],
            'teknisi' => [
                'id' => $this->teknisi?->id,
                'nama' => $this->teknisi?->name,
                // Kolom "Technician ID" di sertifikat isinya inisial (mis. "DR"),
                // bukan nama panjang.
                'employee_id' => $this->teknisi?->employee_id,
            ],
            // Penanda tangan sertifikat = admin yang meng-approve sesi ini.
            // "Manajer Teknis" itu ATRIBUT (`department`), bukan role tersendiri —
            // keputusan pemilik produk, lihat MATRIKS-PERAN.md. null selama sesi
            // belum disetujui.
            'penanda_tangan' => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'nama' => $this->reviewer->name,
                'jabatan' => $this->reviewer->department,
                'ttd_url' => $this->reviewer->ttd_path
                    ? Storage::disk('public')->url($this->reviewer->ttd_path)
                    : null,
            ] : null,

            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toIso8601ZuluString(),
            'tanggal_terima' => $this->tanggal_terima?->toIso8601ZuluString(),
            'status' => $this->status,
            'input_method' => $this->input_method,

            // Sesi bisa punya banyak titik ukur, tapi kontrak minta SATU objek
            // `hasil`. Yang dikirim adalah titik penentu — yang paling mepet ke
            // batas toleransi. Rincian tiap titik ada di `titik` di bawah.
            'hasil' => $penentu ? [
                'rata_rata' => $penentu->rata_rata,
                'error' => $penentu->error,
                'ketidakpastian_gabungan' => $penentu->ketidakpastian_gabungan,
                'faktor_cakupan_k' => $penentu->faktor_cakupan_k,
                'ketidakpastian_diperluas' => $penentu->ketidakpastian_diperluas,
                'keputusan' => $this->keputusan,
            ] : null,

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
                // Buat mobile nge-render QR verifikasi sendiri di layar (backend
                // nggak ngirim gambar). `qr_url` itu alamat yang di-encode ke QR —
                // halaman verifikasi publik, bisa dibuka siapa aja tanpa login.
                'qr_token' => $this->certificate->qr_token,
                'qr_url' => $this->certificate->qr_payload,
                // Kolom "Issuance Date" di sertifikat.
                'tanggal_terbit' => $this->certificate->diterbitkan_pada?->toIso8601ZuluString(),
                'berlaku_sampai' => $this->certificate->berlaku_sampai?->toIso8601ZuluString(),
                // Dikembar dari `penanda_tangan` di level atas — kontrak mobile
                // bacanya dari sini (blok tanda tangan nempel ke sertifikat).
                // Yang di level atas tetap ada karena kepakai buat sesi yang
                // udah disetujui tapi PDF-nya belum kelar digenerate.
                'penanda_tangan' => $this->reviewer ? [
                    'nama' => $this->reviewer->name,
                    'jabatan' => $this->reviewer->department,
                    'ttd_url' => $this->reviewer->ttd_path
                        ? Storage::disk('public')->url($this->reviewer->ttd_path)
                        : null,
                ] : null,
            ] : null,

            'suhu_ruang' => $this->suhu_ruang,
            'kelembaban' => $this->kelembaban,
            // Kondisi lingkungan rinci (worksheet pH): awal/akhir + koreksi
            // (dari sertifikat thermohygro) + nilai terkoreksi + U95%. Field
            // lama `suhu_ruang`/`kelembaban` = rata-ratanya, tetap ada di atas.
            'kondisi_lingkungan' => [
                'suhu' => [
                    'awal' => $this->suhu_ruang_awal,
                    'akhir' => $this->suhu_ruang_akhir,
                    'rata_rata' => $this->suhu_ruang,
                    'koreksi' => $this->suhu_ruang_koreksi,
                    'nilai_terkoreksi' => $this->suhu_ruang !== null && $this->suhu_ruang_koreksi !== null
                        ? $this->suhu_ruang + $this->suhu_ruang_koreksi
                        : $this->suhu_ruang,
                    'u95' => $this->suhu_ruang_u95,
                    'satuan' => '°C',
                ],
                'kelembaban' => [
                    'awal' => $this->kelembaban_awal,
                    'akhir' => $this->kelembaban_akhir,
                    'rata_rata' => $this->kelembaban,
                    'koreksi' => $this->kelembaban_koreksi,
                    'nilai_terkoreksi' => $this->kelembaban !== null && $this->kelembaban_koreksi !== null
                        ? $this->kelembaban + $this->kelembaban_koreksi
                        : $this->kelembaban,
                    'u95' => $this->kelembaban_u95,
                    'satuan' => '%RH',
                ],
                'thermohygro' => $this->thermohygro,
            ],
            'lokasi' => $this->lokasi,
            // Ruangan lab spesifik. `lokasi` di atas cuma lab vs onsite.
            'ruangan' => $this->room ? ['id' => $this->room->id, 'nama' => $this->room->nama] : null,
            'standar_acuan' => $this->standard ? [
                'id' => $this->standard->id,
                'nama' => $this->standard->nama,
                'no_sertifikat' => $this->standard->no_sertifikat,
                'serial_number' => $this->standard->serial_number,
                // Kolom "Merk/Type" di sertifikat — digabung di sini biar mobile
                // nggak nyusun sendiri (gampang beda spasi antar layar).
                'merk_type' => trim(($this->standard->merk ?? '').' '.($this->standard->model ?? '')) ?: null,
                'tertelusur_ke' => $this->standard->tertelusur_ke,
            ] : null,
            'titik' => $this->uncertaintyCalculations
                ->sortBy('titik_ke')
                ->values()
                ->map(fn (UncertaintyCalculation $titik): array => [
                    'titik_ke' => $titik->titik_ke,
                    'titik_ukur' => $titik->titik_ukur,
                    'rata_rata' => $titik->rata_rata,
                    'error' => $titik->error,
                    'koreksi' => $titik->koreksi,
                    'standar_deviasi' => $titik->standar_deviasi,
                    'jumlah_pengulangan' => $titik->jumlah_pengulangan,
                    'type_a' => $titik->type_a,
                    'type_b' => $titik->type_b,
                    'type_b_components' => $titik->type_b_components,
                    'ketidakpastian_gabungan' => $titik->ketidakpastian_gabungan,
                    'faktor_cakupan_k' => $titik->faktor_cakupan_k,
                    'ketidakpastian_diperluas' => $titik->ketidakpastian_diperluas,
                    'toleransi' => $titik->toleransi,
                    'keputusan' => $titik->keputusan,
                    'metode' => $titik->metode,
                    // Titik yang standarnya beda dari standar default sesi (mis.
                    // buffer pH 4/7/10) nampilin punyanya sendiri di sini.
                    'standar_acuan' => $titik->standard ? [
                        'id' => $titik->standard->id,
                        'nama' => $titik->standard->nama,
                        'no_sertifikat' => $titik->standard->no_sertifikat,
                        'serial_number' => $titik->standard->serial_number,
                        'merk_type' => trim(($titik->standard->merk ?? '').' '.($titik->standard->model ?? '')) ?: null,
                        'tertelusur_ke' => $titik->standard->tertelusur_ke,
                    ] : null,
                ]),

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
                        // Suhu larutan tiap pembacaan (pasangan pH/°C) — null
                        // buat alat yang nggak nyatet suhu per baca.
                        'suhu' => $m->suhu,
                        'input_source' => $m->input_source,
                        'is_verified' => $m->is_verified,
                        'photo_path' => $m->photo_path,
                        'ocr_confidence' => $m->ocr_confidence,
                        'ocr_raw_text' => $m->ocr_raw_text,
                    ]),
            ),

            // Ringkasan tahap SEBELUM adjustment (as-found) per titik: rata-rata,
            // koreksi, STDEV — buat tabel "Before Adjustment" di worksheet/sertifikat.
            // Beda dari `titik` di atas yang tahap SESUDAH (yang disertifikasi).
            'titik_sebelum' => $this->whenLoaded(
                'rawMeasurements',
                fn () => $this->ringkasanSebelumAdjustment(),
            ),
        ];
    }

    /**
     * Ringkas pembacaan tahap sebelum adjustment jadi rata-rata/koreksi/STDEV
     * per titik. Nggak disimpen di DB (bukan hasil resmi) — diturunkan dari
     * `rawMeasurements` yang tahap-nya `sebelum_adjustment`.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function ringkasanSebelumAdjustment(): \Illuminate\Support\Collection
    {
        return $this->rawMeasurements
            ->where('tahap', 'sebelum_adjustment')
            ->groupBy('titik_ke')
            ->map(function ($rows) {
                $nilai = $rows->pluck('pembacaan')->map(fn ($v): float => (float) $v)->values();
                $n = $nilai->count();
                $rata = $n > 0 ? $nilai->sum() / $n : null;
                $titikUkur = (float) $rows->first()->titik_ukur;
                $stdev = $n > 1
                    ? sqrt($nilai->map(fn (float $x): float => ($x - $rata) ** 2)->sum() / ($n - 1))
                    : 0.0;

                return [
                    'titik_ke' => (int) $rows->first()->titik_ke,
                    'titik_ukur' => $titikUkur,
                    'rata_rata' => $rata,
                    'koreksi' => $rata !== null ? $rata - $titikUkur : null,
                    'standar_deviasi' => $stdev,
                    'jumlah_pengulangan' => $n,
                ];
            })
            ->sortBy('titik_ke')
            ->values();
    }
}
