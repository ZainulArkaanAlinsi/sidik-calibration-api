<?php

namespace App\Services;

use App\Models\CalibrationMethod;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
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
        $desimal = $this->desimal($alat, $sesi->organization);

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
        $alat = $sesi->equipment;
        $organisasi = $sesi->organization;

        // Profil alat dipakai buat kolom "Remark" — keterangan parameter per
        // titik (Chlorine: "Free Chlorine" / "Total Chlorine"). Alat yang
        // profilnya nggak nyediain remark balikin null, dan kolomnya nggak
        // dicetak sama sekali.
        $profil = $alat
            ? app(CalibrationProfileRegistry::class)->untukAlat($alat)
            : null;

        $baris = $sesi->uncertaintyCalculations
            ->sortBy('titik_ke')
            ->map(function ($titik) use ($alat, $organisasi, $profil): array {
                // Desimal DIHITUNG PER TITIK, bukan sekali buat seluruh tabel.
                //
                // Alat yang resolusinya berubah per rentang (Turbidimeter:
                // 0,01 / 0,1 / 1) sebelumnya kepaksa ikut satu angka `resolusi`,
                // jadi titik 100 NTU kecetak `101,00` — dua digit yang alatnya
                // nggak bisa tampilkan. Di sertifikat terakreditasi itu ngaku
                // ketelitian yang nggak ada.
                //
                // Aturannya tetap cuma diputus di `Organization::desimalSertifikat`
                // (termasuk override manual di pengaturan organisasi); yang beda
                // di sini cuma resolusi mana yang disodorin.
                $resolusi = $alat?->resolusiPada((float) $titik->titik_ukur);

                // Profil alat menang kalau dia nyatain sendiri. Refractometer
                // nyetak 5 desimal walau resolusinya ngasih 4 — di 4 desimal
                // U95% `0,00053` runtuh jadi `0,0005`, kehilangan angka penting
                // di kolom yang justru jadi inti sertifikat. Angkanya dari
                // sertifikat master yang beneran kecetak, bukan diturunkan.
                $desimal = $profil?->desimalSertifikat()
                    ?? ($organisasi
                        ? $organisasi->desimalSertifikat($resolusi)
                        : Angka::desimalDariResolusi($resolusi));

                $remark = $profil?->remarkTitik((float) $titik->titik_ukur);

                return [
                    'titik_ke' => (int) $titik->titik_ke,
                    'standard_value' => (float) $titik->titik_ukur,
                    // Kolom "Remark" di sertifikat asli. Null buat alat yang
                    // titiknya nggak punya keterangan parameter.
                    'remark' => $remark,
                    'unit_under_test' => (float) $titik->rata_rata,
                    'correction' => (float) $titik->koreksi,
                    'u95' => (float) $titik->ketidakpastian_diperluas,
                    // Faktor cakupan yang dipakai buat U95 titik ini.
                    //
                    // Dibekukan karena sertifikat master nyetaknya di bawah
                    // TIAP tabel hasil: "The Uncertainty is taken at a
                    // Confidence Level 95 % and Coverage Factor ( k ) = 3".
                    // Angkanya beda per kelompok (Holmium 3,18; Didynium 2,36;
                    // %T 2,01), jadi nggak bisa diwakili satu nilai di level
                    // sertifikat.
                    //
                    // Sertifikat yang udah terbit nggak punya kunci ini —
                    // pembacanya ngosongin barisnya, bukan ngarang k.
                    // Desimal khusus baris `Uncertainty U95% = ±`, kalau alat
                    // ini nyetaknya beda dari kolom hasil di atasnya. Master
                    // spektro nulis `0,50 %T` sementara UUT & Correction di
                    // blok yang sama pakai tiga desimal (`9,665`).
                    //
                    // Ikut DIBEKUKAN, sama alasannya kayak `desimal` & `satuan`:
                    // sertifikat yang udah terbit nggak boleh berubah bentuk
                    // gara-gara profilnya diedit sesudahnya. `null` = ikut
                    // `desimal` titik, persis perilaku lama.
                    'desimal_u95' => $profil?->desimalU95(),
                    'faktor_cakupan_k' => $titik->faktor_cakupan_k === null
                        ? null
                        : (float) $titik->faktor_cakupan_k,
                    // Desimal angka `k` di kalimat di bawah tabel. Master
                    // spektro nyimpen 3,182446… tapi nyetak `3`.
                    //
                    // Ikut DIBEKUKAN sama alasannya kayak `desimal_u95`:
                    // sertifikat yang udah terbit nggak boleh berubah bunyi
                    // gara-gara profilnya diedit sesudahnya. `null` = perilaku
                    // lama (2 desimal, nol di belakang dibuang).
                    'desimal_k' => $profil?->desimalFaktorCakupan(),
                    // Nilai mentahnya TETAP presisi penuh — ini cuma ngatur
                    // berapa digit yang ditulis. Nggak ada pembulatan data.
                    'desimal' => $desimal,
                    // Satuan DI TITIK INI, buat alat yang nyampur satuan dalam
                    // satu lembar (Conductivity: 25 & 1412 µS/cm, 111 mS/cm).
                    //
                    // Ikut DIBEKUKAN ke snapshot, sama alasannya kayak
                    // `desimal`: sertifikat yang udah terbit nggak boleh
                    // berubah bentuk gara-gara master alat diedit sesudahnya.
                    //
                    // Ditambah SEBELUM sertifikat Conductivity pertama terbit —
                    // sesudah itu betulinnya mesti lewat `sertifikat:bangun-ulang`
                    // ke dokumen yang mungkin udah dipegang pelanggan.
                    //
                    // `null` buat alat bersatuan seragam; PDF & layar jatuh ke
                    // satuan alat kayak biasa, jadi empat alat lain nggak
                    // berubah sama sekali.
                    'satuan' => $profil?->satuanTitik((float) $titik->titik_ukur, $alat),
                    // Koreksi negatif yang membulat ke nol kecetak `-0,0` atau
                    // `0,0` — beda per alat, dibaca dari master masing-masing
                    // (lihat `CalibrationProfile::tandaNolDicetak()`).
                    //
                    // Ikut dibekukan sama alasannya kayak `desimal` & `satuan`.
                    // Sertifikat yang udah terbit nggak punya kunci ini, dan
                    // pembacanya jatuh ke `true` — persis perilaku lamanya, jadi
                    // dokumen yang udah dipegang pelanggan nggak berubah bentuk.
                    'tanda_nol' => $profil?->tandaNolDicetak() ?? true,
                    // Kolom Standard Value: nol di belakang dibuang (`100`)
                    // atau ditulis penuh (`100,0`). Beda per master — lihat
                    // `CalibrationProfile::nolBelakangStandarDibuang()`.
                    //
                    // Ikut dibekukan; sertifikat lama yang belum punya kunci
                    // ini dibaca `?? true` di PDF, persis perilaku lamanya.
                    'standar_ringkas' => $profil?->nolBelakangStandarDibuang() ?? true,
                ];
            })
            ->values()
            ->all();

        return $this->bubuhkanR2($baris, $profil);
    }

    /**
     * Tempelin R² kelompok ke tiap barisnya.
     *
     * Ditempel BERULANG di semua baris sekelompok, sama kayak `u95` &
     * `faktor_cakupan_k` — bukan karena tiap titik punya R² sendiri (nggak,
     * lihat `CalibrationProfile::koefisienDeterminasi()`), tapi supaya
     * pembacanya nggak usah ngerti struktur kelompok buat nemuinnya. Snapshot
     * ini dibaca PDF, halaman verifikasi QR, dan Excel; tiga-tiganya udah
     * ngambil angka kelompok dari baris pertama.
     *
     * Dikelompokkan pakai `remark`, persis kayak PDF-nya. Alat yang `remark`-nya
     * null lewat sebagai satu kelompok tanpa judul, dan profilnya balikin null,
     * jadi lima alat lain nggak kesenggol sama sekali.
     *
     * @param  list<array<string, mixed>>  $baris
     * @return list<array<string, mixed>>
     */
    private function bubuhkanR2(array $baris, ?CalibrationProfile $profil): array
    {
        if ($profil === null) {
            return $baris;
        }

        $r2 = [];

        foreach ($baris as $b) {
            $r2[(string) ($b['remark'] ?? '')][] = $b;
        }

        $r2 = array_map(
            fn (array $kelompok): ?float => $profil->koefisienDeterminasi($kelompok),
            $r2,
        );

        return array_map(
            // Kunci `r2` SELALU ada begitu alatnya lewat sini, walau isinya
            // null. Sertifikat lama yang snapshot-nya belum punya kunci ini
            // dibaca `?? null` di PDF — dokumen yang udah dipegang pelanggan
            // nggak berubah bentuk gara-gara kolomnya ditambahin sekarang.
            fn (array $b): array => [...$b, 'r2' => $r2[(string) ($b['remark'] ?? '')]],
            $baris,
        );
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

    /**
     * Nama ruangan kalau ada; kalau onsite, tempat yang DITULIS TEKNISI.
     *
     * `Insitu (PT. LDC)` — persis yang tercetak di sertifikat master. Nama
     * tempatnya nggak diturunkan dari pelanggan pemilik alat: satu kunjungan
     * bisa dikerjakan di pabrik lain milik grup yang sama, dan yang sah di
     * dokumen adalah tempat alatnya beneran diukur. Kalau teknisi nggak nulis
     * apa-apa, jatuh ke alamat pelanggan seperti sebelumnya.
     */
    private function lokasiKalibrasi(CalibrationSession $sesi): ?string
    {
        if ($sesi->room) {
            return $sesi->room->nama;
        }

        if ($sesi->lokasi === 'onsite') {
            if (filled($sesi->lokasi_nama)) {
                return 'Insitu ('.trim((string) $sesi->lokasi_nama).')';
            }

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

        // Admin nggak selalu milih metode sebelum approve. Kalau nggak dipilih,
        // yang dipakai IK TERBARU buat jenis pengukurannya — itu persis tabel
        // "Jenis Pengukuran → Metode Kalibrasi (Latest IK)" di lembar master,
        // dan `MetodeKalibrasiSeeder` nyimpen jenis pengukurannya di kolom
        // `nama` (mis. "pH Meter").
        //
        // Dicocokkan lewat NAMA ALAT, bukan id: master metode ngelistnya per
        // jenis pengukuran, dan nama alat di sesi ini yang mewakilinya.
        $namaAlat = mb_strtolower(trim((string) $sesi->equipment?->nama_alat));

        if ($namaAlat !== '') {
            // Dicocokkan lewat "nama alat MENGANDUNG jenis pengukuran", bukan
            // sama persis: master ngelist jenisnya ("pH Meter") sementara alat
            // di lapangan namanya lebih panjang ("pH Meter Bench"). Cocok
            // persis bikin cadangan ini nggak pernah kena.
            //
            // Yang dipilih kecocokan TERPANJANG, supaya "Thermometer Glass"
            // nggak kalah sama jenis lain yang kebetulan jadi bagian namanya.
            $metode = CalibrationMethod::query()
                ->where('organization_id', $sesi->organization_id)
                ->where('aktif', true)
                ->get()
                ->filter(fn (CalibrationMethod $m): bool => filled($m->nama)
                    && str_contains($namaAlat, mb_strtolower(trim($m->nama))))
                // Urutannya: revisi dulu, PANJANG NAMA belakangan. `sortBy`
                // Laravel stabil, jadi yang dipanggil terakhir jadi kunci
                // utama — panjang nama menang, revisi jadi pemecah seri.
                ->sortByDesc(fn (CalibrationMethod $m): int => (int) $m->revisi)
                ->sortByDesc(fn (CalibrationMethod $m): int => mb_strlen((string) $m->nama))
                ->first();

            if ($metode !== null) {
                return $metode->kodeLengkap();
            }
        }

        // Terakhir: kode yang kesimpen di baris perhitungan. Ini nggak bawa
        // revisi, jadi sengaja jadi cadangan paling akhir — sertifikat yang
        // nyebut IK tanpa revisi nggak bisa dicocokin ke dokumen mutu mana.
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
     * Timpaan profil alat menang di atas dua-duanya — aturan yang sama persis
     * udah dipakai `hasil()` buat desimal per baris. Sempat cuma dipasang di
     * sana: baris Refractometer kebekuin 5, angka level-sertifikat ini tetap 4.
     * Nggak keliatan di PDF (blade-nya baca per baris duluan), tapi itu yang
     * dipakai sebagai cadangan sama layar mobile & `CertificateResource`, jadi
     * satu sertifikat bisa punya dua jawaban buat pertanyaan yang sama.
     */
    private function desimal(?Equipment $alat, ?Organization $organisasi): int
    {
        $resolusi = $alat?->resolusi !== null ? (float) $alat->resolusi : null;

        $profil = $alat !== null
            ? app(CalibrationProfileRegistry::class)->untukAlat($alat)
            : null;

        // Aturannya sengaja NGGAK diulang di sini — satu-satunya tempat dia
        // diputusin itu `Organization::desimalSertifikat()`, biar angka yang
        // dibekukan ke sertifikat sama persis dengan yang dikirim ke mobile.
        return $profil?->desimalSertifikat()
            ?? ($organisasi
                ? $organisasi->desimalSertifikat($resolusi)
                : Angka::desimalDariResolusi($resolusi));
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
            // Rentang ukur ditulis TANPA pemisah ribuan: sertifikat asli nyetak
            // `0-1000 NTU`, bukan `0-1.000 NTU`. Di angka rentang alat, titik
            // ribuan malah gampang kebaca sebagai koma desimal oleh pembaca
            // asing — dan ini dokumen dwibahasa.
            $angkaRentang = static fn (?float $n): string => $n === null
                ? '—'
                : rtrim(rtrim(number_format($n, 6, ',', ''), '0'), ',');

            $bagian[] = $angkaRentang((float) $alat->range_min)
                .'–'.$angkaRentang((float) $alat->range_max).$satuan;
        }

        // Alat ber-resolusi BERTINGKAT ditulis semua resolusinya, bukan cuma
        // satu. Sertifikat asli PT Sidik buat HACH 2100Q (0189-CAL-624) nulis:
        //
        //   Capacity/Graduation : 0–1000 NTU / 0,01 NTU
        //                                      0,1 NTU
        //                                      1 NTU
        //
        // Waktu cuma `$alat->resolusi` yang dipakai, dua baris terakhir ilang
        // dan dokumennya jadi ngaku alatnya ber-resolusi 0,01 di SELURUH
        // rentang — padahal di atas 100 NTU dia cuma bisa bilangan bulat.
        // Dipisah koma (bukan baris baru) karena field ini satu baris teks di
        // snapshot; yang penting ketiganya kebawa, bukan tata letaknya.
        $rentang = $alat->resolusi_rentang;
        if (is_array($rentang) && $rentang !== []) {
            $resolusi = [];
            foreach ($rentang as $band) {
                if (is_array($band) && isset($band['resolusi'])) {
                    $resolusi[] = Angka::idRingkas((float) $band['resolusi'], 6).$satuan;
                }
            }
            if ($resolusi !== []) {
                // Dipisah BARIS BARU, bukan koma — sertifikat asli PT Sidik
                // (0189-CAL-624) nyetak resolusinya bertumpuk:
                //
                //   Capacity/Graduation : 0-1000 NTU / 0,01 NTU
                //                                      0,1 NTU
                //                                      1 NTU
                //
                // Dijejer koma, barisnya kepanjangan lalu membungkus asal di
                // tengah angka. Yang ngerender wajib ngehormatin `\n`
                // (`white-space: pre-line` di blade PDF & web).
                //
                // `array_unique` jaga-jaga kalau dua rentang resolusinya sama —
                // nulis "1 NTU / 1 NTU" cuma bikin dokumen kelihatan salah.
                $bagian[] = implode("\n", array_unique($resolusi));

                return implode(' / ', $bagian);
            }
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

        // Desimalnya DISALIN dari sertifikat master, bukan diturunkan:
        //
        //   Turbidimeter 0189-CAL-624  T 23,07 °C ± 1,7 °C · %RH 52 % ± 4,8 %
        //   Chlorine     2406.32.C.NK  T 23,21 °C ± 1,8 °C · %RH 53 % ± 4,9 %
        //
        // Jadi: NILAI suhu 2 desimal, NILAI kelembaban bulat, dan ketidakpastian
        // dua-duanya 1 desimal. Kombinasi itu nggak simetris dan nggak bisa
        // dinalar — makanya sempat salah berkali-kali dari dua arah sekaligus.
        //
        // ## Riwayatnya, biar nggak diputer lagi
        //
        // Baris ini udah tiga kali digeser, dan tiap kali alasannya masuk akal:
        //
        //  - suhu diturunkan ke 1 desimal ("teknisi cuma baca 0,1°C, desimal
        //    ketiga itu hasil hitungan koreksi thermohygro") — masuk akal, dan
        //    salah: master nulis `23,07`.
        //  - kelembaban dinaikin ke 2 desimal ("nilainya emang berdesimal,
        //    `53` di Chlorine itu kebetulan bulat") — masuk akal, dan salah:
        //    Turbidimeter yang nilainya 51,83 pun kecetak `52`.
        //
        // Dua-duanya diputus dari nalar metrologi, bukan dari kertasnya, dan
        // dua-duanya kebalik dari yang lab tulis. Kalau baris ini mau digeser
        // lagi, geser SETELAH ngadu ke halaman masternya — bukan sebelum.
        $profil = $sesi->equipment !== null
            ? app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment)
            : null;

        // `null` = format `General` di Excel: tulis apa adanya, nol di belakang
        // dibuang. Dibatesin 2 desimal dulu supaya derau float
        // (`23.069999999999997`) nggak ikut kecetak — Excel juga mangkas di
        // situ waktu nampilin `General`.
        $suhuDesimal = $profil?->desimalSuhuEnv();
        $rhDesimal = $profil?->desimalKelembabanEnv();

        $tulis = static fn (float $nilai, ?int $desimal): string => $desimal === null
            ? Angka::nilaiStandar($nilai, 2)
            : Angka::id($nilai, $desimal);

        if ($sesi->suhu_ruang !== null) {
            $teks = 'T: '.$tulis((float) $sesi->suhu_ruang, $suhuDesimal).'°C';

            if ($sesi->suhu_ketidakpastian !== null) {
                $teks .= ' ± '.Angka::id($sesi->suhu_ketidakpastian, 1).'°C';
            }

            $bagian[] = $teks;
        }

        if ($sesi->kelembaban !== null) {
            // Nilainya BULAT, ketidakpastiannya 1 desimal — lihat catatan di
            // blok suhu di atas. Master Turbidimeter nyimpen 51,83 dan nyetak
            // `52`; Chlorine nyimpen 53,0 dan nyetak `53`. Nilai penuhnya tetap
            // utuh di DB dan tetap dipakai ngitung; yang dipangkas cuma yang
            // kecetak.
            $teks = '%RH: '.$tulis((float) $sesi->kelembaban, $rhDesimal).'%';

            if ($sesi->kelembaban_ketidakpastian !== null) {
                $teks .= ' ± '.Angka::id($sesi->kelembaban_ketidakpastian, 1).'%';
            }

            $bagian[] = $teks;
        }

        return $bagian === [] ? null : implode(' — ', $bagian);
    }
}
