# Perintah Frontend — Multi Gas Detector (alat ke-10)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke
sesi kerja frontend — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api` |
| Formulir lembar kerja | **belum ada nomornya** — lihat catatan di bawah |
| Formulir sertifikat | `SIDIK-FM-CAL-2403_Rev. 0` (LK-285-IDN) |
| Metode | `SIDIK-IK-CAL-0536_Rev.0` |
| Master | `Gas Detector Uli Skin (std Rigaz).xlsm`, sesi asli `001-CAL-226` |
| Status backend | Profil + lembar kerja + olah data **selesai & terverifikasi persis ke master di MySQL** (uc, v_eff, k, U, koreksi, kondisi lingkungan semua cocok) |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Kabar baiknya: **Gas Detector memakai jalur yang SAMA dengan pH / Turbidimeter /
Chlorine / Refractometer / DO Meter.** Tidak ada layar atau endpoint khusus
seperti Autoclave. Kalau layar generik lembar kerja sudah jalan untuk alat-alat
itu, Gas Detector nyaris jalan sendiri.

**Tapi ada TIGA hal yang belum pernah muncul di sembilan alat sebelumnya, dan
ketiganya akan merusak tampilan kalau layar generiknya berasumsi seperti dulu.**
Itu inti dokumen ini.

## Ringkas: apa yang beda

| | Sembilan alat sebelumnya | Gas Detector |
|---|---|---|
| Satuan | satu untuk selembar (`data.satuan`) | **`data.satuan` = `null`**, tiap baris bawa `satuan` sendiri |
| Kondisi lingkungan | suhu + kelembaban | **+ tekanan udara (hPa)** |
| Kolom per sel | pembacaan + suhu larutan | **cuma pembacaan** |
| Pengulangan bawaan | 5 | **3** |
| Vonis PASS/FAIL | ada (kecuali Autoclave & DO Meter) | **tidak ada** |
| `kode_dokumen` | selalu terisi | **`null`** |

---

## 1. Satuan CAMPURAN — jangan pakai `data.satuan`

`GET /api/calibrations/lembar-kerja?profil=gas_detector` mengembalikan
`"satuan": null`. Itu **disengaja**, bukan data yang belum diisi.

Satu sesi mengukur EMPAT gas dengan tiga satuan berbeda. Satuan yang benar ada
di **tiap baris tabel**:

```json
{
  "kode_dokumen": null,
  "judul": "Calibration Worksheet - Multi Gas Detector",
  "jumlah_pengulangan": 3,
  "larutan_standar": [101, 25, 50, 17.9],
  "satuan": null,
  "satuan_suhu": "°C",
  "satuan_tekanan": "hPa"
}
```

Baris tabel hasil (`bagian[kode=hasil].tabel[0].baris`) — `standard_id` di bawah
angka nyata dari database ber-seed, jadi di lingkungan lain nomornya beda;
yang penting keempatnya BERBEDA:

```json
[
  { "titik_ukur": 101,  "label": "CO",  "satuan": "ppm",  "resolusi": 1,   "desimal": 0,
    "remark": "Carbon Monoxide (CO)",   "standard_id": 36, "standard_nama": "Standar Gas Mixture (CO)" },
  { "titik_ukur": 25,   "label": "H2S", "satuan": "ppm",  "resolusi": 1,   "desimal": 0,
    "remark": "Hydrogen Sulfide (H₂S)", "standard_id": 37, "standard_nama": "Standar Gas Mixture (H₂S)" },
  { "titik_ukur": 50,   "label": "CH4", "satuan": "%LEL", "resolusi": 1,   "desimal": 0,
    "remark": "Methane (CH4)",          "standard_id": 38, "standard_nama": "Standar Gas Mixture (CH4)" },
  { "titik_ukur": 17.9, "label": "O2",  "satuan": "%",    "resolusi": 0.1, "desimal": 1,
    "remark": "Oxygen (O2)",            "standard_id": 39, "standard_nama": "Standar Gas Mixture (O2)" }
]
```

> **Kenapa ini penting sampai dijadikan butir pertama.** Kalau layar menempelkan
> satu satuan ke semua kolom, kadar oksigen tercetak **"16,7 ppm"** alih-alih
> **"16,7 %"**. Angkanya benar; artinya meleset sepuluh ribu kali, di lembar
> kerja alat pendeteksi gas beracun.

**Aturannya:** ambil satuan dari `baris[].satuan`. Kalau `data.satuan` null,
JANGAN jatuh ke `equipment.satuan` — itu kolom "satuan terbesar" alat, isinya
`ppm`, dan salah untuk dua dari empat baris.

`desimal` juga per baris: O2 satu desimal, tiga lainnya bulat.

## 2. Baris kondisi lingkungan KETIGA: tekanan udara

Blok `CALIBRATION RESULT` sekarang punya enam field kondisi, bukan empat:

```json
[
  { "kode": "suhu_awal",        "label": "Env. Condition — First", "satuan": "°C" },
  { "kode": "kelembaban_awal",  "label": "Env. Condition — First", "satuan": "%RH" },
  { "kode": "tekanan_awal",     "label": "Env. Condition — First", "satuan": "hPa" },
  { "kode": "suhu_akhir",       "label": "Env. Condition — End",   "satuan": "°C" },
  { "kode": "kelembaban_akhir", "label": "Env. Condition — End",   "satuan": "%RH" },
  { "kode": "tekanan_akhir",    "label": "Env. Condition — End",   "satuan": "hPa" },
  { "kode": "thermohygro_standard_id", "label": "Environmental Meter Used" }
]
```

`tekanan_awal` & `tekanan_akhir` ikut di payload `POST /api/calibrations`,
persis seperti `suhu_awal`. Backend menghitung nilai terkoreksi
(`tekanan_udara`) dan `tekanan_ketidakpastian` sendiri — frontend tidak perlu
mengirim keduanya.

> **Tekanan bukan hiasan.** Sensor elektrokimia membaca TEKANAN PARSIAL gas,
> jadi konsentrasi yang ditampilkan bergerak mengikuti tekanan barometrik. Dua
> dari lima komponen ketidakpastian lahir dari PERGESERAN suhu & tekanan selama
> kerja (`|akhir − awal|`). Tanpa tekanan lengkap, backend jatuh ke jalur
> cadangan dan U95 yang keluar bukan angka yang dipakai master.
>
> Layar sebaiknya menandai dua field ini sebagai yang paling penting diisi,
> walau secara teknis semua kolom opsional. Backend juga mengirim peringatannya
> lewat `peringatan` sesi.

**Pilihan "Environmental Meter Used" cuma berisi satu unit:**

```json
[ { "nilai": "8", "label": "Thermobarometer Lutron", "grup": "Punya kalibrasi tekanan" } ]
```

Itu bukan bug dan bukan daftar yang belum lengkap: TH-1..TH-7 **tidak mengukur
tekanan sama sekali** (sertifikat kalibrasinya cuma dua parameter). Unit yang
secara fisik tidak bisa memberi angkanya memang tidak boleh muncul. Begitu lab
mengalibrasi thermohygro lain untuk tekanan, unit itu muncul sendiri di daftar.

## 3. Tabel hasil: satu kolom, tiga pengulangan

```json
{
  "tahap": "sebelum_adjustment",
  "judul": "Before Adjustment Reading",
  "kolom": [ { "kode": "pembacaan", "label": "Reading", "tipe": "angka", "satuan": null } ],
  "pengulangan": [1, 2, 3]
}
```

Dua hal:

- **Tidak ada kolom `suhu`.** Sembilan alat lain minta suhu larutan per
  pembacaan; di sini yang masuk hitungan suhu RUANGAN, bukan suhu media. Kalau
  layar mengasumsikan tiap sel selalu sepasang (pembacaan + suhu), sel suhunya
  akan kosong selamanya dan teknisi bertanya-tanya.
- **Tiga kolom Repeat, bukan lima.** `?pengulangan=N` tetap berlaku kalau
  teknisi mau lebih.

Ada dua tabel seperti biasa: `sebelum_adjustment` & `sesudah_adjustment`. Yang
masuk sertifikat yang **sesudah**.

## 4. Rentang & resolusi per gas di blok identitas

Blok `identitas_alat` membawa kunci tambahan `baris_rentang` — empat baris,
bukan satu:

```json
[
  { "gas": "CO",  "rentang": 1999, "resolusi": 1,   "satuan": "ppm" },
  { "gas": "H2S", "rentang": 200,  "resolusi": 1,   "satuan": "ppm" },
  { "gas": "CH4", "rentang": 100,  "resolusi": 1,   "satuan": "%LEL" },
  { "gas": "O2",  "rentang": 30,   "resolusi": 0.1, "satuan": "%" }
]
```

Tampilkan sebagai tabel kecil "Range/Resolution", bukan satu baris teks.

## 5. Tidak ada vonis PASS/FAIL

`keputusan` sesi dan `keputusan` tiap titik dua-duanya **null**, dan itu jawaban
yang benar — master tidak punya batas keberterimaan (MPE) sama sekali, dan
sertifikatnya berhenti di kolom `U95%`. Sama seperti Autoclave & DO Meter.

Layar jangan menampilkan chip PASS/FAIL kosong atau menerjemahkan null jadi
"FAIL". Kalau ada komponen bersama yang menganggap null = belum dihitung,
kecualikan alat ini (`profil.kode === 'gas_detector'`).

## 6. `kode_dokumen` null — dan itu disengaja

Nomor formulir LEMBAR KERJA Gas Detector belum ada di berkas mana pun. Satu-
satunya nomor di workbook (`SIDIK-FM-CAL-2403_Rev. 0`) adalah formulir
SERTIFIKAT, dipakai bersama semua alat.

Tampilkan kosong, jangan diisi placeholder yang terlihat seperti nomor asli.
Sudah diminta ke lab (`docs/pertanyaan-lab-gas-detector.md`); begitu dijawab,
kuncinya terisi sendiri tanpa frontend berubah.

---

## Angka acuan untuk mencocokkan tampilan

Sesi master `001-CAL-226` (order `2602.03.A.NK`, PT Unilever Skin Care Factory,
2 Februari 2026). Sudah terseed — `nomor_sesi` = `2602.03.A`.

Kondisi lingkungan:

```json
{
  "suhu_ruang": 23.85,      "suhu_ketidakpastian": 1.2041594578792,
  "kelembaban": 53,         "kelembaban_ketidakpastian": 4.2426406871193,
  "tekanan_udara": 924.15,  "tekanan_ketidakpastian": 2.1189620100417,
  "keputusan": null
}
```

Hasil per gas:

| Gas | Standard | UUT | Correction | U95% | k | Satuan |
|---|---|---|---|---|---|---|
| CO | 101 | 100,333 | +0,667 | 5,054 | 1,9715 | ppm |
| H2S | 25 | 23,667 | +1,333 | 1,538 | 2,0106 | ppm |
| CH4 | 50 | 49,667 | +0,333 | 2,617 | 1,9744 | %LEL |
| O2 | 17,9 | 16,733 | +1,167 | 0,887 | 1,9717 | % |

Koreksi POSITIF di keempat gas — alat membaca rendah, jadi angkanya perlu
ditambah. `U95%` dicetak sebagai KOLOM per baris (`u95PerTitik = true`), bukan
satu baris ringkas di bawah tabel: keempatnya berbeda besaran DAN berbeda
satuan.

Catatan yang tercetak di atas tabel hasil (`catatan_atas_tabel_hasil`):

> Correction factor from % to %LEL — Methane (CH4) = 2,5 % = 50 %LEL

Tampilkan apa adanya. Tanpa baris itu pembaca sertifikat tidak punya cara tahu
bahwa label botol "2,5 %" dan kolom "50 %LEL" adalah besaran yang sama.

---

## Endpoint yang dipakai (tidak ada yang baru)

| Aksi | Endpoint |
|---|---|
| Ambil lembar kerja | `GET /api/calibrations/lembar-kerja?profil=gas_detector` |
| Lembar kerja versi admin | `…&untuk=admin` |
| Atur jumlah Repeat | `…&pengulangan=5` |
| Preview hitungan | `POST /api/calibrations/preview` |
| Simpan sesi | `POST /api/calibrations` |

Payload `POST` sama seperti alat lain, ditambah `tekanan_awal` &
`tekanan_akhir`, dan tiap `measurements[]` membawa `satuan` barisnya sendiri:

```json
{
  "equipment_id": 12,
  "standard_id": 36,
  "input_method": "manual",
  "tanggal_kalibrasi": "2026-02-02T00:00:00Z",
  "suhu_awal": 22.8,      "suhu_akhir": 22.9,
  "kelembaban_awal": 53,  "kelembaban_akhir": 56,
  "tekanan_awal": 923.5,  "tekanan_akhir": 922.8,
  "measurements": [
    { "titik_ukur": 101,  "standard_id": 36, "satuan": "ppm",  "pembacaan": [100, 100, 101] },
    { "titik_ukur": 25,   "standard_id": 37, "satuan": "ppm",  "pembacaan": [24, 24, 23] },
    { "titik_ukur": 50,   "standard_id": 38, "satuan": "%LEL", "pembacaan": [50, 49, 50] },
    { "titik_ukur": 17.9, "standard_id": 39, "satuan": "%",    "pembacaan": [16.7, 16.8, 16.7] }
  ]
}
```

`standard_id` per baris WAJIB dikirim dan harus botol gasnya masing-masing —
ambil dari `baris[].standard_id` yang sudah dikirim lembar kerja. Keempat botol
Rigas ber-serial sama (`WO0125576`), jadi kalau frontend mengirim satu
`standard_id` untuk semua baris, sertifikat mencetak gas acuan yang salah di
tiga dari empat barisnya tanpa error apa pun.

## Yang TIDAK berubah

- Pindai foto (OCR) belum ada template untuk alat ini; jangan tampilkan
  tombolnya.
- Alur approve, sertifikat, PDF, QR: sama persis.
- Semua kolom tetap opsional — lembar kerja setengah jadi boleh dikirim dari
  lapangan. Yang menahan penerbitan sertifikat validator, bukan tombol kirim.
