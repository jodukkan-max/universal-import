# Universal Import — Notes

## Installation & Updates (IMPORTANT)

**Always install/update from the release zip — never from GitHub's "Download ZIP" source archive.**

- ✅ Correct: `https://github.com/jodukkan-max/universal-import/releases/latest/download/universal-import.zip`
- ❌ Wrong: `https://github.com/jodukkan-max/universal-import/archive/refs/heads/main.zip`

Why: WordPress installs the plugin into whatever top-level folder the archive unpacks to. The release zip unpacks to `universal-import/` (the registered plugin slug), so it must match the folder already on disk. If the archive unpacks to a different folder, WordPress deactivates the plugin with:

> The plugin universal-import/rey-swatches-import.php has been deactivated due to an error: Plugin file does not exist.

Recovery: make sure `wp-content/plugins/universal-import/rey-swatches-import.php` exists and remove any other leftover folder for this plugin (e.g. `rey-swatches-import/` or `universal-import-main/`), then re-activate.

## Changelog

### 1.19.6
- **Standardize the plugin folder/slug on `universal-import`.** The release zip, top-level folder, and updater `PLUGIN_SLUG` were previously `universal-import-main`; they now all use `universal-import` to match installs that were copied from the raw source folder. Fixes the folder mismatch that caused the update to install into a second folder (and the "Plugin file does not exist" deactivation).

### 1.19.5
- **Cache the GitHub update check.** The updater now caches `version.json` in a site transient for 12 hours instead of hitting `raw.githubusercontent.com` on every admin page load (it was firing 3× per request). Failures back off for 1 hour instead of retrying on each load.

### 1.19.4
- **Image deduplication.** Product/variation images are now resolved to a single media attachment (per source URL) instead of being re-downloaded every time they appear. The same photo in the parent gallery *and* multiple variations now installs once and is referenced by ID.
- Images are passed to the WooCommerce REST API as `{id}` instead of `{src}`, so no duplicate files are created.
- Cross-session dedupe: a `_source_url` attachment meta is recorded, so re-importing the same product later reuses existing attachments.
- Cache-busting query strings (`?v=…`) are stripped so the same CDN photo with different params maps to one attachment.

### 1.19.3
- Removed the ERP sync section (endpoint, admin tab, docs).
- Removed the "resize images" / ImgBB API key feature (admin UI and all code).

