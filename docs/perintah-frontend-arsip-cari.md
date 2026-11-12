# Perintah Frontend — Arsip & Pencarian (Aplikasi Lab dan Aplikasi Pelanggan)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja frontend
(mobile Lab `sidik-calibration-mobile` dan/atau aplikasi Pelanggan) — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feat/arsip-cari-terpadu-20261112`, commit `(belum di-commit — diisi pemilik setelah commit)` |
| Status backend | Selesai. Tes SQLite dan MySQL lokal hijau (SQLite lokal 510 tes terarah, MySQL lokal 278 tes terarah); gerbang suite MySQL penuh dijalankan pemilik sebelum rilis |
| Sifat perubahan | **ADITIF.** Tidak ada rute, field, atau urutan yang dihapus/diubah. App lama tetap jalan tanpa diubah |
| Referensi lain (opsional) | `docs/kontrak-api.md` §19, `docs/kontrak-api-pelanggan.md` §12 |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Dokumen lama tercecer karena tiga hal: pencarian hanya menebak satu kolom, Riwayat disaring di HP dan hanya
memuat 300 sesi pertama, dan arsip hanya punya folder (PT › Tahun › Bulan › Jenis) tanpa cara menelusuri
menurut waktu. Paket ini memperbaiki ketiganya **di server**. Yang dibutuhkan dari frontend: mengirim kata
cari apa adanya, memuat halaman sampai habis, dan menampilkan garis waktu arsip.

## 0. Aturan paling penting

1. **Frontend TIDAK menghitung apa pun.** Pengelompokan, nomor pekan, label (`Pekan 46 (9–15 Nov 2026)`),
   jumlah, dan peringkat hasil datang dari server. Jangan menghitung pekan ISO atau mengurutkan ulang di HP.
2. **Kirim kata cari apa adanya.** Jangan membuang `/`, `-`, spasi, atau mengubah huruf. Server menyamakan
   `CAL/2026/10/0001`, `cal-2026-10-0001`, dan `cal 2026 10 0001`. Jangan menyaring daftar di HP sebagai
   pengganti `q` — itulah yang membuat Riwayat dulu terbatas 300 sesi.
3. **Garis waktu adalah TAMPILAN.** Folder arsip fisik (PT › Tahun › Bulan › Jenis) tidak berubah, jangan
   diubah. Garis waktu hanya cara lain melihat sertifikat yang sama, menurut **tanggal terbit**.
4. **Tanggal adalah tanggal kalender polos** (`"2026-11-12"`). Jangan di-parse sebagai waktu UTC lalu
   digeser zona (di Jakarta tanggalnya jadi mundur sehari). Tampilkan apa adanya atau pecah per bagian.
5. **Semua parameter baru opsional.** Server lama (yang belum memuat paket ini) mengabaikan parameter baru;
   selalu baca `meta` dan jangan berasumsi parameter baru dihormati tanpa memeriksa bentuk jawabannya.
6. **Izin ditegakkan server.** Teknisi hanya mendapat dokumen dari sesinya sendiri, apa pun parameternya.
   Menyembunyikan tombol di app bukan kontrol akses.
7. Kalau ambigu, berhenti dan tanya — jangan menambal dengan asumsi.

## 1. Pencarian terpadu `q` — satu aturan, empat layar

Satu layanan server (`CariDokumenLab`) dipakai oleh empat rute lab. Aturannya sama di semuanya:

| Yang dicocokkan | Cara |
|---|---|
| Nomor seri alat | yang terdaftar **dan** yang diketik teknisi di lembar kerja; huruf besar/kecil tak berpengaruh |
| Nomor sertifikat | **dinormalisasi**: huruf besar/kecil, `/`, `-`, dan spasi diabaikan di kedua sisi. Revisi `-R<n>` ikut (nomor dasar adalah awalan nomor revisinya) |
| Nomor order | `orders.nomor` dan `calibration_sessions.nomor_order`, dinormalisasi seperti nomor sertifikat |
| Nomor sesi | `KAL/…`, dinormalisasi (Riwayat, Arsip) |
| Nama alat, merek, nama PT | mengandung kata cari |

Urutan hasil: **persis** dulu, lalu **awalan**, lalu **mengandung**. Yang peringkatnya sama berurut seperti
semula (lihat tiap rute). `%` dan `_` yang diketik dibaca sebagai huruf biasa. Kata cari yang hanya berisi
pemisah (`/`, `-`, spasi) tidak mencocokkan nomor apa pun. Selalu dikurung ke laboratorium pemanggil.

| Rute | Parameter | Perubahan |
|---|---|---|
| `GET /api/sertifikat/telusuri` | `q` wajib, 2–100 huruf | Dulu hanya kolom `nomor`. **Bentuk baris tidak berubah** |
| `GET /api/arsip/berkas` | `q` | Dulu hanya nama berkas (`Sertifikat-CAL-2026-10-0001.pdf`, jadi mengetik `CAL/2026/10/0001` tidak menemukan apa-apa). Kini juga nomor sertifikat/seri/order/PT dari sertifikat atau lembar kerja yang ditautkan. Nama berkas tetap dicari. Bentuk baris tidak berubah |
| `GET /api/calibrations` | `q` (≤100) | **Baru**, lihat §2 |
| `GET /api/equipments` | `search` | Seri (persis dulu), nama, merek, nama PT. Nomor sertifikat dan nomor order hanya cocok bila **persis** — potongan seperti `2026` tidak memunculkan alat (agar pemilih alat tidak penuh derau). Bentuk baris tidak berubah |

Contoh — mengetik nomor seri di Telusuri (`GET /api/sertifikat/telusuri?q=sn-0204`), sebelumnya kosong
(contoh menampilkan satu baris):

```json
{
    "data": [
        {
            "id": 5,
            "nomor": "CAL/2026/11/0004",
            "perusahaan": "PT Contoh Alfa",
            "alat": "Jangka Sorong",
            "merk": "Ohaus",
            "model": null,
            "serial_number": "SN-0204",
            "status": "terbit",
            "status_dokumen": "berlaku",
            "revisi_ke": null,
            "diterbitkan_pada": "2026-11-12",
            "berlaku_sampai": "2027-11-12",
            "tahap_paket": null,
            "pdf_url": "http://localhost/api/certificates/5/download"
        }
    ],
    "meta": {
        "total": 1,
        "per_page": 20,
        "current_page": 1,
        "last_page": 1
    }
}
```

## 2. Riwayat sesi — `GET /api/calibrations`

Parameter **baru**, semuanya opsional dan boleh digabung:

| Parameter | Isi | Catatan |
|---|---|---|
| `q` | kata cari (≤100) | aturan §1; saat diisi, peringkat mendahului urutan |
| `dari`, `sampai` | `YYYY-MM-DD` | **tanggal kalibrasi**, kedua ujung termasuk. `sampai` < `dari` → `422`. Draf tanpa tanggal kalibrasi tidak ikut bila salah satunya diisi |
| `customer_id` | id PT | termasuk sesi dari alat yang sudah dihapus |
| `per_page` | 1–50 | bawaan **15** (sama seperti dulu); `51` → `422` |
| `bentuk` | `ringan` | resource daftar ringan, lihat di bawah; selain `ringan` → `422` |

Yang **tidak** berubah: `status`, `equipment_id`, `mine`, `ringkas=1`, `page`, bentuk jawaban penuh, dan
aturan teknisi (hanya sesinya sendiri). Tanpa parameter baru, jawaban dan urutannya persis seperti sebelumnya.

**Urutan stabil.** Baris selalu berurut `id` menurun (unik), dengan peringkat di depan bila `q` diisi.
Memuat halaman demi halaman tidak menggandakan dan tidak melewatkan baris — yang menghapus alasan batas
"20 halaman × 15 = 300 sesi". Cara memuat semuanya:

```
halaman = 1
ulangi:
    jawab = GET /api/calibrations?bentuk=ringan&per_page=50&page=<halaman>[&q=…&dari=…&sampai=…]
    tambahkan jawab.data
    berhenti bila halaman >= jawab.meta.last_page
    halaman += 1
```

Lebih baik lagi: **jangan memuat semuanya** — kirim `q`, `dari`, `sampai`, `customer_id` ke server dan muat
halaman yang diminta layar.

### `bentuk=ringan`

Memakai resource daftar tanpa relasi berat: **tidak ada** `titik`, `standar_acuan`, `metode_kalibrasi`,
`reviewer`, `pembacaan_mentah`, `hasil_autoclave`, `spesifikasi_alat`. Kunci yang sama dengan bentuk penuh
memakai **nama dan arti yang sama**, jadi `CalibrationHistoryItem.fromJson` bisa dipakai tanpa diubah.
`hasil` hanya memuat `keputusan` (`PASS`/`FAIL`, atau `null`). Detail satu sesi tetap `GET /api/calibrations/{id}`.
Alat dan PT yang sudah dihapus tetap bernama. Bungkusnya sama dengan bentuk penuh (`data`, `links`, `meta`);
contoh di bawah memangkas `links` dan `meta.links` dan hanya menampilkan satu baris. `GET /api/calibrations?bentuk=ringan&q=sn-0204&per_page=5`:

```json
{
    "data": [
        {
            "id": 5,
            "nomor_sesi": "KAL/2026/11/9005",
            "nomor_order": "PO-2026-0042",
            "status": "disetujui",
            "keputusan": "PASS",
            "hasil": {
                "keputusan": "PASS"
            },
            "tanggal_kalibrasi": "2026-11-11",
            "equipment": {
                "id": 5,
                "nama_alat": "Jangka Sorong",
                "nama_alat_kemampuan": null,
                "profil": "jangka_sorong",
                "serial_number": "SN-0204"
            },
            "alat_serial_number": null,
            "pelanggan": {
                "id": 1,
                "nama": "PT Contoh Alfa"
            },
            "teknisi": {
                "id": 6,
                "nama": "Dr. Arch Schaefer"
            },
            "catatan_revisi": null,
            "certificate_id": 5,
            "sertifikat": {
                "id": 5,
                "nomor": "CAL/2026/11/0004",
                "status": "terbit",
                "diterbitkan_pada": "2026-11-12"
            },
            "submitted_at": null,
            "reviewed_at": null,
            "updated_at": "2026-11-12T21:24:17+07:00",
            "tersembunyi": false
        }
    ],
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "path": "http://localhost/api/calibrations",
        "per_page": 5,
        "to": 1,
        "total": 1
    }
}
```

Galat validasi (`422`, bentuk Laravel biasa):

```json
{
    "message": "Tanggal `sampai` tidak boleh sebelum tanggal `dari`. (and 1 more error)",
    "errors": {
        "sampai": [
            "Tanggal `sampai` tidak boleh sebelum tanggal `dari`."
        ],
        "per_page": [
            "per_page paling banyak 50."
        ]
    }
}
```

## 3. Garis waktu arsip — Lab: `GET /api/arsip/garis-waktu`

Semua peran internal (admin, teknisi, viewer, super admin). **Teknisi hanya mendapat sertifikat dari
sesinya sendiri.** Throttle 60/menit per pengguna (`429` bila terlampaui).

Arsip dikelompokkan **TAHUN › BULAN › PEKAN › HARI** menurut `diterbitkan_pada`. Dihitung: sertifikat
berstatus `terbit`, `dibatalkan`, dan `ditarik` yang punya tanggal terbit (`status` ada di tiap item supaya
app bisa menandai yang batal/ditarik). `menunggu_generate` dan `gagal` tidak ikut — belum jadi dokumen.

### Parameter

| Parameter | Isi |
|---|---|
| `tingkat` | `tahun` (bawaan) · `bulan` · `pekan` · `hari` |
| `tahun` | 2000–2100 |
| `bulan` | 1–12 |
| `pekan` | 1–53, nomor **pekan ISO** |
| `tanggal` | `YYYY-MM-DD`, hanya tingkat `hari`, mempersempit ke satu hari |
| `customer_id` | hanya sertifikat milik PT itu |
| `q` | aturan §1, menyempitkan **semua** tingkat |
| `per_page`, `page` | paginasi `item` (tingkat `hari` saja); `per_page` 1–50, bawaan 20 |

### Penelusuran bertingkat

| `tingkat` | induk **wajib** | induk lain | isi jawaban |
|---|---|---|---|
| `tahun` | — | **dilarang** | kelompok per tahun |
| `bulan` | `tahun` | dilarang | kelompok per bulan di tahun itu |
| `pekan` | `tahun`, `bulan` | dilarang | kelompok per pekan di bulan itu |
| `hari` | `tahun`, `bulan`, `pekan` | `tanggal` boleh | kelompok per hari **+ daftar item** |

Induk yang kurang, berlebih, atau tidak ada (mis. pekan 20 di November, atau `tanggal` di luar pekan itu)
dijawab `422`. **Jangan menyusun parameter sendiri — pakai `telusuri` di tiap kelompok** (lihat bawah): isinya
persis query string untuk tingkat berikutnya.

### Pekan ISO dipotong per bulan (penting)

Pekan = ISO 8601, Senin–Minggu. Satu pekan bisa melintasi dua bulan, padahal garis waktu bertingkat
butuh hitungan yang bisa dijumlahkan ke atas. Maka `pekan` selalu dibaca **bersama `bulan`** dan hanya
berisi hari-hari bulan itu:

* Pekan 44/2026 = Senin 26 Okt – Minggu 1 Nov. Di bulan Oktober ia berisi 26–31 Okt, di bulan November hanya 1 Nov.
* Jumlah semua pekan di satu bulan **sama** dengan jumlah bulan itu; jumlah semua bulan sama dengan jumlah tahun.
* Pekan 1 bisa jatuh di akhir Desember (pekan 1/2026 dimulai Senin 29 Des 2025): di Desember 2025 ia
  berisi 29–31 Des. Nomor pekan tidak ambigu di dalam satu bulan.

### Bentuk jawaban (sama di semua tingkat)

`data.kelompok` selalu array; `data.item` hanya terisi pada tingkat `hari`; `meta` adalah paginasi `item`
(di tingkat lain `total` = 0).

| Kunci | Arti |
|---|---|
| `data.tingkat` | tingkat yang dijawab |
| `data.induk` | induk yang dikirim (`{}` di tingkat tahun) |
| `data.total_dokumen` | jumlah dokumen dalam lingkup (sesudah `customer_id`/`q`) |
| `data.kelompok[].kunci` | `"2026"`, `"2026-11"`, `"2026-W46"` (tahun-ISO + nomor pekan), `"2026-11-12"` |
| `data.kelompok[].label` | sudah berbahasa Indonesia: `"2026"`, `"November 2026"`, `"Pekan 46 (9–15 Nov 2026)"`, `"Kamis, 12 Nov 2026"` |
| `data.kelompok[].jumlah` | jumlah dokumen |
| `data.kelompok[].mulai`, `selesai` | rentang tanggal kelompok (pekan: sudah dipotong ke bulannya) |
| `data.kelompok[].telusuri` | query string tingkat berikutnya, mis. `{"tingkat":"hari","tahun":2026,"bulan":11,"pekan":46}` |
| `data.item[]` | dokumen (tingkat `hari`), terbaru dulu: tanggal terbit menurun, lalu `id` menurun |

Kelompok **terbaru dulu**; yang berjumlah 0 tidak dikirim. Label tidak perlu dihitung ulang.

### Contoh (data sintetis)

Tingkat `tahun` (`GET /api/arsip/garis-waktu`):

```json
{
    "data": {
        "tingkat": "tahun",
        "induk": {},
        "total_dokumen": 6,
        "kelompok": [
            {
                "kunci": "2026",
                "label": "2026",
                "jumlah": 5,
                "mulai": "2026-01-01",
                "selesai": "2026-12-31",
                "telusuri": {
                    "tingkat": "bulan",
                    "tahun": 2026
                }
            },
            {
                "kunci": "2025",
                "label": "2025",
                "jumlah": 1,
                "mulai": "2025-01-01",
                "selesai": "2025-12-31",
                "telusuri": {
                    "tingkat": "bulan",
                    "tahun": 2025
                }
            }
        ],
        "item": []
    },
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 20,
        "total": 0
    }
}
```

Tingkat `bulan` (`?tingkat=bulan&tahun=2026`):

```json
{
    "data": {
        "tingkat": "bulan",
        "induk": {
            "tahun": 2026
        },
        "total_dokumen": 5,
        "kelompok": [
            {
                "kunci": "2026-11",
                "label": "November 2026",
                "jumlah": 4,
                "mulai": "2026-11-01",
                "selesai": "2026-11-30",
                "telusuri": {
                    "tingkat": "pekan",
                    "tahun": 2026,
                    "bulan": 11
                }
            },
            {
                "kunci": "2026-10",
                "label": "Oktober 2026",
                "jumlah": 1,
                "mulai": "2026-10-01",
                "selesai": "2026-10-31",
                "telusuri": {
                    "tingkat": "pekan",
                    "tahun": 2026,
                    "bulan": 10
                }
            }
        ],
        "item": []
    },
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 20,
        "total": 0
    }
}
```

Tingkat `pekan`, November (`?tingkat=pekan&tahun=2026&bulan=11`) — perhatikan pekan 44 hanya berisi 1 Nov:

```json
{
    "data": {
        "tingkat": "pekan",
        "induk": {
            "tahun": 2026,
            "bulan": 11
        },
        "total_dokumen": 4,
        "kelompok": [
            {
                "kunci": "2026-W46",
                "label": "Pekan 46 (9–15 Nov 2026)",
                "jumlah": 3,
                "mulai": "2026-11-09",
                "selesai": "2026-11-15",
                "telusuri": {
                    "tingkat": "hari",
                    "tahun": 2026,
                    "bulan": 11,
                    "pekan": 46
                }
            },
            {
                "kunci": "2026-W44",
                "label": "Pekan 44 (1 Nov 2026)",
                "jumlah": 1,
                "mulai": "2026-11-01",
                "selesai": "2026-11-01",
                "telusuri": {
                    "tingkat": "hari",
                    "tahun": 2026,
                    "bulan": 11,
                    "pekan": 44
                }
            }
        ],
        "item": []
    },
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 20,
        "total": 0
    }
}
```

Pekan yang sama di Oktober (`?tingkat=pekan&tahun=2026&bulan=10`) — hanya 26–31 Okt:

```json
{
    "data": {
        "tingkat": "pekan",
        "induk": {
            "tahun": 2026,
            "bulan": 10
        },
        "total_dokumen": 1,
        "kelompok": [
            {
                "kunci": "2026-W44",
                "label": "Pekan 44 (26–31 Okt 2026)",
                "jumlah": 1,
                "mulai": "2026-10-26",
                "selesai": "2026-10-31",
                "telusuri": {
                    "tingkat": "hari",
                    "tahun": 2026,
                    "bulan": 10,
                    "pekan": 44
                }
            }
        ],
        "item": []
    },
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 20,
        "total": 0
    }
}
```

Tingkat `hari` (`?tingkat=hari&tahun=2026&bulan=11&pekan=46&per_page=2`) — `kelompok` per hari **dan** `item`
berpaginasi (halaman berikutnya: `&page=2`; `meta.last_page` menentukan berhenti):

```json
{
    "data": {
        "tingkat": "hari",
        "induk": {
            "tahun": 2026,
            "bulan": 11,
            "pekan": 46
        },
        "total_dokumen": 3,
        "kelompok": [
            {
                "kunci": "2026-11-12",
                "label": "Kamis, 12 Nov 2026",
                "jumlah": 2,
                "mulai": "2026-11-12",
                "selesai": "2026-11-12",
                "telusuri": {
                    "tahun": 2026,
                    "bulan": 11,
                    "pekan": 46,
                    "tingkat": "hari",
                    "tanggal": "2026-11-12"
                }
            },
            {
                "kunci": "2026-11-09",
                "label": "Senin, 9 Nov 2026",
                "jumlah": 1,
                "mulai": "2026-11-09",
                "selesai": "2026-11-09",
                "telusuri": {
                    "tahun": 2026,
                    "bulan": 11,
                    "pekan": 46,
                    "tingkat": "hari",
                    "tanggal": "2026-11-09"
                }
            }
        ],
        "item": [
            {
                "id": 6,
                "nomor": "CAL/2026/11/0005",
                "status": "terbit",
                "revisi_ke": null,
                "tanggal": "2026-11-12",
                "nama_alat": "Micrometer",
                "serial_number": "SN-0205",
                "perusahaan": "PT Contoh Alfa",
                "customer_id": 1,
                "nomor_order": null,
                "calibration_session_id": 6,
                "pdf_url": "http://localhost/api/certificates/6/download"
            },
            {
                "id": 5,
                "nomor": "CAL/2026/11/0004",
                "status": "terbit",
                "revisi_ke": null,
                "tanggal": "2026-11-12",
                "nama_alat": "Jangka Sorong",
                "serial_number": "SN-0204",
                "perusahaan": "PT Contoh Alfa",
                "customer_id": 1,
                "nomor_order": "PO-2026-0042",
                "calibration_session_id": 5,
                "pdf_url": "http://localhost/api/certificates/5/download"
            }
        ]
    },
    "meta": {
        "current_page": 1,
        "last_page": 2,
        "per_page": 2,
        "total": 3
    }
}
```

Item (lab): `id`, `nomor`, `status` (`terbit`/`dibatalkan`/`ditarik`), `revisi_ke` (`null` = terbitan pertama),
`tanggal` (tanggal terbit), `nama_alat`, `serial_number` (yang diketik teknisi di lembar lebih dulu, lalu
data alat), `perusahaan`, `customer_id`, `nomor_order` (dari lembar, lalu dari order), `calibration_session_id`,
`pdf_url` (`null` bila belum ada PDF). Detail dan unduh tetap lewat `GET /api/certificates/{id}` dan `pdf_url`.

Mencari di dalam garis waktu: tambahkan `q` (dan/atau `customer_id`) ke tingkat mana pun — jumlah dan
kelompok ikut menyempit. Bentuk jawaban tidak berubah.

Galat (`?tingkat=pekan&tahun=2026`, `bulan` kurang):

```json
{
    "message": "Tingkat `pekan` memerlukan parameter `bulan`.",
    "errors": {
        "bulan": [
            "Tingkat `pekan` memerlukan parameter `bulan`."
        ]
    }
}
```

## 4. Garis waktu arsip — Pelanggan: `GET /api/pelanggan/v1/sertifikat/garis-waktu`

Rute baru; rute pelanggan yang ada tidak berubah. Token pelanggan + akun terverifikasi + konteks perusahaan
(seperti `/sertifikat`). Parameter dan penelusuran **sama dengan §3** (`tingkat`, `tahun`, `bulan`, `pekan`,
`tanggal`, `per_page` ≤ 50, `page`), dengan perbedaan:

* **Perusahaan dari konteks** (`X-Perusahaan-Id` / `KonteksPerusahaan`), **bukan dari parameter**. Kirim
  `customer_id` di query string tidak berpengaruh. Sertifikat perusahaan lain tidak pernah terhitung, tampil,
  atau tertemu lewat `q`.
* Seperti daftar `/sertifikat`: yang **sudah digantikan revisi** disembunyikan; `termasuk_digantikan=1` membukanya
  (status item `digantikan`). Yang dihitung: sertifikat `terbit` dan `dibatalkan`.
* `q`: nomor sertifikat (dinormalisasi, revisi ikut), nomor seri, nomor order (hanya order perusahaan ini),
  nama alat. **`q` di `GET /sertifikat` tidak diubah** — masih pencocokan harfiah seperti sebelumnya.
* `item[]` berbentuk **persis baris `GET /sertifikat`** (model baris yang sudah ada bisa dipakai ulang)
  ditambah satu kunci: `nomor_order` (yang tercetak di PDF). Item tidak memuat `customer_id`/`perusahaan` —
  semuanya milik perusahaan pemanggil.
* Throttle 60/menit per pengguna.

Tingkat `tahun` (`GET /api/pelanggan/v1/sertifikat/garis-waktu`):

```json
{
    "data": {
        "tingkat": "tahun",
        "induk": {},
        "total_dokumen": 1,
        "kelompok": [
            {
                "kunci": "2026",
                "label": "2026",
                "jumlah": 1,
                "mulai": "2026-01-01",
                "selesai": "2026-12-31",
                "telusuri": {
                    "tingkat": "bulan",
                    "tahun": 2026
                }
            }
        ],
        "item": []
    },
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 20,
        "total": 0
    }
}
```

Tingkat `hari` (`?tingkat=hari&tahun=2026&bulan=11&pekan=46`):

```json
{
    "data": {
        "tingkat": "hari",
        "induk": {
            "tahun": 2026,
            "bulan": 11,
            "pekan": 46
        },
        "total_dokumen": 1,
        "kelompok": [
            {
                "kunci": "2026-11-12",
                "label": "Kamis, 12 Nov 2026",
                "jumlah": 1,
                "mulai": "2026-11-12",
                "selesai": "2026-11-12",
                "telusuri": {
                    "tahun": 2026,
                    "bulan": 11,
                    "pekan": 46,
                    "tingkat": "hari",
                    "tanggal": "2026-11-12"
                }
            }
        ],
        "item": [
            {
                "id": 7,
                "nomor": "ISO/CNT/0001",
                "status": "berlaku",
                "diterbitkan_pada": "2026-11-12",
                "berlaku_sampai": "2027-11-12",
                "keputusan": null,
                "alat": {
                    "id": 7,
                    "nama": "Busur Derajat",
                    "merk": null,
                    "model": null,
                    "serial": "PL-0001"
                },
                "revisi_dari": null,
                "digantikan_oleh": null,
                "dibatalkan_pada": null,
                "catatan_pelanggan": null,
                "bisa_diunduh": false,
                "tautan_verifikasi": "http://localhost/verify/1xf3edpxkl",
                "nomor_order": "PO-2026-0042"
            }
        ]
    },
    "meta": {
        "current_page": 1,
        "last_page": 1,
        "per_page": 20,
        "total": 1
    }
}
```

## 5. Galat serial alat — `POST /api/equipments`, `PUT /api/equipments/{id}`

Perbaikan di server, **bentuk galat tidak berubah** (Laravel `422`, `errors.serial_number[0]`). Yang baru: dua
pesan yang bisa muncul. Unik **per laboratorium** (tidak berubah — dua PT pelanggan di lab yang sama tidak
boleh memakai nomor seri yang sama).

| Keadaan | Jawaban |
|---|---|
| Dipakai alat yang masih hidup | `422` — `Nomor seri sudah dipakai alat lain.` (seperti dulu) |
| Dipakai alat yang sudah **dihapus** | `422` — pesan baru, mengatakan nomor seri itu masih tercatat pada alat yang sudah dihapus. Dulu lolos validasi lalu **500**. Alat lama **tidak** dipulihkan otomatis |
| Dua request bersamaan berebut nomor yang sama | `422` yang sama (bukan `500`) |

```json
{
    "message": "Nomor seri ini masih tercatat pada alat yang sudah dihapus, dan nomor seri harus unik di satu laboratorium. Pakai nomor seri lain, atau minta admin memulihkan alat lamanya. Alat yang dihapus tidak dipulihkan otomatis.",
    "errors": {
        "serial_number": [
            "Nomor seri ini masih tercatat pada alat yang sudah dihapus, dan nomor seri harus unik di satu laboratorium. Pakai nomor seri lain, atau minta admin memulihkan alat lamanya. Alat yang dihapus tidak dipulihkan otomatis."
        ]
    }
}
```

Tampilkan `errors.serial_number[0]` apa adanya di bawah kolom nomor seri; jangan menebak penyebabnya dari teks.

## 6. Saran urutan kerja (mobile Lab)

1. Riwayat: ganti penyaringan lokal dengan `q`, `dari`, `sampai`, `customer_id` ke server; muat dengan
   `bentuk=ringan&per_page=50` dan `meta.last_page`. Hapus batas 20 halaman; tetap simpan batas pengaman
   (mis. berhenti bila `last_page` tidak masuk akal).
2. Telusuri dan Arsip: tidak perlu mengubah apa pun agar `q` yang lebih luas bekerja — bentuk baris sama.
   Cukup ubah teks petunjuk kolom cari ("nomor sertifikat, nomor seri, atau order").
3. Layar baru "Garis waktu": mulai dari `tingkat=tahun`, lalu ikuti `telusuri` tiap kelompok. Satu layar
   boleh dipakai untuk keempat tingkat (judul = `label` kelompok yang dibuka).
4. Aplikasi Pelanggan: layar garis waktu yang sama dengan rute §4; model baris `item` = model baris
   daftar sertifikat + `nomor_order`.

## 7. Batas dan yang sengaja tidak berubah

* Folder arsip fisik, `FolderOrganizer`, dan `php artisan arsip:rapikan` tidak disentuh. Tidak ada folder hari/pekan.
* Tidak ada batas jumlah perangkat. Tidak ada unique-per-sesi baru di DB.
* `GET /api/calibrations`: `ringkas=1` tetap berarti "tanpa `titik`" (bentuk penuh minus `titik`); `bentuk=ringan`
  adalah bentuk yang lebih ramping dan tidak menggantikannya.
* `GET /api/pelanggan/v1/sertifikat?q=` tidak berubah.
* Tidak ada pencarian di dalam isi PDF atau snapshot sertifikat.
* Aplikasi tidak boleh memakai garis waktu untuk **menghitung** apa pun (hitungan = tampilan server).

## 8. Daftar kode galat

| Status | Kapan |
|---|---|
| `401` | tanpa token, atau token tidak sah |
| `403` | token dari aplikasi yang salah (mis. token lab di rute pelanggan) |
| `422` | parameter salah (tingkat/induk, tanggal, `per_page`>50, `bentuk`), atau nomor seri bentrok (§5) |
| `429` | throttle garis waktu terlampaui (60/menit per pengguna) |
