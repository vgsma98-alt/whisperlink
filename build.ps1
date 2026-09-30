$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$source = Join-Path $projectRoot 'whisperlink'
$output = Join-Path $projectRoot 'dist'
New-Item -ItemType Directory -Force -Path $output | Out-Null
$pluginHeader = Get-Content -LiteralPath (Join-Path $source 'whisperlink.php') -Raw
$version = [regex]::Match($pluginHeader, 'Version:\s*([0-9.]+)').Groups[1].Value
if (-not $version) { throw 'Plugin version missing' }
$zipPath = Join-Path $output "whisperlink-$version.zip"
# WordPress creates the destination directory from the ZIP filename. Keep the
# plugin files at the archive root and force ZIP-standard forward slashes so a
# Windows build extracts correctly on the Linux hosting server.
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }
$archive = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $source -Recurse -File | ForEach-Object {
        $entryName = $_.FullName.Substring($source.Length + 1).Replace('\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $_.FullName,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
    }
} finally {
    $archive.Dispose()
}
Get-Item -LiteralPath $zipPath | Select-Object FullName, Length
