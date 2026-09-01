# Perintah Frontend — Timer/Stopwatch, Centrifuge, Infrared Tachometer

Dokumen **berdiri sendiri**: tempel utuh ke sesi kerja frontend/mobile, tidak
perlu membaca berkas lain.

Backend alat ke-22/23/24 (kelompok akreditasi **"Waktu dan Frekuensi"**,
LK-285-IDN no. 37, 38, 39) sudah selesai. Kelompok itu sekarang **lengkap** —
ketiga alat di lampiran sudah punya profil.

---

## 1. Ringkas: apa yang berubah untuk frontend

| Alat | Kode profil | Bentuk lembar | Yang baru buat FE |
|---|---|---|---|
| Timer/Stopwatch | `timer_stopwatch` | **2 tabel** (standar & UUT), tiap ulangan **4 kotak** J/M/S/ms | Bentuk input baru |
| Centrifuge | `centrifuge` | 1 tabel, 5 ulangan/titik | Ikut pola tabel yang sudah ada |
| Infrared Tachometer | `tachometer` | 1 tabel, 5 ulangan/titik | Ikut pola tabel yang sudah ada |

Kedua alat rpm **tidak butuh layar baru** — bentuknya tabel titik biasa. Yang
butuh perhatian cuma Timer/Stopwatch.

---

## 2. Alur lengkap: teknisi → admin → sertifikat

Alurnya **sama persis** dengan 21 alat sebelumnya — tidak ada langkah baru.
Yang berbeda cuma isi lembar kerjanya.

```
TEKNISI (HP)                    SERVER                      ADMIN (panel)
─────────────                   ──────                      ─────────────
1. Pilih alat
   └ GET /worksheets/{kode}  →  bentukLembarKerja()
                                 profil dipilih dari
                                 nama_alat_kemampuan
2. Isi lembar
   (manual / kamera)
3. Kirim
   └ POST /calibrations     →  susunBlokWaktu()  atau
                                jalur tabel datar
                                     ↓
                                simpan raw_measurements
                                     ↓
                                hitungPerGrup()          ← OTOMATIS, di sini
                                     ↓
                                simpan uncertainty_calculations
                                     ↓
                                status = menunggu_approval
                                                     →   4. Antrean approval
                                                          └ lihat data mentah
                                                            + hasil hitung
                                                            + peringatan sesi
                                                     ←   5a. Revisi (+catatan)
                                                          5b. Setujui
                                                              ↓
                                GenerateCertificate (queue)
                                     ↓
                                nomor sertifikat (transaction lock)
                                     ↓
                                snapshot + PDF + QR AES-256
                                     ↓
6. Notifikasi                  ←  arsip sertifikat
   "Sertifikat terbit"
```

**Titik pengolahan otomatisnya di langkah 3** — begitu teknisi menekan kirim,
server langsung: merata-rata pembacaan, mencari koreksi standar dari sertifikat
kalibrator, menyusun budget ketidakpastian, menghitung `uc` → `veff` → `k` →
`U`, lalu menerapkan lantai CMC. Teknisi **tidak menghitung apa pun**, dan admin
**tidak mengetik ulang apa pun** — admin cuma menyetujui atau menolak.

### Yang WAJIB ditampilkan ke admin

Respons `POST /calibrations` dan `GET /calibrations/{id}` memuat dua hal yang
tidak boleh disembunyikan:

- **`belum_dihitung`** — daftar `{titik_ke, alasan}`. Titik yang **sengaja
  diblokir** berikut alasannya dalam bahasa manusia. Ini bukan error; ini titik
  yang datanya tidak cukup. **Tampilkan alasannya apa adanya.**
- **`peringatan`** — untuk Centrifuge, muncul kalau ada set point di atas
  9000 rpm (di luar pita akreditasi). Admin harus melewatinya secara sadar.

> ⚠️ Jangan menampilkan `belum_dihitung` sebagai "gagal" berwarna merah menakutkan.
> Titik yang diblokir itu perilaku yang **benar** — kalau UI-nya terkesan seperti
> kerusakan, teknisi akan mengakalinya dengan mengisi nol, dan nol itulah yang
> justru melahirkan titik palsu.

---

## 3. Endpoint

Tidak ada endpoint baru. Yang dipakai persis sama:

| Metode | Path | Catatan |
|---|---|---|
| `GET` | `/api/worksheets/{kode}` | `kode` = `timer_stopwatch` / `centrifuge` / `tachometer` |
| `POST` | `/api/calibrations` | payload di §4 & §5 |
| `PUT` | `/api/calibrations/{id}` | sama bentuknya |
| `GET` | `/api/calibrations/{id}` | hasil + `belum_dihitung` + `peringatan` |

---

## 4. Payload — Centrifuge & Tachometer (tabel datar biasa)

Satu titik = **satu set point** (penunjukan alat pelanggan) + **lima pembacaan
tachometer standar**.

```jsonc
{
  "equipment_id": 42,
  "tanggal_kalibrasi": "2026-09-01",
  "suhu_awal": 21.3, "suhu_akhir": 21.5,
  "kelembaban_awal": 53, "kelembaban_akhir": 56,
  "measurements": [
    { "titik_ukur": 60,  "pembacaan": [59.9, 60.0, 60.0, 60.0, 60.0] },
    { "titik_ukur": 80,  "pembacaan": [80.2, 80.2, 80.2, 80.1, 80.1] },
    { "titik_ukur": 100, "pembacaan": [99.8, 99.8, 99.8, 100.0, 99.9] }
  ]
}
```

### Yang HARUS diketahui FE

1. **`titik_ukur` itu penunjukan ALAT PELANGGAN**, bukan pembacaan standar.
   Untuk centrifuge: putaran yang disetel di alatnya. Kolom `pembacaan` yang
   berisi bacaan tachometer standar lab.

2. **U95 lahir per TIGA titik berurutan.** Titik 1–3 berbagi satu U95, titik
   4–6 berbagi U95 yang lain, dan seterusnya — mengikuti geometri lembar master
   (tiga kolom ukur per baris). Jadi **urutan titik menentukan angka**. Jangan
   mengurutkan ulang daftar titik di sisi FE.

3. **Set point yang tidak dipakai: kosongkan, jangan isi nol.** Titik ber-set
   point 0 diblokir dengan alasan, tapi lebih baik tidak dikirim sama sekali.

4. Nominal sertifikat kalibrator **dipilih server** (terdekat, seri ke atas) —
   FE tidak perlu mengirim apa pun soal itu.

---

## 5. Payload — Timer/Stopwatch (dua deret, empat kotak per ulangan)

Ini satu-satunya yang bentuknya baru.

```jsonc
{
  "equipment_id": 77,
  "tanggal_kalibrasi": "2026-09-01",
  "measurements": [
    {
      "titik_ukur": 60,                       // SET POINT dalam DETIK
      "standar": [                            // stopwatch standar lab
        { "jam": 0, "menit": 1, "detik": 0, "milidetik": 123 },
        { "jam": 0, "menit": 1, "detik": 0, "milidetik": 211 },
        { "jam": 0, "menit": 1, "detik": 0, "milidetik": 45  }
      ],
      "uut": [                                // alat pelanggan
        { "jam": 0, "menit": 1, "detik": 0, "milidetik": 131 },
        { "jam": 0, "menit": 1, "detik": 0, "milidetik": 219 },
        { "jam": 0, "menit": 1, "detik": 0, "milidetik": 61  }
      ]
    }
  ]
}
```

### Aturan yang mengikat

1. **`titik_ukur` dalam DETIK.** Lembar master menulis set point dalam menit
   (1, 5, 10, 15, 30) — FE yang mengalikan 60 sebelum mengirim. Set point 15
   menit → `titik_ukur: 900`.

2. **Ulangan ke-i sisi standar berpasangan dengan ulangan ke-i sisi UUT.**
   Keduanya ditekan berbarengan; koreksinya selisih rata-rata keduanya. Jumlah
   ulangan kedua sisi **harus sama** — kalau tidak, titiknya ditolak dengan
   alasan.

3. **Boleh juga mengirim angka polos** sebagai total milidetik
   (`"standar": [60123, 60211, 60045]`). Disediakan untuk test & seeder; untuk
   HP pakai bentuk objek supaya angkanya sama dengan yang tertera di layar
   stopwatch.

4. **Kotak kosong ≠ nol.** Ulangan yang keempat kotaknya kosong dilewati. Titik
   yang semua ulangannya kosong diblokir — di workbook master, lima titik
   kosong justru melahirkan koreksi 30 ms yang tercetak seperti titik sungguhan.

### Bentuk layar yang disarankan

Empat kotak kecil bersebelahan per ulangan, dengan pemisah yang jelas:

```
Set point 1 menit (60 detik)

  Stopwatch Standar          Alat Pelanggan
  ┌──┬──┬──┬─────┐           ┌──┬──┬──┬─────┐
1 │ 0│ 1│ 0│ 123 │         1 │ 0│ 1│ 0│ 131 │
2 │ 0│ 1│ 0│ 211 │         2 │ 0│ 1│ 0│ 219 │
3 │ 0│ 1│ 0│  45 │         3 │ 0│ 1│ 0│  61 │
  └──┴──┴──┴─────┘           └──┴──┴──┴─────┘
   J  M  S   ms               J  M  S   ms
```

Kotak `J` dan `M` hampir selalu sama di ketiga ulangan — pertimbangkan
mengisinya otomatis dari set point dan membiarkan teknisi cuma mengetik `ms`.
Itu yang sebenarnya berubah antar ulangan.

---

## 6. Bentuk respons hasil

```jsonc
{
  "data": {
    "id": 918,
    "nomor_sesi": "CAL/2026/09/0007",
    "status": "menunggu_approval",
    "uncertainty_calculations": [
      {
        "titik_ke": 1,
        "titik_ukur": 60,
        "rata_rata": 59.98,          // rata-rata pembacaan standar (rpm)
        "koreksi": -0.22,            // yang DICETAK di sertifikat
        "error": 0.22,
        "standar_deviasi": 0.0447,
        "jumlah_pengulangan": 5,
        "ketidakpastian_diperluas": 2.62,   // sudah lewat lantai CMC
        "faktor_cakupan_k": 1.96,
        "keputusan": null            // kelompok ini TIDAK divonis PASS/FAIL
      }
    ],
    "belum_dihitung": [],
    "peringatan": []
  }
}
```

### Dua hal yang beda dari alat lain

- **`keputusan` selalu `null`.** Baik lampiran akreditasi maupun ketiga workbook
  master tidak menyebut satu pun batas keberterimaan, jadi memvonis PASS/FAIL
  berarti mengarang ambang di dokumen terakreditasi. **Jangan menggambar chip
  PASS/FAIL untuk ketiga alat ini** — kolomnya dikosongkan, bukan diisi "-".

- **Desimal tampil**, disalin dari format sel masternya:

  | Alat | Koreksi | U95 |
  |---|---|---|
  | Centrifuge & Tachometer | 2 desimal (`-0,22`) | 1 desimal (`2,6`) |
  | Timer/Stopwatch | 3 desimal (`-0,041`) | 2 desimal (`0,81`) |

---

## 7. Kolom sertifikat

### Kolom `Standard` TIDAK sama dengan set point

Ini beda dari sepuluh alat lain, dan gampang salah digambar.

Buat pH Meter dan kawan-kawan, `titik_ukur` ADALAH nilai acuan (buffer 4,01
datang dari sertifikat larutan), jadi kolom `Standard` = `titik_ukur`. Buat
kelompok ini kebalikannya: yang dibaca berulang justru STANDARNYA, dan
`titik_ukur` menyimpan **set point** — penunjukan alat pelanggan.

Jadi tabel hasilnya:

| Kolom | Isinya | Dari |
|---|---|---|
| `Standard Value` | penunjukan standar yang SUDAH dikoreksi | `unit_under_test + correction` |
| `Unit Under Test` | set point (rpm) / rata-rata penunjukan alat (Timer) | `rata_rata` |
| `Correction` | nilai benar − penunjukan alat | `koreksi` |

Server sudah mengirim ketiganya jadi di snapshot sertifikat — **FE tidak perlu
menghitung apa pun**. Yang penting: jangan menggambar `titik_ukur` sebagai
"Standard" di layar riwayat/preview, karena untuk ketiga alat ini itu bukan
nilai acuan. Identitas `Standard = UUT + Correction` berlaku di setiap baris;
kalau layar Anda tidak menjumlah, ada yang salah pemetaan.



Judul kolom UUT untuk kedua alat rpm adalah **`Setting`**, bukan `UUT` —
karena yang dicatat di situ penunjukan yang **disetel**, dan kolom `Standard`
yang berisi hasil ukur standar yang sudah dikoreksi. Server sudah mengirimnya
lewat `judul_kolom_uut`; FE tinggal memakai apa adanya, jangan di-hardcode.

---

## 8. Yang TIDAK perlu dikerjakan FE

- **Tidak ada kolom database baru.** Ketiga alat mendarat dengan nol kolom baru
  di `raw_measurements` — memakai sumbu `peran_sensor`/`sensor_ke` yang sudah ada.
- **Tidak ada template OCR.** Ketiga lembar belum punya nomor formulir
  `SIDIK-FM-` sendiri (yang ada di workbook cuma `SIDIK-FM-CAL-2403` = footer
  sertifikat), jadi jalur kamera belum dibuka. `bentukPindaiFoto()['didukung']`
  memulangkan `false`. **Input manual dulu.**
- **Tidak ada perubahan alur approval.** Persis sama dengan 21 alat sebelumnya.

---

## 9. Catatan untuk QA

Angka acuan yang bisa dipakai menguji, disalin dari workbook master:

**Centrifuge/Tachometer titik 1** (set point 60 rpm, pembacaan
`59.9 60.0 60.0 60.0 60.0`):

| Kolom | Nilai |
|---|---|
| rata-rata | 59,98 |
| nominal sertifikat dipilih | 60 |
| koreksi standar | −0,2 |
| nilai terkoreksi | 59,78 |
| **koreksi (tercetak)** | **−0,22** |
| simpangan baku | 0,0447213595 |
| kolom sertifikat | `Standard 59,78` · `UUT 60` · `Correction −0,22` |

**Timer titik 1** (set point 60 detik, ms standar `123 211 45`, ms UUT
`131 219 61`):

| Kolom | Nilai |
|---|---|
| nominal sertifikat dipilih | 60 s |
| koreksi standar | −30 ms |
| standar terkoreksi | 60096,33 ms |
| **koreksi (tercetak)** | **−40,667 ms = −0,041 s** |
| kolom sertifikat | `Standard 60,096 s` · `UUT 60,137 s` · `Correction −0,041 s` |
| U95 | 0,81 s (lantai CMC menang atas U 0,38 s) |
