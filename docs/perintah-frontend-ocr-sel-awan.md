# Perintah frontend — OCR awan atas potongan sel angka

Tanggal: 25 Nov 2026. Status: **backend siap, bawaan MATI, belum pernah dipanggil ke Google sungguhan. Aplikasi Lab belum disambung.**
Kontrak rinci: `docs/kontrak-api.md` bagian 22A. Aturan: `AGENTS.md` §OCR awan.

Beda dengan `docs/perintah-frontend-ocr-cloud.md` (foto utuh, `POST /api/ocr/halaman`, tetap berlaku dan tidak berubah):
di sini yang keluar ke Google hanya **potongan sel angka**, dan yang memotong adalah **server**.

## Endpoint

`POST /api/worksheet-scans/{id}/ocr-awan`, JSON, peran teknisi (pemilik hasil pindai) / Master Data / super admin.

Body (semuanya boleh dikosongkan):

```json
{ "kunci": ["tabel_a|1|1|pembacaan", "tabel_a|2|1|pembacaan"] }
```

- Tanpa `kunci`: server memilih sendiri sel angka yang masih perlu dicek (bukan kosong, bukan hijau di HP).
- Dengan `kunci`: persis sel itu (sel hijau boleh dibaca ulang). Maks 48 kunci per permintaan.
- **Tidak ada gambar, kotak, atau foto di body.** Server memotong dari citra hasil pindai yang sudah tersimpan.
  Bidang lain di body diabaikan.

## Respons `200`

```json
{
  "hasil": [
    { "kunci": "tabel_a|1|1|pembacaan", "teks_awan": "4.01", "keyakinan": 0.93, "status": "kuning",
      "teks_normal": "4,01", "nilai_awan": 4.01, "dinormalkan": true, "alasan": [] }
  ],
  "dilewati": [ { "kunci": "isian|suhu_awal", "alasan": "isian_tidak_dikirim" } ],
  "meta": { "sumber": "google_vision", "fitur": "DOCUMENT_TEXT_DETECTION", "unit": 1, "jumlah_dikirim": 1,
            "jumlah_panggilan": 1, "usulan": true, "sebagian": false,
            "dilewati_otomatis": { "sudah_hijau_lokal": 3, "kosong_lokal": 1 }, "sisa_sel_scan": 79 }
}
```

- `teks_awan`: teks dari Google **apa adanya**. Tampilkan ini sebagai bacaan awan. `null` bila Google gagal untuk sel itu;
  `""` bila Google tidak melihat tulisan.
- `teks_normal` / `nilai_awan`: bentuk angka murni (koma desimal) bila `teks_awan` lolos `NormalisasiAngka`; selain itu `null`.
  `dinormalkan: true` berarti bentuknya BEDA dari `teks_awan` (mis. `4.01` jadi `4,01`): **tampilkan keduanya**, jangan
  menukar diam-diam.
- `status`: paling tinggi **`kuning`**. Tidak pernah `hijau` (tulisan tangan). `merah` = tidak lolos normalisasi, keyakinan
  di bawah 0,60 / tidak ada, atau Google gagal untuk sel itu (lihat `alasan`). `kosong` = Google tidak melihat tulisan.
- **Semua ini USULAN.** Server tidak menulis apa pun ke sel hasil pindai. HP menampilkan usulan, teknisi mengonfirmasi atau
  mengetik sendiri, lalu simpan lewat jalur biasa (`POST /worksheet-scans/{id}/koreksi`). Jangan mengisi sel otomatis dari
  `teks_awan`.
- `dilewati[].alasan`: `isian_tidak_dikirim`, `centang_tidak_dikirim`, `bukan_sel_angka` (mis. jam), `kosong_lokal`,
  `tidak_ada_di_hasil_pindai`, `melebihi_batas_permintaan`, `melebihi_batas_scan`, `jatah_lembar_habis`, `google_gagal`,
  `citra_bukan_halaman_utuh`, `kotak_tidak_ada`, `kotak_di_luar_citra`, `potongan_kosong`. Sel yang dilewati tetap
  diperlakukan seperti tanpa OCR awan (pembaca di HP / isi manual).
- `meta.sebagian: true`: sebagian sel terbaca, sisanya tidak sempat (Google gagal di tengah). Sel yang tidak terbaca ada di
  `dilewati` dengan alasan `google_gagal`.

## Galat

Semuanya `{ "message", "kode", "fallback_manual": true }`. **Jatuh ke pembaca di HP / isi manual, tanpa crash.** `message`
adalah kalimat tetap; tampilkan apa adanya.

| HTTP | `kode` | Arti |
|---|---|---|
| `503` | `fitur_dimatikan` | Super Admin mematikan `ocr_cloud_sel` atau `pindai_lembar` |
| `503` | `ocr_cloud_belum_aktif` | env server mati (bawaan) |
| `503` | `ocr_cloud_belum_disetel` | kredensial server belum benar |
| `503` | `citra_tidak_bisa_dipotong` | server tanpa GD |
| `422` | `citra_tidak_tersimpan` / `citra_rusak` | citra hasil pindai tidak ada di server, rusak, atau terlalu besar (lebih dari 16 juta piksel, mis. 48 MP); pindai ulang. Tidak ada kode baru untuk "terlalu besar": tangani sama dengan `citra_rusak` |
| `422` | `gambar_tidak_terbaca` | Google tidak bisa membaca satu pun potongan |
| `429` | `batas_per_lembar` | jatah panggilan per sesi habis |
| `429` | `gagal_berulang_per_lembar` | Google gagal berulang untuk sesi itu; **berhenti memanggil untuk lembar itu** |
| `429` | `batas_sel_per_scan` | batas sel OCR awan per hasil pindai habis |
| `429` | — | limiter 4 permintaan per menit per akun |
| `502` | `ocr_cloud_gagal` | Google gagal, waktu habis, atau jaringan putus |
| `404` / `403` | — | hasil pindai organisasi lain / milik teknisi lain |

## Yang harus dilakukan aplikasi Lab (belum dikerjakan)

1. Sakelar klien bawaan MATI (`--dart-define`, nama final menyusul). Server punya tiga gerbang sendiri; klien menyala belum
   berarti server menjawab. Baca `fitur_nonaktif` di `GET /api/me/permissions`: bila memuat `ocr_cloud_sel` atau
   `pindai_lembar`, sembunyikan tombolnya.
2. Tombol "Baca ulang dengan OCR awan" per hasil pindai (dan boleh per sel). Kirim `kunci` bila teknisi memilih sel tertentu.
   **Jangan memanggil per sel dalam satu putaran**: satu permintaan boleh memuat banyak sel (server memecah per 16 gambar).
3. Tampilkan `teks_awan` dan `teks_normal` berdampingan bila `dinormalkan`, status sebagai kuning/merah/kosong, dan
   tandai "usulan awan". Konfirmasi manusia wajib sebelum nilai dipakai.
4. Tangani semua galat di atas dengan jatuh ke jalur lama. Jangan mengulang otomatis pada `429`/`502`.
5. Batas yang perlu diketahui: 80 sel per hasil pindai (hitungan lunak, bisa hilang saat cache dibersihkan), 5 panggilan per
   sesi (`GOOGLE_VISION_MAKS_PER_SESI`, satu panggilan = hingga 16 sel). Jatah per sesi dipakai BERSAMA dengan
   `POST /api/ocr/halaman` (foto utuh): satu panggilan dari jalur mana pun mengurangi sisa jatah keduanya.
6. Hasil pindai TANPA sesi kalibrasi tetap boleh memakai endpoint ini. Jatah dan batas kegagalan per sesi tidak berlaku
   untuknya; yang membatasi tinggal 4 permintaan per menit dan 80 sel per hasil pindai. Jangan mengandalkan `batas_per_lembar`
   untuk hasil pindai tanpa sesi.
7. Citra hasil pindai di atas 16 juta piksel ditolak server (`422 citra_rusak`). Foto HP 12 MP lolos; kalau kamera diset
   48/50 MP, kecilkan sebelum diunggah sebagai hasil pindai.

## Belum dikerjakan / belum terbukti

- **Akurasi OCR awan pada tulisan tangan nyata BELUM terukur.** Tes memakai Google palsu (`Http::fake`); tidak ada panggilan
  nyata dan tidak ada foto uji berlabel manusia. Jangan menyebut angka akurasi.
- **Biaya:** asumsi 1 gambar = 1 unit tagihan belum dicocokkan dengan laporan Google Cloud (`SUM(unit_vision)`).
- Kualitas potongan sel kecil (tanpa pembesaran) belum diuji pada foto nyata.
- Penyalaan di produksi menunggu pemilik: butir 4 `AGENTS.md` §OCR awan (kontrak pelanggan, ISO/IEC 17025 bagian 4.2).
  Sel identitas tidak pernah dikirim, tetapi potongan angka tabel tetap meninggalkan server.
