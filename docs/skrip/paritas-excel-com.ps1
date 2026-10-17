<#
.SYNOPSIS
  Acuan paritas Sidik Studio: Excel (COM) menghitung ulang SALINAN workbook per
  skenario edit, lalu nilai Value2 SEMUA sel rumus ditulis ke JSON.

.DESCRIPTION
  Generik untuk workbook kalibrasi mana pun: tidak ada nama lembar, alamat sel,
  atau nilai yang ditanam di skrip ini. Berkas asli TIDAK pernah dibuka; yang
  dibuka salinan di folder temp acak, dihapus di akhir (juga saat gagal).

  Pasangan sisi server: `php artisan studio:paritas-excel` (mesin
  App\Services\Studio\Hitung pada skenario yang sama, lalu membandingkan).
  Pembungkus satu-perintah: docs/skrip/paritas-excel.ps1.

  Salinan dasar dibuat sekali: berkas bersandi dibuka dengan sandi lalu
  SaveAs TANPA sandi ke folder temp; proteksi lembar (<sheetProtection>)
  dibuang dari XML salinan (proteksi tidak memengaruhi hitung, tetapi
  Unprotect() lewat COM memunculkan dialog sandi yang menggantung).

  Per skenario:
    1. buka salinan segar dari salinan dasar (makro dimatikan, tautan luar
       TIDAK diperbarui, mode hitung manual supaya Excel tidak menghitung
       sebelum edit);
    2. (opsional) bekukan NOW()/TODAY() ke -WaktuAcuan (serial Excel) di SALINAN;
    3. terapkan edit seperti mengetik di Excel: "" = kosongkan, "=..." = rumus
       (sintaks Inggris, Range.Formula), angka bertitik desimal = angka,
       TRUE/FALSE = boolean, "'..." / selain itu = teks;
    4. Application.CalculateFull();
    5. baca UsedRange.Formula + UsedRange.Value2 per lembar (satu panggilan COM
       per lembar), simpan nilai sel yang berumus.

  Bentuk nilai di JSON (tanpa teks sel mentah - repo & workspace bisa terbaca):
    ["n","<double G17>"]   angka (G17 = round-trip persis IEEE-754)
    ["s","h:<16 hex>"]     teks -> sha256(UTF-8) 16 hex pertama, + panjang
    ["b","1"|"0"]          boolean
    ["e","#N/A"]           galat Excel
    ["k",""]               kosong

  Skenario "cache" selalu ditulis: nilai yang tersimpan di berkas (dibaca
  sebelum Excel menghitung apa pun) - pembanding mesin skenario tanpa edit.

.PARAMETER Workbook
  Jalur .xlsx / .xlsm.
.PARAMETER Skenario
  JSON: {"skenario":[{"nama":"a-tanpa-edit","edit":[]},
                     {"nama":"b1","edit":[{"lembar":"INPUT DATA","a":"E5","isi":"12.5"}]}]}
.PARAMETER Keluar
  Jalur JSON hasil (folder dibuat bila belum ada).
.PARAMETER Sandi
  Minta sandi buka workbook (Read-Host tersembunyi). Sandi tidak dicetak,
  tidak ditulis ke berkas, tidak disimpan.
.PARAMETER SandiAman
  SecureString dari pembungkus (paritas-excel.ps1) supaya sandi diketik sekali.
.PARAMETER WaktuAcuan
  Serial tanggal Excel (mis. 46309.5) untuk NOW(); TODAY() = INT(serial).

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File docs\skrip\paritas-excel-com.ps1 `
    -Workbook "C:\data\Master.xlsx" -Skenario skenario.json -Keluar hasil-excel.json -WaktuAcuan 46309.5
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$Workbook,
    [Parameter(Mandatory = $true)][string]$Skenario,
    [Parameter(Mandatory = $true)][string]$Keluar,
    [switch]$Sandi,
    [System.Security.SecureString]$SandiAman,
    [double]$WaktuAcuan = [double]::NaN
)

Set-StrictMode -Version 2
$ErrorActionPreference = 'Stop'

$kodeGalat = @{
    -2146826288 = '#NULL!'; -2146826281 = '#DIV/0!'; -2146826273 = '#VALUE!'; -2146826265 = '#REF!'
    -2146826259 = '#NAME?'; -2146826252 = '#NUM!'; -2146826246 = '#N/A'; -2146826245 = '#GETTING_DATA'
    -2146826236 = '#SPILL!'; -2146826238 = '#CALC!'; -2146826235 = '#FIELD!'; -2146826233 = '#BLOCKED!'
}
$inv = [System.Globalization.CultureInfo]::InvariantCulture
$sha = [System.Security.Cryptography.SHA256]::Create()

function Get-Kolom([int]$n) {
    $s = ''
    while ($n -gt 0) { $m = ($n - 1) % 26; $s = [char](65 + $m) + $s; $n = [int][math]::Floor(($n - 1) / 26) }
    return $s
}

function Get-HashTeks([string]$t) {
    $b = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($t))
    return 'h:' + ([System.BitConverter]::ToString($b).Replace('-', '').ToLower().Substring(0, 16)) + ':' + $t.Length
}

function ConvertTo-Nilai($v) {
    if ($null -eq $v) { return @('k', '') }
    if ($v -is [double]) { return @('n', $v.ToString('G17', $inv)) }
    if ($v -is [int]) {
        if ($kodeGalat.ContainsKey($v)) { return @('e', $kodeGalat[$v]) }
        return @('e', '#ERR' + $v)
    }
    if ($v -is [bool]) { if ($v) { return @('b', '1') } else { return @('b', '0') } }
    if ($v -is [string]) { if ($v -eq '') { return @('s', '') } else { return @('s', (Get-HashTeks $v)) } }
    if ($v -is [decimal]) { return @('n', ([double]$v).ToString('G17', $inv)) }
    if ($v -is [datetime]) { return @('n', $v.ToOADate().ToString('G17', $inv)) }
    return @('s', (Get-HashTeks ([string]$v)))
}

function Read-SelRumus($wb) {
    $hasil = [ordered]@{}
    foreach ($ws in $wb.Worksheets) {
        $nama = [string]$ws.Name
        $rng = $ws.UsedRange
        $r0 = [int]$rng.Row; $c0 = [int]$rng.Column
        $nb = [int]$rng.Rows.Count; $nk = [int]$rng.Columns.Count
        $f = $rng.Formula
        $v = $rng.Value2
        # Teks konstan yang kebetulan diawali '=' juga muncul di .Formula; rumus sungguhan
        # tidak pernah bernilai teks yang sama persis dengan rumusnya sendiri.
        if ($nb -eq 1 -and $nk -eq 1) {
            if (([string]$f).StartsWith('=') -and -not ($v -is [string] -and $v -eq [string]$f)) { $hasil["$nama!$(Get-Kolom $c0)$r0"] = (ConvertTo-Nilai $v) }
        } else {
            for ($i = 1; $i -le $nb; $i++) {
                for ($j = 1; $j -le $nk; $j++) {
                    $fi = $f[$i, $j]
                    if ($fi -is [string] -and $fi.StartsWith('=') -and -not ($v[$i, $j] -is [string] -and $v[$i, $j] -ceq $fi)) {
                        $hasil["$nama!$(Get-Kolom ($c0 + $j - 1))$($r0 + $i - 1)"] = (ConvertTo-Nilai $v[$i, $j])
                    }
                }
            }
        }
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($rng)
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($ws)
    }
    return $hasil
}

function Set-Isi($sel, [string]$isi) {
    if ($isi -eq '') { [void]$sel.ClearContents(); return }
    if ($isi.StartsWith('=')) { $sel.Formula = $isi; return }
    if ($isi.StartsWith("'")) { $sel.Value2 = $isi; return }
    if ($isi -match '^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$') { $sel.Value2 = [double]::Parse($isi, $inv); return }
    if ($isi -match '^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?%$') { $sel.Value2 = [double]::Parse($isi.TrimEnd('%'), $inv) / 100; return }
    if ($isi -ieq 'TRUE') { $sel.Value2 = $true; return }
    if ($isi -ieq 'FALSE') { $sel.Value2 = $false; return }
    # Teks: diawali apostrof supaya Excel tidak menafsirkannya (tanggal, angka lokal).
    $sel.Value2 = "'" + $isi
}

function Remove-ProteksiXml([string]$jalur) {
    # Hanya di SALINAN (paket ZIP polos): buang <sheetProtection .../> semua lembar.
    # Proteksi lembar tidak memengaruhi hitung, tetapi menghalangi edit skenario dan
    # pembekuan NOW(); Unprotect() lewat COM memunculkan dialog sandi yang menggantung.
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $n = 0
    $zip = [System.IO.Compression.ZipFile]::Open($jalur, [System.IO.Compression.ZipArchiveMode]::Update)
    try {
        $entri = @($zip.Entries | Where-Object { $_.FullName -match '^xl/worksheets/[^/]+\.xml$' })
        foreach ($e in $entri) {
            $r = New-Object System.IO.StreamReader($e.Open(), [System.Text.Encoding]::UTF8)
            $teks = $r.ReadToEnd(); $r.Close()
            $baru = [regex]::Replace($teks, '<sheetProtection[^>]*/>', '')
            if ($baru -ne $teks) {
                $nama = $e.FullName
                $e.Delete()
                $b = $zip.CreateEntry($nama)
                $w = New-Object System.IO.StreamWriter($b.Open(), (New-Object System.Text.UTF8Encoding($false)))
                $w.Write($baru); $w.Close()
                $n++
            }
        }
    } finally { $zip.Dispose() }
    return $n
}

function Set-WaktuBeku($wb, [double]$serial) {
    $s = $serial.ToString('G17', $inv)
    $hari = [math]::Floor($serial).ToString('G17', $inv)
    $n = 0
    foreach ($ws in $wb.Worksheets) {
        $rng = $ws.UsedRange
        $r0 = [int]$rng.Row; $c0 = [int]$rng.Column
        $nb = [int]$rng.Rows.Count; $nk = [int]$rng.Columns.Count
        $f = $rng.Formula
        for ($i = 1; $i -le $nb; $i++) {
            for ($j = 1; $j -le $nk; $j++) {
                $fi = if ($nb -eq 1 -and $nk -eq 1) { $f } else { $f[$i, $j] }
                if ($fi -is [string] -and $fi.StartsWith('=') -and $fi -match '(?i)\b(NOW|TODAY)\(\s*\)') {
                    $baru = [regex]::Replace($fi, '(?i)\bNOW\(\s*\)', "($s)")
                    $baru = [regex]::Replace($baru, '(?i)\bTODAY\(\s*\)', "($hari)")
                    $ws.Cells.Item($r0 + $i - 1, $c0 + $j - 1).Formula = $baru
                    $n++
                }
            }
        }
    }
    return $n
}

$asli = (Resolve-Path -LiteralPath $Workbook).Path
$ekstensi = [System.IO.Path]::GetExtension($asli)
$daftar = (Get-Content -LiteralPath $Skenario -Raw -Encoding UTF8 | ConvertFrom-Json).skenario
$plain = $null
if ($SandiAman) { $plain = $SandiAman }
elseif ($Sandi) { $plain = Read-Host -AsSecureString 'Sandi workbook (tidak ditampilkan, tidak disimpan)' }

$dir = Split-Path -Parent $Keluar
if ($dir -and -not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
$temp = Join-Path ([System.IO.Path]::GetTempPath()) ('sidik-paritas-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $temp | Out-Null
$excel = $null
$pidExcel = 0
$hasil = [ordered]@{
    alat = 'excel-com'
    berkas_sha256 = (Get-FileHash -LiteralPath $asli -Algorithm SHA256).Hash.ToLower()
    dibuat = (Get-Date).ToString('o')
    waktu_acuan = $(if ([double]::IsNaN($WaktuAcuan)) { $null } else { $WaktuAcuan.ToString('G17', $inv) })
    skenario = @()
}

try {
    $excel = New-Object -ComObject Excel.Application
    $excel.Visible = $false
    $excel.DisplayAlerts = $false
    $excel.AskToUpdateLinks = $false
    $excel.EnableEvents = $false
    $excel.ScreenUpdating = $false
    $excel.AutomationSecurity = 3   # msoAutomationSecurityForceDisable: makro tidak jalan
    $hasil.versi_excel = [string]$excel.Version
    Add-Type -Namespace SidikParitas -Name Win -MemberDefinition '[DllImport("user32.dll")] public static extern int GetWindowThreadProcessId(System.IntPtr hWnd, out int pid);' -ErrorAction SilentlyContinue
    [void][SidikParitas.Win]::GetWindowThreadProcessId([System.IntPtr]$excel.Hwnd, [ref]$pidExcel)

    # Mode hitung manual hanya bisa disetel bila ada workbook terbuka.
    $kosong = $excel.Workbooks.Add()
    $excel.Calculation = -4135      # xlCalculationManual
    $excel.CalculateBeforeSave = $false

    # PID Excel ditulis supaya pembungkus bisa membunuhnya bila tergantung dialog.
    [System.IO.File]::WriteAllText($Keluar + '.excel.pid', [string]$pidExcel)

    # Salinan dasar: ZIP polos tanpa proteksi lembar. Berkas bersandi dibuka sekali
    # dengan sandi lalu SaveAs TANPA sandi ke folder temp (dihapus di akhir).
    $basis = Join-Path $temp ("basis$ekstensi")
    if ($null -ne $plain) {
        $sumber = Join-Path $temp ("sumber$ekstensi")
        Copy-Item -LiteralPath $asli -Destination $sumber
        $bstr = [System.IntPtr]::Zero
        try {
            $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($plain)
            $wb0 = $excel.Workbooks.Open($sumber, 0, $true, [Type]::Missing, [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr))
        } finally {
            if ($bstr -ne [System.IntPtr]::Zero) { [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
        }
        $format = $(if ($ekstensi -ieq '.xlsm') { 52 } else { 51 })
        $wb0.SaveAs($basis, $format, '')
        $wb0.Close($false)
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($wb0)
        Remove-Item -LiteralPath $sumber -Force -ErrorAction SilentlyContinue
    } else {
        Copy-Item -LiteralPath $asli -Destination $basis
    }
    $hasil.proteksi_lembar_dilepas = (Remove-ProteksiXml $basis)

    $urutan = @([pscustomobject]@{ nama = 'cache'; edit = @() }) + @($daftar)
    $k = 0
    foreach ($sk in $urutan) {
        $k++
        $salinan = Join-Path $temp ("s$k$ekstensi")
        Copy-Item -LiteralPath $basis -Destination $salinan
        $mulai = Get-Date
        $wb = $excel.Workbooks.Open($salinan, 0, $false)
        $sel = $null
        try {
            $catatan = @()
            if ($sk.nama -eq 'cache') {
                $sel = Read-SelRumus $wb
            } else {
                if (-not [double]::IsNaN($WaktuAcuan)) { $catatan += ('NOW/TODAY dibekukan di ' + (Set-WaktuBeku $wb $WaktuAcuan) + ' sel') }
                foreach ($e in @($sk.edit)) {
                    $ws = $wb.Worksheets.Item([string]$e.lembar)
                    Set-Isi $ws.Range([string]$e.a) ([string]$e.isi)
                }
                $excel.CalculateFull()
                $sel = Read-SelRumus $wb
            }
            $hasil.skenario += [ordered]@{
                nama = [string]$sk.nama
                mode = $(if ($sk.nama -eq 'cache') { 'nilai-cache-berkas' } else { 'CalculateFull' })
                jumlah_edit = @($sk.edit).Count
                durasi_ms = [int]((Get-Date) - $mulai).TotalMilliseconds
                catatan = $catatan
                sel = $sel
            }
            Write-Host ("[{0}] {1}: {2} sel rumus, {3} ms" -f $k, $sk.nama, $sel.Count, [int]((Get-Date) - $mulai).TotalMilliseconds)
        } catch {
            # Satu skenario gagal (mis. lembar terproteksi sandi) tidak menghentikan yang lain.
            $hasil.skenario += [ordered]@{ nama = [string]$sk.nama; mode = 'gagal'; jumlah_edit = @($sk.edit).Count; catatan = @([string]$_.Exception.Message); sel = [ordered]@{} }
            Write-Host ("[{0}] {1}: GAGAL - {2}" -f $k, $sk.nama, $_.Exception.Message)
        } finally {
            $wb.Close($false)
            [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($wb)
            Remove-Item -LiteralPath $salinan -Force -ErrorAction SilentlyContinue
        }
    }
    $kosong.Close($false)
} finally {
    if ($null -ne $excel) {
        try { $excel.Quit() } catch { }
        [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
        $excel = $null
    }
    [GC]::Collect(); [GC]::WaitForPendingFinalizers()
    if ($pidExcel -gt 0) { Stop-Process -Id $pidExcel -Force -ErrorAction SilentlyContinue }
    Remove-Item -LiteralPath ($Keluar + '.excel.pid') -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $temp -Recurse -Force -ErrorAction SilentlyContinue
    $plain = $null
}

$json = $hasil | ConvertTo-Json -Depth 6 -Compress
[System.IO.File]::WriteAllText($Keluar, $json, (New-Object System.Text.UTF8Encoding($false)))
Write-Host "Ditulis: $Keluar"
