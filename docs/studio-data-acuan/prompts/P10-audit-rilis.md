# P10 — Audit sebelum dinyalakan di produksi

Jalankan sebelum tahap 4–5 rollout (`07-Migrasi-Rollout.md` §2) untuk setiap gelombang alat.

## Periksa & laporkan (jangan memperbaiki sambil mengaudit — catat dulu)

1. Kedua suite (SQLite & MySQL) hijau di commit yang akan dirilis; `flutter test` hijau.
2. `validasi_katalog.py` 100% untuk paket yang dirilis; nol drift.
3. `TabelAcuanSetaraJsonTest` hijau untuk tiap paket yang dirilis.
4. Izin: matriks 02 §4 diuji lewat HTTP; organisasi lain → 404; super admin hanya rute yang ditentukan.
5. Pemisahan wewenang: test pengesah = penyunting ditolak, termasuk super admin.
6. Tidak ada `private static ?array $data` tersisa di kelas `Tabel*` yang sudah dipindah.
7. `DATA_ACUAN_SUMBER` ada di `.env.example` DAN `render.yaml` dengan `value:` eksplisit.
8. Tidak ada nama/alamat pelanggan di berkas baru (`git diff main --stat` lalu sapu isinya).
9. Sensus baca-saja produksi (izin sekali, sebut tabel & perkiraan baris dulu, aturan
   `docs/aturan-akses-database.md`): jumlah `uncertainty_calculations` tanpa stempel acuan untuk
   profil yang sudah dipindah harus 0.
10. Cadangan produksi terverifikasi bisa dipulihkan (07 §1).

Laporan berupa daftar centang dengan bukti (perintah + keluaran). Keputusan menyalakan ada di
pemilik proyek, bukan di prompt ini.
