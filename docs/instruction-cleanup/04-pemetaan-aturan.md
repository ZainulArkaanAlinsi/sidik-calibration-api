# 04 — Pemetaan aturan lama → lokasi baru

> **DRAFT.** Kode lokasi: **INTI** = `01` (`AGENTS.md` baru) · **G-INTI** = `02` §B
> (`~/.claude/rules/inti.md`) · **G-MD** = `02` §A (`~/.claude/CLAUDE.md` baru) ·
> **P:nama** = panduan `docs/panduan/nama.md` · **PG:nama** = panduan global
> `~/.claude/panduan/nama.md` · **PL** = `.claude/panduan-ui-lokal.md` ·
> **CL** = `CLAUDE.local.md` ringkas · **DUP→X** = dihapus karena duplikat, penggantinya X ·
> **BASI** = dibuang karena tidak benar lagi (bukti di `03`).
>
> Rentang baris = batas heading `AGENTS.md` di commit `0beb5bb` (`grep -n '^#'`).
> Tidak ada baris yang berstatus "dihapus tanpa pengganti".

## A. `AGENTS.md`

| Bagian lama (baris) | Isi | Lokasi baru |
|---|---|---|
| Pembuka (1–10) | satu sumber, deskripsi repo | INTI (diringkas; repo pelanggan ditambahkan sebagai klien) |
| §Perintah (11–18) | `composer setup/dev`, `serve` | INTI |
| §Test (19–58) | perintah test, dua suite wajib + alasan, SQL setup, user sempit, pembungkus ps1, data mesin, durasi | Perintah, aturan dua suite, "DB & user khusus, sandi tidak di repo", durasi: INTI · teks utuh 29–57: P:test-mysql-lokal |
| §Format (59–65) | pint per berkas, ide-helper | INTI |
| §Perintah artisan (66–79) | daftar perintah | INTI (daftar pendek); angka "24 perintah" di baris 78 BASI |
| §Generator (80–91) | jangan sunting hasil generator | INTI |
| §Dua jebakan (92–109) | `Storage::fake`, worktree symlink | INTI (ringkas, kedua aturan utuh) |
| §CI beda dari lokal (110–118) | PHP 8.4, deploy hook | INTI; + fakta "CI hanya SQLite" (memory `ci-cuma-jalankan-suite-sqlite`, dicek ulang 1 Okt: `tes.yml` tidak menyebut `phpunit.mysql`) |
| §Profil kalibrasi (121–136) | subclass, pencocokan nama, kelas bersama | INTI |
| §Jalur angka (137–164) | diagram, guarded acceptance, hitung-ulang ganda | INTI |
| §Lapisan lain (165–175) | tabel lapisan | INTI (angka "86 migrasi" & "~640 baris" dibuang karena akan basi) |
| §Data sumber (176–180) | workbook master | INTI |
| §Data sumber (181–200) | cek visibilitas via `gh`, sapu nama, kejadian Hydrometer | Aturan: INTI · cerita: P:riwayat-git-publik |
| §Data sumber (201–209) | CMC, BACA-DULU, permintaan-*, spesifikasi poin | INTI |
| §Sebelum repo PUBLIK (210–249) | tabel riwayat, `rahasia123`, urutan 3 langkah | Aturan mengikat (`asmo_dev` bocor, force-push eksplisit, `rahasia123` bukan rahasia): INTI · rincian: P:riwayat-git-publik |
| §Modul Pelanggan (250–281) | 7 aturan keras | INTI (utuh); paragraf status 252–256 BASI |
| §Olah data (282–369) | 3 lapis, `formula_version_id`, simulasi, catatan audit, tanpa pembulatan | INTI (aturan utuh, alasan diringkas); sensus 324–331 BASI |
| §Peran (370–416) | 5 butir + rujukan | INTI (utuh; butir 4 diberi status kode 1 Okt) |
| §Keadaan nyata `super_admin` (417–503) | tabel pintu, alasan, keputusan | Tujuh aturan mengikat: INTI · tabel & alasan: P:super-admin |
| §Akun lahir dari undangan (504–559) | larangan, yang ditinggal, celah | Aturan: INTI · alasan & sejarah: P:akun-undangan |
| §Git Workflow (560–569) | pull, jangan commit otomatis, urutan, konflik | INTI (teks sama; ditandai `[K-I1]`) |
| §SATU PUSH (570–603) | dua aturan + kejadian 19 Sep | Aturan + diagnosis: INTI · kejadian: P:deploy-satu-push |
| §Pemilihan model (604–608) | haiku/sonnet/opus | INTI |
| §MCP (609–657) | Aiven, Render, aturan umum | Keputusan & syarat keras: INTI · alasan: P:mcp |
| §Akses database (658–667) | `@docs/aturan-akses-database.md` | INTI (import **tetap**) |
| §Daftar permintaan (668–674) | `permintaan-user-7.md` | INTI |
| §Alur kerja fitur besar (675–705) | 11 langkah | INTI (ringkas, 11 langkah utuh) |
| §Kesalahan nyata (706–734) | 5 aturan | INTI (5 aturan utuh); cerita 722–731 diringkas, versi lengkap tetap di riwayat git |
| §Compaction (735–744) | daftar pertahankan/buang | INTI |

## B. `CLAUDE.local.md`

| Bagian | Lokasi baru |
|---|---|
| Berkas yang tidak boleh masuk commit/push; larangan `--no-verify` | CL |
| Aturan pemakaian graphify (5 baris "first run graphify query…") | DUP→hook PreToolUse graphify |
| Larangan `graphify claude install` + cara memulihkan | CL |
| Berkas ter-track yang diubah graphify; sebelum commit/push; pull/merge/rebase | CL |
| Catatan sejarah 19 Sep tentang `CLAUDE.md` | DUP→komentar kepala `CLAUDE.md` |
| Aturan kerja UI/desain (seluruhnya, 3.706 byte) | PL + satu baris penunjuk di CL |

## C. `~/.claude/CLAUDE.md`

| Bagian | Lokasi baru |
|---|---|
| graphify trigger | G-MD |
| Obsidian vault (pola lengkap) | PG:obsidian-vault (utuh) + pemicu & larangan rahasia di G-MD |

## D. `~/.claude/rules/*.md`

| Berkas lama · bagian | Lokasi baru |
|---|---|
| bug-review · Choose behavior from intent | G-INTI §1 |
| bug-review · Bug evidence | G-INTI §3 |
| bug-review · Risk-based inspection | G-INTI §3 |
| bug-review · Patches and verification | G-INTI §4 |
| bug-review · Review reporting and cost | G-INTI §3 (reviewer terpisah, ECC, eskalasi, jangan dump berkas) |
| bug-review · Learning and engineering principles | G-INTI §1 (mode belajar, ringkasan) + §4 (KISS/YAGNI) |
| bug-review · "Explain … in Indonesian" | DUP→G-INTI kepala |
| domain-modeling · Working perspective | G-INTI §2, §6 (label peran ≠ keahlian) |
| domain-modeling · Understand the domain | G-INTI §6 |
| domain-modeling · Database design | G-INTI §6 |
| domain-modeling · ERP and WMS | PG:referensi-sistem |
| domain-modeling · References and solution choice | PG:referensi-sistem |
| domain-modeling · Deliverables and validation | G-INTI §6 |
| domain-modeling · "Explain … in Indonesian" | DUP→G-INTI kepala |
| engineering · Tool selection | G-INTI §5 (cek versi, Context7) + §8 (ECC, tool tidak tersedia) + §7 (UI UX Pro Max) |
| engineering · Design | G-INTI §7 |
| engineering · Logic and backend | G-INTI §2, §4 |
| engineering · Debugging and verification | G-INTI §4 |
| engineering · Token efficiency | G-INTI §9 |
| engineering · "Follow github-cli-workflow.md" | DUP→G-INTI §8 |
| engineering · "Explain … in Indonesian" | DUP→G-INTI kepala |
| erp-wms-references (seluruh) | PG:referensi-sistem; "follow research budgets" DUP→G-INTI §5; "explain in Indonesian" DUP→G-INTI kepala |
| focused-research · Understand before deciding | G-INTI §2 (+ data representatif → §9) |
| focused-research · When to research | G-INTI §5 |
| focused-research · Source selection | G-INTI §5 |
| focused-research · Research budget | G-INTI §5 |
| focused-research · Decisions and design | G-INTI §5 (opsi, prototipe) + §7 (UI) |
| focused-research · Durable understanding | G-INTI §5 (decision log) |
| focused-research · Open source selection and adaptive depth | G-INTI §5 |
| github-cli-workflow (seluruh) | G-INTI §8 |
| inventory-ocr-and-workflows · daftar referensi | PG:referensi-sistem |
| inventory-ocr-and-workflows · OCR decisions | PG:referensi-sistem (opsi: tetap di G-INTI, lihat `05` K-I7) |
| inventory-ocr-and-workflows · Workflow and reporting | PG:referensi-sistem |

## E. Yang tidak berubah

- `CLAUDE.md` proyek (penunjuk `@AGENTS.md`).
- `docs/aturan-akses-database.md` dan import-nya.
- `MEMORY.md` dan berkas memory.
- Skill proyek `sidik-*`.
