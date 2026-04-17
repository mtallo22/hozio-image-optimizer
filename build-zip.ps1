# ============================================================
# Hozio Image Optimizer — Build Release ZIP
# ============================================================
# MUST use System.IO.Compression.ZipFile with forward slashes.
# NEVER use Compress-Archive (creates backslash paths that break on Linux).
# ============================================================

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$pluginSlug  = "hozio-image-optimizer"
$srcDir      = Split-Path -Parent $MyInvocation.MyCommand.Path
$releasesDir = Join-Path $srcDir "releases"
if (-not (Test-Path $releasesDir)) { New-Item -ItemType Directory -Path $releasesDir | Out-Null }
# Legacy root zip kept for the GitHub updater; versioned copy goes in releases/
$zipPath     = Join-Path $srcDir "$pluginSlug.zip"

# Files/folders to EXCLUDE from the ZIP
$excludeNames = @(
    ".git", ".gitignore", ".claude", ".vscode", ".idea",
    "build-zip.ps1", "RELEASE.md", "UPDATER-SETUP.md", "README.md",
    "node_modules", "build", "releases",
    ".DS_Store", "Thumbs.db", "desktop.ini"
)

$excludeExtensions = @(".zip", ".log", ".tmp", ".bak", ".swp")

# Read version from plugin header
$mainFile = Join-Path $srcDir "$pluginSlug.php"
$versionLine = Select-String -Path $mainFile -Pattern "^\s*\*\s*Version:\s*(.+)" | Select-Object -First 1
if ($versionLine) {
    $version = $versionLine.Matches[0].Groups[1].Value.Trim()
} else {
    Write-Error "Could not read version from plugin header!"
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Building $pluginSlug v$version" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan

# Remove old ZIP
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Create ZIP with forward slashes
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
$fileCount = 0

# Get all files recursively
Get-ChildItem $srcDir -Recurse -File -Force | ForEach-Object {
    $relativePath = $_.FullName.Substring($srcDir.Length + 1)

    # Check excludes
    $skip = $false

    # Check if any parent folder or the file itself is in exclude list
    foreach ($exc in $excludeNames) {
        if ($relativePath -like "$exc\*" -or $relativePath -like "*\$exc\*" -or $_.Name -eq $exc -or $_.Directory.Name -eq $exc) {
            $skip = $true
            break
        }
    }

    # Check file extension
    if (-not $skip) {
        foreach ($ext in $excludeExtensions) {
            if ($_.Extension -eq $ext) {
                $skip = $true
                break
            }
        }
    }

    # Skip the root .git folder and anything inside it
    if ($relativePath.StartsWith(".git\") -or $relativePath.StartsWith(".git/")) {
        $skip = $true
    }

    if (-not $skip) {
        # CRITICAL: Use forward slashes for Linux compatibility
        $entryName = "$pluginSlug/" + ($relativePath -replace '\\', '/')

        $entry = $zip.CreateEntry($entryName)
        $stream = $entry.Open()
        $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
        $fileCount++
    }
}

$zip.Dispose()

# Copy versioned zip to releases/
$versionedZip = Join-Path $releasesDir "$pluginSlug-v$version.zip"
Copy-Item $zipPath $versionedZip -Force

# Verify
if (Test-Path $zipPath) {
    $size = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host "  SUCCESS" -ForegroundColor Green
    Write-Host "  File:    $pluginSlug.zip ($size MB)" -ForegroundColor Green
    Write-Host "  Versioned: releases/$pluginSlug-v$version.zip" -ForegroundColor Green
    Write-Host "  Version: $version" -ForegroundColor Green
    Write-Host "  Files:   $fileCount" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "  1. git add -A && git commit -m `"v${version}`"" -ForegroundColor White
    Write-Host "  2. git push origin main" -ForegroundColor White
    Write-Host "  3. gh release create v${version} ${pluginSlug}.zip --title `"v${version}`"" -ForegroundColor White
    Write-Host ""
} else {
    Write-Error "ZIP creation failed!"
    exit 1
}
