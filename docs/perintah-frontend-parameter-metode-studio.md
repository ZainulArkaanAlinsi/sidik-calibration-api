# Perintah frontend: penetapan keputusan metode dari Sidik Studio Web

Status: **API SELESAI (Studio Tahap 1 sisi API, 25 Nov 2026)**. Berkas ini adalah kontrak final
untuk Sidik Studio Web. Ia menggantikan usulan di `docs/perintah-frontend-parameter-metode.md`
(usulan itu memakai endpoint `usulan/simulasi/sahkan/tolak` yang TIDAK dibangun; jangan dipakai).

Keputusan yang mendasarinya (D1-D8): `C:\Users\USER\sidik-dokumentasi\2026-11-25\BRIEF-STUDIO-TAHAP-1.md`.
Latar registri dan alasan setiap parameter: `docs/parameter-keputusan-metode.md`.

## Yang berubah untuk pengguna

Keputusan metode Lembar Centang (mis. "u(perbedaan temperatur) Refractometer dari suhu ruang atau dari
sel tetap 21,0 C") sebelumnya hanya bisa ditetapkan lewat PR kode ke berkas registri. Sekarang TM
mencatat jawabannya dari Studio, lalu menyetujuinya. Tidak ada deploy.

**Yang menentukan angka hanya penetapan berstatus `disetujui`.** Mencatat (`menunggu_tm`) dan menarik
(`ditarik`) tidak pernah menggeser satu angka pun. Aplikasi mobile tidak berubah dan tidak perlu rilis
baru (D5): ia menerima hasil hitung dari server seperti biasa.

## Aturan penting untuk tampilan

1. **Hanya sesi BARU yang terpengaruh.** Penetapan `disetujui` dengan `berlaku_mulai = 2026-12-01`
   dipakai sesi yang `tanggal_kalibrasi`-nya 2026-12-01 atau sesudahnya. Sesi bertanggal lebih awal tetap
   memakai penetapan sebelumnya, atau bawaan kode bila belum ada. Tampilkan itu di layar konfirmasi.
2. **Tidak ada berlaku surut.** `berlaku_mulai` tidak boleh sebelum tanggal server. Sertifikat yang sudah
   terbit tidak bergeser. Pesan 422 `berlaku_surut` sudah menjelaskannya; tampilkan apa adanya.
3. **Tanggal server = `now()` aplikasi** dalam zona waktu `APP_TIMEZONE` (bawaan UTC). Dekat tengah malam
   WIB tanggal server bisa satu hari di belakang jam dinding pengguna. Jangan menolak tanggal di klien
   dengan jam dinding lokal; biarkan server yang memutuskan dan tampilkan `rincian.tanggal_server`.
4. **Append-only.** Penetapan tidak pernah diubah isinya atau dihapus. Salah ketik: tarik lalu catat baru.
5. **`berlaku_mulai` tidak boleh turun menurut nomor.** Penetapan nomor 3 tidak boleh berlaku lebih awal
   dari nomor 2 yang masih aktif (belum ditarik). Menarik nomor 2 membuka jalan.
6. Menarik penetapan yang SUDAH `disetujui` dan sudah berlaku adalah aksi berisiko: hitung ulang sesi
   bertanggal sesudahnya kembali ke pilihan sebelumnya dan validator melaporkan `hitung_ulang_beda` untuk
   sesi yang dulu dihitung dengan penetapan itu. Respons `tarik` membawa `peringatan` untuk kasus ini;
   tampilkan sebagai konfirmasi sebelum tombol Tarik (dan sebagai pesan sesudahnya).

## Akses, header, dan batas

- Semua rute di bawah `/api/studio/...`, grup Studio: `auth:sanctum` plus gerbang `studio`. Hanya token
  Studio dari akun Studio tunggal (`STUDIO_AKUN`). Token lab dan token akun lain ditolak. Rute ini TIDAK
  ditambahkan ke grup lapisan 1 (D4).
- Header wajib seperti rute Studio lain: `Authorization: Bearer <token studio>`, `Accept: application/json`,
  `X-Klien: sidik-studio`, `X-Versi-Klien: <versi build>`. Versi di bawah minimum server dijawab 426
  `klien_kedaluwarsa`.
- Jatah: `GET` ikut ember `studio-baca` (300/menit per pengguna). Tiga `POST` memakai ember
  `studio-parameter-tulis`: **20 per menit per pengguna**. Lewat batas: 429 `{"message": "Kebanyakan percobaan. Tunggu sebentar, terus coba lagi."}`
  dengan header `Retry-After`.

Galat akses (403, bentuk `{kode, message}`):

| `kode` | Arti |
|---|---|
| `studio_tertutup` | `STUDIO_AKUN` kosong di server: Studio tertutup total |
| `bukan_akun_studio` | Pengguna bukan akun Studio |
| `bukan_token_studio` | Token tidak memuat ability `studio` (mis. token lab) |

Tanpa token: 401. Akun nonaktif: 401 `akun_nonaktif`.

## Daftar: `GET /api/studio/parameter-metode`

Satu elemen per parameter registri (saat ini 7), beserta SEMUA penetapannya dalam semua status.

```json
{
  "data": [
    {
      "kunci": "spectrophotometer.max_stdev_holmium",
      "alat": "spectrophotometer",
      "nama": "Batas maksimum stdev Holmium Spektrofotometer",
      "keterangan": "Pertanyaan TM dalam bahasa lab ...",
      "lingkup": "hitung",
      "butir_tm": ["spectrophotometer#7"],
      "dampak": "Teks dampak angka bila pilihan berganti ...",
      "pilihan": [
        {
          "kode": "semua_titik",
          "label": "Semua titik",
          "keterangan": "Perilaku persisnya ...",
          "setara_master": "tidak",
          "bukti_singkat": "kode: ..."
        },
        {
          "kode": "tanpa_titik_pertama",
          "label": "Tanpa titik pertama, seperti workbook",
          "keterangan": "Perilaku persisnya ...",
          "setara_master": "ya",
          "bukti_singkat": "workbook: ..."
        }
      ],
      "bawaan": "semua_titik",
      "aktif_sekarang": "semua_titik",
      "penetapan": [
        {
          "id": 12,
          "nomor": 1,
          "nilai": "tanpa_titik_pertama",
          "berlaku_mulai": "2026-12-01",
          "dicatat_pada": "2026-11-25T09:12:03+00:00",
          "status": "disetujui",
          "keputusan_oleh": "Nama TM",
          "tanggal_keputusan": "2026-11-25",
          "catatan": "Jawaban TM butir 7.",
          "sumber": "db",
          "jejak": [
            {"dari_status": null, "ke_status": "menunggu_tm", "oleh": "studio@contoh.id", "pada": "2026-11-25T09:12:03+00:00", "catatan": "Jawaban TM butir 7."},
            {"dari_status": "menunggu_tm", "ke_status": "disetujui", "oleh": "studio@contoh.id", "pada": "2026-11-25T09:20:41+00:00", "catatan": "Diputuskan oleh Nama TM, tanggal keputusan 2026-11-25."}
          ]
        }
      ]
    }
  ]
}
```

Catatan field:

- `aktif_sekarang`: pilihan yang berlaku untuk sesi bertanggal HARI INI (tanggal server). Bukan untuk
  sesi lama atau sesi bertanggal depan: tampilkan sebagai "berlaku hari ini", bukan "berlaku selalu".
- `lingkup`: `hitung` (menggeser angka hasil) atau `cetak` (hanya tanggal pada sertifikat).
- `penetapan` urut menurut `nomor`. `status` salah satu `menunggu_tm`, `disetujui`, `ditarik`.
- `sumber`: `db` (dicatat lewat Studio; punya `id` dan `jejak`) atau `berkas` (entri bersejarah di berkas
  registri, `id: null`, `jejak: []`, tidak bisa ditarik lewat API). Saat ini semua parameter tidak punya
  entri `berkas`, tetapi klien harus siap menerimanya dan menyembunyikan tombol aksi.
- `dicatat_pada` dan `jejak[].pada`: ISO 8601 dari jam server (klien tidak mengirimnya).
- `jejak[].oleh`: email akun Studio. Bisa `null` bila akunnya sudah dihapus.

## Catat: `POST /api/studio/parameter-metode/{kunci}/penetapan`

`{kunci}` adalah `kunci` dari daftar (huruf kecil, angka, `_`, `.`). Kunci tidak dikenal: 404.

```json
{
  "nilai": "tanpa_titik_pertama",
  "berlaku_mulai": "2026-12-01",
  "catatan": "Jawaban TM butir 7, 25 Nov 2026."
}
```

| Field | Aturan |
|---|---|
| `nilai` | wajib, salah satu `pilihan[].kode` parameter ini |
| `berlaku_mulai` | wajib, `Y-m-d`, `>=` tanggal server, tidak lebih awal dari penetapan aktif sebelumnya |
| `catatan` | opsional, maks 2000 karakter (disarankan: sumber jawaban TM) |

Sukses: **201**, status penetapan `menunggu_tm`, belum memengaruhi angka.

```json
{
  "message": "Penetapan dicatat, menunggu keputusan TM. Belum memengaruhi angka.",
  "data": { "id": 12, "nomor": 1, "nilai": "tanpa_titik_pertama", "berlaku_mulai": "2026-12-01",
            "dicatat_pada": "2026-11-25T09:12:03+00:00", "status": "menunggu_tm",
            "keputusan_oleh": null, "tanggal_keputusan": null, "catatan": "Jawaban TM butir 7, 25 Nov 2026.",
            "sumber": "db",
            "jejak": [{"dari_status": null, "ke_status": "menunggu_tm", "oleh": "studio@contoh.id",
                       "pada": "2026-11-25T09:12:03+00:00", "catatan": "Jawaban TM butir 7, 25 Nov 2026."}] }
}
```

Nomor diberikan server (urut per parameter, tidak bisa dikirim klien). Dua pencatatan serentak untuk
parameter yang sama: yang kedua mendapat 409 `konflik_nomor` (termasuk bila database membatalkan
salah satunya karena deadlock); tidak ada yang tersimpan dari yang kalah, jadi muat ulang daftar lalu ulangi.

## Setujui: `POST /api/studio/parameter-metode/penetapan/{id}/setujui`

`{id}` adalah `penetapan[].id` (bilangan bulat). Id tidak ada: 404. Hanya dari `menunggu_tm`.

```json
{
  "keputusan_oleh": "Nama TM",
  "tanggal_keputusan": "2026-11-25"
}
```

| Field | Aturan |
|---|---|
| `keputusan_oleh` | wajib, 3-150 karakter: siapa TM yang memutuskan (bukan akun Studio) |
| `tanggal_keputusan` | wajib, `Y-m-d`, tidak boleh sesudah tanggal server |

Sukses **200**:

```json
{
  "message": "Penetapan disetujui. Sesi BARU bertanggal kalibrasi 2026-12-01 atau sesudahnya memakai pilihan ini.",
  "data": { "id": 12, "nomor": 1, "status": "disetujui", "keputusan_oleh": "Nama TM",
            "tanggal_keputusan": "2026-11-25", "...": "bentuk baris sama seperti di daftar" }
}
```

Penyetujuan memeriksa `berlaku_mulai` LAGI terhadap tanggal server. Penetapan yang dicatat kemarin untuk
"hari ini" lalu baru disetujui besok ditolak `berlaku_surut`: tarik lalu catat ulang.

## Tarik: `POST /api/studio/parameter-metode/penetapan/{id}/tarik`

Dari `menunggu_tm` atau `disetujui`. Menarik yang sudah `ditarik`: 422 `status_tidak_sesuai`.

```json
{ "alasan": "Salah ketik tanggal berlaku, dicatat ulang." }
```

`alasan` wajib, 10-2000 karakter. Sukses **200**:

```json
{
  "message": "Penetapan ditarik.",
  "peringatan": null,
  "data": { "id": 12, "status": "ditarik", "...": "bentuk baris sama" }
}
```

`peringatan` berisi teks (bukan `null`) bila yang ditarik berstatus `disetujui` dan `berlaku_mulai`-nya
sudah tercapai: hasil tersimpan tidak berubah, tetapi hitung ulang sesi bertanggal sesudahnya kembali ke
penetapan sebelumnya atau bawaan kode. Tampilkan terlihat jelas.

## Galat

Validasi bentuk (field kosong, format tanggal salah, panjang): **422** bentuk Laravel
`{"message": "...", "errors": {"field": ["..."]}}`.

Aturan domain: **422** `{"message": "...", "kode": "...", "errors": {"field": ["..."]}, "rincian": {...}}`
(`errors` dan `rincian` hanya ada bila relevan; `errors` memakai bentuk sama dengan validasi supaya layar
yang sudah menampilkan galat per kolom tidak butuh cabang baru).

| `kode` | Status | Muncul di | `errors` pada | `rincian` |
|---|---|---|---|---|
| `nilai_bukan_pilihan` | 422 | catat | `nilai` | `{pilihan: [kode, ...]}` |
| `tanggal_tidak_sah` | 422 | catat, setujui | `berlaku_mulai` / `tanggal_keputusan` | - |
| `berlaku_surut` | 422 | catat, setujui | `berlaku_mulai` | `{tanggal_server}` (+ `berlaku_mulai` saat setujui) |
| `berlaku_mulai_turun` | 422 | catat | `berlaku_mulai` | `{nomor_sebelumnya, berlaku_mulai_sebelumnya}` |
| `tanggal_keputusan_di_depan` | 422 | setujui | `tanggal_keputusan` | `{tanggal_server}` |
| `status_tidak_sesuai` | 422 | setujui, tarik | - | `{status}` status sekarang |
| `konflik_nomor` | **409** | catat | - | - |

404 (`{"message": ...}` bentuk Laravel) untuk kunci tidak dikenal atau id tidak ada.

## Pemakaian di layar (saran, bukan kewajiban)

1. Muat `GET` sekali per layar; tampilkan satu kartu per parameter: `nama`, `keterangan`, `pilihan`
   (`label`, `setara_master`, `bukti_singkat`), `dampak`, `aktif_sekarang`, dan tabel `penetapan` dengan `jejak`.
2. Bentuk "Catat jawaban TM": pilih `nilai`, isi `berlaku_mulai` (bawaan: hari ini), `catatan`.
3. Baris `menunggu_tm` menampilkan tombol Setujui dan Tarik; baris `disetujui` hanya Tarik (dengan
   konfirmasi); baris `ditarik` dan `sumber: "berkas"` tanpa tombol.
4. Sesudah setiap aksi, ganti baris dari `data` respons atau muat ulang `GET`.
5. Jangan menghitung ulang aturan D3 di klien sebagai sumber kebenaran; server yang memutuskan. Pemeriksaan
   klien hanya untuk kenyamanan.

## CORS (D6)

API sebelumnya mengizinkan semua origin (bawaan framework `*`). Sekarang `config/cors.php` hanya
mengizinkan `http://localhost:5173` dan `http://127.0.0.1:5173` (dev server Vite) ditambah origin produksi
dari env `CORS_ALLOWED_ORIGINS` (daftar dipisah koma, tanpa garis miring akhir; `*` dibuang). Token lewat
header `Authorization`, tanpa cookie, jadi `supports_credentials` tetap `false`. Header yang boleh dibaca
klien: `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`. Dijaga `tests/Feature/CorsStudioTest.php`.

**Serah terima ke pemilik (wajib sebelum Studio produksi):**

- Isi `CORS_ALLOWED_ORIGINS` di dashboard Render dengan origin Studio produksi, mis. `https://<domain studio>`.
  Tanpa itu, Studio di produksi gagal di preflight CORS.
- Kunci itu BELUM ada di `render.yaml` dan `.env.example` (pekerjaan ini dilarang menyentuh keduanya).
  Sesuai AGENTS.md butir 9, daftarkan di kedua berkas itu; kalau tidak, sinkron blueprint bisa menimpa env
  dashboard secara diam-diam.
- Aplikasi mobile dan panel Filament tidak melewati CORS, jadi tidak terpengaruh.

## Verifikasi

Dijaga tes: `tests/Feature/StudioParameterMetodeApiTest.php` (akses, kontrak, aturan D3, 422),
`tests/Unit/ParameterKeputusanMetodePenetapanDbTest.php` (resolusi tanggal, status, cache),
`tests/Feature/ParameterMetodePenetapanStudioSesiTest.php` (sesi bertanggal sebelum dan sesudah
`berlaku_mulai` lewat `POST /api/calibrations`), `tests/Feature/CorsStudioTest.php`, dan golden
`tests/Fixtures/parameter-metode-golden-bawaan.json` (tidak diubah: angka bawaan identik).
