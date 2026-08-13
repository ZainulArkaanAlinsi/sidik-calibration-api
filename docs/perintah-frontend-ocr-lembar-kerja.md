# Perintah Frontend — Pindai Lembar Kerja (OCR lokal)

Tempel bagian di bawah garis ini ke sesi kerja frontend/mobile. Sudah lengkap; tidak perlu menjelaskan ulang konteksnya.

---

Bertindak sebagai Mobile Engineer (Flutter) untuk aplikasi kalibrasi PT Sidik. Backend jalur **pindai lembar kerja** sudah selesai dan sudah lulus test di SQLite maupun MySQL. Tugasmu membangun sisi perangkat: kamera, OCR di HP, dan layar review.

Kontrak lengkapnya ada di `docs/SPEC-ocr-template-lokal.md` di repo API — **baca itu lebih dulu dan perlakukan sebagai satu-satunya sumber kebenaran.** Dokumen ini hanya ringkasan kerjanya.

## ATURAN PALING PENTING

1. **Foto tidak boleh keluar dari perangkat ke pihak ketiga.** OCR jalan di HP (ML Kit on-device). Dilarang menambahkan Gemini, OpenAI, Google Cloud Vision, atau SDK OCR apa pun yang mengirim citra ke server orang lain. Tidak boleh ada biaya per foto. Kalau sebuah paket butuh API key ke layanan luar, paket itu salah — laporkan, jangan pasang.

2. **Yang dikirim ke server bukan foto, tapi hasil bacanya.** Teks per sel, angka confidence, kotak koordinat, dan skor mutu foto. Citra ikut naik hanya sebagai lampiran audit dan boleh gagal naik tanpa membatalkan hasil pindai.

3. **Frontend tidak menghitung apa pun.** Tidak ada rata-rata, tidak ada koreksi, tidak ada pembulatan yang mengubah nilai, dan **tidak ada tebakan koma**. Kalau OCR membaca `133659`, kirim `133659` apa adanya. Server yang memutuskan itu angka valid atau bukan.

4. **Jangan hardcode bentuk lembar kerja.** Jumlah titik ukur, jumlah kolom pengulangan, nama kolom, satuan, jumlah desimal, jumlah tabel — semuanya datang dari `GET /api/worksheet-templates/{kode}`. Alat akan bertambah sampai 48 jenis; layar yang menebak bentuknya akan pecah di alat berikutnya.

5. **Hasil pindai itu usulan, bukan submit.** Menyimpan hasil kalibrasi tetap lewat `POST`/`PUT /api/calibrations` seperti sekarang. Endpoint pindai tidak pernah membuat `raw_measurements`.

6. Kalau ada yang ambigu antara kontrak dan kenyataan respons API, **berhenti dan tanya**. Jangan menambal dengan asumsi.

## PRASYARAT YANG BELUM TERPENUHI — baca sebelum mulai

Semua berkas geometri di server masih `terverifikasi: false`, jadi **`siap_pindai` bernilai `false` untuk keenam alat** dan setiap kiriman pindai akan ditolak. Ini disengaja: koordinat sel belum diukur dari lembar cetak asli, dan koordinat tebakan berarti angka mendarat di sel yang salah.

Konsekuensi buat kamu: bangun layarnya, tapi **hormati `siap_pindai`**. Kalau `false`, tombol "Pindai" nonaktif dan tampilkan `alasan_belum_siap` apa adanya. Jangan buka kamera "biar bisa dites dulu" — hasilnya cuma bikin teknisi percaya fitur yang belum boleh dipakai.

Prasyarat kedua ada di sisi lab, bukan di kode: lembar kerja harus dicetak ulang dari `php artisan ocr:cetak-lembar {kode}`. Cuma lembar itu yang punya **4 marker sudut + QR versi**, dan tanpa keduanya penguncian baris/kolom tidak bisa dijamin. Formulir lama hasil fotokopi tidak bisa dipakai.

### Bentuk markernya — jangan pasang OpenCV

Markernya **bukan ArUco**, dan itu disengaja. Yang tercetak di tiap sudut cuma **kotak hitam pejal 90 px (±11,4 mm @200 dpi) dengan kotak putih di tengahnya**. Titik yang kamu kirim di `geometri.marker[].x/y` adalah **titik pusat** kotak itu, bukan pojoknya — sama dengan yang tertulis di berkas geometri server.

Konsekuensinya buat aplikasi:

- Deteksinya cukup **ambang gelap + titik berat gumpalan** di tiap kuadran sudut foto. Itu bisa Dart murni di atas bytes kamera; **tidak perlu OpenCV, tidak perlu kamus/dictionary ArUco, tidak perlu paket native tambahan**.
- Kotak putih di tengah ada supaya gumpalannya tidak menyatu dengan garis tabel saat fotonya agak gelap. Pakai itu sebagai penyaring: gumpalan sudut yang benar punya lubang terang di tengah.
- `id` 0..3 = kiri-atas, kanan-atas, kanan-bawah, kiri-bawah. Urutannya **kamu tentukan dari posisi gumpalan di foto**, bukan dari id yang tercetak — markernya memang tidak menyimpan id apa pun.
- QR-nya beda urusan: baca dengan pemindai barcode ML Kit yang sudah on-device, isinya `{template_id}|v{versi}`.

## ALUR LAYARNYA

1. Teknisi buka sesi kalibrasi → tombol **Pindai Lembar Kerja**.
2. Ambil template: `GET /api/worksheet-templates/{kode}?equipment_id=...&jumlah_pengulangan=...`.
3. Kamera terbuka dengan bingkai bantu. Deteksi 4 marker + QR secara langsung; tombol jepret baru aktif saat 4 marker terlihat dan mutu foto lolos ambang.
4. Setelah jepret: warp perspektif → potong per sel dari koordinat template → OCR tiap potongan → kirim `POST /api/worksheet-scans`.
5. Layar review: tabel yang bentuknya persis lembar kertas, tiap sel diwarnai sesuai vonis server. Teknisi memeriksa sel kuning, membetulkan yang salah, lalu **Pakai Angka Ini** → nilainya masuk ke form kalibrasi biasa.

## KONTRAK API

Semua endpoint butuh Sanctum token, peran `admin` atau `teknisi`, dan hanya melayani organisasi milik pemakainya. Teknisi hanya boleh memindai sesi yang dia kerjakan sendiri.

### 1. `GET /api/worksheet-templates`

Daftar lembar kerja yang dikenal sistem. Untuk layar "pilih lembar kerja".

```json
{
  "data": [
    {
      "template_id": "ph_meter",
      "versi": 1,
      "kode_dokumen": "SIDIK-FM-CAL-0509_Rev.4",
      "judul": "Calibration Worksheet - pH Meter",
      "jumlah_sel": 60,
      "siap_pindai": false,
      "alasan_belum_siap": "geometri_belum_diverifikasi"
    }
  ]
}
```

### 2. `GET /api/worksheet-templates/{kode}`

Query opsional: `equipment_id` (bentuk lembar bisa ikut alat pelanggan — Conductivity memilih µS/cm vs mS/cm dari resolusi alatnya) dan `jumlah_pengulangan` (2–10).

Isi yang wajib kamu pakai:

| Field | Untuk apa |
|---|---|
| `tabel[].tabel_id` | identitas tabel. **Bukan** `tahap` — Spectrophotometer punya tiga tabel dengan `tahap` sama, dibedakan `grup` |
| `tabel[].baris[]` | `baris_ke`, `titik_ukur`, `label`, `standard_id`, `satuan`, `resolusi`, `desimal` |
| `tabel[].kolom[]` | `field_id`, `label`, `satuan` — judul kolom di layar |
| `tabel[].pengulangan` | daftar nomor Repeat, mis. `[1,2,3,4,5]` |
| `sel` | peta koordinat per sel, kunci → `{x, y, w, h}` di ruang `ukuran_referensi` |
| `geometri.marker`, `geometri.qr` | posisi marker & QR untuk penyelarasan. `marker[].x/y` = titik pusat kotak, `ukuran` = sisi kotak dalam piksel |
| `jangkar` | teks yang harus ikut kebaca sebagai bukti barisnya tidak geser |
| `siap_pindai`, `alasan_belum_siap` | penentu tombol pindai aktif atau tidak |

**Kunci sel** — ini inti keselamatan fitur ini:

```
{tabel_id}|{baris_ke}|{repeat_no}|{field_id}
contoh: sebelum_adjustment|1|1|pembacaan
```

Ambil kunci dari template apa adanya. Jangan menyusunnya sendiri dari indeks tampilan, jangan mengurutkan ulang, jangan mengarang kunci untuk sel yang tidak ada di template — kiriman dengan kunci asing membuat **satu lembar penuh ditolak**.

`titik_ukur` dan `standard_id` tetap kamu kirim per sel sebagai bukti silang, tapi keduanya **bukan** bagian kunci.

### 3. `POST /api/worksheet-scans` — kirim hasil pindai

`multipart/form-data` (karena ada lampiran citra) atau JSON kalau citra tidak dikirim.

```json
{
  "template_id": "ph_meter",
  "template_versi": 1,
  "calibration_session_id": 812,
  "equipment_id": 47,
  "jumlah_pengulangan": 5,

  "qr": { "terbaca": true, "isi": "ph_meter|v1" },

  "geometri": {
    "marker": [
      { "id": 0, "x": 118.4, "y": 132.9 },
      { "id": 1, "x": 1535.2, "y": 133.1 },
      { "id": 2, "x": 1535.6, "y": 2206.4 },
      { "id": 3, "x": 118.1, "y": 2206.0 }
    ],
    "residual_reproyeksi_px": 0.8,
    "grid_tersnap": true,
    "ukuran_referensi": { "w": 1654, "h": 2339 }
  },

  "kualitas": {
    "blur_laplacian": 168.2,
    "kecerahan_rata": 142.0,
    "rasio_glare": 0.01,
    "sudut_kemiringan_deg": 1.4,
    "px_per_sel_tinggi": 61
  },

  "perangkat": { "model": "Redmi Note 12", "os": "Android 14", "app": "1.8.0", "ocr": "mlkit-2.0" },
  "diambil_pada": "2026-08-13T09:41:00+07:00",

  "sel": [
    {
      "tabel_id": "sebelum_adjustment",
      "baris_ke": 1,
      "repeat_no": 1,
      "field_id": "pembacaan",
      "teks_mentah": "4,01",
      "confidence_ocr": 0.93,
      "kotak_teks_di_dalam_sel": true,
      "kotak": { "x": 413, "y": 940, "w": 112, "h": 150 },
      "titik_ukur": 4,
      "standard_id": 9,
      "sumber": "mlkit"
    }
  ],

  "sel_jangkar": [
    { "field_id": "label_repeat", "repeat_no": 1, "teks_mentah": "1", "cocok": true }
  ],

  "citra": "<file jpg/png/webp, maks 8 MB>",
  "citra_warp": "<file jpg/png/webp, maks 8 MB>"
}
```

Aturan kiriman:

- **Kirim SEMUA sel yang ada di template**, termasuk yang kosong (`teks_mentah: null`) dan yang tidak kebaca. Sel kurang = lembar ditolak. Sel dobel = lembar ditolak.
- Urutan array `sel` bebas. Sudah diuji: payload yang dibalik urutannya menghasilkan angka yang sama persis. Yang menentukan posisi cuma kunci.
- Maksimal 600 sel per pindai, `teks_mentah` maksimal 64 karakter.
- `kotak` dalam koordinat citra **hasil warp**, bukan foto mentah — dipakai server untuk memotong ulang sel di layar review.

**Balasan 201 (diterima):**

```json
{
  "scan_id": 391,
  "status": "perlu_review",
  "template": { "id": "ph_meter", "versi": 1, "kode_dokumen": "SIDIK-FM-CAL-0509_Rev.4" },
  "pipeline_versi": "ocr-1.0.0",
  "aturan_versi": "val-1.0.0",
  "ringkasan": { "total_sel": 60, "hijau": 0, "kuning": 58, "merah": 1, "kosong": 1 },
  "tabel": [
    {
      "tabel_id": "sebelum_adjustment",
      "tahap": "sebelum_adjustment",
      "grup": null,
      "judul": "Before adjustment Reading",
      "baris": [
        {
          "baris_ke": 1,
          "titik_ukur": 4,
          "label": "4,00",
          "standard_id": 9,
          "satuan": null,
          "resolusi": null,
          "desimal": null,
          "pengulangan": [
            {
              "repeat_no": 1,
              "kolom": {
                "pembacaan": {
                  "kunci": "sebelum_adjustment|1|1|pembacaan",
                  "teks_mentah": "4,01",
                  "teks_normal": "4,01",
                  "nilai": 4.01,
                  "status": "kuning",
                  "alasan": [],
                  "normalisasi": [],
                  "confidence_ocr": 0.93,
                  "confidence_akhir": 0.83,
                  "kotak": { "x": 413, "y": 940, "w": 112, "h": 150 }
                }
              }
            }
          ]
        }
      ]
    }
  ],
  "boleh_auto_isi": false,
  "wajib_dicek": true,
  "status_sel": { "hijau": "...", "kuning": "...", "merah": "...", "kosong": "..." }
}
```

`tabel[].baris[].pengulangan[].kolom[field_id]` bisa `null` kalau selnya tidak ada di lembar itu. Render sebagai sel kosong, bukan error.

`satuan`, `resolusi`, dan `desimal` per baris bisa `null` kalau `equipment_id` tidak dikirim — nilainya lahir dari resolusi alat pelanggan. Kirim `equipment_id` kalau sesinya sudah punya alat, dan jangan mengisi nilai bawaan sendiri saat ketiganya `null`.

**Balasan 422 (ditolak):**

```json
{
  "scan_id": 392,
  "status": "ditolak_kualitas",
  "message": "Fotonya buram. Tahan HP lebih diam, lalu foto ulang.",
  "fallback_manual": true
}
```

**Satu lembar ditolak berarti tidak ada satu angka pun yang dipakai.** Dilarang mengisi sebagian dari hasil yang 422 — separuh benar di posisi yang salah lebih berbahaya daripada gagal terang-terangan. Tampilkan `message` apa adanya (kalimatnya sudah ditulis untuk teknisi, bukan untuk programmer) dan sediakan tombol ke jalur ketik manual, karena `fallback_manual: true` artinya teknisi tidak boleh buntu di situ.

Status gagal yang mungkin muncul:

| `status` | Artinya | Yang layar lakukan |
|---|---|---|
| `ditolak_kualitas` | foto buram / gelap / silau / miring / kejauhan | tawarkan foto ulang, tampilkan pesannya |
| `geometri_meragukan` | marker kurang, QR tak terbaca, versi lembar beda, atau grid meleset | tawarkan foto ulang; kalau versi lembar beda, jangan tawarkan ulang — lembarnya memang salah |
| `mapping_gagal` | kunci asing/dobel/kurang, atau `titik_ukur` tidak cocok | **bug di aplikasi, bukan salah teknisi** — laporkan, jangan suruh foto ulang |
| `template_tidak_dikenali` | `template_id` tidak dikenal server | bug di aplikasi |

### 4. `GET /api/worksheet-scans/{scan}`

Hasil pindai yang sudah tersimpan, bentuknya sama dengan balasan 201. Untuk layar review yang dibuka lagi.

### 5. `GET /api/worksheet-scans/{scan}/sel/{kunci}/crop`

Potongan citra sel aslinya, `image/jpeg`. Ini yang membuat layar review layak dipakai: teknisi membandingkan angka bacaan mesin dengan coretan aslinya di layar yang sama, tanpa membuka kertasnya lagi.

Muat potongan ini untuk **setiap sel kuning dan merah** — kalau harus buka kertas, teknisi akan memilih mengetik dari awal. Balasannya `Cache-Control: private` dan berisi data pelanggan: boleh di-cache di memori selama layar hidup, **jangan** ditulis ke penyimpanan bersama, galeri, atau folder yang ikut ter-backup ke cloud.

`{kunci}` harus di-URL-encode (`|` jadi `%7C`).

### 6. `POST /api/worksheet-scans/{scan}/koreksi`

Dipanggil saat teknisi menekan **Pakai Angka Ini**.

```json
{
  "koreksi": [
    { "kunci": "sebelum_adjustment|1|1|pembacaan", "nilai_final": 4.01 },
    { "kunci": "sebelum_adjustment|1|2|pembacaan", "nilai_final": 4.02 },
    { "kunci": "sebelum_adjustment|2|1|pembacaan", "nilai_final": null }
  ]
}
```

Kirim **semua** sel, termasuk yang bacaannya sudah benar dan tidak diubah teknisi. Ini satu-satunya sumber data akurasi sistem: sel yang dilewat berarti tidak ada bukti bacaannya benar, dan sel hijau yang salah tidak akan pernah ketahuan. `nilai_final: null` untuk sel yang memang kosong di kertasnya.

Balasan: `{ "data": { "scan_id", "tercatat", "kunci_tidak_dikenal", "cocok", "meleset" } }`.

## PIPELINE DI PERANGKAT

Urutannya wajib begini — kalau OCR dijalankan sebelum warp, atau seluruh halaman dibaca sekaligus lalu dicocokkan ke kolom, angka akan pindah baris dan itu persis kegagalan yang harus dicegah fitur ini.

1. **CameraX** — resolusi tertinggi yang wajar, fokus terkunci, flash mati (flash bikin silau di kertas mengkilap).
2. **Deteksi 4 marker sudut + QR** secara langsung sebelum jepret — ambang gelap + titik berat gumpalan per kuadran (Dart murni, tanpa OpenCV; lihat "Bentuk markernya" di atas), QR pakai ML Kit. Tombol jepret nonaktif sampai keempatnya terlihat.
3. **Gerbang mutu** — hitung di HP dan cegah kirim kalau tidak lolos, supaya teknisi tidak menunggu server hanya untuk ditolak. Ambang server (kirim nilainya apa adanya, jangan dibulatkan):

   | Ukuran | Ambang server |
   |---|---|
   | `blur_laplacian` | minimal 90 |
   | `kecerahan_rata` | 60–225 |
   | `rasio_glare` | maksimal 0.05 |
   | `sudut_kemiringan_deg` | maksimal ±8 |
   | `px_per_sel_tinggi` | minimal 24 |

4. **Warp perspektif** ke `ukuran_referensi` template (mis. 1654×2339). Simpan hasil warp — ini yang dilampirkan sebagai `citra_warp`.
5. **Potong per sel** dari koordinat `sel` di template, beri margin kecil, lalu **OCR tiap potongan satu per satu** dengan ML Kit. Satu potongan = satu sel = satu kunci. Tidak ada pencocokan teks halaman ke kolom.
6. **Baca jangkar** (nomor Repeat, label titik) dan kirim hasil cocok/tidaknya di `sel_jangkar`.
7. Kirim. Citra boleh menyusul/gagal; hasil bacanya tidak.

## ATURAN LAYAR REVIEW

- **Server yang memutuskan tombol simpan boleh aktif atau tidak.** Pakai `boleh_auto_isi` dan `wajib_dicek` apa adanya. Jangan menghitung ulang dari jumlah sel merah di sisi HP — aturannya harus sama di semua versi APK.
- Warna sel ikut `status`: hijau = terisi otomatis, kuning = terisi tapi wajib dilihat teknisi, merah = kosongkan dan biarkan teknisi mengetik, kosong = memang kosong di kertasnya.
- **Hampir semua sel akan kuning, dan itu benar.** Tulisan tangan tidak pernah dinaikkan ke hijau, karena model OCR tetap percaya diri saat salah membaca coretan tangan. Jangan menyembunyikan sel kuning, jangan menyediakan tombol "terima semua" yang melewati pemeriksaan.
- Tampilkan `alasan` sel dalam bahasa manusia, jangan kode mentahnya. Daftar lengkapnya:

  | Kode | Kalimat untuk teknisi |
  |---|---|
  | `teks_meluber_dari_sel` | tulisannya keluar dari kotak, bisa jadi kebaca dari kolom sebelah |
  | `di_luar_rentang` | angkanya di luar rentang wajar titik ini |
  | `magnitudo_meleset` | angkanya jauh dari nilai standar titik ini |
  | `ada_koreksi_karakter` | ada huruf yang ditebak jadi angka |
  | `desimal_kebanyakan` | desimalnya lebih banyak dari resolusi alat |
  | `bukan_kelipatan_resolusi` | angkanya tidak mungkin keluar dari alat dengan resolusi ini |
  | `jauh_dari_repeat_lain` | angkanya jauh beda dari Repeat lain di baris ini |
  | `sebar_repeat_tidak_diuji` | Repeat yang kebaca terlalu sedikit untuk dibandingkan |
  | `terlalu_banyak_substitusi` | terlalu banyak huruf yang harus ditebak |
  | `karakter_di_luar_whitelist` | ada karakter yang bukan angka |
  | `bentuk_angka_tidak_wajar` | bentuk angkanya tidak masuk akal |
  | `digit_kebanyakan` | digitnya kebanyakan untuk kolom ini |
  | `pemisah_desimal_tidak_wajar` | letak koma/titiknya tidak wajar |
  | `desimal_ambigu` | tidak jelas titik itu koma desimal atau pemisah ribuan |
  | `minus_di_tengah` | ada tanda minus di tengah angka |
  | `minus_tidak_diizinkan` | kolom ini tidak boleh bernilai negatif |

  `normalisasi` berisi catatan perubahan yang dilakukan server, mis. `"O→0"` atau `"pemisah ribuan dibuang ..."`. Tampilkan ini di detail sel — teknisi berhak tahu angka yang dia lihat sudah ditebak-tebak sampai mana.
- Urutan Repeat di layar harus mengikuti `tabel[].pengulangan`, bukan urutan array yang datang.

## YANG DILARANG

- Mengisi form dari hasil pindai yang statusnya 422.
- Menambahkan koma/titik desimal yang tidak terbaca di kertas.
- Menyimpan foto lembar kerja ke galeri atau folder yang tersinkron ke cloud.
- Mengirim citra ke layanan OCR mana pun selain proses di dalam aplikasi.
- Menonaktifkan atau melonggarkan gerbang mutu supaya "lebih jarang gagal".
- Melewati `POST .../koreksi` karena "angkanya sudah benar semua".

## CHECKLIST SELESAI

- [ ] Tombol pindai mengikuti `siap_pindai`; `alasan_belum_siap` tampil apa adanya.
- [ ] Semua sel template terkirim, termasuk yang kosong; kunci diambil dari template.
- [ ] Urutan array `sel` diacak tetap menghasilkan angka yang sama (uji ini di perangkat).
- [ ] Payload dari lembar Spectrophotometer memakai tiga `tabel_id` berbeda, tidak dilebur jadi satu.
- [ ] Semua pesan 422 tampil apa adanya + jalur ketik manual tersedia.
- [ ] Sel kuning & merah bisa dibandingkan dengan potongan citranya di layar yang sama.
- [ ] `POST .../koreksi` mengirim seluruh sel, bukan hanya yang diubah.
- [ ] Tidak ada dependensi baru yang mengirim data ke luar perangkat.

## KALAU ADA YANG BELUM JELAS

Tanya sebelum menebak. Tiga hal yang paling sering salah dipahami dan paling mahal akibatnya: kunci sel disusun sendiri, hasil 422 dipakai sebagian, dan koma ditambahkan supaya angkanya "kelihatan wajar".
