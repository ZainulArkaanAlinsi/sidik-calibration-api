# Permintaan ke lab — sertifikat arsip tekanan & piston beserta data mentahnya

> Ditulis 28 Sep 2026. Pembaca: admin / manajer teknis lab PT Sidik.
> Ini langkah 3 dari rencana validasi enam alat baru (§39 `docs/permintaan-user-7.md`).
> Acuan & catatan perubahannya: `database/data/manifest-workbook-tekanan-piston.json`
> dan `database/data/log-metode-tekanan-piston.json`.

## Kenapa sertifikat arsip, bukan sesi baru saja

Aplikasi sudah terbukti sama dengan workbook master **sel demi sel** (tekanan 962
sel, piston 274 sel). Tapi bukti itu melingkar: yang diadu adalah workbook yang
kami baca sendiri. Kalau workbook master dibetulkan besok, angka aplikasi dan angka
workbook bisa bergeser bersama dan tetap "cocok".

Sertifikat yang **sudah terbit** tidak ikut bergeser. Angkanya dihitung lab pada
saat itu, dari data mentah pada saat itu, dan sudah di tangan pelanggan. Itu
satu-satunya pembanding yang independen dari workbook yang kami bekukan.

Karena itu yang kami minta bukan cuma PDF-nya. **Tanpa data mentah, sertifikat
arsip tidak bisa diuji ulang** — kami cuma bisa membandingkan angka akhir, dan itu
kesan, bukan bukti.

## Yang diminta per sertifikat

| # | Berkas | Wajib? | Catatan |
|---|---|---|---|
| 1 | PDF sertifikat seperti yang terbit (semua halaman) | Wajib | |
| 2 | Workbook olah data (`.xlsm`) **yang dipakai waktu menerbitkan sertifikat itu** | Wajib | Salinan berkas asli. **Jangan dibuka lalu disimpan ulang** sebelum dikirim: menyimpan ulang mengubah sha256 dan bisa menghitung ulang sel yang merujuk tautan luar. |
| 3 | Scan/foto lembar kerja kertas (FM-0507 / FM-0528 / FM-0529) | Wajib | Supaya kami bisa memastikan angka di workbook memang angka yang ditulis teknisi, bukan hasil salin ulang. |
| 4 | Sertifikat standar yang berlaku pada tanggal kalibrasi itu | Kalau ada | Tabel koreksi di master sekarang adalah hasil kalibrasi standar terakhir. Sertifikat arsip dari sebelum standar dikalibrasi ulang memakai tabel lama, dan selisih karena itu **bukan** kesalahan. |
| 5 | Tanggal terbit dan, kalau tahu, versi master yang dipakai | Kalau ada | |

## Berapa dan yang mana — pilih yang paling BERBEDA, bukan yang paling rapi

**2–3 sertifikat per alat, jadi 12–18 sertifikat total.** Lima sesi minimum per alat
dipenuhi dari arsip ini ditambah sesi baru yang dihitung paralel (Excel dan aplikasi,
data mentah yang sama).

Pilih yang variasinya paling lebar. Sertifikat yang mirip satu sama lain cuma
menguji jalur yang sama dua kali.

| Alat (nama lampiran) | Yang paling berguna | Kenapa |
|---|---|---|
| **Pressure Gauge** | satu per kalibrator: DRUCK07G, DRUCK13G, SPMK. Untuk SPMK, kalau bisa yang beda tinggi (Δh) standar–UUT-nya tidak nol | tiap kalibrator punya workbook sendiri; koreksi beda tinggi hanya ada di SPMK |
| **Vacuum Gauge** | **wajib ada satu yang pakai DRUCK13G** (cabang Vacum); satu DRUCK07G; satuan beda (cmHg / inHg / mmHg) | temuan T-11: master DRUCK13G cabang Vacum memakai kolom U95 sebagai koreksi. Sertifikat arsip menunjukkan apakah sertifikat yang sudah terbit ikut terkena |
| **Differential Pressure** | dua rentang berbeda; satuan berbeda kalau ada | temuan T-2 mengubah U sesi contoh 3,998 → 5,047 mbar |
| **Piston Pipette** | fixed volume kecil (≤ 100 µl); fixed volume 1000 µl; satu variable/graduated (titik MIN/MID/MAX) | satuan µl lawan ml, dan dua master yang berbeda (Fixed / Graduated) |
| **Dispensett** | satu single stroke, satu multi stroke; kapasitas besar (≥ 25 ml) | massa kumulatif di atas 200 g memicu tara ulang — jalur temuan G-8 |
| **Buret Digital** | hand driven dan motor driven; **kapasitas 50 ml kalau ada** | sama: jalur tara ulang (G-2, G-8) hanya teruji kalau massanya besar |

Tambahan yang sangat berguna, kalau ada: **satu sertifikat tekanan dari
DRUCK07G, DRUCK13G, atau Differential yang terbit sebelum 28 Sep 2026.** Temuan T-2
(komponen pengulangan selalu nol di tiga master itu) mengecilkan U95. Sertifikat
arsip dari tiga master itu menjawab pertanyaan P-2: apakah sertifikat yang sudah
terbit perlu ditinjau.

## Cara kami memprosesnya

1. Berkas disimpan di `CATATAN/arsip-uji-paralel/<alat>/<nomor-sertifikat>/`.
   Direktori itu diabaikan git (`CATATAN/*` di `.gitignore`), jadi nama dan alamat
   pelanggan **tidak pernah masuk repo**.
2. sha256 tiap workbook dicatat saat diterima, sebelum dibuka.
3. `python docs/skrip/uji-paralel-tekanan-piston.py CATATAN/arsip-uji-paralel/<alat>`
   menjalankan data mentah yang sama ke tiga penghitung: cache Excel sesi itu,
   reimplementasi Python, dan mesin hitung aplikasi (mode tiru-master dan mode yang
   terbit). Yang dibandingkan **nilai antara**, bukan cuma angka akhir: rata-rata naik
   dan turun, simpangan baku, histeresis, indeks standar terpilih, koreksi standar,
   tiap komponen budget (U, pembagi, v, c, u), u_c, v_eff, k, U, CMC, U95. Dua
   kesalahan yang saling meniadakan di angka akhir tetap kelihatan di tengah.
4. Laporan mencatat sha256 workbook sesi, apakah tabel referensinya sama dengan
   master yang dibekukan, dan `versi_rumus` aplikasi yang dipakai.
5. **Satu selisih saja = status TAHAN.** Sertifikat untuk alat itu tidak diterbitkan
   dari aplikasi sampai selisihnya ditelusuri sampai ke sel dan dicatat di log metode.
   Skrip tidak memutuskan apa pun sendiri.

## Apa yang bisa dan tidak bisa dibuktikan arsip ini

- **Bisa:** aplikasi mode tiru-master menghasilkan angka yang sama dengan yang
  benar-benar terbit, dari data mentah yang sama. Itu menguji pembacaan data, tabel
  standar, dan rantai hitungnya terhadap sumber yang tidak kami sentuh.
- **Bisa:** menunjukkan sertifikat terbit mana yang terkena cacat master (T-1, T-2,
  T-11, G-2, G-8, G-9) dan berapa besar pengaruhnya.
- **Tidak bisa:** membuktikan pembetulan kami benar secara metode. Sertifikat arsip
  dihitung dengan master yang cacatnya sama, jadi yang dia pastikan cuma mode
  tiru-master. Pembetulan tetap menunggu jawaban manajer teknis (P-1, P-2, P-11,
  V-6, V-7, V-9, V-11, V-12).
- **Kalau workbook arsip berbeda dari master yang dibekukan** (rumus atau tabel), itu
  temuan tersendiri: lab pernah memakai versi master lain. Laporannya menyebut sel
  yang berbeda, dan versinya dicatat di log metode sebagai acuan lama.
