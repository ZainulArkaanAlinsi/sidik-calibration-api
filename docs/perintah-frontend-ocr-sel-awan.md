# Perintah frontend — OCR awan atas potongan sel angka

Tanggal: 25 Nov 2026. Status: **kontrak jalur unggah (`POST /api/ocr/sel-awan`) ditulis lebih dulu supaya mobile bisa mulai; kode
server menyusul di PR yang sama. Bawaan MATI, belum pernah dipanggil ke Google sungguhan. Aplikasi Lab belum disambung.**
Kontrak rinci: `docs/kontrak-api.md` bagian 22B (jalur yang dipakai aplikasi Lab) dan 22A (jalur lama, server memotong). Aturan:
`AGENTS.md` §OCR awan.

Beda dengan `docs/perintah-frontend-ocr-cloud.md` (foto utuh, `POST /api/ocr/halaman`, tetap berlaku dan tidak berubah):
di sini yang keluar ke Google hanya **potongan sel angka**.

## Jalur mana yang dipakai aplikasi Lab

**`POST /api/ocr/sel-awan` (bagian 22B). HP memotong sendiri sel angka dari fotonya dan mengunggah potongannya.**

Jalur lama `POST /api/worksheet-scans/{id}/ocr-awan` (bagian 22A) membutuhkan `scan_id` dan citra hasil pindai yang tersimpan di
server. Aplikasi Lab tidak punya keduanya (foto dan halaman utuh tidak pernah meninggalkan HP, K-50-11), jadi **jangan memakainya
dari aplikasi Lab**. Jalur itu tetap ada, tidak berubah, untuk klien yang memang menyimpan hasil pindai di server.

Keputusan pemilik 24-25 Nov 2026: ke Google hanya potongan sel angka. Bukan foto utuh, bukan sel identitas (nama, alamat, nomor
seri, tanda tangan), bukan tanggal, bukan centang. Server menegakkan ini (kunci non-angka dibuang), tetapi **klien tidak boleh
mengandalkan penjagaan itu**: jangan memotong dan mengirim sel yang bukan angka.

## Endpoint

`POST /api/ocr/sel-awan`, `multipart/form-data`, peran teknisi / Master Data / super admin.

| Field | Wajib | Isi |
|---|---|---|
| `template_id` | ya | kode templat dari `GET /worksheet-templates` (pengenal yang sama dengan `POST /worksheet-scans`) |
| `kertas` | tidak | `cetak` (bawaan) atau `asli` |
| `kode_dokumen` | tidak | kode FM dari kaki formulir asli (pemilih varian) |
| `equipment_id` | tidak | alat seorganisasi |
| `jumlah_pengulangan` | tidak | 2 sampai 10, sama dengan `GET /worksheet-templates/{kode}` |
| `calibration_session_id` | tidak | sesi kerja seorganisasi (menyalakan jatah per sesi) |
| `sel[0][kunci]`, `sel[0][gambar]`, `sel[1][kunci]`, ... | ya | kunci sel templat + berkas potongan (JPEG/PNG) |

- `kunci` persis seperti di `GET /worksheet-templates/{kode}` (`tabel|baris|repeat|field`). Kirim `template_id`, `kertas`,
  `kode_dokumen`, `equipment_id`, `jumlah_pengulangan` yang SAMA dengan yang dipakai saat mengambil templat, supaya server
  menyusun templat yang sama.
- Satu potongan = satu sel. Potong tepat di kotak sel (dengan margin kecil), jangan sebesar halaman.
- Batas per potongan: **300 KB** dan **500.000 piksel** (lebar x tinggi). Potongan sel yang wajar (mis. 200 x 80 px sampai
  600 x 300 px, JPEG kualitas 80-90) jauh di bawahnya. Angka ini tebakan awal yang belum diukur pada foto nyata; bila potongan
  nyata ternyata sering melewatinya, laporkan ke backend, jangan menurunkan resolusi sampai tulisan tidak terbaca.
- **Kirim paling banyak 20 sel per permintaan** selama server belum menaikkan `max_file_uploads` PHP (bawaan 20; berkas ke-21
  dan seterusnya dibuang PHP diam-diam dan server menjawab `422 permintaan_tidak_valid`). Batas aplikasi 48 sel belum bisa
  dipakai penuh. Dua permintaan untuk 40 sel muat dalam limiter 4 per menit.
- **Tidak ada foto utuh, halaman utuh, atau potongan sel non-angka di body.** Potongan tidak disimpan di server.

## Respons `200`

Bentuk per sel SAMA dengan jalur 22A:

```json
{
  "hasil": [
    { "kunci": "tabel_a|1|1|pembacaan", "teks_awan": "4.01", "keyakinan": 0.93, "status": "kuning",
      "teks_normal": "4,01", "nilai_awan": 4.01, "dinormalkan": true, "alasan": [] }
  ],
  "dilewati": [ { "kunci": "isian|suhu_awal", "alasan": "isian_tidak_dikirim" } ],
  "meta": { "sumber": "google_vision", "fitur": "DOCUMENT_TEXT_DETECTION", "unit": 1, "jumlah_dikirim": 1,
            "jumlah_panggilan": 1, "usulan": true, "sebagian": false, "sisa_sel_lembar": 159 }
}
```

- `teks_awan`: teks dari Google **apa adanya**. Tampilkan sebagai bacaan awan. `null` bila Google gagal untuk sel itu; `""` bila
  Google tidak melihat tulisan (sel kosong tetap kosong).
- `teks_normal` / `nilai_awan`: bentuk angka murni (koma desimal) bila `teks_awan` lolos `NormalisasiAngka`; selain itu `null`.
  `dinormalkan: true` berarti bentuknya BEDA dari `teks_awan` (mis. `4.01` jadi `4,01`): **tampilkan keduanya**, jangan menukar
  diam-diam.
- `status`: paling tinggi **`kuning`**, tidak pernah `hijau` (tulisan tangan). `merah` = tidak lolos normalisasi, keyakinan di
  bawah 0,60 / tidak ada, atau Google gagal untuk sel itu (lihat `alasan`). `kosong` = Google tidak melihat tulisan.
- **Semua ini USULAN.** Server tidak menulis apa pun. HP menampilkan usulan, teknisi mengonfirmasi atau mengetik sendiri.
  Jangan mengisi sel otomatis dari `teks_awan`.
- `dilewati[].alasan`: `kunci_kembar`, `tidak_ada_di_templat`, `isian_tidak_dikirim`, `centang_tidak_dikirim`, `bukan_sel_angka`
  (mis. jam), `potongan_kebesaran_byte`, `potongan_kebesaran_piksel`, `potongan_bukan_citra`, `melebihi_batas_lembar`,
  `jatah_lembar_habis`, `google_gagal`. Sel yang dilewati diperlakukan seperti tanpa OCR awan (pembaca di HP / isi manual).
- `meta.sebagian: true`: sebagian sel terbaca, sisanya tidak sempat (Google gagal di tengah). Sel yang tidak terbaca ada di
  `dilewati` dengan alasan `google_gagal`.

## Galat

Galat dari server ini membawa `{ "message", "kode", "fallback_manual": true }`. Dua jawaban datang dari middleware bersama dan
TIDAK membawa `fallback_manual`: `503 fitur_dimatikan` dan `401`/`403` peran. **Perlakukan SETIAP jawaban bukan-2xx dari
endpoint ini sebagai "jatuh ke pembaca di HP / isi manual", tanpa crash.** `message` kalimat tetap; tampilkan apa adanya.

| HTTP | `kode` | Arti |
|---|---|---|
| `503` | `fitur_dimatikan` | Super Admin mematikan `ocr_cloud_sel` atau `pindai_lembar` |
| `503` | `ocr_cloud_belum_aktif` | env server mati (bawaan) |
| `503` | `ocr_cloud_belum_disetel` | kredensial server belum benar |
| `422` | `permintaan_tidak_valid` | field wajib hilang, `sel` kosong / lebih dari 48, `gambar` hilang (termasuk terpotong batas 20 berkas PHP) |
| `422` | `templat_tidak_dikenal` | `template_id` / `kertas` / `kode_dokumen` tidak menghasilkan templat |
| `422` | `tidak_ada_potongan_valid` | tidak ada satu pun sel yang boleh dikirim; lihat `dilewati[]` |
| `422` | `gambar_tidak_terbaca` | Google tidak bisa membaca satu pun potongan |
| `404` | `sesi_tidak_ditemukan` | sesi tidak ada atau milik organisasi lain |
| `403` | `bukan_sesi_anda` | sesi milik teknisi lain |
| `429` | `batas_per_lembar` | jatah panggilan per sesi habis (dipakai BERSAMA dengan `POST /ocr/halaman` dan 22A) |
| `429` | `gagal_berulang_per_lembar` | Google gagal berulang untuk sesi itu; **berhenti memanggil untuk lembar itu** |
| `429` | `batas_sel_per_lembar` | hitungan sel unggah habis (per sesi, atau per akun per hari tanpa sesi) |
| `429` | `terlalu_sering` | limiter 4 permintaan per menit per akun |
| `502` | `ocr_cloud_gagal` | Google gagal, waktu habis, jaringan putus, atau galat server |

## Yang harus dilakukan aplikasi Lab (belum dikerjakan)

1. Sakelar klien bawaan MATI (`--dart-define`, nama final menyusul). Server punya tiga gerbang sendiri; klien menyala belum
   berarti server menjawab. Baca `fitur_nonaktif` di `GET /api/me/permissions`: bila memuat `ocr_cloud_sel` atau `pindai_lembar`,
   sembunyikan tombolnya.
2. Potong HANYA sel bertipe angka (baris `angka` di templat; bukan `jam`, `isian`, `centang`). Pilih sel yang masih perlu
   dicek (kosong atau ragu), bukan semua sel.
3. Satu permintaan memuat banyak sel (maks 20 sementara ini; server memecah per 16 gambar ke Google). Jangan memanggil per sel
   dalam satu putaran.
4. Tampilkan `teks_awan` dan `teks_normal` berdampingan bila `dinormalkan`, status sebagai kuning/merah/kosong, dan tandai
   "usulan awan". Konfirmasi manusia wajib sebelum nilai dipakai.
5. Tangani semua galat di atas dengan jatuh ke jalur lama. Jangan mengulang otomatis pada `429`/`502`.
6. Batas yang perlu diketahui: jatah per sesi 5 panggilan (`GOOGLE_VISION_MAKS_PER_SESI`, satu panggilan = hingga 16 sel,
   dipakai bersama dengan `POST /ocr/halaman`); hitungan sel unggah 160 per sesi atau 240 per akun per hari tanpa sesi
   (hitungan lunak, bisa hilang saat cache dibersihkan); limiter 4 permintaan per menit per akun, terpisah dari limiter jalur
   22A. Sertakan `calibration_session_id` bila ada, supaya jatah per lembar berlaku.
7. Jangan menyimpan potongan di HP lebih lama dari yang dibutuhkan untuk unggah, dan jangan menulis potongan ke log klien.

## Belum dikerjakan / belum terbukti

- **Akurasi OCR awan pada tulisan tangan nyata BELUM terukur.** Tes memakai Google palsu (`Http::fake`); tidak ada panggilan
  nyata dan tidak ada foto uji berlabel manusia. Jangan menyebut angka akurasi.
- **Biaya:** asumsi 1 gambar = 1 unit tagihan belum dicocokkan dengan laporan Google Cloud (`SUM(unit_vision)`).
- Kualitas potongan sel kecil hasil crop di HP (resolusi, margin, kompresi) belum diuji pada foto nyata; batas 300 KB dan
  500.000 piksel adalah tebakan awal.
- `max_file_uploads` PHP (bawaan 20) lebih kecil dari batas aplikasi 48 sel; menaikkannya butuh perubahan `Dockerfile`
  (keputusan pemilik).
- Penyalaan di produksi menunggu pemilik: butir 4 `AGENTS.md` §OCR awan (kontrak pelanggan, ISO/IEC 17025 bagian 4.2).
  Sel identitas tidak pernah dikirim, tetapi potongan angka tabel tetap meninggalkan server dan sampai ke Google.
