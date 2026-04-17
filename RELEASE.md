# Release Process — Hozio Image Optimizer

## Quick Release (3 commands)

```powershell
# 1. Build the ZIP
.\build-zip.ps1

# 2. Commit and push
git add -A && git commit -m "v1.4.2: description of changes" && git push origin main

# 3. Create GitHub release with the ZIP attached
gh release create v1.4.2 hozio-image-optimizer.zip --title "v1.4.2: Description"
```

---

## Full Step-by-Step

### 1. Bump the version (TWO places!)

In `hozio-image-optimizer.php`, update BOTH:

```php
// Plugin header (line ~5)
 * Version: 1.4.3

// Constants (line ~23-24)
define('HOZIO_IMAGE_OPTIMIZER_VERSION', '1.4.3');
define('HOZIO_IMG_VERSION', '1.4.3');
```

**Both must match!** The updater reads the header, the plugin uses the constants.

### 2. Build the ZIP

```powershell
.\build-zip.ps1
```

This creates `hozio-image-optimizer.zip` with the correct folder structure:
- Top-level folder: `hozio-image-optimizer/`
- All plugin files inside (excluding .git, .claude, build files, etc.)
- Uses `System.IO.Compression.ZipFile` (NOT `Compress-Archive`)

### 3. Commit and push

```powershell
git add -A
git commit -m "v1.4.3: brief description of changes"
git push origin main
```

### 4. Create the GitHub release

```powershell
gh release create v1.4.3 hozio-image-optimizer.zip --title "v1.4.3: Brief description"
```

**Important:** The ZIP file MUST be attached as a release asset. The updater prefers the attached ZIP over GitHub's auto-generated zipball.

### 5. Verify

- Go to https://github.com/mtallo22/hozio-image-optimizer/releases
- Confirm the ZIP is listed under "Assets"
- On a test site, go to Plugins > Check for updates
- The plugin should show the new version available

---

## How Auto-Updates Work

1. The `includes/plugin-updater.php` checks GitHub releases every 12 hours (cached)
2. If a newer version exists AND the site has a valid license key, WordPress shows the update
3. Clicking "Update" downloads the ZIP from the GitHub release asset
4. The `fix_source_directory` method renames the extracted folder to match the plugin slug
5. The plugin is automatically reactivated after update

## License

The plugin shares the `hozio_license_key` WordPress option with Hozio Pro. If a site already has Hozio Pro licensed, this plugin is automatically licensed too.

## Repo

- **URL:** https://github.com/mtallo22/hozio-image-optimizer
- **Visibility:** Private
- **Branch:** main
