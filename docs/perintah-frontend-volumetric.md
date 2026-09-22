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

Sel kosong dikirim `null` di posisinya (tiap deret `size:3`). Titik yang
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
