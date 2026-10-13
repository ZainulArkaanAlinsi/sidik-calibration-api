# 04 — UI/UX Flow Sidik Studio

Acuan perilaku layar. Belum ada mockup visual; token desain di §5 diambil dari kode Studio yang
sedang dibangun di S0b (`studio/lib/tema/*.dart` worktree mobile, belum commit — bisa berubah).
Klaim tampilan di dokumen ini **belum diverifikasi di perangkat**; verifikasi visual menjadi DoD
tugas S1–S5.

Pengguna utama: Master Data dan pengesah di meja kerja (Windows, jarak pandang ±60 cm, mouse +
keyboard, kerja panjang) dan di lab (tablet, jari, berdiri atau duduk, sesi pendek). Tugas paling
sering: menjelajah dan membetulkan data, meninjau angka, mengesahkan. Tugas paling berisiko:
menyunting rumus dan mengesahkan versi.

Arah visual: **alat kerja padat mirip spreadsheet**, permukaan rata, pemisah garis 1 px, angka
dengan font tabular, amber sebagai penanda (bukan dekorasi). Berbeda sengaja dari aplikasi
lapangan (K-49-01). Tanpa gradasi, tanpa kartu besar beranimasi, tanpa ikon emoji.

---

## 1. Daftar layar

| Kode | Layar | Modul | Peran yang melihat | Tahap |
|---|---|---|---|---|
| ST-01 | Masuk | Auth | semua internal | S1 |
| ST-02 | Beranda | Cangkang | semua | S1 (kartu bertambah tiap tahap) |
| ST-10 | Kelola Data — Daftar Tabel | Kelola Data | admin, viewer, SA (teknisi Q-49-04) | S2 |
| ST-11 | Kelola Data — Grid Tabel | Kelola Data | idem | S2 |
| ST-12 | Detail Baris | Kelola Data | idem | S2 |
| ST-13 | Riwayat Baris | Kelola Data | idem | S2 |
| ST-14 | Form Sunting / Tambah | Kelola Data | admin | S2 |
| ST-15 | Konfirmasi Arsip / Pulihkan | Kelola Data | admin | S2 |
| ST-16 | Ekspor CSV | Kelola Data | admin (Q-49-05) | S2 |
| ST-17 | Koreksi Pembacaan | Kelola Data | admin | S2 |
| ST-20 | Data Acuan — Daftar Paket | Data Acuan | semua (teknisi: aktif saja) | S1 (pindah M2) |
| ST-21 | Data Acuan — Studio Grid (aktif / draf) | Data Acuan | baca semua; sunting admin | S1 baca, S3 sunting |
| ST-22 | Banding Versi Acuan | Data Acuan | bukan teknisi | S1 |
| ST-23 | Riwayat Sel & Garis Waktu | Data Acuan | bukan teknisi | S1 |
| ST-24 | Ajukan (dialog) | Data Acuan & Lembar Hitung | admin | S3, S5 |
| ST-30 | Lembar Hitung — Daftar Alat + status paritas | Lembar Hitung | admin, viewer, SA | S4 (baca), S5 |
| ST-31 | Editor Lembar Hitung | Lembar Hitung | baca semua; sunting admin (Q-49-01) | S5 |
| ST-32 | Panel Named Range | Lembar Hitung | idem | S5 |
| ST-33 | Panel Jejak Ketergantungan | Lembar Hitung | idem | S5 |
| ST-34 | Panel Pengikat | Lembar Hitung | idem | S5 |
| ST-35 | Pratinjau Sesi | Lembar Hitung | admin | S5 |
| ST-36 | Banding Versi Lembar Hitung | Lembar Hitung | admin, viewer, SA | S5 |
| ST-37 | Laporan Paritas | Lembar Hitung | admin, viewer, SA | S4 |
| ST-38 | Log Metode | Lembar Hitung | admin, viewer, SA | S5 |
| ST-40 | Laporan Simulasi | bersama | admin, viewer, SA | S3 |
| ST-50 | Pengesahan — Antrean | Pengesahan | SA | S3 |
| ST-51 | Pengesahan — Keputusan | Pengesahan | SA | S3, S5 |
| ST-60 | Impor `.xlsm` — Unggah | Impor | admin | S5 |
| ST-61 | Impor `.xlsm` — Laporan | Impor | admin, viewer, SA | S5 |
| ST-70 | Pengaturan | Cangkang | semua | S1 |
| ST-90 | Keadaan global (offline, sesi berakhir, tanpa izin, konflik, simpan gagal, galat) | bersama | semua | S1 |

"SA" = `super_admin`. Peran yang "melihat" hanyalah kenyamanan; server tetap menolak
(`02-SRS.md` §4).

## 2. Cangkang dan tata letak

### 2.1 Tiga lebar

| Lebar | Perangkat | Navigasi | Area kerja | Inspektur / detail |
|---|---|---|---|---|
| ≥ 1280 dp | Windows (jendela dimaksimalkan atau ≥ 1280) | sidebar tetap 240 dp, label penuh, lencana jumlah | kolom tengah lentur | panel kanan 320 dp, bisa dilipat (`Ctrl+.`) |
| ±1138 dp | tablet lanskap (perangkat uji 1600×2560 px) | rail 72 dp: ikon + label pendek | kolom tengah | lembar samping 320 dp di atas konten, dibuka tombol, tutup dengan geser |
| ±711 dp | tablet potret | rail 56 dp ikon saja (label di tekan-lama) | penuh | bottom sheet 50%–90% tinggi, bisa ditarik |
| < 1024 dp di Windows | jendela sempit | sama dengan tablet lanskap | | |

Bilah atas 48 dp (`UkuranStudio.tinggiBilah`): jejak navigasi (mis. "Lembar Hitung › Pipet Volume ›
Draf v2"), status simpan, tombol aksi utama layar. Bilah status bawah 24 dp (Windows) berisi server,
koneksi, realtime, pengguna + label peran, versi mesin rumus. Di tablet bilah status dilipat ke menu
akun.

### 2.2 Seksi

Urutan sidebar: Beranda · Kelola Data · Data Acuan · Lembar Hitung · Pengesahan (SA) · Impor ·
Pengaturan. Seksi yang tahapnya belum mendarat tampil abu-abu dengan label tahap ("S2") dan tidak
bisa dibuka (pola `studio/lib/layar/seksi_studio.dart`). Seksi tanpa izin tidak tampil.

### 2.3 Pintasan global (Windows)

`Alt+1…7` seksi · `Ctrl+F` cari di layar · `F5` muat ulang · `Esc` tutup panel/dialog ·
`Ctrl+,` Pengaturan · `Ctrl+.` lipat/buka inspektur · `F1` bantuan pintasan layar aktif.

## 3. Keadaan yang berlaku di semua layar (ST-90)

Setiap layar di §4 merujuk tabel ini dan hanya menulis yang khusus.

| Keadaan | Tampilan | Aksi pengguna | Data |
|---|---|---|---|
| **Memuat** | kerangka bentuk konten (baris abu-abu setinggi baris grid), bukan spinner layar penuh; > 8 s → teks "masih memuat…" + Batal | Batal | — |
| **Kosong** | kalimat yang menyebut kenapa kosong dan langkah berikutnya (khusus per layar) | tombol langkah berikutnya bila berizin | — |
| **Galat** | panel galat di area konten: kalimat manusia + `kode` server kecil + Coba lagi; tanpa jejak tumpukan | Coba lagi, Salin rincian | isian pengguna tetap |
| **Tanpa izin** | layar "Butuh izin `<nama izin>`. Minta ke admin lab." bila dibuka lewat tautan; tombol yang tidak diizinkan tidak tampil | Kembali | — |
| **Offline** | pita kuning di bawah bilah atas "Offline sejak 10.31 — hanya baca. Perubahan tidak bisa disimpan."; tombol tulis nonaktif dengan tooltip alasan | — | cache baca (NFR-STU-08) |
| **Konflik 409** | dialog: tabel per kolom/sel "punya Anda / di server / diubah oleh, kapan" | Muat ulang (isian saya tetap tampil sebagai catatan di dialog), Batal | tidak ada yang dikirim |
| **Simpan gagal** | sel/kolom bergaris merah + ikon; bilah atas "2 perubahan belum tersimpan"; toast menyebut alasan | Coba simpan lagi, Buang perubahan ini | isian tetap di layar |
| **Sesi kedaluwarsa** | dialog modal "Sesi berakhir, masuk lagi" di atas layar yang sama | Masuk (kembali ke layar ini) | isian tetap; draf server sudah tersimpan otomatis |
| **Throttle 429** | toast "Terlalu banyak permintaan, coba lagi sebentar" + hitung mundur bila server memberi `Retry-After` | — | — |
| **Versi Studio usang (426)** | layar "Versi Studio ini terlalu lama untuk server. Pasang versi terbaru." | — | — |

### 3.1 Matriks keadaan per layar

`G` = pola global §3 tanpa tambahan; teks = perilaku khusus layar itu (rinci di §4); `—` = tidak
berlaku (layar tidak menulis atau tidak bisa kosong).

| Layar | Memuat | Kosong | Galat | Tanpa izin | Offline | Konflik 409 | Simpan gagal | Sesi kedaluwarsa |
|---|---|---|---|---|---|---|---|---|
| ST-01 | tombol berputar | — | pesan di bawah isian | akun pelanggan ditolak | "tidak ada koneksi ke server" | — | — | — |
| ST-02 | kerangka kartu | per kartu | per kartu, kartu lain tetap | kartu disembunyikan | cache + pita | — | — | G |
| ST-10 | G | — | G | G | cache | — | — | G |
| ST-11 | baris kerangka | dengan/tanpa saringan | G | G | cache halaman terakhir (tanpa data pribadi, NFR-STU-08) | — | — | G |
| ST-12 | G | 404 "tidak ditemukan" | G | tombol ubah tidak ada | baca | — | — | G |
| ST-13 | G | "belum ada perubahan tercatat" | G | G | cache | — | — | G |
| ST-14 | — | — | per isian | tidak bisa dibuka | tombol Simpan nonaktif | dialog per kolom | isian tetap + Coba lagi | dialog, isian tetap |
| ST-15 | — | — | penolakan domain | tidak tampil | nonaktif | dialog | G | G |
| ST-16 | — | 0 baris → tombol nonaktif | G | tidak tampil | nonaktif | — | unduhan gagal → Coba lagi | G |
| ST-17 | G | — | per sel | tidak tampil | nonaktif | dialog per titik | sel merah + Coba lagi | dialog, isian tetap |
| ST-20 | kerangka kartu | "belum ada paket" | G | G | cache | — | — | G |
| ST-21 | grid kerangka | "lembar belum berisi data" | sel merah | baca saja | baca saja, ketik ditolak | `draf_berubah` per sel | sel merah + bilah "n belum tersimpan" | dialog, batch tersimpan aman |
| ST-22 | G | "tidak ada perbedaan" | G | teknisi: tidak tersedia | cache | — | — | G |
| ST-23 | G | "belum pernah berubah" | G | teknisi: tidak tersedia | cache | — | — | G |
| ST-24 | — | — | per isian | tidak tampil | nonaktif | versi berubah → muat ulang | isian tetap | dialog, isian tetap |
| ST-30 | baris kerangka | "masih dihitung profil PHP" per alat | G | G | cache | — | — | G |
| ST-31 | grid kerangka | sheet kosong: "sheet ini belum berisi sel" | sel bergaris, galat di bilah rumus | baca saja | baca saja dari cache isi | dialog per sel | sel merah + bilah "n belum tersimpan" | dialog, batch tersimpan aman |
| ST-32 | — | "belum ada nama" | G | baca saja | baca | ikut ST-31 | ikut ST-31 | G |
| ST-33 | G | "konstanta atau masukan" / "tidak dipakai keluaran" | G | G | dari cache graf | — | — | G |
| ST-34 | G | "belum ada pengikat" | `kontrak_input_dilanggar` | baca saja | baca | dialog | baris merah | G |
| ST-35 | progres | "belum ada sesi tersimpan" | `pratinjau_terlalu_berat` | tidak tampil | nonaktif | — | — | G |
| ST-36 | G | "isi identik" | G | G | cache | — | — | G |
| ST-37 | progres n/N | "belum pernah dijalankan" | job gagal + sesi penyebab | G | hasil terakhir | — | — | G |
| ST-38 | G | "masih versi kode" | G | G | cache | — | — | G |
| ST-40 | progres n/N | "hanya vektor master" | job gagal + sesi penyebab | G | hasil terakhir | — | — | G |
| ST-50 | G | "tidak ada yang menunggu" | G | bukan SA: tidak tampil | cache, tombol nonaktif | — | — | G |
| ST-51 | G | — | `pengesah_ikut_menyunting` kartu merah | bukan SA: tidak tampil | nonaktif | sudah diputuskan SA lain | keputusan tidak terkirim + Coba lagi | dialog, belum ada yang diputuskan |
| ST-60 | unggah berprogres | — | sandi salah / bukan OOXML | tidak tampil | nonaktif | — | unggah gagal + Coba lagi (sandi diketik ulang) | dialog, berkas dipilih ulang |
| ST-61 | progres | — | gagal baca + alasan | G | cache | — | — | G |
| ST-70 | — | — | — | — | info versi API tidak tersedia | — | pengaturan lokal selalu tersimpan | G |

## 4. Layar

Setiap layar: **tugas utama**, **isi menurut prioritas**, **interaksi** (Windows / tablet),
**keadaan khusus**, **tata letak**.

### ST-01 Masuk

- **Tugas utama:** masuk dengan akun lab.
- **Isi:** merek Sidik Studio (ikon + nama) → isian email/ID pegawai → sandi (tombol lihat) →
  tombol Masuk → server yang dituju (teks kecil) → versi Studio.
- **Interaksi:** Windows: `Enter` di sandi = Masuk; `Tab` urutan wajar. Tablet: papan ketik email
  untuk identitas; tombol 48 dp.
- **Keadaan khusus:** salah sandi → pesan server di bawah isian, isian identitas tetap; akun
  pelanggan → "Akun ini bukan akun lab. Studio hanya untuk orang lab."; 429 → hitung mundur;
  offline → "Tidak ada koneksi ke server <alamat>".
- **Tata letak:** kartu masuk 400 dp di tengah semua lebar; potret: penuh lebar dengan tepi 24 dp.

### ST-02 Beranda

- **Tugas utama:** tahu apa yang menunggu saya.
- **Isi (urut):** (1) menunggu keputusan saya (SA: versi diajukan; admin: draf yang dikembalikan);
  (2) pekerjaan berjalan (simulasi/paritas/impor + progres); (3) status paritas per alat (ringkas:
  berapa belum/bayangan/paritas/aktif, tautan ST-30); (4) draf saya; (5) standar mendekati
  `berlaku_sampai` (≤ 30/7/1 hari).
- **Interaksi:** tiap baris bisa dibuka ke layarnya; Windows `↑/↓` + `Enter`.
- **Keadaan khusus:** kosong per kartu ("Tidak ada yang menunggu Anda"); kartu yang izinnya tidak
  dimiliki tidak tampil.
- **Tata letak:** ≥ 1280 dua kolom (kiri 1–2, kanan 3–5); lanskap dua kolom sempit; potret satu
  kolom berurutan.

### ST-10 Kelola Data — Daftar Tabel

- **Tugas utama:** memilih tabel.
- **Isi:** kotak cari tabel → kelompok Master / Transaksi / Audit / Sistem, tiap baris: nama
  tampilan, nama tabel (mono kecil), lencana kelas (`ubah`, `arsip`, `baca saja`), jumlah baris.
- **Interaksi:** ketik untuk menyaring; `↑/↓` + `Enter`; tablet ketuk.
- **Keadaan khusus:** kosong tidak mungkin (registry); galat → panel galat.
- **Tata letak:** ≥ 1280 daftar 360 dp di kiri + ST-11 di kanan (master-detail); tablet: daftar
  layar penuh, memilih membuka ST-11.

### ST-11 Kelola Data — Grid Tabel

- **Tugas utama:** menemukan baris yang perlu dibetulkan.
- **Isi:** bilah alat (cari, Saring, Kolom, Arsip: tanpa/termasuk/hanya, Ekspor, + Tambah bila
  boleh) → baris saringan aktif sebagai cip yang bisa dihapus → grid (kolom tampil registry; relasi
  sebagai label; angka mono kanan-rata; tanggal "9 Okt 2026") → kaki: "1–100 dari 412", ukuran
  halaman.
- **Interaksi:** Windows: klik kepala kolom = urut (Shift+klik tingkat kedua); `↑/↓/PgUp/PgDn`;
  `Enter` buka detail ST-12; `E` sunting baris terpilih (bila boleh); `Ctrl+C` salin sel/baris
  sebagai TSV; gulir menambah halaman. Tablet: ketuk baris = detail; tekan lama = menu (Salin,
  Sunting, Arsipkan, Riwayat); geser horizontal untuk kolom; kolom pertama dibekukan.
- **Keadaan khusus:** kosong dengan saringan → "Tidak ada baris yang cocok dengan saringan ini" +
  Hapus saringan; kosong tanpa saringan → "Tabel ini belum berisi data"; baris terarsip berlatar
  abu dan berikon arsip; tabel `baca saja` → pita info "Tabel ini baca saja: <alasan dari registry>".
- **Tata letak:** ≥ 1280 grid + panel detail kanan 320 dp (ST-12 ringkas) saat baris dipilih;
  lanskap: grid penuh, detail sebagai lembar samping; potret: grid dengan kolom inti saja (registry
  menandai `kolom_inti`), detail layar penuh.

### ST-12 Detail Baris

- **Tugas utama:** membaca satu baris lengkap dan bertindak atasnya.
- **Isi:** judul (label baris, mis. nama alat + serial) → lencana status (aktif/terarsip) → kolom
  berlabel dua kolom (label kiri, nilai kanan, nilai persis di tooltip) → relasi yang bisa dibuka →
  metadata (dibuat, diubah, oleh siapa dari riwayat terakhir) → tombol: Sunting, Arsipkan/Pulihkan,
  Riwayat, (sesi) Koreksi pembacaan.
- **Interaksi:** Windows `E` sunting, `H` riwayat, `Esc` tutup; tablet tombol di bilah bawah lembar.
- **Keadaan khusus:** baris sudah tidak ada / organisasi lain → 404 "Baris tidak ditemukan"
  (tidak membedakan); baris terkunci domain (sesi disetujui, sertifikat terbit) → pita "Terkunci:
  <alasan>" tanpa tombol ubah.
- **Tata letak:** panel kanan (≥ 1280), lembar samping (lanskap), layar penuh (potret).

### ST-13 Riwayat Baris

- **Tugas utama:** menjawab "siapa mengubah apa, kapan, dari berapa ke berapa".
- **Isi:** garis waktu terbaru di atas: waktu, pelaku + peran, aksi (dibikin/diubah/diarsipkan/
  dipulihkan/diekspor), tabel kolom lama → baru (lama dicoret abu, baru tebal), catatan/alasan.
  Untuk sesi: entri koreksi pembacaan menampilkan potret pembacaan lama vs baru per titik.
- **Interaksi:** saring per aksi/pelaku; Salin entri sebagai teks; Ekspor riwayat (bila izin ekspor).
- **Keadaan khusus:** kosong → "Belum ada perubahan tercatat sejak audit dimulai (27 Jul 2026)."
- **Tata letak:** sama dengan ST-12 (tab kedua di panel detail).

### ST-14 Form Sunting / Tambah

- **Tugas utama:** mengubah nilai dengan benar dan beralasan.
- **Isi:** kolom yang boleh diubah saja (urutan registry), tiap isian dengan label, satuan, petunjuk
  aturan (dari server), nilai lama kecil di bawah isian yang diubah → **Alasan** (wajib, 5–500
  karakter, pencacah) → peringatan khusus (mis. FR-STU-051 "sesi baru alat ini akan dihitung dengan
  profil X") → Simpan / Batal.
- **Interaksi:** Windows `Tab` antar isian, `Ctrl+Enter` Simpan, `Esc` Batal (konfirmasi bila
  ada perubahan); pemilih relasi dengan cari ketik. Tablet: papan ketik sesuai tipe (angka,
  tanggal), tombol Simpan lengket di bawah.
- **Keadaan khusus:** validasi 422 → pesan di bawah isian yang salah, fokus ke isian pertama yang
  salah; 409 → ST-90 konflik; simpan gagal jaringan → isian tetap, tombol Coba lagi.
- **Tata letak:** dialog 560 dp (≥ 1280), lembar samping (lanskap), layar penuh (potret).

### ST-15 Konfirmasi Arsip / Pulihkan

- **Tugas utama:** mengarsipkan dengan sadar akibatnya.
- **Isi:** kalimat akibat dari server ("Alat ini dipakai 12 sesi. Sesinya tidak ikut terarsip dan
  tetap bisa dibuka.") → Alasan (wajib) → tombol "Arsipkan alat ini" (bukan "Hapus").
- **Interaksi:** tombol utama nonaktif sampai alasan terisi; `Esc` batal.
- **Keadaan khusus:** server menolak (aturan domain) → kalimat penolakan, tanpa tombol.
- **Tata letak:** dialog 480 dp / bottom sheet di potret.

### ST-16 Ekspor CSV

- **Tugas utama:** mengambil data sesuai saringan.
- **Isi:** ringkasan "412 baris, 14 kolom, saringan: …" → peringatan bila memuat data pribadi
  ("Berkas ini berisi nama dan kontak pelanggan. Ekspor tercatat atas nama Anda.") → tombol Ekspor.
- **Interaksi:** Windows: dialog simpan berkas sistem; tablet: lembar bagikan Android.
- **Keadaan khusus:** > 50.000 baris → "Terlalu banyak; saring dulu"; 429 → hitung mundur.
- **Tata letak:** dialog kecil.

### ST-17 Koreksi Pembacaan

- **Tugas utama:** membetulkan angka teknisi tanpa menghapus aslinya.
- **Isi:** identitas sesi (nomor, alat, teknisi, status) → tabel pembacaan per titik × ulangan
  seperti lembar kerja (nilai asli tampil) → sel yang diubah menampilkan "asli → baru" → Alasan
  (wajib) → catatan "Anda akan tercatat ikut mengisi sesi ini dan tidak bisa menyetujuinya"
  (`PemisahanWewenang`) → Simpan koreksi.
- **Interaksi:** navigasi sel ala grid; `F2` sunting; angka koma desimal; `Ctrl+Enter` simpan.
- **Keadaan khusus:** sesi disetujui/terbit → layar baca saja + "Gunakan revisi sertifikat";
  409 bila teknisi/admin lain mengubah sesi.
- **Tata letak:** layar penuh di semua lebar (tabel lebar); potret: geser horizontal, kolom titik
  dibekukan.

### ST-20 Data Acuan — Daftar Paket

Layar M2 yang sudah ada (`lib/screens/data_acuan/daftar_paket_acuan_screen.dart` repo mobile)
dipindah ke cangkang Studio. Isi per CR-48 04 §1 S1: paket, versi aktif + tanggal berlaku, jumlah
draf (bukan teknisi), versi terjadwal, peringatan standar. Profil berskema yang belum diimpor tampil
abu-abu. Tata letak: ≥ 1280 tabel; tablet kartu satu kolom (potret) / dua kolom (lanskap).

### ST-21 Data Acuan — Studio Grid (aktif / draf)

Dasar: layar M2 `studio_acuan_screen.dart` + `widgets/grid_acuan.dart` (655 baris, baca saja) dan
anatomi CR-48 04 §4.

- **Tugas utama:** membaca versi aktif; di mode draf, menyunting sel dengan aman.
- **Isi:** bilah atas (paket › versi + status, disimpan hh:mm, lock, jumlah sel diubah; tombol
  Buat draf / Simulasikan / Banding / Ajukan) → tab lembar dari `skemaAcuan()` → bilah nilai
  (alamat kunci/kolom, nilai persis, satuan, asal `Sheet!Sel`) → grid → panel sel (nilai
  tersimpan persis, asal, versi pertama memuat nilai, diubah/disahkan oleh, dipakai di) → kaki
  (jumlah baris, sel ditolak, "tampilan dibulatkan N desimal [presisi penuh]").
- **Interaksi:** keyboard grid §4.1 (subset: tanpa rumus); tempel TSV dengan pratinjau (CR-48 FR-07);
  sel terkunci bergembok menolak ketik dengan alasan. Tablet: ketuk ganda untuk sunting, papan
  ketik angka dengan tombol koma.
- **Keadaan khusus:** mode aktif = baca saja (tombol Buat draf); draf milik orang lain = baca saja +
  "Draf milik Rina"; sel ditolak server = garis merah + alasan; simulasi kedaluwarsa = pita kuning;
  409 `draf_berubah` = dialog konflik per sel.
- **Tata letak:** ≥ 1280 grid + panel sel 320; lanskap panel sel sebagai lembar samping; potret
  panel sel bottom sheet, tab lembar bergulir horizontal.

### ST-22 Banding Versi Acuan

Rute P1 `banding` sudah ada. Isi: pemilih versi A dan B → ringkasan (sel berubah, baris tambah/
hapus per lembar) → daftar beda per lembar (kunci, kolom, lama → baru, selisih absolut & relatif).
Kosong: "Tidak ada perbedaan". Teknisi tidak punya layar ini. Potret: daftar, bukan dua grid
berdampingan.

### ST-23 Riwayat Sel & Garis Waktu

Rute P1 sudah ada. Garis waktu versi paket (nomor, status, rentang berlaku, pengaju, pengesah) dan
riwayat satu sel lintas versi. Kosong: "Sel ini belum pernah berubah sejak versi 1".

### ST-24 Ajukan (dialog, Data Acuan dan Lembar Hitung)

- **Tugas utama:** menyerahkan versi ke pengesah dengan dasar yang lengkap.
- **Isi:** ringkasan beda (jumlah sel) + ringkasan simulasi (sesi berubah, **angka cetak berubah**,
  terbit terdampak) + paritas (Lembar Hitung pertama: LULUS/GAGAL) → Alasan (wajib) → Rujukan
  (≥ 1; jenis: sertifikat standar / IK / lampiran / sel master / keputusan Lab) → Lembar Hitung:
  **Sumber metode** (IK + revisi, sel master atau "keputusan Lab tanpa sel master", diputuskan oleh,
  tanggal) → tombol "Ajukan ke pengesah".
- **Keadaan khusus:** `simulasi_kedaluwarsa`/`simulasi_belum_ada` → tombol berubah jadi
  "Jalankan simulasi dulu" (ke ST-40); `galat_statis_ada` → daftar sel bergalat; `paritas_belum_lulus`
  → tautan ST-37.
- **Tata letak:** dialog 640 dp / layar penuh di potret.

### ST-30 Lembar Hitung — Daftar Alat

- **Tugas utama:** melihat status setiap alat dan membuka lembarnya.
- **Isi:** tabel alat (48 profil): nama, kode profil, versi berlaku (nomor + `kode`/`database`),
  **status paritas** (lencana `belum` abu, `bayangan` biru, `paritas` amber, `aktif` hijau),
  draf terbuka, diajukan, gelombang S6, tautan pertanyaan lab terbuka. Saringan: status, gelombang,
  keluarga.
- **Interaksi:** `Enter` buka editor versi berlaku (baca) atau draf saya; tombol Buat draf
  (salin versi / dari profil PHP / impor `.xlsm`).
- **Keadaan khusus:** alat tanpa Lembar Hitung → "Masih dihitung profil PHP (versi kode). Belum ada
  Lembar Hitung." + Buat draf (bila izin).
- **Tata letak:** tabel di ≥ 1280 dan lanskap; potret kartu.

### ST-31 Editor Lembar Hitung

Layar inti S5. Anatomi (≥ 1280):

```
┌ Bilah atas: Lembar Hitung › Pipet Volume › Draf v2 (milik Rina) · tersimpan 10.42 · lock 15 ──────┐
│ [Periksa] [Pratinjau ▸ sesi #5012 ▾] [Simulasikan] [Banding v1] [Ajukan…]      3 galat statis ⚠ │
├ Bilah rumus: [BUDGET!C5 ▾ nama kotak] fx │ =SQRT(C3^2+C4^2)                     │ Umassa · g    │
│            galat: —     nilai pratinjau: 0,000058… (persis di inspektur)                          │
├──────────────────────────────────────────────────────────────────────────┬────────────────────────┤
│    A        B                                   C            D     E     │ INSPEKTUR              │
│ 3       Utimb — resolusi neraca/√3          0,0000577…                    │ Sel | Jejak | Riwayat  │
│ 4       Urepeat neraca — stdev/√n           0,0000316…                    │ BUDGET!C5              │
│ 5 ▸     Umassa = √(Utimb² + Urepeat²)       0,0000658…                    │ Rumus  =SQRT(C3^2+C4^2)│
│ 6       Utherm — U95 termometer/2           0,36                          │ Nilai  6.58…e-5 (persis│
│ …   (sel terikat: latar biru muda + ikon rantai; sel tampilan: miring)    │ Satuan g · Asal master │
│                                                                           │ Dipakai: BUDGET!C20 → …│
├ Tab sheet: MASUKAN ⛓ | ACUAN ⛓ | KONSTANTA | PERHITUNGAN | BUDGET ● | + ─┴────────────────────────┤
└ Kaki: 5 sheet · 412 sel · 3 galat statis · mesin phpspreadsheet 5.10.0 · tampilan 6 desimal [penuh]┘
```

- **Tugas utama:** memahami dan (di draf) mengubah rantai perhitungan, lalu membuktikan akibatnya.
- **Isi menurut prioritas:** (1) grid sel dengan nilai pratinjau terakhir (atau rumus bila mode
  "tampilkan rumus", `Ctrl+\``); (2) bilah rumus dengan nama kotak, rumus, galat sel, satuan;
  (3) galat statis (jumlah di bilah atas, daftar di inspektur); (4) inspektur sel; (5) tab sheet
  dengan penanda sheet berpengikat (⛓) dan sheet yang berubah (●); (6) kaki.
- **Pembeda visual sel:** terikat (latar `infoTipis`, ikon rantai, tidak bisa diketik); rumus
  (teks biasa); konstanta (teks tebal ringan); tampilan (miring, ikon mata); bergalat (garis
  `gagal`); berubah di draf ini (segitiga amber di pojok); keluaran terikat (garis bawah amber);
  **blok agregasi** (`uc`…`keputusan`, K-49-07) berlabel "Agregasi — diawasi GUM", dan sel yang
  rumusnya sudah beda dari blok bawaan diberi ikon perisai amber + tooltip "beda dari GumCalculator:
  wajib catatan penyimpangan".
- **Bilah rumus:** pelengkapan otomatis hanya fungsi daftar putih (§6.4 SDD) dan named range;
  petunjuk argumen; rujukan dalam rumus diwarnai dan kotak sel yang dirujuk diberi bingkai warna
  sama di grid (seperti Excel); `F4` mengganti relatif/absolut saat menyunting; galat statis tampil
  langsung di bawah bilah sesudah simpan batch.
- **Interaksi keyboard (Windows) — §4.1.** **Sentuh (tablet):** ketuk pilih; ketuk ganda sunting;
  seret pegangan sudut untuk memperluas seleksi; tekan lama = menu (Salin, Tempel, Isi ke bawah,
  Jejak hulu/hilir, Riwayat sel, Tandai tampilan); cubit = perbesar grid 75–150%; bilah tombol di
  atas papan ketik: `=` `(` `)` `,` `:` `!` `$` `^` `fx` (daftar fungsi) `Σ`.
- **Simpan:** otomatis per batch 2 detik diam atau saat pindah sheet; `Ctrl+S` paksa. Urungkan/
  ulangi lokal sampai batch dikirim; sesudah dikirim, urungkan mengirim suntingan balik (tercatat).
- **Keadaan khusus:** mode baca (versi beku, bukan pemilik, atau tanpa izin) → bilah atas "Baca
  saja: versi v1 aktif" + Buat draf; sel terikat diketik → ditolak dengan "Sel ini diisi dari
  <sumber>. Ubah pengikatnya di panel Pengikat."; `409 draf_berubah` → dialog konflik per sel;
  lewat batas ukuran → pita merah dengan angka batas; pratinjau gagal → nilai sel menampilkan `—`
  dan galat di bilah rumus; offline → baca saja dari cache isi versi.
- **Tata letak:** ≥ 1280 seperti gambar; lanskap: inspektur jadi lembar samping, bilah rumus satu
  baris; potret: grid penuh, bilah rumus dua baris, inspektur bottom sheet, tab sheet di bawah
  sebagai daftar gulir. **Q-49-08**: apakah penyuntingan rumus dibuka di tablet atau tablet hanya
  baca + pratinjau + pengesahan. Sampai dijawab, tablet tetap bisa menyunting tapi tombol Ajukan
  diberi konfirmasi tambahan.

#### 4.1 Peta keyboard grid (Windows)

| Tombol | Aksi | Berlaku di |
|---|---|---|
| `←↑→↓` | pindah sel | semua grid |
| `Shift+panah` | perluas seleksi | semua grid |
| `Ctrl+panah` | lompat ke tepi blok data | semua grid |
| `Ctrl+Shift+panah` | perluas seleksi ke tepi blok | semua grid |
| `Tab` / `Shift+Tab` | sel kanan / kiri | semua grid |
| `Enter` / `Shift+Enter` | sel bawah / atas (sesudah sunting: simpan sel) | semua grid |
| `F2` | sunting sel aktif | draf |
| `Esc` | batal sunting | draf |
| `Delete` | kosongkan isi sel terpilih | draf (bukan sel terikat) |
| `Ctrl+C` / `Ctrl+X` / `Ctrl+V` | salin / potong / tempel (TSV; Lembar Hitung: rumus dengan penggeseran rujukan relatif) | draf (potong/tempel), salin di semua |
| `Ctrl+Shift+V` | tempel nilai saja | draf Lembar Hitung |
| `Ctrl+D` / `Ctrl+R` | isi ke bawah / ke kanan | draf Lembar Hitung |
| `Ctrl+Z` / `Ctrl+Y` | urungkan / ulangi | draf |
| `Ctrl+Space` / `Shift+Space` | pilih kolom / baris | semua grid |
| `Ctrl+Home` / `Ctrl+End` | sel A1 / sel terakhir berisi | semua grid |
| `PgUp` / `PgDn` | satu layar ke atas / bawah | semua grid |
| `Ctrl+PgUp` / `Ctrl+PgDn` | sheet/tab sebelumnya / berikutnya | Lembar Hitung, Data Acuan |
| `F4` | ganti relatif/absolut saat menyunting rumus | draf Lembar Hitung |
| `Ctrl+[` / `Ctrl+]` | lompat ke sel hulu / hilir (ST-33) | Lembar Hitung |
| `Ctrl+\`` | tampilkan rumus / nilai | Lembar Hitung |
| `F9` | jalankan pratinjau pada sesi terpilih | Lembar Hitung |
| `Ctrl+F` | cari teks/alamat/nama | semua grid |
| `Ctrl+G` | pergi ke alamat atau nama | Lembar Hitung |
| `Ctrl+S` | simpan paksa batch | draf |
| `Ctrl+Shift+=` / `Ctrl+-` | sisip / hapus baris atau kolom terpilih | draf Lembar Hitung |

### ST-32 Panel Named Range

- **Isi:** daftar nama (nama, rujukan, nilai pratinjau, dipakai berapa sel), nama dari pengikat
  Data Acuan bertanda ⛓ dan tidak bisa diubah. Tambah/ubah/hapus nama di draf; hapus nama yang
  masih dipakai ditolak dengan daftar pemakainya.
- **Interaksi:** ketuk nama = sorot rentangnya di grid; `Enter` = pergi ke rentang.
- **Keadaan khusus:** nama tanpa definisi (hasil impor) bertanda merah.
- **Tata letak:** tab di inspektur.

### ST-33 Panel Jejak Ketergantungan

- **Tugas utama:** "angka ini datang dari mana / dipakai ke mana".
- **Isi:** dua daftar bertingkat: **Hulu** (sel/nama/pengikat yang dirujuk, sampai sumber masukan)
  dan **Hilir** (sampai keluaran terikat); tiap simpul: alamat, label, nilai pratinjau, ikon jenis.
  Pilihan kedalaman (1, 3, semua). Di grid, panah tipis dari hulu ke sel terpilih (mode jejak).
- **Interaksi:** ketuk simpul = pindah ke sel itu; `Ctrl+[`/`Ctrl+]`.
- **Keadaan khusus:** sel tanpa hulu → "Konstanta atau masukan"; sel tanpa hilir → "Tidak dipakai
  keluaran mana pun" (petunjuk sel yatim).

### ST-34 Panel Pengikat

- **Tugas utama:** mengatur dari mana sel masukan diisi dan ke mana keluaran disetor.
- **Isi:** dua bagian: **Masukan** (kunci, rentang, jenis sumber + rinciannya, wajib, pesan kosong)
  dan **Keluaran** (rata_rata, error, koreksi, …, tabel komponen dengan pemetaan kolom, k_tetap,
  blok `agregat` wajib: uc, veff, k, U hitung, U95, keputusan). Kontrak input profil (kunci yang tersedia dari `bentukLembarKerja()`)
  tampil sebagai pilihan, bukan ketik bebas.
- **Keadaan khusus:** `kontrak_input_dilanggar` → baris merah dengan daftar kunci yang tersedia;
  keluaran wajib belum terikat → peringatan di atas.
- **Tata letak:** layar lebar dialog 880 dp (≥ 1280); layar penuh di tablet.

### ST-35 Pratinjau Sesi

- **Tugas utama:** membandingkan draf dengan angka tersimpan dan profil PHP pada satu sesi nyata.
- **Isi:** pemilih sesi (cari nomor sesi/alat/tanggal; atau vektor master) → kartu sesi (tanggal
  kalibrasi, versi Data Acuan berstempel) → tabel per titik: kolom **Tersimpan | PHP hari ini |
  Draf**, baris: rata-rata, koreksi, uc, veff, k, U hitung, CMC, U95 terbit, **angka cetak**,
  keputusan; beda disorot (amber untuk nilai antara, merah untuk angka cetak/keputusan) → baris
  **Pengawas GUM** di bawah kolom Draf (uc, veff, k, U, U95, keputusan menurut `GumCalculator`; beda
  dari draf diberi ikon perisai + selisih + kode catatan yang menjelaskannya, atau "tanpa catatan —
  tidak bisa diajukan") → titik ditahan beserta alasan.
- **Interaksi:** `F9` jalankan ulang; klik baris = sorot sel lembar yang menghasilkannya.
- **Keadaan khusus:** tidak ada sesi tersimpan untuk profil → "Belum ada sesi tersimpan; pilih
  vektor master"; `pratinjau_terlalu_berat` → arahkan ke simulasi.
- **Tata letak:** panel bawah (≥ 1280, tinggi 40%) di editor; layar penuh di tablet.

### ST-36 Banding Versi Lembar Hitung

- **Isi:** pemilih dua versi → ringkasan per sheet (sel tambah/hapus/ubah, nama, pengikat) →
  daftar beda: alamat, isi lama → baru (rumus berdampingan, bagian beda disorot) → mode grid
  berdampingan (≥ 1280 saja).
- **Keadaan khusus:** kosong → "Isi kedua versi identik (sha256 sama)".

### ST-37 Laporan Paritas

- **Tugas utama:** membuktikan versi pertama setara profil PHP.
- **Isi:** tab **Bayangan** (kandidat yang sedang dibayangkan: sha256, mulai kapan, jumlah sesi nyata
  terbandingkan, beda, syarat Q-49-03 terpenuhi atau belum, tombol Hentikan) dan tab **Paritas**:
  vonis besar LULUS/GAGAL + sha256 isi + mesin + waktu → ringkasan per syarat PRD §5 (nilai
  antara: identik bit / dalam toleransi / gagal; angka cetak; keputusan; titik ditahan; kode jejak)
  → daftar sesi/vektor yang gagal (kolom, PHP, Lembar Hitung, selisih) → pemeriksa independen
  (agen + orang Lab, tanda tangan elektronik = catatan bernama di laporan, Q-49-02).
- **Keadaan khusus:** berjalan → progres n/N, boleh ditinggal; kedaluwarsa → "Isi berubah sejak
  paritas; jalankan ulang".

### ST-38 Log Metode

- **Isi:** garis waktu versi rumus (nomor, sumber kode/database, status, rentang berlaku, IK, sel
  master, diputuskan oleh, pengaju, pengesah, tanggal) + tautan ke banding dan laporan. Ekspor CSV/
  Markdown.
- **Keadaan khusus:** hanya versi kode → "Rumus ini masih ditanam di kode program sejak awal
  (versi 1)."

### ST-40 Laporan Simulasi

- **Tugas utama:** tahu sesi mana yang **angka cetaknya** berubah sebelum mengajukan.
- **Isi:** ringkasan besar: sesi total, berubah, **cetak berubah**, **cetak berubah pada sertifikat
  terbit** (merah bila > 0), keputusan berubah, tertahan berubah, **agregat beda dari pengawas GUM**
  (amber; K-49-07) → saringan "hanya cetak berubah"
  (bawaan menyala) → tabel sesi: nomor, tanggal, status, terbit?, kolom berubah, lama → baru,
  cetak lama → baru → vektor master.
- **Interaksi:** klik sesi = ST-35 dengan sesi itu; ekspor laporan.
- **Keadaan khusus:** antre/jalan (progres, boleh ditinggal; kabar di Beranda); gagal (alasan + sesi
  penyebab); kedaluwarsa (pita kuning + Jalankan ulang); kosong ("Belum ada sesi tersimpan — hanya
  vektor master yang dijalankan").

### ST-50 Pengesahan — Antrean (SA)

- **Isi:** daftar versi `diajukan` (Data Acuan dan Lembar Hitung): alat, jenis, nomor, pengaju,
  diajukan kapan, ringkasan simulasi (cetak berubah), paritas (bila pertama). Versi yang SA ini ikut
  sunting/ajukan ditandai "Anda tidak bisa mengesahkan ini (ikut menyunting)".
- **Keadaan khusus:** kosong → "Tidak ada yang menunggu pengesahan".

### ST-51 Pengesahan — Keputusan (SA)

- **Tugas utama:** memutuskan dengan semua bahan di satu layar.
- **Isi (urut):** identitas versi → **akibat** ("Berlaku untuk sesi bertanggal kalibrasi ≥ 1 Nov 2026
  di semua perangkat; 3 sesi tersimpan berubah angka cetak; 0 sertifikat terbit terdampak; 0 sesi
  agregat beda dari pengawas GUM") → beda
  (ringkas, tautan ST-22/36) → simulasi (ST-40 ringkas) → paritas (versi pertama) → alasan, rujukan,
  sumber metode dari pengaju → tanggal berlaku (bawaan hari ini; masa depan boleh; mundur hanya bila
  syarat terpenuhi) → tombol **Sahkan** (minta sandi), **Kembalikan** (catatan wajib), **Tolak**
  (alasan wajib).
- **Keadaan khusus:** `pengesah_ikut_menyunting` → kartu merah menjelaskan aturan, tanpa tombol;
  versi sudah diputuskan SA lain → muat ulang; sandi salah → pesan di dialog sandi.
- **Interaksi tablet:** bahan dalam tab (Akibat · Beda · Simulasi · Sumber), tombol keputusan lengket
  di bawah, sandi dengan papan ketik aman.
- **Tata letak:** ≥ 1280 dua kolom (bahan kiri, keputusan kanan 360 dp); tablet satu kolom.

### ST-60 Impor `.xlsm` — Unggah

- **Isi:** pemilih profil tujuan → seret-lepas/pilih berkas (≤ 20 MB) → "Berkas terenkripsi?" isian
  sandi (tidak disimpan; teks penjelas "Sandi hanya dipakai saat membaca berkas ini dan tidak
  disimpan di mana pun") → Impor.
- **Keadaan khusus:** bukan OOXML / terlalu besar → pesan; sandi salah → pesan tanpa mengulang sandi;
  unggahan kembar → "Berkas ini pernah diimpor 9 Okt; buka laporannya?".
- **Tata letak:** dialog 560 dp / layar penuh tablet. Tablet memakai pemilih berkas Android.

### ST-61 Impor `.xlsm` — Laporan

- **Isi:** hasil (draf dibuat / bermasalah / gagal) → sheet dibaca/dilewati (DATABASE dilewati:
  data pelanggan) → hitung ulang pembanding (sel sama/beda) → temuan per kode (rujukan luar,
  `#REF!`, volatile, fungsi tidak didukung, larik, nama tanpa definisi, dugaan rujukan meleset), tiap
  temuan dengan sel, rumus asli, usulan → pengikat yang diusulkan (wajib dikonfirmasi satu per satu
  di ST-34) → Buka draf.
- **Keadaan khusus:** diproses → progres.

### ST-70 Pengaturan

Tema (Terang / Gelap / Ikut sistem), Bahasa (Indonesia / English), Ukuran tampilan grid (padat /
nyaman), Tentang (versi Studio, versi API `deploy.versi`, mesin rumus), Keluar. Build uji: alamat
server.

## 5. Token desain Studio

Sumber: `studio/lib/tema/warna_studio.dart`, `tema_studio.dart`, `font_studio.dart` (S0b). Warna
identitas dari ikon Studio: navy `#041E50`, amber `#F59E0B`, amber tua `#D97706`. Kontras teks
dijaga `studio/test/tema_studio_test.dart` (≥ 4,5:1).

### 5.1 Warna

| Token | Terang | Gelap | Dipakai untuk |
|---|---|---|---|
| `latar` | `#F3F4F7` | `#0A0F18` | latar jendela |
| `permukaan` | `#FFFFFF` | `#111827` | panel, grid, isian |
| `permukaanRendah` | `#EEF1F5` | `#172133` | kepala tabel, baris selang-seling |
| `garis` | `#D9DEE6` | `#243044` | pemisah sel 1 px |
| `garisKuat` | `#8A94A6` | `#66748A` | garis kontrol |
| `teks` / `teks2` / `teksRedup` | `#0F1A2E` / `#465266` / `#5E6878` | `#E7EBF2` / `#B0BACA` / `#8F9AAD` | teks utama / sekunder / keterangan |
| `primer` / `diAtasPrimer` | `#0B2A66` / `#FFFFFF` | `#FBBF24` / navy | tombol utama, tautan, fokus |
| `aksen` | `#F59E0B` | `#FBBF24` | penanda: menu aktif, sel terpilih, sel berubah |
| `aksenTeks` | `#9A4A06` | `#FCD34D` | teks beraksen di atas permukaan |
| `aksenTipis` | `#FFF3D6` | `#3A2C0B` | latar sel/baris terpilih |
| `sisi` / `sisiAktif` | navy / `#14336E` | `#060B14` / `#15213A` | sidebar |
| `lulus` / `lulusTipis` | `#166534` / `#DCF3E3` | `#6EE7A0` / `#10291C` | paritas LULUS, status aktif |
| `gagal` / `gagalTipis` | `#B42318` / `#FDE5E3` | `#FF9B91` / `#3A1714` | galat, angka cetak berubah |
| `awas` / `awasTipis` | `#9A4A06` / `#FFF0D4` | `#FCD34D` / `#3A2C0B` | kedaluwarsa, offline |
| `info` / `infoTipis` | `#1D4ED8` / `#E1EAFD` | `#93B4FF` / `#15254A` | sel terikat, bayangan |

Aturan: amber adalah **penanda**, bukan teks di atas putih (kontras ±2:1); teks di atas amber selalu
navy. Merah KAN milik panel/sertifikat tidak dipakai di Studio. Status selalu dengan ikon + teks,
tidak hanya warna.

### 5.2 Tipografi

| Peran | Font | Ukuran / berat (Windows) | Tablet |
|---|---|---|---|
| Judul layar | Inter | 20 / 600 | ×1,08 |
| Judul panel | Inter | 17 / 600; 15 / 600 | ×1,08 |
| Isi | Inter | 14 / 400; 13 / 400 | ×1,08 |
| Keterangan | Inter | 12 / 400 | ×1,08 |
| Label kecil | Inter | 11 / 600, spasi 0,4 | ×1,08 |
| **Angka** (nilai, alamat sel, rumus, sha, nomor versi) | IBM Plex Mono, tabular | 13 / 500 | ×1,08 |

Font dibundel dari paket `sidik_calibration` (`packages/sidik_calibration/Inter`,
`…/IBMPlexMono`, `font_studio.dart`), tidak diunduh saat jalan.

### 5.3 Jarak, sudut, kepadatan

Jarak kelipatan 4: 4 · 8 · 12 · 16 · 24 · 32. Sudut 4 (kontrol), 6 (panel). Sidebar 240, bilah 48.
Tinggi kontrol 36 dp (Windows) / 48 dp (tablet). Tinggi baris grid (usulan, ⏳ diuji di S1 —
tindak lanjut ST-T-015 grid dasar + ST-T-020 uji perangkat, menunggu prototipe grid; per 13 Okt 2026
belum diuji): padat 26 dp / nyaman 32 dp di Windows; 40 dp di tablet. Lebar kolom grid bawaan 96 dp, bisa ditarik.

### 5.4 Gerak

Hanya untuk umpan balik: sorot sel berubah memudar 600 ms, panel samping masuk 150 ms. Hormati
"kurangi gerak" sistem (tanpa animasi). Grid tidak dianimasikan (pola aplikasi lapangan: bagian
bertabel polos).

## 6. Bahasa layar

| Pakai | Jangan | Alasan |
|---|---|---|
| Lembar Hitung | formula, script | istilah §49 |
| Data Acuan | database, parameter | istilah CR-48 |
| Kelola Data | phpMyAdmin, admin DB | — |
| Sahkan | Simpan, Approve | aksi yang membuat versi berlaku |
| Ajukan | Kirim, Submit | — |
| Arsipkan / Pulihkan | Hapus | tidak ada hapus permanen (K-49-06) |
| Koreksi pembacaan | Edit data teknisi | nilai asli tetap tersimpan |
| Pratinjau | Test, Coba | tidak menyimpan |
| Simulasi | Uji | menjalankan ke semua sesi |
| Paritas / Mode bayangan | Validasi | istilah §49 |
| Angka cetak | Hasil | yang tercetak di sertifikat |
| Master Data | admin | label peran `admin` (`AGENTS.md` §Peran 1) |

- Angka: koma desimal (`1,0043`), titik ribuan tidak dipakai di grid; nilai persis di tooltip/inspektur.
- Tanggal: "9 Okt 2026"; waktu "10.42".
- Tombol yang mengubah status menyebut akibatnya: "Sahkan — berlaku untuk sesi bertanggal kalibrasi
  1 Nov 2026 ke atas".
- Pesan galat menyebut langkah berikutnya, bukan hanya apa yang salah.

## 7. Alur utama

**A. Membetulkan data (Master Data, Windows).** ST-10 pilih Alat → ST-11 saring "jatuh tempo bulan
ini" → pilih baris → ST-12 → `E` → ST-14 ubah serial + alasan → Simpan → ST-13 riwayat menampilkan
lama → baru.

**B. Rekalibrasi standar Micrometer (S3).** CR-48 04 §2 dengan layar Studio: ST-20 → ST-21 Buat draf →
tempel nilai terkoreksi → Simulasikan (ST-40, cetak berubah 3, terbit 0) → ST-24 Ajukan → SA lain di
ST-50/51 Sahkan (berlaku tanggal sertifikat baru) → aplikasi lapangan memakai v2 ≤ 3 menit.

**C. Lembar Hitung pilot (S4–S5).** ST-30 Pipet Volume → Buat draf "dari profil PHP" (atau ST-60
impor) → ST-31 periksa (nol galat statis) → ST-35 pratinjau pada satu sesi (tiga kolom sama) →
"Mulai bayangan" (status `bayangan` di ST-30; PHP tetap menghitung sesi nyata) → sesudah syarat
Q-49-03 → ST-37 paritas LULUS (termasuk laporan bayangan) + pemeriksa independen (status `paritas`)
→ ST-24 ajukan (sumber metode) → ST-51 SA lain sahkan → berlaku: status `aktif` dengan pengawasan PHP.

**D. Versi 2 rumus (S5).** ST-31 draf dari v1 → ubah konstanta/rumus → ST-35 pratinjau → ST-40
simulasi (sorot cetak berubah) → ST-24 ajukan → ST-51 sahkan dengan tanggal berlaku → ST-38 log
metode bertambah.

**E. Pengesahan dari tablet.** Beranda kartu "2 menunggu Anda" → ST-50 → ST-51 tab Akibat/Beda/
Simulasi/Sumber → Sahkan → dialog sandi → selesai; Beranda diperbarui.
