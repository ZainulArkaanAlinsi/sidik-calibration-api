# Perubahan API untuk sisi mobile — perbaikan QA ronde 2 (paket A)

**Berkas ini ringkas dan berdiri sendiri.** Semua perubahan di bawah ADITIF atau berupa jawaban galat yang
lebih tepat; tidak ada angka sertifikat yang berubah, dan app Lab 1.0.819 tidak patah.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `fix/qa-ronde2-a` |
| Sumber temuan | uji E2E ronde 2 (19 Nov 2026) dan uji kontrak app lawan API |
| Rincian kontrak | `docs/kontrak-api.md` (catatan 19 Nov 2026 di §0, §3, §5, §8, §11) |

## Yang perlu diketahui layar

1. **Riwayat sertifikat alat memuat `keputusan` (P-1).** `GET /api/equipments/{id}/sertifikat` sekarang mengirim
   `"keputusan": "PASS" | "FAIL" | null` di tiap baris — persis kunci dan nilai yang sudah dibaca
   `SertifikatRingkas.fromJson` (`lib/models/alat_pelanggan.dart`). `null` = jenis alat itu memang tidak divonis
   (43 dari 48 profil); **jangan menampilkan lencana** bila `null`. Nilainya keputusan sesi yang TERSIMPAN, sama
   dengan yang tampil di `GET /certificates/{id}` dan halaman verifikasi — jangan dihitung ulang di app.
2. **Peran tanpa hak selalu `403`, apa pun ID-nya (R2-7).** Dulu teknisi/viewer yang membuka rute khusus admin
   dengan ID yang TIDAK ADA mendapat `404`, sedangkan ID yang ada `403`. Sekarang keduanya `403`. Peran yang
   berhak tidak berubah (ID lab lain / tidak ada tetap `404`). Tidak perlu perubahan di app; hanya pastikan
   penanganan `403` ("Kamu nggak punya akses ke sini.") tidak mengandaikan `404` untuk ID tak dikenal.
3. **`PUT /api/calibrations/{id}` ke sesi lab lain / sesi teknisi lain = `404` (R2-5)**, bukan `422`. Antrean
   draf lokal yang menerima `404` sudah menandainya "perlu diperbaiki" (`draf_lokal_provider.dart`); perilaku itu
   tetap benar. Yang berubah hanya sesi yang BUKAN milik pemanggil (dulu `422` tentang field kosong).
4. **Angka tak terhingga / di luar rentang kolom = `422` per field (R2-8)**, bukan `500` — kini juga di
   `POST/PUT /standards` (ketidakpastian, faktor cakupan, drift, `koefisien_suhu.*`, `parameter_kondisi.*`),
   `POST/PUT /rooms` (suhu ±999.999,99), `POST /calibrations/autoclave/preview` (sama dengan simpan), dan angka
   bersarang `spesifikasi_alat.*` (mis. `micrometer.pra_evaluasi`). Isian bukan-angka di `pra_evaluasi` (`"NaN"`,
   `"abc"`) dulu DIBUANG diam-diam; sekarang `422` pada indeks kotak itu. Kotak kosong (`null`/`""`) tetap sah.
5. **`POST /standards/{id}/pulihkan` bisa `422 serial_dipakai` (R2-1)** bila nomor seri standar itu sudah dipakai
   standar aktif lain: `{"kode":"serial_dipakai","id":<id standar aktif>,"entitas":"standards","message":"…"}`.
   Tampilkan `message` apa adanya; standar tetap terhapus.
6. **`GET /api/audit-logs` (+ ekspor) tidak lagi memuat baris `akses_lintas_lab` untuk admin (R2-6).** Baris itu
   tetap tercatat; hanya super admin yang melihatnya di daftar. Layar riwayat tidak perlu berubah.

7. **Saran (aman, tanpa perubahan app yang wajib):**
   - `GET /api/calibrations/lembar-kerja?profil=<kode tak dikenal>` = `422 {"kode":"profil_tidak_dikenal"}` (dulu `200`
     dengan lembar pH). Semua kode profil yang dikirim picker app ada di server (diperiksa); klien yang mengirim
     `equipment_id` atau `instrumen` tidak terpengaruh. Layar lembar kerja sebaiknya menampilkan `message` apa adanya.
   - 404 dari `GET/PUT /calibrations/{id}` dkk. kini `{"message":"Data yang dicari nggak ketemu."}` (dulu
     `No query results for model [App\Models\…]` yang tampil di antrean draf). Statusnya tetap `404`; jangan mencocokkan teksnya.
   - `POST/PUT /orders` dengan alat ganda = `422` pada `items.N.equipment_id` (dulu `500`).
   - `GET /users?search=` membaca `%`/`_` harfiah; `GET /penugasan/1abc` = `404`.
   - Password baru maksimal 72 byte: `POST /users` dan reset password admin menjawab `422` pada `password`.

## Tidak ada perubahan di app yang WAJIB

Semua poin di atas kompatibel mundur. Poin 1 hanya membuat lencana PASS/FAIL yang sudah ada di
`riwayat_sertifikat_alat_screen.dart` mulai terisi di HP asli.
