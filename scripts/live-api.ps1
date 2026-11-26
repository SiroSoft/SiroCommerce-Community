#Requires -Version 5.1
<#
.SYNOPSIS
  Call the live SiroCommerce API from Windows PowerShell without quote-mangling.
.DESCRIPTION
  PowerShell strips embedded double quotes when passing inline JSON to native
  curl.exe (e.g. -d '{"email":"a@b.cc"}' arrives as {email:a@b.cc}), which the
  backend then rejects as invalid JSON. This helper always sends the body from
  a temp file (--data-binary @file), so quotes survive intact.
.EXAMPLE
  .\scripts\live-api.ps1 -Method POST -Path /api/auth/forgot-password -Body '{"email":"buyer@example.com"}'
.EXAMPLE
  .\scripts\live-api.ps1 -Path /api/account/orders -Token $env:SIRO_TOKEN
#>
param(
  [string]$Method = "GET",
  [Parameter(Mandatory = $true)][string]$Path,
  [string]$Body = "",
  [string]$Token = "",
  [string]$Base = "https://ecommerce.sirophp.com"
)

$curlArgs = @("-s", "-X", $Method, "$Base$Path", "-w", "`nHTTP:%{http_code}`n")
$tempFile = $null
try {
  if ($Body -ne "") {
    $tempFile = Join-Path ([IO.Path]::GetTempPath()) ("siro-body-" + [guid]::NewGuid().ToString("N") + ".json")
    [IO.File]::WriteAllText($tempFile, $Body, [Text.UTF8Encoding]::new($false))
    $curlArgs += @("-H", "Content-Type: application/json", "--data-binary", "@$tempFile")
  }
  if ($Token -ne "") { $curlArgs += @("-H", "Authorization: Bearer $Token") }
  & "C:\Windows\System32\curl.exe" @curlArgs
}
finally {
  if ($tempFile -and (Test-Path -LiteralPath $tempFile)) { Remove-Item -LiteralPath $tempFile -Force }
}
