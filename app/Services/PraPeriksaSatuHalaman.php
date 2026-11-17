<?php

namespace App\Services;

use App\Exceptions\SertifikatLebihSatuHalamanException;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gerbang PRA-APPROVE: sertifikat yang terbukti tidak muat SATU halaman
 * ditolak SEBELUM sesinya disetujui.
 *
 * ## Kenapa ada
 *
 * Sejak #249 sertifikat yang meluap tidak lagi terbit sebagai "Page : 1 of 1"
 * palsu — `SertifikatSatuHalaman` melempar dan `GenerateCertificate` menandai
 * sertifikatnya `gagal`. Itu menutup dokumen yang berbohong, tapi menyisakan
 * hari-H yang buruk: sesinya SUDAH `disetujui`, nomor sertifikat (diketik admin,
 * K-50-04) SUDAH terpakai, tombol "Setujui" sudah tidak ada, dan PDF-nya tidak
 * mungkin jadi sampai isinya dipangkas. Di sini kegagalan yang sama dipindah ke
 * depan pintu: tidak ada status berubah, tidak ada nomor terbuang, tidak ada
 * job dikirim.
 *
 * ## Satu aturan, bukan dua
 *
 * Gerbang ini TIDAK menghitung tinggi sendiri. Dia merakit sertifikat sementara
 * (tidak disimpan) dengan `CertificateSnapshotBuilder` yang sama, lalu bertanya
 * ke `SertifikatSatuHalaman::terbuktiTidakMuat()` — yang menolak hanya bila
 * `isi()`, fungsi jalur terbit, memang akan melempar.
 *
 * ## Gagal terbuka, kecuali untuk satu hal
 *
 * Satu-satunya vonis yang menahan approve adalah "terbukti tidak muat". Segala
 * galat lain di sini (relasi tak termuat, render dompdf gagal, kunci tata letak
 * salah ketik yang memang dilempar `bangun()`) dicatat lalu DILEWATKAN: jalur
 * terbit akan menemui galat yang sama dan menanganinya seperti dulu (sertifikat
 * `gagal`, admin dikabari). Gerbang yang sendirinya rusak tidak boleh menjadi
 * alasan lab berhenti menyetujui sesi. Sama untuk dua hal lain: sakelar
 * `sertifikat.pra_approve_satu_halaman` mati, dan gerbang lain sedang merender
 * (kunci `KUNCI_RENDER`, tanpa menunggu) — jatah memori produksi 512 MB dibagi
 * dengan queue worker.
 */
class PraPeriksaSatuHalaman
{
    public const KODE = 'sertifikat_lebih_satu_halaman';

    /** Kunci render gerbang (`Cache::lock`): satu render gerbang pada satu waktu. */
    public const KUNCI_RENDER = 'pra-periksa-satu-halaman';

    /**
     * Relasi yang dimuat `GenerateCertificate::handle()` sebelum membangun snapshot.
     * Disalin apa adanya: snapshot sementara harus lahir dari data yang sama.
     */
    private const RELASI = [
        'equipment.customer', 'organization', 'teknisi', 'reviewer', 'standard', 'thermohygro',
        'standarDicek', 'calibrationMethod', 'room', 'rawMeasurements', 'uncertaintyCalculations.standard',
    ];

    public function __construct(private readonly CertificateSnapshotBuilder $snapshot) {}

    /**
     * @param  string|null  $nomorSertifikat  nomor yang DIKETIK admin (null bila sertifikat ditarik: nomor revisi)
     * @param  Certificate|null  $dasarTarikan  sertifikat ditarik yang digantikan (K-50-03), atau null
     * @return array{kode: string, message: string, halaman: int}|null null = tidak ada alasan menahan
     */
    public function periksa(CalibrationSession $sesi, User $penyetuju, ?string $nomorSertifikat, ?Certificate $dasarTarikan = null): ?array
    {
        if (! config('sertifikat.pra_approve_satu_halaman', true)) {
            return null;
        }

        $this->longgarkanBatasWaktu();

        // Satu render gerbang pada satu waktu. Jatah memori kotak produksi (512 MB)
        // dibagi dengan queue worker yang juga merender; beberapa approve bersamaan
        // yang masing-masing merender itu resep OOM (alasan yang sama dengan kunci
        // `BerkasPdfSertifikat`). Yang tidak kebagian kunci TIDAK menunggu dan tidak
        // merender: approve-nya jalan seperti sebelum PK-6 (gagal terbuka). Begitu
        // juga bila cache-nya sendiri bermasalah.
        $kunci = $this->ambilKunci($sesi);

        if ($kunci === null) {
            return null;
        }

        try {
            $sertifikat = $this->sertifikatSementara($sesi, $penyetuju, $nomorSertifikat, $dasarTarikan);
            $penolakan = app(SertifikatSatuHalaman::class)->terbuktiTidakMuat($sertifikat);
        } catch (\Throwable $e) {
            // Gagal terbuka — lihat docblock kelas. Dicatat, tidak disembunyikan.
            Log::warning('Pra-periksa satu halaman dilewati karena galat; jalur terbit yang memutuskan.', [
                'calibration_session_id' => $sesi->getKey(),
                'galat' => $e::class,
                'pesan' => $e->getMessage(),
            ]);

            return null;
        } finally {
            try {
                $kunci->release();
            } catch (\Throwable) {
                // Kunci punya umur (120 dtk) — melepasnya tidak boleh menjatuhkan approve.
            }
        }

        if ($penolakan === null) {
            return null;
        }

        return [
            'kode' => self::KODE,
            'message' => self::pesan($penolakan),
            'halaman' => $penolakan->halamanPadat,
        ];
    }

    /** Kunci render gerbang, atau null bila sibuk / cache bermasalah (dicatat). */
    private function ambilKunci(CalibrationSession $sesi): ?Lock
    {
        try {
            $kunci = Cache::lock(self::KUNCI_RENDER, 120);

            if ($kunci->get()) {
                return $kunci;
            }

            Log::warning('Pra-periksa satu halaman dilewati: gerbang lain sedang merender.', [
                'calibration_session_id' => $sesi->getKey(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Pra-periksa satu halaman dilewati: kunci render tak bisa diambil.', [
                'calibration_session_id' => $sesi->getKey(),
                'galat' => $e::class,
            ]);
        }

        return null;
    }

    /**
     * Render dompdf di CPU kecil bisa melewati `max_execution_time` request web
     * (30 detik bila php.ini tidak menyetelnya). Timeout PHP itu fatal — bukan
     * pengecualian yang bisa ditangkap "gagal terbuka" di atas — jadi approve
     * mati sebelum sempat memutuskan apa pun. Batasnya cuma dinaikkan kalau
     * memang ada batas (web); CLI/antrean/test bernilai 0 = tanpa batas dan
     * tidak boleh dikenai batas baru oleh gerbang ini.
     */
    private function longgarkanBatasWaktu(): void
    {
        $batas = (int) ini_get('max_execution_time');

        if ($batas > 0 && $batas < 120) {
            @set_time_limit(120);
        }
    }

    /**
     * Sertifikat yang DIJAMIN tidak ada di database: isinya persis yang akan
     * dirakit `GenerateCertificate` sesudah persetujuan (nomor yang sama,
     * penyetuju sebagai reviewer, tanggal terbit hari ini), kecuali token QR
     * yang acak — dan token QR cuma menentukan isi gambar, bukan ukurannya
     * (panjangnya sama: 10 huruf kecil/angka).
     */
    public function sertifikatSementara(CalibrationSession $sesi, User $penyetuju, ?string $nomorSertifikat, ?Certificate $dasarTarikan = null): Certificate
    {
        // Dibaca ulang dari database, seperti job: yang dipegang pemanggil bisa
        // membawa relasi setengah jalan atau atribut yang belum disegarkan.
        $lengkap = CalibrationSession::with(self::RELASI)->findOrFail($sesi->getKey());
        // Waktu job jalan, `reviewer` = yang menyetujui (`reviewed_by`); footer
        // sertifikat memakainya sebagai cadangan penandatangan.
        $lengkap->setRelation('reviewer', $penyetuju);

        $ke = $dasarTarikan === null ? 0 : ((int) $dasarTarikan->revisi_ke + 1);
        $nomor = $dasarTarikan !== null
            ? Certificate::nomorDasar((string) $dasarTarikan->nomor).'-R'.$ke
            : ($nomorSertifikat !== null && trim($nomorSertifikat) !== '' ? trim($nomorSertifikat) : 'PRA-PERIKSA');

        $token = Str::lower(Str::random(10));

        $sertifikat = new Certificate;
        $sertifikat->forceFill([
            'organization_id' => $lengkap->organization_id,
            'calibration_session_id' => $lengkap->getKey(),
            'nomor' => $nomor,
            'qr_token' => $token,
            'qr_payload' => rtrim((string) config('app.url'), '/')."/verify/{$token}",
            'diterbitkan_pada' => now(),
            'status' => Certificate::STATUS_MENUNGGU_GENERATE,
            ...($dasarTarikan === null ? [] : ['revisi_ke' => $ke]),
        ]);
        $sertifikat->setRelation('organization', $lengkap->organization);
        $sertifikat->setRelation('session', $lengkap);

        $snapshot = $this->snapshot->bangun($lengkap, $sertifikat);

        if ($dasarTarikan !== null) {
            // Sama dengan `GenerateCertificate::handle()`: lembar terbit ulang
            // menyebut yang digantikannya — satu baris lagi di kepala.
            $snapshot['header']['catatan_revisi'] = "Revisi ke-{$ke}, menggantikan {$dasarTarikan->nomor}";
            $snapshot['meta']['revisi'] = [
                'ke' => $ke,
                'menggantikan' => $dasarTarikan->nomor,
                'kunci_berubah' => ['hasil_diterbitkan_ulang'],
            ];
        }

        $sertifikat->setAttribute('snapshot', $snapshot);

        return $sertifikat;
    }

    /**
     * Pesan yang dibaca admin — harus berdiri sendiri (tanpa membuka log) dan
     * menyebut dua hal yang paling dicari: nomornya BELUM terpakai, dan apa
     * yang dipangkas.
     */
    public static function pesan(SertifikatLebihSatuHalamanException $e): string
    {
        return sprintf(
            'Sertifikat sesi ini tidak muat satu halaman (jadi %d halaman walau sudah dipadatkan sepenuhnya), '
            .'sehingga sesi TIDAK disetujui dan nomor sertifikat belum terpakai. '
            .'Kurangi isi yang memanjang tanpa menambah baris — nama atau alamat pelanggan, nama lokasi, '
            .'merk/model/nomor seri, jumlah titik ukur — lalu setujui lagi.',
            $e->halamanPadat,
        );
    }
}
