<?php
/**
 * Regression tests for the hook surface of jpkcom-acf-shortcode-enable.
 *
 * Runs standalone (no WordPress, no ACF): the WordPress functions the main file
 * touches at load time are stubbed, the plugin file is required, and the
 * recorded registrations are then asserted and the callbacks invoked.
 *
 * The ACF side of each hook was verified by hand against ACF Pro 6.8.6; the
 * file references in the comments point at the code that consumes them.
 *
 * Every case below is red against 2.0.8.
 *
 * @package JPKCom_ACF_Shortcode_Enable
 * @since 2.0.9
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
    define( constant_name: 'WPINC', value: true );
}

/** Recorded hook registrations: $GLOBALS['jpkcom_hooks'][type][hook][] = [cb, priority]. */
$GLOBALS['jpkcom_hooks'] = array();

/** Settings written through the stubbed acf_update_setting(). */
$GLOBALS['jpkcom_acf_settings'] = array();

if ( ! function_exists( function: 'add_action' ) ) {
    function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks']['action'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks']['filter'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'plugin_dir_path' ) ) {
    function plugin_dir_path( string $file ): string {
        return dirname( path: $file ) . DIRECTORY_SEPARATOR;
    }
}

if ( ! function_exists( function: 'acf_update_setting' ) ) {
    function acf_update_setting( string $name, mixed $value ): void {
        $GLOBALS['jpkcom_acf_settings'][ $name ] = $value;
    }
}

if ( ! function_exists( function: '__return_true' ) ) {
    function __return_true(): bool {
        return true;
    }
}

require_once dirname( path: __DIR__ ) . '/jpkcom-acf-shortcode-enable.php';

$failed = 0;
$passed = 0;

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @param string $note Extra detail printed on failure.
 * @return void
 */
function jpkcom_check( string $name, bool $ok, string $note = '' ): void {
    global $failed, $passed;

    if ( $ok ) {
        ++$passed;
        printf( "  ok   %s\n", $name );
        return;
    }

    ++$failed;
    printf( "  FAIL %s%s\n", $name, $note !== '' ? ' -- ' . $note : '' );
}

/**
 * Fetch the registered callbacks for a hook.
 *
 * @param string $type action|filter
 * @param string $hook Hook name.
 * @return array<int,array{0:callable,1:int}>
 */
function jpkcom_hooked( string $type, string $hook ): array {
    return $GLOBALS['jpkcom_hooks'][ $type ][ $hook ] ?? array();
}

echo "jpkcom-acf-shortcode-enable: hook regressions\n";

/*
 * 2.0.8 only wrote the setting once on acf/init. acf_get_setting() applies
 * acf/settings/{$name} on every read (includes/api/api-helpers.php:101) and
 * acf_shortcode() consults it at render time (api-template.php:1017), so a later
 * acf_update_setting( 'enable_shortcode', false ) from anywhere would win.
 * Measured against ACF Pro 6.8.6: with a competing plugin writing false on
 * acf/init priority 999 the shortcode stopped rendering.
 */
$setting = jpkcom_hooked( 'filter', 'acf/settings/enable_shortcode' );
jpkcom_check(
    'acf/settings/enable_shortcode is filtered at PHP_INT_MAX',
    $setting !== array() && $setting[0][1] === PHP_INT_MAX,
    $setting === array() ? 'not registered - a later write would disable the shortcode again' : sprintf( 'priority %d', $setting[0][1] )
);

if ( $setting !== array() ) {
    jpkcom_check( 'it reports the shortcode as enabled', $setting[0][0]( false ) === true );
}

/*
 * On a block theme acf_shortcode() returns nothing unless it runs inside
 * the_content, unless this filter says otherwise (api-template.php:1025-1030).
 * Without it, enabling the shortcode only worked for post content on an FSE
 * theme - a template part, a widget or a block outside the content flow
 * produced nothing, with no error.
 */
$block = jpkcom_hooked( 'filter', 'acf/shortcode/allow_in_block_themes_outside_content' );
jpkcom_check(
    'the block theme restriction is lifted at PHP_INT_MAX',
    $block !== array() && $block[0][1] === PHP_INT_MAX,
    $block === array() ? 'not registered - shortcode stays silent outside the_content on FSE themes' : sprintf( 'priority %d', $block[0][1] )
);

if ( $block !== array() ) {
    jpkcom_check( 'it allows the shortcode outside the_content', $block[0][0]( false ) === true );
}

/* The one-shot write stays, so acf_raw_setting() readers see it as well. */
$acf_init = jpkcom_hooked( 'action', 'acf/init' );
jpkcom_check(
    'the setting is still written on acf/init',
    $acf_init !== array(),
    'nothing registered on acf/init'
);

jpkcom_check(
    'the callback is named and reusable',
    function_exists( function: 'jpkcom_acf_enable_shortcode' )
);

if ( $acf_init !== array() ) {
    $acf_init[0][0]();

    jpkcom_check(
        'it writes enable_shortcode = true',
        ( $GLOBALS['jpkcom_acf_settings']['enable_shortcode'] ?? null ) === true,
        'written: ' . var_export( $GLOBALS['jpkcom_acf_settings']['enable_shortcode'] ?? null, true )
    );
    jpkcom_check(
        'it touches nothing else',
        count( $GLOBALS['jpkcom_acf_settings'] ) === 1,
        'wrote: ' . implode( ', ', array_keys( $GLOBALS['jpkcom_acf_settings'] ) )
    );
}

/* The updater must still boot ahead of the default priority. */
$init = jpkcom_hooked( 'action', 'init' );
jpkcom_check(
    'the updater bootstrap is registered on init at priority 5',
    $init !== array() && $init[0][1] === 5,
    $init === array() ? 'not registered' : sprintf( 'priority %d', $init[0][1] )
);

/* Version constant and header must agree. */
$header = array();
preg_match(
    '/^Version:\s*(\S+)/m',
    (string) file_get_contents( dirname( path: __DIR__ ) . '/jpkcom-acf-shortcode-enable.php' ),
    $header
);

jpkcom_check(
    'the version constant matches the plugin header',
    defined( constant_name: 'JPKCOM_ACF_SHORTCODE_ENABLE_VERSION' )
    && constant( 'JPKCOM_ACF_SHORTCODE_ENABLE_VERSION' ) === ( $header[1] ?? '' ),
    sprintf(
        'constant %s vs header %s',
        defined( constant_name: 'JPKCOM_ACF_SHORTCODE_ENABLE_VERSION' ) ? (string) constant( 'JPKCOM_ACF_SHORTCODE_ENABLE_VERSION' ) : 'undefined',
        $header[1] ?? 'not found'
    )
);

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
