# Release Process — Hozio Image Optimizer

## ZIP Build Rules

1. **MUST use forward slashes** in the ZIP — PowerShell's `Compress-Archive` uses backslashes which break on Linux servers. Use `System.IO.Compression.ZipFile` with explicit forward slash replacement instead.

2. **Top-level folder MUST be `hozio-image-optimizer/`**. WordPress expects this exact folder name.

3. **Version MUST be bumped in THREE places** in `hozio-image-optimizer.php`:
   - Line ~5: `Version: X.X.X` (plugin header)
   - Line ~23: `define('HOZIO_IMAGE_OPTIMIZER_VERSION', 'X.X.X');`
   - Line ~24: `define('HOZIO_IMG_VERSION', 'X.X.X');`

4. **NEVER use `Compress-Archive`** — it creates Windows backslash paths that break WordPress plugin installation on Linux servers.

---

## How to Build the ZIP

Run the build script from the plugin directory:

```powershell
cd "g:\Plugins\Ai Image Renamer Plugin (Incomplete)\hozio-image-optimizer"
.\build-zip.ps1
```

This will:
- Read the version from the plugin header automatically
- Create `hozio-image-optimizer.zip` with forward-slash paths
- Exclude: `.git/`, `.claude/`, `.vscode/`, `build-zip.ps1`, `RELEASE.md`, `UPDATER-SETUP.md`, `*.zip`, `*.log`, temp files
- Top-level folder in ZIP is `hozio-image-optimizer/`
- Print file count and size when done

---

## Release Steps

1. **Bump version** in `hozio-image-optimizer.php` (all three places — header + both constants)

2. **Commit and push:**
   ```
   git add -A
   git commit -m "v1.4.3: description of changes"
   git push origin main
   ```

3. **Build ZIP** (NOT `Compress-Archive`!):
   ```powershell
   .\build-zip.ps1
   ```

4. **Verify ZIP entries use forward slashes:**
   The build script handles this automatically via `-replace '\\', '/'`

5. **Create GitHub release with ZIP attached:**
   ```
   gh release create v1.4.3 hozio-image-optimizer.zip --title "v1.4.3: Description"
   ```

6. **Copy ZIP to Downloads** (optional, for manual installs):
   ```
   copy hozio-image-optimizer.zip C:\Users\mtallo\Downloads\
   ```

---

## Updater Safety

- `after_install()` must NEVER delete or move directories. WordPress handles file placement.
- `fix_source_directory()` handles folder rename BEFORE install if ZIP has wrong folder name.
- `after_install()` should ONLY reactivate the plugin and clear cache.

---

## Repo Info

- **Repo:** https://github.com/mtallo22/hozio-image-optimizer (private)
- **Branch:** main
- **Updater file:** `includes/plugin-updater.php`
- **License:** Shared with Hozio Pro via `hozio_license_key` option
- **Cache:** Update checks cached for 12 hours (`hozio_imgopt_update_cache` transient)

---

## Quick Reference

```powershell
# Full release in 3 commands:
.\build-zip.ps1
git add -A && git commit -m "v1.4.3: changes" && git push origin main
gh release create v1.4.3 hozio-image-optimizer.zip --title "v1.4.3: Changes"
```
