# Definisi lembar kerja: kerangka (v0.1)

Status: **KERANGKA**. Hanya identitas rujukannya yang mengikat di v0.1 (`{profil, versi}`, dipakai `skema-formulir.definisi_lembar_kerja`). Isi di bawah menamai bagian-bagiannya dan bukti yang dituntut tiap bagian; nama field dan JSON Schema-nya BELUM final dan baru dibekukan (kontrak 0.2) setelah Terminal 1 menyerahkan matriks SATU alat yang sudah diverifikasi. Tidak ada rumus, angka, atau alamat sel di berkas ini: semuanya akan datang dari workbook master dengan bukti, bukan dari tebakan.

## Apa dan bukan apa

Definisi lembar kerja adalah catatan berversi, per alat, yang mengikat tiga hal: (1) sel di workbook master yang divalidasi lab, (2) kode atau Lembar Hitung yang menghitungnya di server, (3) kolom aplikasi dan kolom sertifikat yang memakai hasilnya.

- BUKAN mesin hitung baru. Untuk alat yang belum punya Lembar Hitung aktif, rumus tetap kode PHP di profil (`AGENTS.md` bagian "Olah data", butir 1, lapis 2: PR kode + test rekonsiliasi master). Studio tidak mengubah rumus.
- BUKAN pengganti `App\Support\*Mentah`, `CalibrationValidator`, atau `HitungUlangSesi`. Alat baru tetap wajib lahir bersama jalur hitung ulangnya (`AGENTS.md` bagian "Aturan yang Lahir dari Kesalahan Nyata").
- Gunanya: (a) daftar sel input yang boleh dirujuk pemetaan skema formulir; (b) sumber uji pembanding terhadap workbook yang dihitung ulang; (c) kamus label untuk OCR; (d) tempat konflik Excel lawan kertas dicatat dan menahan alat.
- Penyimpanan belum diputuskan. Sebelum menambah tabel (kolom baru = pilihan terakhir, `AGENTS.md` Alur Kerja butir 4), T4 memeriksa dulu apakah workbook versi Studio, `formula_versions`, atau `paket_acuan_versi` sudah cukup menampungnya.

## Identitas dan versi

| Field | Arti | Aturan |
|---|---|---|
| `profil` | `CalibrationProfile::kode()`, mis. `micrometer`, `ph_meter` | Pola `^[a-z][a-z0-9_]{1,59}$`. Satu definisi aktif per profil. |
| `versi` | bilangan bulat ≥ 1 | Naik tiap perubahan isi. Versi yang sudah dirujuk skema `dipublikasi`, usulan, atau hasil tidak pernah diubah atau dihapus; hanya dipensiunkan (sama dengan `AGENTS.md` Olah data butir 2). |
| `status_bukti` | `TERBUKTI` atau `SIAP-MENUNGGU` | Definisi kerja `KOORDINASI.md` bagian 14: TERBUKTI = ada uji pembanding terhadap workbook yang dihitung ulang, dengan bukti angka. SIAP-MENUNGGU = masuk sistem tetapi belum tervalidasi karena menunggu workbook atau jawaban orang. Tidak boleh `TERBUKTI` tanpa bukti. |

## Bagian dan bukti yang dituntut

Tiap butir di semua bagian membawa `sumber`: nama berkas workbook, lembar, alamat sel (`Sheet!C12`), dan nilai hasil hitung ulang (bukan nilai cache) bila butir itu hasil perhitungan. Sandi workbook tidak pernah ditulis di mana pun.

1. **Sumber.** Nama berkas workbook, lembar yang relevan, hash isi berkas, siapa yang memeriksa, kapan. Salinan workbook tanpa password hanya di `C:\Users\USER\bahan-uji-excel\` dan tidak masuk repo.
2. **Input.** Per sel masukan: `kunci` stabil, `sel`, tipe (angka, teks, pilihan, tanggal), satuan, rentang sah beserta asalnya (validasi data di workbook atau metode), wajib atau tidak, dan kolom aplikasi tujuannya (mis. sumbu `peran_sensor` / `sensor_ke` / `tahap` pada `raw_measurements`, atau blok `spesifikasi_alat`). Sel tersembunyi dan nama rentang (named range) dicatat apa adanya.
3. **Rumus.** Teks rumus Excel apa adanya (tidak ditulis ulang), daftar dependensi (kunci yang dibutuhkan), lookup dan referensi silang ke lembar lain atau tabel acuan (dan paketnya di Data Acuan, bila ada), serta makro/VBA yang memengaruhi hasil. Selisih rumus dengan kode yang sudah ada TIDAK diselesaikan sendiri: dicatat sebagai konflik.
4. **Hasil antara.** Kunci, sel, satuan. Tidak dibulatkan: pembulatan di lapisan render sertifikat saja (`AGENTS.md` Olah data butir 5; workbook master pun memakai format tampilan, bukan `ROUND()`).
5. **Hasil akhir.** Kunci, sel, satuan, dan aturan angka cetak (`desimalSertifikat`, `desimalU95`, `desimalFaktorCakupan` di profil) beserta asalnya.
6. **Kolom sertifikat.** Tiap kolom tercetak dipetakan ke satu hasil akhir (atau input) dengan format cetaknya. Sertifikat sah harus sama persis dengan sheet SERTIFIKAT di workbook master.
7. **Header pekerjaan.** Sel di workbook yang bersesuaian dengan `header-pekerjaan` (nomor sertifikat, nomor order, ID teknisi, metode, standar, thermohygro, tanggal terbit, penandatangan). Dipakai menjawab K7 di KONTRAK.md.
8. **Kamus label OCR.** Untuk tiap input: label cetak di formulir (baris dan kolom, dan variasi tulisnya), satuan yang tercetak, dan formulir (`kode`, `revisi`) tempat label itu berlaku. Pemetaan label ke input hanya usulan dengan skor; pemetaan jadi sah setelah disetujui manusia sekali per revisi formulir (prompt OCR D).
9. **Konflik.** Selisih Excel lawan kertas, atau Excel lawan kode: uraian, bukti (kedua sisi dengan sel), status (`terbuka`, `diputuskan`), siapa yang memutuskan dan kapan. Alat dengan konflik `terbuka` berstatus menunggu keputusan dan hasilnya tidak dipakai untuk sertifikat; tempat penahanan yang sudah ada: `PenahananProfil` (dipakai `CalibrationResource.php:92` @79280fc2).
10. **Uji pembanding.** Kasus: data normal, batas rentang, percabangan, data tidak valid, pembulatan. Tiap kasus: nilai input per kunci, nilai yang diharapkan per kunci dan sel (dari workbook yang dihitung ulang), tanggal hitung ulang, toleransi beserta dasarnya (resolusi atau metode yang disetujui, bukan "asal lulus"). Selisih ditelusuri sampai sel, rumus, atau satuan.

## Hubungan ke bagian lain kontrak

```
kotak kertas (skema-formulir.item.id)
   └─ pemetaan.sel_input  ──►  definisi.input.sel        (harus ada; KONTRAK.md R3)
                                   └─ kolom aplikasi      (definisi.input)
                                         └─ perhitungan di server (profil / Lembar Hitung)
                                               └─ hasil akhir ──► kolom sertifikat
```

Skema formulir menunjuk satu versi definisi (`definisi_lembar_kerja {profil, versi}`). Bila definisi berganti versi dan alamat sel bergeser, pemetaan dibuat ulang lewat skema formulir versi baru; tidak ada penunjukan "ke versi terbaru".

## Yang ditunggu dari Terminal 1

Satu alat dulu. Agar kerangka ini bisa dibekukan jadi JSON Schema, matriks verifikasinya harus memuat, untuk alat itu, minimal:

1. Daftar input dengan lembar, sel, satuan, rentang, dan kolom aplikasi.
2. Rumus hasil antara dan hasil akhir dengan dependensi dan nilai hitung ulang.
3. Aturan pembulatan angka cetak dan kolom sertifikat.
4. Sel header pekerjaan (menjawab K7).
5. Lookup dan tabel acuan yang dipakai, termasuk apakah sudah ada di Data Acuan.
6. Makro/VBA: ada atau tidak, dan bila ada apa efeknya pada hasil.
7. Set uji pembanding minimal (normal, batas, tidak valid, pembulatan) dengan nilai dari workbook yang dihitung ulang.
8. Konflik terbuka lawan kertas, bila ada.

Sampai itu ada, T4 tidak menulis rumus, tidak mengaktifkan workbook, dan tidak menandai alat apa pun `TERBUKTI`.
