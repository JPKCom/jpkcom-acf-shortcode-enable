# JPKCom ACF (Pro) Enable Shortcode

**Plugin Name:** JPKCom ACF (Pro) Enable Shortcode  
**Plugin URI:** https://github.com/JPKCom/jpkcom-acf-shortcode-enable  
**Description:** Shortcodes can be used within a WYSIWYG to display another field’s value.  
**Version:** 2.0.9  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com  
**Contributors:** JPKCom  
**Tags:** ACF, Shortcode, HTML, Gutenberg  
**Requires Plugins:** advanced-custom-fields-pro  
**Requires at least:** 6.9  
**Tested up to:** 7.1  
**Requires PHP:** 8.3  
**Stable tag:** 2.0.9  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Shortcodes can be used within a WYSIWYG to display another field’s value.


## Description

Shortcodes can be used within a WYSIWYG to display another field’s value.

ACF disables the `[acf]` shortcode by default on any installation whose ACF was first activated on version 6.3 or later. This plugin turns it back on, and keeps it on: the setting is filtered on every read, so a later change from elsewhere cannot quietly switch it off again. On block themes it additionally lifts ACF's separate restriction that otherwise limits the shortcode to post content, so `[acf]` also works in template parts, widgets and blocks outside the content flow.

If your ACF installation predates 6.3, the shortcode is already enabled and this plugin changes nothing.

**Please note:** both of these are hardening defaults that ACF introduced deliberately. Enabling the shortcode is the point of this plugin — but it is worth deciding per site. ACF's output escaping is left untouched.

Verified against **ACF Pro 6.8.6**.

For more details visit: https://www.advancedcustomfields.com/resources/shortcode/

### Requirements for a shortcode to actually output something

Enabling the shortcode is necessary but not sufficient. ACF additionally requires that the field is **registered** (a bare post meta value is not enough — ACF resolves the name through its `_fieldname` reference row), that the field type supports bindings, and that the referenced post is publicly viewable. Previews need the `publish_posts` capability. When one of these is not met the shortcode outputs nothing, silently.


### Documentation

**API Documentation:** Complete PHPDoc-generated API documentation is available at:
[https://jpkcom.github.io/jpkcom-acf-shortcode-enable/docs/](https://jpkcom.github.io/jpkcom-acf-shortcode-enable/docs/)


## Installation

1. In your admin panel, go to 'Plugins' > and click the 'Add New' button.
2. Click Upload Plugin and 'Choose File', then select the Plugin's .zip file. Click 'Install Now'.
3. Make sure 'Advanced Custom Fields' plugin is activated.
4. Click 'Activate' to use the plugin right away.


## Changelog

### 2.0.9
* Fixed: on a block theme the `[acf]` shortcode produced nothing outside `the_content` — a template part, a widget or a block outside the content flow rendered empty, with no error and no log entry. ACF gates that separately from the shortcode setting (`acf_shortcode()`, api-template.php:1025-1030); the plugin now lifts it via `acf/shortcode/allow_in_block_themes_outside_content`. Measured on Twenty Twenty-Five: outside `the_content` empty before, the field value after. Classic themes were never affected — ACF skips the branch entirely
* Changed: the shortcode setting is now also filtered on read (`acf/settings/enable_shortcode`, at `PHP_INT_MAX`) instead of relying solely on the one-shot `acf_update_setting()` on `acf/init`. `acf_get_setting()` applies that filter on every read and `acf_shortcode()` consults it at render time, so any later `acf_update_setting( 'enable_shortcode', false )` — from another plugin, or from ACF itself in a future release — used to win silently. Verified against ACF Pro 6.8.6 with a competing plugin writing false on `acf/init` priority 999: the shortcode stopped rendering before, keeps working now. The `acf_update_setting()` call stays so that readers of the raw setting see the enabled state too
* Added: `tests/test-hooks.php` covers the hook surface and every callback; CI runs it on every pull request and push to `main`
* Docs: documented why the shortcode is off in the first place (ACF only disables it by default for installations first activated on 6.3 or later, `acf.php:239-244`, so this plugin is a no-op on older installs), the block-theme restriction and that lifting it widens the scope beyond post content, and the further conditions ACF places on the shortcode — only registered fields resolved through the `_fieldname` reference meta, only field types supporting bindings, only publicly viewable posts, and an escaped value unless `acf/shortcode/allow_unsafe_html` says otherwise
* Verified against ACF Pro **6.8.6** and WordPress 7.0.2. `Requires Plugins: advanced-custom-fields-pro` resolves against the installed plugin folder even though ACF Pro is not on wordpress.org — only the "install dependency" link is unavailable

### 2.0.8
* Fixed: the update manifest no longer reports `network: true` for this plugin. The generator defaulted a missing `Network:` header to true, while WordPress' own default for a missing header is "not network-only". Metadata only — WordPress derives network-only from the plugin header via `is_network_only_plugin()`, not from the update manifest
* CI: the lint and guard workflow now also runs on pushes to `main`. It only covered pull requests, so a direct push with bypass rights skipped every check
* Changed: comments, workflow step names and CI output across the repository are now English throughout, and the developer notes in `CLAUDE.md` were translated and trimmed. No effect on the shipped plugin

### 2.0.7
* Changed: `Tested up to` raised to WordPress 7.1
* Changed: the bundled updater's runtime floor now matches the plugin's own minimum. It bailed out below WordPress 6.8 while the plugin header has required 6.9 for several releases, so the check could never fire on a supported installation
* CI: the release manifest's fallback values for `requires` and `tested` now say 6.9 and 7.1. They only apply when the README metadata cannot be read, but a stale fallback would have published a minimum the plugin no longer supports

### 2.0.6
* Added: plugin banners (`assets/banner-1544x500.avif`, `assets/banner-772x250.avif`) — a plain `#3c4955` surface with no lettering. The update manifest already advertised these two URLs, but nothing was published under them, so the plugin card in wp-admin had a broken banner

### 2.0.5
* CI: the release step no longer copies the staging directory into itself, so the ZIP has no empty `jpkcom-acf-shortcode-enable/jpkcom-acf-shortcode-enable/` folder
* CI: bumped the pinned GitHub Actions (checkout v7.0.1, setup-python v7.0.0, action-gh-release v3.0.2, fetch-metadata v3.1.0), still pinned to full commit SHAs
* CI: the release ZIP now excludes the development-only `tests/` and `tools/` directories
* CI: security and regression tests now run on every pull request, where a plugin has them

### 2.0.4
* Security: update packages are now verified *before* installation — the verified file is handed to WordPress instead of being downloaded a second time, so the bytes that were checked are the bytes that get installed
* Security: a missing or unfetchable SHA-256 checksum now aborts the update instead of installing unverified code (previously it silently skipped verification)
* Security: pinned every GitHub Action to a full commit SHA and added Dependabot with a 7-day cooldown, so a moved tag can no longer change the release build
* Security: tightened which download the updater claims, so sibling plugins cannot match each other's package
* Fixed: `sprintf()` calls in the updater bound named arguments to a variadic parameter, which raises `ArgumentCountError` on PHP 8.3
* Fixed: the "View Details" modal could fail with a `TypeError` when the manifest omitted `requires_plugins`
* Performance: a failed manifest fetch is now cached for an hour instead of being retried on every admin request
* Added: CI workflow on every pull request (PHP lint, named-argument check, YAML validation, action-pinning guard)

### 2.0.3
* Added secure self-hosted plugin updates via GitHub with SHA256 checksum verification
* Added an automated release workflow (builds the ZIP, generates the manifest and deploys to gh-pages on tag push)
* Raised the minimum WordPress version to 6.9 and "Tested up to" to WordPress 7.0
* Switched license metadata to the SPDX identifier `GPL-2.0-or-later` with the HTTPS license URI
* Added PHPDoc-generated API documentation, built and deployed to gh-pages on release
* Hardening: enabled `declare(strict_types=1)`, prefixed and guarded the ACF init callback (`jpkcom_acf_enable_shortcode`)

### 2.0.2
* Tested up to WP v6.8

### 2.0.1
* Fix Stable tag

### 2.0.0
* Added README.md
* Plugin meta data update

### 1.0.0
* Initial Release
