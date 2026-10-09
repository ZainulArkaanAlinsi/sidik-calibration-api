# Perintah frontend — lembar **Volumetric Glassware** (alat ke-34..39, kelompok Volume)

Enam alat lampiran LK-285-IDN, dua keluarga, satu bentuk lembar. Dokumen ini
berdiri sendiri — tidak perlu membaca kode server untuk memasangnya.

| Kode profil | Nama lampiran (persis) | No. | Keluarga | Kertas |
|---|---|---|---|---|
| `labu_ukur` | Labu Ukur | 18 | Fixed | `SIDIK-FM-CAL-0513_Rev.4` |
| `pipet_volume` | Pipet Volume | 20 | Fixed | `SIDIK-FM-CAL-0513_Rev.4` |
| `picnometer` | Picnometer | 21 | Fixed | `SIDIK-FM-CAL-0513_Rev.4` |
| `buret` | Buret | 13 | Graduated | `SIDIK-FM-CAL-0514_Rev.4` |
| `gelas_ukur` | Gelas Ukur | 17 | Graduated | `SIDIK-FM-CAL-0514_Rev.4` |
| `pipet_ukur` | Pipet Ukur | 19 | Graduated | `SIDIK-FM-CAL-0514_Rev.4` |

`Buret Digital` (no. 14) **bukan** buret ini — server menjawab profilnya `null`
(form generik). Jangan cocokkan nama di HP dengan `contains("buret")`; pakai
kode profil yang dikirim server (`equipment.profil` di respons sesi).

---

## 1. Yang diketik teknisi BUKAN volume

Per titik tiga deret × tiga ulangan:

| Deret | Kunci | Satuan | Desimal kotak |
|---|---|---|---|
| a. Empty Container Weight | `vol_kosong` | g | 4 |
| b. Weight of Contents (wadah + air) | `vol_isi` | g | 4 |
| c. Temperature of Destillate Water | `vol_suhu` | °C | 1 |

Volume pada 20 °C (V20) dihitung server secara gravimetri, termasuk koreksi
termometer & sensor suhu air. HP tidak menghitung apa pun.

---

## 2. Bentuk lembar — sama pola dengan Hydrometer

Bagian `hasil` membawa **tiga** entri `tabel` yang barisnya sinkron:

```json
{
  "tahap": "sesudah_adjustment",
  "grup": "vol_kosong",
  "offset_kunci": 1000,
  "simpan_ke": "measurements[].vol_kosong",
  "judul_nilai": "Nominal (mL)",
  "titik_bisa_diubah": false,
  "baris": [{ "nomor": 1, "titik_ukur": null, "label": "Titik 1", "satuan": "g", "desimal": 4 }],
  "kolom": [{ "kode": "pembacaan", "label": "Berat Kosong", "tipe": "angka", "satuan": "g" }],
  "pengulangan": [1, 2, 3]
}
```

Dua tabel lainnya sama bentuknya: `vol_isi` (`offset_kunci` 2000) dan
`vol_suhu` (`offset_kunci` 3000, satuan °C, desimal 1).

- **Fixed: 1 baris. Graduated: 5 baris.** `titik_ukur: null` → kotak Nominal
  diketik teknisi (kertasnya membiarkan kolom itu kosong). Baris tanpa
  nominal ditahan HP seperti biasa.
- `offset_kunci` WAJIB dihormati — tanpa itu ketiga tabel berbagi kotak isian.
- Tidak ada jalur pindai foto untuk lembar ini (bentuk pindai: `didukung`
  dan `lokal` sama-sama `false`).

Jalur kirim `measurements[].<grup>` yang sudah dipakai Hydrometer & Flowmeter
cukup; **tidak perlu kontrak baru**. Yang perlu dicek di
`lib/services/lembar_kerja_service.dart`: keenam kode profil di atas
diarahkan ke jalur `simpan_ke` bernama, bukan bentuk bawaan.

---

## 3. Blok tingkat-sesi `spesifikasi_alat.volumetric`

Ada di bagian `identitas_alat`:

| Kode field | Tipe | Keterangan |
|---|---|---|
| `spesifikasi_alat.volumetric.kapasitas_ml` | angka | **Wajib untuk Graduated.** Lantai CMC diambil dari kapasitas, bukan nominal titik. Fixed boleh kosong (nominal = kapasitas). |
| `spesifikasi_alat.volumetric.resolusi_ml` | angka | **Graduated saja** (Fixed tidak punya kotak ini). Wajib. |
| `spesifikasi_alat.volumetric.kelas` | pilihan `A`/`B` | Dropdown — kelas lain tidak punya koefisien muai. |
| `spesifikasi_alat.volumetric.toleransi_ml` | angka | Fixed: harus ada di tabel ISO 4787 (mis. 0,008). |
| `spesifikasi_alat.volumetric.neraca` | pilihan | Isi pilihannya dari server, **beda per keluarga** (Fujitsu di Fixed, Precisa di Graduated). |

Plus `tekanan_awal` / `tekanan_akhir` (hPa) — **wajib** walau tidak tercetak
di kertas; densitas udara lahir dari situ.

---

## 4. Payload simpan (`POST /api/calibrations`)

```json
{
  "equipment_id": 12,
  "standard_id": 3,
  "tanggal_kalibrasi": "2026-09-22",
  "suhu_awal": 20.4, "suhu_akhir": 20.5,
  "kelembaban_awal": 64, "kelembaban_akhir": 62,
  "tekanan_awal": 933.2, "tekanan_akhir": 933.1,
  "measurements": [
    { "titik_ukur": 10,
      "vol_kosong": [60.234, 60.24, 60.243],
      "vol_isi":    [70.7791, 70.7965, 70.8854],
      "vol_suhu":   [25.4, 25.3, 25.4] }
  ],
  "spesifikasi_alat": { "volumetric": {
      "kelas": "B", "toleransi_ml": 0.5, "resolusi_ml": 1, "kapasitas_ml": 100,
      "neraca": "Electronic Balance Precisa" } }
}
```

Sel kosong dikirim `null` di posisinya (tiap deret `size:3`; Labu Ukur & Pipet
Volume: `vol_suhu` 3 atau 6, lihat §8). Titik yang
ketiga deretnya tidak lengkap **tidak disimpan** dan pulang sebagai
`belum_dihitung` — bukan 422 — supaya draft tetap bisa disimpan.

**Graduated: satu titik rusak menahan SEMUA titik** (budget-nya satu untuk
seluruh titik). Tampilkan alasan `belum_dihitung` apa adanya; teknisi cukup
melengkapi deret yang kurang. Graduated juga butuh **minimal dua titik**.

---

## 5. Yang dikembalikan & dicetak

| Kolom sertifikat | Sumber | Catatan |
|---|---|---|
| Nominal Value | `titik_ukur` | |
| Actual Volume | `rata_rata` | V20, mL |
| Correction | `-koreksi` | Tercetak = **V20 − Nominal** (profil membalik tanda) |
| U95% | `ketidakpastian_diperluas` | SATU angka untuk semua baris; Fixed 4 desimal, Graduated 2 desimal |
| k | `faktor_cakupan_k` | dicetak bulat |

Tidak ada PASS/FAIL (`keputusan` selalu `null`).

---

## 6. Yang perlu ditanyakan balik ke backend kalau aneh

- Sesi tersimpan tapi `titik` kosong → baca `belum_dihitung`; hampir selalu
  kelas bukan A/B, neraca salah keluarga, tekanan kosong, atau kapasitas kosong.
- Angka berbeda dari Excel lab di digit belakang → wajar untuk dua hal yang
  sengaja dihitung benar (Veff Fixed, keterulangan Graduated); selisihnya
  tercatat di jejak sesi, lihat `docs/pertanyaan-lab-volumetric.md` no. 1 & 2.

## 7. Labu Ukur & Pipet Volume ikut workbook Rev.7 — perubahan 8 Okt 2026 (tahap 1)

Semua datang dari bentuk lembar server; HP yang merender `bagian[]` apa adanya
tidak perlu diubah untuk butir 1–3.

1. **Usage Check** (`bagian[kode=usage_check].baris`) Labu Ukur & Pipet Volume
   jadi empat baris: Balance Excellent, Balance Mettler Toledo, **Balance
   Fujitsu**, **Termometer & Sensor Std. (Yokogawa CA 150)**. Baris "RTD Sensor"
   tidak ada lagi di kedua lembar ini (Picnometer & Graduated tidak berubah).
   Mencentang SATU neraca menimpa "Balance Used"; mencentang dua lalu mengirim
   → 422 `errors.standar_dicek` (tampilkan apa adanya). Termometer tidak wajib
   dicentang — server selalu menautkannya.
2. **Thermohygro Used** menawarkan **Thermobarometer Lutron** (satu-satunya yang
   mengukur tekanan) selain TH-1..TH-7.
3. `budget_ketidakpastian` bertuliskan tujuh komponen + sumber workbook Rev.7.
4. **Sesi tanpa angka:** jawaban `POST`/`PUT /api/calibrations` membawa
   `meta.belum_dihitung` (bentuk sama dengan preview). Yang PERLU ditambah di HP:
   tampilkan daftar itu sesudah kirim kalau tidak kosong. Rinciannya
   `docs/kontrak-api.md` §4 `meta.belum_dihitung`.
5. **Enam bacaan suhu air (awal & akhir per ulangan)** — tahap 2, lihat §8.

## 8. Labu Ukur & Pipet Volume: enam bacaan suhu air — 9 Okt 2026 (tahap 2)

Workbook Rev.7 mencatat suhu air awal & akhir tiap ulangan (`INPUT DATA!H39:M39`).
Lembar Labu Ukur & Pipet Volume kini mengirim tabel `vol_suhu` dengan **enam**
kotak; profil lain (Picnometer, Buret, Gelas Ukur, Pipet Ukur) tetap tiga.

**Bentuk lembar** — hanya tabel `vol_suhu` yang berubah; `vol_kosong` & `vol_isi`
tetap `pengulangan: [1, 2, 3]`:

```json
{
  "grup": "vol_suhu",
  "offset_kunci": 3000,
  "simpan_ke": "measurements[].vol_suhu",
  "pengulangan": [1, 2, 3, 4, 5, 6],
  "pengulangan_arah": [
    { "ke": 1, "label": "X1 Awal" }, { "ke": 2, "label": "X1 Akhir" },
    { "ke": 3, "label": "X2 Awal" }, { "ke": 4, "label": "X2 Akhir" },
    { "ke": 5, "label": "X3 Awal" }, { "ke": 6, "label": "X3 Akhir" }
  ]
}
```

`pengulangan_arah` sudah dibaca HP ke kepala kolom (pola TITS/Timbangan/Piston),
jadi **tidak perlu kode HP baru**: renderer data-driven menggambar enam kotak
berlabel, dan `_measurementsDeretBernama` mengirim enam angka berurutan.

**Payload** — urutan = urutan kotak:

```json
{ "titik_ukur": 250,
  "vol_kosong": [0, 0, 0],
  "vol_isi":    [248.823, 248.833, 248.831],
  "vol_suhu":   [25.2, 25.2, 25.3, 25.3, 25.2, 25.2] }
```

| Kiriman `vol_suhu` | Labu Ukur & Pipet Volume | Profil Volumetric lain |
|---|---|---|
| 3 angka (APK lama) | **diterima**, angka persis seperti sebelumnya | diterima |
| 6 angka, semua terisi | **diterima** — awal & akhir tiap ulangan | **422** `errors["measurements.0.vol_suhu"]` |
| 6 kotak, sebagian `null` | titik **tidak disimpan**, alasan di `meta.belum_dihitung` (bukan 422, supaya draft tetap tersimpan) | — |
| panjang lain (1, 2, 4, 5, 7, …) | **422** `errors["measurements.0.vol_suhu"]`, pesan menyebut 3 atau 6 | **422** (`size:3`) |

Kotak yang terisi sebagian sengaja tidak "dirapatkan" jadi tiga bacaan: tiga
angka pertama dari enam kotak adalah X1 awal, X1 akhir, X2 awal — bukan tiga
ulangan.

**Yang dihitung server** (rinci: `docs/pertanyaan-lab-volumetric.md` no. 14–16):
tiap bacaan dikoreksi sendiri; suhu ulangan = rata-rata awal & akhir terkoreksi,
ρ air dari suhu itu (ulangan 1 & 2 tetap 25,5 °C); suhu air budget = rata-rata
keenamnya; rentang u suhu meniru `O35` workbook (lima bacaan pertama untuk MAX).
Enam bacaan kembar (awal = akhir) memberi angka identik dengan tiga bacaan.
HP tidak menghitung apa pun.
