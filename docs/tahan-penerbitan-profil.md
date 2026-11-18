# Penahanan penerbitan sertifikat per jenis alat (18 Nov 2026)

Keputusan pemilik 18 Nov 2026: **sertifikat salah di lab terakreditasi lebih berbahaya daripada sertifikat
tertunda.** Tujuh jenis alat tidak bisa diterbitkan sampai Technical Manager (TM) menjawab butir yang
bersangkutan di `SIDIK-PERTANYAAN-UNTUK-TECHNICAL-MANAGER.md`. Sumber penilaiannya peta 48 profil
(rating "JANGAN TERBITKAN"). **Yang ditahan hanya penerbitan.** Mengisi lembar, pratinjau, simpan, dan
hitung tetap jalan, dan tidak satu angka pun diubah (aturan olah data butir 5).

## Tujuh jenis alat

| Jenis alat | Kode profil | Butir TM | Cakupan | Sumber penahanan |
|---|---|---|---|---|
| Timbangan **kg** | `timbangan` | TM-1 | hanya varian `kg` (gram & substitusi tidak) | `config/sertifikat.php` `profil_ditahan` |
| Proving Ring | `proving_ring` | TM-4 | semua sesi | config |
| Bath | `bath` | TM-8 | semua sesi | config |
| Picnometer | `picnometer` | TM-7 | semua sesi | config |
| Pressure Gauge | `pressure_gauge` | TM-3 | sesi yang memicu cacat master P-1/P-2/P-11 | log metode (sudah ada sejak 28 Sep) |
| Vacuum Gauge | `vacuum_gauge` | TM-3 | idem | log metode |
| Differential Pressure | `differential_pressure` | TM-3 | idem | log metode |

Tekanan SENGAJA tidak ditulis di config: penahanannya sudah ada, bersyarat (kalibrator SPMK tidak
terkena cacatnya, keputusan 28 Sep), dan dilepas lewat jawaban TM di
`database/data/log-metode-tekanan-piston.json`. Menulis `pressure_gauge` di config berarti menahan
SEMUA varian termasuk SPMK; itu keputusan pemilik, bukan efek samping.

## Cara kerja: satu daftar, satu hook, tiga pintu

```
config/sertifikat.php  profil_ditahan  ──┐
log metode Tekanan/Piston ───────────────┤  CalibrationProfile::penahanTerbit($sesi)   (satu hook)
                                         │      ├─ CalibrationValidator → temuan ERROR `menunggu_keputusan_tm`
                                         │      └─ App\Services\PenahananProfil::periksa($sesi)
                                         │             ├─ pintu 1  CalibrationController::approve()   → 422 `profil_ditahan`
                                         │             ├─ pintu 2  tombol Setujui panel               → notifikasi
                                         │             └─ pintu 3  job GenerateCertificate            → sertifikat `gagal`
```

- **Hook** `CalibrationProfile::penahanTerbit()` sudah dipakai Tekanan dan Piston sebelum ini. Kelas
  dasarnya sekarang membaca daftar config (lewat `PenahananProfil::butirDariConfig()`); profil yang
  menimpanya wajib memanggil `parent::penahanTerbit()` (Tekanan dan Piston sudah; dijaga
  `ProfilDitahanTest::test_profil_lain_tidak_ditahan_dan_semua_kode_terdaftar_dibaca_hook_profilnya`).
  Tidak ada mekanisme kedua.
- **Varian.** Entri ber-`varian` hanya menahan sesi bervarian itu; profil yang varian-nya per sesi
  menimpa `CalibrationProfile::varianPenahan()` (Timbangan sudah, aturannya sama dengan hitungannya).
  Varian yang tidak bisa ditentukan pada entri ber-`varian` ikut ditahan.
- **Entri yang rusak tetap menahan** (bukan array, alasan kosong, `varian` kosong): salah ketik tidak
  boleh diam-diam melepas. Kode yang tidak terdaftar sebagai profil ditolak `ProfilDitahanTest`.

## Perilaku menurut keadaan sesi

| Keadaan | Yang terjadi |
|---|---|
| `draft` / `perlu_revisi` | Bebas diisi, dipratinjau, disimpan, dihitung. Respons sesi membawa `penerbitan_ditahan.ditahan: true`. |
| `menunggu_approval` | `approve` (API dan panel) dijawab `422 profil_ditahan` sebelum pemeriksaan lain. Status tidak berubah, nomor yang diketik belum terpakai, tidak ada job, satu baris `audit_logs` `penerbitan_ditahan`. |
| `disetujui`, sertifikat belum ada atau `menunggu_generate` (disetujui SEBELUM penahanan dipasang) | Sesi tetap `disetujui` (tidak dimundurkan). Job penerbitan menolak: baris sertifikat dijadikan `gagal` (nomor yang sudah dipegang tetap; tanpa PDF), admin dapat notifikasi `SertifikatGagal` berisi alasannya, dan audit mencatat pintu `job_terbit`. Penyapu `sertifikat:sapu-tertunda` tidak mendorongnya lagi (hanya `menunggu_generate`). |
| sertifikat `gagal` | "Terbitkan ulang" menjalankan job yang sama: tetap `gagal` dan tercatat lagi sampai penahanan dilepas; sesudah dilepas tombol itu menerbitkannya seperti biasa. |
| sertifikat `terbit` | Tidak disentuh. `penerbitan_ditahan.ditahan` untuk sesinya `false` (yang ditahan penerbitan BARU). |
| sertifikat ditarik (K-50-03), terbit ulang `-R<n>` | Lewat `approve()`, jadi ditahan juga. |
| `ReviseCertificate` (revisi sertifikat yang sudah terbit) | Tidak diubah paket ini; lihat "Belum dicakup". |

## Melepas satu jenis alat (PR kecil, bukan env)

Jawaban TM masuk, lalu satu PR:

1. Hapus entri jenis alat itu dari `config/sertifikat.php` `profil_ditahan` (Timbangan: hapus
   `'kg'` dari `varian` kalau hanya satu varian yang dijawab; hapus seluruh entri kalau semuanya).
   **Tekanan:** jawab di log metode (versi baru yang menurunkan `tahan_terbit`), bukan di config.
2. Sesuaikan penjaga: hapus kasusnya dari `ProfilDitahanTest::jenisAlatDiConfig()` /
   `varianTimbangan()` dan dari daftar di `test_kode_di_config_terdaftar_dan_isinya_lengkap()`.
   Suite sengaja merah kalau entri hilang tanpa perubahan ini: pelepasan harus kelihatan di PR.
3. Sertakan buktinya di PR: jawaban TM, sel master yang diadu, dan angka cetak sebelum/sesudah.
   Penahanan dilepas sesudah angkanya terbukti, bukan sebelum.
4. Sesi yang sempat `gagal` karena job menolak: admin menekan "Terbitkan ulang" sesudah deploy.

JANGAN melepas lewat variabel lingkungan Render: angka di sertifikat tidak boleh bergantung pada
setelan dashboard yang bisa tertimpa sinkron blueprint.

## Jejak audit

`audit_logs.action = 'penerbitan_ditahan'`, entitas `calibration_sessions`, `changed_by` = yang menekan
(atau yang menyetujui, untuk job), `new_data` = `{pintu: approve_api | approve_panel | job_terbit,
profil, varian, rujukan_tm, alasan, status_sesi, peran_pelaku}`, `note` = pesan yang sama dengan `422`
(dipotong `AuditLog::catatan()` di 500 karakter; teks lengkap di `new_data.alasan`). Status sesi tidak
berubah, jadi baris `diubah` otomatis tidak ada; baris ini satu-satunya bukti percobaannya. Tiap klik
"Setujui" yang ditolak menulis satu baris.

## Tes

- `tests/Feature/ProfilDitahanTest.php` (sesi kosong, tiap jenis alat): `422` + status + nomor + job +
  audit, urutan di depan pemeriksaan lain, varian Timbangan, profil lain tidak terpengaruh (semua 48
  profil), melepas satu entri, config rusak, panel, job (tiga keadaan sertifikat), field klien, panjang
  kolom audit.
- `tests/Feature/ProfilDitahanDataSeederTest.php` (sesi contoh seeder): ketujuh jenis alat ditolak dengan
  data nyata, hitung/validasi tetap jalan, Tekanan bersyarat, pratinjau dan simpan tetap jalan.
- Mutan yang dicoba dan harus merah: cabut gerbang di `approve()`, cabut di panel, cabut di job, salah
  ketik kode profil di config, `varian` dilebarkan/dihapus, hook Tekanan lupa `parent::`.

## Belum dicakup (dicatat, bukan lupa)

- `ReviseCertificate` / `RevisiSertifikat` (revisi dokumen yang SUDAH terbit) tidak diubah.
- Tekanan: penahanan bersyarat dibaca dari hasil hitung tersimpan, jadi lembar yang belum pernah
  disimpan belum bisa memberi tahu klien. Klien tahu begitu simpan pertama (respons simpan membawa
  `penerbitan_ditahan`).
- Gram `xlsm2` (TM-2), Gaya (TM-5), Anak Timbangan (TM-6) dan seterusnya TIDAK ditahan: peta
  menilainya "HATI-HATI", bukan "JANGAN TERBITKAN".
- Gate MySQL penuh (3 jam) wajib sebelum modul disebut selesai; paket ini baru dijalankan di SQLite
  dan pembagian shard.
