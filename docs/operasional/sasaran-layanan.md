# Sasaran layanan (SLI, SLO, error budget)

Status: **usulan awal, periode uji** (19 Nov 2026). Angka direvisi setelah baseline beban lokal (`docs/operasional/uji-beban.md`) dan beberapa pekan data nyata. Contoh angka dari panduan performa umum (20 RPS, 800 ms) bukan target Sidik.

## Konteks yang menentukan angkanya

- Satu lab, sekitar 8 akun internal; puncak mungkin 10 pengguna bersamaan. Laju permintaan rendah (satuan per detik); batasnya bukan throughput.
- Produksi di Render Free (satu instance, 0,1 CPU, 512 MB, tidur setelah 15 menit diam) dan MySQL Aiven Free (satu node; layanan gratis berakhir 21 Des 2026).
- Sertifikat PDF dirender di request approve (dompdf): median sekitar 2 detik di mesin lokal, bisa jauh lebih lama pada CPU 0,1. Klien mobile menunggu approve paling lama 60 detik.

## SLI dan SLO awal

| Sinyal (SLI) | Definisi "sukses" | Target awal (SLO), 30 hari |
| --- | --- | --- |
| Ketersediaan API | `GET /api/health` menjawab 200 dengan `status: ok` dalam 60 detik (termasuk bangun dari tidur) | 99% pengecekan |
| Approve sertifikat | Request approve selesai tanpa 5xx dan tanpa timeout; penolakan bisnis yang sah (422, termasuk `profil_ditahan`) dihitung sukses | 99% |
| Latensi approve | p95 dari request approve yang sukses | di bawah 20 detik (render PDF) |
| Simpan dan sinkron lembar | Request simpan/sync selesai tanpa 5xx | 99,5% |
| Latensi baca daftar | p95 daftar sesi, alat, sertifikat, saat instance hangat | di bawah 2 detik |
| Pemulihan data | Cadangan terbaru dapat dipulihkan | RPO sasaran 24 jam; RTO sasaran 4 jam (diuji, lihat runbook) |

Bukan SLO, melainkan aturan integritas tanpa toleransi: angka pada sertifikat sama dengan hasil hitung server; sertifikat terbit tidak berubah; nilai teknisi tidak hilang.

## Error budget

Dengan target 99% selama 30 hari, jatah ketidaktersediaan sekitar 7,2 jam per periode. Pada 1.000 request approve, jatah kegagalan 10. Bila jatah habis sebelum periode berakhir: hentikan perubahan fitur yang berisiko pada jalur itu dan perbaiki keandalan dulu.

## Cara mengukur

- Ketersediaan: workflow `Jaga server bangun` mengetuk `/up` dan `/api/health` dan gagal (surel GitHub ke pemilik) bila tidak sehat. **Peringatan jujur:** jadwal GitHub bersifat usaha terbaik; pada 18 Nov 2026 `Jaga server melek` (jadwal tiap 5 menit) dan `Jaga server bangun` (tiap 10 menit) hanya berjalan sekitar tiga kali dalam 16 jam. Jadi workflow ini BUKAN pengukur SLO dan bukan penahan tidur yang andal. Pengukur yang layak: layanan uptime gratis dengan pengecekan tiap 5 menit dan peringatan email (dirujuk di `docs/deploy-gratis-render.md`, langkah 7); butuh akun milik pemilik.
- Approve, simpan, latensi: log aplikasi dan hasil uji beban lokal; metrik produksi menyusul bila ada tempat penyimpanan gratis yang layak.
- Setiap target dicatat bersama versi (`deploy.versi` di `/api/health`), ukuran instance, dan volume data saat diukur.

## Penolakan bisnis bukan kegagalan

Pengguna di luar peran, validasi 422, `pemisahan_wewenang`, `profil_ditahan`, dan 404 lintas organisasi adalah hasil yang benar. Hitung sebagai gagal hanya 5xx, timeout, dan data yang salah tersimpan.
