<?php

namespace App\Services;

use App\Events\PerubahanDataOrganisasi;
use App\Jobs\ReviseCertificate;
use App\Models\Certificate;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Terbitkan REVISI sertifikat (§38, keputusan D1/D2/D6).
 *
 * Revisi = baris sertifikat BARU (`revision_of` → pendahulu langsung, nomor
 * `<asli>-R<n>`) yang snapshot-nya DISALIN dari pendahulunya, lalu cuma kunci
 * daftar putih di bawah yang ditimpa. Angka pengukuran tidak pernah dihitung
 * ulang di jalur ini — `hasil[]`, standar, keputusan, dan seluruh budget ikut
 * apa adanya, byte per byte.
 *
 * Dipakai dua pintu: tombol Revisi admin (`CertificateController::revisi`) dan
 * penerimaan koreksi dari pelanggan (`KoreksiPelangganController::terima`).
 * Satu tempat yang memutuskan boleh/tidak, supaya dua pintu itu tidak pernah
 * punya aturan yang berbeda.
 */
class RevisiSertifikat
{
    /**
     * Kunci API → kunci `snapshot.header`. D2: cuma data administratif.
     * `berlaku_sampai` bukan kunci header (tidak dicetak) — dia kolom baris.
     */
    public const KUNCI_HEADER = [
        'pemilik' => 'owner',
        'alamat' => 'address',
        'merk' => 'manufacturer',
        'tipe' => 'model_type',
        'nomor_seri' => 'serial_number',
        'lokasi_kalibrasi' => 'calibration_location',
        'tanggal_kalibrasi' => 'calibration_date',
    ];

    public const KUNCI_BOLEH = [
        'pemilik', 'alamat', 'merk', 'tipe', 'nomor_seri', 'lokasi_kalibrasi', 'tanggal_kalibrasi',
        'berlaku_sampai',
    ];

    /** Label untuk layar & catatan audit. */
    public const LABEL = [
        'pemilik' => 'Nama pemilik',
        'alamat' => 'Alamat',
        'merk' => 'Merk',
        'tipe' => 'Model/tipe',
        'nomor_seri' => 'Nomor seri',
        'lokasi_kalibrasi' => 'Lokasi kalibrasi',
        'tanggal_kalibrasi' => 'Tanggal kalibrasi',
        'berlaku_sampai' => 'Berlaku sampai',
    ];

    /**
     * Nilai yang TERCETAK di sertifikat ini untuk tiap kunci yang boleh
     * direvisi. Null kalau snapshot-nya kosong (sertifikat lama).
     *
     * @return array<string, string|null>|null
     */
    public static function dataCetak(Certificate $sertifikat): ?array
    {
        $header = $sertifikat->snapshot['header'] ?? null;

        if (! is_array($header) || $header === []) {
            return null;
        }

        $hasil = [];

        foreach (self::KUNCI_HEADER as $kunci => $kolom) {
            $nilai = $header[$kolom] ?? null;
            $hasil[$kunci] = $nilai === null || $nilai === '' ? null : (string) $nilai;
        }

        $hasil['berlaku_sampai'] = $sertifikat->berlaku_sampai?->toDateString();

        return $hasil;
    }

    /**
     * Buat baris revisi & masukkan ke antrean render.
     *
     * @param  array<string, mixed>  $perubahan  kunci API (lihat KUNCI_BOLEH)
     *
     * @throws ValidationException kalau keadaan/isi tidak memenuhi syarat
     */
    public function terbitkan(
        Certificate $asal,
        array $perubahan,
        string $alasan,
        ?string $catatanPelanggan,
        User $oleh,
    ): Certificate {
        $this->tolakKunciAsing($perubahan);

        $revisi = DB::transaction(function () use ($asal, $perubahan, $alasan, $catatanPelanggan, $oleh): Certificate {
            // Dikunci & dibaca ulang: dua admin yang menekan Revisi bersamaan
            // tidak boleh melahirkan dua `-R1`.
            /** @var Certificate $asal */
            $asal = Certificate::query()->with('organization')->whereKey($asal->getKey())->lockForUpdate()->firstOrFail();

            if (! $asal->bisaDiubahStatusnya()) {
                throw ValidationException::withMessages(['sertifikat' => $this->alasanTidakBisa($asal)]);
            }

            $cetak = self::dataCetak($asal);

            if ($cetak === null) {
                throw ValidationException::withMessages([
                    'sertifikat' => 'Sertifikat ini terbit sebelum snapshot ada, jadi tidak bisa direvisi dari aplikasi. Terbitkan dari kalibrasi baru.',
                ]);
            }

            $berubah = self::yangBerubah($cetak, $perubahan);

            if ($berubah === []) {
                throw ValidationException::withMessages([
                    'perubahan' => 'Tidak ada yang berubah dari yang tercetak di sertifikat.',
                ]);
            }

            $tanggalKalibrasi = array_key_exists('tanggal_kalibrasi', $berubah) ? $berubah['tanggal_kalibrasi'] : $cetak['tanggal_kalibrasi'];
            $berlakuSampai = array_key_exists('berlaku_sampai', $berubah) ? $berubah['berlaku_sampai'] : $cetak['berlaku_sampai'];

            if ($tanggalKalibrasi !== null && $berlakuSampai !== null
                && Carbon::parse($berlakuSampai)->lte(Carbon::parse($tanggalKalibrasi))) {
                throw ValidationException::withMessages([
                    'perubahan.berlaku_sampai' => 'Tanggal berlaku harus sesudah tanggal kalibrasi.',
                ]);
            }

            $ke = (int) $asal->revisi_ke + 1;
            $nomor = Certificate::nomorDasar((string) $asal->nomor).'-R'.$ke;
            $token = $this->tokenUnik();
            $payload = rtrim((string) config('app.url'), '/')."/verify/{$token}";

            return Certificate::create([
                'organization_id' => $asal->organization_id,
                'calibration_session_id' => $asal->calibration_session_id,
                'issued_by' => $oleh->id,
                'revision_of' => $asal->id,
                'revisi_ke' => $ke,
                'nomor' => $nomor,
                'qr_token' => $token,
                'qr_payload' => $payload,
                'snapshot' => $this->snapshotRevisi($asal, $berubah, $nomor, $ke, $token, $payload),
                // Hasil pemeriksaan hitung ulang milik pengukurannya, dan
                // pengukurannya tidak berubah — disalin, bukan diperiksa ulang.
                'validasi' => $asal->validasi,
                'diterbitkan_pada' => now()->toDateString(),
                'berlaku_sampai' => $berlakuSampai,
                'status' => Certificate::STATUS_MENUNGGU_GENERATE,
                'alasan_revisi' => $alasan,
                'catatan_pelanggan' => filled($catatanPelanggan) ? $catatanPelanggan : null,
            ]);
        });

        $this->antrekan($revisi);

        PerubahanDataOrganisasi::siarkanAman($revisi->organization_id, 'sertifikat', 'direvisi', $revisi->id);

        return $revisi;
    }

    /**
     * Masukkan render ke antrean. Antrean yang menolak TIDAK membatalkan
     * baris revisinya: statusnya jadi `gagal`, dan tombol "Terbitkan ulang"
     * (`CertificateController::retry`) yang sama dipakai untuk mencobanya lagi.
     */
    public function antrekan(Certificate $revisi): void
    {
        try {
            ReviseCertificate::dispatch($revisi->id);
        } catch (\Throwable $e) {
            report($e);
            $revisi->update(['status' => Certificate::STATUS_GAGAL]);
        }
    }

    /**
     * Cuma kunci yang nilainya BENAR-BENAR beda dari yang tercetak. Dipakai
     * juga oleh koreksi pelanggan untuk mencatat "lama → baru".
     *
     * @param  array<string, string|null>  $cetak
     * @param  array<string, mixed>  $perubahan
     * @return array<string, string|null>
     */
    public static function yangBerubah(array $cetak, array $perubahan): array
    {
        $hasil = [];

        foreach ($perubahan as $kunci => $nilai) {
            $baru = $nilai === null ? null : trim((string) $nilai);
            $baru = $baru === '' ? null : $baru;

            if (in_array($kunci, ['tanggal_kalibrasi', 'berlaku_sampai'], true) && $baru !== null) {
                $baru = Carbon::parse($baru)->toDateString();
            }

            if ($baru !== ($cetak[$kunci] ?? null)) {
                $hasil[$kunci] = $baru;
            }
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $perubahan
     *
     * @throws ValidationException
     */
    private function tolakKunciAsing(array $perubahan): void
    {
        $asing = array_diff(array_keys($perubahan), self::KUNCI_BOLEH);

        if ($asing === []) {
            return;
        }

        $galat = [];

        foreach ($asing as $kunci) {
            $galat["perubahan.{$kunci}"] = 'Bagian ini tidak boleh diubah lewat revisi — cuma data administratif (nama & alamat pemilik, merk/tipe/nomor seri, lokasi, tanggal kalibrasi, masa berlaku).';
        }

        throw ValidationException::withMessages($galat);
    }

    /**
     * Salin snapshot pendahulu, timpa kunci daftar putih, lalu identitas
     * dokumennya (nomor, QR, tanggal terbit, penandatangan hari ini — D6).
     *
     * @param  array<string, string|null>  $berubah
     * @return array<string, mixed>
     */
    private function snapshotRevisi(
        Certificate $asal,
        array $berubah,
        string $nomor,
        int $ke,
        string $token,
        string $payload,
    ): array {
        $snapshot = (array) $asal->snapshot;

        foreach ($berubah as $kunci => $nilai) {
            if (isset(self::KUNCI_HEADER[$kunci])) {
                $snapshot['header'][self::KUNCI_HEADER[$kunci]] = $nilai;
            }
        }

        $snapshot['header']['certificate_number'] = $nomor;
        $snapshot['header']['catatan_revisi'] = "Revisi ke-{$ke}, menggantikan {$asal->nomor}";

        $snapshot['meta']['qr_token'] = $token;
        $snapshot['meta']['qr_payload'] = $payload;
        $snapshot['meta']['revisi'] = [
            'ke' => $ke,
            'menggantikan' => $asal->nomor,
            'kunci_berubah' => array_keys($berubah),
        ];

        $snapshot['footer']['issuance_date'] = now()->toDateString();

        // D6 — penandatangan resmi PADA HARI REVISI, dari pengaturan
        // organisasi. Kalau lab belum mengisinya, nama di lembar asli yang
        // dipertahankan: lebih baik nama yang pernah sah daripada kosong.
        $pengaturan = (array) ($asal->organization?->settings ?? []);
        $nama = $pengaturan[Organization::KEY_PENANDATANGAN_NAMA] ?? null;

        if (filled($nama)) {
            $snapshot['footer']['penandatangan'] = $nama;
            $snapshot['footer']['jabatan'] = $pengaturan['penandatangan_jabatan']
                ?? $snapshot['footer']['jabatan']
                ?? 'Technical Manager';
        }

        return $snapshot;
    }

    private function alasanTidakBisa(Certificate $asal): string
    {
        return match (true) {
            $asal->status === Certificate::STATUS_DIBATALKAN => 'Sertifikat ini sudah dibatalkan.',
            $asal->status !== Certificate::STATUS_TERBIT => 'Sertifikat ini belum terbit.',
            default => 'Sertifikat ini sudah punya revisi. Yang direvisi harus revisi terakhirnya.',
        };
    }

    private function tokenUnik(): string
    {
        do {
            $token = Str::lower(Str::random(10));
        } while (Certificate::where('qr_token', $token)->exists());

        return $token;
    }
}
