This folder holds the WordPress.org **plugin directory listing** assets (icon,
banner, screenshots) for karks-crm. It is not part of the plugin itself and is
excluded from releases via `.distignore`.

The `deploy-wporg.yml` GitHub Actions workflow copies this folder's contents
into the plugin's SVN `assets/` folder on every tagged release.

Add files here using WordPress.org's expected names/sizes:

- `icon-128x128.png` and `icon-256x256.png` (or a single `icon.svg`)
- `banner-772x250.png` and `banner-1544x500.png` (retina)
- `screenshot-1.png`, `screenshot-2.png`, ... — numbered to match the
  `== Screenshots ==` section in `readme.txt` (add that section when
  screenshots are added; each numbered caption there corresponds to the
  same-numbered file here)

All images should be `.png` or `.jpg` (or `.svg`/`.gif` for the icon only).
