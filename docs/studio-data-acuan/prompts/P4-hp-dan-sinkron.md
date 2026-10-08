# P4 — HP: versi acuan di lembar kerja + banner draf terdampak

Repo: `sidik-calibration-mobile`. Rujukan: `02-SRS.md` FR-20…23, `04-UI-UX-Flow.md` §6,
`ui-rujukan/HpLembar.dc.html`.

## Tugas

1. `lib/services/lembar_kerja_service.dart`: simpan `versi_acuan` + `ETag` per lembar; kirim
   `If-None-Match`; 304 → pakai salinan. Backend lama tanpa field → perilaku lama, tanpa error.
2. Banner draf terdampak (HP & desktop): muncul bila versi berlaku untuk `tanggal_kalibrasi` draf
   ≠ versi saat draf terakhir dihitung. Tidak menghalangi kirim. Tombol "Lihat bedanya" →
   daftar sel acuan yang berubah (dari API banding).
3. Kaki lembar menampilkan `data acuan <paket> v<n> · bentuk v<n>` dengan font angka.
4. Tarik ulang saat aplikasi kembali ke depan & saat lembar dibuka; tarikan berkala 3 menit yang
   sudah ada (§40.2) ikut meng-invalidate lembar terbuka.
5. Bila disetujui pemilik proyek (T3.4a): header `X-Versi-Klien` di `api_client.dart`.

## Test

Widget test banner (muncul/tidak muncul), test ETag 304, uji HP fisik Android untuk lembar
Micrometer (catat tipe HP & versi Android di laporan).

## Jangan

Menghitung angka di HP. Semua angka hasil tetap dari server.
