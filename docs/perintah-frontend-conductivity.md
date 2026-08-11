# Perintah Frontend — Conductivity Meter

Tempel bagian di bawah garis ini ke sesi kerja frontend. Sudah lengkap; tidak
perlu menjelaskan ulang konteksnya.

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Backend modul **Conductivity Meter** (alat ke-5) sudah selesai dan sudah di
`main`. Tugasmu menyambungkan sisi frontend. Kontrak lengkapnya ada di
`docs/handoff-frontend-conductivity.md` di repo API — **baca itu lebih dulu dan
perlakukan sebagai satu-satunya sumber kebenaran.**

## ATURAN PALING PENTING

1. **Frontend TIDAK menghitung apa pun.** Tidak ada rata-rata, tidak ada STDEV,
   tidak ada koreksi, tidak ada ketidakpastian, tidak ada konversi satuan.
   Semua angka datang dari API. Kalau ada angka yang belum tersedia di respons,
   laporkan — jangan hitung sendiri.
2. **Jangan hardcode titik ukur, satuan, resolusi, atau jumlah desimal.**
   Semuanya datang dari `GET /api/calibrations/lembar-kerja?profil=conductivity_meter`.
   Alat ini akan bertambah sampai 48 jenis; layar yang menebak bentuknya akan
   pecah di alat berikutnya.
3. Kalau ada yang ambigu antara kontrak dan kenyataan respons API, **berhenti
   dan tanya**. Jangan menambal dengan asumsi.

## YANG BEDA DARI ALAT LAIN — ini sumber bug-nya

Conductivity satu-satunya alat yang **mencampur dua satuan dalam satu lembar**.

1. **Satuan mengikat per BARIS, bukan per lembar.** Lembarnya mengirim
   `satuan: null` dan `satuan_campuran: true`. Ambil satuan dari
   `baris[].satuan`. Kalau kamu ambil dari level lembar, seluruh kolom akan
   salah label.

2. **Kirim `equipment_id` kalau alatnya sudah dipilih.**

   ```
   GET /api/calibrations/lembar-kerja?profil=conductivity_meter&equipment_id=123
   ```

   Ini yang paling menentukan bentuk layarnya:

   - **Dengan `equipment_id`** → 3 baris, satuan tiap titik sudah terpilih
     mengikuti alat pelanggan. Tidak ada yang perlu dipilih teknisi.
   - **Tanpa `equipment_id`** → 4 baris (template generik), karena titik tengah
     dikirim dalam dua varian satuan untuk dipilih.

   Pada bentuk generik, baris `1412 µS/cm` dan `1,412 mS/cm` adalah botol
   larutan yang sama dibaca dalam dua satuan. Tiap baris membawa
   `eksklusif_dengan` — begitu satu diisi, kunci pasangannya. Kalau dua-duanya
   bisa terisi, sistem menerima dua nilai untuk satu botol.

   **Selalu kirim `equipment_id` kalau bisa.** Aturan lab: sertifikat mengikuti
   satuan yang tampil di layar alat pelanggan, dan hanya alat yang tahu itu.

3. **Suhu wajib kalau pembacaan diisi.** Nilai acuan larutan digeser ikut suhu.
   Tahan pengiriman kalau ada baris yang pembacaannya terisi tapi suhunya
   kosong. Ini bukan formalitas: di master Excel-nya, kolom suhu yang kosong
   membuat polinomial dievaluasi pada T=0 dan menghasilkan angka yang kelihatan
   wajar tapi salah — lalu ikut tercetak di sertifikat.

4. **`keputusan` bisa `null`.** Conductivity tidak divonis PASS/FAIL karena
   master-nya tidak punya batas keberterimaan. Jangan tampilkan badge kalau
   null — pakai strip (`—`) atau sembunyikan kolomnya. Alat lain tetap
   mengirim `"PASS"`/`"FAIL"`, jadi komponennya harus menangani tiga keadaan.
   Periksa juga daftar sesi dan sertifikat, bukan hanya layar detail.

5. **Desimal berbeda per titik** (`baris[].desimal`): 1 / 0 / 2 / 3. Pad angka
   ke jumlah desimalnya, jangan buang nol belakang — `25,0` tetap `25,0`.

## YANG BERUBAH DI PANEL ADMIN

Admin sekarang bisa mengedit lembar berstatus `menunggu_approval` lewat
`PUT /api/calibrations/{id}` — seluruh permukaan input, termasuk field
administratif. Dulu status itu mengunci semua orang.

- Munculkan tombol Edit untuk sesi `menunggu_approval` di panel admin
  (sebelumnya disembunyikan).
- Status `disetujui` tetap terkunci untuk semua orang — jangan munculkan Edit.
- Pesan 422 sudah membedakan dua sebab; tampilkan apa adanya.
- Field mana yang boleh diisi sudah disaring backend menurut peran. Kalau
  field muncul di respons `lembar-kerja`, user itu boleh mengisinya. Jangan
  menyaring lagi di frontend.

## PERINGATAN YANG HARUS DITAMPILKAN

Kalau sesi memakai varian **mS/cm** untuk titik tengah, backend mengembalikan
peringatan. Tampilkan ke admin sebelum approve. Ini peringatan, bukan
penghalang — admin boleh lanjut secara sadar, pola yang sama dengan peringatan
lain di alur approve.

## CARA KERJA

1. Tarik bentuk lembar dari API, render dari data itu — jangan dari konstanta.
2. Kerjakan layar teknisi dulu, baru panel admin.
3. Uji dengan sesi contoh `2405.32.A.NK` yang sudah ter-seed. Angka acuannya
   ada di bagian 5 dokumen handoff.
4. Kalau ada angka di layar yang berbeda dari tabel acuan itu, **jangan
   perbaiki tampilannya** — laporkan selisihnya, karena kemungkinan besar
   masalahnya di pemetaan data, bukan di format.

## CATATAN — nomor style tertukar dari file Excel lama

Angka hasil kalibrasi **cocok master Excel persis**, tidak ada selisih yang
disengaja.

Yang berbeda cuma **penomoran style**:

| Style | Satuan per titik |
|---|---|
| 1 | µS/cm · µS/cm · mS/cm |
| 2 | µS/cm · mS/cm · mS/cm |
| 3 | mS/cm · mS/cm · mS/cm |

Sheet `SERTIFIKAT STYLE 1` di file Excel justru mencetak titik tengah dalam
mS/cm — kebalikan dari tabel ini. Lab sendiri yang menyatakan label di file itu
keliru dan menetapkan urutan di atas.

Kalau ada yang membandingkan dengan cetakan Excel lama: **angkanya sama persis,
hanya nomor style-nya yang tertukar.** Jangan dibalik agar cocok dengan nama
sheet.

Style-nya **diturunkan dari satuan**, bukan dipilih. Tidak perlu dropdown
"pilih style" di layar mana pun.
