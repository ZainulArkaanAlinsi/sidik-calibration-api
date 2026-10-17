<#
.SYNOPSIS
  Paritas mesin hitung Sidik Studio vs Excel, satu perintah, untuk workbook
  kalibrasi APA PUN (.xlsx/.xlsm, bersandi atau tidak).

.DESCRIPTION
  1. (opsional) bangkitkan skenario edit sintetis: php artisan studio:paritas-skenario
  2. Excel COM menghitung SALINAN workbook per skenario (paritas-excel-com.ps1),
     dijalankan sebagai job dengan batas waktu; bila tergantung (dialog Excel),
     proses Excel-nya dibunuh lewat berkas PID dan job dihentikan.
  3. php artisan studio:paritas-excel menjalankan mesin pada skenario yang sama
     dan menulis <Label>.paritas.json + .md ke folder -Keluar.

  Sandi (-Sandi): diketik SEKALI (tersembunyi). Ke Excel diteruskan sebagai
  SecureString; ke artisan lewat env STUDIO_SANDI_WORKBOOK yang hanya hidup
  selama perintah itu dan dihapus di finally. Tidak dicetak, tidak ditulis.

  Folder -Keluar menerima angka & alamat sel, teks sel hanya sebagai hash.
  Tetap simpan di luar repo (mis. _agents_workspace, git-exclude).

.EXAMPLE
  # Tanpa sandi, skenario dibangkitkan otomatis dari dua lembar input:
  powershell -NoProfile -ExecutionPolicy Bypass -File docs\skrip\paritas-excel.ps1 `
    -Workbook "C:\data\Anak timbangan.xlsx" -Label AT -Keluar C:\paritas `
    -LembarInput "INPUT DATA" -GantiTeks "E2,F1,M1"

.EXAMPLE
  # Workbook bersandi pemilik (Hydrometer .xlsx / 57 .xlsm): sandi diminta sekali.
  powershell -NoProfile -ExecutionPolicy Bypass -File docs\skrip\paritas-excel.ps1 `
    -Workbook "C:\Users\USER\Downloads\Hydrometer 0.600 - 0.650 gmL.xlsx" -Label HYD-0600 `
    -Keluar C:\paritas -Sandi
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Workbook,
    [Parameter(Mandatory = $true)][string]$Label,
    [Parameter(Mandatory = $true)][string]$Keluar,
    [string]$Skenario = '',
    [string[]]$LembarInput = @(),
    [string]$GantiTeks = '',
    [int]$JumlahSkenario = 8,
    [double]$WaktuAcuan = 46309.5,
    [switch]$Sandi,
    [string]$Api = '',
    [int]$BatasMenit = 30
)

$ErrorActionPreference = 'Stop'
if ($Api -eq '') { $Api = Split-Path -Parent (Split-Path -Parent $PSScriptRoot) }
$com = Join-Path $PSScriptRoot 'paritas-excel-com.ps1'
$inv = [System.Globalization.CultureInfo]::InvariantCulture
if (-not (Test-Path -LiteralPath $Keluar)) { New-Item -ItemType Directory -Path $Keluar | Out-Null }
$Workbook = (Resolve-Path -LiteralPath $Workbook).Path
$sec = $null
if ($Sandi) { $sec = Read-Host -AsSecureString 'Sandi workbook (tidak ditampilkan, tidak disimpan)' }

function Invoke-Artisan([string[]]$argumen) {
    $bstr = [System.IntPtr]::Zero
    Push-Location $Api
    try {
        if ($null -ne $sec) {
            $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
            $env:STUDIO_SANDI_WORKBOOK = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
        }
        # opcache + JIT hanya mempercepat harness (hasil angka tidak bergantung padanya).
        & php -d memory_limit=1500M -d zend_extension=opcache -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M artisan @argumen
        if ($LASTEXITCODE -ne 0) { throw "artisan $($argumen[0]) gagal ($LASTEXITCODE)" }
    } finally {
        Remove-Item Env:STUDIO_SANDI_WORKBOOK -ErrorAction SilentlyContinue
        if ($bstr -ne [System.IntPtr]::Zero) { [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
        Pop-Location
    }
}

if ($Skenario -eq '') {
    $Skenario = Join-Path $Keluar ("skenario-$Label.json")
    $arg = @('studio:paritas-skenario', $Workbook, "--jumlah=$JumlahSkenario", "--keluar=$Skenario")
    foreach ($l in $LembarInput) { $arg += "--lembar=$l" }
    if ($GantiTeks -ne '') { $arg += "--ganti-teks=$GantiTeks" }
    Invoke-Artisan $arg
}

$jsonExcel = Join-Path $Keluar ("excel-$Label.json")
$job = Start-Job -ScriptBlock {
    param($com, $wb, $sk, $out, $wa, $s)
    if ($null -ne $s) { & $com -Workbook $wb -Skenario $sk -Keluar $out -WaktuAcuan $wa -SandiAman $s }
    else { & $com -Workbook $wb -Skenario $sk -Keluar $out -WaktuAcuan $wa }
} -ArgumentList $com, $Workbook, $Skenario, $jsonExcel, $WaktuAcuan, $sec

if (-not (Wait-Job $job -Timeout ($BatasMenit * 60))) {
    $pidBerkas = $jsonExcel + '.excel.pid'
    if (Test-Path -LiteralPath $pidBerkas) {
        $p = [int](Get-Content -LiteralPath $pidBerkas -Raw)
        if ($p -gt 0) { Stop-Process -Id $p -Force -ErrorAction SilentlyContinue }
        Remove-Item -LiteralPath $pidBerkas -Force -ErrorAction SilentlyContinue
    }
    Stop-Job $job
    Get-ChildItem ([System.IO.Path]::GetTempPath()) -Directory -Filter 'sidik-paritas-*' -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    throw "Excel COM melewati $BatasMenit menit (kemungkinan dialog Excel) - dihentikan."
}
Receive-Job $job
Remove-Job $job

Invoke-Artisan @('studio:paritas-excel', $Workbook, "--excel=$jsonExcel", "--skenario=$Skenario",
    ('--waktu-acuan=' + $WaktuAcuan.ToString('R', $inv)), "--keluar=$Keluar", "--label=$Label")
$sec = $null
Write-Host "Selesai: $(Join-Path $Keluar ($Label + '.paritas.md'))"
