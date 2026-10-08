# P2 — Alur versi di server: draf → simulasi → ajukan → sahkan

Repo: `sidik-calibration-api`. Rujukan: `02-SRS.md` FR-05…FR-22, BR-01…BR-13, §3 status, §4 izin;
`03-SDD-ADR.md` §3, ADR-05, ADR-09; `05-Task-Breakdown.md` T2.1–T2.4.
Skill: `[[sidik-api-scaffolder]]`, `[[sidik-query-organisasi]]`, `[[sidik-test-verifier]]`.

## Gerbang masuk

P1 hijau di kedua suite. Alat yang boleh disentuh: hanya Micrometer.

## Tugas

1. **Draf & sunting** (FR-05…09): `POST /data-acuan/paket/{kode}/draf`;
   `PATCH /data-acuan/versi/{versi}/sel` dengan `If-Match: lock_versi`, validasi per kolom dari
   `skemaAcuan()` (tipe, batas, kunci unik toleransi 1e-9, kolom wajib, **sel kosong tidak pernah
   dibaca nol**), parser angka BR-04 (koma/titik diterima, pemisah ribuan ditolak). Jejak tiap sel
   ke `paket_acuan_suntingan`. Tambah/hapus baris menolak `baris_dipakai_titik`.
2. **Simulasi** (FR-12/13): job antrean menghitung ulang SEMUA sesi profil itu (+ profil lain yang
   membaca kelas tabel yang sama — lihat KARTU "dibaca juga oleh") dan semua fixture master,
   **tanpa menyimpan**. Angka cetak dihitung dengan `desimalSertifikat`/`desimalU95`/
   `desimalFaktorCakupan` profil. Suntingan sesudah simulasi → `kedaluwarsa`.
3. **Ajukan / sahkan / tolak / kembalikan / tarik** (FR-15…19) sesuai tabel transisi 02 §3.
   Rute sahkan/tolak/kembalikan/tarik di grup `role:super_admin` saudara (pola §40), **tanpa**
   melonggarkan `lolosBacaSuperAdmin`. `PemisahanWewenang::bolehMengesahkanVersiAcuan()` menolak
   pembuat/penyunting/pengaju/pengunggah (`pengesah_ikut_menyunting`). Sandi dikonfirmasi.
   Rentang ditutup dalam SATU transaksi; berlaku mundur ditolak bila menyentuh sertifikat terbit.
4. **Sinyal & jadwal** (FR-22, FR-29): `PerubahanDataOrganisasi::siarkanAman($org, 'data_acuan', ...)`;
   scheduler harian mengaktifkan versi `terjadwal` dan mengirim pengingat standar H-30/7/1.
5. Semua rute tulis ber-`throttle:`. Perbarui `docs/kontrak-api.md`.

## Test wajib

Semua kasus 06 §1 untuk FR-05…FR-22 + `PemisahanWewenangAcuanTest` + regresi 06 §2.

## Jangan

UI; alat selain Micrometer; mengubah rumus; mengaktifkan versi tanpa simulasi lewat jalur apa pun
(termasuk seeder, tinker, Filament).

## Selesai bila

Alur penuh draf→sah berjalan di test dengan contoh suntingan (balok 1,1 / 2,5 / 6,0 mm) dan
laporan simulasi menyebut titik terdampak yang sama dengan `ui-rujukan/Simulasi.dc.html`
(20 nilai standar berubah, A 5,1 & B 45,2 tetap). Kedua suite hijau.
