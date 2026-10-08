# Studio Data Acuan — "Excel kedua" di laptop admin

Paket dokumen perubahan besar, ditulis 8 Okt 2026. Status: **usulan, belum ada satu baris kode.**

Ide pemilik proyek (8 Okt 2026), dibahasakan ulang: setiap alat lahir dari workbook Excel master.
Data kalibrasi (nilai standar, koreksi, pita CMC, tabel, bentuk lembar) sering berubah. Sekarang
tiap perubahan berarti developer menjalankan skrip generator, menyunting JSON/PHP, test, lalu
deploy. Pemilik ingin **satu layar di laptop/PC yang rasanya seperti Excel**, tempat data itu
diubah langsung, dan begitu disahkan **semua HP dan perangkat lain ikut memakai data baru**
tanpa install ulang apa pun.

Paket ini menjawab ide itu sejauh yang aman untuk lab terakreditasi SNI ISO/IEC 17025:2017,
memakai aturan yang sudah tertulis di `AGENTS.md §Olah data — aturan keras`.

## Urutan baca

| No | Berkas | Isi |
|---|---|---|
| 0 | [00-CR-Impact-Analysis.md](00-CR-Impact-Analysis.md) | Apa yang berubah, kenapa, efek ke DB/API/izin/data lama/app lama |
| 1 | [01-PRD.md](01-PRD.md) | Masalah, tujuan, persona, user story |
| 2 | [02-SRS.md](02-SRS.md) | FR ber-ID, kriteria terima, aturan bisnis, matriks izin, transisi status |
| 3 | [03-SDD-ADR.md](03-SDD-ADR.md) | ERD, migrasi, kontrak API, ADR-01…ADR-09 |
| 4 | [04-UI-UX-Flow.md](04-UI-UX-Flow.md) | Layar baru/ubah, semua state |
| 5 | [05-Task-Breakdown.md](05-Task-Breakdown.md) | Tugas + DoD, estimasi, dependensi, owner |
| 6 | [06-Test-Plan.md](06-Test-Plan.md) | FR ↔ test case, uji pembanding Excel, regresi |
| 7 | [07-Migrasi-Rollout.md](07-Migrasi-Rollout.md) | Backup, urutan deploy, rollback, kompatibilitas app lama |
| 8 | [08-Changelog.md](08-Changelog.md) | Satu untuk tim, satu untuk pengguna |
| A | [Lampiran-A-Inventaris-Master.md](Lampiran-A-Inventaris-Master.md) | 46 folder master × sheet × profil × tabel acuan × formulir |

## Tiga kalimat yang harus dipegang

1. **Yang bisa diubah dari Studio adalah DATA (lapis 1) dan BENTUK LEMBAR (lapis 3).** Rumus
   (lapis 2) tetap kode + test rekonsiliasi master; Studio menampilkannya baca-saja lengkap dengan
   asal selnya, dan menyediakan jalur "usul perubahan rumus" yang berakhir di PR.
2. **Tidak ada perubahan yang berlaku sebelum disimulasikan dan disahkan orang kedua.** Simulasi
   menunjukkan sesi mana yang angka cetaknya bergeser; pengesah ≠ penyunting.
3. **Server tetap satu-satunya database.** "Khusus laptop" berarti layar penyuntingnya hanya di
   panel desktop; datanya tetap di server yang sama yang dibaca HP, jadi begitu versi aktif
   semua perangkat ikut.

## Keputusan yang ditunggu (default dipakai kalau belum dijawab)

| ID | Pertanyaan | Default |
|---|---|---|
| K-48-01 | Apakah lapis 2 (rumus) boleh diubah dari UI di masa depan, lewat mesin ekspresi + gerbang uji bayangan? Ini **mengubah aturan keras AGENTS.md**. | **Tidak.** Fase 1–3 tanpa itu. |
| K-48-02 | Alat pilot | **Micrometer** (alasan di 00 §5). Rencana 30 Sep memakai pH — lihat 00 §5. |
| K-48-03 | Siapa pengesah versi data acuan | `super_admin`, bukan penyunting/pengaju |
| K-48-04 | Versi baru berlaku menurut apa | `tanggal_kalibrasi` sesi (sama dengan `RumusKalibrasi` hari ini) |
| K-48-05 | Boleh tidaknya Master Data menyunting tanpa mengajukan (draf pribadi) | Boleh; draf tidak berpengaruh ke apa pun |
| K-48-06 | Teknisi boleh melihat tabel acuan aktif | Boleh, baca saja, tanpa riwayat draf |
| K-48-07 | Toleransi uji pembanding per alat | Ditetapkan Lab per alat (06 §3); belum diisi = alat tidak boleh "selesai" |
