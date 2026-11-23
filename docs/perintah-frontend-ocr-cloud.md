# Perintah frontend — OCR awan (Google Cloud Vision)

Tanggal: 23 Nov 2026. Status: **backend siap, bawaan MATI. Mobile belum disambung (tahap 2).**
Kontrak rinci: `docs/kontrak-api.md` bagian 22. Aturan: `AGENTS.md` §OCR awan.

## Yang berlaku untuk klien

1. **Foto dikirim ke BACKEND, tidak pernah langsung ke Google.** Tidak ada kunci, kredensial, atau URL Google
   di aplikasi atau APK.
2. **Sakelar klien bawaan MATI** (`--dart-define`, mis. `PAKAI_OCR_CLOUD`, nama final ditentukan di tahap 2).
   Pembaca utama tetap yang sudah ada (ML Kit / mesin angka). Server punya dua gerbang sendiri; klien yang
   menyala belum berarti server menjawab.
3. **Jatuh ke pembaca yang ada tanpa crash** pada `503`, `502`, `429`, `422`, waktu habis, atau tanpa jaringan.
   Respons galat membawa `fallback_manual: true`. Sel yang terdampak ditandai **perlu dicek**.
4. **Hasilnya USULAN.** Sel kosong tetap kosong; tulisan tangan tidak pernah hijau; teknisi mengonfirmasi.
   `keyakinan` dari Google bukan persen kebenaran angka.
5. **Satu foto halaman = satu permintaan.** Jangan memanggil per sel. Batas per lembar (bawaan 5 per sesi,
   hanya panggilan berhasil yang dihitung, dan tidak dikunci: permintaan bersamaan bisa melewatinya sebanyak yang berebut)
   dan 6 permintaan per menit per akun. Setelah 3 kegagalan Google untuk satu sesi (total, bukan beruntun; bawaan
   `GOOGLE_VISION_MAKS_GAGAL_PER_SESI`) server menjawab `429 gagal_berulang_per_lembar` tanpa menghubungi Google:
   **berhenti memanggil endpoint ini untuk lembar itu** dan pakai pembaca yang ada (batas kegagalan ini juga tidak dikunci,
   jadi permintaan bersamaan bisa melewatinya). Foto di atas `GOOGLE_VISION_MAKS_BYTE` (6 MB) dijawab `422 foto_terlalu_besar`;
   kecilkan dulu di HP. `message` galat adalah kalimat tetap per `kode`; tampilkan apa adanya, jangan mengurai isinya.
6. `halaman` di respons sukses bisa **`null`** (halaman tanpa teks yang tidak diberi ukuran oleh Google): jangan
   mengandalkan `halaman.lebar`/`tinggi` tanpa memeriksa `null`.
7. Koordinat `kotak` dinormalkan 0..1 terhadap halaman. Pemetaan teks ke sel (memakai templat
   `database/ocr-templates/` dan registrasi halaman) adalah pekerjaan tahap 2 dan belum ada.
8. **Pemetaan dikunci per versi formulir** (syarat pemilik, 23 Nov 2026): satu pemetaan hanya untuk satu
   versi/revisi cetak, tidak pernah dipakai lintas versi. Klien wajib mengirim `template_versi` (sudah
   diwajibkan `POST /worksheet-scans`; aplikasi lama yang tidak mengirimnya ditolak `422`). Foto dari versi
   yang belum terverifikasi: perilaku penolakannya menunggu keputusan pemilik (lihat laporan tahap 1).

## Belum dikerjakan (tahap 2, menunggu persetujuan)

- Pemetaan blok/kata ke sel formulir dan status "ragu" per sel.
- Implementasi `PembacaSel` untuk cloud + sakelar klien + tes fallback.
- Skrip evaluasi dan `docs/ocr-lessons.md`: butuh foto uji nyata berlabel manusia.

## Variabel lingkungan server (nilai asli TIDAK di repo)

`GOOGLE_VISION_AKTIF` (bawaan `false`), `GOOGLE_VISION_KREDENSIAL` (JSON service account mentah atau base64),
`GOOGLE_VISION_BASE_URL`, `GOOGLE_VISION_TIMEOUT` (30), `GOOGLE_VISION_MAKS_BYTE` (6291456),
`GOOGLE_VISION_MAKS_PER_SESI` (5), `GOOGLE_VISION_MAKS_GAGAL_PER_SESI` (3). Dua yang pertama sudah ada di `render.yaml`; `.env.example` BELUM diperbarui
(lihat laporan: berkas itu tidak bisa dibaca sesi ini).
