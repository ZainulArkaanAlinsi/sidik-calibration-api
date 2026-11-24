# Kontrak antar-terminal v0.1

Pemilik: Terminal 4 (API Laravel). Konsumen (Studio Web, layanan OCR, mobile, Excel/alat) boleh meminta perubahan lewat `KOORDINASI.md` bagian 11, tidak boleh mengubah sendiri.

| | |
|---|---|
| Versi | `0.1` |
| Status | Ditulis T4 24 Nov 2026. Bentuk data dan aturan di bawah SIAP DIPAKAI untuk membangun; butir yang bertanda **[KONFIRMASI]** adalah usulan sementara yang menunggu Zainul dan Manajer Teknis (bagian 10). |
| Dasar kode | `origin/main` API `79280fc2` (semua rujukan `path:baris` memakai hash itu) |
| Berkas | `schema/*.schema.json` (acuan tunggal bentuk), `contoh/` (payload sah dan tidak sah + `MANIFEST.json`), `definisi-lembar-kerja.md` (kerangka), berkas ini |
| Dasar | Draf `sidik-dokumentasi\kontrak\KONTRAK-ANTAR-TERMINAL.md` (Zainul/koordinator). Glosarium dan prinsipnya tidak diubah; yang diputuskan T4 ada di bagian 4 sampai 8. |

Bila berkas ini dan JSON Schema berselisih soal **bentuk**, JSON Schema yang benar dan berkas ini dikoreksi. Bila berselisih soal **aturan di luar bentuk** (bagian 7), berkas ini yang benar.

## 1. Prinsip (dari draf, tidak diubah)

1. Kontrak dulu, kode kemudian. Tidak ada yang membangun fitur lintas-terminal di atas bentuk yang belum tertulis di sini.
2. Satu pemilik per hal. T4 memiliki kontrak; yang lain konsumen.
3. Satu istilah, satu arti (glosarium bagian 2).
4. Yang tidak pasti ditanyakan (`KOORDINASI.md` bagian 12), bukan ditebak.
5. Laravel tetap satu-satunya sumber kebenaran. Layanan OCR dan Studio Web tidak menyimpan skema, usulan, atau data pengukuran sendiri.
6. Hasil OCR hanyalah usulan. Tidak ada angka masuk data pengukuran tanpa konfirmasi manusia yang tercatat.

## 2. Glosarium (wajib dipakai persis)

Delapan istilah pertama disalin dari draf tanpa perubahan.

- **Formulir**: lembar kerja cetak, diidentifikasi oleh `kode` (mis. SIDIK-FM-CAL-0511) + `revisi` (mis. 5) + PDF kosongnya.
- **Skema formulir**: letak kotak isian dan artinya (peran, label, satuan) untuk satu formulir dan revisi. Berversi.
- **Definisi lembar kerja**: sel input, rumus, hasil antara, hasil akhir, pembulatan, kolom sertifikat dari Excel yang divalidasi. Berversi. Milik T4/T1. Studio tidak mengubah rumus.
- **Pemetaan (binding)**: hubungan satu kotak isian di skema formulir ke satu sel input di definisi lembar kerja, atau ke satu data master.
- **Data master**: daftar yang sering berubah (standar yang dipakai, Thermohygro, metode).
- **Usulan OCR**: nilai yang dibaca mesin untuk satu kotak isian. Belum data pengukuran.
- **Konfirmasi**: tindakan manusia yang mengubah usulan menjadi data pengukuran draf. Mencatat pelaku, waktu, nilai sebelum dan sesudah.
- **Data pengukuran**: nilai yang sudah dikonfirmasi. Tidak ditimpa; koreksi = versi baru.

Istilah tambahan v0.1 (muncul karena bagian 4 sampai 6 butuh namanya; tidak mengubah arti delapan di atas):

- **Item**: satu kotak/area pada skema formulir. Punya `id` yang stabil.
- **Versi skema** (`versi_skema`): nomor urut skema untuk satu pasangan (kode, revisi). Beda dari **revisi**, yaitu revisi cetak formulir di kertas.
- **Pindaian** (`pindai_id`): satu foto formulir yang diterima server, beserta usulan dan konfirmasinya.
- **Slot master**: tempat di sesi kalibrasi yang diisi dari data master: `standar_dipakai`, `standar_dicek` (Usage Check), `thermohygro`, `metode_kalibrasi`.
- **Header pekerjaan**: blok kepala yang sama untuk semua alat (nomor sertifikat, order, teknisi, metode, standar, thermohygro, tanggal terbit, penandatangan). Bukan hasil OCR.

## 3. Peta berkas

```
docs/kontrak/v0.1/
  KONTRAK.md                     berkas ini
  definisi-lembar-kerja.md       kerangka definisi lembar kerja per alat (bagian 4.9)
  schema/
    skema-formulir.schema.json
    usulan-ocr.schema.json
    konfirmasi-permintaan.schema.json   badan permintaan klien (tanpa pelaku/waktu)
    konfirmasi.schema.json              catatan tersimpan buatan server
    data-master.schema.json
    header-pekerjaan.schema.json
    SHA256SUMS.txt                      hash isi (LF) tiap schema; berubah = naikkan versi (bagian 8)
  contoh/<nama-schema>/valid-*.json , tidak-valid-*.json
  contoh/MANIFEST.json           tiap contoh tidak sah + alasan + jalur galat yang diharapkan
```

JSON Schema memakai draf 2020-12, tiap berkas berdiri sendiri (tanpa `$ref` antar-berkas) supaya validator mana pun (Opis di PHP, Ajv di TypeScript, `jsonschema` di Python, paket Dart) memuatnya tanpa pendaftaran tambahan. `$defs` yang namanya sama harus identik di semua berkas; tes kontrak menjaganya. Semua objek `additionalProperties: false`: nama field salah ketik ditolak, bukan diabaikan.

## 4. Bentuk data

### 4.1 Nama final

| Konsep (glosarium) | Dokumen | Keterangan |
|---|---|---|
| Skema formulir | `skema-formulir` | Satu dokumen per (kode, revisi, versi_skema). |
| Usulan OCR | `usulan-ocr` | Satu dokumen per pindaian. Hanya ada bila formulir berhasil dikenali; kegagalan mengenali dijawab sebagai galat (bagian 6.3), bukan dokumen. |
| Konfirmasi | `konfirmasi-permintaan` (masuk) dan `konfirmasi` (tersimpan) | Dipisah supaya klien tidak bisa menulis pelaku dan waktu sendiri; keduanya diisi server dari token. |
| Data master | `data-master` | Bentuk BACA terpadu atas `standards` dan `calibration_methods`. Tidak ada tabel master baru; sunting tetap lewat `/api/standards` dan `/api/calibration-methods`. |
| Pemetaan (binding) | objek `pemetaan` di tiap item skema | Bukan dokumen sendiri: daur hidup dan jejak auditnya harus sama dengan skema yang memuatnya. Dokumen terpisah memungkinkan skema yang sudah dipublikasi menunjuk pemetaan yang masih bisa berubah. |
| Header pekerjaan | `header-pekerjaan` | Bentuk BACA gabungan dari sesi kalibrasi. `dari_ocr` selalu `false`. |
| Definisi lembar kerja | kerangka saja | `definisi-lembar-kerja.md`; JSON Schema menunggu matriks satu alat dari T1. |

Selisih dengan bentuk contoh di draf kontrak (bagian 4 draf), semuanya sengaja:

| Draf | v0.1 | Alasan |
|---|---|---|
| `jenis`: `sel \| teks_bebas \| centang` | `sel \| teks_bebas \| area_usulan` | Kotak centang adalah arti (`peran`), bukan bentuk geometri; `jenis` dan `peran` sama-sama memuat "centang" melahirkan kombinasi yang saling bertentangan. `area_usulan` dibutuhkan untuk kolom identitas yang tak punya kotak tercetak (prompt OCR H.2). |
| `peran` tanpa nilai "belum diputuskan" | tambah `belum_ditentukan` | Heuristik hanya tahu "isian", bukan angka atau teks. Skema berstatus `ditinjau` ke atas dilarang memuatnya (aturan skema, bukan konvensi). |
| item tanpa asal | tambah `sumber` (`heuristik \| model_visi \| manusia`) | Jejak dari mana label dan peran berasal; `model_visi` belum diizinkan penyedianya (`KOORDINASI.md` bagian 10 butir 5). |
| `pemetaan.master`: "nama_master" bebas | enum slot: `standar_dipakai \| standar_dicek \| thermohygro \| metode_kalibrasi` | Slot sudah ada di sesi (bagian 4.7). |
| `status`: `hijau \| kuning \| merah` | `sepakat_dua_pembacaan \| ragu \| tidak_terbaca \| kosong` | Aturan keras: tulisan tangan tidak pernah hijau (`KOORDINASI.md` bagian 10 butir 4; `AGENTS.md` OCR awan butir 5). Tidak ada nama warna di data; warna urusan tampilan. `kosong` ada karena kotak kosong adalah hasil yang sah. |
| `pembaca` (satu string) | `pembacaan[]` `{pembaca, teks, keyakinan}` | Tanpa semua pembacaan mentah, "persentase sel sepakat yang ternyata salah" (angka terpenting di prompt OCR F) tidak bisa dihitung dari data tersimpan. |
| usulan: tanpa waktu | tambah `dibuat_pada`, `pipeline_versi` | Pengulangan pengukuran dan audit. |
| konfirmasi: satu butir `{item_id, nilai_final, dikonfirmasi_oleh, waktu, alasan_jika_diubah}` | satu catatan per tindakan: `{pindai_id, urutan, dikonfirmasi_oleh, waktu, item[]}` dengan `nilai_usulan`, `nilai_sebelum`, `nilai_final`, `diubah`, `master_id` | Satu ketukan "kirim" = satu tindakan manusia. `nilai_sebelum` membuat koreksi kedua dan seterusnya tetap lengkap sebelum/sesudahnya. Pelaku dan waktu tidak dikirim klien. |
| (tidak ada) | `definisi_lembar_kerja` di tingkat skema | Pemetaan `sel_input` hanya bermakna terhadap satu versi definisi. |
| `halaman_pt: [lebar, tinggi]` | tidak berubah | 41 PDF formulir di repo terhitung satu halaman (hitungan kasar penanda `/Type /Page`; belum diperiksa dengan pembaca PDF). Multi-halaman = tambahan kompatibel di versi berikutnya. |

### 4.2 Skema formulir

Satu dokumen = satu formulir + satu revisi cetak + satu `versi_skema`. Bidang: `kontrak_versi`, `formulir {kode, revisi}`, `versi_skema`, `status`, `halaman_pt`, `definisi_lembar_kerja` (`null` atau `{profil, versi}`), `catatan` (opsional), `item[]`. Item: `id`, `jenis`, `bbox_pt`, `peran`, `label_baris`, `label_kolom`, `satuan`, `kelompok`, `desimal`, `pemetaan {sel_input, master}`, `sumber`, `perlu_dicek`.

- `bbox_pt` memakai titik asal kiri-atas, poin PDF, sama dengan `skema_draf`.
- `peran` `isian_angka`, `isian_teks`, `centang`, `tanggal` adalah kotak yang diisi orang. `label`, `pemisah`, `abaikan` bukan isian (tidak punya pemetaan, tidak muncul di usulan).
- `desimal` `null` = belum diketahui. Pembulatan angka cetak bukan urusan skema formulir; itu di definisi lembar kerja.
- Aturan yang sudah ditegakkan JSON Schema: status `ditinjau`/`dipublikasi`/`ditarik` tidak boleh memuat `peran: belum_ditentukan` atau `perlu_dicek: true`; `definisi_lembar_kerja: null` berarti semua `sel_input` harus `null`; `label`/`pemisah`/`abaikan` tidak punya pemetaan.
- Formulir tanpa definisi lembar kerja tetap sah dan bisa dibaca OCR; hasilnya hanya data mentah berlabel yang tidak masuk perhitungan (prompt OCR G).

### 4.3 Aturan id item STABIL antar revisi

Keputusan (b). Kegagalan yang dihindari: `nomor` pada `skema_draf` adalah urutan deteksi, bergeser begitu satu sel ditambah; konfirmasi dan data lama yang menunjuk `nomor` ikut menunjuk kotak yang salah tanpa galat.

1. **`id` opak.** Pola `^[a-z0-9][a-z0-9_-]{5,63}$`. Tidak dihitung dari posisi, urutan, atau label setelah dibuat. Klien tidak boleh menafsirkan isinya.
2. **Lingkup.** Unik dalam (organisasi, `formulir.kode`) lintas SEMUA revisi dan `versi_skema`. `id` yang sama di dua revisi berarti "kotak logis yang sama".
3. **Tidak pernah dipakai ulang.** Kotak yang dihapus dari versi baru mempensiunkan `id`-nya; server mencatat semua `id` yang pernah ada per kode (buku besar id) dan menolak pemakaian ulang untuk kotak lain. `id` yang sudah punya usulan, konfirmasi, atau data pengukuran tidak boleh berganti arti; berganti arti = `id` baru.
4. **Versi skema baru, revisi cetak sama.** Semua kotak yang dipertahankan membawa `id` lama; kotak baru mendapat `id` baru (diterbitkan server saat disimpan; klien boleh membuat calon `id` dengan pola yang sama, server menolak yang bentrok).
5. **Revisi cetak baru.** Server MENGUSULKAN pencocokan kotak revisi baru ke revisi lama; manusia menyetujuinya di Studio; baru kemudian draf revisi baru disimpan dengan `id` lama untuk yang cocok. Urutan kriteria:
   1. Bentuk (`jenis`) sama.
   2. Kunci label ternormalisasi (huruf kecil, spasi tunggal, tanda baca dibuang) dari `kelompok` + `label_baris` + `label_kolom` sama.
   3. Bila kunci label muncul lebih dari sekali atau kosong: dibedakan oleh posisi relatif (`bbox_pt` dibagi `halaman_pt`) dan oleh tetangga yang sudah cocok.
   4. Pasangan satu-ke-satu: tiap `id` lama dipakai paling banyak sekali.
   5. Pasangan ambigu TIDAK dipasang otomatis; kotaknya diberi `id` baru dan `perlu_dicek: true`.
   Ambang jarak geometri BELUM ditetapkan: tiap kode di repo hanya punya satu revisi, jadi belum ada pasangan revisi nyata untuk mengukurnya. Jangan mengarang angka; ukur dulu saat pasangan revisi pertama datang.
6. **Revisi lama tetap utuh.** Foto dari revisi lama diproses dengan skema revisi lamanya (selaras `AGENTS.md:775` @79280fc2: revisi baru = berkas baru, berkas lama tidak disunting).
7. **Impor awal.** Hanya saat mengimpor draf PERTAMA sebuah kode, `id` boleh diturunkan secara deterministik dari `nomor` draf supaya impor bisa diulang: `"i" + 12 hex pertama sha1("sidik-skema-awal|kode|revisi|nomor")`. Setelah tersimpan, `id` itu opak dan tidak pernah dihitung ulang. Alat konversinya ada di `sidik-dokumentasi\kontrak\v0.1\_alat\` (bukan bagian kontrak).

### 4.4 Pemetaan dari `skema_draf` (41 contoh) ke v0.1

| `skema_draf` | v0.1 |
|---|---|
| `formulir` (nama PDF) | dibuang; identitas = `formulir.kode` + `formulir.revisi` |
| `kode`, `revisi` | `formulir {kode, revisi}` |
| `halaman_pt` | `halaman_pt` (sama) |
| `catatan` | `catatan` (sama) |
| (tidak ada) | `kontrak_versi: "0.1"`, `versi_skema: 1`, `status: "draf"`, `definisi_lembar_kerja: null` |
| `item[].nomor` | dibuang; hanya dipakai menurunkan `id` awal (bagian 4.3 butir 7) |
| `item[].jenis` (`sel`, `teks_bebas`) | `jenis` (sama) |
| `item[].peran_awal = pemisah` | `peran: "pemisah"` |
| `item[].peran_awal` = `isian`, `teks_bebas_kandidat`, `centang_kandidat` | `peran: "belum_ditentukan"` |
| `item[].bbox_pt`, `label_baris`, `label_kolom` | sama |
| `item[].perlu_model_visi` | dibuang; diganti `perlu_dicek: true` pada semua butir |
| (tidak ada) | `satuan: ""`, `kelompok: ""`, `desimal: null`, `pemetaan {sel_input: null, master: null}`, `sumber: "heuristik"` |

Bukti: 41 dari 41 draf dikonversi dengan aturan ini dan lulus `skema-formulir.schema.json` (hasil di laporan T4; `contoh/skema-formulir/valid-draf-konversi-0513-rev4.json` adalah konversi nyata, bukan karangan).

### 4.5 Usulan OCR

Bidang: `kontrak_versi`, `pindai_id`, `foto_ref`, `formulir {kode, revisi, skor, skor_kedua}`, `versi_skema`, `item[]`, `dibuat_oleh: "mesin"`, `dibuat_pada`, `pipeline_versi`. Butir: `item_id`, `nilai_usulan`, `keyakinan`, `status`, `potongan_ref`, `pembacaan[]`.

- `nilai_usulan` adalah teks persis hasil baca: tidak dikoreksi, tidak dibulatkan, tidak "dibetulkan" ke nilai standar (prompt OCR, aturan keras). Kedekatan dengan nilai standar hanya boleh jadi penanda perlu dicek.
- `status`: `sepakat_dua_pembacaan` (dua pembaca BERBEDA, teks sama persis; tetap wajib dikonfirmasi), `ragu`, `tidak_terbaca` (nilai `null`), `kosong` (kotak kosong di kertas, nilai `null`).
- Kotak `centang`: nilai `"1"` (dicentang) atau `"0"` (kosong), sama dengan kebiasaan `POST /worksheet-scans/{id}/koreksi` (`WorksheetScanController.php:382`). `kosong` tidak dipakai untuk centang; kotak centang tak bertanda adalah bacaan `"0"`.
- `skor` dan `skor_kedua` tidak berskala 0..1 (prototipe memakai hitungan jangkar: 34 lawan 32); keduanya hanya dibandingkan satu sama lain dalam satu pindaian dan bukan probabilitas.
- `keyakinan` belum terkalibrasi; jangan dijadikan ambang tanpa pengukuran pada foto asli.
- Hanya butir dengan `peran` isian (`isian_angka`, `isian_teks`, `centang`, `tanggal`) yang boleh muncul.

### 4.6 Konfirmasi

Permintaan klien (`konfirmasi-permintaan`): `{kontrak_versi, pindai_id, item: [{item_id, nilai_final, alasan_jika_diubah?, master_id?}]}`. Catatan tersimpan (`konfirmasi`): ditambah `urutan`, `dikonfirmasi_oleh {user_id, peran}`, `waktu`, dan per butir `nilai_usulan`, `nilai_sebelum`, `diubah`.

- Append-only. Koreksi = catatan baru dengan `urutan` lebih besar; catatan lama tidak ditimpa (selaras `AGENTS.md` peran butir 5: nilai lama dan baru sama-sama tersimpan, ISO/IEC 17025 7.5.2).
- `nilai_sebelum` = `nilai_usulan` pada `urutan` 1, dan `nilai_final` catatan sebelumnya untuk butir yang sama pada urutan berikutnya.
- `diubah` = `nilai_final` berbeda dari `nilai_sebelum`; dihitung server. Bila `true`, `alasan_jika_diubah` wajib (tidak kosong, bukan hanya spasi); bila `false`, harus `null`.
- `nilai_final` berupa TEKS (`null` = manusia memastikan kotak kosong). Teks dipertahankan persis agar `4.10` tidak menjadi `4.1`; penafsiran angka terjadi di server menurut satuan dan `desimal`, dan nilai antara tidak dibulatkan.
- Pelaku diambil dari token. `super_admin` tercatat sebagai `super_admin`, tidak pernah menyamar (`AGENTS.md` peran butir 3).

### 4.7 Data master

`{kontrak_versi, daftar: "standar" | "metode", dibaca_pada, item[]}`. Butir standar = subset `GET /api/standards` (`routes/api.php:714`; contoh bentuk di `docs/kontrak-api.md:722`): `id, nama, merk, model, serial_number, no_sertifikat, tertelusur_ke, berlaku_sampai, masih_berlaku, status_kalibrasi, hari_menuju_kadaluarsa`. Butir metode = subset `GET /api/calibration-methods` (`routes/api.php:689`, `CalibrationMethodResource.php:20-33`): `id, kode_lengkap, nama, aktif`.

Pemetaan slot master ke sesi kalibrasi yang SUDAH ada (`CalibrationResource.php` @79280fc2):

| Slot (`pemetaan.master`) | Di sesi | Rujukan |
|---|---|---|
| `standar_dipakai` | `standard_id` (`standar_acuan`) | `CalibrationResource.php:290` |
| `standar_dicek` | baris Usage Check, pivot `calibration_session_standard` dengan `dipakai` | `CalibrationResource.php:317-329`, `CalibrationSession.php:572` |
| `thermohygro` | `thermohygro_standard_id` | `CalibrationController.php:5771`, `CalibrationResource.php:311` |
| `metode_kalibrasi` | `calibration_method_id` | `CalibrationResource.php:306` |

Konsekuensi: daftar master tidak berversi di v0.1 (sama seperti `/api/standards` sekarang; perubahan tercatat di `audit_logs`), dan hasil mencatat standar yang dipakai lewat id baris di sesi. Standar kedaluwarsa dijawab `422 standar_kedaluwarsa` bila dipakai, sama dengan aturan `docs/kontrak-api.md` bagian Standar Acuan. Bagaimana kotak centang di kertas dikaitkan ke butir master tertentu (label cetak, urutan, atau tulisan tangan) BELUM diputuskan (K5 di bagian 10); v0.1 hanya mengikat kotak ke SLOT, dan butir master dipilih manusia saat konfirmasi lewat `master_id`.

### 4.8 Pemetaan (binding)

`pemetaan {sel_input, master}` per item. `sel_input` = `Sheet!C12` (nama lembar apa adanya tanpa kutip, kolom huruf besar, tanpa `$`), harus ada di daftar input definisi lembar kerja yang dirujuk header skema (`definisi_lembar_kerja {profil, versi}`). Rantai penuh yang dipakai tim: kotak kertas (`item.id`) → `sel_input` → kolom aplikasi → kolom sertifikat; dua sambungan terakhir tertulis di definisi lembar kerja (`definisi-lembar-kerja.md`), bukan di skema formulir. Bila workbook berganti versi dan alamat sel bergeser, pemetaan dibuat ulang lewat skema versi baru (tidak ada penunjukan "mengambang" ke versi definisi terbaru).

### 4.9 Header pekerjaan

Blok kepala semua alat (prompt Excel-vs-kertas C). Sumber di API @79280fc2 (semua dari `CalibrationResource.php`, kecuali disebut lain):

| Field kontrak | Sumber | Baris |
|---|---|---|
| `sesi_id` | `id` | 38 |
| `nomor_sertifikat` | `sertifikat.nomor` | 233 |
| `nomor_order` | `nomor_order` | 40 |
| `teknisi {employee_id, kode_teknisi, nama}` | `teknisi` | 154-165 |
| `metode_kalibrasi {id, kode_lengkap, nama}` | `metode_kalibrasi` (`kode` di resource = `kodeLengkap()`) | 306-310 |
| `standar_acuan` | `standar_acuan` | 290 (`petakanStandar` 662) |
| `standar_dicek[]` | `standar_dicek` | 318-329 |
| `status_standar {ringkasan, pesan}` | `status_standar` (`CalibrationSession.php:374`) | 296 |
| `thermohygro {id, nama, serial_number}` | `thermohygro` | 311-315 |
| `kondisi` | `suhu_awal/akhir`, `kelembaban_awal/akhir` (272-275), `suhu_ruang`, `kelembaban` (252-254) | |
| `tanggal_terbit` | `sertifikat.diterbitkan_pada` | 240 |
| `dikalibrasi_oleh`, `diperiksa_oleh` | `teknisi.nama`, `reviewer.nama` | 156, 172 |

Yang BELUM dibuktikan (menunggu matriks T1, K7): apakah "Technician ID" di Excel = `employee_id` atau `kode_teknisi`; apakah "Checked by" = `reviewer`; apakah `suhu_ruang`/`kelembaban` (angka cetak sesudah koreksi thermohygro, `CalibrationController.php:5757`) sama dengan "rata-rata suhu/RH" di header Excel. Sampai terbukti, kontrak menyediakan semua kandidatnya dan tidak memilih.

### 4.10 Definisi lembar kerja

Hanya kerangka (`definisi-lembar-kerja.md`). Identitas rujukannya di skema formulir sudah final: `{profil, versi}` dengan `profil` = `CalibrationProfile::kode()` (mis. `micrometer`, `ph_meter`).

## 5. Mesin status skema dan wewenang

**[KONFIRMASI Zainul dan Manajer Teknis]** Zainul belum memutuskan siapa yang memublikasikan. Yang di bawah adalah usulan sementara yang memakai peran dan pola yang SUDAH ada di API; bukan keputusan.

### 5.1 Status

`draf → ditinjau → dipublikasi → ditarik`, dengan satu jalur balik `ditinjau → draf`.

| Dari | Ke | Aksi | Siapa (USULAN) | Syarat | Dicatat (`audit_logs` dan riwayat skema) |
|---|---|---|---|---|---|
| (baru) | `draf` | buat | akun Studio | skema sah | pelaku, waktu, asal (PDF kosong / unggah / salinan versi lain) |
| `draf` | `draf` | sunting | akun Studio | status `draf` | pelaku, waktu, per butir nilai sebelum dan sesudah |
| `draf` | `ditinjau` | ajukan | akun Studio | lulus aturan skema status `ditinjau` (tanpa `belum_ditentukan`, tanpa `perlu_dicek`); alasan | pelaku, waktu, alasan |
| `ditinjau` | `draf` | kembalikan | `super_admin` lab | alasan | pelaku, waktu, alasan |
| `ditinjau` | `dipublikasi` | publikasikan | `super_admin` lab yang BUKAN yang membuat, menyunting, atau mengajukan draf itu | alasan; uji pada foto contoh bila ada (K3) | pelaku, waktu, alasan, `sha256` isi kanonik |
| `dipublikasi` | `ditarik` | tarik | `super_admin` lab | alasan | pelaku, waktu, alasan |

Alasan: teks, minimal 10 karakter (meniru `DataAcuanAlurController.php:84`).

Dasar usulan: Studio hanya satu akun (`STUDIO_AKUN`, `routes/api.php:437-449`; token `studio` ditolak di semua rute lab dan sebaliknya), sehingga memublikasikan di Studio berarti satu orang membuat dan memublikasikan sendiri. Alur versi Data Acuan sudah memisahkan tegas: mengajukan oleh `admin`/`super_admin` (`routes/api.php:523-531`), mengesahkan oleh `super_admin` (`routes/api.php:533-538`) dengan penjaga `PemisahanWewenang::periksaPengesahanVersiAcuan` (`PemisahanWewenang.php:136`: pengesah tidak boleh yang membuat, menyunting, atau mengajukan). Usulan ini meniru pola itu. Alternatif yang harus diputuskan: (A) usulan di atas, (B) akun Studio memublikasikan sendiri dengan alasan wajib, (C) `admin` (Master Data) boleh menyunting dan mengajukan lewat aplikasi lab. Lihat K1 dan K2.

### 5.2 Aturan tetap (bukan usulan)

- `ditinjau` ke atas read-only. `dipublikasi` tidak pernah diubah isinya; memperbaiki = salin menjadi `versi_skema` baru berstatus `draf`.
- Versi AKTIF untuk (kode, revisi) = `versi_skema` terbesar yang berstatus `dipublikasi` (turunan, bukan status). Pindaian baru memakai versi aktif. Versi `dipublikasi` yang lebih lama tetap terbaca karena hasil lama merujuknya.
- `ditarik` tidak dipakai untuk pindaian baru; hasil yang sudah merujuknya tidak diubah otomatis (K4).
- Pindaian, usulan, konfirmasi, dan data pengukuran mencatat `formulir.kode`, `formulir.revisi`, `versi_skema` yang dipakainya.
- Foto dari revisi yang tidak punya skema `dipublikasi` ditolak dengan `revisi_tidak_dikenal` (menawarkan pendaftaran dari PDF kosong), tidak pernah dibaca memakai revisi tetangga (prinsip OCR 24 Nov: kertas berubah = sistem tidak menebak).
- Skema terikat organisasi (`organization_id`), seperti semua data lab (`AGENTS.md` Lapisan lain). Data milik lab lain dijawab 404.

## 6. Endpoint

Konvensi (dari `docs/kontrak-api.md` bagian 0): `Authorization: Bearer`, tanggal ISO 8601, sukses dibungkus `{ "data": ... }`, validasi `422 {message, errors}`, galat mesin-terbaca memakai kolom `kode` di badan (mis. `akun_nonaktif`, `pemisahan_wewenang`), data lab lain `404`. Tidak ada awalan `/v1` pada rute lab; versi kontrak dibawa `kontrak_versi` di badan dan header `X-Kontrak-Versi: 0.1` di jawaban rute baru.

### 6.1 Yang SUDAH ada (dirujuk, tidak diubah oleh v0.1) @79280fc2

Peran: `auth:sanctum` + `aplikasi:internal` + `role:admin,teknisi` (`routes/api.php:622`, `:795`; super admin lolos lewat `EnsureUserHasRole::lolosTulisSuperAdmin`), semuanya di balik saklar `fitur:pindai_lembar` (`routes/api.php:884`).

| Metode dan path | Rute | Controller | Catatan |
|---|---|---|---|
| `GET /api/worksheet-templates` | `routes/api.php:885` | `WorksheetScanController.php:69` | Daftar templat dikenal + kesiapan formulir asli. |
| `GET /api/worksheet-templates/{kode}` | `routes/api.php:886` | `WorksheetScanController.php:95` | Geometri dari `database/ocr-templates/`, bukan dari skema v0.1. |
| `POST /api/worksheet-scans` | `routes/api.php:889` | `WorksheetScanController.php:149` | Jalur HP: bacaan teks per sel dikirim dari HP. `WorksheetScanRequest.php` memegang bentuknya. |
| `GET /api/worksheet-scans/{id}` | `routes/api.php:891` | `WorksheetScanController.php:253` | |
| `GET /api/worksheet-scans/{id}/sel/{kunci}/crop` | `routes/api.php:894` | `WorksheetScanController.php:272` | |
| `POST /api/worksheet-scans/{id}/koreksi` | `routes/api.php:897` | `WorksheetScanController.php:370` | |
| `POST /api/ocr/halaman` | `routes/api.php:920` | `OcrHalamanController::baca` | Mengirim SELURUH foto halaman; di luar lingkup GO Cloud Vision (`KOORDINASI.md` bagian 10 butir 1). Tetap mati. |
| `POST /api/dokumen/baca` | `routes/api.php:912` | `DokumenGenerikController::baca` | Jalur LLM, `VISION_AKTIF`. |

v0.1 TIDAK mengubah bentuk satu pun rute di atas (aturan prompt OCR: kontrak lama tidak diubah; tambah endpoint baru). Status per sel di jalur HP (`hijau`, `kuning`, `merah`, `kosong`; `ValidasiSel.php:24-30`) adalah kontrak jalur itu dan TIDAK sama dengan `status` di `usulan-ocr`; jangan memetakan keduanya satu-satu.

Master standar dan metode yang sudah ada (dipakai apa adanya): `GET /api/standards` dan `/{standard}` (`routes/api.php:714-715`, semua peran internal), `POST/PUT/DELETE /api/standards` (`routes/api.php:1098-1100`, `role:admin`), `GET /api/calibration-methods` (`routes/api.php:689`), tulis `routes/api.php:1125-1127`.

### 6.2 Yang BARU, spesifikasi saja, BELUM diimplementasi

Tidak ada satu pun rute di tabel ini yang ada di kode. Peran memakai middleware yang sudah ada.

**A. Penyuntingan skema (akun Studio; grup `['auth:sanctum','studio']`, awalan `/api/studio`, pola `routes/api.php:455`)**

| Metode dan path | Fungsi | Badan / parameter | Sukses |
|---|---|---|---|
| `GET /api/studio/skema-formulir` | Daftar semua status | `?kode=&revisi=&status=` | `200 {data:[{formulir, versi_skema, status, jumlah_item, jumlah_perlu_dicek, diubah_pada}], meta}` |
| `GET /api/studio/skema-formulir/{kode}/{revisi}/{versi}` | Satu skema | | `200 {data: skema-formulir}` |
| `POST /api/studio/skema-formulir` | Buat draf (impor draf, salinan versi lain, atau hasil layanan OCR) | dokumen `skema-formulir` berstatus `draf`; `versi_skema` = versi tertinggi sekarang + 1 (atau 1) | `201 {data: skema-formulir}` |
| `POST /api/studio/skema-formulir/dari-pdf` | Buat draf dari PDF kosong | multipart `pdf`, `kode`, `revisi` | `202`; bentuk jawaban menunggu `RENCANA-OCR.md` (sketsa) |
| `PUT /api/studio/skema-formulir/{kode}/{revisi}/{versi}` | Sunting draf | dokumen penuh + `alasan?` | `200`; server mencatat selisih per butir |
| `POST .../{versi}/ajukan` | `draf → ditinjau` | `{alasan}` | `200` |
| `GET .../{versi}/banding/{revisi_lain}/{versi_lain}` | Selisih dua skema + usulan pencocokan id | | `200 {data:{ditambah:[id], dihapus:[id], diubah:[{id, field:[...]}], pasangan_usulan:[{id_lama,id_baru,dasar}]}}` (sketsa) |
| `GET .../{versi}/riwayat` | Jejak audit skema | | `200 {data:[{aksi, pelaku, waktu, alasan, sebelum, sesudah}]}` |

**B. Wewenang publikasi dan baca (akun lab; `aplikasi:internal`)**

| Metode dan path | Peran | Fungsi |
|---|---|---|
| `POST /api/skema-formulir/{kode}/{revisi}/{versi}/publikasikan` | `role:super_admin`, grup sendiri seperti `routes/api.php:533` | `ditinjau → dipublikasi`; `{alasan}`; `422 pemisahan_wewenang` bila pelakunya ikut membuat/menyunting/mengajukan |
| `POST .../kembalikan` | `role:super_admin` | `ditinjau → draf`; `{alasan}` |
| `POST .../tarik` | `role:super_admin` | `dipublikasi → ditarik`; `{alasan}` |
| `GET /api/skema-formulir` | `aplikasi:internal,studio` + `role:admin,teknisi,viewer` (pola `routes/api.php:497`) | Daftar versi aktif per (kode, revisi). Teknisi/viewer hanya melihat `dipublikasi`; selain itu 404 (pola Data Acuan). |
| `GET /api/skema-formulir/{kode}/{revisi}` | idem | Skema versi aktif; `?versi=N` untuk versi tertentu. Admin dan super admin boleh semua status. |

**C. Pindaian (OCR, mobile)** — `aplikasi:internal`, `role:admin,teknisi` (super admin lewat gerbang yang sama), di balik saklar fitur sendiri; pembacaan awan atas potongan sel memakai saklar TERPISAH dari `ocr_cloud` (`KOORDINASI.md` bagian 10 butir 1).

| Metode dan path | Fungsi | Badan | Sukses |
|---|---|---|---|
| `POST /api/pindaian` | Unggah foto; server mengenali formulir, membaca, menyimpan usulan | multipart: `foto`, `kontrak_versi`; opsional `calibration_session_id`, `equipment_id`, `kode` + `revisi` (bila teknisi memilih sendiri); header `Idempotency-Key` WAJIB | `201 {data: usulan-ocr}`; unggahan ulang dengan kunci sama `200` dengan dokumen yang sama, tidak membuat pindaian kedua |
| `GET /api/pindaian/{pindai_id}` | Buka ulang | | `200 {data:{usulan: usulan-ocr, konfirmasi:[konfirmasi]}}`; lab lain atau milik teknisi lain `404` (pola `WorksheetScanController::pastikanBolehLihat`, `:683`) |
| `GET /api/pindaian/{pindai_id}/potongan/{item_id}` | Potongan citra satu kotak | | gambar |
| `POST /api/pindaian/{pindai_id}/konfirmasi` | Konfirmasi manusia | `konfirmasi-permintaan` | `201 {data: konfirmasi}` |
| `POST /api/pindaian/{pindai_id}/terapkan` | Menulis konfirmasi terakhir ke sesi kalibrasi draf | `{calibration_session_id}` | SKETSA: bentuk final menunggu definisi lembar kerja satu alat (T1). Server yang menerapkan; mobile tidak menyusun payload kalibrasi sendiri. |

Konfirmasi harus menandai pengonfirmasi sebagai `raw_measurements.verified_by` sehingga penjaga yang sudah ada (`PemisahanWewenang::KODE_PENYETUJU_MEMVERIFIKASI_OCR`, `PemisahanWewenang.php:55`; pengonfirmasi pindai tidak boleh menyetujui sesi) tetap menegakkannya.

**D. Data master dan header**

| Metode dan path | Peran | Fungsi |
|---|---|---|
| `GET /api/master/{daftar}` (`standar`, `metode`) | `aplikasi:internal,studio` + `role:admin,teknisi,viewer` | `200 data-master`; `?berlaku_saja=true` untuk standar |
| `GET /api/calibrations/{calibration}/header-pekerjaan` | peran yang sama dengan `GET /calibrations/{id}`; teknisi hanya sesinya sendiri | `200 {data: header-pekerjaan}` |

**Antarmuka internal Laravel ke layanan OCR** bukan endpoint publik dan rancangannya milik terminal OCR (`RENCANA-OCR.md`). Kontrak hanya mengikat dua hal: masukan layanan memuat skema `dipublikasi` lengkap (layanan tidak menyimpan skema), dan `item[]` yang dikembalikannya harus lulus bentuk `usulan-ocr.item`. Laravel yang menerbitkan `pindai_id`, menyimpan usulan, dan menegakkan izin.

### 6.3 Kode galat baru (badan `{message, kode, ...}`)

| `kode` | HTTP | Kapan | Isi tambahan |
|---|---|---|---|
| `kontrak_versi_tidak_didukung` | 422 | `kontrak_versi` bukan yang didukung server | `didukung: ["0.1"]` |
| `skema_tidak_valid` | 422 | gagal JSON Schema atau aturan bagian 7 | `errors: {"/item/3/peran": ["..."]}` (kunci = JSON Pointer) |
| `versi_skema_bentrok` | 409 | `versi_skema` sudah ada | |
| `skema_tidak_draf` | 409 | menyunting skema yang bukan `draf` | `status` |
| `skema_belum_lengkap` | 422 | `ajukan` dengan butir `belum_ditentukan` atau `perlu_dicek` | jumlah dan `item_id` pertama |
| `pemisahan_wewenang` | 422 | pelaku publikasi ikut membuat/menyunting/mengajukan | `temuan` (meniru `PemisahanWewenang`) |
| `id_item_ganda` | 422 | `id` kembar dalam satu dokumen | |
| `id_item_berganti_arti` | 422 | `id` yang sudah punya data dipakai untuk kotak lain, atau `id` pensiun dipakai ulang | |
| `formulir_tidak_pasti` | 422 | skor rendah atau selisih dengan kandidat kedua kecil | `kandidat: [{kode, revisi, skor}]`; klien meminta teknisi memilih |
| `revisi_tidak_dikenal` | 422 | kode dikenal tetapi tidak ada skema `dipublikasi` untuk revisi itu | `kode`, `revisi_terbaca`, tawaran pendaftaran dari PDF kosong |
| `foto_ditolak` | 422 | buram, gelap, silau, atau halaman tak ditemukan | `alasan: [...]`; klien kembali ke isian manual |
| `konfirmasi_belum_lengkap` | 422 | konfirmasi pertama tidak mencakup semua butir `ragu` dan `tidak_terbaca` | `item_id: [...]` |
| `alasan_wajib` | 422 | `nilai_final` berbeda dari nilai sebelumnya tanpa alasan | `item_id` |
| `nilai_tidak_sesuai_peran` | 422 | format nilai tidak cocok peran (bagian 7, R8) | `item_id`, `peran` |
| `standar_kedaluwarsa` | 422 | `master_id` menunjuk standar `expired` | `master_id` |
| `fitur_dimatikan` | 503 | saklar fitur mati (kode yang sudah dipakai `fitur:pindai_lembar`) | |

### 6.4 Prasyarat yang bukan bagian bentuk data

- **CORS.** Tidak ada `config/cors.php` dan `git grep -i cors` di `bootstrap/app.php`, `config`, `app/Http/Middleware` pada `79280fc2` kosong. Studio Web (browser, origin lain) hanya bisa memanggil rute bagian 6.2A setelah CORS dikonfigurasi untuk `api/login` dan `api/studio/*` (header `Authorization`, `X-Klien`, `Content-Type`, `Idempotency-Key`; ekspos `Retry-After`, `X-RateLimit-*`, `X-Kontrak-Versi`). Itu pekerjaan implementasi T4, belum dikerjakan, dan harus diuji dari origin Studio sungguhan.
- **Format angka.** Di kontrak angka selalu bertitik desimal (R8) dan nilai pengukuran dibawa sebagai teks. Tampilan berkoma (`id-ID`) adalah urusan klien; klien mengonversi sebelum mengirim. Jangan mengandalkan server menafsirkan `"1,5"`: pada `/api/studio/workbook/{id}/hitung` string itu tetap TEKS (`docs/kontrak-api.md:3730`).

## 7. Aturan di luar JSON Schema

JSON Schema tidak bisa menyatakan perbandingan antar-field atau antar-dokumen. Aturan berikut tetap bagian kontrak; server menegakkannya dan tes kontrak (bagian 9) memeriksa semua contoh sah terhadapnya.

| Kode | Aturan |
|---|---|
| R1 | `bbox_pt`: `x0 < x1`, `y0 < y1`, dan seluruhnya di dalam `halaman_pt`. |
| R2 | `item[].id` unik dalam satu dokumen skema. |
| R3 | `sel_input` ada di daftar input definisi lembar kerja yang dirujuk. |
| R4 | `usulan-ocr.item[].item_id` ada di skema yang ditunjuk `(kode, revisi, versi_skema)` dan berperan `isian_angka`, `isian_teks`, `centang`, atau `tanggal`. |
| R5 | `sepakat_dua_pembacaan`: paling sedikit dua `pembacaan` dari `pembaca` BERBEDA dengan `teks` sama persis, dan sama dengan `nilai_usulan`. |
| R6 | Peran `centang`: `nilai_usulan` dan `nilai_final` hanya `"1"` atau `"0"` (atau `null` bila kosong di `nilai_final`). |
| R7 | Konfirmasi `urutan` 1 mencakup semua butir `ragu` dan `tidak_terbaca`. |
| R8 | `nilai_final` per peran: `isian_angka` `^-?[0-9]+(\.[0-9]+)?$` (titik desimal; klien mengonversi koma); `tanggal` `YYYY-MM-DD`; `centang` R6; `isian_teks` tidak kosong, dipangkas. |
| R9 | `konfirmasi.nilai_sebelum` pada `urutan` n > 1 = `nilai_final` butir yang sama pada catatan sebelumnya; `nilai_usulan` selalu salinan usulan mesin. |
| R10 | `konfirmasi.diubah` = (`nilai_final` ≠ `nilai_sebelum`). |
| R11 | Skema yang ditunjuk usulan berstatus `dipublikasi`. |
| R12 | Semua id (`pindai_id`, `master_id`, skema) dalam organisasi pemanggil; selain itu 404. |
| R13 | `master_id` cocok dengan slot butirnya (`standar_*` dan `thermohygro` ke `standards.id`, `metode_kalibrasi` ke `calibration_methods.id`). |
| R14 | `item[].id` yang dipertahankan antar versi tidak berganti `jenis` dan kunci label (bagian 4.3 butir 3 dan 5). |

## 8. Versi kontrak

Format `depan.belakang`, diambil dari draf dan dipakai persis.

- **Naik belakang** (`0.1 → 0.2`): perubahan yang kompatibel, yaitu menambah field OPSIONAL baru atau mengendurkan batasan. Contoh yang sudah diketahui: `halaman` per item untuk formulir multi-halaman.
- **Naik depan** (`0.x → 1.0`): perubahan yang memutus. Termasuk: mengganti nama atau membuang field, menambah nilai ke enum yang sudah ada, mengetatkan batasan, mengubah arti field, mengubah nama atau metode endpoint.
- Tiap dokumen membawa `kontrak_versi` (konstanta di schema) dan tiap jawaban rute baru membawa `X-Kontrak-Versi`. Schema memakai `additionalProperties: false`, jadi dokumen versi lebih tinggi sengaja GAGAL divalidasi schema versi lama; konsumen menarik versi baru sebelum melanjutkan (draf kontrak 5.3).
- Folder versi tidak diubah isinya setelah dipakai konsumen, kecuali salah ketik di penjelasan. Perubahan nyata = folder baru (`docs/kontrak/v0.2/`) dan baris di bagian 11. `schema/SHA256SUMS.txt` memegang hash isi (akhir baris dinormalkan LF); tes kontrak gagal bila schema berubah tanpa hash diperbarui, supaya perubahan diam-diam tidak lolos.
- Usul perubahan: `KOORDINASI.md` bagian 11 (siapa, apa, alasan, dampak). Hanya T4 yang menyetujui dan menaikkan versi.
- Kontrak API lama dan data lama tidak boleh rusak; migrasi dijelaskan dan diuji.

## 9. Cara membuktikan "nyambung"

- Tes kontrak API: `tests/Unit/Kontrak/KontrakV01Test.php`. Memuat semua schema, memeriksa hanya memakai kata kunci JSON Schema yang diizinkan, memeriksa `$defs` bernama sama identik antar-berkas, memvalidasi SEMUA contoh sah (harus lulus) dan SEMUA contoh tidak sah (harus gagal, di jalur dan kata kunci yang tertulis di `contoh/MANIFEST.json`, bukan gagal karena sebab lain), memeriksa R1, R2, R4, R5, R6, R9, R10 pada contoh sah, dan memeriksa hash schema.
- Tiap konsumen menyalin `schema/` dan `contoh/` (atau menariknya) dan menjalankan pemeriksaan yang sama dengan validator bahasanya. Salinan wajib lulus pemeriksaan `SHA256SUMS.txt`.
- Contoh sah saling terhubung: skema `SIDIK-FM-CAL-9999 rev 1 versi 1` → usulan `pdi-01jd8k2m4q9x` → permintaan konfirmasi → catatan konfirmasi urutan 1 → koreksi urutan 2. Kode 9999 dan koordinatnya dikarang untuk memperlihatkan bentuk; satu-satunya contoh dari data nyata adalah `valid-draf-konversi-0513-rev4.json`.
- Alur ujung ke ujung (draf kontrak 6.2) baru bisa diuji setelah endpoint bagian 6.2 diimplementasi dan satu alat dipilih T1; belum ada.

## 10. Keputusan terbuka (butuh Zainul dan/atau Manajer Teknis)

| No | Pertanyaan | Usulan sementara T4 | Yang memutuskan |
|---|---|---|---|
| K1 | Siapa yang boleh MEMUBLIKASIKAN skema formulir? | `super_admin` lab yang bukan pembuat/penyunting/pengaju (A di bagian 5.1). Alternatif B: akun Studio sendiri, alasan wajib. | Zainul dan Manajer Teknis |
| K2 | Siapa yang boleh membuat, menyunting, dan mengajukan draf? | Hanya akun Studio (satu akun, K-50-12). Alternatif C: `admin` (Master Data) juga, lewat aplikasi lab. | Zainul |
| K3 | Apakah publikasi wajib didahului uji pada foto contoh? | Wajib bila sudah ada foto contoh untuk kode dan revisi itu; bila belum, boleh dengan alasan tercatat. | Manajer Teknis |
| K4 | Akibat menarik skema yang sudah dipakai hasil? | Hasil lama TIDAK diubah otomatis; ditandai untuk ditinjau. | Manajer Teknis |
| K5 | Bagaimana kotak centang daftar standar di kertas dikaitkan ke butir master tertentu (label cetak, urutan, tulisan tangan)? | v0.1 hanya mengikat kotak ke slot; manusia memilih `master_id` saat konfirmasi. | Orang lab / Manajer Teknis |
| K6 | Standar mana yang boleh dipilih untuk slot `thermohygro`? Tidak ada penanda khusus di `standards` (hanya `parameter_kondisi` terisi atau tidak; belum diverifikasi sebagai aturan lab). | Semua standar, difilter tampilan. | Manajer Teknis dan T1 |
| K7 | "Technician ID", "Checked by", dan "rata-rata suhu/RH" di header Excel berasal dari field mana? | Kontrak menyediakan semua kandidat (bagian 4.9). | T1 (bukti sel) dan Manajer Teknis |
| K8 | Skema formulir per organisasi atau global? | Per organisasi (`organization_id`), selaras semua data lab. Saat ini satu lab. | Zainul |

## 11. Riwayat

| Versi | Tanggal | Perubahan |
|---|---|---|
| 0.1 | 24 Nov 2026 | Versi pertama. Dasar `79280fc2`. |
