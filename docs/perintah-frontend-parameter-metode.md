# Perintah frontend: parameter keputusan metode (spesifikasi untuk Studio Web)

Dibuat 24 Nov 2026. **Ini SPESIFIKASI. Belum ada endpoint, belum ada layar.** API
sekarang hanya MEMBACA registri di dalam perhitungan; penetapan jawaban TM
dilakukan lewat PR (lihat `docs/parameter-keputusan-metode.md`). Berkas ini
menulis apa yang Studio Web butuhkan supaya langkah berikutnya tidak menebak.

Aturan repo yang berlaku untuk implementasinya nanti: izin ditegakkan di server
(`role:` + `MatriksIzin`), pemisahan wewenang (`PemisahanWewenang`: pengesah bukan
pengaju), semua query `organization_id`, tiap perubahan menulis pelaku, waktu,
alasan, nilai sebelum/sesudah. Layar tidak menghitung apa pun: yang ditampilkan
adalah apa yang server katakan.

## Data yang SUDAH tersedia (bentuk kanonik)

`ParameterKeputusanMetode::semua($tanggal = hari ini)` memulangkan daftar ini
(dasar respons `GET` di bawah):

```json
{
  "kunci": "refractometer.sumber_deviasi_suhu",
  "profil": "refractometer",
  "lingkup": "hitung",
  "judul": "Sumber komponen u(perbedaan temperatur) Refractometer",
  "pertanyaan": "...",
  "butir_tm": ["refractometer#24"],
  "pilihan": [
    {"kode": "suhu_ruang", "label": "...", "perilaku": "...", "setara_master": "tidak",
     "bukti": "path:baris @hash", "bukti_singkat": "...", "data": {}}
  ],
  "bawaan": "suhu_ruang",
  "bukti_bawaan": "path:baris @hash",
  "dampak": "...",
  "status": "menunggu_tm",
  "berlaku": {"tanggal": "2026-11-24", "nilai": "suhu_ruang", "bawaan_kode": true,
              "penetapan": 0, "berlaku_mulai": null},
  "penetapan": []
}
```

- `lingkup`: `hitung` (mengubah angka hasil, ada baris jejak di tiap titik) atau
  `cetak` (hanya tanggal tercetak, beku di snapshot sertifikat).
- `status`: `menunggu_tm` selama `penetapan` kosong, `diputuskan` sesudahnya.
- `setara_master` per pilihan: `ya`, `tidak`, `sebagian`. Tampilkan apa adanya
  dengan `bukti_singkat`; jangan menyimpulkan sendiri.
- Hasil hitung: `uncertainty_calculations.type_b_components` (di respons detail
  sesi sebagai `type_b_components[]`) memuat satu baris `sumber:
  "parameter_metode"` dengan `keterangan` siap tampil. Baris tambahan itu tidak
  punya angka (`nilai: null`); layar yang menjumlah kontribusi harus
  melewatinya (distribusi `-`, sama dengan `perbandingan_cmc`).

## Endpoint yang diusulkan (belum ada)

Semua di bawah `/api` (Sanctum), tulis hanya `super_admin` dan `admin` (Master
Data) sebagai pengaju; pengesahan oleh `super_admin` yang bukan pengaju.

| Metode & jalur | Peran | Isi |
|---|---|---|
| `GET /parameter-metode` | admin, super_admin, teknisi (baca) | daftar di atas; `?tanggal=YYYY-MM-DD` untuk melihat pilihan yang berlaku pada tanggal itu |
| `GET /parameter-metode/{kunci}` | sama | satu parameter + riwayat penetapan + berapa sesi (per organisasi) yang akan terkena bila pilihan diganti (hitungan, bukan angka) |
| `POST /parameter-metode/{kunci}/usulan` | admin, super_admin | `{nilai, berlaku_mulai, alasan, rujukan}` membuat DRAF; menolak `berlaku_mulai` sebelum hari ini |
| `POST /parameter-metode/usulan/{id}/simulasi` | pengaju | menjalankan pilihan baru terhadap semua sesi tersimpan profilnya (pola `SimulasiVersiAcuan`): sesi mana berubah, di kolom mana, dan angka CETAK mana yang bergeser |
| `POST /parameter-metode/usulan/{id}/sahkan` | super_admin BUKAN pengaju | wajib simulasi terbaru; wajib pengakuan tertulis (min. 10 karakter) per butir bila angka cetak sertifikat terbit bergeser |
| `POST /parameter-metode/usulan/{id}/tolak` | super_admin | alasan wajib |

Jawaban galat memakai kode yang sudah ada di Data Acuan (`status_tidak_sesuai`,
`simulasi_belum_ada`, `simulasi_kedaluwarsa`, `pengakuan_wajib`,
`hanya_super_admin`, `pengesah_ikut_menyunting`) supaya klien yang sudah
menangani alur Data Acuan tidak butuh cabang baru.

## Layar

1. **Daftar parameter**: judul, profil, status (menunggu TM / diputuskan), pilihan
   yang berlaku hari ini, lencana `hitung` atau `cetak`. Satu baris = satu parameter.
2. **Detail parameter**: pertanyaan, butir lembar centang, tiap pilihan dengan
   `perilaku`, `setara_master`, `bukti`, dan `dampak`; riwayat penetapan
   (siapa, kapan, alasan, rujukan); tombol "Usulkan penetapan" hanya untuk
   peran yang boleh (server menolak, tombol hanya kenyamanan).
3. **Simulasi**: tabel sesi terdampak (nomor sesi, kolom berubah, angka cetak
   lama dan baru). Angka cetak yang bergeser ditandai dan membuka kolom
   pengakuan; tanpa simulasi terbaru tombol Sahkan tidak aktif.

Gaya tampilan mengikuti keputusan desain 16 Nov (tenang, formal, ringkas); tidak
ada layar yang dibuat tanpa PNG yang disetujui pemilik.

## Yang harus diputuskan pemilik sebelum dibangun

1. Rollout `DATA_ACUAN_SUMBER=db` (tanpa itu penetapan dari DB tidak dibaca
   produksi). Lihat `docs/parameter-keputusan-metode.md` bagian "Langkah berikut".
2. Tempat menyimpan penetapan: paket virtual `parameter_metode` per organisasi
   di tabel `paket_acuan` yang ada (tanpa tabel baru), atau tetap di berkas
   dengan PR.
3. Siapa yang boleh mengajukan dan mengesahkan (usulan: pola Data Acuan).
