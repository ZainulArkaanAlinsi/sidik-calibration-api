#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generator `database/data/sesi-master-anak-timbangan.json` (alat ke-29).

Masukan sesi contoh Anak Timbangan, disalin dari `1.1 Anak Timbangan F1
1mg-500 g 202501022 imp.xlsx` (sertifikat `001-CAL-126`). Yang ditanam cuma
MASUKAN — empat penimbangan ABBA tiap keping dan keenam ujung kondisi ruangan.
Hasilnya dihitung `AnakTimbanganProfile::hitungPerGrup()` waktu seeder jalan,
supaya kalau mesin hitungnya bergeser `HitungUlangSemuaSesiTest` yang merah,
bukan angka tempelan yang diam-diam ikut bergeser.

    python docs/skrip/gen-sesi-anak-timbangan.py

## Identitas pelanggan SINTETIS

Master menyebut pelanggan sungguhan berikut alamatnya. Yang ditulis ke sini
`PT Contoh Kalibrasi Nusantara`, sama seperti seluruh seeder lain di repo ini.
Alasannya bukan visibility repo hari ini melainkan riwayat git: `git log -S`
membaca seluruh masa lalu, dan siapa pun yang pernah atau nanti punya akses
bisa menjalankannya.

Penanda keping (`no_identitas`) juga sintetis: master mencetak `-` dua puluh
kali (pertanyaan lab §11), jadi tidak ada yang bisa disalin. Diberi label netral
`AT-<nominal>-<urutan>` supaya keping kembar tetap bisa dibedakan.

## ENAM keping master SENGAJA tidak ikut

Sesi master berisi dua puluh keping; yang ditanam empat belas. Yang ditinggal
bukan pilihan rasa — keenamnya justru yang membuktikan gerbangnya bekerja:

  titik 4-8  (0,005 / 0,01 / 0,02 / 0,02 / 0,05 g)
      Tabel densitas tidak punya baris kelas F1 untuk nominal sekecil itu.
      Master menerbitkannya sebagai `#VALUE!` di kolom massa konvensional DAN
      ketidakpastian — lima dari dua puluh baris sertifikat pelanggan.
      Pertanyaan lab §4.

  titik 17   (10 g)
      `T1` tertulis 0,9998, seharusnya 9,9998. Master menerbitkannya sebagai
      **5,500163 g** — meleset 45 % — dengan ketidakpastian yang tetap rapi.
      Pertanyaan lab §3.

Keenamnya TIDAK dihilangkan dari bukti: `AnakTimbanganMasterTest` menanam
kedua puluh titik dan menuntut keenam itu DITOLAK dengan alasan yang kebaca.
Yang tidak ikut cuma ke sesi contoh, supaya sesi demo tidak selamanya membawa
enam temuan — data demo yang selalu merah melatih admin menekan "setujui tetap"
tanpa membaca, dan itu persis kebiasaan yang bikin sertifikat rusak lolos.
"""
from __future__ import annotations

import json
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
KELUARAN = AKAR / "database/data/sesi-master-anak-timbangan.json"

# Kondisi ruangan, `PERHITUNGAN FC` baris Suhu/Kelembaban/Tekanan udara.
LINGKUNGAN = {
    "suhu_awal": 23.1,
    "suhu_akhir": 23.0,
    "kelembaban_awal": 55.0,
    "kelembaban_akhir": 56.0,
    "tekanan_awal": 933.2,
    "tekanan_akhir": 933.1,
}

# Empat belas keping: (nominal_g, S1, T1, T2, S2) — `INPUT DATA` blok
# Penimbangan, titik master 1-3, 9-16, dan 18-20.
KEPING = [
    (100.0, 100.0, 99.9999, 99.9999, 100.0),
    (200.0, 199.9999, 199.9999, 199.9999, 199.9999),
    (200.0, 199.9999, 199.9999, 199.9999, 199.9999),
    (0.1, 0.1, 0.1, 0.1, 0.1),
    (0.2, 0.2, 0.2, 0.2, 0.2),
    (0.2, 0.2, 0.2, 0.2, 0.2),
    (0.5, 0.5, 0.5, 0.5, 0.5),
    (1.0, 1.0, 1.0002, 0.9999, 1.0),
    (2.0, 2.0002, 2.0005, 2.0003, 2.0002),
    (2.0, 2.0001, 2.0003, 2.0004, 2.0001),
    (5.0, 5.0002, 5.0003, 5.0003, 5.0003),
    (20.0, 20.0002, 20.0, 20.0001, 20.0004),
    (20.0, 20.0, 20.0003, 20.0005, 20.0001),
    (50.0, 50.0003, 50.0003, 50.0003, 50.0002),
]

# Nominal yang punya lebih dari satu keping fisik di set standar lab. Keping
# bernominal ini WAJIB punya `no_identitas`, kalau tidak profilnya menolak
# titiknya (pertanyaan lab §11).
KEMBAR = {0.02, 0.2, 2.0, 20.0, 200.0}


def label_nominal(n: float) -> str:
    return ("%g" % n).replace(".", "p")


titik = []
identitas: dict[str, str] = {}
urut: dict[float, int] = {}

for i, (nominal, s1, t1, t2, s2) in enumerate(KEPING, start=1):
    titik.append(
        {
            "titik_ke": i,
            "nominal_g": nominal,
            # Satu pembacaan per peran, seperti workbook masternya. Kertas
            # Rev.0 menyediakan tiga (`X1 X2 X3`) dan `AnakTimbanganMentah`
            # merata-ratakan berapa pun yang ada — dengan satu angka, dia
            # runtuh jadi angka itu sendiri. Pertanyaan lab §23.
            "at_s1": [s1],
            "at_t1": [t1],
            "at_t2": [t2],
            "at_s2": [s2],
        }
    )

    if nominal in KEMBAR:
        urut[nominal] = urut.get(nominal, 0) + 1
        identitas[str(i)] = "AT-%s-%d" % (label_nominal(nominal), urut[nominal])

# Penjagaan: tiap keping kembar WAJIB punya penanda. Kalau tidak, seeder-nya
# jalan "sukses" dan sesinya lahir tanpa titik-titik itu — diam-diam.
kurang = [
    t["titik_ke"]
    for t in titik
    if t["nominal_g"] in KEMBAR and str(t["titik_ke"]) not in identitas
]

if kurang:
    print(f"MENOLAK MENULIS — keping kembar tanpa penanda: {kurang}", file=sys.stderr)
    sys.exit(1)

data = {
    "_sumber": "1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx, sertifikat 001-CAL-126",
    "_catatan": (
        "Identitas pelanggan SINTETIS. Enam dari dua puluh keping master "
        "sengaja tidak ikut — lihat docblock gen-sesi-anak-timbangan.py."
    ),
    "_digenerate_oleh": "docs/skrip/gen-sesi-anak-timbangan.py",
    "sesi": {
        "nomor_sertifikat": "DEMO-AT-001",
        "nomor_order": "DEMO-AT-ORD-001",
        "pelanggan": "PT Contoh Kalibrasi Nusantara",
        "alamat": "Kawasan Industri Contoh Blok A No. 1, Indonesia",
        "nama_alat": "Anak Timbangan",
        "merk": "Delima Scientific",
        "serial": "202501022",
        "kelas_uut": "F1",
        "kelas_standar": "E2",
        "kapasitas_g": 200.0,
        "rentang": "0,1 g - 200 g",
        "timbangan": "Analytical Balance",
        "meter_lingkungan": "Thermobarometer",
        "thermohygro": "Thermobarometer",
        "tanggal_terima": "2026-01-26",
        "tanggal": "2026-01-27",
        **LINGKUNGAN,
    },
    "identitas": identitas,
    "titik": titik,
}

KELUARAN.write_text(
    json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
)
print(f"Ditulis {KELUARAN}")
print(f"  {len(titik)} keping, {len(identitas)} penanda keping kembar")
