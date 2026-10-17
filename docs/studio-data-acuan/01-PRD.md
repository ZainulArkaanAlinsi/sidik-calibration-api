# 01 — PRD Studio Data Acuan

## 1. Masalah

1. **Data acuan berubah lebih sering daripada kode.** Standar direkalibrasi tiap tahun, CMC
   diperbarui, lembar kertas naik revisi. Tiap kali, hanya developer yang bisa memindahkannya
   ke sistem (generator → JSON → commit → deploy).
2. **Jejak keputusan ada di git, bukan di sistem.** Auditor dan Technical Manager tidak bisa
   melihat dari aplikasi: nilai koreksi balok ukur 25 mm berubah kapan, dari berapa ke berapa,
   atas dasar sertifikat nomor berapa, disahkan siapa.
3. **Excel tidak memberi tahu apa yang ikut berubah.** Mengganti satu sel di workbook menggeser
   semua hasil tanpa laporan. Dua kesalahan workbook lab lolos bertahun-tahun persis karena itu
   (`pertanyaan-lab-hydrometer.md` §7 dan §14).
4. **Tiap alat bentuknya beda.** Satu form generik "parameter: nilai" tidak akan dipakai orang lab,
   karena mereka berpikir dalam sheet workbook masing-masing alat.

## 2. Tujuan

| ID | Tujuan | Ukuran berhasil |
|---|---|---|
| G1 | Master Data bisa memperbarui data acuan tanpa developer | Rekalibrasi standar Micrometer selesai dari layar: draf → simulasi → sah, < 30 menit, nol commit |
| G2 | Nol angka bergeser diam-diam | Setiap versi aktif punya laporan simulasi; uji pembanding 100% hijau untuk alat pilot |
| G3 | Semua perangkat memakai versi yang sama | Sesudah sah, HP yang membuka lembar menerima `versi_acuan` baru ≤ 3 menit (≤ 5 detik bila realtime nyala) |
| G4 | Terasa seperti Excel milik lab | Tab Studio per alat sama dengan sheet acuan workbooknya; tempel dari Excel jalan; angka koma Indonesia dibaca benar |
| G5 | Jejak terbaca tanpa membuka kode | Tiap sel aktif bisa ditelusuri: asal (workbook/sheet/sel atau sertifikat standar), versi, penyunting, pengesah |

**Bukan tujuan:** mengubah rumus dari UI (lapis 2), database lokal di laptop, mengganti panel
Filament, aplikasi pelanggan.

## 3. Persona

| Persona | Peran sistem | Kebutuhan |
|---|---|---|
| **Rina, Master Data** (±5 orang) | `admin` | Menyalin nilai sertifikat standar baru ke tabel yang benar, melihat dampaknya, mengajukan |
| **Bu Sari, pengesah (Technical Manager)** — nama fiktif | `super_admin` | Membaca beda lama-baru dan laporan dampak, mengesahkan atau menolak dengan alasan |
| **Dimas, teknisi lapangan** | `teknisi` | Lembar yang dia buka selalu memakai data terbaru; diberi tahu kalau draf yang sedang dia isi terdampak |
| **Auditor KAN / internal** | `viewer` | Menelusuri angka sertifikat ke versi data acuan dan sumbernya |
| **Developer (Zainul)** | — | Lapis 2 tetap di kode; Studio memberinya usulan rumus yang terstruktur, bukan WA |

## 4. User story

| ID | Sebagai | Saya ingin | Supaya |
|---|---|---|---|
| US-01 | Master Data | membuka alat Micrometer dan melihat tab yang sama dengan sheet acuan workbooknya (`Standar_GB`, pita CMC, konstanta) | saya tidak perlu belajar bentuk baru |
| US-02 | Master Data | membuat draf dari versi aktif dan menyunting sel langsung di grid | perubahan kecil cepat |
| US-03 | Master Data | menempel blok dari Excel/sertifikat (koma desimal) | tidak mengetik ulang 32 baris |
| US-04 | Master Data | mengunggah workbook master revisi dan sistem mengisi draf dari sel yang dipetakan | pembaruan besar tidak manual |
| US-05 | Master Data | melihat tiap sel yang saya ubah disorot dengan nilai lamanya | saya yakin tidak salah tempel |
| US-06 | Master Data | menjalankan simulasi dan melihat sesi mana yang angka **cetaknya** berubah | saya tahu akibatnya sebelum mengajukan |
| US-07 | Master Data | mengajukan dengan alasan dan rujukan (no. sertifikat standar, IK, halaman lampiran) | pengesah punya dasar |
| US-08 | Pengesah | membaca beda + simulasi + rujukan di satu layar, lalu sahkan atau tolak dengan alasan | keputusan cepat dan tercatat |
| US-09 | Pengesah | menjadwalkan berlaku mulai tanggal tertentu | rekalibrasi standar berlaku sejak sertifikat baru, bukan hari ini |
| US-10 | Teknisi | diberi tahu di draf saya bila data acuan berubah | saya tidak kaget angkanya bergeser |
| US-11 | Auditor | dari sertifikat, membuka versi data acuan + asal tiap nilai | ketertelusuran terbukti |
| US-12 | Master Data | mengubah bentuk lembar (label, urutan, jumlah titik dalam batas) | lembar sama dengan kertas Rev. terbaru |
| US-13 | Master Data | melihat rumus (baca saja) yang memakai sel yang saya sunting | saya paham sel ini dipakai di mana |
| US-14 | Master Data | mengirim usulan perubahan rumus ke developer, lengkap dengan sel workbook | perubahan rumus tidak lewat chat |
| US-15 | Siapa pun di lab | diingatkan standar yang tanggal berlakunya mendekati habis | rekalibrasi tidak telat |

## 5. Lingkup per fase

| Fase | Isi | Alat |
|---|---|---|
| 1 | Fondasi: tabel, versi 1 dari JSON, stempel, `TabelStandar*` baca dari versi, API baca | 19 paket ber-JSON |
| 2 | Studio desktop: grid, draf, diff, simulasi, ajukan/sahkan, sinyal ke perangkat | **Micrometer** (pilot) |
| 3 | Uji pembanding di UI, unggah workbook (F1), editor bentuk lembar | Micrometer → Height Gauge, Dial Indicator, Jangka Sorong, Timbangan |
| 4 | Ekstraksi konstanta PHP ke paket | pH, lalu 9 profil analitik lain + Hydrometer |
| 5 (bersyarat K-48-01) | Mesin ekspresi + uji bayangan untuk lapis 2 | — |

## 6. Kriteria rilis fase 2

- Versi 1 paket Micrometer sha256-nya sama dengan JSON kanonik hari ini, dan seluruh test
  Micrometer hijau tanpa diubah.
- Satu rekalibrasi standar nyata (data dari Lab) berhasil dijalankan end-to-end di staging.
- Pengesah ≠ penyunting ditegakkan server (test).
- Suite SQLite **dan** MySQL hijau (AGENTS.md §Test).
