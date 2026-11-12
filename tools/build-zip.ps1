<#
  Fabrique le zip installable du module : build\snt_quotes-<version>.zip
  Contient uniquement ce dont la boutique a besoin (pas de vendor/, .git, tests, outils, docs de dev).

  Usage (depuis le dossier du module) :
    powershell -ExecutionPolicy Bypass -File tools\build-zip.ps1

  Les chemins du zip utilisent « / » (Compress-Archive de Windows PowerShell 5.1 écrit des « \ »,
  ce qui casse l'extraction sur un serveur Linux).
#>
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent $PSScriptRoot
$version = (Select-String -Path (Join-Path $root 'snt_quotes.php') -Pattern "this->version = '([^']+)'").Matches[0].Groups[1].Value
$build = Join-Path $root 'build'
$zipPath = Join-Path $build "snt_quotes-$version.zip"

# Fichiers et dossiers livrés dans la boutique.
$include = @(
  'snt_quotes.php', 'index.php', 'logo.png', 'config.xml',
  'classes', 'config', 'controllers', 'mails', 'src', 'translations', 'upgrade', 'views'
)

New-Item -ItemType Directory -Force $build | Out-Null
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
$count = 0
try {
  foreach ($item in $include) {
    $source = Join-Path $root $item
    if (-not (Test-Path $source)) { continue }
    $files = if ((Get-Item $source).PSIsContainer) { Get-ChildItem -Path $source -Recurse -File } else { @(Get-Item $source) }
    foreach ($file in $files) {
      $relative = $file.FullName.Substring($root.Length).TrimStart('\', '/').Replace('\', '/')
      [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $file.FullName, "snt_quotes/$relative", [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
      $count++
    }
  }
} finally {
  $zip.Dispose()
}

$size = [math]::Round((Get-Item $zipPath).Length / 1KB)
Write-Output "Zip créé : $zipPath ($count fichiers, $size Ko)"
