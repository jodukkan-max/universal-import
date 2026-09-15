# Universal Import — Notes

## Installation & Updates (IMPORTANT)

**Always install/update from the release zip — never from GitHub's "Download ZIP" source archive.**

- ✅ Correct: `https://github.com/jodukkan-max/universal-import/releases/latest/download/universal-import-main.zip`
- ❌ Wrong: `https://github.com/jodukkan-max/universal-import/archive/refs/heads/main.zip`

Why: WordPress installs the plugin into whatever top-level folder the archive unpacks to. The release zip unpacks to `universal-import-main/` (the registered plugin slug), so it must match the folder already on disk. If the archive unpacks to a different folder, WordPress deactivates the plugin with:

> The plugin universal-import-main/rey-swatches-import.php has been deactivated due to an error: Plugin file does not exist.

Recovery: make sure `wp-content/plugins/universal-import-main/rey-swatches-import.php` exists and remove any other leftover folder for this plugin (e.g. `rey-swatches-import/`), then re-activate.

