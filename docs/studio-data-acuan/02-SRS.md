# 02 — SRS Studio Data Acuan

Istilah: **paket** = seluruh data acuan satu profil alat (mis. `micrometer`). **Versi** = satu
isi paket yang tidak bisa diubah sesudah diajukan. **Lembar acuan** = satu tab di Studio, setara
satu sheet acuan di workbook. **Sel** = satu nilai di lembar acuan.

## 1. Kebutuhan fungsional

### A. Melihat

**FR-01 Daftar paket.** Given pengguna ber-izin `data-acuan.lihat` When membuka Studio Then tampil
semua paket organisasinya: nama alat, kode profil, nomor versi aktif + tanggal berlaku, jumlah
draf terbuka, versi terjadwal, peringatan standar mendekati `berlaku_sampai` (≤ 30 hari).
Paket yang belum dimigrasi (profil analitik, fase 4) tampil abu-abu "belum bisa disunting".

**FR-02 Lembar acuan per alat.** Given paket dipilih When dibuka Then tab-tab yang tampil adalah
yang dideklarasikan **skema paket** profil itu (`skemaAcuan()`, 03 ADR-03), bukan tab generik.
Tiap kolom punya: label, satuan, tipe (`desimal`, `bulat`, `teks`, `tanggal`, `pilihan`),
batas valid, asal (`Sheet!Sel` workbook atau "sertifikat standar"), dan apakah boleh disunting.

**FR-03 Asal sel.** Given sel dipilih When panel detail dibuka Then tampil: nilai persis
tersimpan (tanpa pembulatan tampilan), asal workbook (`berkas`, `sheet`, `sel`, `sha256 workbook`
bila ada), versi yang pertama memuat nilai ini, siapa menyunting/mengesahkan, dan daftar rumus
(baca saja) yang memakai sel ini.

**FR-04 Versi berlaku pada tanggal.** Given paket dan tanggal When ditanya Then sistem menjawab
versi yang berlaku pada tanggal itu (atau `null` + alasan). Sama dengan cara
`RumusKalibrasi::versiUntukProfil()` mencari versi rumus.

### B. Menyunting

**FR-05 Buat draf.** Given `data-acuan.sunting` When "Buat draf" dari versi X Then lahir versi
berstatus `draf` dengan isi salinan X, `dasar_versi_id = X`, pemilik = pembuat. Paket boleh
punya beberapa draf (milik orang berbeda).

**FR-06 Sunting sel.** Given draf milik saya When saya mengubah sel Then server memvalidasi tipe,
satuan, batas, dan aturan lembar (BR-07); yang lolos dicatat di `paket_acuan_suntingan`
(lama, baru, alasan opsional per sel, waktu). Yang gagal ditolak per sel dengan pesan yang
menyebut kolom, nilai, dan batasnya. Penyimpanan otomatis per 2 detik diam.

**FR-07 Tempel blok.** Given saya menempel teks tab-separated (dari Excel/PDF) When ditempel
Then pratinjau menunjukkan sel yang akan berubah dan sel yang ditolak sebelum diterapkan;
desimal koma dan titik dibaca sesuai BR-04.

**FR-08 Tambah/hapus baris.** Hanya di lembar yang skemanya mengizinkan (mis. balok ukur: boleh
tambah keping; pita CMC: tidak). Menghapus baris yang dipakai titik pra-cetak lembar kerja
ditolak dengan menyebut titiknya (mis. nominal 5,1 mm memakai keping 1,1 + 2,5 + 1,5).

**FR-09 Kunci edit.** Draf memakai `lock_versi`; simpan dengan `If-Match` usang → 409 + daftar
sel yang diubah orang lain. Draf hanya bisa disunting pemiliknya; `super_admin` boleh mengambil
alih dengan alasan (tercatat).

**FR-10 Unggah workbook (F1).** Given `data-acuan.impor` When mengunggah `.xlsm/.xlsx`
(≤ 20 MB) Then: berkas utuh + sha256 disimpan; hanya sel di **peta sel** versi workbook itu yang
dibaca; sidik jari rumus sel perhitungan dibandingkan; hasilnya draf baru atau status
`struktur_berubah`. Kode error mengikuti F1: `peta_sel_belum_ada`, `struktur_berubah`,
`berkas_terenkripsi`, `sel_kosong`, `satuan_tidak_cocok`, `berkas_kembar`.

**FR-11 Banding versi.** Given dua versi When dibandingkan Then tampil per lembar: sel berubah
(lama → baru, selisih absolut & relatif), baris tambah/hapus, dan ringkasan jumlah.

### C. Simulasi & uji

**FR-12 Simulasi dampak.** Given draf When "Simulasikan" Then job menghitung ulang, dengan isi
draf, **semua sesi tersimpan** profil itu (semua status) + **semua fixture master** profil itu,
tanpa menyimpan apa pun ke sesi. Laporan per sesi: nilai antara berubah (kolom, lama, baru),
U95 berubah, **angka cetak** berubah (sesudah pembulatan per alat: `desimalSertifikat`,
`desimalU95`, `desimalFaktorCakupan`), keputusan PASS/FAIL berubah, lantai CMC terpicu/lepas.

**FR-13 Simulasi kedaluwarsa.** Draf yang disunting sesudah simulasi menandai simulasinya
`kedaluwarsa`; pengajuan butuh simulasi terbaru (`simulasi_belum_ada`).

**FR-14 Uji pembanding.** Given paket punya vektor uji (fixture master hasil workbook **dihitung
ulang**, bukan nilai cache) When simulasi berjalan Then tiap vektor diadu per komponen budget,
bukan cuma U95 akhir, dengan toleransi yang ditetapkan Lab per alat (06 §3). Gagal = tidak bisa
diajukan, kecuali perubahannya memang dimaksud menggeser vektor itu — maka pengaju wajib
menyertakan vektor pengganti beserta asalnya.

### D. Persetujuan & berlaku

**FR-15 Ajukan.** Given draf dengan simulasi terbaru When diajukan dengan `alasan` (wajib) dan
`rujukan[]` (≥ 1: no. sertifikat standar / no. IK / halaman+butir lampiran LK-285-IDN / alamat
sel master) Then status `diajukan`, isi dibekukan (sha256 dihitung), pengesah dikabari.

**FR-16 Sahkan.** Given `data-acuan.sahkan` dan saya **bukan** pembuat/penyunting/pengaju versi
itu When "Sahkan" dengan konfirmasi sandi Then status `terjadwal` (bila `berlaku_mulai` > hari
ini) atau `aktif`; rentang versi sebelumnya ditutup dalam **satu transaksi**; sinyal
`data_acuan` disiarkan.

**FR-17 Tolak / kembalikan.** Pengesah boleh `tolak` (final, alasan wajib) atau `kembalikan`
(jadi draf baru milik pengaju, berdasar versi itu, catatan wajib).

**FR-18 Berlaku mundur.** `berlaku_mulai` < tanggal pengesahan hanya boleh bila simulasi
menunjukkan **nol** sertifikat terbit yang angka cetaknya berubah; kalau ada, ditolak
`berlaku_mundur_menyentuh_terbit` — itu urusan ketidaksesuaian, bukan konfigurasi.

**FR-19 Tarik versi aktif.** `super_admin` boleh menarik versi aktif (alasan wajib) → versi
sebelumnya berlaku lagi untuk sesi baru; sesi yang sudah dihitung dengan versi tertarik dan
belum terbit ditahan (`menunggu_keputusan_tm`) sampai dihitung ulang atau diputuskan.

### E. Sampai ke perangkat

**FR-20 Stempel.** Setiap `uncertainty_calculations` baru menyimpan `paket_acuan_versi_id` dari
versi yang berlaku pada `tanggal_kalibrasi` sesi; sesi menyimpan `bentuk_versi_id` saat lembar
pertama kali disimpan.

**FR-21 Lembar kerja membawa versi.** `GET /calibrations/lembar-kerja` mengembalikan
`versi_acuan` + `ETag`. Klien yang mengirim `If-None-Match` sama → 304.

**FR-22 Sinyal.** Sesudah sah/aktif/tarik, server menyiarkan
`PerubahanDataOrganisasi(jenis: 'data_acuan', aksi: 'aktif'|'terjadwal'|'ditarik', id)` lewat
`siarkanAman()`. Versi terjadwal disiarkan lagi saat tanggalnya tiba (scheduler harian).

**FR-23 Draf terdampak.** Given teknisi membuka draf yang distempel versi lama dan versi baru
berlaku untuk `tanggal_kalibrasi`-nya When dihitung ulang Then banner menyebut versi lama → baru
dan sel acuan yang berubah; angka sebelumnya tetap bisa dilihat.

### F. Bentuk lembar (lapis 3)

**FR-24 Sunting bentuk.** Given `bentuk-lembar.sunting` When mengubah label, urutan, satuan
tampilan (dari daftar yang diizinkan profil), jumlah titik (dalam `[min,max]` profil),
wajib/opsional field non-kontrak Then pratinjau lembar dirender memakai renderer yang sama
dengan HP.

**FR-25 Kontrak input.** Perubahan bentuk yang menghapus/menyembunyikan field di
`kontrakInput()` profil, mengubah kunci field, atau memakai jenis field di luar kosakata
`form_dinamis` versi minimum klien → ditolak `kontrak_input_dilanggar` / `klien_belum_mendukung`.

### G. Rumus (lapis 2) — baca saja

**FR-26 Peta rumus.** Tab "Rumus" menampilkan rantai perhitungan profil (nama besaran, rumus
dalam notasi matematika, sel master asalnya, komponen budget) dari deklarasi kode. Tidak ada
tombol simpan.

**FR-27 Usulan rumus.** "Usulkan perubahan rumus" membuat catatan terstruktur (alat, besaran,
sel master, rumus sekarang, rumus usulan, alasan, rujukan, lampiran workbook) yang diekspor
sebagai Markdown untuk issue/PR. Tidak mengubah perhitungan.

### H. Jejak

**FR-28 Riwayat.** Tiap paket punya garis waktu versi; tiap sel punya riwayat nilai lintas versi.
Ekspor CSV + PDF ringkas "Laporan Perubahan Data Acuan" per versi (untuk rekaman mutu).

**FR-29 Pengingat standar.** Tiap hari, standar dalam paket yang `berlaku_sampai`-nya ≤ 30/7/1
hari memicu notifikasi ke Master Data & super admin.

## 2. Aturan bisnis

| ID | Aturan |
|---|---|
| BR-01 | Versi `diajukan` ke atas **tidak bisa diubah isinya**. Perubahan = versi baru |
| BR-02 | Versi yang pernah menstempel hasil hitung tidak bisa dihapus; hanya `pensiun`/`ditarik` |
| BR-03 | Rentang berlaku versi aktif/terjadwal satu paket tidak boleh tumpang tindih (pola `FormulaVersion::bentrokRentang`) |
| BR-04 | Angka disimpan sebagai **string desimal kanonik** persis seperti diketik/diimpor (`"1.00011"`), tidak pernah dibulatkan. Input `1,00011` dan `1.00011` keduanya diterima; `1.000,11` / `1,000.11` (pemisah ribuan) **ditolak** sebagai ambigu |
| BR-05 | Nilai antara tidak pernah dibulatkan (AGENTS.md §Olah data 5). Pembulatan hanya di tampilan grid (bisa diganti "tampilkan presisi penuh") dan render sertifikat |
| BR-06 | Satuan disimpan per kolom dari skema, bukan per sel. Konversi satuan **tidak** dilakukan di Studio |
| BR-07 | Aturan lembar dari skema: kunci unik (mis. nominal balok ukur unik dengan toleransi 1e-9, pola `TabelStandarMicrometer::nilaiTerkoreksi`), monoton (pita CMC), tidak kosong untuk kolom wajib. **Sel kosong tidak pernah dibaca nol** (aturan `IFERROR(…,"")`) |
| BR-08 | Pengesah ≠ pembuat, penyunting, pengaju, atau pengunggah workbook versi itu. Tanpa pengecualian, termasuk `super_admin` (pola K-30-03) |
| BR-09 | `berlaku_mulai` default = hari pengesahan; boleh di masa depan; mundur hanya lewat FR-18 |
| BR-10 | Sesi dihitung dengan versi yang berlaku pada `tanggal_kalibrasi`, bukan versi aktif hari ini |
| BR-11 | Sertifikat terbit tidak pernah dihitung ulang karena versi baru |
| BR-12 | Data identitas standar yang juga ada di tabel `standards` (serial, no. sertifikat, `berlaku_sampai`) harus konsisten: Studio menunjukkan ketidakcocokan, tidak menimpa tabel `standards` diam-diam |
| BR-13 | Nama/alamat pelanggan tidak pernah masuk paket (sapuan K27) |

## 3. Status & transisi

```
          ┌──────────── buang (pemilik) ──────────► dibuang
          │
draf ──ajukan──► diajukan ──sahkan──► terjadwal ──(tanggal tiba)──► aktif ──(versi baru aktif)──► pensiun
  ▲                 │   │                 │                          │
  │                 │   └──tolak──► ditolak  └──batalkan jadwal──► ditolak
  └──kembalikan─────┘                                              └──tarik──► ditarik
```

| Dari | Ke | Siapa | Syarat |
|---|---|---|---|
| — | draf | Master Data, super admin | izin sunting |
| draf | dibuang | pemilik draf | belum pernah diajukan |
| draf | diajukan | pemilik draf | simulasi terbaru `selesai`, uji pembanding hijau, alasan + ≥1 rujukan |
| diajukan | terjadwal / aktif | super admin, bukan BR-08 | sandi dikonfirmasi; BR-03; FR-18 |
| diajukan | ditolak | super admin | alasan |
| diajukan | draf (baru) | super admin | catatan |
| terjadwal | aktif | sistem (scheduler) | tanggal tiba |
| terjadwal | ditolak | super admin | alasan |
| aktif | pensiun | sistem | versi lain menjadi aktif |
| aktif | ditarik | super admin | alasan; FR-19 |

## 4. Matriks izin

| Aksi | Izin | teknisi | viewer | admin (Master Data) | super_admin |
|---|---|---|---|---|---|
| Lihat paket & versi aktif | `data-acuan.lihat` | ✓ (aktif saja) | ✓ | ✓ | ✓ |
| Lihat draf, simulasi, riwayat sel | `data-acuan.lihat` | — | ✓ | ✓ | ✓ |
| Buat/sunting draf | `data-acuan.sunting` | — | — | ✓ | ✓ |
| Unggah workbook | `data-acuan.impor` | — | — | ✓ | ✓ |
| Jalankan simulasi | `data-acuan.sunting` | — | — | ✓ | ✓ |
| Ajukan | `data-acuan.ajukan` | — | — | ✓ | ✓ |
| Sahkan / tolak / kembalikan / tarik | `data-acuan.sahkan` | — | — | — | ✓ (BR-08) |
| Sunting bentuk lembar (draf) | `bentuk-lembar.sunting` | — | — | ✓ | ✓ |
| Lihat peta rumus / kirim usulan | `data-acuan.lihat` | — | ✓ lihat | ✓ | ✓ |

Ditegakkan di server (`role:` + `MatriksIzin` + policy versi). Menyembunyikan tombol hanya
kenyamanan. Data organisasi lain dijawab 404.

## 5. Kebutuhan non-fungsional

| ID | Kebutuhan |
|---|---|
| NFR-01 | Grid 2.000 baris × 20 kolom gulir mulus di laptop kantor (virtualisasi baris) |
| NFR-02 | Simpan sel ≤ 300 ms p95; simulasi 500 sesi ≤ 2 menit, berjalan di antrean, bisa ditinggal |
| NFR-03 | Isi versi + sha256 dihitung dari JSON kanonik (kunci terurut, angka sebagai string) — dua server menghasilkan sha256 sama |
| NFR-04 | Semua aksi tulis ber-`throttle:` seperti rute tulis lain |
| NFR-05 | Semua tulis tercatat `audit_logs` (trait `Diaudit`) + `paket_acuan_suntingan` |
| NFR-06 | Studio hanya di panel desktop (Windows/macOS). HP: baca versi aktif saja |
| NFR-07 | Tanpa jaringan: Studio baca-saja dari cache terakhir dengan label "offline — tidak bisa menyimpan" |
| NFR-08 | Uji SQLite + MySQL hijau; `decimal`/string angka diuji di keduanya |
