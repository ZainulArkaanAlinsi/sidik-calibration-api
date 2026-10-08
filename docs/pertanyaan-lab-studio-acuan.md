# Pertanyaan Lab — Studio Data Acuan (CR-48)

Konteks: `docs/permintaan-user-7.md` §48 dan `docs/studio-data-acuan/06-Test-Plan.md` §3.

Studio Data Acuan memindahkan data acuan olah data (lapis 1) dari berkas di repo ke versi yang
disahkan di server. Setiap alat yang dipindah wajib lolos **uji pembanding**: hasil hitung
server diadu ke workbook master. Batas selisih yang diterima hanya boleh ditetapkan Lab
(K-48-07). Selama batas itu belum ada, sebuah alat tidak boleh disebut selesai.

Berkas ini **tidak mengusulkan angka toleransi**. Yang ditulis hanya keadaan kode hari ini,
sebagai bahan Lab memutuskan.

Status: semua pertanyaan **[PERLU JAWABAN]**. Yang tertahan: status "Micrometer selesai" di P5.
P1–P4 tetap bisa dikerjakan.

---

## Bahan: Micrometer hari ini

| Hal | Keadaan di kode | Rujukan |
|---|---|---|
| Satuan nilai standar tumpukan, rata-rata, koreksi | mm | `MicrometerCalculator::totalNominal()` |
| Satuan komponen ketidakpastian, uc, U95 | µm (diubah ke mm hanya saat dicetak) | `MicrometerCalculator::budget()`, `hitungSesi()` |
| Desimal angka cetak hasil (standar, UUT, koreksi) | 5 desimal mm | `MicrometerProfile::desimalSertifikat()` |
| Desimal angka cetak U95 | 5 desimal mm | `MicrometerProfile::desimalU95()` |
| Desimal faktor cakupan k | tidak disetel di profil (bawaan) | `CalibrationProfile::desimalFaktorCakupan()` |
| Resolusi alat (UUT) | **diisi teknisi per sesi**, bukan angka tetap: `spesifikasi_alat.micrometer.resolusi_mm` | `MicrometerProfile` blok identitas alat |
| Resolusi di sesi contoh master | 0,001 mm (sesi 25–50, 50–75, 75–100) dan 0,000254 mm (sesi 0–25, yang satuannya terisi `inch` — cacat data di `pertanyaan-lab-micrometer.md` §3) | `database/data/sesi-master-micrometer.json` |
| U95 yang terbit | MAX(U95 hitung, CMC pita rentangnya). Di luar keempat pita CMC (0–100 mm), U95 tidak terbit | `MicrometerCalculator::hitungSesi()` |
| Selisih yang dipakai test hari ini | 5·10⁻⁶ dalam satuan besarannya (mm atau µm). **Angka ini dipilih developer untuk mengadu kode ke master, bukan keputusan Lab, dan bukan usulan** | `tests/Unit/MicrometerMasterTest.php` |

Komponen budget Micrometer (sembilan, semuanya per sesi):

| `sumber` | Keterangan |
|---|---|
| `pengulangan` | Repeatability (pra-evaluasi), t-student |
| `resolusi_uut` | Resolusi alat |
| `ketidakpastian_standar` | Standar balok ukur (tumpukan) |
| `suhu_ruang` | Perubahan suhu terhadap suhu acuan 20 °C |
| `koefisien_muai` | Koefisien muai termal |
| `drift_standar` | Drift standar |
| `wringing` | Lapisan wringing |
| `geometri` | Kesalahan geometri |
| `selisih_suhu` | Selisih suhu mikrometer dengan balok ukur |

---

## Toleransi uji pembanding Micrometer

Untuk tiap pertanyaan, mohon sebutkan juga **bentuknya**: selisih mutlak (dalam satuan apa),
selisih relatif, atau "harus identik sampai digit ke-N".

**T-1 — Nilai standar tumpukan balok ukur (mm).** Berapa selisih maksimum antara total nilai
terkoreksi tumpukan hasil server dan nilai yang sama di workbook?

**T-2 — Ketidakpastian tumpukan balok ukur.** Berapa selisih maksimum untuk ketidakpastian
tumpukan (gabungan U keping-kepingnya)?

**T-3 — Rata-rata pembacaan dan koreksi per titik (mm).** Berapa selisih maksimum per titik?

**T-4 — Tiap komponen ketidakpastian.** Apakah satu toleransi berlaku untuk kesembilan komponen
di atas, atau per komponen? Apakah toleransi yang sama berlaku untuk koefisien sensitivitas
(`ci`) dan derajat kebebasan (`vi`)?

**T-5 — uc, νeff, dan k.** Berapa selisih maksimum untuk ketidakpastian gabungan, derajat
kebebasan efektif, dan faktor cakupan?

**T-6 — U95.** Berapa selisih maksimum untuk U95 hasil hitung (sebelum lantai CMC), dan apakah
batasnya sama untuk U95 yang terbit (sesudah MAX dengan CMC)?

**T-7 — Angka cetak.** Apakah angka cetak sertifikat (5 desimal mm) wajib identik dengan cetak
workbook? Kalau nilai antara jatuh tepat di batas pembulatan sehingga digit terakhir berbeda,
apakah itu dihitung gagal?

**T-8 — Keempat rentang.** Apakah toleransi T-1 sampai T-7 berlaku sama untuk 0–25, 25–50, 50–75,
dan 75–100 mm?

**T-9 — Data pembanding.** Sesi mana yang dipakai sebagai pembanding: sesi contoh yang sudah ada
di workbook master (sudah dipakai `sesi-master-micrometer.json`), atau Lab menyediakan sesi
lain? Data pelanggan dari sheet `DATABASE` tidak akan disalin ke repo.

---

## Konfirmasi keputusan K-48

Pemilik proyek memilih jawaban default untuk ketujuhnya pada 8 Okt 2026 (§48). Mohon
konfirmasi, terutama K-48-01 dan K-48-03.

| ID | Keputusan yang tercatat | Perlu dikonfirmasi oleh | Pertanyaan |
|---|---|---|---|
| **K-48-01** | Rumus (lapis 2) **tidak** bisa diubah dari layar. Revisi rumus di Excel tetap lewat PR kode + test rekonsiliasi master (`P9`) | Pemilik proyek, Manajer Teknis | Setuju rumus hanya berubah lewat jalur `P9`, bukan dari Studio? |
| K-48-02 | Alat pilot: **Micrometer**. pH jadi alat kedua | Pemilik proyek | Setuju? |
| **K-48-03** | Pengesah versi data acuan: **`super_admin`**, dan tidak pernah orang yang menyunting atau mengajukan versi itu | Pemilik proyek, Manajer Teknis | Setuju? Catatan: K4 (siapa yang mengesahkan *sertifikat*) belum dijawab Manajer Teknis. K-48-03 hanya untuk *data acuan* dan tidak menjawab K4 |
| K-48-04 | Versi baru berlaku menurut **`tanggal_kalibrasi` sesi** | Manajer Teknis | Setuju sesi dihitung dengan versi yang berlaku pada tanggal kalibrasinya, bukan tanggal input? |
| K-48-05 | Master Data **boleh** menyimpan draf pribadi tanpa mengajukan. Draf tidak berpengaruh ke perhitungan | Pemilik proyek | Setuju? |
| K-48-06 | Teknisi **boleh** melihat tabel acuan yang aktif, baca saja, tanpa riwayat draf | Pemilik proyek | Setuju? |
| K-48-07 | Toleransi uji pembanding **ditetapkan Lab per alat** | Lab | Dijawab lewat T-1 sampai T-9 untuk Micrometer. Alat berikutnya menyusul dengan berkas pertanyaannya sendiri |
