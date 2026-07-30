<?php
/*
Plugin Name: JPKCom ACF (Pro) Enable Shortcode
Plugin URI: https://github.com/JPKCom/jpkcom-acf-shortcode-enable
Description: Shortcodes can be used within a WYSIWYG to display another field’s value.
Version: 2.0.9
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: ACF, Shortcode, HTML, Gutenberg
Requires Plugins: advanced-custom-fields-pro
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 2.0.9
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
	die;
}


/**
 * Plugin Constants
 *
 * @since 2.0.3
 */
if ( ! defined( 'JPKCOM_ACF_SHORTCODE_ENABLE_VERSION' ) ) {
    define( 'JPKCOM_ACF_SHORTCODE_ENABLE_VERSION', '2.0.9' );
}


/**
 * Initialize Plugin Updater
 *
 * Loads and initializes the GitHub-based plugin updater with SHA256 checksum verification.
 *
 * @since 2.0.3
 *
 * @return void
 */
add_action( 'init', static function (): void {
    $updater_file = plugin_dir_path( __FILE__ ) . 'includes/class-plugin-updater.php';

    if ( file_exists( $updater_file ) ) {
        require_once $updater_file;

        if ( class_exists( 'JPKComAcfShortcodeEnableGitUpdate\\JPKComGitPluginUpdater' ) ) {
            new \JPKComAcfShortcodeEnableGitUpdate\JPKComGitPluginUpdater(
                plugin_file: __FILE__,
                current_version: JPKCOM_ACF_SHORTCODE_ENABLE_VERSION,
                manifest_url: 'https://jpkcom.github.io/jpkcom-acf-shortcode-enable/plugin_jpkcom-acf-shortcode-enable.json'
            );
        }
    }
}, 5 );

/* https://www.advancedcustomfields.com/resources/shortcode/ */

if ( ! function_exists( function: 'jpkcom_acf_enable_shortcode' ) ) {

    /**
     * Enable the ACF (Pro) `[acf]` shortcode.
     *
     * Writes ACF's `enable_shortcode` setting so anything reading the raw
     * setting (`acf_raw_setting()`) sees the enabled state too. The read-time
     * filter below is what actually guarantees it — see there for why both
     * exist.
     *
     * @since 1.0.0
     *
     * @return void
     */
    function jpkcom_acf_enable_shortcode(): void {
        acf_update_setting( 'enable_shortcode', true );
    }

}

add_action( 'acf/init', 'jpkcom_acf_enable_shortcode' );

/**
 * Keep the `[acf]` shortcode enabled on every read of the setting.
 *
 * `acf_get_setting()` applies an `acf/settings/{$name}` filter on every read
 * (`includes/api/api-helpers.php:101`), and `acf_shortcode()` consults
 * `acf_get_setting( 'enable_shortcode' )` at render time. Filtering the read is
 * therefore authoritative, while the `acf_update_setting()` call above writes
 * the value once on `acf/init` and any later `acf_update_setting(
 * 'enable_shortcode', false )` — from another plugin, or from ACF itself in a
 * future release — would silently win.
 *
 * Measured against ACF Pro 6.8.6 with a competing plugin writing `false` on
 * `acf/init` priority 999: with only the one-shot write the shortcode stopped
 * rendering; with this filter it kept working.
 *
 * @since 2.0.9
 */
add_filter( 'acf/settings/enable_shortcode', '__return_true', PHP_INT_MAX );

/**
 * Allow the `[acf]` shortcode outside `the_content` on block themes.
 *
 * On a block theme, `acf_shortcode()` bails out unless it is running inside the
 * `the_content` filter:
 *
 *     if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
 *         if ( ! doing_filter( 'the_content' ) && ! apply_filters(
 *             'acf/shortcode/allow_in_block_themes_outside_content', false ) ) {
 *             return;
 *         }
 *     }
 *
 * So a shortcode in a template part, a block outside the content flow or a
 * widget produced nothing at all, with no error and no log entry. Enabling the
 * shortcode without this filter therefore only did half the job on an FSE theme.
 *
 * This deliberately reopens a restriction ACF added on purpose. That is the
 * point of the plugin — the `[acf]` shortcode is off by default on ACF 6.3+ for
 * the same reason — but be aware the scope is wider than the setting alone: it
 * covers every rendering context, not just post content. Classic themes are
 * unaffected either way, because the branch above is skipped entirely.
 *
 * @since 2.0.9
 */
add_filter( 'acf/shortcode/allow_in_block_themes_outside_content', '__return_true', PHP_INT_MAX );
