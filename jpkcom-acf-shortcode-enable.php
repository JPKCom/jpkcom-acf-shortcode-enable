<?php
/*
Plugin Name: JPKCom ACF (Pro) Enable Shortcode Plugin
Plugin URI: https://www.jpkc.com/
Description: Shortcodes can be used within a WYGIWYG to display another field’s value.
Version: 1.0.2
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Requires Plugins: advanced-custom-fields-pro
Requires at least: 6.7
Requires PHP: 8.3
License: MIT
License URI: https://opensource.org/license/MIT
GitHub Plugin URI: JPKCom/jpkcom-acf-shortcode-enable
Primary Branch: main
*/

/* https://www.advancedcustomfields.com/resources/shortcode/ */

add_action( 'acf/init', 'set_acf_settings' );
function set_acf_settings(): void {
    acf_update_setting( 'enable_shortcode', true );
}
