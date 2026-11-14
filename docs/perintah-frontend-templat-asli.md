# Kontrak frontend — kunci `asli` di daftar template ("Pindai lembar kerja" dari beranda)

Ditulis 15 Nov 2026 untuk **aplikasi Lab** (`sidik-calibration-mobile`). PRD-nya
`docs/permintaan-user-7.md` §60; kontrak dasar pindai ada di `docs/SPEC-ocr-template-lokal.md` §6
dan `docs/perintah-frontend-ocr-lembar-kerja.md`.

Semuanya **additive**: tidak ada rute baru, tidak ada kolom/migrasi, tidak ada kunci lama yang
berubah atau pindah. APK yang sudah terpasang tetap jalan; yang di bawah adalah yang BOLEH dibaca
tambahan di sisi aplikasi.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feat/api-templat-asli-20261114` (belum commit saat dokumen ini ditulis) |
| Rute | `GET /api/worksheet-templates` (yang sudah ada; pagar akses tidak berubah: tanpa token 401, `viewer` 403) |
| Status backend | Tes SQLite terarah hijau. **Gate MySQL penuh belum dijalankan** (CI) — jangan menganggapnya terverifikasi MySQL |

---

## 0. Aturan paling penting

1. **Jangan memanggil `?kertas=asli` per kode lagi untuk beranda.** Satu `GET /worksheet-templates`
   cukup; `?kertas=asli&kode_dokumen=…` tetap dipakai SAAT memindai (butuh sel & kotak).
2. **`asli.siap_pindai` BUKAN `siap_pindai` di tingkat atas.** Yang di tingkat atas = lembar CETAK
   bermarker (kontrak lama, tidak berubah). Yang di dalam `asli` = formulir ASLI lab. Dua hal berbeda
   dengan nilai berbeda — pH Meter hari ini `siap_pindai: true` (cetak) dan `asli.siap_pindai: false`.
3. **Frontend tidak menghitung kesiapan sendiri.** Semua bendera datang dari server. Aturan "boleh
   dipindai" di bawah hanya MENGGABUNGKAN bendera itu persis seperti penolakan di server.
4. **Kunci yang tidak ada = `false`/`null`.** Server selalu mengirim keenam kunci `asli`, tapi
   aplikasi versi lama/lain wajib toleran: `asli` hilang → anggap `ada: false`.

## 1. Bentuk

Tiap entri daftar mendapat satu kunci baru di UJUNG:

```json
{
  "template_id": "ph_meter",
  "versi": 1,
  "kode_dokumen": "SIDIK-FM-CAL-0509_Rev.4",
  "judul": "Calibration Worksheet - pH Meter",
  "jumlah_sel": 60,
  "siap_pindai": true,
  "alasan_belum_siap": null,
  "asli": {
    "ada": true,
    "kode_dokumen": "SIDIK-FM-CAL-0509",
    "revisi": "4",
    "siap_pindai": false,
    "mode_uji": true,
    "perlu_konfirmasi_lab": false
  }
}
```

(Respons nyata dari tes, `OCR_FORMULIR_ASLI_UJI=true`; tujuh kunci pertama tidak berubah.)

| Kunci `asli` | Arti |
|---|---|
| `ada` | server punya formulir asli yang BISA DIPILIH untuk kode ini tanpa kode FM dari kertas |
| `kode_dokumen` | kode FM yang tercetak di formulir, **tanpa** `_Rev.n` (`SIDIK-FM-CAL-0509`); `null` bila `ada: false` |
| `revisi` | nomor revisi saja (`"4"`, string); `null` bila tak tercatat atau `ada: false` |
| `siap_pindai` | geometri formulir terverifikasi ke foto nyata (>=20 foto). Hari ini **belum ada satu pun** |
| `mode_uji` | saklar server `OCR_FORMULIR_ASLI_UJI` menyala DAN formulirnya ada: pindai boleh dicoba, semua sel maks kuning. **Produksi hari ini: mati** |
| `perlu_konfirmasi_lab` | pemetaan kolom ke titik masih asumsi; server MENOLAK pindai formulir ini apa pun nilai bendera lain |

`asli` = jawaban `GET /worksheet-templates/{kode}?kertas=asli` untuk kode itu **tanpa
`equipment_id`, `jumlah_pengulangan`, dan `kode_dokumen`**, dikurangi isi lembarnya (diambil dari
fungsi server yang sama, bukan aturan kedua). `?kertas=asli` yang menjawab 404 muncul di daftar
sebagai:

```json
"asli": { "ada": false, "kode_dokumen": null, "revisi": null,
          "siap_pindai": false, "mode_uji": false, "perlu_konfirmasi_lab": false }
```

Alasan 404-nya tidak dibawa daftar. Hari ini ada tiga entri `ada: false` dengan dua sebab: belum ada
peta formulir sama sekali (`gas_detector`, `height_gauge`), atau satu template punya beberapa formulir
dan server tidak menebak salah satunya tanpa kode FM dari kertas (`micrometer`: 0522.A–D). Untuk
yang terakhir `ada: false` BUKAN "tidak punya formulir asli": jalur pindai dari kertas (kode FM
terbaca → `?kertas=asli&kode_dokumen=…`) tetap bisa menemukannya. Beranda yang memakai `ada` saja
akan menyembunyikan jenis itu; lihat §3.

## 2. Aturan "boleh dipindai" (selaras penolakan server)

```
boleh(entri) = asli.ada
            && !asli.perlu_konfirmasi_lab
            && (asli.siap_pindai || asli.mode_uji)
            && asli.revisi != null
```

(Server juga menolak bila `revisi` bukan angka, `PemrosesScanLembarKerja`. Hari ini setiap entri
`ada:true` punya `revisi` terisi, jadi baris terakhir belum pernah menentukan; ditulis supaya rumus
klien tidak lebih longgar dari server kalau suatu saat ada formulir tanpa revisi.)

Tombol "Pindai lembar kerja" di beranda tampil hanya bila ada **minimal satu** entri `boleh`.

Keadaan nyata (tes, 48 entri, repo 15 Nov 2026):

| Saklar `OCR_FORMULIR_ASLI_UJI` | `ada` | `siap_pindai` | `mode_uji` | `perlu_konfirmasi_lab` | `boleh` |
|---|---|---|---|---|---|
| mati (**produksi sekarang**, `render.yaml`) | 45 | 0 | 0 | 5 | **0** → tombol tersembunyi |
| nyala (tes, build uji) | 45 | 0 | 45 | 5 | 40 |

Kelima `perlu_konfirmasi_lab`: `turbidimeter`, `chlorine_meter`, `refractometer`,
`conductivity_meter`, `do_meter`.

## 3. Yang perlu diketahui sebelum memakainya

- **Kode FM di daftar = kode FM asli.** Untuk ke-45 entri `ada`, `kode_dokumen` lama (cetak,
  `SIDIK-FM-CAL-0509_Rev.4`) dan `asli.kode_dokumen` + `asli.revisi` (`SIDIK-FM-CAL-0509` + `"4"`)
  sama setelah `_Rev.n` dipisah. Tetap bukan string yang sama: jangan membandingkan keduanya
  mentah-mentah; pisahkan revisinya.
- **Template ber-banyak formulir (`ada: false`)** tidak bisa dipilih-awal dari beranda; biarkan
  pengguna memilih manual atau baca kode FM dari foto lalu panggil `?kertas=asli&kode_dokumen=…`
  (server mencocokkan kode dengan atau tanpa `_Rev.n`).
- **`asli` tidak peka alat.** `?kertas=asli&equipment_id=…` pada alat tertentu bisa memilih varian
  lain (mis. Timbangan 0508 vs 0508.A dari profil alat) daripada yang tanpa alat. Daftar menjawab
  "tanpa alat"; keputusan akhir tetap dari `?kertas=asli` saat memindai.
- **Biaya.** Daftar sekarang lebih berat di server (membaca & mencocokkan peta formulir tiap
  template; ±16,7 KB lawan ±10,4 KB). Daftar hanya berubah saat server dideploy, jadi cukup diambil
  sekali per sesi/beranda dan di-cache di klien; jangan di-poll.
- **Isolasi.** `asli` adalah katalog berkas server, identik antar lab; tidak ada data organisasi di
  dalamnya.

## 4. Belum

- Gate MySQL penuh dan SQLite penuh (CI).
- Belum ada formulir yang `siap_pindai` (menunggu >=20 foto nyata per formulir, `docs/PANDUAN-OCR-LEMBAR-KERJA.md` §5).
- Kode FM dari kertas → template (pengenalan jenis lembar) adalah pekerjaan aplikasi, bukan daftar ini.
