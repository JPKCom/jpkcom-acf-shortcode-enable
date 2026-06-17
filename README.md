# JPKCom ACF (Pro) Enable Shortcode

**Plugin Name:** JPKCom ACF (Pro) Enable Shortcode  
**Plugin URI:** https://github.com/JPKCom/jpkcom-acf-shortcode-enable  
**Description:** Shortcodes can be used within a WYSIWYG to display another field’s value.  
**Version:** 2.0.3  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com  
**Contributors:** JPKCom  
**Tags:** ACF, Shortcode, HTML, Gutenberg  
**Requires Plugins:** advanced-custom-fields-pro  
**Requires at least:** 6.9  
**Tested up to:** 7.0  
**Requires PHP:** 8.3  
**Stable tag:** 2.0.3  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Shortcodes can be used within a WYSIWYG to display another field’s value.


## Description

Shortcodes can be used within a WYSIWYG to display another field’s value.

For more details visit: https://www.advancedcustomfields.com/resources/shortcode/


### Documentation

**API Documentation:** Complete PHPDoc-generated API documentation is available at:
[https://jpkcom.github.io/jpkcom-acf-shortcode-enable/docs/](https://jpkcom.github.io/jpkcom-acf-shortcode-enable/docs/)


## Installation

1. In your admin panel, go to 'Plugins' > and click the 'Add New' button.
2. Click Upload Plugin and 'Choose File', then select the Plugin's .zip file. Click 'Install Now'.
3. Make sure 'Advanced Custom Fields' plugin is activated.
4. Click 'Activate' to use the plugin right away.


## Changelog

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
