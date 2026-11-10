# Perintah Frontend — Simpan & Muat Ulang Draf Data Acuan (Studio)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja Mobile/Studio (A2) — tidak perlu membuka kode server.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feature/studio-draft-sync-20261108`, dasar commit `7b876d7b` (+ perubahan B1–B4 di working tree, **belum di-commit**) |
| Desain ringkas | Tanpa migrasi/kolom baru: draf = baris `paket_acuan_versi` (jenis `data`, status `draf`, `isi` = teks JSON kanonik, `isi_sha256` tetap NULL sampai diajukan); riwayat sel = `paket_acuan_suntingan` (sumber `ketik`); log metode = `paket_acuan_log` (aksi `dibuat` untuk POST, `sunting` untuk PUT). Hash CAS = sha256 teks isi kanonik. Kunci baris: `paket_acuan` dulu, baru `paket_acuan_versi` (urutan yang sama dengan alur ajukan/sahkan) |
| Status backend | Tes SQLite hijau, dapat direproduksi: `php artisan test --filter='StudioDrafAcuan|PemetaanSuntinganTest|ProyeksiDrafTest'` = 340 tes / 1624 assertion; `--filter='MeIzinTest|StudioSatuAkunTest'` = 24 / 351. Suite penuh SQLite tidak dijalankan. **MySQL BELUM dijalankan** (lihat §10). Flag **default MATI** — belum boleh dinyalakan di produksi |
| Referensi | `docs/studio-data-acuan/02-SRS.md` (FR-ST-DRAF-01..08), `03-SDD-ADR.md` Addendum A0 |

---

> **Tentang contoh JSON:** diambil dari keluaran tes API sungguhan (fixture sintetis Micrometer; hash dan id di data nyata akan berbeda). Baris `// HTTP nnn` hanya penanda status, bukan bagian respons.

Bertindak sebagai Frontend Engineer untuk **Sidik Studio** (aplikasi `sidik_studio`, token ability `studio`). Backend menyediakan empat endpoint untuk **menyimpan suntingan angka ke DRAF data acuan dan memuatnya kembali persis**. Dokumen ini mengatur kontraknya.

## 0. Aturan paling penting

1. **Klien tidak menghitung, membulatkan, atau menebak apa pun.** Semua nilai yang ditampilkan di kolom "tersimpan" berasal dari `data.suntingan[].nilai` respons server. Hash selalu disalin dari respons, tidak pernah dihitung di klien.
2. **`nilai` selalu STRING** (di request dan di respons). Jangan mengurai ke `double`/`num` lalu menampilkannya lagi: nilai 15 digit kehilangan ketelitian di `double`, dan `0.80` ≠ tampilan `0.8` yang berubah-ubah. Simpan sebagai `String`, tampilkan apa adanya.
3. **Server yang menentukan sel apa yang boleh disunting.** Klien hanya mengirim `{sel, nilai}`; jalur, tipe, dan pembulatan **tidak pernah** dikirim klien (kunci tambahan = 422). Sel yang tidak muncul di `data.suntingan` tidak bisa disunting di tahap ini.
4. **Hanya JSON.** `Content-Type: application/json`. Bodi form/multipart/teks ditolak 415 `harus_json` (server memangkas spasi bodi form — angka tidak boleh berubah diam-diam).
5. **Setiap 409 berarti "muat ulang dulu".** Jangan retry otomatis dengan hash yang sama. Edit lokal pengguna jangan dibuang (lihat §7).
6. **Izin dibaca dari `GET /api/studio/me`, BUKAN `/api/me/permissions`.** Menurut riset A0 atas kode klien Studio (**belum diverifikasi di repo mobile**; hanya sisi server yang terbukti oleh tes `StudioSatuAkunTest`: `/api/me/permissions` menjawab 403 `bukan_aplikasi_ini` untuk token `studio`), klien Studio memanggil `/me/permissions` lalu jatuh ke daftar cadangan. Jangan membuka rute itu untuk token Studio; A2 harus membaca `/studio/me`.
7. Kalau ambigu, berhenti dan tanya — jangan menambal dengan asumsi.

## 1. Tujuan dan batas

- Studio menyimpan suntingan **angka tunggal** pada sel yang sudah dipetakan server ke DRAF `paket_acuan_versi` (status `draf`), dan memuatnya kembali. Pengunci konflik = hash isi (compare-and-swap).
- **Draf tidak menyentuh**: versi aktif/pensiun, master workbook, sesi kalibrasi lama, stempel versi, `uncertainty_calculations`, atau sertifikat. Draf hanya berpengaruh setelah melewati alur resmi (ajukan → simulasi → sahkan), yang **bukan** bagian endpoint ini.
- Flag `STUDIO_DRAF_ACUAN_AKTIF` **default `false`**: semua rute draf menjawab `503 belum_tersedia`. Hanya akun Studio tunggal (`STUDIO_AKUN`) yang bisa memanggil; akun lab biasa 403.
- Yang BELUM didukung: sel teks/tanggal/peta/daftar, rumus, transformasi, `nilai: null` (mengosongkan sel ditolak), tambah/hapus baris. Sel di luar pemetaan = 422 `sel_tidak_didukung`.
- Pilot saat ini: profil **Micrometer** saja (empat sel angka `DATABASE!T5..T8` → CMC pita A..D).
- **Satu draf memuat keempat pita Micrometer (A–D).** Menyunting `T5` lewat workbook pita B tetap mengubah `cmc[kode=A].u95_um`. Label di UI diambil dari `jalur` (mis. `cmc[kode=A].u95_um`), **bukan** dari nama workbook yang sedang dibuka. T5–T8 identik di keempat master, jadi ini disengaja.

## 2. Alur

```
GET  /api/studio/workbook/{id}/draf-dasar        -> hash workbook/peta/dasar + nilai dasar + daftar draf
POST /api/studio/workbook/{id}/draf              -> draf BARU dari dasar              (201 draf_dibuat | 200 draf_sudah_ada)
PUT  /api/studio/workbook/{id}/draf/{versi}      -> sunting draf yang ada (CAS)       (200 draf_diperbarui | 200 tidak_berubah)
GET  /api/studio/workbook/{id}/draf/{versi}      -> muat ulang satu versi (status apa pun)
```

1. Buka editor: `GET draf-dasar`. Simpan `hash.workbook`, `hash.peta`, `hash.dasar`; tampilkan `suntingan` sebagai nilai dasar; tampilkan `draf` (maks 20 terbaru) bila pengguna ingin melanjutkan draf yang ada.
2. Simpan pertama: `POST` dengan tiga hash dari langkah 1 (`base_isi_sha256` = `hash.dasar`). Respons memberi `versi.id` dan `hash.isi`.
3. Simpan berikutnya: `PUT` ke `versi.id`, `expected_isi_sha256` = `hash.isi` **terakhir yang diterima klien**. Ganti `hash.isi` yang disimpan klien dengan yang di respons.
4. Buka draf yang ada: `GET draf/{versi}` → pakai `hash.isi` dari respons itu.
5. Setelah 409: ikuti `rincian.hash_terbaru`, `GET` untuk memuat ulang (§7).

Dua jalur ke draf: `POST` selalu membuat draf baru dari **dasar** (versi berlaku, atau berkas JSON repo bila belum ada versi berlaku). Melanjutkan draf yang sudah ada = `PUT`. Isi POST yang persis sama dengan draf yang ada → `200 draf_sudah_ada` (ID sama, tanpa baris baru).

## 3. Gerbang

| Hal | Aturan |
|---|---|
| Autentikasi | Sanctum, token ability `studio`, email = `STUDIO_AKUN`. Tanpa token 401; token lab biasa / akun lain 403 (`bukan_akun_studio`). |
| Flag | `studio.draf_acuan_aktif` mati → **503** `belum_tersedia` untuk keempat rute (gerbang Studio dicek lebih dulu: non-Studio tidak pernah melihat 503). |
| Izin di klien | `workbook.draf.sunting` di `GET /api/studio/me` → `data.izin.boleh`. **Muncul hanya bila flag ON.** Izin yang sama berlaku untuk GET, POST, dan PUT (tidak ada izin baca terpisah). Jangan tampilkan tombol Simpan bila izin tidak ada. |
| Throttle | `throttle:studio-draf` **30 permintaan/menit per pengguna**, ember dipakai bersama keempat rute (GET + tulis). **Jangan polling.** 429 membawa header `Retry-After`. |
| Organisasi | Selalu organisasi akun; id lab lain = 404 yang sama dengan "tidak ada". |
| Tidak menulis | Kedua GET tidak menulis apa pun (tidak ada baris audit/jejak baru). |

## 4. Kontrak endpoint

Semua respons sukses: `{"data": {...}}`. Semua galat aturan-alur: `{"message": "...", "kode": "...", "rincian": {...}}` (`rincian` opsional). Galat validasi bentuk (422 tanpa `kode`): format Laravel `{"message", "errors": {"field": ["..."]}}`.

### 4.1 `GET /api/studio/workbook/{workbook}/draf-dasar`

Dipanggil **sebelum** draf pertama ada. Tidak menulis, tidak mengunci. Menolak: `paket_belum_ada` (422), `profil_belum_terpetakan` (422), `workbook_tidak_aktif` (422), `isi_tidak_stabil` (409).

```json
// HTTP 200
{
    "data": {
        "hash": {
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518",
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221"
        },
        "dasar_versi_id": 1,
        "suntingan": [
            {
                "sel": "DATABASE!T5",
                "jalur": "cmc[kode=A].u95_um",
                "nilai": "0.83"
            },
            {
                "sel": "DATABASE!T6",
                "jalur": "cmc[kode=B].u95_um",
                "nilai": "0.87"
            },
            {
                "sel": "DATABASE!T7",
                "jalur": "cmc[kode=C].u95_um",
                "nilai": "0.91"
            },
            {
                "sel": "DATABASE!T8",
                "jalur": "cmc[kode=D].u95_um",
                "nilai": "0.91"
            }
        ],
        "draf": [],
        "draf_total": 0,
        "draf_terpotong": false
    }
}
```

| Field | Arti |
|---|---|
| `hash.workbook` / `hash.peta` / `hash.dasar` | Tiga hash yang diminta POST (`workbook_sha256`, `peta_sel_sha256`, `base_isi_sha256`). |
| `dasar_versi_id` | Id versi berlaku yang menjadi dasar; `null` bila dasarnya berkas JSON repo (belum ada versi berlaku). |
| `suntingan[]` | Proyeksi **dari dasar**: sel yang bisa disunting beserta nilai dasar (STRING kanonik). Urutan tetap. `jalur` = label bagi pengguna. |
| `draf[]` | Draf `data` berstatus `draf` pada paket, **terbaru dulu, maks 20**. Per butir: `id`, `nomor_versi`, `hash.isi`, `dasar_versi_id`, `alasan` (alasan saat dibuat), `dibuat_pada` (ISO 8601). |
| `draf_total` / `draf_terpotong` | Jumlah semua draf di paket; `true` bila `draf` memuat kurang dari `draf_total`. Tampilkan "ada draf lain" bila terpotong. |

### 4.2 `POST /api/studio/workbook/{workbook}/draf`

Request (semua kunci wajib; kunci lain → 422):

```json
{
    "workbook_sha256": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
    "peta_sel_sha256": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518",
    "base_isi_sha256": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585",
    "alasan": "Koreksi CMC pita A sesuai lampiran akreditasi",
    "suntingan": [
        {
            "sel": "DATABASE!T5",
            "nilai": "0.84"
        }
    ]
}
```

| Field | Aturan |
|---|---|
| `workbook_sha256`, `peta_sel_sha256`, `base_isi_sha256` | 64 heksadesimal huruf kecil; salin dari `draf-dasar`. |
| `alasan` | 10..500 karakter; tercatat di jejak audit. |
| `suntingan` | Daftar 1..300 butir `{sel, nilai}`; `sel` unik; alamat maks 256; `nilai` string maks 128. |

Respons `201` `draf_dibuat` (sama bentuk untuk PUT/GET; `status` berbeda):

```json
// HTTP 201
{
    "data": {
        "status": "draf_dibuat",
        "versi": {
            "id": 2,
            "nomor_versi": 2,
            "status": "draf",
            "dasar_versi_id": 1,
            "dasar_versi_id_sekarang": 1,
            "dasar_basi": false,
            "alasan": "Koreksi CMC pita A sesuai lampiran akreditasi",
            "dibuat_pada": "2026-11-10T04:17:05+07:00"
        },
        "hash": {
            "isi": "0fc25574fe2cab01ad89fdff183fff6d6403f3d0cc33930d825467b14cf61e70",
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585",
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518"
        },
        "suntingan": [
            {
                "sel": "DATABASE!T5",
                "jalur": "cmc[kode=A].u95_um",
                "nilai": "0.84"
            },
            {
                "sel": "DATABASE!T6",
                "jalur": "cmc[kode=B].u95_um",
                "nilai": "0.87"
            },
            {
                "sel": "DATABASE!T7",
                "jalur": "cmc[kode=C].u95_um",
                "nilai": "0.91"
            },
            {
                "sel": "DATABASE!T8",
                "jalur": "cmc[kode=D].u95_um",
                "nilai": "0.91"
            }
        ]
    }
}
```

| Field | Arti |
|---|---|
| `status` | `draf_dibuat` (201) · `draf_sudah_ada` (200) · `draf_diperbarui` · `tidak_berubah` · `draf_dimuat`. |
| `versi.id` / `nomor_versi` / `status` | Identitas draf. `status` versi: `draf`, `diajukan`, `aktif`, `pensiun`, `ditolak`, … (GET bisa mengembalikan status apa pun). |
| `versi.dasar_versi_id` | Dasar **asal** draf (`null` = berkas JSON). Tidak berubah oleh PUT. |
| `versi.dasar_versi_id_sekarang` | Dasar berlaku **sekarang** (`null` = berkas JSON). |
| `versi.dasar_basi` | Hanya bermakna untuk **draf**: `true` bila `versi.status` = `draf` dan `dasar_versi_id` ≠ `dasar_versi_id_sekarang` (versi baru sudah disahkan sesudah draf dibuat). Tampilkan peringatan "draf dibuat dari versi lama"; draf tetap bisa dilihat/disunting. Untuk versi **bukan-draf** selalu `false` (tetap bool, bukan `null`): mis. v1 hasil impor awal punya `dasar_versi_id` `null` tetapi bukan draf basi. |
| `versi.alasan` | Alasan saat draf **dibuat**. **PUT tidak mengubahnya**; alasan tiap suntingan ada di jejak audit server, tidak di respons. |
| `versi.dibuat_pada` | ISO 8601 (zona dari server). |
| `hash.isi` | Hash isi draf saat ini. Ini nilai `expected_isi_sha256` untuk PUT berikutnya. |
| `hash.dasar` | Dasar **SEKARANG** (basis POST berikutnya), bukan dasar asal draf. Asal draf = `versi.dasar_versi_id`. |
| `hash.workbook` / `hash.peta` | Hash yang berlaku sekarang. |
| `suntingan[]` | Proyeksi **dari isi draf tersimpan** (nilai STRING kanonik): `{sel, jalur, nilai}`. |

POST dengan isi yang persis sama dengan draf yang sudah ada:

```json
// HTTP 200
{
    "data": {
        "status": "draf_sudah_ada",
        "versi": {
            "id": 2,
            "nomor_versi": 2,
            "status": "draf",
            "dasar_versi_id": 1,
            "dasar_versi_id_sekarang": 1,
            "dasar_basi": false,
            "alasan": "Koreksi CMC pita A sesuai lampiran akreditasi",
            "dibuat_pada": "2026-11-10T04:17:05+07:00"
        },
        "hash": {
            "isi": "0fc25574fe2cab01ad89fdff183fff6d6403f3d0cc33930d825467b14cf61e70",
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585",
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518"
        },
        "suntingan": [
            {
                "sel": "DATABASE!T5",
                "jalur": "cmc[kode=A].u95_um",
                "nilai": "0.84"
            },
            {
                "sel": "DATABASE!T6",
                "jalur": "cmc[kode=B].u95_um",
                "nilai": "0.87"
            },
            {
                "sel": "DATABASE!T7",
                "jalur": "cmc[kode=C].u95_um",
                "nilai": "0.91"
            },
            {
                "sel": "DATABASE!T8",
                "jalur": "cmc[kode=D].u95_um",
                "nilai": "0.91"
            }
        ]
    }
}
```

### 4.3 `PUT /api/studio/workbook/{workbook}/draf/{versi}`

Request sama dengan POST, kecuali `expected_isi_sha256` menggantikan `base_isi_sha256` (mengirim `base_isi_sha256` ke PUT atau `expected_isi_sha256` ke POST = 422 kunci tidak dikenal).

```json
{
    "workbook_sha256": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
    "peta_sel_sha256": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518",
    "expected_isi_sha256": "0fc25574fe2cab01ad89fdff183fff6d6403f3d0cc33930d825467b14cf61e70",
    "alasan": "Koreksi lanjutan CMC pita B sesuai lampiran",
    "suntingan": [
        {
            "sel": "DATABASE!T6",
            "nilai": "0.88"
        }
    ]
}
```

Hanya **draf** yang bisa disunting; versi lain → 409 `draf_beku`. PUT hanya mengubah isi draf: status, `isi_sha256`, `lock_versi`, masa berlaku, dasar, dan `versi.alasan` tidak berubah. Respons `200` `draf_diperbarui`:

```json
// HTTP 200
{
    "data": {
        "status": "draf_diperbarui",
        "versi": {
            "id": 2,
            "nomor_versi": 2,
            "status": "draf",
            "dasar_versi_id": 1,
            "dasar_versi_id_sekarang": 1,
            "dasar_basi": false,
            "alasan": "Koreksi CMC pita A sesuai lampiran akreditasi",
            "dibuat_pada": "2026-11-10T04:17:05+07:00"
        },
        "hash": {
            "isi": "e53c10903b0b882c1919c2611412ce91f6a66107a4cb6d5d98b37f45b6a29cdc",
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585",
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518"
        },
        "suntingan": [
            {
                "sel": "DATABASE!T5",
                "jalur": "cmc[kode=A].u95_um",
                "nilai": "0.84"
            },
            {
                "sel": "DATABASE!T6",
                "jalur": "cmc[kode=B].u95_um",
                "nilai": "0.88"
            },
            {
                "sel": "DATABASE!T7",
                "jalur": "cmc[kode=C].u95_um",
                "nilai": "0.91"
            },
            {
                "sel": "DATABASE!T8",
                "jalur": "cmc[kode=D].u95_um",
                "nilai": "0.91"
            }
        ]
    }
}
```

### 4.4 `GET /api/studio/workbook/{workbook}/draf/{versi}`

Status versi apa pun boleh dibaca (aktif, pensiun, diajukan, ditolak, …) selama jenisnya `data` dan milik paket workbook itu. Respons `200` `draf_dimuat`, bentuk **sama** dengan PUT (byte JSON identik selain `status`):

```json
// HTTP 200
{
    "data": {
        "status": "draf_dimuat",
        "versi": {
            "id": 2,
            "nomor_versi": 2,
            "status": "draf",
            "dasar_versi_id": 1,
            "dasar_versi_id_sekarang": 1,
            "dasar_basi": false,
            "alasan": "Koreksi CMC pita A sesuai lampiran akreditasi",
            "dibuat_pada": "2026-11-10T04:17:05+07:00"
        },
        "hash": {
            "isi": "e53c10903b0b882c1919c2611412ce91f6a66107a4cb6d5d98b37f45b6a29cdc",
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585",
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518"
        },
        "suntingan": [
            {
                "sel": "DATABASE!T5",
                "jalur": "cmc[kode=A].u95_um",
                "nilai": "0.84"
            },
            {
                "sel": "DATABASE!T6",
                "jalur": "cmc[kode=B].u95_um",
                "nilai": "0.88"
            },
            {
                "sel": "DATABASE!T7",
                "jalur": "cmc[kode=C].u95_um",
                "nilai": "0.91"
            },
            {
                "sel": "DATABASE!T8",
                "jalur": "cmc[kode=D].u95_um",
                "nilai": "0.91"
            }
        ]
    }
}
```

Membaca tidak menuntut workbook aktif (workbook diarsipkan tetap 404).

## 5. Aturan `nilai`

- Selalu string desimal. Regex wire: `-?(0|[1-9][0-9]*)(\.[0-9]{1,10})?` (tanpa `+`, tanpa spasi, tanpa eksponen `1e3`, tanpa `.5`, tanpa `2.`, tanpa `01.5`, koma bukan pemisah).
- Maks 10 angka di belakang titik, dan harus **tepat** terwakili (sekitar 15 digit signifikan): `9007199254740993` (16 digit) ditolak 422; `123456789.123456` (15 digit) lolos.
- `-0` diterima dan disimpan sebagai `0.0` (kanonik tidak menyimpan tanda nol).
- Bilangan bulat dikembalikan sebagai float kanonik bila daunnya float (`"1"` → `"1.0"`; `"0.870"` → `"0.87"`). Tampilkan nilai respons, bukan nilai yang diketik.
- `null`, angka JSON (`0.84`), boolean, array, string kosong → 422 validasi (`suntingan.N.nilai`).
- Nilai yang **sama** dengan isi sekarang dilewati (tidak dicatat sebagai perubahan). Bila **semua** butir sama: POST → 422 `tidak_ada_perubahan`; PUT → 200 `tidak_berubah`.
- Satu butir buruk membatalkan **seluruh** gelombang (tidak ada penyimpanan parsial); `rincian.indeks` (0-basis) dan `rincian.sel` menunjuk butir pertama yang ditolak.
- Validasi di klien boleh memakai regex di atas untuk umpan balik cepat, tetapi **422 server tetap otoritas**. (Regex ketikan klien lama lebih longgar — jangan dipakai.)

## 6. Kode galat dan tindakan klien

| HTTP | `kode` | Arti | Tindakan klien |
|---|---|---|---|
| 401 | – | Tanpa/token kedaluwarsa | Alur login ulang yang sudah ada |
| 403 | `bukan_akun_studio` / – | Bukan akun Studio / token lab | Sembunyikan fitur; jangan retry |
| 404 | – (`message` standar, identik) | Workbook/versi tak ada, milik lab lain, diarsipkan, versi milik paket lain, jenis bukan `data`, id bukan angka | Tutup editor, muat ulang daftar. Jangan membedakan penyebabnya |
| 409 | `workbook_berubah` | Hash workbook basi, atau profil workbook bergeser di antara dua bacaan server | `rincian.hash_terbaru`, muat ulang workbook (§7) |
| 409 | `peta_berubah` | Peta sel di server berubah | Muat ulang (`draf-dasar`), ulangi |
| 409 | `dasar_berubah` | (POST) dasar berganti | Pakai `hash_terbaru.dasar`, tampilkan nilai dasar baru, minta pengguna meninjau, ulangi |
| 409 | `draf_berubah` | (PUT) draf sudah berubah, atau (POST) draf lain baru dibuat | `GET` draf, gabungkan edit lokal, ulangi dengan `hash.isi` baru |
| 409 | `draf_beku` | (PUT) versi bukan draf lagi; `rincian.status` | Tampilkan hanya-baca; tawarkan draf baru dari dasar |
| 409 | `isi_tidak_stabil` | Isi dasar/draf tak bisa disimpan ulang byte-identik (objek kosong, kunci numerik, bilangan di luar jangkauan, isi rusak) | Jangan retry; tampilkan "hubungi admin" |
| 415 | `harus_json` | Content-Type bukan JSON | Bug klien: kirim `application/json` |
| 422 | `profil_belum_terpetakan` | Workbook ini belum punya peta sel | Sembunyikan simpan untuk workbook ini |
| 422 | `paket_belum_ada` | Paket acuan profil belum diimpor | Tampilkan "versi 1 belum diimpor"; draf tidak bisa dibuat |
| 422 | `workbook_tidak_aktif` | Workbook riwayat (sudah digantikan) — POST/PUT/draf-dasar | Buka master yang aktif |
| 422 | `sel_tidak_didukung` | Sel di luar pemetaan (teks/tanggal/rumus/tidak ada) | Kunci sel itu di UI |
| 422 | `suntingan_ditolak` | Nilai/gelombang ditolak (`rincian.indeks`, `rincian.sel`) | Tandai butir itu; edit tetap ada |
| 422 | `tidak_ada_perubahan` | (POST) semua nilai sama dengan dasar | Tidak ada yang disimpan |
| 422 | – + `errors{}` | Validasi bentuk (hash/alasan/batas/kunci tak dikenal) | Tunjukkan per field |
| 429 | – | Throttle 30/menit | Tunggu `Retry-After`; jangan polling |
| 500 | `proyeksi_gagal` | Proyeksi tidak bisa disusun; **tidak ada yang tersimpan** | Tampilkan galat; boleh retry sekali |
| 503 | `belum_tersedia` | Flag mati | Sembunyikan fitur |

Contoh galat nyata (keluaran tes):

```json
// HTTP 409
{
    "message": "Data acuan dasar berubah sejak Anda membukanya. Muat ulang lalu ulangi.",
    "kode": "dasar_berubah",
    "rincian": {
        "hash_terbaru": {
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518",
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585"
        }
    }
}
```

```json
// HTTP 409
{
    "message": "Workbook master berubah sejak Anda membukanya. Muat ulang workbook lalu ulangi.",
    "kode": "workbook_berubah",
    "rincian": {
        "hash_terbaru": {
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518",
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585"
        }
    }
}
```

```json
// HTTP 409
{
    "message": "Versi ini bukan draf lagi (status diajukan), isinya dibekukan dan tidak bisa disunting. Buat draf baru dari dasar.",
    "kode": "draf_beku",
    "rincian": {
        "status": "diajukan"
    }
}
```

```json
// HTTP 422
{
    "message": "Nilai untuk sel DATABASE!T5 (suntingan ke-1) ditolak. Pakai angka desimal biasa: maksimal 10 angka di belakang koma, tanpa spasi, tanda +, atau eksponen, dan tidak kehilangan ketelitian.",
    "kode": "suntingan_ditolak",
    "rincian": {
        "indeks": 0,
        "sel": "DATABASE!T5"
    }
}
```

```json
// HTTP 422
{
    "message": "Sel berikut tidak bisa disunting di draf: DATABASE!T9. Hanya sel angka tunggal yang dipetakan server yang boleh disunting.",
    "kode": "sel_tidak_didukung",
    "rincian": {
        "sel": [
            "DATABASE!T9"
        ],
        "jumlah": 1
    }
}
```

```json
// HTTP 422
{
    "message": "Alasan suntingan minimal 10 karakter.",
    "errors": {
        "alasan": [
            "Alasan suntingan minimal 10 karakter."
        ]
    }
}
```

```json
// HTTP 422
{
    "message": "Nilai yang dikirim sama dengan isi dasar. Tidak ada yang perlu disimpan.",
    "kode": "tidak_ada_perubahan"
}
```

```json
// HTTP 415
{
    "message": "Bodi harus JSON (Content-Type: application/json). Bodi form dipangkas spasinya oleh server, dan angka sertifikat tidak boleh berubah diam-diam.",
    "kode": "harus_json"
}
```

```json
// HTTP 422
{
    "message": "Workbook ini bukan master aktif lagi (sudah digantikan versi lain). Buka master yang aktif lalu ulangi.",
    "kode": "workbook_tidak_aktif"
}
```

```json
// HTTP 503
{
    "kode": "belum_tersedia",
    "message": "Simpan draf data acuan belum tersedia di server ini."
}
```

## 7. Konflik, retry, dan "muat ulang"

**Urutan penolakan** (yang pertama kena yang dijawab): 404 (workbook tak ada/lab lain/diarsipkan) → 422 `profil_belum_terpetakan` → 422 `workbook_tidak_aktif` (hanya `draf-dasar` memeriksa tidak-aktif lebih dulu daripada profil) → 409 `workbook_berubah` → 409 `peta_berubah` → POST: 422 `paket_belum_ada`, 409 `dasar_berubah`, 409 `isi_tidak_stabil`, lalu 422 isi; PUT: 404 (paket/versi tak ada) → 409 `draf_beku` → 409 `isi_tidak_stabil` → 409 `draf_berubah` / 422 isi. Akibatnya lab yang belum punya paket tetapi mengirim hash workbook/peta basi mendapat 409, **bukan** 422 `paket_belum_ada` (POST) atau 404 (PUT); kode galat yang Anda lihat bergeser begitu hash dibetulkan.

**`rincian.hash_terbaru`** ada di keempat 409 hash: `workbook_berubah`, `peta_berubah`, `dasar_berubah`, `draf_berubah`. Isinya hanya hash — tidak ada isi lain:

- POST: `{workbook, peta, dasar}` (`dasar` bisa `null` bila tak terbaca).
- PUT: `{workbook, peta, isi}` (`isi` bisa `null` bila versi tak ada di paket workbook ini).

Cara pulih: panggil `GET draf/{versi}` (atau `draf-dasar`), ganti hash lokal dengan yang baru, **pertahankan edit pengguna yang belum tersimpan**, tampilkan nilai server yang baru berdampingan, lalu kirim ulang.

**PUT dengan hash basi.** Server tidak langsung menolak: ia mencoba menerapkan suntingan Anda ke isi draf **sekarang**.

- Hasilnya nol perubahan (nilai yang Anda kirim sudah ada) → `200 tidak_berubah`, tanpa tulis/audit.
- Selain itu → `409 draf_berubah` (termasuk bila suntingan Anda sekarang tertolak; hash basi mendahului 422).

```json
// HTTP 200
{
    "data": {
        "status": "tidak_berubah",
        "versi": {
            "id": 2,
            "nomor_versi": 2,
            "status": "draf",
            "dasar_versi_id": 1,
            "dasar_versi_id_sekarang": 1,
            "dasar_basi": false,
            "alasan": "Koreksi CMC pita A sesuai lampiran akreditasi",
            "dibuat_pada": "2026-11-10T04:17:05+07:00"
        },
        "hash": {
            "isi": "e53c10903b0b882c1919c2611412ce91f6a66107a4cb6d5d98b37f45b6a29cdc",
            "dasar": "ba1fa153808d125a2ff1e54ca3589e25c723b59ccb23a68df7372862ef56f585",
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518"
        },
        "suntingan": [
            {
                "sel": "DATABASE!T5",
                "jalur": "cmc[kode=A].u95_um",
                "nilai": "0.84"
            },
            {
                "sel": "DATABASE!T6",
                "jalur": "cmc[kode=B].u95_um",
                "nilai": "0.88"
            },
            {
                "sel": "DATABASE!T7",
                "jalur": "cmc[kode=C].u95_um",
                "nilai": "0.91"
            },
            {
                "sel": "DATABASE!T8",
                "jalur": "cmc[kode=D].u95_um",
                "nilai": "0.91"
            }
        ]
    }
}
```

```json
// HTTP 409
{
    "message": "Draf ini sudah berubah sejak Anda memuatnya. Muat ulang lalu ulangi suntingan Anda.",
    "kode": "draf_berubah",
    "rincian": {
        "hash_terbaru": {
            "workbook": "3f40a3010598bfbd092b700fe1d39cdf4534aaaf0d505c4ac80639a1bc918221",
            "peta": "271bf94c0e108a84ecb8d4968b99ce618734778499c53a7c4ee75022c5277518",
            "isi": "e53c10903b0b882c1919c2611412ce91f6a66107a4cb6d5d98b37f45b6a29cdc"
        }
    }
}
```

**PERHATIAN — `tidak_berubah` itu ambigu.** Respons yang sama muncul untuk (a) retry Anda sendiri setelah respons pertama hilang (sukses sebenarnya), dan (b) orang lain yang menulis nilai yang sama lebih dulu. Karena itu **klien harus mengambil `hash.isi` dan `suntingan` dari respons `tidak_berubah`**, bukan mengandalkan hash/nilai lokalnya: itulah keadaan server sekarang. Kolom lain yang tidak Anda kirim bisa saja sudah diubah orang lain.

**Retry jaringan.**

- PUT yang sama dua kali dengan `expected` lama: yang kedua `200 tidak_berubah` (bila nilai sudah masuk) atau `409` — aman.
- POST yang sama dua kali: yang kedua `200 draf_sudah_ada` dengan ID yang sama. Dua POST dengan isi berbeda = dua draf (nomor berurutan).
- Respons terlambat sesudah pengguna menyunting lagi/logout: gunakan token generasi di klien; jangan menimpa edit terbaru dengan respons lama. Respons selalu `hash.isi` yang berlaku saat server menulis.
- Klien Studio **tidak** me-retry 409/422/429 otomatis (pola yang sudah ada); biarkan begitu.

## 8. Kebijakan workbook

- POST, PUT, dan `draf-dasar` membaca ulang workbook di dalam transaksi: diarsipkan → 404; **tidak aktif** (riwayat hasil penggantian, `aktif=false`) → **422 `workbook_tidak_aktif`** (fail-closed: hash riwayat tidak menggambarkan master yang dipakai Studio). Buka versi aktif lewat `GET /api/studio/workbook/{id}/riwayat`.
- GET draf tidak menuntut workbook aktif (hanya membaca riwayat).
- `workbook_sha256` = field `sha256` di respons workbook (bukan `sha256_hidup`).

## 9. Hal lain yang perlu diketahui klien

- `dibuat_pada` ISO 8601; urutan `suntingan[]` tetap menurut peta.
- Satu paket bisa punya banyak draf (impor workbook juga membuat draf). `draf-dasar` hanya menampilkan 20 terbaru (`draf_terpotong`).
- Sel di draf yang daunnya hilang/tidak angka di isi tersimpan disaring (tidak muncul di `suntingan`), bukan error.
- `nomor_versi` = 1 + nomor tertinggi di paket (berurutan, dihitung di bawah kunci paket).
- Hash dihitung server atas teks isi kanonik; klien tidak pernah mereproduksinya.

## 10. Yang BELUM terbukti (jangan diandalkan)

- **MySQL.** Seluruh bukti ada di SQLite. Kunci baris (`FOR UPDATE`) dibuktikan hanya sebagai *pemanggilan* (perekam global-scope + urutan kueri), bukan perilaku antar-koneksi. Dua penyimpan paralel sungguhan, semantik snapshot REPEATABLE READ InnoDB, deadlock paket↔versi, dan trigger append-only MySQL belum diuji. Suite MySQL penuh wajib sebelum flag dinyalakan.
- **Paritas workbook asli.** Master .xlsm asli tidak ada di mesin build; bukti dari peta JSON + CSV master.
- **Perangkat nyata / UI.** Tidak ada UI atau perangkat yang diuji terhadap kontrak ini.
- **Izin produksi.** Hak sunting selain akun Studio tunggal (FR-ST-DRAF-05) belum didefinisikan.

## 11. Daftar kerja A2 (usulan)

1. Baca izin dari `/studio/me`; sembunyikan Simpan bila `workbook.draf.sunting` tidak ada.
2. Model data: `nilai` String, hash String; model `versi` memuat `dasar_basi`, `dasar_versi_id_sekarang`.
3. `draf-dasar` saat editor dibuka; POST pertama; PUT berikutnya; GET untuk memuat/melanjutkan.
4. Dialog 409 dengan pemuatan ulang dan pertahankan edit lokal; label sel dari `jalur`; peringatan `dasar_basi`; tampilan hanya-baca untuk `draf_beku`.
5. Tes: wire-string (15 digit, `-0`, `1`→`1.0`), 409 per kode, `tidak_berubah`, logout/respons terlambat, 429 tanpa polling.
