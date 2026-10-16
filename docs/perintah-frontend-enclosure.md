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
| Status backend | Kalkulator + profil + ingest grid + olah data **selesai & diadu ke kedua master di SQLite + MySQL** — Uc, v_eff, k, U, & sebaran cocok, KECUALI sel master yang memang rusak (Yokogawa SP3 & Recorder SP3, lihat `docs/pertanyaan-lab-enclosure.md` #5); di situ kalkulator menghitung yang benar dan U95 yang dilaporkan tetap sama dengan sertifikat |

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

- **9 termokopel** (Type N: nomor 3–11; Type K: nomor 1–9 — ikut master),
  masing-masing **5 pembacaan**. Yang bernomor **terkecil** jadi **Sensor
  Acuan** (lihat bagian 4); urutan pengisiannya bebas.
- **Kalau kalibrator Recorder:** tiap termokopel juga punya **nomor Channel**
  (CH1..CH20) — koreksi recorder dibaca per kanal.
- **1 baris Indikator enclosure** (pembacaan alat itu sendiri, 5×).
- **1 baris Suhu Ruang** — **JANGAN dikirim dulu.** Backend belum punya tempat
  buat menampungnya (validasi request cuma mengenal `sensor_grid` & `indikator`),
  jadi kalau dikirim sekarang angkanya hilang tanpa pesan. Kegunaannya lagi
  ditanyakan ke lab — lihat `docs/pertanyaan-lab-suhu.md` **C-9**. Barisnya boleh
  ditampilkan di layar (biar sama dengan kertasnya), tapi jangan diikutkan ke
  `POST` sampai keputusannya turun.

`bentukLembarKerja` mengirim ringkasannya di kunci `grid_sensor`:

```json
{
  "grid_sensor": {
    "jumlah_sensor_saran": 9,
    "pengulangan": [1, 2, 3, 4, 5],
    "butuh_channel_untuk": "recorder",
    "baris_indikator": true,
    "baris_suhu_ruang": true,
    "catatan_sensor_acuan": "Sensor Acuan = termokopel bernomor TERKECIL yang terisi (keseragaman diukur relatif ke sensor itu). Urutan pengisian grid bebas — yang menentukan nomornya, bukan posisinya."
  }
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
        { "no": 5, "pembacaan": [15.1, 15.1, 15.1, 15.2, 15.2] },
        { "no": 6, "pembacaan": [15.1, 15.1, 15.2, 15.2, 15.3] },
        { "no": 7, "pembacaan": [15.0, 14.9, 14.9, 14.9, 14.9] },
        { "no": 8, "pembacaan": [15.2, 15.2, 15.3, 15.3, 15.4] },
        { "no": 9, "pembacaan": [15.0, 15.1, 15.1, 15.2, 15.2] },
        { "no": 10, "pembacaan": [15.1, 15.2, 15.2, 15.2, 15.3] },
        { "no": 11, "pembacaan": [14.9, 14.9, 14.9, 15.0, 15.0] }
      ],
      "indikator": [15.0, 15.0, 15.0, 15.0, 15.0]
    }
  ]
}
```

Contoh di atas memuat **satu** set point lengkap (9 termokopel). Set point
berikutnya ditambahkan sebagai entri berikutnya di array `measurements`.

Untuk kalibrator **Recorder**, tiap item `sensor_grid` tambah `"channel"`:

```json
{ "no": 1, "channel": 1, "pembacaan": [66.8, 66.81, 66.82, 66.81, 66.8] }
```

Catatan:
- `titik_ukur` = set point (°C).
- Sel kosong boleh dikirim `null` — disaring waktu hitung. Set point yang
  BENAR-BENAR kosong (tidak ada pembacaan termokopel MAUPUN Indikator) diabaikan;
  set point yang baru terisi Indikator saja tetap tersimpan.
- Jumlah set point bebas (ikut kapasitas alat) — sesi contoh 4 (Yokogawa) & 3
  (Recorder). Batas request: 60 set point, 40 sensor/set point, 20 pembacaan.
- Nomor termokopel tidak boleh kembar **dalam satu set point** (ditolak 422).
  Nomor yang sama di set point LAIN normal dan diterima — memang begitu cara
  alat ini dipakai.
- **Nomor termokopel harus ada di sertifikat sensor lab.** Type N mulai dari
  no. 3 (TCN3…TCN12), Type K dari no. 1. Nomor di luar itu — atau termokopel
  Recorder tanpa `channel` — bikin set point-nya TIDAK dihitung dan muncul di
  `belum_dihitung`, bukan dihitung dengan koreksi 0.

### Set point yang DISIMPAN tapi tidak dihitung

Empat hal bikin satu set point pindah ke `belum_dihitung`. Semuanya **tetap
tersimpan** (pembacaannya tidak hilang) dan **tidak menjatuhkan set point lain**
di sesi yang sama — jadi ini status per baris, bukan error seluruh request:

| kondisi | kenapa tidak dihitung |
|---|---|
| grid termokopel kosong, atau baris Indikator kosong | tidak ada bahan buat sebaran maupun budget |
| **< 2 termokopel** | Keseragaman & Variasi itu selisih antar-posisi; dengan satu sensor keduanya keluar `0,0` seolah sudah terbukti seragam |
| **ada termokopel dengan < 4 pembacaan** | master memetakan kolom `[1,2,3,3,4]`; di bawah 4 kolom yang hilang harus ditebak, dan tebakan itu mendarat di kolom Sebaran Suhu yang tercetak |
| nomor termokopel di luar tabel, atau Recorder tanpa `channel` | koreksinya tidak ketemu — dan koreksi yang hilang tidak boleh dianggap nol |

**Empat pembacaan sudah cukup**, bukan lima: master memang membuang pembacaan
ke-5 (lihat `docs/pertanyaan-lab-enclosure.md` #4), jadi grid 4 dan grid 5
memberi angka yang identik. Kolom ke-5 tetap ditampilkan supaya lembar kerjanya
sama dengan kertasnya.

**Saran UI:** tandai baris termokopel yang pembacaannya masih di bawah 4 SEBELUM
dikirim — lebih baik teknisi melengkapi di layar daripada set point-nya balik
sebagai `belum_dihitung` sesudah submit. `alasan` tiap entri `belum_dihitung`
sudah menyebut nomor termokopel dan jumlah pembacaannya, jadi bisa ditampilkan
apa adanya.

### Sensor Acuan = termokopel bernomor TERKECIL

Keseragaman diukur relatif ke Sensor Acuan, dan acuannya ditentukan dari **nomor
termokopel terkecil** yang terisi di set point itu — bukan dari urutan array.
Jadi **urutan `sensor_grid` bebas**: kirim urut nomor, urut posisi di chamber,
atau urut apa pun, angkanya sama.

Itu disengaja. Backend punya tiga jalur yang menyusun grid (simpan dari request,
validasi sebelum approve, dan hitung ulang dari data tersimpan), dan dua di
antaranya membaca balik dari database yang terkelompok per nomor sensor. Kalau
acuannya ikut urutan array, satu data mentah bisa menghasilkan dua angka
berbeda — pada grid contoh selisihnya 2× di kolom Keseragaman.

Satu hal yang **masih** bisa menggeser acuan: termokopel yang kolomnya kosong
dibuang sebelum dihitung, jadi kalau nomor terkecil tidak terisi, nomor
berikutnya yang jadi acuan. Tampilkan `sensor_acuan` (di
`type_b_components[]` kunci `sumber: "sebaran_sensor"`) di layar hasil supaya
kegeserannya kelihatan.

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

### Snapshot sertifikat (`GET /api/certificates/{id}` → `snapshot`) — sejak 15 Okt 2026

- `snapshot.hasil` untuk Enclosure **`[]`** (sama seperti Autoklaf). Tabel
  `Standard | UUT | Correction` TIDAK berlaku: kolom `koreksi` baris
  `uncertainty_calculations` enclosure berisi Keseragaman, bukan koreksi.
  Sertifikat lama (terbit sebelum perbaikan) masih punya baris itu — layar
  jangan mencetak kolom `correction` untuk alat enclosure.
- Isinya di **`snapshot.enclosure`**:

```json
{
  "satuan": "°C", "varian_master": "constant_yokogawa",
  "desimal": { "indikator": 1, "terukur": 1, "koreksi": 1, "u95": 1, "k": 0,
               "setting": 1, "indikator_kinerja": 1, "kinerja": 1 },
  "tanda_nol": true,
  "posisi": [{ "posisi": 1, "no": 3 }, { "posisi": 2, "no": 4 }],
  "set_point": [{
    "titik_ke": 1, "set_point": 25.5, "indikator": 25.5,
    "sensor_acuan": 3, "posisi_acuan": 1,
    "sensor": [{ "posisi": 1, "no": 3, "channel": null, "terukur": 24.60625, "koreksi": -0.89375 }, null],
    "keseragaman": 0.8, "kestabilan": 0.15, "variasi_keseluruhan": 1.3,
    "u95": 1.4, "faktor_cakupan_k": 1.9615165
  }]
}
```

- `sensor[i]` sejajar dengan `posisi[i]`; `null` = termokopel itu kosong di
  set point ini (jangan geser kolom). Kepala kolom master: `Sensor No. (No. 1
  Sensor Acuan)` — kalau `posisi_acuan` tidak sama di semua set point, tampilkan
  per set point.
- Angka presisi penuh; bulatkan di layar per KOLOM dengan `desimal.<kolom>`
  (format sel `SERTIFIKAT` varian masternya). `null` = format **General**:
  tulis apa adanya, nol di belakang dibuang (Recorder `setting` → `67`).
  - `constant_yokogawa`: semua `0.0`, `k` `0` (tercetak `2`).
  - `recorder`: `terukur`/`koreksi`/`indikator_kinerja`/`kinerja` `0.00`,
    `indikator` (tabel A) & `u95` `0.0`, `setting` General, `k` `0`.
  Pembulatan = tampilan Excel: bulatkan ke 15 angka penting dulu, baru ke
  desimal kolom, setengah menjauhi nol (`0,1499999999999996` → `0,2`).
- **Boleh `null`** — tampilkan `—`, jangan diganti 0: `u95` (baris hitung tanpa
  U tersimpan), `faktor_cakupan_k` (tanpa `v_eff`), `sensor_acuan` &
  `posisi_acuan` (jejak lama), dan elemen `sensor[i]`.
- `tanda_nol` (`true` di kelima enclosure): nilai negatif yang membulat ke nol
  tetap bertanda — `-0,04` tercetak `-0,0`. `false` = tanda dibuang (`0,0`).
- Pola tampilannya ikut PDF: tabel A (Indikator · Terukur/Koreksi per posisi ·
  U95) lalu kalimat `k` (set point pertama; `≈` kalau `k` cetak antar set point
  beda) lalu tabel B (Suhu Setting · Indikator · Keseragaman · Kestabilan ·
  Variasi Keseluruhan).
- Sejak 15 Okt 2026 Terukur/Keseragaman/Kestabilan/Variasi dihitung dari
  SELURUH pembacaan termokopel (peta kolom FC master yang rusak tidak ditiru);
  versi master ada di `type_b_components` `sumber: peta_kolom_master_rusak`.

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

Ada 13 hal yang tidak bisa diputuskan dari berkas dan sudah didokumentasikan di
**`docs/pertanyaan-lab-enclosure.md`** (pembagi drift `1,73` vs √3, √(√3) di
Recorder, radiasi 0,6 vs 0,1, blok SP3 kedua master yang salah sel, `#REF!`
PT100, GL840 expired, dll.). Semuanya ditiru apa adanya dengan catatan audit;
tidak mengubah angka yang sudah tercetak (lantai CMC menang di kedua sesi
contoh).

Dua di antaranya (**#12 batas kelengkapan grid**, **#13 Sensor Acuan**) BERKAITAN
dengan frontend — keduanya sudah dijelaskan di bagian 4 di atas. Kalau lab
menjawab lain, yang berubah cuma ambangnya di backend; bentuk request-nya tetap.
