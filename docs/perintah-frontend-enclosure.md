# Perintah Frontend — Enclosure (Oven, Furnace, Bath, Inkubator, Refrigerator)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi ke sesi kerja
frontend — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api` |
| Formulir lembar kerja | **belum ada nomornya** — `kode_dokumen: null` |
| Formulir sertifikat | `SIDIK-FM-CAL-2403_Rev. 0` |
| Metode | `SIDIK-IK-CAL-0501_Rev.6` |
| Master | `…Enclosure_Constant_Yokogawa.xlsm` (sesi `0123-CAL-524`) & `…Enclosure_Recorder.xlsm` (sesi `0304-CAL-624`) |
| Kode profil | `oven`, `furnace`, `bath`, `inkubator`, `refrigerator` (satu mesin hitung) |
| Status backend | Kalkulator + profil + ingest grid + olah data **selesai & terverifikasi persis ke kedua master di MySQL** (Uc, v_eff, k, U, sebaran semua cocok) |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

**Kabar penting: Enclosure TIDAK memakai tabel pembacaan datar seperti sepuluh
alat lain.** Tiap set point adalah **GRID**: banyak termokopel ditaruh di
berbagai posisi dalam chamber, tiap termokopel dibaca 5×, plus baris Indikator
enclosure & Suhu Ruang. Ini layar input baru — paling mirip Autoclave, bukan pH.

## Ringkas: apa yang beda

| | Alat lain | Enclosure |
|---|---|---|
| Satu titik = | satu deret pembacaan | **GRID: 9 termokopel × 5 pembacaan + Indikator + Suhu Ruang** |
| Field wajib khusus | — | **`tipe_sensor`** (Type N/Type K) + **kalibrator** (via standar) |
| Nomor kanal | — | **wajib per termokopel kalau kalibrator Recorder** |
| U95 | per titik / per sesi | **per SET POINT** (tiap set point punya U95 sendiri) |
| Hasil sertifikat | koreksi per titik | **2 bagian**: Sebaran Suhu (per sensor) + Kinerja Enclosure (keseragaman/kestabilan/variasi) |
| Vonis PASS/FAIL | ada (kebanyakan) | **tidak ada** |

---

## 1. Jenis enclosure = jenis alat (bukan pilihan per sesi)

Ada LIMA profil terpisah, satu per jenis enclosure, karena tiap jenis punya CMC
sendiri di lampiran akreditasi:

| profil | `nama_alat_kemampuan` | CMC | rentang (RLK) |
|---|---|---|---|
| `oven` | `Oven` | 1,5 °C | amb–300 °C |
| `furnace` | `Furnace` | 3,0 °C | 300–1000 °C |
| `bath` | `Bath` | 1,2 °C | 0–100 °C |
| `inkubator` | `Inkubator` | 1,4 °C | 15–100 °C |
| `refrigerator` | `Refrigerator` | 1,5 °C | −20–10 °C |

Jenisnya melekat di **alat** (`equipments.nama_alat_kemampuan`), bukan dipilih
tiap sesi — oven tidak pernah jadi bath. Frontend tidak perlu dropdown jenis
enclosure; ikut jenis alatnya.

## 2. Dua hal yang menentukan ANGKA: `tipe_sensor` & kalibrator

**`tipe_sensor`** (`Type N` / `Type K`) — field tingkat atas di body `POST`,
sama tempatnya dengan TITS. Tanpa ini sesi TIDAK dihitung (koreksi, drift, U95
termokopel semua beda per tipe).

**Kalibrator** ditentukan dari **standar yang dicentang** (`standard_id`), lewat
kolom `standards.merk`:

| `merk` standar | tabel yang dipakai | butuh kanal? |
|---|---|---|
| `Constant` | Constant | tidak |
| `Yokogawa` | Yokogawa | tidak |
| `Graphtech` / mengandung "recorder" | Recorder (GL840) | **ya** |

Frontend tidak mengirim "merk" terpisah — cukup `standard_id` yang benar.

## 3. Bentuk GRID per set point (INTI dokumen ini)

Tiap set point diisi:

- **9 termokopel** (Type N: nomor 3–11; Type K: nomor 1–9 — ikut master, sensor
  pertama = **Sensor Acuan**), masing-masing **5 pembacaan**.
- **Kalau kalibrator Recorder:** tiap termokopel juga punya **nomor Channel**
  (CH1..CH20) — koreksi recorder dibaca per kanal.
- **1 baris Indikator enclosure** (pembacaan alat itu sendiri, 5×).
- **1 baris Suhu Ruang** (opsional, informatif — belum masuk budget; lihat
  `docs/pertanyaan-lab-enclosure.md` #… ).

`bentukLembarKerja` mengirim ringkasannya di kunci `grid_sensor`:

```json
"grid_sensor": {
  "jumlah_sensor_saran": 9,
  "pengulangan": [1, 2, 3, 4, 5],
  "butuh_channel_untuk": "recorder",
  "baris_indikator": true,
  "baris_suhu_ruang": true,
  "catatan_sensor_acuan": "Sensor pertama = Sensor Acuan (keseragaman diukur relatif ke sensor ini)."
}
```

## 4. Bentuk request `POST /api/calibrations` (dan `/preview`)

`measurements[]` = satu entri per **set point** (bukan per baris). Tiap entri:

```json
{
  "equipment_id": 12,
  "standard_id": 4,
  "input_method": "manual",
  "tanggal_kalibrasi": "2024-05-02",
  "tipe_sensor": "Type N",
  "suhu_awal": 23.7, "suhu_akhir": 23.7,
  "kelembaban_awal": 47, "kelembaban_akhir": 46,
  "measurements": [
    {
      "titik_ukur": 15.0,
      "satuan": "°C",
      "sensor_grid": [
        { "no": 3, "pembacaan": [15.0, 15.1, 15.1, 15.1, 15.1] },
        { "no": 4, "pembacaan": [15.2, 15.3, 15.2, 15.3, 15.2] },
        { "no": 5, "pembacaan": [15.1, 15.1, 15.1, 15.2, 15.2] }
        /* … total 9 termokopel … */
      ],
      "indikator": [15.0, 15.0, 15.0, 15.0, 15.0]
    }
    /* … set point berikutnya … */
  ]
}
```

Untuk kalibrator **Recorder**, tiap item `sensor_grid` tambah `"channel"`:

```json
{ "no": 1, "channel": 1, "pembacaan": [66.8, 66.81, 66.82, 66.81, 66.8] }
```

Catatan:
- `titik_ukur` = set point (°C).
- Sel kosong boleh dikirim `null` — disaring waktu hitung. Set point yang tidak
  punya satu pun pembacaan termokopel otomatis diabaikan.
- Jumlah set point bebas (ikut kapasitas alat) — sesi contoh 4 (Yokogawa) & 3
  (Recorder).

## 5. U95 PER SET POINT

Beda dari TITS (satu U95 seluruh sesi): tiap set point keluar `U95%` sendiri.
Respons `preview`/`GET` memulangkan satu baris hitungan per set point di
`data.titik[]`, masing-masing membawa `ketidakpastian_diperluas`,
`faktor_cakupan_k`, `derajat_kebebasan_efektif`.

## 6. Hasil sertifikat: DUA bagian

Master mencetak dua tabel per sesi (bukan satu kolom koreksi):

**A) Sebaran Suhu** — per set point, per sensor:
- `Pembacaan Indikator Enklosur` (angka alat).
- Untuk tiap sensor: `Terukur` (rata-rata terkoreksi) & `Koreksi` (= Terukur −
  Indikator).
- `U95% ±` set point itu.

**B) Kinerja Enklosur** — per set point:
- `Keseragaman` (KS), `Kestabilan` (SS), `Variasi Keseluruhan` (VK).

Backend menaruh semua ini di `uncertainty_calculations.type_b_components`
(kunci `sumber: "sebaran_sensor"` membawa `kestabilan`, `keseragaman`,
`variasi_keseluruhan`, dan array `sensor[]` dengan `no`, `channel`,
`rata_rata_terkoreksi`, `koreksi_vs_indikator`). `k` dicetak **2 desimal**.

## 7. Yang TIDAK ada di alat ini

- **Tidak ada vonis PASS/FAIL** (`punyaToleransi: false`, `keputusan: null`) —
  master berhenti di baris `U95%`, tidak membandingkan ke batas keberterimaan.
- **Tidak ada mode Measure/Source** (itu punya TITS).

## 8. Peringatan sesi yang mungkin muncul

Endpoint validasi/approve mengembalikan `validasi.temuan[]`:

| kode | arti |
|---|---|
| `enclosure_tipe_sensor_kosong` | tipe sensor belum dipilih — sesi tidak kehitung |
| `enclosure_kalibrator_kosong` | merk kalibrator tidak kebaca dari standar |
| `enclosure_cmc_kosong` | CMC jenis enclosure belum ada (jalankan seeder) |

---

## Catatan untuk backend/lab (bukan pekerjaan frontend)

Ada 11 hal di master yang tidak bisa diputuskan dari berkas dan sudah
didokumentasikan di **`docs/pertanyaan-lab-enclosure.md`** (pembagi drift `1,73`
vs √3, √(√3) di Recorder, radiasi 0,6 vs 0,1, blok SP3 kedua master yang salah
sel, `#REF!` PT100, GL840 expired, dll.). Semuanya ditiru apa adanya dengan
catatan audit; tidak mengubah angka yang sudah tercetak (lantai CMC menang di
kedua sesi contoh). Frontend tidak perlu menangani ini.
