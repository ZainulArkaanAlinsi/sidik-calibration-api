<#
.SYNOPSIS
  Baca TEKS TAMPILAN (`Range.Text`) sel-sel lembar SERTIFIKAT sebuah workbook master
  lewat Excel (COM) — hakim tampilan untuk `gen-cetak-master-massa-volume.py`.

.DESCRIPTION
  Generator fixture `tests/Fixtures/cetak-master/massa-volume.json` meniru pembulatan
  Excel dari nilai cache + number_format. Skrip ini membuktikan tiruan itu: Excel
  sendiri menyebut teks yang tercetak. Dibuka READ-ONLY, makro dimatikan, tautan luar
  tidak diperbarui; sandi dari env `SIDIK_MASTER_SANDI` (tidak dicetak, tidak ditulis).

  Hasil 17 Nov 2026: 476 sel di 11 master, 473 identik; 3 sisanya hanya beda tanda
  bintang nominal kembar (`200*`, `20*`) yang sengaja dibuang generator.
  Catatan temuan dari pembuktian ini: Excel TIDAK mencetak tanda minus bila angka
  negatif membulat ke nol (-0,0003 berformat `0.00` tercetak `0.00`).

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File docs\skrip\cek-teks-cetak-excel.ps1 `
    -Wb "C:\data\Master.xlsm" -Lembar SERTIFIKAT -DaftarSel "E21,N21,S21,Q22" -Out teks.json
#>
param(
    [Parameter(Mandatory = $true)][string]$Wb,
    [Parameter(Mandatory = $true)][string]$Lembar,
    [Parameter(Mandatory = $true)][string]$DaftarSel,
    [Parameter(Mandatory = $true)][string]$Out
)

$ErrorActionPreference = 'Stop'
$xl = New-Object -ComObject Excel.Application
$xl.Visible = $false
$xl.DisplayAlerts = $false
$xl.AutomationSecurity = 3   # msoAutomationSecurityForceDisable: tanpa makro
try {
    $wbk = $xl.Workbooks.Open($Wb, 0, $true, [Type]::Missing, $env:SIDIK_MASTER_SANDI)
    $ws = $wbk.Worksheets.Item($Lembar)
    $hasil = [ordered]@{}
    foreach ($a in ($DaftarSel -split ',')) {
        $hasil[$a] = [string]$ws.Range($a).Text
    }
    $hasil | ConvertTo-Json | Set-Content -Encoding UTF8 -LiteralPath $Out
    $wbk.Close($false)
}
finally {
    $xl.Quit()
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($xl)
}
