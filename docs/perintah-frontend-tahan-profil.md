# Perintah Frontend — Penahanan penerbitan sertifikat per jenis alat

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja frontend —
tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feat/tahan-penerbitan-profil`, commit `427425c0` (sebelum commit dokumen ini) |
| Status backend | Terverifikasi di SQLite: 33 tes baru (`ProfilDitahanTest`, `ProfilDitahanDataSeederTest`) dan sapuan terkait 653 tes hijau; contoh JSON di bawah dari respons nyata tes. **Gate MySQL penuh belum dijalankan.** |
| Perubahan kontrak | **Aditif saja.** Satu kunci baru `penerbitan_ditahan` di respons sesi dan di `GET /categories/{kode}`, satu kode galat baru `profil_ditahan` di `approve`. Tidak ada kunci yang berubah atau hilang. |
| Referensi | `docs/tahan-penerbitan-profil.md` (cara kerja & pelepasan), `docs/kontrak-api.md` (`approve`, `GET /calibrations`, `GET /categories/{kode}`) |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Keputusan pemilik 18 Nov 2026: **sertifikat yang salah di lab terakreditasi lebih berbahaya daripada
sertifikat yang tertunda.** Tujuh jenis alat sekarang TIDAK bisa disetujui/diterbitkan sampai Technical
Manager (TM) menjawab pertanyaan metodenya: **Timbangan varian kg, Proving Ring, Bath, Picnometer, dan
Tekanan (Pressure Gauge, Vacuum Gauge, Differential Pressure)**. Yang ditahan **hanya penerbitan**.
Teknisi tetap boleh mengisi lembar, memratinjau, menyimpan, dan menghitung seperti biasa.

## 0. Aturan paling penting
1. Frontend TIDAK menghitung dan TIDAK memutuskan apa pun soal penahanan: daftar jenis alat yang
   ditahan, alasan, dan rujukan TM semuanya dari API. **Jangan** menyalin daftar tujuh jenis alat ke
   aplikasi — daftar itu akan berubah satu per satu begitu TM menjawab, tanpa rilis aplikasi.
2. **Jangan memblokir kerja teknisi.** Penahanan bukan alasan menonaktifkan tombol Simpan, Pratinjau,
   atau Kirim. Cukup beri tahu (lihat §3).
3. Jangan menyembunyikan tombol Setujui sebagai satu-satunya kontrol: server yang menolak (`422`).
   Menyembunyikan tombol hanya kenyamanan.
4. Cabangkan pada `kode` (`profil_ditahan`), bukan pada teks `message`. `alasan` dan `rujukan_tm` aman
   ditampilkan apa adanya.
5. Kunci yang tidak ada di respons (server lama) = tidak ditahan.

## 1. `penerbitan_ditahan` di respons sesi

Ada di tiap sesi: `GET /api/calibrations`, `GET /api/calibrations/{id}`, dan respons simpan
(`POST`/`PUT`). Contoh dari respons nyata (Bath, sesi menunggu approval):

```json
"penerbitan_ditahan": {
  "ditahan": true,
  "alasan": "Bath: aturan master baru (radiasi dan pembebanan nol) belum masuk, dan tanpa data standar enclosure 2026 U95 tercetak di bawah master pada 7 dari 16 baris Yokogawa/Constant. Boleh terbit dengan data standar lama dan SP6 memakai M151 atau M180 belum dijawab TM",
  "rujukan_tm": "TM-8"
}
```

Sesi yang tidak ditahan:

```json
"penerbitan_ditahan": { "ditahan": false, "alasan": null, "rujukan_tm": null }
```

- `ditahan: true` = `POST /calibrations/{id}/approve` akan dijawab `422 profil_ditahan`.
- Sesi yang sertifikatnya sudah `terbit` selalu `ditahan: false` (yang ditahan penerbitan BARU).
- **Timbangan:** hanya varian `kg` yang ditahan. Server membaca varian dari
  `spesifikasi_alat.varian_master` (atau bawaan kapasitas/satuan). Teknisi yang mengganti variannya di
  lembar bisa menggeser `ditahan` antara simpan yang satu dan berikutnya; baca ulang dari respons simpan.
- **Tekanan:** penahanannya bersyarat dan dibaca dari HASIL HITUNG tersimpan (hanya sesi yang memicu
  cacat master P-1/P-2/P-11; kalibrator SPMK tidak). Jadi `ditahan` baru `true` setelah simpan pertama;
  sebelum itu `false`. `rujukan_tm` berisi nomor pertanyaan (`P-2`, `P-1, P-2`, …).

## 2. `penerbitan_ditahan` di `GET /api/categories/{kode}` (sebelum lembar dikirim)

Tiap baris `kemampuan[]` membawa kunci yang sama ditambah `varian`:

```json
{ "nama_alat": "Timbangan (Elektronik, mekanik)", "profil": "timbangan",
  "penerbitan_ditahan": {
    "ditahan": true,
    "alasan": "Timbangan kg: U95 bagian 7 (Weighing) tercetak 0,033 kg, sedangkan master 0,043 sampai 0,053 kg (sampai 37% lebih kecil dan tidak konservatif). Paraf TM untuk butir T2 belum ada.",
    "rujukan_tm": "TM-1",
    "varian": ["kg"] } }
```

- **`varian` terisi = hanya varian itu yang ditahan.** Jangan menyimpulkan "seluruh Timbangan ditahan":
  pesan ke teknisi harus menyebut varian (mis. "Timbangan kg"). `varian: null` = seluruh jenis alat.
- Tekanan TIDAK muncul `ditahan: true` di sini (penahanannya bersyarat); pakai §1 setelah simpan.
- Baris tanpa penahanan: `{ "ditahan": false, "alasan": null, "rujukan_tm": null, "varian": null }`.

## 3. Saran tampilan (keputusan desain tetap di pemilik — jangan diubah tanpa gambar dulu)

- Lembar kerja / ringkasan kirim: satu baris peringatan non-blokir ("Sertifikat jenis alat ini ditahan
  menunggu keputusan Technical Manager (TM-1). Lembar tetap bisa diisi dan dihitung.") memakai
  `alasan` dan `rujukan_tm` dari server.
- Layar approval: bila `penerbitan_ditahan.ditahan`, tombol Setujui boleh dinonaktifkan dengan `alasan`
  yang sama. Tetap tangani `422 profil_ditahan` (di bawah) karena server yang memutuskan.

## 4. `POST /api/calibrations/{id}/approve` → `422 profil_ditahan`

```json
{
  "message": "Penerbitan sertifikat jenis alat ini ditahan menunggu keputusan Technical Manager: Proving Ring: U95 tercetak 2,3 kgf (…). Lembar tetap bisa diisi dan dihitung.",
  "kode": "profil_ditahan",
  "profil": "proving_ring",
  "varian": null,
  "rujukan_tm": "TM-4",
  "alasan": "Proving Ring: U95 tercetak 2,3 kgf (…)",
  "penahan": [ { "penyimpangan": "profil_ditahan", "pesan": "Ditahan menunggu keputusan Technical Manager (TM-4): …" } ]
}
```

- Status sesi **tidak berubah** (tetap `menunggu_approval`), nomor sertifikat yang diketik **belum
  terpakai** (boleh dipakai lagi), tidak ada sertifikat, tidak ada job.
- Diperiksa **paling awal**: sebelum pemeriksaan Order Number, nomor sertifikat, dan peringatan.
  `abaikan_peringatan: true` tidak menembusnya; peran apa pun (termasuk super admin) ditolak sama.
- `message` sudah kalimat siap tampil seperti galat `approve` lain: tampilkan apa adanya.
- Tidak ada tombol "coba lagi": ulangi hanya setelah penahanan dilepas (`penerbitan_ditahan.ditahan`
  menjadi `false` pada pembacaan berikutnya).
- Tombol Setujui panel `/admin` menolak dengan notifikasi yang sama (bukan `422`).

## 5. Sertifikat yang tersangkut `gagal` karena penahanan

Sesi yang disetujui SEBELUM penahanan dipasang dan belum sempat terbit: sesi tetap `disetujui`,
sertifikatnya `gagal` (bukan `terbit`), admin mendapat notifikasi `SertifikatGagal` berisi alasannya, dan
tombol "Terbitkan ulang" tetap ada. Menekannya sebelum penahanan dilepas akan `gagal` lagi dengan alasan
yang sama; sesudah dilepas menerbitkannya seperti biasa. Sertifikat yang SUDAH `terbit` tidak berubah.

## 6. Yang TIDAK berubah
- Bentuk lembar kerja, validasi titik, pratinjau, simpan, dan angka hasil hitung.
- `GET /me/permissions` dan izin per peran.
- Rute pelanggan (`/pelanggan/v1`): tidak membawa `penerbitan_ditahan`.

## 7. Verifikasi cepat (tanpa menyentuh data produksi)
1. Buka sesi Proving Ring / Picnometer / Bath di lingkungan uji: `penerbitan_ditahan.ditahan` harus `true`.
2. Tekan Setujui: `422` dengan `kode: "profil_ditahan"`; status sesi tetap `menunggu_approval`.
3. Buka sesi Autoklaf: `ditahan: false`, Setujui berjalan seperti biasa.
4. Buka kategori Massa: baris Timbangan membawa `varian: ["kg"]`.
