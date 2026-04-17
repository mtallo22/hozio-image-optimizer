# ============================================================
# Hozio Image Optimizer — Build Release ZIP
# ============================================================
# Usage: .\build-zip.ps1
#
# This creates a properly structured ZIP for GitHub releases.
# The ZIP will contain: hozio-image-optimizer/[all plugin files]
#
# IMPORTANT: Uses System.IO.Compression.ZipFile, NOT Compress-Archive
# (Compress-Archive creates ZIPs that WordPress can't extract properly)
# ============================================================

$ErrorActionPreference = "Stop"

# Config
$pluginSlug = "hozio-image-optimizer"
$pluginDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$buildDir   = Join-Path $pluginDir "build"
$stageDir   = Join-Path $buildDir $pluginSlug

# Read version from plugin header
$mainFile = Join-Path $pluginDir "hozio-image-optimizer.php"
$versionLine = Select-String -Path $mainFile -Pattern "^\s*\*\s*Version:\s*(.+)" | Select-Object -First 1
if ($versionLine) {
    $version = $versionLine.Matches[0].Groups[1].Value.Trim()
} else {
    Write-Error "Could not read version from plugin header!"
    exit 1
}

$zipName = "$pluginSlug.zip"
$zipPath = Join-Path $pluginDir $zipName

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Building $pluginSlug v$version" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Clean previous build
if (Test-Path $buildDir) {
    Remove-Item $buildDir -Recurse -Force
}
if (Test-Path $zipPath) {
    Remove-Item $zipPath -Force
}

# Create staging directory
New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

# Files and folders to EXCLUDE from the ZIP
$excludes = @(
    ".git",
    ".gitignore",
    ".claude",
    "build",
    "build-zip.ps1",
    "RELEASE.md",
    "UPDATER-SETUP.md",
    "README.md",
    "*.zip",
    ".vscode",
    ".idea",
    "node_modules",
    "*.log",
    ".DS_Store",
    "Thumbs.db"
)

# Copy files to staging, excluding unwanted items
Write-Host "Copying files to staging..." -ForegroundColor Yellow

Get-ChildItem -Path $pluginDir -Recurse -Force | ForEach-Object {
    $relativePath = $_.FullName.Substring($pluginDir.Length + 1)

    # Check if this path matches any exclude pattern
    $skip = $false
    foreach ($exclude in $excludes) {
        if ($relativePath -like "$exclude*" -or $relativePath -like "*\$exclude*" -or $_.Name -like $exclude) {
            $skip = $true
            break
        }
    }

    if (-not $skip) {
        $destPath = Join-Path $stageDir $relativePath
        if ($_.PSIsContainer) {
            if (-not (Test-Path $destPath)) {
                New-Item -ItemType Directory -Path $destPath -Force | Out-Null
            }
        } else {
            $destDir = Split-Path $destPath -Parent
            if (-not (Test-Path $destDir)) {
                New-Item -ItemType Directory -Path $destDir -Force | Out-Null
            }
            Copy-Item $_.FullName $destPath -Force
        }
    }
}

# Count files
$fileCount = (Get-ChildItem -Path $stageDir -Recurse -File).Count
Write-Host "Staged $fileCount files" -ForegroundColor Green

# Create ZIP using System.IO.Compression (NOT Compress-Archive!)
Write-Host "Creating ZIP archive..." -ForegroundColor Yellow

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $buildDir,
    $zipPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false  # includeBaseDirectory = false (the folder IS the base)
)

# Verify
if (Test-Path $zipPath) {
    $size = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host "  SUCCESS: $zipName ($size MB)" -ForegroundColor Green
    Write-Host "  Version: $version" -ForegroundColor Green
    Write-Host "  Files:   $fileCount" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Cyan
    Write-Host "  1. git add -A && git commit -m 'v$version'" -ForegroundColor White
    Write-Host "  2. git push origin main" -ForegroundColor White
    Write-Host "  3. gh release create v$version $zipName --title 'v$version'" -ForegroundColor White
    Write-Host ""
} else {
    Write-Error "ZIP creation failed!"
    exit 1
}

# Clean up staging
Remove-Item $buildDir -Recurse -Force

Write-Host "Done!" -ForegroundColor Green
