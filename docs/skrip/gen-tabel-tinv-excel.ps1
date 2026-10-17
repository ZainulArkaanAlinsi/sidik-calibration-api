<#
.SYNOPSIS
  Generator tabel acuan TINV Excel untuk mesin hitung Sidik Studio
  (App\Services\Studio\Hitung\TabelTinvExcel). JANGAN disunting tangan.

.DESCRIPTION
  Excel tidak membulatkan-benar TINV/T.INV.2T: dibanding kuantil presisi tinggi
  (Decimal 60 digit) selisihnya sampai 11 ULP untuk p = 0,05, df 1..80, dan
  BETA.INV yang dipakainya pun meleset sampai 3 ULP (bukti:
  _agents_workspace/studio-data-acuan/C-anak-timbangan/excel-audit.md). Algoritma
  internalnya tidak terbuka, jadi untuk p yang dipakai workbook kalibrasi nilai
  Excel sendiri yang dijadikan tabel: df bulat 1..DfMaks (TINV memotong df ke
  bilangan bulat). Di luar tabel mesin memakai algoritma cadangan (selisih
  beberapa ULP, dilaporkan harness paritas).

  Keluaran: biner double little-endian (8 byte per df, df = 1..DfMaks berurutan),
  ke resources/studio/tinv-excel/p<P>.bin. Skrip juga memeriksa bahwa
  T.INV.2T(p,df) identik bit dengan TINV(p,df) untuk seluruh tabel.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File docs\skrip\gen-tabel-tinv-excel.ps1 -P 0.05 -DfMaks 5000
#>
param(
    [double]$P = 0.05,
    [int]$DfMaks = 5000,
    [string]$Keluar = ''
)

$ErrorActionPreference = 'Stop'
$inv = [System.Globalization.CultureInfo]::InvariantCulture
if ($Keluar -eq '') {
    $Keluar = Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) ('resources\studio\tinv-excel\p' + $P.ToString('R', $inv) + '.bin')
}

$excel = New-Object -ComObject Excel.Application
try {
    $excel.Visible = $false
    $excel.DisplayAlerts = $false
    $wb = $excel.Workbooks.Add()
    $ws = $wb.Worksheets.Item(1)
    $pTeks = $P.ToString('R', $inv)
    $ws.Range("A1:A$DfMaks").Formula = "=TINV($pTeks,ROW())"
    $ws.Range("B1:B$DfMaks").Formula = "=_xlfn.T.INV.2T($pTeks,ROW())"
    $excel.CalculateFull()
    $a = $ws.Range("A1:A$DfMaks").Value2
    $b = $ws.Range("B1:B$DfMaks").Value2
    $dir = Split-Path -Parent $Keluar
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
    $fs = [System.IO.File]::Create($Keluar)
    $bw = New-Object System.IO.BinaryWriter($fs)
    try {
        for ($i = 1; $i -le $DfMaks; $i++) {
            $x = $a[$i, 1]; $y = $b[$i, 1]
            if (-not ($x -is [double])) { throw "TINV($pTeks,$i) bukan angka" }
            if ([BitConverter]::DoubleToInt64Bits($x) -ne [BitConverter]::DoubleToInt64Bits([double]$y)) { throw "T.INV.2T beda dari TINV di df=$i" }
            $bw.Write([double]$x)
        }
    } finally { $bw.Close(); $fs.Close() }
    $wb.Close($false)
    Write-Host ("Excel {0}: TINV({1}, 1..{2}) -> {3} (sha256 {4})" -f $excel.Version, $pTeks, $DfMaks, $Keluar, (Get-FileHash -LiteralPath $Keluar -Algorithm SHA256).Hash.ToLower())
} finally {
    $excel.Quit()
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
}
