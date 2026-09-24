#!/usr/bin/env python3
"""Generate `database/data/tabel-standar-gaya.json` dari master lab.

Sumber: tiga workbook master keluarga Gaya (ber-password), sudah diekspor ke CSV
di `Project-PT-Sidik/alat-alat-Pt-Sidik/Alat_Gaya/`:

  - `Gaya_UTM/`           Mesin UTM
  - `Gaya_Load_Cell/`     Load Cell
  - `Gaya_Proving_Ring/`  Proving Ring

Tabelnya DIGENERATE, tidak diketik. Tiga standar x dua arah berisi ~60 pasang
angka koreksi; satu digit yang meleset waktu diketik menggeser koreksi satu titik
beban tanpa satu pun error muncul - yang ketahuan cuma angka di sertifikat
pelanggan yang salah.

## Yang TIDAK ikut digenerate: CMC

Nilai CMC ketiga alat sudah ada di `database/data/kemampuan-kalibrasi.json`
(lampiran akreditasi LK-285-IDN), dan sudah diadu: cocok persis, termasuk
konversi kgf->kN (2,1 kgf x 0,00981 = 0,020601). Menyalinnya ke sini berarti dua
salinan yang bisa berbeda diam-diam - dan yang menang nanti belum tentu yang
tertulis di akreditasi.

## Tiga hal yang ditemukan waktu membandingkan ketiga workbook

1. **Drift arah Tarik untuk standar yang SAMA berbeda antar workbook.**
   `Load Cell 5 kN` (S/N J10CC13283): workbook UTM menulis `0`, sementara Load
   Cell dan Proving Ring menulis `0.04000000000000001`. Ketiganya disimpan apa
   adanya di `drift[arah][sumber]` - memilih salah satu berarti diam-diam
   menggeser U95% yang terbit. Diangkat sebagai pertanyaan lab bernomor.

2. **Identitas standar di workbook Proving Ring rusak** - `Merek`, `Type`, dan
   `S/N` standar 5 kN berisi `#REF!`. Dibiarkan null, bukan ditambal dari
   workbook tetangga: yang menambal diam-diam membuat sertifikat menyebut
   ketertelusuran yang tidak pernah dibaca dari sumbernya.

3. **Dua faktor kg->kN dipakai berdampingan.** Set point tabel standar memakai
   `0.00980665` (nilai fisik eksak), sementara pembacaan teknisi dikonversi
   dengan `0.00981` dari `Tabel_Satuan`. Keduanya ditulis terpisah di JSON;
   menyeragamkannya menggeser hasil nearest-match di titik yang dekat batas.
"""

from __future__ import annotations

import csv
import json
import pathlib
import re
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
SUMBER = AKAR / 'Project-PT-Sidik' / 'alat-alat-Pt-Sidik' / 'Alat_Gaya'
KELUARAN = AKAR / 'database' / 'data' / 'tabel-standar-gaya.json'

WORKBOOK = {
    'utm': 'Gaya_UTM',
    'load_cell': 'Gaya_Load_Cell',
    'proving_ring': 'Gaya_Proving_Ring',
}

# Kunci standar dipakai kode PHP; label panjangnya cuma buat manusia.
KUNCI_STANDAR = {
    'Load Cell 5 kN (500kg)': '5kN',
    'Load Cell 3000 kN': '3000kN',
    'Load Cell 100 kN (10000 kg)': '100kN',
}

NAMA_DRIFT = {
    'Load Cell 5 kN': '5kN',
    'Load Cell 100 kN': '100kN',
    'Load Cell 3000 kN': '3000kN',
}


def sel(nilai: str) -> str:
    """Buang anotasi rumus `[=...]` yang ikut waktu workbook diekspor."""
    return re.sub(r'\[=.*', '', nilai).strip().strip('"')


def baca(path: pathlib.Path) -> list[list[str]]:
    isi = path.read_text(encoding='utf-8-sig').splitlines()
    return [[sel(c) for c in baris] for baris in csv.reader(isi)]


def angka(teks: str):
    if teks in ('', '#REF!', '-'):
        return None
    try:
        return float(teks)
    except ValueError:
        return None


def ambil(baris: list[str], i: int) -> str:
    return baris[i] if 0 <= i < len(baris) else ''


def tabel_drift(rows: list[list[str]]) -> dict[str, dict[str, float | None]]:
    """Tabel `LATEST RECORD DRIFT` - kolom 15..18, arah Tekan & Tarik."""
    hasil: dict[str, dict[str, float | None]] = {}

    for baris in rows:
        kunci = NAMA_DRIFT.get(ambil(baris, 16))
        if kunci:
            hasil[kunci] = {
                'Push': angka(ambil(baris, 17)),
                'Pull': angka(ambil(baris, 18)),
            }

    return hasil


def blok_standar(rows: list[list[str]]) -> dict[str, dict]:
    """Pecah berkas jadi blok per standar, lengkap dengan tabel dua arahnya."""
    hasil: dict[str, dict] = {}
    kunci_aktif: str | None = None
    arah_aktif: str | None = None
    kolom: dict[str, int] = {}

    for baris in rows:
        c1, c2, c3 = ambil(baris, 1), ambil(baris, 2), ambil(baris, 3)

        if c1 == 'Nama Alat' and c3:
            kunci_aktif = KUNCI_STANDAR.get(c3)
            arah_aktif = None
            if kunci_aktif and kunci_aktif not in hasil:
                hasil[kunci_aktif] = {
                    'nama': c3,
                    'merek': None, 'tipe': None, 'serial': None,
                    'tertelusur': None,
                    'tanggal_kalibrasi': ambil(baris, 11)[:10] or None,
                    'berlaku_sampai': None,
                    'suhu_sertifikat': None,
                    'u95_persen': None,
                    'tabel': {},
                }
            continue

        if not kunci_aktif:
            continue

        std = hasil[kunci_aktif]

        # Identitas. `#REF!` dibiarkan null - lihat docstring temuan 2.
        if c1 in ('Merek', 'Type', 'S/N') and c3:
            std[{'Merek': 'merek', 'Type': 'tipe', 'S/N': 'serial'}[c1]] = (
                None if c3 == '#REF!' else c3
            )
        if ambil(baris, 8) == 'Due Date Kalibrasi':
            std['berlaku_sampai'] = ambil(baris, 11)[:10] or None
        if ambil(baris, 8) == 'Tertelusur':
            std['tertelusur'] = ambil(baris, 11) or None
        if ambil(baris, 9) == 'Temp cal. Certificate':
            std['suhu_sertifikat'] = angka(ambil(baris, 11))

        # Penanda arah: "... (Tekan)" / "... (Tarik)".
        if '(Tekan)' in c2 or '(Tarik)' in c2:
            arah_aktif = 'Push' if '(Tekan)' in c2 else 'Pull'
            std['tabel'].setdefault(arah_aktif, [])
            kolom = {}
            continue

        # Baris header tabel - dua tata letak berbeda, jadi kolomnya DIBACA,
        # bukan ditebak dari posisi. Tata letak kg dipakai standar 5 kN & 100 kN;
        # standar 3000 kN langsung kN tanpa kolom kg.
        if c2 == 'No.' and arah_aktif:
            for i, judul in enumerate(baris):
                if judul.startswith('Set Point (kN)'):
                    kolom['set_point_kn'] = i
                elif judul.startswith('Set Point (kg)'):
                    kolom['set_point_kg'] = i
                elif judul.startswith('Correction (kN)'):
                    kolom['koreksi_kn'] = i
                elif judul.startswith('Correction (kg)'):
                    kolom['koreksi_kg'] = i
                elif judul.startswith('U95%'):
                    kolom['u95'] = i
            continue

        # Baris data: kolom 2 berisi nomor urut.
        if arah_aktif and re.fullmatch(r'\d+', c2) and 'set_point_kn' in kolom:
            titik = {
                'set_point_kn': angka(ambil(baris, kolom['set_point_kn'])),
                'koreksi_kn': angka(ambil(baris, kolom['koreksi_kn'])),
            }
            if 'set_point_kg' in kolom:
                titik['set_point_kg'] = angka(ambil(baris, kolom['set_point_kg']))
            if 'koreksi_kg' in kolom:
                titik['koreksi_kg'] = angka(ambil(baris, kolom['koreksi_kg']))

            if 'u95' in kolom:
                u95 = angka(ambil(baris, kolom['u95']))
                if u95 is not None and std['u95_persen'] is None:
                    std['u95_persen'] = u95

            if titik['set_point_kn'] is not None and titik['koreksi_kn'] is not None:
                std['tabel'][arah_aktif].append(titik)

    return hasil


def faktor_satuan(rows: list[list[str]]) -> dict[str, float]:
    """`Tabel_Satuan` di DATABASE.csv - kolom 17 nama, kolom 18 faktor."""
    hasil: dict[str, float] = {}

    for baris in rows:
        nama, nilai = ambil(baris, 17), angka(ambil(baris, 18))
        if nama in ('kN', 'N', 'lbf', 'kgf', 'tnf') and nilai is not None:
            hasil[nama] = nilai

    return hasil


def main() -> int:
    if not SUMBER.is_dir():
        print(f'Folder master tidak ada: {SUMBER}', file=sys.stderr)
        return 1

    standar: dict[str, dict] = {}
    drift: dict[str, dict[str, dict[str, float | None]]] = {}
    satuan: dict[str, float] = {}

    for sumber, folder in WORKBOOK.items():
        rows_std = baca(SUMBER / folder / 'STANDAR_LOADCELL.csv')
        rows_db = baca(SUMBER / folder / 'DATABASE.csv')

        for kunci, isi in blok_standar(rows_std).items():
            # Blok standar identik di ketiga workbook kecuali identitas yang
            # rusak di Proving Ring; yang pertama ketemu jadi acuan, yang
            # sesudahnya cuma mengisi lubang.
            if kunci not in standar:
                standar[kunci] = isi
            else:
                for k, v in isi.items():
                    if standar[kunci].get(k) is None and v is not None:
                        standar[kunci][k] = v

        for kunci, arah in tabel_drift(rows_std).items():
            for nama_arah, nilai in arah.items():
                drift.setdefault(kunci, {}).setdefault(nama_arah, {})[sumber] = nilai

        satuan.update(faktor_satuan(rows_db))

    keluaran = {
        '_sumber': 'Project-PT-Sidik/alat-alat-Pt-Sidik/Alat_Gaya/*/STANDAR_LOADCELL.csv + DATABASE.csv',
        '_generator': 'docs/skrip/gen-tabel-standar-gaya.py - JANGAN disunting tangan',
        '_catatan': {
            'cmc': 'TIDAK di sini - sumbernya database/data/kemampuan-kalibrasi.json (LK-285-IDN).',
            'drift': 'Per arah per WORKBOOK, karena ketiganya tidak sepakat untuk standar yang sama.',
            'faktor_set_point_kg_ke_kn': 'Eksak 0,00980665, beda dari faktor pembacaan kgf 0,00981.',
        },
        'faktor_satuan': satuan,
        'faktor_set_point_kg_ke_kn': 0.00980665,
        'koefisien_suhu_per_c': 0.00027,
        'standar': standar,
        'drift': drift,
    }

    KELUARAN.write_text(
        json.dumps(keluaran, ensure_ascii=False, indent=2) + '\n',
        encoding='utf-8',
    )

    print(f'Ditulis: {KELUARAN.relative_to(AKAR)}')
    for kunci, isi in standar.items():
        arah = {a: len(t) for a, t in isi['tabel'].items()}
        print(f'  {kunci:8} suhu_sertifikat={isi["suhu_sertifikat"]} '
              f'u95={isi["u95_persen"]} titik={arah} serial={isi["serial"]}')
    print(f'  faktor satuan: {satuan}')
    for kunci, arah in drift.items():
        for nama_arah, per_sumber in arah.items():
            if len(set(per_sumber.values())) > 1:
                print(f'  ! drift {kunci} {nama_arah} BEDA antar workbook: {per_sumber}')

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
