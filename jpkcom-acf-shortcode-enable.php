<?php
/*
Plugin Name: JPKCom ACF (Pro) Enable Shortcode
Plugin URI: https://github.com/JPKCom/jpkcom-acf-shortcode-enable
Description: Shortcodes can be used within a WYSIWYG to display another field’s value.
Version: 2.0.3
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: ACF, Shortcode, HTML, Gutenberg
Requires Plugins: advanced-custom-fields-pro
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 2.0.3
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
    define( 'JPKCOM_ACF_SHORTCODE_ENABLE_VERSION', '2.0.3' );
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
     * Turns on ACF's `enable_shortcode` setting so field values can be rendered
     * inside WYSIWYG content via the shortcode.
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
