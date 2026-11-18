# Uji beban dan uji kegagalan (rencana, belum dijalankan)

Status: **rencana** (19 Nov 2026). Hasil dan baseline akan ditulis di berkas ini setelah dijalankan.

## Aturan

- Uji beban TIDAK dijalankan ke produksi atau Render: tier gratis rapuh, ada data pelanggan nyata, dan tulis ke produksi tidak dilakukan. Hanya lokal: server `php artisan serve` dengan MySQL/TiDB lokal dan akun sintetis.
- Alat: skrip Node (tanpa memasang alat baru). Pola skrip QA ada di folder kerja QA; skrip uji beban disimpan di repo di `tests/Beban/` bila sudah layak dibagikan.
- Tiap hasil mencatat: versi (`deploy.versi` atau commit), ukuran/batasan CPU dan RAM, volume data, jumlah pengguna virtual, durasi.

## Model beban

Satu lab sekitar 8 akun internal, puncak mungkin 10 pengguna bersamaan; pelanggan membaca. Jalur yang diuji: login, daftar sesi/alat, simpan lembar, kirim, approve (render PDF), baca sertifikat, sinkron pagi hari.

## Tahapan (urut risiko)

| Tahap | Isi | Yang dilihat |
| --- | --- | --- |
| Smoke | Beberapa pemanggilan tiap jalur dengan akun uji | skrip dan autentikasi jalan, data tersimpan |
| Load | 20 pengguna virtual: sinkron, simpan, approve bergantian | p95 per jalur, error, duplikasi, memori PHP |
| Spike | 10 teknisi sinkron dan simpan dalam beberapa detik | antrean, 5xx, pemulihan |
| Soak | beban wajar 1 sampai 2 jam | memori naik, koneksi bocor, latensi memburuk |
| Kegagalan | approve ganda bersamaan, retry setelah timeout (idempotensi), database mati di tengah, instance bangun dari tidur, deploy lalu rollback, restore cadangan | data tetap benar, tidak ganda, RTO dan RPO terukur |

Pembatasan CPU produksi (0,1 CPU) disimulasikan lewat pembatasan sumber daya kontainer bila diperlukan; angka lokal tanpa pembatasan hanya batas atas.

## Hasil

Belum ada.
