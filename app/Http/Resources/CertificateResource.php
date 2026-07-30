<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuk sertifikat buat mobile. Superset — kalau kontrak di repo mobile ngunci
 * bentuknya, selarasin di sana. `pdf_url` sengaja URL absolut siap-pakai biar
 * mobile nggak nyusun path sendiri (gampang salah, dan path storage-nya privat).
 *
 * @mixin Certificate
 */
class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'calibration_session_id' => $this->calibration_session_id,
            'nomor' => $this->nomor,
            'status' => $this->status,
            // Berapa desimal angka hasil ditulis di sertifikat ini. Diambil dari
            // SNAPSHOT, bukan dihitung ulang: sertifikat yang udah terbit nggak
            // boleh berubah bentuk gara-gara pengaturan organisasi atau resolusi
            // alat diubah sesudahnya. Dua sertifikat dari tanggal berbeda boleh
            // beda desimal, dan itu bener.
            //
            // Sertifikat lama yang snapshot-nya kosong jatuh ke hitungan hidup —
            // lebih baik angka yang wajar daripada null yang bikin layar nebak.
            'desimal' => $this->snapshot['desimal']
                ?? $this->organization?->desimalSertifikat(
                    $this->session?->equipment?->resolusi !== null
                        ? (float) $this->session->equipment->resolusi
                        : null,
                ),
            // Ikut nempel biar layar daftar sertifikat nggak perlu buka sesi satu-satu.
            'keputusan' => $this->session?->keputusan,
            // Tanggal POLOS (`2024-05-30`), jangan dibalikin ke ISO. Kolomnya cast
            // `date` — nggak ada jamnya — dan `toIso8601ZuluString()` di zona
            // Jakarta bikin `30 Mei` keluar jadi `2024-05-29T17:00:00Z`. Di
            // sertifikat, tanggal terbit yang salah sehari itu cacat dokumen
            // terkendali, bukan bug tampilan.
            'diterbitkan_pada' => $this->diterbitkan_pada?->toDateString(),
            'berlaku_sampai' => $this->berlaku_sampai?->toDateString(),
            'kadaluarsa' => (bool) $this->berlaku_sampai?->isPast(),
            'qr_token' => $this->qr_token,
            'qr_payload' => $this->qr_payload,

            // Siapa yang tanda tangan sertifikat ini (fase-2 §3c). Beku dari
            // snapshot, bukan dari pengaturan organisasi yang berlaku sekarang —
            // alasannya di `Certificate::penandaTangan()`. `null` kalau belum terbit.
            'penanda_tangan' => $this->penandaTangan(),

            // Cuma ada kalau PDF-nya emang udah jadi. Kalau `gagal`/`menunggu_generate`,
            // null — mobile munculin tombol retry, bukan tombol unduh.
            'pdf_url' => $this->status === Certificate::STATUS_TERBIT
                ? route('certificates.download', $this->resource)
                : null,

            'alat' => [
                'nama_alat' => $this->session?->equipment?->nama_alat,
                'serial_number' => $this->session?->equipment?->serial_number,
            ],

            // Kontak pelanggan buat tombol kirim. Dua-duanya boleh null —
            // layar yang milih nampilin tombol mana, bukan nebak nomor sendiri.
            //
            // Kenapa `pemilik_nama` sesi menang atas master pelanggan: yang
            // dicetak di sertifikat juga pakai isian teknisi (lihat
            // `CertificateSnapshotBuilder`), jadi nama di pesan WhatsApp mesti
            // sama dengan nama di dokumennya — kalau beda, penerima ngira salah
            // kirim.
            'pelanggan' => [
                'nama' => $this->session?->pemilik_nama
                    ?: $this->session?->equipment?->customer?->nama,
                'email' => $this->session?->equipment?->customer?->email,
                'telepon' => $this->session?->equipment?->customer?->telepon,
            ],
        ];
    }
}
