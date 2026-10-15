# K-50-12 — Studio satu akun (catatan desain, untuk dikerjakan saat merge Studio)

Status: HANYA rancangan. Kode Studio ada di `feat/studio-s1b`; berkas ini tidak
menggabungkannya. Dikerjakan pada slice G7 (merge Studio).

## Keputusan pemilik (15 Okt 2026)
Studio dipakai satu orang (pemilik). Tidak ada peran/tim Studio: satu akun, hak penuh,
termasuk sel terkunci.

## Rancangan
1. **Config**: `config/studio.php` -> `'akun' => env('STUDIO_AKUN')` (email, huruf kecil).
   Kunci env baru `STUDIO_AKUN` masuk `.env.example` DAN `render.yaml` (`sync: false`).
   Kosong = Studio tertutup total (semua rute Studio 403), bukan terbuka.
2. **Token**: login Studio (rute/ perintah terpisah dari login lab) hanya menerbitkan
   token Sanctum dengan ability `studio` bila `strtolower($user->email) === config('studio.akun')`
   dan user aktif. Token lab biasa TIDAK pernah memuat `studio`; token `studio` tidak
   memuat ability lab (tidak bisa dipakai di rute lab).
3. **Rute Studio**: grup `auth:sanctum` + `abilities:studio` (middleware `CheckAbilities`),
   ditambah pengecekan ulang email == `STUDIO_AKUN` di server tiap request (token lama
   mati begitu `STUDIO_AKUN` diganti). Super admin lab tanpa ability `studio` -> 403.
4. **Hak penuh**: pemegang ability `studio` lolos semua gerbang Studio, termasuk
   penyuntingan sel terkunci/terproteksi (`LockedCells`/sejenisnya di Studio S1b);
   hapus pengecekan peran lain di rute Studio, ganti satu gate `studio`.
5. **Jejak audit tetap**: tiap perubahan rumus/sel terkunci mencatat waktu, pelaku, alasan,
   nilai sebelum/sesudah. Master yang sudah dipublikasi tidak ditimpa — buat revisi.
6. **Tes yang harus ada**: akun cocok -> token `studio`; email lain -> 403; `STUDIO_AKUN`
   kosong -> 403; token lab ke rute Studio -> 403; token studio ke rute lab -> 403;
   sunting sel terkunci -> 200 + baris audit.

## Belum diputuskan
Cara login (endpoint khusus vs `php artisan studio:token`) — usul: perintah artisan, sehingga
tidak ada permukaan login baru di internet.
