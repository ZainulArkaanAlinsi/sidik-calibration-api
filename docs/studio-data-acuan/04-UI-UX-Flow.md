# 04 — UI/UX Flow Studio Data Acuan

Mockup visual: artefak desain "Studio Data Acuan — Panel Desktop" (dikirim terpisah). Dokumen
ini adalah acuan perilakunya. Gaya mengikuti keputusan 26 Sep: tidak ada gradasi warna campur,
mode terang/gelap tetap; "meja kerja lab" yang tenang. Angka memakai font tabular (lebar sama)
supaya kolom desimal sejajar.

## 1. Daftar layar

| Layar | Status | Peran | Isi |
|---|---|---|---|
| S1 Daftar Paket | **baru** | semua lab | kartu/tabel paket, versi aktif, draf, peringatan standar |
| S2 Studio (grid) | **baru** | Master Data, SA; lainnya baca | tab lembar per alat, grid, panel sel, bilah draf |
| S3 Banding Versi | **baru** | semua kecuali teknisi | dua versi berdampingan, sel berubah disorot |
| S4 Simulasi Dampak | **baru** | MD, SA, viewer | ringkasan + daftar sesi + vektor uji |
| S5 Ajukan | **baru** (dialog) | MD, SA | alasan, rujukan, ringkasan beda + simulasi |
| S6 Pengesahan Acuan | **baru** | SA | antrean versi `diajukan`; satu layar keputusan |
| S7 Riwayat Paket & Sel | **baru** | semua kecuali teknisi | garis waktu versi; riwayat satu sel |
| S8 Peta Rumus | **baru**, baca saja | MD, SA, viewer | rantai besaran, sel master, komponen budget; tombol "Usulkan perubahan" |
| S9 Editor Bentuk Lembar | **baru** (fase 3) | MD, SA | daftar field + pratinjau lembar seperti di HP |
| S10 Unggah Workbook | **baru** (fase 3) | MD, SA | seret-lepas `.xlsm`, laporan peta sel |
| DesktopShell | **ubah** | — | seksi baru "Data Acuan": Paket, Pengesahan Acuan (SA), dengan lencana jumlah |
| `rumus_list_screen.dart` | **ubah** | — | tautan "lihat data acuan yang dipakai versi ini" |
| Lembar kerja HP | **ubah kecil** | teknisi | banner draf terdampak; label versi data acuan di kaki lembar |
| Layar perhitungan admin (`perhitungan_screen.dart`) | **ubah kecil** | MD | baris "Data acuan: Micrometer v3 (a1b2c3…)" yang bisa diketuk |
| Yang dihapus | — | — | tidak ada |

## 2. Alur utama: rekalibrasi standar Micrometer

```
S1 ─pilih "Micrometer"─► S2 (versi aktif v1, baca saja)
   ─"Buat draf"─► S2 (draf v2, bisa sunting)
   ─tempel kolom "nilai terkoreksi" dari sertifikat baru─► pratinjau tempel ─terapkan─►
   sel berubah disorot kuning, nilai lama tampil kecil dicoret
   ─ubah tab "standar": tanggal_kalibrasi, no. sertifikat─►
   ─"Simulasikan"─► S4 (berjalan; boleh ditinggal) ─► selesai: 12 sesi, 3 angka cetak berubah, 0 terbit
   ─"Ajukan"─► S5: alasan + rujukan "Sertifikat LK-410-IDN no. …" ─► kirim
S6 (SA, orang lain) ─buka v2─► beda + simulasi + rujukan di satu layar
   ─"Sahkan", berlaku mulai = tanggal sertifikat baru, sandi─► v2 aktif
   ─► HP teknisi: lembar Micrometer berikutnya memuat v2 (≤3 menit / saat dibuka)
```

## 3. Tab per alat — mengikuti workbook, bukan seragam

Tab dibangun dari `skemaAcuan()` profil. Contoh dari master yang ada (Lampiran A):

| Alat | Tab Studio | Sheet master asal |
|---|---|---|
| Micrometer (4 rentang) | Standar · Balok Ukur · Ketidakpastian Balok · Pita CMC · Titik Pra-cetak · Konstanta · *Rumus* · *Bentuk Lembar* | `Standar_GB`, `DATABASE` (bagian CMC), `PERHITUNGAN` (konstanta) |
| Timbangan (gram/kg/substitusi) | Standar AT · Drift AT (E2, F1, F2, M2 …) · Pita CMC · Keluarga Drift | `STANDAR_AT`, `Drift AT …` |
| Anak Timbangan | STD AT · MPE AT · Densitas · Data Sensitivitas per neraca · Drift AT | `STD AT`, `MPE AT`, `Data Sens …`, `Drift AT …` |
| Suhu (TIDS/TITS/Enclosure/Thermocouple/Thermometer gelas) | Kalibrator · Sensor PT100 · FC PRT · TC Type K · TC Type N · Interpolasi · Variasi Dryblock A/B · Stabilitas | `STANDAR-…`, `SENSOR PT100`, `FC Prt Pt100`, `TERMOCOUPLE TYPE K/N`, `Interpolasi`, `Variasi axial …`, `stdev drywell` |
| Waktu (Timer/Stopwatch, Centrifuge, Tachometer) | Standar · Sertifikat Kalibrator · Drift · Human Reaction | `SERTIFIKAT KALIBRATOR`, `Drift …`, `Human Reaction` |
| Gaya (UTM, Load Cell, Proving Ring) | Standar Load Cell · Drift · Faktor Satuan | `STANDAR_LOADCELL` |
| Volumetric glassware | Pita CMC · Diameter ISO 4787 · Koefisien Muai · Koreksi Suhu · Neraca | `Tabel_Maximum_Internal_Diameter`, `Tabel_Koefisien_Muai_Bahan`, `STANDARD_KALIBRATOR` |
| Viscometer (fase 4) | MPE Visco · Pengaruh Temperatur | `MPE Visco`, `Tabel Pengaruh Temperature` |

Sheet `INPUT DATA` ↔ tab **Bentuk Lembar**; `PERHITUNGAN*` ↔ tab **Rumus** (baca saja);
`SERTIFIKAT` ↔ pratinjau sertifikat di S4. Dari sheet `DATABASE` hanya sel yang dipetakan
(mis. pita CMC Micrometer) yang dibaca; baris data pelanggan di sheet itu **tidak pernah** masuk
Studio (BR-13).

## 4. Anatomi S2 Studio

```
┌ Bilah atas: Micrometer › Draf v2 (milik Rina) · disimpan 10:42 · ● Online ─────────────┐
│ [Simulasikan] [Banding dgn v1] [Ajukan…]                        lock 14 · 7 sel diubah  │
├ Tab: Standar | Balok Ukur● | Ketidakpastian | Pita CMC | Titik | Konstanta | Rumus | Bentuk
├──────────────────────────────────────────────────────────┬──────────────────────────────┤
│ Bilah rumus:  R11 = 1,00011  mm   asal: Standar_GB!R11   │ Panel sel                    │
├────┬────────────┬──────────────────┬────────────┬────────┤ Nilai tersimpan: 1.00011      │
│ #  │ Nominal mm │ Nilai terkoreksi │ U95 µm     │ Status │ Asal: Standar_GB!R11 (v1)    │
│ 1  │ 1,0        │ 1,00011          │ 0,12       │        │ Diubah: — · Disahkan: —      │
│ 2  │ 1,1        │ 1,09989 → 1,09991│ 0,12       │ diubah │ Dipakai di: u_balok,          │
│ …  │            │                  │            │        │ titik A-3 (5,1 mm)           │
└────┴────────────┴──────────────────┴────────────┴────────┴──────────────────────────────┘
  Kaki: 32 baris · 1 sel ditolak (lihat) · Tampilan dibulatkan 5 desimal [presisi penuh]
```

Interaksi keyboard ala Excel: panah, Tab/Shift+Tab, Enter/Shift+Enter, F2 sunting, Esc batal,
Ctrl+C/V (TSV), Ctrl+Z/Y dalam sesi draf, Ctrl+F cari, Ctrl+S simpan paksa. Sel terkunci
(asal "dipatok IK", kolom kunci) ditandai gembok dan menolak ketik dengan alasan.

## 5. Semua state

| Layar | Memuat | Kosong | Error | Tanpa izin | Offline | Konflik |
|---|---|---|---|---|---|---|
| S1 | kerangka kartu | "Belum ada paket — jalankan migrasi versi 1" (hanya SA melihat petunjuknya) | pesan + Coba lagi | menu tidak tampil; rute langsung → layar "Butuh izin data-acuan.lihat" | daftar dari cache + label "offline sejak 10:31" | — |
| S2 | grid kerangka | lembar tanpa baris: "Lembar ini belum berisi data" + (bila boleh) Tambah baris | sel gagal simpan diberi garis merah + alasan; bilah atas "2 perubahan belum tersimpan" | baca saja, tombol sunting tidak tampil | baca saja; ketikan ditolak dengan toast "offline — tidak tersimpan" | 409: dialog daftar sel yang diubah orang lain, pilih muat ulang (perubahan saya disimpan sebagai catatan) |
| S3 | — | "Tidak ada perbedaan" | — | teknisi: tidak tersedia | cache | — |
| S4 | progres per sesi (n/N), boleh ditinggal | "Belum ada sesi tersimpan untuk alat ini — hanya vektor uji yang dijalankan" | job gagal: alasan + sesi penyebab | — | tampil hasil terakhir | simulasi kedaluwarsa: pita kuning + Jalankan ulang |
| S5 | — | — | validasi di dekat field (alasan kosong, rujukan kurang) | tombol tidak tampil | dinonaktifkan | `simulasi_kedaluwarsa` → arahkan ke S4 |
| S6 | — | "Tidak ada yang menunggu pengesahan" | `pengesah_ikut_menyunting`: kartu merah menjelaskan aturan, tanpa tombol | MD: tidak tampil | dinonaktifkan | versi sudah disahkan orang lain → muat ulang |
| S8 | — | "Profil ini belum mendeklarasikan peta rumus" | — | — | cache | — |
| HP lembar | — | — | — | — | memakai versi terakhir yang diketahui + label "data acuan belum diperbarui" | banner draf terdampak |

## 6. Banner draf terdampak (HP & desktop)

> **Data acuan Micrometer berubah sejak draf ini dibuat (v1 → v2).**
> 3 nilai standar berbeda. Angka hasil di bawah sudah memakai v2. [Lihat bedanya]

Muncul bila `bentuk_versi_id`/stempel draf ≠ versi yang berlaku untuk `tanggal_kalibrasi`-nya.
Tidak menghalangi pengiriman; admin melihat catatan yang sama di antrean.

## 7. Bahasa layar

- "Data acuan", bukan "database" atau "rumus".
- "Sahkan", bukan "Simpan", untuk aksi yang membuat versi berlaku.
- Angka di layar memakai koma desimal Indonesia; tooltip menunjukkan nilai tersimpan persis.
- Setiap tombol yang mengubah status menyebut akibatnya: "Sahkan — berlaku untuk sesi
  bertanggal 12 Okt 2026 ke atas di semua perangkat".
