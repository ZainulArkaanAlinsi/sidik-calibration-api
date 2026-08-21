# Perintah Frontend — TITS, Temperature Indikator Tanpa Sensor (alat ke-11)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke
sesi kerja frontend — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api` |
| Formulir lembar kerja | **belum ada nomornya** — `kode_dokumen: null` |
| Formulir sertifikat | `SIDIK-FM-CAL-2403_Rev. 0` (LK-285-IDN) |
| Metode | `SIDIK-IK-CAL-0502_Rev.3` |
| Master | `Master Olah Data_Suhu_TITS fungsi Measure utk UUT.xlsm` (sesi `01-CAL-625`) & `… fungsi Source utk UUT.xlsm` (sesi `0159-CAL-626`) |
| Kode profil | `tits` |
| Status backend | Profil + lembar kerja + olah data **selesai & terverifikasi persis ke kedua master di MySQL** (uc, v_eff, k, U, koreksi semua cocok) |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Kabar baiknya: **TITS memakai jalur yang SAMA dengan pH / Turbidimeter /
Chlorine / DO Meter / Gas Detector.** Tidak ada layar atau endpoint khusus
seperti Autoclave. Kalau layar generik lembar kerja sudah jalan untuk alat-alat
itu, TITS hampir jalan sendiri.

**Tapi ada EMPAT hal yang belum pernah muncul di sepuluh alat sebelumnya, dan
keempatnya akan merusak tampilan kalau layar generiknya berasumsi seperti
dulu.** Itu inti dokumen ini.

## Ringkas: apa yang beda

| | Sepuluh alat sebelumnya | TITS |
|---|---|---|
| Titik ukur | daftar tetap dari backend | **teknisi boleh menambah/menghapus/mengubah** (`titik_bisa_diubah: true`) |
| Judul kolom tabel | satu string | **peta per mode** — bertukar sisi |
| Pengulangan | angka polos (`[1,2,3]`) | **objek ber-`arah`** — UP ×3 lalu DOWN ×3 |
| Field wajib khusus | — | **`mode_kalibrasi` & `tipe_sensor`** — tanpa keduanya sesi TIDAK dihitung |
| U95 | per titik (sebagian alat) | **satu untuk seluruh sesi**, dicetak di bawah tabel |
| Vonis PASS/FAIL | ada (kecuali Autoclave, DO Meter, Gas Detector) | **tidak ada** |

---

## 1. Dua field baru yang menentukan ANGKA: `mode_kalibrasi` & `tipe_sensor`

Ini yang paling penting di seluruh dokumen. Keduanya ada di
`bagian[kode=data_kalibrasi].field`, dikirim balik di **body `POST`
preview/simpan sebagai field tingkat atas** (bukan di dalam `spesifikasi_alat`),
dan dipulangkan lagi di `GET` sesi.

```json
{
  "kode": "mode_kalibrasi",
  "label": "1. Mode",
  "tipe": "pilihan",
  "wajib": false,
  "pilihan": [
    { "nilai": "measure", "label": "Measure (UUT membaca)" },
    { "nilai": "source",  "label": "Source (UUT men-source)" }
  ]
}
```

```json
{
  "kode": "tipe_sensor",
  "label": "2. Temperature Type",
  "tipe": "pilihan",
  "pilihan": [
    { "nilai": "RTD", "label": "RTD" },
    { "nilai": "Type K", "label": "Type K" },
    { "nilai": "Type N", "label": "Type N" },
    { "nilai": "Type B", "label": "Type B" },
    { "nilai": "Type T", "label": "Type T" },
    { "nilai": "Type R", "label": "Type R" },
    { "nilai": "Type S", "label": "Type S" },
    { "nilai": "Type J", "label": "Type J" }
  ]
}
```

`wajib: false` mengikuti pola semua alat — lembar kerja setengah jadi tetap
boleh dikirim dari lapangan. **Tapi tanpa dua field ini, seluruh titik pulang di
`belum_dihitung`**, bukan dengan angka yang salah:

```json
{
  "belum_dihitung": [
    { "titik_ke": 1, "alasan": "Mode kalibrasi (Measure / Source) belum dipilih di sesi ini. Arah perhitungan koreksi berbalik antara keduanya, jadi hitungannya nggak boleh nebak." }
  ]
}
```

Sarannya: tandai dua field ini di layar (misalnya blok berlatar berbeda di atas
tabel) dan tampilkan peringatan sebelum submit kalau salah satunya kosong.
Backend juga memulangkannya lewat `peringatan` sesi dengan kode
`tits_mode_kosong` & `tits_tipe_sensor_kosong`.

Catatan: **Type B belum punya klaim CMC di lampiran akreditasi.** Sesinya boleh
disimpan, tapi U95-nya tidak terbit — alasannya ikut di `belum_dihitung`.

## 2. Judul kolom tabel BERTUKAR mengikuti mode

`bagian[kode=hasil].tabel[i].judul_nilai` dan `judul_pengulangan` **bukan
string** di alat ini, melainkan peta:

```json
{
  "tahap": "sebelum_adjustment",
  "judul": "Before Adjustment Reading",
  "satuan": "°C",
  "judul_nilai": {
    "measure": "Standard Indication",
    "source": "UUT Indication"
  },
  "judul_pengulangan": {
    "measure": "Reading Unit Under Test",
    "source": "Reading Standard"
  },
  "titik_bisa_diubah": true
}
```

Pilih judul menurut `mode_kalibrasi` yang sedang dipilih, dan **perbarui waktu
teknisi mengganti mode** — jangan dibaca sekali waktu layar dibuka.

Alasannya bukan kosmetik: di mode `measure` kolom kiri berisi setpoint
kalibrator dan kolom pengulangan berisi bacaan alat pelanggan; di mode `source`
kebalikannya. Judul yang salah membuat teknisi mengisi kolom yang keliru, dan
angkanya tetap masuk tanpa error apa pun.

Kalau layar generik sekarang mengharapkan string, tangani dua-duanya:

```dart
String judulNilai(dynamic v, String mode) =>
    v is Map ? (v[mode] ?? v.values.first) as String : v as String;
```

## 3. Enam pembacaan per titik, dengan arah

`pengulangan` di alat ini **list objek**, bukan list angka:

```json
[
  { "ke": 1, "arah": "UP",   "label": "UP X1" },
  { "ke": 2, "arah": "UP",   "label": "UP X2" },
  { "ke": 3, "arah": "UP",   "label": "UP X3" },
  { "ke": 4, "arah": "DOWN", "label": "DOWN X1" },
  { "ke": 5, "arah": "DOWN", "label": "DOWN X2" },
  { "ke": 6, "arah": "DOWN", "label": "DOWN X3" }
]
```

Yang dikirim balik tetap `measurements[i].pembacaan` sebagai **array enam
angka berurutan** (indeks 0–2 = UP, 3–5 = DOWN) — persis seperti alat lain.
Arahnya cuma label kolom; backend merata-rata dan mencari STDEV atas keenamnya
sekaligus, sama seperti masternya. Histeresis tidak dilaporkan terpisah di
sertifikat TITS.

Catat: `jumlah_pengulangan` di akar bentuk lembar kerja bernilai **3** (per
arah), sementara panjang `pengulangan` **6**. Pakai panjang `pengulangan` untuk
menggambar kolom.

## 4. Titik ukur bisa ditambah, dihapus, dan diubah

`titik_bisa_diubah: true` — satu-satunya alat sejauh ini yang begitu.

```json
"larutan_standar": [-20, 10, 50, 100, 200, 400, 600, 800, 1000],
"satuan": "°C"
```

Sembilan baris itu **saran**, dari sesi master fungsi Measure. Rentang alat
pelanggan beda-beda: sesi master fungsi Source memakai 0, 100, 300, 500, 700,
900, 1100, 1200. Layar harus menyediakan tombol tambah/hapus baris dan kolom
titik yang bisa diketik.

Tiap baris membawa `standard_id` kalibrator sebagai **nilai awal** (Yokogawa CA
150 Handy Cal — yang dipakai kedua sesi master; sertifikat Constant 40T sudah
lewat masa berlaku). Kalau lab memakai Constant, teknisi menggantinya lewat
blok STANDARD, dan **`standard_id` tiap baris harus ikut berubah** — backend
membaca merk kalibrator dari standar yang dikirim per baris, dan merk itu yang
menentukan tabel koreksi mana yang dipakai.

```json
{
  "titik_ukur": -20,
  "label": "-20 °C",
  "satuan": "°C",
  "standard_id": 58,
  "standard_nama": "Temperature Calibrator Yokogawa CA 150 Handy Cal"
}
```

## 5. U95 satu untuk seluruh sesi

`u95PerTitik` **false**: semua baris hasil membawa `ketidakpastian_diperluas`
yang sama, dan sertifikat mencetaknya sebagai satu baris di bawah tabel
(`Uncertainty 95% ± … °C`), bukan sebagai kolom.

Jangan menggambar kolom `U95%` per baris untuk alat ini — sepuluh angka
identik berjejer ke bawah membuat pembaca mengira tiap titik punya
ketidakpastian sendiri.

Presisi cetak, dari format sel master:

| kolom | desimal |
|---|---|
| Standard Reading / Unit Under Test / Correction | 1 (`desimal: 1`) |
| U95% | 2 (`desimal_u95: 2`) |
| faktor cakupan `k` | 0 |

`k` nol desimal itu **mengikuti master** (`SERTIFIKAT!O30` berformat `0`), jadi
`k = 2,40` tercetak `2`. Sudah ditanyakan ke lab; kalau nanti diubah, yang
berubah cuma angka di respons, bukan bentuk datanya.

## 6. Contoh respons `GET /api/calibrations/{id}` (sesi source master)

Dipotong ke bagian yang relevan; diambil dari database ber-seed, jadi `id` &
`standard_id` di lingkungan lain berbeda.

```json
{
  "nomor_sesi": "2606.08.C",
  "mode_kalibrasi": "source",
  "tipe_sensor": "Type S",
  "desimal": 1,
  "titik": [
    {
      "titik_ke": 1,
      "titik_ukur": 4.98,
      "rata_rata": 0,
      "koreksi": 4.98,
      "error": -4.98,
      "standard_id": 58,
      "desimal": 1,
      "desimal_u95": 2,
      "satuan": "°C",
      "standar_deviasi": 0.10954451,
      "jumlah_pengulangan": 6,
      "ketidakpastian_diperluas": 1.2,
      "faktor_cakupan_k": 1.97475128,
      "derajat_kebebasan_efektif": 161.65565127,
      "toleransi": null,
      "keputusan": null,
      "type_b_components": [
        { "sumber": "ketidakpastian_standar", "keterangan": "Sertifikat kalibrator Yokogawa Type S (titik index 1200 °C, U=0.56 °C, k=2)", "distribusi": "normal", "nilai": 0.28 },
        { "sumber": "drift_standar", "keterangan": "Drift kalibrator Yokogawa Type S (0.056 °C, ÷√3)", "distribusi": "persegi", "nilai": 0.03233162 },
        { "sumber": "resolusi_alat", "keterangan": "Daya baca alat 0.1 °C (÷2, ÷√3)", "distribusi": "persegi", "nilai": 0.02886751 },
        { "sumber": "drift_referensi_mati", "keterangan": "Drift tambahan 0.38 °C (÷√3, ci 2) — sel mati master ke drift Constant Type N", "distribusi": "persegi", "nilai": 0.4387862 },
        { "sumber": "ac_pick_up", "keterangan": "Pengaruh AC Pick Up 0.2 °C (÷√(√3) mengikuti master)", "distribusi": "persegi", "nilai": 0.15196714 },
        { "sumber": "pengulangan_pembacaan", "keterangan": "Pengulangan pembacaan (Type A) — STDEV terbesar 0.32863353 °C dari 8 titik, ÷√3", "distribusi": "t-student", "nilai": 0.18973666 },
        { "sumber": "konteks_sesi", "keterangan": "Mode source, sensor Type S, kalibrator Yokogawa. …", "distribusi": "-", "nilai": 1.13767958 }
      ]
    }
  ]
}
```

Perhatikan baris pertama: `titik_ukur` **4,98** padahal teknisi mengetik **0**.
Itu benar — `titik_ukur` di hasil adalah kolom **Standard Reading** sertifikat
(rata-rata bacaan kalibrator + koreksi sertifikat kalibrator), sementara
`rata_rata` adalah kolom **Unit Under Test** yang di mode `source` berisi
setpoint. Setpoint mentahnya tetap utuh di `raw_measurements.titik_ukur`, jadi
lembar kerja yang dibuka lagi tetap menampilkan `0`.

Di mode `measure` kebalikannya: `titik_ukur` = setpoint + koreksi kalibrator,
`rata_rata` = rata-rata bacaan alat pelanggan.

## 7. Yang TIDAK ada di alat ini

- **Kolom suhu per pembacaan.** Yang masuk budget suhu ruangan, bukan suhu
  media.
- **Vonis PASS/FAIL.** `keputusan` selalu `null`, `toleransi` selalu `null` —
  master tidak punya kolom batas keberterimaan dan sertifikatnya berhenti di
  baris `Uncertainty 95%`. Jangan menggambar chip PASS/FAIL.
- **Tekanan udara.** Itu cuma Gas Detector.
- **`kode_dokumen`.** `null`; jangan mencetak nomor formulir apa pun di kop
  lembar kerja sampai lab mengirimkan nomornya.

## 8. Peringatan sesi yang mungkin muncul

Kode-kode ini datang di `peringatan` (bentuknya `{kode, pesan}`, sama seperti
alat lain) — tampilkan apa adanya:

| kode | kapan |
|---|---|
| `tits_mode_kosong` | mode belum dipilih |
| `tits_tipe_sensor_kosong` | tipe sensor belum dipilih |
| `tits_titik_luar_rlk` | titik di luar rentang RLK tipe sensor itu (mis. 600 °C untuk Type T yang cuma sampai 400) |
| `tits_titik_jauh_dari_tabel` | titik lebih dari 50 °C dari titik tabel kalibrator terdekat — koreksinya diambil utuh tanpa interpolasi |

---

## Catatan untuk backend/lab (bukan pekerjaan frontend)

Ada satu selisih yang **sudah ada sebelum TITS** dan kelihatan lagi di sini:
kondisi lingkungan yang tercetak. Master TITS mencocokkan suhu ruang ke titik
kalibrasi thermohygro yang **paling dekat ke suhu terukur** (24,3 °C → titik
29,14, koreksi −0,82 → tercetak 23,38 °C). Sistem memakai satu titik tetap per
thermohygro (19,37, koreksi −0,59 → 23,61 °C).

Itu jalur bersama (`KondisiLingkungan` + `database/data/thermohygro-lab.json`)
yang dipakai sepuluh alat lain, jadi **tidak diubah** dalam pekerjaan TITS ini.
Sudah dicatat di `docs/pertanyaan-lab-tits.md` untuk diputuskan lab.
