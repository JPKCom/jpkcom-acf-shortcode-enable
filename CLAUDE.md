# JPKCom ACF (Pro) Enable Shortcode – Developer Reference

## Plugin Overview

Re-enables ACF (Pro)'s `[acf]` shortcode, which ACF turns off by default, and lifts the extra restriction ACF places on it under block themes.

- **Text Domain:** `jpkcom-acf-shortcode-enable` (no header declared, defaults to slug; the plugin has no translatable strings of its own)
- **Requires Plugins:** `advanced-custom-fields-pro`
- **Min PHP:** 8.3 | **Min WP:** 6.9
- **Network:** not network-only (no `Network:` header)

> **Verified against ACF Pro 6.8.6** (current release at the time of writing, 2026-07-14) and WordPress 7.0.2. Every hook below carries the ACF file and line that consumes it — check those first when an ACF update lands.

---

## Why the shortcode is off in the first place

ACF does not simply ship `enable_shortcode => false`. The default is `true`, and ACF then flips it based on when ACF was *first activated* on the site (`acf.php:239-244`):

```php
$first_activated_version = acf_get_version_when_first_activated();

// Only enable shortcode by default for versions prior to 6.3
if ( $first_activated_version && version_compare( $first_activated_version, '6.3', '>=' ) ) {
    $this->settings['enable_shortcode'] = false;
}
```

So on an installation whose ACF was first activated on **6.3 or later** the shortcode is off and this plugin is what turns it back on. On an older installation it is already on and **this plugin is a no-op**. Worth knowing before hunting for an effect that was never missing. On `posts.ddev.site`, `acf_get_version_when_first_activated()` returns `6.3.6`, so the plugin is load-bearing there — measured: `acf_get_setting( 'enable_shortcode' )` is `true` with the plugin and `false` without it, and the field value appears in the rendered page only with it.

Turning the shortcode on is, by design, taking back a hardening ACF introduced. That is the purpose of the plugin, not an oversight — but it should be a conscious decision per site.

---

## Architecture

```
Main file (jpkcom-acf-shortcode-enable.php)
├── declare(strict_types=1)
├── Plugin header (Requires Plugins: advanced-custom-fields-pro)
├── JPKCOM_ACF_SHORTCODE_ENABLE_VERSION constant
├── init @ priority 5: boot JPKComGitPluginUpdater
├── acf/init → jpkcom_acf_enable_shortcode()
│              └── acf_update_setting( 'enable_shortcode', true )
├── acf/settings/enable_shortcode                        → __return_true (PHP_INT_MAX)
└── acf/shortcode/allow_in_block_themes_outside_content  → __return_true (PHP_INT_MAX)
```

---

## Behaviour

| Hook | Consumed by (ACF Pro 6.8.6) | Effect |
|------|-----------------------------|--------|
| `acf/init` | — | Writes `enable_shortcode = true` so readers of `acf_raw_setting()` see it |
| `acf/settings/enable_shortcode` (`PHP_INT_MAX`) | `acf_get_setting()`, `includes/api/api-helpers.php:101` | Reports the shortcode as enabled on **every read**, which is the read `acf_shortcode()` performs |
| `acf/shortcode/allow_in_block_themes_outside_content` (`PHP_INT_MAX`) | `acf_shortcode()`, `includes/api/api-template.php:1025-1030` | Allows the shortcode outside `the_content` on block themes |

The `acf/init` callback is `function_exists()`-guarded and `jpkcom_`-prefixed (renamed from the previous generic `set_acf_settings`) to avoid global-namespace collisions.

### Why both the write and the filter

`acf_shortcode()` reads the setting at render time through `acf_get_setting()`, which applies `acf/settings/enable_shortcode` on every call. A one-shot `acf_update_setting()` on `acf/init` is therefore not authoritative: any later write wins. Measured against 6.8.6 with a competing plugin writing `false` on `acf/init` priority 999 — with only the write, the shortcode stopped rendering; with the filter it kept working. The write is kept because anything reading `acf_raw_setting( 'enable_shortcode' )` bypasses the filter.

Timing is not the problem, and was worth ruling out: `add_shortcode( 'acf', 'acf_shortcode' )` runs unconditionally at include time (`api-template.php:1133`) and the setting is only consulted inside the callback (line 1017), so `acf/init` is early enough.

### The block-theme restriction, and that we lift it

On a block theme `acf_shortcode()` returns nothing unless it is running inside the `the_content` filter, independently of `enable_shortcode`. Without the filter this plugin now sets, enabling the shortcode only covered post content on an FSE theme; a template part, a widget or a block outside the content flow rendered empty with no diagnostic.

Measured on Twenty Twenty-Five, rendering `[acf]` from `wp_footer` (i.e. outside `the_content`):

| Theme | State | outside `the_content` | inside `the_content` |
|---|---|---|---|
| Twenty Twenty-Five (block) | 2.0.8 behaviour | **empty** | value |
| Twenty Twenty-Five (block) | 2.0.9 | **value** | value |
| bootscore-child (classic) | 2.0.9 | value | value |

Note the scope: this covers **every** rendering context, not only post content. It is a deliberate widening, chosen for this fleet; if a site should keep ACF's narrower default, remove that one filter.

---

## What "enabled" still does not get you

ACF places several further conditions on the shortcode. All of these bite silently — the shortcode returns an empty string, with no error and no log entry, except in previews where ACF substitutes an explanatory message:

- **Only registered fields.** `acf_shortcode()` forces `acf/prevent_access_to_unknown_fields` to `true` for the duration of the call, so a field name with no ACF definition yields nothing.
- **Resolved through the reference meta.** `acf_maybe_get_field( …, $strict = true )` looks the name up via `acf_get_meta_field()`, i.e. through the `_fieldname` → field-key meta. A value written with a bare `update_post_meta()` and no reference row is invisible to the shortcode, even when `get_field()` returns it.
- **Only bindings-capable field types** (`acf_field_type_supports( $type, 'bindings', true )`), and only fields whose `allow_in_bindings` is not false.
- **Only publicly viewable posts**, when a `post_id` other than the current one is passed (`acf/shortcode/prevent_access_to_fields_on_non_public_posts`).
- **Capability gates:** previews need `publish_posts` (`acf/shortcode/preview_capability`), AJAX requests need `edit_posts` (`acf/ajax/shortcode_capability`).
- **The value is escaped** unless `acf/shortcode/allow_unsafe_html` is filtered; when ACF strips something it fires `acf/removed_unsafe_html`.

---

## Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `JPKCOM_ACF_SHORTCODE_ENABLE_VERSION` | matches the header `Version:` | Plugin version (sync with header/README/phpdoc.xml) |

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
- Shared JPKCom updater (downstream copy of upstream `jpkcom-post-filter`; do not edit per-plugin). SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`. Checksum verification is **mandatory**: a missing or unfetchable `checksum_sha256` aborts the update instead of installing unverified code. The verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed (no second download). Failed manifest fetches are negatively cached for 1 h.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.

---

## Release Workflow

**Actions are pinned to commit SHAs.** Every `uses:` line in `.github/workflows/` references a 40-character commit SHA instead of a tag (`@v4`), with the version as a trailing comment. A tag is a movable pointer and can be repointed; a SHA cannot. Since the release workflow builds the plugin ZIP **and** the SHA256 checksum the auto-updater trusts, a compromised action would ship a tampered ZIP together with a matching checksum — the checksum secures the transport, the pinning secures the build. `.github/dependabot.yml` keeps the pins current weekly in one combined PR; when updating, always change the SHA *and* the version comment together.

**CI** (`.github/workflows/ci.yml`) runs on every pull request *and* on every push to `main` — a required status check only covers pull requests, so a direct push with bypass rights would otherwise skip the checks entirely. It runs `php -l` over all PHP files; flags invalid named arguments to internal PHP functions (catches `sprintf(format:, values:)` → `ArgumentCountError`, which `php -l` does not see); validates the YAML of every `.github` file; asserts every action is pinned to a 40-character commit SHA; and executes `tests/test-*.php` where present.

**Dependabot auto-merge** (`.github/workflows/dependabot-auto-merge.yml`) merges only `semver-patch` and `semver-minor`, and only PRs from `dependabot[bot]` in this repo — never from forks. Major updates get a comment and stay manual. Two repo settings are prerequisites, otherwise this is useless or outright dangerous: "Allow auto-merge" must be enabled, and branch protection must list `CI / Lint & Guards` as a **required status check** — without it `gh pr merge --auto` merges *immediately*, since there is nothing left to wait for. Together with `cooldown: default-days: 7` no action release is adopted during its first week.

Triggered by **pushing a `v*` tag**; the workflow creates the GitHub release automatically. Pipeline: setup PHP/Python/Pandoc/GraphViz → README metadata → slug-named ZIP → SHA256 → upload ZIP + `.sha256` → `plugin_<slug>.json` manifest → PHPDoc → deploy to `gh-pages`.

---

## Security Checklist

- `declare(strict_types=1)` in every PHP file
- `acf/init` callback prefixed + `function_exists()`-guarded (no collision)
- No input, no output, no database writes of its own
- Deliberately reverses two ACF hardening defaults — see the two sections above; that is the plugin's purpose, but it belongs in a per-site decision
- ACF's own output escaping is left intact: `acf/shortcode/allow_unsafe_html` is **not** filtered here
- Updater: SHA256 verification + URL validation (audited separately)

### Plugin dependency

`Requires Plugins: advanced-custom-fields-pro` works even though ACF Pro is not on wordpress.org: WordPress resolves the slug against the installed plugin folder. Verified on WP 7.0.2 — `WP_Plugin_Dependencies::get_dependency_filepath()` returns `advanced-custom-fields-pro/acf.php`, `has_unmet_dependencies()` is `false`, and the name resolves to "Advanced Custom Fields PRO". Only `get_dependency_data()` returns `false`, which just means no "install dependency" link in the Plugins screen. The prerequisite is that ACF Pro sits in a folder with exactly that name.

---

## Tests

`tests/test-hooks.php` runs standalone — no WordPress and no ACF. It stubs the functions the main file touches at load time, requires the plugin, then asserts hook names and priorities and invokes each callback: both new filters and their return values, that the `acf/init` write still happens and touches only `enable_shortcode`, the updater bootstrap priority, and that the version constant matches the header. 10 cases; 2 of them fail against 2.0.8. CI runs it on every pull request and push to `main`.

```bash
php tests/test-hooks.php   # exit 0 = green
```

What it cannot cover is whether ACF still honours these hooks, or the block-theme behaviour — both need a real instance with a block theme active. See the measured table above.

---

## Release Checklist

1. Bump the version in five places:
   - Plugin header `Version:`
   - Plugin header `Stable tag:`
   - Constant `JPKCOM_ACF_SHORTCODE_ENABLE_VERSION`
   - `phpdoc.xml` `<version number="…">`
   - `README.md` — `**Version:**` and `**Stable tag:**`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. Run `php tests/test-hooks.php`
4. Commit, then push the tag `vx.y.z` → the workflow builds and publishes everything
