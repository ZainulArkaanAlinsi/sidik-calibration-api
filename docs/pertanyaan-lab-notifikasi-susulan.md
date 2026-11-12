# Pertanyaan produk — kabar susulan untuk anggota pelanggan yang bergabung belakangan

> 12 Nov 2026. Keputusan pekerjaan didelegasikan pemilik ("pilih yang paling aman"), jadi
> yang dibangun adalah versi MINIMAL yang aman (lihat `docs/kontrak-api-pelanggan.md`, bagian
> "Kabar susulan untuk anggota yang bergabung SESUDAH sertifikat terbit"). Pertanyaan di bawah
> bukan penghalang; masing-masing punya bawaan yang sudah dipakai. Jawaban pemilik tinggal
> mengubah satu tempat (`KabarSertifikatUntukAnggotaBaru`).

## Yang sudah diputuskan (bawaan)

- Anggota yang diundang/diaktifkan SETELAH sertifikat terbit **sudah bisa melihat** sertifikat
  perusahaannya: isolasi di API pelanggan per `customer_id` (dari `KonteksPerusahaan`), tidak
  per tanggal masuk. Tidak ada yang diubah di sisi ini; hanya dibuktikan oleh test.
- Kabarnya **satu notifikasi ringkas** (jumlah sertifikat yang berlaku), bukan satu per sertifikat.
- Menghormati saklar `status_permintaan`; isolasi perusahaan/organisasi sama dengan kabar "terbit".

## S1 — Sekali seumur, atau tiap kali bergabung kembali?

Bawaan sekarang: **sekali per akun per perusahaan** (`tanda_tangan` memuat `customer_id`). Anggota
yang dinonaktifkan lalu diundang kembali setelah berbulan-bulan TIDAK dikabari lagi, walau sudah
ada sertifikat baru yang terbit saat dia di luar. Alternatif: kabar tiap kali bergabung kembali,
dengan jumlah sertifikat yang terbit sejak dia dinonaktifkan. Mana yang diinginkan?

## S2 — Perlu tautan ke daftar sertifikat?

Bawaan: `tautan = null` (buka beranda), karena app pelanggan hanya memetakan
`pelanggan_sertifikat` + id satu sertifikat ke layar detail; tipe tautan baru butuh perubahan di
aplikasi pelanggan. Kalau diinginkan "ketuk → daftar sertifikat", app perlu memetakan satu tipe
tautan baru lebih dulu (perubahan API aditif menyusul setelah app siap).

## S3 — Sertifikat yang dihitung

Bawaan: hanya yang `terbit` dan belum digantikan revisi (yang dibatalkan tidak dihitung, karena
bukan dokumen yang berlaku). Perlu menyebut yang dibatalkan/digantikan? Bawaan menganggap tidak.

## S4 — Pemberitahuan ke PIC utama?

`AnggotaBaruBergabung` (PIC utama dikabari ada anggota baru) sudah ada di jalur persetujuan
pengajuan lama, tetapi TIDAK dikirim di jalur undangan. Itu di luar cakupan perubahan ini dan
tidak disentuh; ditulis di sini supaya tidak ditemukan ulang sebagai temuan baru.
