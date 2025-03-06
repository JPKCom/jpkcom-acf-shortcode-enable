<?php
/*
Plugin Name: JPKCom ACF (Pro) Enable Shortcode Plugin
Plugin URI: https://github.com/JPKCom/jpkcom-acf-shortcode-enable
Description: Shortcodes can be used within a WYSIWYG to display another field’s value.
Version: 2.0.0
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: ACF, Shortcode, HTML, Gutenberg
Requires Plugins: advanced-custom-fields-pro
Requires at least: 6.7
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: trunk
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: JPKCom/jpkcom-acf-shortcode-enable
Primary Branch: main
*/

if ( ! defined( constant_name: 'WPINC' ) ) {
	die;
}

/* https://www.advancedcustomfields.com/resources/shortcode/ */

add_action( 'acf/init', 'set_acf_settings' );
function set_acf_settings(): void {
    acf_update_setting( 'enable_shortcode', true );
}
