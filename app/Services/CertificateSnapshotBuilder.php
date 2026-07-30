<?php

namespace App\Services;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Support\Angka;
use Illuminate\Support\Collection;

/**
 * Nyusun ISI sertifikat sesuai struktur baku (spesifikasi poin 9), lalu
 * dibekukan ke `certificates.snapshot`.
 *
 * Kenapa dibekukan, bukan di-join ulang tiap dicetak: sertifikat itu dokumen
 * resmi yang salinannya udah dipegang pelanggan. Kalau alamat PT-nya berubah
 * tahun depan, atau alatnya dijual & datanya diedit, cetakan ulang HARUS tetap
 * sama persis kayak yang dulu diterbitin. Snapshot bikin PDF, Excel, halaman
 * verifikasi QR, dan API mustahil beda isi — semuanya baca dari sini.
 *
 * Strukturnya SENGAJA dikunci di empat bagian sesuai spesifikasi: header
 * informasi, tabel hasil, tabel standar, footer. Nambah field di luar itu
 * dilarang di dokumennya, jadi jangan ditambah di sini juga.
 */
class CertificateSnapshotBuilder
{
    /**
     * Dinaikin kalau bentuk snapshot berubah — snapshot lama tetap kebaca
     * apa adanya, yang berubah cuma cara view baca versi baru.
     */
    public const VERSI = 1;

    /** Catatan baku di bawah tabel hasil. Bunyinya nggak boleh diubah. */
    public const CATATAN_HASIL = [
        'The Uncertainty is taken at a Confidence Level 95% and Coverage Factor (k) = 2',
        'Calibration results are not to be announced and only apply to related tools',
    ];

    public const KODE_DOKUMEN_DEFAULT = 'SIDIK-FM-CAL-2403_Rev. 0';

    /**
     * @return array<string, mixed>
     */
    public function bangun(CalibrationSession $sesi, Certificate $sertifikat): array
    {
        $alat = $sesi->equipment;
        $pengaturan = $sesi->organization?->settings ?? [];
        $desimal = $this->desimal($alat, $pengaturan);

        return [
            'versi' => self::VERSI,
            'desimal' => $desimal,
            'satuan' => $sesi->rawMeasurements->first()?->satuan ?? $alat?->satuan,
            'header' => $this->header($sesi, $sertifikat),
            'hasil' => $this->hasil($sesi),
            'catatan' => self::CATATAN_HASIL,
            'standar_digunakan' => $this->standarDigunakan($sesi),
            'footer' => $this->footer($sesi, $sertifikat, $pengaturan),
            // Bukan bagian dari dokumen cetak — dipakai halaman verifikasi &
            // API buat nampilin status alat. Ditaruh di luar empat bagian baku
            // supaya jelas ini metadata, bukan field sertifikat.
            'meta' => [
                'keputusan' => $sesi->keputusan,
                'qr_token' => $sertifikat->qr_token,
                'qr_payload' => $sertifikat->qr_payload,
                'organization' => [
                    'nama' => $sesi->organization?->nama,
                    'alamat' => $sesi->organization?->alamat,
                    'no_akreditasi' => $sesi->organization?->no_akreditasi,
                    'standar_akreditasi' => $sesi->organization?->standar_akreditasi,
                ],
            ],
        ];
    }

    /**
     * Header informasi — 16 field, persis urutan & nama di spesifikasi.
     *
     * @return array<string, mixed>
     */
    private function header(CalibrationSession $sesi, Certificate $sertifikat): array
    {
        $alat = $sesi->equipment;
        $pelanggan = $alat?->customer;

        return [
            'certificate_number' => $sertifikat->nomor,
            // Satu sesi = satu halaman sertifikat. Kalau nanti tabel hasilnya
            // panjang & kepecah, angka ini yang diubah, bukan strukturnya.
            'page' => '1 of 1',
            // Lima field di bawah: YANG DICATAT TEKNISI menang, master cuma
            // cadangan. Teknisi megang alat fisiknya dan nyalin dari badan
            // alat; master diisi admin waktu pendaftaran dan bisa udah basi
            // (unit ketuker, PT pindah alamat). Kalau master yang menang,
            // sertifikat resmi bisa nyebut seri alat yang bukan alat yang
            // beneran dikalibrasi — dan itu temuan asesor.
            //
            // `??` PER FIELD, bukan per blok: teknisi boleh ngisi seri doang
            // dan ngosongin merk, dan yang kosong itu tetap jatuh ke master.
            'owner' => $sesi->pemilik_nama ?: $pelanggan?->nama,
            'order_number' => $sesi->nomor_order,
            'address' => $sesi->pemilik_alamat ?: $pelanggan?->alamat,
            'received_date' => $sesi->tanggal_terima?->toDateString(),
            'equipment_name' => $alat?->nama_alat,
            'manufacturer' => $sesi->alat_merk ?: $alat?->merk,
            'calibration_location' => $this->lokasiKalibrasi($sesi),
            'model_type' => $sesi->alat_model ?: $alat?->model,
            'calibration_date' => $sesi->tanggal_kalibrasi?->toDateString(),
            'serial_number' => $sesi->alat_serial_number ?: $alat?->serial_number,
            'calibration_method' => $this->metodeKalibrasi($sesi),
            'capacity_graduation' => $this->kapasitasGraduasi($sesi),
            'env_condition' => $this->kondisiLingkungan($sesi),
            'technician_id' => $sesi->teknisi?->kodeTeknisi(),
        ];
    }

    /**
     * Tabel hasil kalibrasi: Standard Value | Unit Under Test | Correction |
     * U95%. Empat kolom, nggak lebih.
     *
     * - Standard Value = nilai acuan di titik itu (`titik_ukur`)
     * - Unit Under Test = rata-rata pembacaan alat yang dikalibrasi
     * - Correction = angka yang harus DITAMBAHKAN ke pembacaan alat biar ketemu
     *   nilai benar, jadi = Standard Value − Unit Under Test
     * - U95% = ketidakpastian diperluas (k=2)
     *
     * @return list<array<string, float|null>>
     */
    private function hasil(CalibrationSession $sesi): array
    {
        return $sesi->uncertaintyCalculations
            ->sortBy('titik_ke')
            ->map(fn ($titik): array => [
                'titik_ke' => (int) $titik->titik_ke,
                'standard_value' => (float) $titik->titik_ukur,
                'unit_under_test' => (float) $titik->rata_rata,
                'correction' => (float) $titik->koreksi,
                'u95' => (float) $titik->ketidakpastian_diperluas,
            ])
            ->values()
            ->all();
    }

    /**
     * Tabel standar: Name | Merk/Type | Serial Number | Traceable to SI through.
     *
     * Isinya gabungan standar per titik (mis. buffer pH 4/7/10 — beda tiap
     * titik), standar acuan sesi, dan thermohygro. Di-dedupe by id: standar
     * yang sama dipakai di tiga titik tetap ditulis sekali.
     *
     * @return list<array<string, string|null>>
     */
    private function standarDigunakan(CalibrationSession $sesi): array
    {
        /** @var Collection<int, Standard> $standar */
        $standar = $sesi->uncertaintyCalculations
            ->pluck('standard')
            ->filter()
            ->when($sesi->standard, fn (Collection $c) => $c->push($sesi->standard))
            // Thermohygro SENGAJA nggak masuk tabel ini.
            //
            // Dia alat pemantau kondisi ruangan, bukan acuan yang nilainya
            // dipakai ngoreksi pembacaan — dan kontribusinya udah kelaporan di
            // tempatnya sendiri: kolom "Env. Condition" di header. Waktu ikut
            // ditulis di sini, barisnya keluar setengah jadi (`TH-2` · `—` ·
            // `TH-2`) karena master thermohygro nggak nyimpen merk/model/serial
            // kayak standar acuan, dan baris kosong di tabel ketertelusuran itu
            // lebih buruk daripada nggak ada baris.
            //
            // Kalau nanti thermohygro-nya mau tampil (lembar manual lab nulisnya
            // sebagai "Termometer & Sensor Std." dengan merk, serial, dan dua
            // nomor ketertelusuran), yang dibutuhin itu data masternya dulu —
            // bukan baris ini dibalikin.
            // Kolom "Usage Check" di lembar kerja: standar yang dicentang
            // teknisi tapi nggak nempel ke titik hitung mana pun (mis. RTD
            // Sensor buat baca suhu larutan) tetap harus tercatat — itu bagian
            // dari ketertelusuran, bukan pelengkap.
            ->concat($sesi->standarDicek->filter(fn (Standard $s): bool => (bool) $s->pivot->dipakai))
            ->unique('id')
            ->values();

        return $standar
            ->map(fn (Standard $s): array => [
                'name' => $s->nama,
                'merk_type' => trim(($s->merk ?? '').'/'.($s->model ?? ''), '/ ') ?: null,
                'serial_number' => $s->serial_number,
                'traceable_to' => $s->tertelusur_ke,
            ])
            ->all();
    }

    /**
     * Footer: tanggal terbit, penandatangan, jabatan, kode dokumen.
     *
     * Penandatangan diambil dari pengaturan organisasi dulu — di lab, yang tanda
     * tangan sertifikat itu Technical Manager, belum tentu admin yang kebetulan
     * mencet tombol approve. Kalau pengaturannya kosong, baru jatuh ke reviewer.
     *
     * @param  array<string, mixed>  $pengaturan
     * @return array<string, string|null>
     */
    private function footer(CalibrationSession $sesi, Certificate $sertifikat, array $pengaturan): array
    {
        return [
            'issuance_date' => $sertifikat->diterbitkan_pada?->toDateString(),
            'penandatangan' => $pengaturan['penandatangan_nama'] ?? $sesi->reviewer?->name,
            'jabatan' => $pengaturan['penandatangan_jabatan'] ?? $sesi->reviewer?->department ?? 'Technical Manager',
            'kode_dokumen' => $pengaturan['kode_dokumen_form'] ?? self::KODE_DOKUMEN_DEFAULT,
        ];
    }

    /** Nama ruangan kalau ada; kalau onsite, alamat pelanggan yang lebih berguna. */
    private function lokasiKalibrasi(CalibrationSession $sesi): ?string
    {
        if ($sesi->room) {
            return $sesi->room->nama;
        }

        if ($sesi->lokasi === 'onsite') {
            return $sesi->equipment?->customer?->alamat
                ? 'Onsite — '.$sesi->equipment->customer->alamat
                : 'Onsite';
        }

        return 'Laboratorium';
    }

    /**
     * Nomor IK. Prioritasnya baris `calibration_methods` yang dipilih admin;
     * kalau belum dipilih, jatuh ke nomor IK yang nempel di kemampuan kalibrasi
     * (CMC) yang kepake waktu hitung — itu sumber yang sama, cuma nggak eksplisit.
     */
    private function metodeKalibrasi(CalibrationSession $sesi): ?string
    {
        if ($sesi->calibrationMethod) {
            return $sesi->calibrationMethod->kodeLengkap();
        }

        return $sesi->uncertaintyCalculations
            ->sortBy('titik_ke')
            ->first(fn ($titik): bool => filled($titik->metode))?->metode;
    }

    /**
     * Jumlah desimal tabel CALIBRATION REPORT.
     *
     * Bawaannya diturunin dari resolusi alat: alat yang bacanya sampai 0,001
     * dicetak 3 desimal, yang 0,01 dicetak 2. Nulis lebih banyak daripada yang
     * bisa dibaca alatnya itu ngaku-ngaku presisi yang nggak ada.
     *
     * Pengaturan organisasi bisa nimpa, karena resolusi di master alat nggak
     * selalu ngikut spek fisiknya — dan kalau kekecilan, yang paling kena itu
     * U95%: 0,023 kecetak jadi `0,02` dan kehilangan angka penting, padahal
     * ketidakpastian lazimnya dilaporin 2 angka penting.
     *
     * Ikut dibekukan ke snapshot (bukan dibaca ulang waktu render) supaya
     * sertifikat yang udah terbit nggak berubah bentuk gara-gara pengaturan
     * diubah sesudahnya.
     *
     * @param  array<string, mixed>  $pengaturan
     */
    private function desimal(?Equipment $alat, array $pengaturan): int
    {
        $paksa = $pengaturan[Organization::KEY_DESIMAL_SERTIFIKAT] ?? null;

        // `is_numeric` bukan `!== null`: kolom teks di panel admin ngirim string
        // kosong waktu dibersihin, dan `(int) ''` itu 0 — nol desimal bikin
        // seluruh tabel sertifikat jadi bilangan bulat tanpa ada yang minta.
        if (is_numeric($paksa)) {
            return max(0, min(6, (int) $paksa));
        }

        return Angka::desimalDariResolusi(
            $alat?->resolusi !== null ? (float) $alat->resolusi : null,
        );
    }

    /** `0–14 pH / 0,01 pH` — rentang alat / resolusinya. */
    private function kapasitasGraduasi(CalibrationSession $sesi): ?string
    {
        $alat = $sesi->equipment;

        if ($alat === null) {
            return null;
        }

        $satuan = $alat->satuan ? ' '.$alat->satuan : '';
        $bagian = [];

        if ($alat->range_min !== null || $alat->range_max !== null) {
            $bagian[] = Angka::idRingkas((float) $alat->range_min)
                .'–'.Angka::idRingkas((float) $alat->range_max).$satuan;
        }

        if ($alat->resolusi !== null) {
            $bagian[] = Angka::idRingkas((float) $alat->resolusi, 6).$satuan;
        }

        return $bagian === [] ? null : implode(' / ', $bagian);
    }

    /**
     * `T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,7%`.
     *
     * Bagian `±`-nya cuma ditulis kalau admin ngisi ketidakpastiannya. Nulis
     * `± 0` waktu angkanya nggak diketahui itu klaim yang nggak benar.
     */
    private function kondisiLingkungan(CalibrationSession $sesi): ?string
    {
        $bagian = [];

        if ($sesi->suhu_ruang !== null) {
            $teks = 'T: '.Angka::id($sesi->suhu_ruang, 1).'°C';

            if ($sesi->suhu_ketidakpastian !== null) {
                $teks .= ' ± '.Angka::id($sesi->suhu_ketidakpastian, 1).'°C';
            }

            $bagian[] = $teks;
        }

        if ($sesi->kelembaban !== null) {
            $teks = '%RH: '.Angka::id($sesi->kelembaban, 2).'%';

            if ($sesi->kelembaban_ketidakpastian !== null) {
                $teks .= ' ± '.Angka::id($sesi->kelembaban_ketidakpastian, 1).'%';
            }

            $bagian[] = $teks;
        }

        return $bagian === [] ? null : implode(' — ', $bagian);
    }
}
