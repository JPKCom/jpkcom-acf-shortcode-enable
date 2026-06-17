# JPKCom ACF (Pro) Enable Shortcode – Developer Reference

## Plugin Overview

Enables ACF (Pro)'s `[acf]` shortcode by switching the `enable_shortcode` setting to `true` on `acf/init`, so field values can be rendered inside WYSIWYG content.

- **Text Domain:** `jpkcom-acf-shortcode-enable` (no header declared, defaults to slug; only used by the shared updater)
- **Requires Plugins:** `advanced-custom-fields-pro`
- **Min PHP:** 8.3 | **Min WP:** 6.9
- **Network:** not network-only (no `Network:` header)

---

## Architecture

```
Main file (jpkcom-acf-shortcode-enable.php)
├── declare(strict_types=1)
├── Plugin header (Requires Plugins: advanced-custom-fields-pro)
├── JPKCOM_ACF_SHORTCODE_ENABLE_VERSION constant
├── init @ priority 5: boot JPKComGitPluginUpdater
└── add_action acf/init → jpkcom_acf_enable_shortcode() : void
    └── acf_update_setting( 'enable_shortcode', true )
```

---

## Behaviour

| Hook | Type | Effect |
|------|------|--------|
| `acf/init` | action | Calls `acf_update_setting( 'enable_shortcode', true )` |

The callback is `function_exists()`-guarded and `jpkcom_`-prefixed (renamed from the previous generic `set_acf_settings`) to avoid global-namespace collisions.

---

## Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `JPKCOM_ACF_SHORTCODE_ENABLE_VERSION` | `'2.0.3'` | Plugin version (sync with header/README/phpdoc.xml) |

---

## File Structure

```
jpkcom-acf-shortcode-enable/
├── jpkcom-acf-shortcode-enable.php ← Main: header, constant, acf/init callback, updater bootstrap
├── includes/
│   └── class-plugin-updater.php  ← GitHub auto-updater (namespace: JPKComAcfShortcodeEnableGitUpdate)
├── .github/workflows/release.yml ← Build ZIP, manifest, PHPDoc, deploy to gh-pages (on tag push)
├── phpdoc.xml                    ← phpDocumentor config
├── README.md                     ← Public readme (source for the WP plugin modal)
├── CLAUDE.md                     ← This file
├── LICENSE                       ← GPL-2.0-or-later
└── .gitignore
```

---

## Plugin Updater

- **Namespace:** `JPKComAcfShortcodeEnableGitUpdate\JPKComGitPluginUpdater`
- **Manifest URL:** `https://jpkcom.github.io/jpkcom-acf-shortcode-enable/plugin_jpkcom-acf-shortcode-enable.json`
- Shared JPKCom updater (downstream copy of upstream `jpkcom-post-filter`; do not edit per-plugin). SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.

---

## Release Workflow

Triggered by **pushing a `v*` tag**; the workflow creates the GitHub release automatically. Pipeline: setup PHP/Python/Pandoc/GraphViz → README metadata → slug-named ZIP → SHA256 → upload ZIP + `.sha256` → `plugin_<slug>.json` manifest → PHPDoc → deploy to `gh-pages`.

---

## Security Checklist

- `declare(strict_types=1)` in every PHP file
- `acf/init` callback prefixed + `function_exists()`-guarded (no collision)
- Updater: SHA256 verification + URL validation (audited separately)

---

## Release Checklist

1. Bump version in: header `Version:` + `Stable tag:`, `JPKCOM_ACF_SHORTCODE_ENABLE_VERSION`, `README.md`, `phpdoc.xml`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. Commit, tag `vx.y.z`, push the tag → the workflow builds and publishes everything
