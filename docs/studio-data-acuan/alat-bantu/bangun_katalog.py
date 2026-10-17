"""Bangun katalog Studio Data Acuan dari isi repo sidik-calibration-api.

Dijalankan:  python3 -I bangun_katalog.py <akar-repo-api> <folder-keluaran>

Semua yang ditulis di katalog DIAMBIL dari repo (JSON acuan, kelas PHP,
skrip generator, daftar test, CSV master). Yang tidak bisa dipastikan dari
repo ditandai `perlu_konfirmasi`, tidak ditebak diam-diam.
"""
import csv, glob, hashlib, json, os, re, sys

AKAR = sys.argv[1]
OUT = sys.argv[2]
DATA = os.path.join(AKAR, 'database/data')
MASTER = os.path.join(AKAR, 'Project-PT-Sidik/alat-alat-Pt-Sidik')
FORM = os.path.join(AKAR, 'Project-PT-Sidik/worksheet_alat_calibration')

# ------------------------------------------------------------------ peta paket
# Disusun tangan dari pemakaian kelas Tabel* (grep `app/`), generator di
# docs/skrip, fixture database/data, dan 46 folder master. Satu paket = satu
# keluarga data acuan yang dibaca bersama oleh satu atau beberapa profil.
P = lambda **k: k
PAKET = [
 P(kode='micrometer', nama='Micrometer 0–100 mm (4 varian)', kelompok='Panjang', jenis='json',
   json=['tabel-standar-micrometer.json'], kelas=['TabelStandarMicrometer'], kalkulator=['MicrometerCalculator'],
   profil=['MicrometerProfile'], formulir=['0522.A', '0522.B', '0522.C', '0522.D'],
   master=['Panjang_CSV/Master_Olah_Data_Micrometer_0-25mm', 'Panjang_CSV/Master_Olah_Data_Micrometer_25-50mm',
           'Panjang_CSV/Master_Olah_Data_Micrometer_50-75mm', 'Panjang_CSV/Master_Olah_Data_Micrometer_75-100mm'],
   generator=['gen-tabel-standar-micrometer.py'], fixture=['sesi-master-micrometer.json'],
   kata=['micrometer'], pilot=True),
 P(kode='height_gauge', nama='Height Gauge 600 mm', kelompok='Panjang', jenis='json',
   json=['tabel-standar-height-gauge.json'], kelas=['TabelStandarHeightGauge'], kalkulator=['HeightGaugeCalculator'],
   profil=['HeightGaugeProfile'], formulir=[], master=['Panjang_CSV/Master_olda_Height_Gauge_600_mm_2026'],
   generator=['gen-tabel-standar-height-gauge.py'], fixture=['sesi-master-height-gauge.json'], kata=['height-gauge']),
 P(kode='dial_indicator', nama='Dial Indicator', kelompok='Panjang', jenis='json',
   json=['tabel-standar-dial-indicator.json'], kelas=['TabelStandarDialIndicator'], kalkulator=['DialIndicatorCalculator'],
   profil=['DialIndicatorProfile'], formulir=['0526'], master=['Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator'],
   generator=['gen-tabel-standar-dial-indicator.py'], fixture=['sesi-master-dial-indicator.json'], kata=['dial-indicator']),
 P(kode='jangka_sorong', nama='Jangka Sorong (Caliper)', kelompok='Panjang', jenis='json',
   json=['tabel-standar-jangka-sorong.json'], kelas=['TabelStandarJangkaSorong'], kalkulator=['JangkaSorongCalculator'],
   profil=['JangkaSorongProfile'], formulir=['0527'], master=['Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_'],
   generator=['gen-tabel-standar-jangka-sorong.py'], fixture=['sesi-master-jangka-sorong.json'], kata=['jangka-sorong']),
 P(kode='sieve', nama='Sieve Mesh', kelompok='Panjang', jenis='json',
   json=['tabel-standar-sieve.json'], kelas=['TabelStandarSieve'], kalkulator=['SieveCalculator'],
   profil=['SieveProfile'], formulir=['0536'], master=['Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Sieve_Mesh'],
   generator=['gen-tabel-standar-sieve.py'], fixture=['sesi-master-sieve.json'], kata=['sieve']),
 P(kode='anak_timbangan', nama='Anak Timbangan (OIML R111)', kelompok='Massa', jenis='json',
   json=['tabel-standar-anak-timbangan.json'], kelas=['TabelStandarAnakTimbangan'], kalkulator=['AnakTimbanganCalculator'],
   profil=['AnakTimbanganProfile'], formulir=['0541'], master=['Massa_Timbangan/1__1_Anak_Timbangan_F1_1mg-500_g_202501022_imp'],
   generator=['gen-tabel-standar-anak-timbangan.py'], fixture=['sesi-master-anak-timbangan.json'], kata=['anak-timbangan']),
 P(kode='timbangan', nama='Timbangan (gram / kg / substitusi)', kelompok='Massa', jenis='json',
   json=['tabel-standar-timbangan.json'], kelas=['TabelStandarTimbangan'], kalkulator=['TimbanganCalculator'],
   profil=['TimbanganProfile'], formulir=['0508_', '0508.A'],
   master=['Massa_Timbangan/_New__Master_Olda_Timbangan_gram', 'Massa_Timbangan/_New__Master_Olda_Timbangan_kg',
           'Massa_Timbangan/_TERBARU__Master_Olda_Timbangan_Subtitusi_291025'],
   generator=[], fixture=['sesi-master-timbangan.json'], kata=['timbangan']),
 P(kode='waktu', nama='Timer & Stopwatch', kelompok='Waktu', jenis='json',
   json=['tabel-standar-waktu.json'], kelas=['TabelStandarWaktu'], kalkulator=['WaktuCalculator'],
   profil=['TimerStopwatchProfile'], formulir=['0512'], master=['Waktu_/Master_Olda_Timer_dan_Stopwatch'],
   generator=['gen-tabel-standar-waktu-frekuensi.py'], fixture=['sesi-master-waktu-frekuensi.json'], kata=['waktu-frekuensi']),
 P(kode='putaran', nama='Centrifuge & Tachometer', kelompok='Waktu', jenis='json',
   json=['tabel-standar-putaran.json'], kelas=['TabelStandarPutaran'], kalkulator=['PutaranCalculator'],
   profil=['ProfilPutaran', 'CentrifugeProfile', 'TachometerProfile'], formulir=['0515'],
   master=['Waktu_/Master_Olda_Centrifuge', 'Waktu_/Master_Olda_Tachometer'],
   generator=['gen-tabel-standar-waktu-frekuensi.py'], fixture=['sesi-master-waktu-frekuensi.json'], kata=['waktu-frekuensi']),
 P(kode='gaya', nama='Gaya — UTM, Load Cell, Proving Ring', kelompok='Gaya', jenis='json',
   json=['tabel-standar-gaya.json'], kelas=['TabelStandarGaya'], kalkulator=['GayaCalculator'],
   profil=['GayaProfile', 'UtmProfile', 'LoadCellProfile', 'ProvingRingProfile'], formulir=['0519', '0520', '0521'],
   master=['Alat_Gaya/Gaya_UTM', 'Alat_Gaya/Gaya_Load_Cell', 'Alat_Gaya/Gaya_Proving_Ring'],
   generator=['gen-tabel-standar-gaya.py'], fixture=[], kata=['gaya']),
 P(kode='flowmeter', nama='Flowmeter — pembanding UFM', kelompok='Aliran', jenis='json',
   json=['tabel-standar-flowmeter.json'], kelas=['TabelStandarFlowmeter'], kalkulator=['FlowmeterCalculator'],
   profil=['FlowmeterProfile', 'FlowmeterFlowrateProfile', 'FlowmeterTotalizerProfile'], formulir=['0538-', '0538.A', '0538.B'],
   master=['Aliran_2/1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026', 'Aliran_2/Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026'],
   generator=['gen-tabel-standar-flowmeter.py'], fixture=[], kata=['flowmeter']),
 P(kode='flowmeter_gravimetri', nama='Flowmeter — gravimetri (ISO 4185)', kelompok='Aliran', jenis='json',
   json=['tabel-standar-flowmeter-gravimetri.json'], kelas=['TabelStandarFlowmeterGravimetri'], kalkulator=['FlowmeterGravimetriCalculator'],
   profil=['FlowmeterProfile'], formulir=['0538.A', '0538.B'],
   master=['Aliran_2/1_2_Master_olda_Flowmeter_Totalizer_dini__2026__140-2500L_', 'Aliran_2/2_1_Master_olda__Flowmeter_Flowrate_100-980lpm_2026'],
   generator=['gen-tabel-standar-flowmeter-gravimetri.py'], fixture=[], kata=['flowmeter-gravimetri']),
 P(kode='volumetric', nama='Volumetric Glassware (fixed & graduated)', kelompok='Volume', jenis='json',
   json=['tabel-standar-volumetric.json'], kelas=['TabelStandarVolumetric'], kalkulator=['VolumetricGlasswareCalculator'],
   profil=['VolumetricGlasswareProfile', 'FixedVolumetricGlasswareProfile', 'GraduatedVolumetricGlasswareProfile',
           'LabuUkurProfile', 'PipetVolumeProfile', 'PipetUkurProfile', 'GelasUkurProfile', 'BuretProfile', 'PicnometerProfile'],
   formulir=['0513', '0514'], master=['Volumetric_Glassware_2026/Fixed_Volumetric_Glassware_2026', 'Volumetric_Glassware_2026/Graduated_Volumetric_Glassware_2026'],
   generator=['gen-tabel-standar-volumetric.py'], fixture=[], kata=['volumetric']),
 P(kode='tekanan', nama='Tekanan — pressure, vacuum, differential', kelompok='Tekanan', jenis='json',
   json=['tabel-standar-tekanan.json'], kelas=['TabelStandarTekanan'], kalkulator=['TekananCalculator'],
   profil=['TekananProfile', 'PressureGaugeProfile', 'VacuumGaugeProfile', 'DifferentialPressureProfile'], formulir=['0507'],
   master=[], generator=['gen-tabel-standar-tekanan.py'], fixture=['sesi-master-tekanan.json'], kata=['tekanan'],
   catatan_master='Master tekanan (4 workbook) TIDAK ada di folder alat-alat (CSV-nya di-gitignore karena memuat data pelanggan). Sha256 workbook di database/data/manifest-workbook-tekanan-piston.json; riwayat metode di log-metode-tekanan-piston.json.'),
 P(kode='piston_volume', nama='Piston Volume (fixed & graduated, buret digital, dispensett, piston pipette)', kelompok='Volume', jenis='json',
   json=['tabel-standar-piston-volume.json'], kelas=['TabelStandarPistonVolume'], kalkulator=['PistonVolumeCalculator'],
   profil=['PistonVolumeProfile', 'BuretDigitalProfile', 'DispensettProfile', 'PistonPipetteProfile'], formulir=['0528', '0529'],
   master=[], generator=['gen-tabel-standar-piston-volume.py'], fixture=['sesi-master-piston-volume.json'], kata=['piston-volume'],
   catatan_master='Master piston (2 workbook) TIDAK ada di folder alat-alat; sha256 di manifest-workbook-tekanan-piston.json.'),
 P(kode='tids', nama='TIDS (indikator suhu digital)', kelompok='Suhu', jenis='json',
   json=['tabel-standar-tids.json'], kelas=['TabelStandarTids'], kalkulator=['TidsCalculator'],
   profil=['TidsProfile'], formulir=['0506'],
   master=['suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Recorder__Graptech_', 'suhu_&_kelembapan/Master_Olah_Data_Suhu_TIDS_-_Yokogawa_K_N'],
   generator=[], fixture=['tids-cache-master.json'], kata=['tids-workbook', 'tids'], pakai_juga=['tits']),
 P(kode='tits', nama='TITS & kalibrator suhu (measure/source)', kelompok='Suhu', jenis='json',
   json=['tabel-kalibrator-suhu.json'], kelas=['TabelKalibratorSuhu'], kalkulator=['TitsCalculator'],
   profil=['TitsProfile'], formulir=['0505'],
   master=['suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Measure_utk_UUT', 'suhu_&_kelembapan/Master_Olah_Data_Suhu_TITS_fungsi_Source_utk_UUT'],
   generator=[], fixture=[], kata=['tits'],
   catatan_master='Tabel kalibrator ini juga dibaca TIDS, Enclosure, dan tabel suhu 3 alat — perubahan di sini menggeser alat-alat itu juga. Simulasi wajib menjalankan semua profil pemakainya.'),
 P(kode='enclosure', nama='Enclosure — oven, inkubator, bath, furnace, refrigerator', kelompok='Suhu', jenis='json',
   json=['tabel-kalibrator-enclosure.json'], kelas=['TabelKalibratorEnclosure'], kalkulator=['EnclosureCalculator'],
   profil=['EnclosureProfileBase', 'OvenProfile', 'InkubatorProfile', 'BathProfile', 'FurnaceProfile', 'RefrigeratorProfile'], formulir=['0504'],
   master=['suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa', 'suhu_&_kelembapan/Master_Olah_Data_Suhu_Enclosure_Recorder'],
   generator=['gen-tabel-kalibrator-enclosure.py'], fixture=[], kata=['enclosure']),
 P(kode='suhu_3alat', nama='Thermocouple · Termometer gelas · Thermohygrometer', kelompok='Suhu', jenis='json',
   json=['tabel-master-suhu-3alat.json'], kelas=['TabelKalibratorSuhu3Alat'],
   kalkulator=['ThermocoupleCalculator', 'ThermometerGlassCalculator', 'ThermohygroCalculator'],
   profil=['ThermocoupleProfile', 'ThermometerGlassProfile', 'ThermohygroProfile'], formulir=['0535', '0537', '0525'],
   master=['suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermocouple', 'suhu_&_kelembapan/Master_Olah_Data_Suhu_Thermometer_Glass',
           'suhu_&_kelembapan/Master_Olah_Data_Suhu___Kelembapan'],
   generator=[], fixture=[], kata=['suhu-3alat', 'thermohygro-satuan']),
 P(kode='thermohygro_lab', nama='Thermohygrometer lab (alat kondisi lingkungan, lintas alat)', kelompok='Lintas alat', jenis='seed-standards',
   json=['thermohygro-lab.json'], kelas=[], kalkulator=[], profil=['(semua profil yang memakai Environmental Meter)'], formulir=[],
   master=[], generator=[], fixture=[], kata=['thermohygro-satuan'],
   catatan_master='Diseed ThermohygroSeeder ke tabel `standards` (koreksi & U kondisi lingkungan). Sumber kebenaran sesudah seed adalah tabel `standards`, yang SUDAH bisa disunting di menu Standar. Studio menampilkannya sebagai paket ber-versi, tidak membuat salinan kedua.'),
 P(kode='cmc_lampiran', nama='Pita CMC lampiran akreditasi LK-285-IDN (semua alat)', kelompok='Lintas alat', jenis='seed-db',
   json=['kemampuan-kalibrasi.json'], kelas=['TabelStandarHydrometer', 'TabelStandarTimbangan'], kalkulator=[], profil=['(semua profil)'], formulir=[],
   master=[], generator=[], fixture=[], kata=[],
   catatan_master='Diseed ke `calibration_capabilities` (`php artisan kemampuan:pastikan`), sudah ada resource Filament. Studio menjadikannya ber-versi; jangan membuat tabel CMC kedua.'),
]
PHP = [
 ('ph_meter', 'pH Meter', 'Analitik', ['PhMeterProfile'], ['0509'], ['instrument-analiitk/pH_meter_IMTE-WQ-129'], ['kalibrasi-ph-meter.json'], ['ph-dua-master'],
  'Titik buffer (4,00 / 7,00 / 10,01) dan ci/vi komponen budget ditulis di dalam method profil; nilai standar buffer (U, k, drift) sudah di tabel `standards`.'),
 ('conductivity', 'Conductivity Meter', 'Analitik', ['ConductivityProfile'], ['0510'], ['instrument-analiitk/Master_Olah_Data_Conductivity'], [], ['conductivity'], ''),
 ('chlorine', 'Chlorine Meter', 'Analitik', ['ChlorineProfile'], ['0531'], ['instrument-analiitk/Master_Olah_Data_Chlorine_Meter'], [], [], ''),
 ('do_meter', 'DO Meter', 'Analitik', ['DoMeterProfile'], ['0532'], ['instrument-analiitk/Master_Olah_Data_DO_Meter'], [], [], ''),
 ('turbidimeter', 'Turbidimeter', 'Analitik', ['TurbidimeterProfile'], ['0530'], ['instrument-analiitk/Master_Olah_Data_Turbidimeter'], [], [], ''),
 ('refractometer', 'Refractometer', 'Analitik', ['RefractometerProfile'], ['0523'], ['instrument-analiitk/Master_Olah_Data_Refractometer'], [], [], ''),
 ('spectrophotometer', 'Spektrofotometer', 'Analitik', ['SpectrophotometerProfile'], ['0511'], ['instrument-analiitk/Master_Olah_Data_Spectrofotometer'], [], ['r2-spektro'], ''),
 ('viscometer', 'Viscometer', 'Analitik', ['ViscometerProfile'], ['0524'],
  ['instrument-analiitk/Master_Olah_Data_Viscometer', 'instrument-analiitk/5__Viscometer_86068360_terbaru_'], [], ['viscometer'], ''),
 ('gas_detector', 'Gas Detector', 'Analitik', ['GasDetectorProfile'], [], ['instrument-analiitk/Gas_Detector_Uli_Skin__std_Rigaz_'], [], ['gas-detector'], ''),
 ('autoclave', 'Autoclave', 'Suhu & tekanan', ['AutoclaveProfile'], ['0539'], ['instrument-analiitk/Master_Olah_Data_Autoclave'], [], [], ''),
 ('hydrometer', 'Hydrometer', 'Massa jenis', ['HydrometerProfile'], ['0533'],
  ['Hydrometer/Hydrometer_0.600-0.650_gmL', 'Hydrometer/Hydrometer_1.800-2.000_gmL'], [], ['hydrometer'],
  'Kelas `TabelStandarHydrometer` ada, tetapi tanpa JSON tabel sendiri; pita CMC-nya dibaca dari kemampuan-kalibrasi.json.'),
]
for kode, nama, kel, prof, form, mas, fix, kata, cat in PHP:
    PAKET.append(P(kode=kode, nama=nama, kelompok=kel, jenis='konstanta-php', json=[], kelas=(['TabelStandarHydrometer'] if kode == 'hydrometer' else []),
                   kalkulator=(['HydrometerCalculator'] if kode == 'hydrometer' else []), profil=prof, formulir=form, master=mas,
                   generator=[], fixture=fix, kata=kata, catatan_master=cat))

# -------------------------------------------------------------- bantuan umum
BUKAN_ACUAN = {'DATABASE', 'FORM VALIDASI', 'FORM_VALIDASI', 'INPUT DATA', 'INPUT_DATA', 'SERTIFIKAT', 'SERTIFIKAT (2)',
               'SERTIFIKAT STYLE 1', 'SERTIFIKAT STYLE 2', 'Certificate (2)', 'konsep', 'Konsep', 'Sekilas Info',
               'Drawing Autoclave', 'Drawing Enclosure', 'ClimaticChamberStability(unuse)'}

def golongkan_sheet(nama):
    n = nama.upper()
    if nama in ('INPUT DATA', 'INPUT_DATA'): return 'bentuk_lembar'
    if n.startswith('PERHITUNGAN') or n.startswith('NILAI_U95') or 'KOEF' in n or nama == 'Misalignment': return 'rumus_baca_saja'
    if n.startswith('SERTIFIKAT') or nama == 'Certificate (2)': return 'pratinjau_sertifikat'
    if nama in ('FORM VALIDASI', 'FORM_VALIDASI'): return 'versi_workbook'
    if nama == 'DATABASE': return 'database_internal_jangan_disalin_kecuali_sel_terpetakan'
    if nama in BUKAN_ACUAN: return 'dokumen_bukan_data'
    return 'acuan_lapis1'

def baca_header_csv(p, maks=14):
    """Baris-baris awal sheet (nilai saja) — cukup untuk mengenali judul kolom."""
    try:
        rows = list(csv.reader(open(p, encoding='utf-8-sig', errors='replace')))
    except Exception:
        return 0, []
    awal = []
    for r in rows[:maks]:
        isi = [c.strip().replace('\n', ' ') for c in r if c.strip()]
        if isi:
            awal.append(' | '.join(isi)[:220])
    return len(rows), awal

SATUAN_SUFIKS = [('_um_per_mm', 'µm/mm'), ('_per_c', '/°C'), ('_mm', 'mm'), ('_um', 'µm'), ('_c', '°C'), ('_kg', 'kg'),
                 ('_gram', 'g'), ('_g', 'g'), ('_mg', 'mg'), ('_kn', 'kN'), ('_n', 'N'), ('_ml', 'mL'), ('_l', 'L'),
                 ('_lpm', 'L/min'), ('_s', 's'), ('_rpm', 'rpm'), ('_bar', 'bar'), ('_pa', 'Pa'), ('_nm', 'nm'), ('_persen', '%')]

def tebak_satuan(kunci):
    k = str(kunci).lower()
    for suf, sat in SATUAN_SUFIKS:
        if k.endswith(suf):
            return sat
    return None

TGL = re.compile(r'^\d{4}-\d{2}-\d{2}$')

def tipe(v):
    if isinstance(v, bool): return 'boolean'
    if isinstance(v, (int, float)): return 'desimal'
    if isinstance(v, str):
        if TGL.match(v): return 'tanggal'
        try:
            float(v.replace(',', '.')); return 'desimal_teks'
        except ValueError:
            return 'teks'
    if v is None: return 'kosong'
    if isinstance(v, list):
        if all(isinstance(x, (int, float)) for x in v): return 'daftar_angka'
        return 'daftar'
    return 'objek'

def skalar(v): return not isinstance(v, (dict, list))

def datar(v):
    """Skalar atau daftar skalar — bisa duduk di satu sel grid."""
    return skalar(v) or (isinstance(v, list) and all(skalar(x) for x in v))

def lembar_dari(nilai, jalur, keluaran, prefix=None):
    """Ubah satu cabang JSON jadi satu atau beberapa 'lembar' grid.

    Tiap lembar mencatat `_daun`: jalur daun JSON yang BENAR-BENAR diwakilinya,
    supaya uji cakupan bisa membuktikan tidak ada satu angka pun yang tercecer.
    `jalur` = nama tampilan (boleh memuat `*` untuk anak tabel), `prefix` = jalur
    nyata di JSON.
    """
    j = '/'.join(jalur)
    nyata = prefix if prefix is not None else j
    if skalar(nilai) or (isinstance(nilai, list) and all(skalar(x) for x in nilai)):
        bentuk = 'nilai_tunggal' if skalar(nilai) else ('daftar' if nilai else 'daftar_kosong')
        keluaran.append({'jalur': j, 'bentuk': bentuk,
                         'kolom': [{'kunci': 'nilai', 'tipe': tipe(nilai), 'satuan_tebakan': tebak_satuan(jalur[-1])}],
                         'jumlah_baris': 1 if skalar(nilai) else len(nilai), 'contoh': nilai if skalar(nilai) else nilai[:3],
                         '_daun': {nyata}})
        return
    if isinstance(nilai, list):
        if all(isinstance(x, dict) for x in nilai):
            tabel_dari_baris([(f'{nyata}/{i}', str(i), r) for i, r in enumerate(nilai)], jalur, keluaran, 'tabel')
            return
        for i, x in enumerate(nilai):
            lembar_dari(x, jalur + [str(i)], keluaran, f'{nyata}/{i}')
        return
    if not nilai:
        keluaran.append({'jalur': j, 'bentuk': 'peta', 'kolom': [], 'baris': [], 'jumlah_baris': 0, '_daun': {nyata}})
        return
    if all(datar(v) for v in nilai.values()):
        keluaran.append({'jalur': j, 'bentuk': 'peta',
                         'kolom': [{'kunci': 'kunci', 'tipe': 'teks'}, {'kunci': 'nilai', 'tipe': 'campuran'}],
                         'baris': [{'kunci': k, 'tipe': tipe(v), 'satuan_tebakan': tebak_satuan(k)} for k, v in nilai.items()],
                         'jumlah_baris': len(nilai), 'contoh': dict(list(nilai.items())[:3]),
                         '_daun': {f'{nyata}/{k}' for k in nilai}})
        return
    if all(isinstance(v, dict) and v for v in nilai.values()) and all(
            sum(1 for x in v.values() if datar(x) or (isinstance(x, dict) and all(datar(y) for y in x.values()))) * 2 >= len(v)
            for v in nilai.values()):
        tabel_dari_baris([(f'{nyata}/{k}', k, r) for k, r in nilai.items()], jalur, keluaran, 'tabel_berkunci')
        return
    # Campuran: kunci skalar (identitas standar: nama, seri, tanggal…) jadi SATU
    # lembar peta; cabang bersarang dipecah sendiri. Tanpa ini satu blok
    # identitas pecah jadi belasan lembar satu-sel.
    skalar_saja = {k: v for k, v in nilai.items() if datar(v)}
    if skalar_saja:
        keluaran.append({'jalur': j, 'bentuk': 'peta', 'campuran': True,
                         'kolom': [{'kunci': 'kunci', 'tipe': 'teks'}, {'kunci': 'nilai', 'tipe': 'campuran'}],
                         'baris': [{'kunci': k, 'tipe': tipe(v), 'satuan_tebakan': tebak_satuan(k)} for k, v in skalar_saja.items()],
                         'jumlah_baris': len(skalar_saja), 'contoh': dict(list(skalar_saja.items())[:3]),
                         '_daun': {f'{nyata}/{k}' for k in skalar_saja}})
    for k, v in nilai.items():
        if k not in skalar_saja:
            lembar_dari(v, jalur + [str(k)], keluaran, f'{nyata}/{k}')

def tabel_dari_baris(baris, jalur, keluaran, bentuk):
    """Satu grid dari kumpulan baris dict.

    Kolom skalar → kolom biasa; sub-dict skalar (mis. `koreksi_ms.F`) → kolom
    bertitik; isi yang lebih dalam (daftar baris, mis. `titik` di tiap pita CMC)
    → lembar ANAK yang barisnya membawa kolom `induk`, seperti sheet Excel yang
    dirujuk lewat kunci.
    """
    j = '/'.join(jalur)
    kolom, urut, daun_set, anak = {}, [], set(), {}
    for pref, kunci, r in baris:
        for k, v in r.items():
            if datar(v):
                if k not in kolom:
                    kolom[k] = {'kunci': k, 'tipe': tipe(v), 'satuan_tebakan': tebak_satuan(k)}; urut.append(k)
                daun_set.add(f'{pref}/{k}')
            elif isinstance(v, dict) and v and all(datar(x) for x in v.values()):
                for sk, sv in v.items():
                    nk = f'{k}.{sk}'
                    if nk not in kolom:
                        kolom[nk] = {'kunci': nk, 'tipe': tipe(sv), 'satuan_tebakan': tebak_satuan(k) or tebak_satuan(sk)}; urut.append(nk)
                    daun_set.add(f'{pref}/{k}/{sk}')
            else:
                anak.setdefault(k, []).append((pref, kunci, v))
    l = {'jalur': j, 'bentuk': bentuk, 'kolom': [kolom[k] for k in urut], 'jumlah_baris': len(baris),
         'kunci_baris': [b[1] for b in baris][:200], 'contoh': {k: v for k, v in baris[0][2].items() if datar(v)} if baris else {},
         '_daun': daun_set}
    if anak:
        l['lembar_anak'] = [f'{j}/*/{k}' for k in anak]
    keluaran.append(l)
    for k, isi in anak.items():
        sub = []
        sisa = []
        for pref, kunci, v in isi:
            if isinstance(v, list) and all(isinstance(x, dict) for x in v):
                sub += [(f'{pref}/{k}/{m}', f'{kunci}·{m}', x) for m, x in enumerate(v)]
            elif isinstance(v, dict) and all(isinstance(x, dict) for x in v.values()) and v:
                sub += [(f'{pref}/{k}/{kk}', f'{kunci}·{kk}', x) for kk, x in v.items()]
            elif isinstance(v, dict):
                sub.append((f'{pref}/{k}', kunci, v))
            else:
                sisa.append((pref, kunci, v))
        if sub:
            tabel_dari_baris(sub, jalur + ['*', k], keluaran, 'tabel_anak')
        for pref, kunci, v in sisa:
            lembar_dari(v, jalur + [kunci, k], keluaran, f'{pref}/{k}')

def daun(nilai, jalur=()):
    """Semua jalur daun JSON — dipakai validator untuk membuktikan cakupan 100%."""
    if isinstance(nilai, dict):
        for k, v in nilai.items():
            yield from daun(v, jalur + (str(k),))
    elif isinstance(nilai, list) and nilai and not all(skalar(x) for x in nilai):
        for i, v in enumerate(nilai):
            yield from daun(v, jalur + (str(i),))
    else:
        yield '/'.join(jalur)

REF_SEL = re.compile(r"([A-Za-z][A-Za-z0-9_ %().\-]{0,40}?)!\$?([A-Z]{1,3})\$?(\d+)(?::\$?([A-Z]{1,3})\$?(\d+))?")

def rujukan_sel(teks):
    out = set()
    for m in REF_SEL.finditer(teks):
        sheet = m.group(1).strip().strip("'\"`([ ")
        if not sheet or len(sheet) < 2: continue
        sel = m.group(2) + m.group(3) + (':' + m.group(4) + m.group(5) if m.group(4) else '')
        out.add(f'{sheet}!{sel}')
    return sorted(out)

def cari_berkas(pola_nama, folder):
    return sorted(os.path.relpath(p, AKAR) for p in glob.glob(os.path.join(AKAR, folder, '**', pola_nama), recursive=True))

def test_untuk(nama_kelas):
    # Cocok lewat isi (kelas disebut) ATAU nama berkas (MicrometerSertifikatTest
    # menguji lewat HTTP dan tidak pernah menyebut nama kelasnya).
    dasar = {n[:-len('Profile')] for n in nama_kelas if n.endswith('Profile') and len(n) > len('Profile') + 3}
    hasil = set()
    for p in glob.glob(os.path.join(AKAR, 'tests', '**', '*.php'), recursive=True):
        isi = open(p, encoding='utf-8', errors='replace').read()
        if any(re.search(r'\b' + re.escape(n) + r'\b', isi) for n in nama_kelas) or any(b in os.path.basename(p) for b in dasar):
            hasil.add(os.path.relpath(p, AKAR))
    return sorted(hasil)

def pemakai_kelas(kelas):
    """Kelas lain di app/ yang menyebut kelas tabel ini — cakupan simulasi wajib ikut mereka."""
    out = set()
    for p in glob.glob(os.path.join(AKAR, 'app', '**', '*.php'), recursive=True):
        nama = os.path.basename(p)[:-4]
        if nama == kelas: continue
        if re.search(r'\b' + re.escape(kelas) + r'\b', open(p, encoding='utf-8', errors='replace').read()):
            out.add(nama)
    return sorted(out)

def konstanta_php(berkas):
    """Konstanta kelas + baris ci/vi bernilai angka di dalam method — kandidat ekstraksi lapis 1."""
    out = []
    if not os.path.exists(berkas): return out
    for i, baris in enumerate(open(berkas, encoding='utf-8'), 1):
        m = re.match(r"\s*(public|private|protected)?\s*const\s+([A-Z0-9_]+)\s*=\s*(.*)", baris)
        if m:
            out.append({'baris': i, 'nama': m.group(2), 'nilai_awal': m.group(3).strip()[:120], 'jenis': 'const'})
            continue
        m = re.search(r"'(ci|vi|u|titik|koreksi|drift|toleransi)'\s*=>\s*([0-9][0-9_.eE+\-]*)", baris)
        if m:
            out.append({'baris': i, 'nama': m.group(1), 'nilai_awal': m.group(2), 'jenis': 'literal_dalam_method'})
    return out

# ------------------------------------------------------------------- bangun
os.makedirs(os.path.join(OUT, 'katalog', 'paket'), exist_ok=True)
form_pdf = sorted(os.listdir(FORM))
semua = []
for p in PAKET:
    kode = p['kode']
    d = {k: v for k, v in p.items()}
    # formulir → nama PDF nyata
    d['formulir_pdf'] = [f for f in form_pdf if any(('SIDIK-FM-CAL-' + x) in f for x in p['formulir'])]
    # sheet master
    sheet = []
    for m in p['master']:
        folder = os.path.join(MASTER, m)
        if not os.path.isdir(folder):
            sheet.append({'master': m, 'ada': False}); continue
        for f in sorted(os.listdir(folder)):
            if not f.endswith('.csv'): continue
            nama = f[:-4]
            jml, awal = baca_header_csv(os.path.join(folder, f))
            sheet.append({'master': m, 'sheet': nama, 'golongan': golongkan_sheet(nama), 'jumlah_baris_csv': jml,
                          'cuplikan_atas': awal[:6] if golongkan_sheet(nama) == 'acuan_lapis1' else []})
    d['sheet_master'] = sheet
    # lembar dari JSON
    lembar, metadata, cakupan = [], {}, {}
    for jf in p['json']:
        isi = json.load(open(os.path.join(DATA, jf), encoding='utf-8'))
        for k, v in isi.items():
            if k.startswith('_'):
                metadata[f'{jf}:{k}'] = v if isinstance(v, str) else json.dumps(v, ensure_ascii=False)[:400]
                continue
            sebelum = len(lembar)
            lembar_dari(v, [k], lembar)
            for l in lembar[sebelum:]:
                l['sumber_json'] = jf
        semua_daun = [x for x in daun({k: v for k, v in isi.items() if not k.startswith('_')})]
        wakil = set()
        for l in lembar:
            if l.get('sumber_json') == jf:
                wakil |= l['_daun']
        hilang = [x for x in semua_daun if x not in wakil]
        cakupan[jf] = {'daun': len(semua_daun), 'tercakup': len(semua_daun) - len(hilang), 'contoh_hilang': hilang[:5]}
    for l in lembar:
        l['jumlah_sel_data'] = len(l.pop('_daun'))
        # Grid lebar ratusan kolom (mis. TIDS: index × titik) tidak terbaca di
        # layar; Studio menampilkannya memanjang: satu baris per (kunci, kolom).
        l['tampilan_usulan'] = 'panjang' if len(l.get('kolom', [])) > 24 else 'lebar'
    # CRLF dinormalkan supaya hash sama di Windows (core.autocrlf) dan Linux;
    # validasi_katalog.py menghitung dengan cara yang sama.
    d['sha256_json'] = {jf: hashlib.sha256(open(os.path.join(DATA, jf), 'rb').read().replace(b'\r\n', b'\n')).hexdigest() for jf in p['json']}
    d['lembar'] = lembar
    d['metadata_json'] = metadata
    d['cakupan'] = cakupan
    # rujukan sel dari generator + kelas Tabel*
    teks = ''
    for g in p['generator']:
        gp = os.path.join(AKAR, 'docs/skrip', g)
        if os.path.exists(gp): teks += open(gp, encoding='utf-8').read()
    for k in p['kelas'] + p['kalkulator']:
        kp = os.path.join(AKAR, 'app/Services/Calibration', k + '.php')
        if os.path.exists(kp): teks += open(kp, encoding='utf-8').read()
    d['rujukan_sel_ditemukan'] = rujukan_sel(teks)
    # berkas kode
    kode_berkas = []
    for k in p['kelas'] + p['kalkulator']:
        f = f'app/Services/Calibration/{k}.php'
        if os.path.exists(os.path.join(AKAR, f)): kode_berkas.append(f)
    for pr in p['profil']:
        for f in cari_berkas(pr + '.php', 'app/Services/Calibration/Profiles'):
            kode_berkas.append(f)
    d['berkas_kode'] = kode_berkas
    d['kelas_dipakai_oleh'] = {k: pemakai_kelas(k) for k in p['kelas']}
    nama_uji = [x for x in p['kelas'] + p['kalkulator'] + p['profil'] if not x.startswith('(')]
    d['test_terkait'] = test_untuk(nama_uji) if nama_uji else []
    d['pertanyaan_lab'] = [f'docs/pertanyaan-lab-{k}.md' for k in p['kata'] if os.path.exists(os.path.join(AKAR, f'docs/pertanyaan-lab-{k}.md'))]
    d['perintah_frontend'] = [f'docs/perintah-frontend-{k}.md' for k in p['kata'] if os.path.exists(os.path.join(AKAR, f'docs/perintah-frontend-{k}.md'))]
    if p['jenis'] == 'konstanta-php':
        cand = []
        for f in kode_berkas:
            for c in konstanta_php(os.path.join(AKAR, f)):
                c['berkas'] = f; cand.append(c)
        d['kandidat_konstanta'] = cand
    d['fixture_ada'] = [f for f in p['fixture'] if os.path.exists(os.path.join(DATA, f))]
    os.makedirs(os.path.join(OUT, 'katalog', 'paket', kode), exist_ok=True)
    json.dump(d, open(os.path.join(OUT, 'katalog', 'paket', kode, 'skema.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    semua.append(d)

json.dump([{k: x[k] for k in ('kode', 'nama', 'kelompok', 'jenis', 'json', 'profil', 'formulir_pdf', 'master', 'cakupan')} for x in semua],
          open(os.path.join(OUT, 'katalog', 'katalog.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('paket:', len(semua))
for x in semua:
    print(f"{x['kode']:22} {x['jenis']:15} lembar={len(x['lembar']):3} sheet={len(x['sheet_master']):3} test={len(x['test_terkait']):3} ref={len(x['rujukan_sel_ditemukan']):3} cakupan={x['cakupan']}")
