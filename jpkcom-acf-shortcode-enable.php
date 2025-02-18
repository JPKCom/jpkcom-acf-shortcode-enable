<?php
/*
Plugin Name: JPKCom ACF (Pro) Enable Shortcode Plugin
Description: Shortcodes can be used within a WYGIWYG to display another field’s value.
Version: 1.0.1
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Requires Plugins: advanced-custom-fields-pro
GitHub Plugin URI: JPKCom/jpkcom-acf-shortcode-enable
Primary Branch: main
*/

/* https://www.advancedcustomfields.com/resources/shortcode/ */

add_action( 'acf/init', 'set_acf_settings' );
function set_acf_settings(): void {
    acf_update_setting( 'enable_shortcode', true );
}
