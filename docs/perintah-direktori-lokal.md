# Direktori perusahaan lokal — cari nama & alamat PT tanpa keluar server

**Untuk siapa:** admin lab yang memasang datanya, dan siapa pun yang perlu tahu kenapa hasil pencarian di HP berubah.
**Tanggal:** 2 September 2026
**Berdiri sendiri:** ya.

---

## 1. Apa yang berubah buat teknisi

Sebelumnya, mencari PT yang belum pernah dilayani lab menembak ke OpenStreetMap — dan pabrik di
kawasan industri yang belum dipetakan sukarelawan **tidak ketemu**.

Sekarang ada lapis tambahan **di depan**: salinan direktori yang disimpan di database lab sendiri.
Yang ketemu di sana muncul **tanpa menyentuh jaringan sama sekali** (±10 ms untuk 10.320 baris).
Yang tidak ketemu tetap diteruskan ke OpenStreetMap seperti biasa — cakupan tidak berkurang
sedikit pun.

**Di sisi HP tidak ada yang berubah.** Tidak ada rilis APK baru, tidak ada tambahan ukuran
aplikasi, tidak ada layar baru. Endpoint `GET /customers/direktori` yang sudah dipanggil aplikasi
sejak lama sekarang cuma punya sumber tambahan di belakangnya.

## 2. Yang WAJIB dipahami sebelum memakainya

Isi direktori ini **bukan kebenaran, dan bukan data pelanggan lab.** Dia petunjuk untuk mempercepat
pengetikan.

Sumbernya memperingatkan dirinya sendiri:

| Sumber | Baris | Peringatan dari sumbernya |
|---|---|---|
| Kawasan Industri Jababeka | 450 | data 2020 — *"banyak perusahaan sudah pindah, berganti nama, atau tutup"* |
| Indonetwork | 9.870 | isian mandiri tiap perusahaan — *"keakuratannya bervariasi"* |

Karena itu layar teknisi memajang kalimat **"Belum diverifikasi — cocokkan dengan surat pesanan
sebelum dipakai di sertifikat"** di bawah hasilnya. Kalimat itu bukan hiasan: `certificates.snapshot`
membekukan alamat pelanggan saat sertifikat terbit, jadi **alamat yang salah tidak bisa ditarik
lagi** — memperbaiki `customers` besok tidak memperbaiki sertifikat yang sudah dipegang pelanggan.

Baris direktori baru jadi data lab **setelah teknisi memilihnya**. Saat itu yang lahir baris
`customers` baru lewat jalur yang sudah ada, lengkap dengan `sumber`, `dibuat_oleh_user_id`, dan
`direktori_ref` yang menunjuk balik ke sini.

## 3. Memasang datanya

### Di produksi: OTOMATIS, tidak perlu dikerjakan siapa pun

`docker/entrypoint.sh` memuatnya sendiri tiap container nyala. **Tidak ada langkah manual.**

Alasannya bukan kemewahan: paket gratis Render **tidak menyediakan shell sama sekali** (*"Shell is
not supported for free compute plans"*), jadi tidak ada tempat lain buat menjalankannya. Tanpa itu
tabelnya akan kosong selamanya di produksi.

Aman ditaruh di jalur boot karena `--lewati-kalau-terisi` memeriksa isi tabel **sebelum** membaca
berkas:

| Boot | Yang dibayar |
|---|---|
| pertama sesudah deploy | baca 1,3 MB CSV + 22 paket `upsert` — hitungan detik |
| berikutnya (termasuk tiap Render membangunkan service yang ketiduran) | **dua query `COUNT`** |

Yang diperiksa **isi tabelnya**, bukan penanda "sudah pernah jalan". Database yang direset bikin
penanda berbohong, dan tanpa shell tidak ada yang bisa membetulkannya — hitungan baris selalu jujur,
jadi jalur ini memulihkan dirinya sendiri.

Impor yang gagal **tidak menjatuhkan boot** (`|| true`). Direktori ini fitur kenyamanan: tanpa dia
pendaftaran pelanggan tetap jalan lewat ketik tangan dan OpenStreetMap. Menukar itu dengan seluruh
server yang dipakai teknisi di lokasi adalah pertukaran yang salah arah. Gagalnya tetap terbaca di
log Render, dan `GET /api/health` melaporkan jumlah barisnya.

### Di lokal: manual

```bash
php artisan direktori:impor-lokal database/direktori/jababeka.csv    --sumber=jababeka
php artisan direktori:impor-lokal database/direktori/indonetwork.csv --sumber=indonetwork
```

Dua berkas CSV-nya ikut di repo, jadi perintah ini bisa dijalankan ulang kapan saja tanpa menyiapkan
apa-apa. Butuh ±2,4 detik untuk 10.320 baris.

| Opsi | Arti |
|---|---|
| `berkas` | path CSV. Kolom wajib: `ref`, `nama`. Opsional: `alamat`, `kota`, `provinsi` |
| `--sumber=` | **wajib**, salah satu dari `jababeka` / `indonetwork` |
| `--uji-coba` | baca dan tampilkan contoh, tanpa menulis apa pun |
| `--lewati-kalau-terisi` | keluar tanpa membaca berkas kalau sumbernya sudah berisi. Dipakai `entrypoint.sh`; jarang berguna dijalankan tangan |

**Aman dijalankan berkali-kali.** Kunci uniknya `(sumber, ref)`, jadi jalan kedua memperbarui baris
yang sama — bukan menggandakannya. Memperbarui satu sumber tidak menyentuh sumber lain.

## 4. Memastikan sudah terpasang

```bash
curl -s https://api.pt-sidik.com/api/health | jq .direktori_perusahaan
```

```json
{
  "disetel": true,
  "driver": "osm",
  "bisa_ditagih": false,
  "lokal": { "aktif": true, "baris": 10320 }
}
```

`lokal.baris` menjawab pertanyaan yang paling sering muncul — *"kenapa PT ini ketemu di HP saya tapi
tidak di HP teman saya"* — yang jawabannya hampir selalu impornya belum dijalankan di container itu.
Tanpa angka ini, satu-satunya cara memeriksanya masuk ke database produksi.

`driver` sengaja **tidak** ikut berubah jadi `lokal`: yang dijawabnya pertanyaan *"lab ini sedang
ditagih atau tidak"*, dan lapis lokal tidak mengubah jawabannya — nol jaringan, nol kuota.

## 5. Kenapa TIDAK di-seed ke `customers`

Ini keputusan yang paling menentukan, dan alasannya terukur — bukan selera.

`lib/services/simpanan_pelanggan.dart` menyalin **seluruh** daftar pelanggan ke SharedPreferences
HP tiap teknisi, supaya pemilih pelanggan tetap jalan di dalam pabrik yang nol sinyal.
SharedPreferences dibaca **utuh ke memori setiap aplikasi nyala**.

10.320 baris nama+alamat = **1,36 MB JSON**, diurai tiap kali aplikasi dibuka, di HP teknisi,
selamanya. Ukuran unduhan APK tidak berubah — yang berubah waktu nyala dan pemakaian memorinya,
dan itu tidak kelihatan dari mana pun sampai ada yang mengeluh aplikasinya lemot.

Tiga alasan lain, masing-masing sudah cukup sendiri:

- `customers` punya `sumber` + `dibuat_oleh_user_id` — tiap baris ada yang bertanggung jawab.
  10.320 baris hasil seed tidak punya siapa-siapa di belakangnya, tapi **kelihatan sama sahihnya**
  di layar.
- Unique index `(organization_id, nama)` akan bentrok dengan pelanggan asli, dan yang mentok
  teknisi di lapangan.
- Panel "Kelola Pelanggan" jadi 10.320 baris yang 99%-nya bukan pelanggan.

## 6. Kenapa tabelnya tanpa `organization_id`

Isinya data publik, bukan data lab. Menempelkannya ke organisasi berarti menyalin 10.320 baris yang
sama untuk tiap lab, dan tidak ada yang dibeli dari situ — tidak ada lab yang "memiliki" alamat
pabrik orang.

> **Konsekuensinya buat siapa pun yang menambah query ke tabel ini:** dia **sengaja tidak tersaring
> `organization_id`**. Jangan pernah menyimpan apa pun milik lab di sana. Yang boleh masuk hanya
> hasil `direktori:impor-lokal`.

## 7. Memperbarui atau mencabut

Memperbarui satu sumber: siapkan CSV baru dengan `ref` yang sama, jalankan perintahnya lagi.

Mencabut satu sumber seluruhnya:

```bash
php artisan tinker --execute="App\Models\DirektoriLokal::where('sumber','indonetwork')->delete();"
```

Tabel kosong bikin lapis lokal **mati sendiri** (`tersedia()` jadi `false`), dan perilakunya kembali
sama persis seperti sebelum fitur ini ada. Tidak ada setelan yang perlu diubah.
