<?php
/**
 * Family behaviour of the shared JPKCom updater (1.5.0+).
 *
 * Standalone: no WordPress. The WordPress functions the updater calls are
 * stubbed below (in-memory site transients, a small priority-aware hook system,
 * header parsing over real files, HTTP and download_url() over fixtures), and a
 * temporary WP_PLUGIN_DIR holds fake JPKCom plugins: canonical ones, renamed
 * folders, a foreign Plugin URI, a git checkout, and an active plugin whose
 * older updater copy is loaded but not registered.
 *
 * The class under test is loaded from UPDATER_FILE (default: this plugin's
 * includes/class-plugin-updater.php). Several copies are loaded at once by
 * rewriting the namespace (and, for the election cases, UPDATER_VERSION), the
 * way the family carries one copy per plugin.
 *
 * Run against the 1.4.0 file this suite must fail with named assertions, not a
 * fatal: every call that 1.4.0 cannot answer (a missing method, a missing
 * constant) is caught per assertion and reported.
 *
 *     php tests/test-updater-family.php
 *     UPDATER_FILE=/path/to/class-plugin-updater.php php tests/test-updater-family.php
 *
 * @package JPKComPostFilter
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Assertion harness (same output shape as the other suites).

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function section( string $title ): void { echo "\n" . $title . "\n"; }

function chk( string $name, bool $cond, string $detail = '' ): void {
	if ( $cond ) {
		$GLOBALS['__pass']++;
		echo "  PASS  {$name}\n";
	} else {
		$GLOBALS['__fail']++;
		echo "  FAIL  {$name}" . ( '' !== $detail ? "  ({$detail})" : '' ) . "\n";
	}
}

function summary(): int {
	printf( "\n  %d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fail'] );
	return $GLOBALS['__fail'] > 0 ? 1 : 0;
}

/**
 * Run a call that the code under test may not support; record the throwable.
 *
 * @param callable    $fn  Call.
 * @param string|null $err Set to the throwable's message, or null.
 * @return mixed
 */
function attempt( callable $fn, ?string &$err = null ): mixed {
	$err = null;
	try {
		return $fn();
	} catch ( \Throwable $e ) {
		$err = get_class( $e ) . ': ' . $e->getMessage();
		return null;
	}
}

/**
 * Short description of a value for failure details.
 *
 * @param mixed $v Value.
 * @return string
 */
function show( mixed $v ): string {
	if ( $v instanceof WP_Error ) {
		return 'WP_Error(' . $v->get_error_code() . ')';
	}
	if ( is_object( $v ) ) {
		return get_class( $v ) . ' ' . substr( (string) json_encode( $v ), 0, 160 );
	}
	return substr( var_export( $v, true ), 0, 160 );
}

// ---------------------------------------------------------------------------
// Environment.

$tmp = sys_get_temp_dir() . '/jpkcom-updater-family-' . getmypid();
@mkdir( $tmp . '/plugins', 0755, true );
@mkdir( $tmp . '/copies', 0755, true );
@mkdir( $tmp . '/dl', 0755, true );
@mkdir( $tmp . '/abspath', 0755, true );

register_shutdown_function( static function () use ( $tmp ): void {
	if ( ! is_dir( $tmp ) ) {
		return;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $tmp );
} );

define( 'ABSPATH', $tmp . '/abspath/' );
define( 'WP_PLUGIN_DIR', $tmp . '/plugins' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['wp_version'] = '7.1.3';

$GLOBALS['__php_errors'] = [];
set_error_handler( static function ( int $no, string $str, string $file, int $line ): bool {
	$GLOBALS['__php_errors'][] = $str . ' at ' . basename( $file ) . ':' . $line;
	return true;
} );

const REGISTRY_KEY = 'jpkcom_git_updater_registry';
const HOST_FAIL    = 'jpk_git_update_host_fail';

// ---------------------------------------------------------------------------
// WordPress stubs.

class WP_Error {
	public string $code;
	public string $message;
	public mixed $data;
	public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

class WP_Upgrader {}
class Plugin_Upgrader extends WP_Upgrader {}

function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function esc_url_raw( mixed $url ): string { return trim( (string) $url ); }
function sanitize_text_field( mixed $s ): string { return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : ''; }
function sanitize_key( mixed $k ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_title( mixed $t ): string { return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ); }
function wp_kses_post( mixed $s ): string { return (string) $s; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function is_multisite(): bool { return false; }
function wp_doing_cron(): bool { return (bool) $GLOBALS['__cron']; }

function current_user_can( string $cap ): bool {
	return ! empty( $GLOBALS['__can'][ $cap ] );
}

function wp_http_validate_url( mixed $url ): string|false {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}
	$p = parse_url( $url );
	if ( ! is_array( $p ) || ! in_array( $p['scheme'] ?? '', [ 'http', 'https' ], true ) || empty( $p['host'] ) || isset( $p['user'] ) ) {
		return false;
	}
	return $url;
}

function wp_normalize_path( string $path ): string {
	$path = str_replace( '\\', '/', $path );
	return (string) preg_replace( '|(?<=.)/+|', '/', $path );
}

function plugin_basename( string $file ): string {
	$file = wp_normalize_path( $file );
	$dir  = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
	return str_starts_with( $file, $dir ) ? substr( $file, strlen( $dir ) ) : ltrim( $file, '/' );
}

// jpkcom-hide-login's copy logs through its plugin's helper.
function jpkcom_hide_login_log( string $message, string $level = 'trace' ): void {}

function wp_get_active_and_valid_plugins(): array {
	return $GLOBALS['__active'];
}

function get_plugin_data( string $file, bool $markup = true, bool $translate = true ): array {
	$data = (string) file_get_contents( $file, false, null, 0, 8192 );
	$out  = [];
	foreach ( [ 'Name' => 'Plugin Name', 'PluginURI' => 'Plugin URI', 'Version' => 'Version' ] as $key => $header ) {
		$out[ $key ] = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $data, $m ) ? trim( $m[1] ) : '';
	}
	return $out;
}

// Hooks with priorities and accepted_args, like WP_Hook.
$GLOBALS['__hooks'] = [];

function add_filter( string $tag, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['__hooks'][ $tag ][ $priority ][] = [ $cb, $accepted_args ];
	return true;
}

function add_action( string $tag, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $tag, $cb, $priority, $accepted_args );
}

function apply_filters( string $tag, mixed $value, mixed ...$args ): mixed {
	if ( empty( $GLOBALS['__hooks'][ $tag ] ) ) {
		return $value;
	}
	$byprio = $GLOBALS['__hooks'][ $tag ];
	ksort( $byprio );
	foreach ( $byprio as $callbacks ) {
		foreach ( $callbacks as [ $cb, $accepted ] ) {
			$value = $cb( ...array_slice( array_merge( [ $value ], $args ), 0, $accepted ) );
		}
	}
	return $value;
}

function do_action( string $tag, mixed ...$args ): void {
	if ( empty( $GLOBALS['__hooks'][ $tag ] ) ) {
		return;
	}
	$byprio = $GLOBALS['__hooks'][ $tag ];
	ksort( $byprio );
	foreach ( $byprio as $callbacks ) {
		foreach ( $callbacks as [ $cb, $accepted ] ) {
			$cb( ...array_slice( $args, 0, $accepted ) );
		}
	}
}

// Site transients (expiry recorded, not enforced).
function get_site_transient( string $key ): mixed {
	return array_key_exists( $key, $GLOBALS['__st'] ) ? $GLOBALS['__st'][ $key ] : false;
}

function set_site_transient( string $key, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['__st'][ $key ]     = $value;
	$GLOBALS['__st_exp'][ $key ] = $expiration;
	return true;
}

function delete_site_transient( string $key ): bool {
	unset( $GLOBALS['__st'][ $key ], $GLOBALS['__st_exp'][ $key ] );
	return true;
}

// HTTP over fixtures: url => [code, body] | WP_Error. Unknown URLs answer 404.
function wp_safe_remote_get( string $url, array $args = [] ): array|WP_Error {
	$GLOBALS['__http_log'][] = $url;
	$fx = $GLOBALS['__http'][ $url ] ?? [ 404, 'Not Found' ];
	if ( $fx instanceof WP_Error ) {
		return $fx;
	}
	return [ 'response' => [ 'code' => $fx[0] ], 'body' => $fx[1] ];
}

function wp_remote_retrieve_response_code( mixed $r ): int|string {
	return is_array( $r ) ? $r['response']['code'] : '';
}

function wp_remote_retrieve_body( mixed $r ): string {
	return is_array( $r ) ? (string) $r['body'] : '';
}

// download_url over fixtures: url => bytes.
function download_url( string $url, int $timeout = 300, bool $signature_verification = false ): string|WP_Error {
	$GLOBALS['__dl_log'][] = $url;
	if ( ! isset( $GLOBALS['__downloads'][ $url ] ) ) {
		return new WP_Error( 'http_404', 'Not Found' );
	}
	$path = (string) tempnam( $GLOBALS['__tmp'] . '/dl', 'pkg' );
	file_put_contents( $path, $GLOBALS['__downloads'][ $url ] );
	$GLOBALS['__dl_files'][] = $path;
	return $path;
}

function wp_delete_file( string $file ): void {
	@unlink( $file );
}

$GLOBALS['__tmp'] = $tmp;

// ---------------------------------------------------------------------------
// Fixtures: plugins, manifests, packages.

function manifest_url( string $slug ): string {
	return sprintf( 'https://jpkcom.github.io/%1$s/plugin_%1$s.json', $slug );
}

function pkg( string $slug, string $tag ): string {
	return sprintf( 'https://github.com/JPKCom/%1$s/releases/download/%2$s/%1$s.zip', $slug, $tag );
}

function zip_bytes( string $slug, string $version ): string {
	return "PK\x03\x04 fake zip of {$slug} {$version}";
}

function make_plugin( string $file, string $uri, string $version, bool $git = false ): void {
	$dir = WP_PLUGIN_DIR . '/' . dirname( $file );
	@mkdir( $dir, 0755, true );
	file_put_contents(
		WP_PLUGIN_DIR . '/' . $file,
		"<?php\n/**\n * Plugin Name: " . basename( $file, '.php' ) . "\n * Plugin URI: {$uri}\n * Version: {$version}\n */\n"
	);
	if ( $git ) {
		@mkdir( $dir . '/.git', 0755, true );
	}
}

function remove_plugin( string $file ): void {
	@unlink( WP_PLUGIN_DIR . '/' . $file );
	@rmdir( WP_PLUGIN_DIR . '/' . dirname( $file ) );
}

const F_SELF    = 'jpkcom-post-filter/jpkcom-post-filter.php';
const F_SECOND  = 'jpkcom-second/jpkcom-second.php';
const F_ALPHA   = 'jpkcom-alpha/jpkcom-alpha.php';
const F_BETA    = 'jpkcom-beta/jpkcom-beta.php';
const F_VCS     = 'jpkcom-vcs/jpkcom-vcs.php';
const F_GAMMA   = 'jpkcom-gamma-main/jpkcom-gamma.php';
const F_DELTA   = 'jpkcom-delta-copy/jpkcom-delta-copy.php';
const F_EPSILON = 'jpkcom-epsilon-dev/jpkcom-epsilon.php';
const F_FOREIGN = 'jpkcom-foreign/jpkcom-foreign.php';
const F_SUFFIX  = 'jpkcom-uri-suffix/jpkcom-uri-suffix.php';
const F_OLD     = 'jpkcom-old/jpkcom-old.php';
const F_AKISMET = 'akismet/akismet.php';

make_plugin( F_SELF, 'https://github.com/JPKCom/jpkcom-post-filter', '1.4.6' );
make_plugin( F_SECOND, 'https://github.com/JPKCom/jpkcom-second/', '3.0.0' );
make_plugin( F_ALPHA, 'https://github.com/JPKCom/jpkcom-alpha', '1.0.0' );
make_plugin( F_BETA, 'https://github.com/JPKCom/jpkcom-beta', '2.0.0' );
make_plugin( F_VCS, 'https://github.com/JPKCom/jpkcom-vcs', '1.0.0', true );
make_plugin( F_GAMMA, 'https://github.com/JPKCom/jpkcom-gamma', '1.0.0' );
make_plugin( F_DELTA, 'https://github.com/JPKCom/jpkcom-delta', '1.0.0' );
make_plugin( F_EPSILON, 'https://github.com/JPKCom/jpkcom-epsilon', '1.0.0' );
make_plugin( F_FOREIGN, 'https://github.com/SomeoneElse/jpkcom-foreign', '1.0.0' );
make_plugin( F_SUFFIX, 'https://github.com/JPKCom/jpkcom-uri-suffix/tree/main', '1.0.0' );
make_plugin( F_OLD, 'https://github.com/JPKCom/jpkcom-old', '1.0.0' );
// "Loaded" means PHP included the main file, exactly as WordPress does for an active plugin.
require WP_PLUGIN_DIR . '/' . F_OLD;
make_plugin( F_AKISMET, 'https://akismet.com/', '5.3' );

/** Manifest version offered per manifest slug. */
$GLOBALS['__offer'] = [
	'jpkcom-post-filter' => '1.4.6',
	'jpkcom-second'      => '3.0.0',
	'jpkcom-alpha'       => '1.1.0',
	'jpkcom-beta'        => '2.0.0',
	'jpkcom-vcs'         => '9.0.0',
	'jpkcom-gamma'       => '9.0.0',
	'jpkcom-delta'       => '9.0.0',
	'jpkcom-epsilon'     => '1.0.0',
	'jpkcom-foreign'     => '9.0.0',
	'jpkcom-uri-suffix'  => '9.0.0',
	'jpkcom-old'         => '2.0.0',
	'jpkcom-newbie'      => '0.5.0',
	'jpkcom-b1'          => '1.0.0',
	'jpkcom-b2'          => '1.0.0',
	'jpkcom-b3'          => '1.0.0',
	'jpkcom-b4'          => '1.0.0',
];

function manifest_array( string $slug ): array {
	$v = $GLOBALS['__offer'][ $slug ];
	return [
		'name'            => 'Name of ' . $slug,
		'slug'            => 'not-the-installed-slug',
		'version'         => $v,
		'download_url'    => pkg( $slug, 'v' . $v ),
		'checksum_sha256' => hash( 'sha256', zip_bytes( $slug, $v ) ),
		'tested'          => '7.1',
		'requires'        => '6.9',
		'requires_php'    => '8.3',
		'icons'           => [ '1x' => "https://jpkcom.github.io/{$slug}/icon-128x128.png", '2x' => "https://jpkcom.github.io/{$slug}/icon-256x256.png" ],
		'autoupdate'      => true,
		'translations'    => [ [ 'language' => 'de_DE', 'package' => 'https://evil.example/de.zip' ] ],
		'sections'        => [ 'description' => 'Description of ' . $slug ],
	];
}

function manifest_object( string $slug, array $override = [] ): object {
	return json_decode( (string) json_encode( array_merge( manifest_array( $slug ), $override ) ) );
}

function cache_key( string $slug ): string {
	return 'jpk_git_update_' . md5( $slug );
}

/** Cache slug => manifest slug for the seeded caches (a renamed folder caches under its folder name). */
function seed_caches( array $only = [] ): void {
	$map = [
		'jpkcom-post-filter' => 'jpkcom-post-filter',
		'jpkcom-second'      => 'jpkcom-second',
		'jpkcom-alpha'       => 'jpkcom-alpha',
		'jpkcom-beta'        => 'jpkcom-beta',
		'jpkcom-epsilon-dev' => 'jpkcom-epsilon',
		'jpkcom-old'         => 'jpkcom-old',
		'jpkcom-gamma'       => 'jpkcom-gamma',
		'jpkcom-delta'       => 'jpkcom-delta',
		'jpkcom-delta-copy'  => 'jpkcom-delta',
		'jpkcom-gamma-main'  => 'jpkcom-gamma',
		'jpkcom-foreign'     => 'jpkcom-foreign',
		'jpkcom-uri-suffix'  => 'jpkcom-uri-suffix',
	];
	foreach ( $map as $cache_slug => $manifest_slug ) {
		if ( [] === $only || in_array( $cache_slug, $only, true ) ) {
			$GLOBALS['__st'][ cache_key( $cache_slug ) ] = manifest_object( $manifest_slug );
		}
	}
}

function reset_world(): void {
	$GLOBALS['__hooks']     = [];
	$GLOBALS['__st']        = [];
	$GLOBALS['__st_exp']    = [];
	$GLOBALS['__http_log']  = [];
	$GLOBALS['__dl_log']    = [];
	$GLOBALS['__dl_files']  = [];
	$GLOBALS['__can']       = [ 'update_plugins' => true ];
	$GLOBALS['__cron']      = false;
	$GLOBALS['__active']    = [
		WP_PLUGIN_DIR . '/' . F_SELF,
		WP_PLUGIN_DIR . '/' . F_SECOND,
		WP_PLUGIN_DIR . '/' . F_OLD,
		WP_PLUGIN_DIR . '/' . F_AKISMET,
	];
	unset( $GLOBALS[ REGISTRY_KEY ] );

	$GLOBALS['__http']      = [];
	$GLOBALS['__downloads'] = [];
	foreach ( array_keys( $GLOBALS['__offer'] ) as $slug ) {
		$GLOBALS['__http'][ manifest_url( $slug ) ] = [ 200, (string) json_encode( manifest_array( $slug ) ) ];
		$v = $GLOBALS['__offer'][ $slug ];
		$GLOBALS['__downloads'][ pkg( $slug, 'v' . $v ) ] = zip_bytes( $slug, $v );
	}
}

// ---------------------------------------------------------------------------
// Load the class under test as several namespaced copies.

$updater_file = getenv( 'UPDATER_FILE' ) ?: __DIR__ . '/../includes/class-plugin-updater.php';
$src          = (string) @file_get_contents( $updater_file );

echo "Updater under test: {$updater_file}\n";

if ( '' === $src || 1 !== preg_match( '~^namespace\s+([A-Za-z0-9_\\\\]+)\s*;~m', $src, $nsm ) ) {
	chk( 'updater source is readable and declares a namespace', false, $updater_file );
	exit( summary() );
}

/**
 * Write and load a copy of the updater in another namespace.
 *
 * @param string      $ns      Target namespace.
 * @param string|null $version UPDATER_VERSION to set, or null to keep it.
 * @return bool Whether the version was rewritten (false when the source has no such constant).
 */
function load_copy( string $src, string $from_ns, string $ns, ?string $version ): bool {
	$code     = (string) preg_replace( '~^namespace\s+' . preg_quote( $from_ns, '~' ) . '\s*;~m', 'namespace ' . $ns . ';', $src, 1 );
	$replaced = 0;
	if ( null !== $version ) {
		$code = (string) preg_replace( "~(const\s+UPDATER_VERSION\s*=\s*)'[^']*'~", '${1}\'' . $version . '\'', $code, 1, $replaced );
	}
	$path = $GLOBALS['__tmp'] . '/copies/' . $ns . '.php';
	file_put_contents( $path, $code );
	require_once $path;
	return $replaced > 0;
}

load_copy( $src, $nsm[1], 'FamA', null );           // The file as shipped.
load_copy( $src, $nsm[1], 'FamTie', null );         // Same UPDATER_VERSION, another plugin.
$hi_versioned = load_copy( $src, $nsm[1], 'FamHi', '1.10.0' ); // Higher (and not higher as a string).

const CLS_A   = 'FamA\\JPKComGitPluginUpdater';
const CLS_TIE = 'FamTie\\JPKComGitPluginUpdater';
const CLS_HI  = 'FamHi\\JPKComGitPluginUpdater';

$shipped_version = attempt( static fn() => ( new ReflectionClassConstant( CLS_A, 'UPDATER_VERSION' ) )->getValue() );
echo 'UPDATER_VERSION: ' . ( is_string( $shipped_version ) ? $shipped_version : '(none)' ) . "\n";

/**
 * Construct a copy for one installed plugin.
 *
 * @param string      $cls           Class.
 * @param string      $file          Plugin file relative to WP_PLUGIN_DIR.
 * @param string      $version       Installed version.
 * @param string|null $manifest_slug Manifest slug (default: folder name).
 * @return object
 */
function copy_for( string $cls, string $file, string $version, ?string $manifest_slug = null ): object {
	return new $cls( WP_PLUGIN_DIR . '/' . $file, $version, manifest_url( $manifest_slug ?? dirname( $file ) ) );
}

function read_transient( mixed $value ): mixed {
	return apply_filters( 'site_transient_update_plugins', $value, 'update_plugins' );
}

/** A transient as core stores it after a wordpress.org check. */
function stored_transient(): object {
	return (object) [
		'last_checked' => 1700000000,
		'checked'      => [ F_SELF => '1.4.6', F_SECOND => '3.0.0', F_ALPHA => '1.0.0', F_OLD => '1.0.0' ],
		'response'     => [],
		'no_update'    => [],
		'translations' => [],
	];
}

function entry_of( mixed $t, string $list, string $file ): ?object {
	if ( ! is_object( $t ) || ! isset( $t->$list ) || ! is_array( $t->$list ) ) {
		return null;
	}
	$e = $t->$list[ $file ] ?? null;
	return is_object( $e ) ? $e : null;
}

/** Whether a direct check_update() call changed anything in the transient. */
function acted( mixed $before_json, mixed $after ): bool {
	return json_encode( $after ) !== $before_json;
}

$upgrader = new Plugin_Upgrader();

/**
 * Run the upgrader_pre_download chain the way WP_Upgrader::download_package() does.
 */
function pre_download( mixed $reply, string $package, array $hook_extra ): mixed {
	return apply_filters( 'upgrader_pre_download', $reply, $package, $GLOBALS['upgrader'], $hook_extra );
}

function update_extra( string $file ): array {
	return [ 'plugin' => $file, 'type' => 'plugin', 'action' => 'update' ];
}

// ===========================================================================
section( 'Source constraints shared with the rest of the family' );

chk( 'check_update is hooked with the exact line harden-trim pins', str_contains( $src, "add_filter( 'site_transient_update_plugins', [\$this, 'check_update'] );" ) );
chk( 'no dependency on the Update URI header (no UpdateURI, no update_plugins_)', ! str_contains( $src, 'UpdateURI' ) && ! str_contains( $src, 'update_plugins_' ) );

preg_match_all( "~__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'[a-z0-9-]+'\s*\)~", $src, $gm );
$msgids   = array_values( array_unique( $gm[1] ) );
$expected = [
	'Security verification failed: the update manifest could not be loaded, so the package cannot be verified.',
	'Security verification failed: the update manifest contains no SHA-256 checksum, so the package cannot be verified.',
	'Download failed: %s',
	'Security verification failed: Plugin checksum mismatch. Expected: %1$s, Got: %2$s',
];
sort( $msgids );
sort( $expected );
chk( 'exactly the four existing msgids, byte-identical', $msgids === $expected, json_encode( $msgids ) );
chk( 'every __( call is single-line and readable by the catalogue guard', substr_count( $src, '__(' ) === count( $gm[0] ), substr_count( $src, '__(' ) . ' calls, ' . count( $gm[0] ) . ' readable' );

$pattern = attempt( static fn() => ( new ReflectionClassConstant( CLS_A, 'PACKAGE_PATTERN' ) )->getValue(), $err );
chk( 'PACKAGE_PATTERN exists', is_string( $pattern ), (string) $err );
if ( is_string( $pattern ) ) {
	$real = 'https://github.com/JPKCom/jpkcom-post-filter/releases/download/v1.4.6/jpkcom-post-filter.zip';
	$r    = @preg_match( $pattern, $real, $pm );
	chk( 'PACKAGE_PATTERN compiles', false !== $r && PREG_NO_ERROR === preg_last_error(), preg_last_error_msg() );
	chk( 'PACKAGE_PATTERN matches the real release asset URL and captures the slug', 1 === $r && 'jpkcom-post-filter' === ( $pm[1] ?? '' ) );
	chk( 'PACKAGE_PATTERN rejects a zip named after another slug', 0 === preg_match( $pattern, 'https://github.com/JPKCom/jpkcom-alpha/releases/download/v1/jpkcom-beta.zip' ) );
	chk( 'PACKAGE_PATTERN rejects another owner', 0 === preg_match( $pattern, 'https://github.com/evil/jpkcom-alpha/releases/download/v1/jpkcom-alpha.zip' ) );
	chk( 'PACKAGE_PATTERN rejects a query or fragment', 0 === preg_match( $pattern, $real . '?x=1' ) && 0 === preg_match( $pattern, $real . '#x' ) );
	chk( 'PACKAGE_PATTERN rejects another host', 0 === preg_match( $pattern, 'https://github.com.evil.example/JPKCom/jpkcom-alpha/releases/download/v1/jpkcom-alpha.zip' ) );
}
$uri_pattern = attempt( static fn() => ( new ReflectionClassConstant( CLS_A, 'PLUGIN_URI_PATTERN' ) )->getValue(), $err );
chk( 'PLUGIN_URI_PATTERN exists and compiles', is_string( $uri_pattern ) && false !== @preg_match( $uri_pattern, '' ), (string) $err );

chk( 'UPDATER_VERSION can be raised in a copy (election fixtures are real)', $hi_versioned );

// ===========================================================================
section( 'Leader election' );

/**
 * Build two registered copies and report which of them acts on a direct read.
 *
 * @return array{0: bool, 1: bool} [first acted, second acted]
 */
function who_acts( object $x, object $y ): array {
	$base = stored_transient();
	$json = json_encode( $base );
	$rx   = attempt( static fn() => $x->check_update( clone $base ) );
	$ry   = attempt( static fn() => $y->check_update( clone $base ) );
	return [ acted( $json, $rx ), acted( $json, $ry ) ];
}

// Higher version registered second.
reset_world();
seed_caches();
$a  = copy_for( CLS_A, F_SELF, '1.4.6' );
$hi = copy_for( CLS_HI, F_SECOND, '3.0.0' );
[ $a_acts, $hi_acts ] = who_acts( $a, $hi );
chk( 'higher UPDATER_VERSION (1.10.0 > 1.5.0) wins when registered second', $hi_acts && ! $a_acts, "A acts: " . var_export( $a_acts, true ) . ', Hi acts: ' . var_export( $hi_acts, true ) );

$r = attempt( static fn() => $hi->check_update( stored_transient() ) );
chk( 'the leader serves its own plugin', null !== entry_of( $r, 'no_update', F_SECOND ) );
chk( 'the leader serves the non-leader copy\'s plugin', null !== entry_of( $r, 'no_update', F_SELF ) );
chk( 'the leader serves an inactive member', null !== entry_of( $r, 'response', F_ALPHA ) );

// Non-leader does nothing in any duty.
$alpha_obj = (object) [ 'slug' => 'jpkcom-alpha' ];
$res       = attempt( static fn() => $a->plugin_info( false, 'plugin_information', $alpha_obj ), $err );
chk( 'non-leader: plugin_info leaves a family slug to the leader', false === $res, $err ?? show( $res ) );
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => $a->verify_download_checksum( false, '', $GLOBALS['upgrader'], update_extra( F_ALPHA ) ), $err );
chk( 'non-leader: the checksum gate returns $reply and downloads nothing', false === $res && [] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
$in  = (object) [ 'response' => [ F_ALPHA => (object) [ 'package' => pkg( 'jpkcom-alpha', 'v1.1.0' ) ] ] ];
$res = attempt( static fn() => $a->strip_packages( $in ), $err );
chk( 'non-leader: strip_packages returns its input untouched', $res === $in && pkg( 'jpkcom-alpha', 'v1.1.0' ) === $in->response[ F_ALPHA ]->package, $err ?? show( $res ) );
attempt( static fn() => $a->clear_cache( $GLOBALS['upgrader'], [ 'action' => 'update', 'type' => 'plugin', 'plugin' => F_ALPHA ] ), $err );
chk( 'non-leader: clear_cache deletes nothing', false !== get_site_transient( cache_key( 'jpkcom-alpha' ) ), (string) $err );

// Through the hook chain only one copy builds entries.
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'hook chain: alpha is offered (by the leader)', null !== entry_of( $r, 'response', F_ALPHA ), $err ?? '' );

// Higher version registered first.
reset_world();
seed_caches();
$hi = copy_for( CLS_HI, F_SECOND, '3.0.0' );
$a  = copy_for( CLS_A, F_SELF, '1.4.6' );
[ $hi_acts, $a_acts ] = who_acts( $hi, $a );
chk( 'higher UPDATER_VERSION wins when registered first', $hi_acts && ! $a_acts, "Hi acts: " . var_export( $hi_acts, true ) . ', A acts: ' . var_export( $a_acts, true ) );

// Tie: first registered wins, both orders.
reset_world();
seed_caches();
$a   = copy_for( CLS_A, F_SELF, '1.4.6' );
$tie = copy_for( CLS_TIE, F_SECOND, '3.0.0' );
[ $a_acts, $tie_acts ] = who_acts( $a, $tie );
chk( 'tie: the first registered copy leads (A first)', $a_acts && ! $tie_acts, "A acts: " . var_export( $a_acts, true ) . ', Tie acts: ' . var_export( $tie_acts, true ) );

reset_world();
seed_caches();
$tie = copy_for( CLS_TIE, F_SECOND, '3.0.0' );
$a   = copy_for( CLS_A, F_SELF, '1.4.6' );
[ $tie_acts, $a_acts ] = who_acts( $tie, $a );
chk( 'tie: the first registered copy leads (Tie first)', $tie_acts && ! $a_acts, "Tie acts: " . var_export( $tie_acts, true ) . ', A acts: ' . var_export( $a_acts, true ) );

// Election is per call: a later, higher copy takes over.
reset_world();
seed_caches();
$a      = copy_for( CLS_A, F_SELF, '1.4.6' );
$json   = json_encode( stored_transient() );
$before = acted( $json, attempt( static fn() => $a->check_update( stored_transient() ) ) );
$hi     = copy_for( CLS_HI, F_SECOND, '3.0.0' );
$after  = acted( $json, attempt( static fn() => $a->check_update( stored_transient() ) ) );
chk( 'election is not memoised: A leads alone, stops once a higher copy registers', $before && ! $after, 'before: ' . var_export( $before, true ) . ', after: ' . var_export( $after, true ) );

// Malformed registry entries are ignored.
reset_world();
seed_caches();
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS[ REGISTRY_KEY ][] = 'not an array';
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => '99.0.0' ];
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => '99.0.0', 'id' => '1', 'file' => 'x/x.php', 'slug' => 'x', 'manifest' => 'https://e.example/', 'version' => '1' ];
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => '99', 'id' => 424242, 'file' => 'x/x.php', 'slug' => 'x', 'manifest' => 'https://e.example/', 'version' => '1' ];
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => '99.0.0', 'id' => 424242, 'file' => 7, 'slug' => 'x', 'manifest' => 'https://e.example/', 'version' => '1' ];
$GLOBALS[ REGISTRY_KEY ][] = (object) [ 'v' => '99.0.0', 'id' => 424242, 'file' => 'x/x.php', 'slug' => 'x', 'manifest' => 'https://e.example/', 'version' => '1' ];
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => [ '99.0.0' ], 'id' => 424242, 'file' => 'x/x.php', 'slug' => 'x', 'manifest' => 'https://e.example/', 'version' => '1' ];
$errs_before = count( $GLOBALS['__php_errors'] );
$r           = attempt( static fn() => $a->check_update( stored_transient() ), $err );
chk( 'malformed registry entries are skipped: A still leads and serves alpha', null !== entry_of( $r, 'response', F_ALPHA ), $err ?? show( $r ) );
chk( 'malformed registry entries raise no PHP warning', count( $GLOBALS['__php_errors'] ) === $errs_before, implode( '; ', array_slice( $GLOBALS['__php_errors'], $errs_before ) ) );

// ===========================================================================
section( 'Discovery and update entries' );

reset_world();
seed_caches();
$GLOBALS['__active'][] = WP_PLUGIN_DIR . '/' . F_EPSILON;
$a   = copy_for( CLS_A, F_SELF, '1.4.6' );
$tie = copy_for( CLS_TIE, F_EPSILON, '1.0.0', 'jpkcom-epsilon' ); // Renamed folder, registered, not the leader.
// The old copy's own entry, as a 1.4 copy injects it.
$old_entry = (object) [ 'slug' => 'jpkcom-old', 'plugin' => F_OLD, 'new_version' => '2.0.0', 'package' => pkg( 'jpkcom-old', 'v2.0.0' ) ];
$in = (object) [
	'last_checked' => 1700000000,
	'response'     => [
		F_OLD => $old_entry,
		F_VCS => (object) [ 'slug' => 'jpkcom-vcs', 'plugin' => F_VCS, 'new_version' => '9.0.0', 'package' => pkg( 'jpkcom-vcs', 'v9.0.0' ) ],
		F_BETA => (object) [ 'slug' => 'jpkcom-beta', 'plugin' => F_BETA, 'new_version' => '1.9.0', 'package' => '' ],
	],
	'no_update'    => [],
];
// No `checked` at all: the family must not depend on a wordpress.org check.
$r = attempt( static fn() => read_transient( $in ), $err );
chk( 'read filter runs without error', null === $err, (string) $err );

$e = entry_of( $r, 'response', F_ALPHA );
chk( 'inactive canonical member (alpha) is discovered and offered', null !== $e, show( $r ) );
chk( 'no `checked` needed: entries are built without it', null !== $e );
chk( 'the leader does not write `checked`', is_object( $r ) && ! property_exists( $r, 'checked' ) );
if ( null !== $e ) {
	chk( 'entry slug is the folder name', 'jpkcom-alpha' === ( $e->slug ?? null ), (string) ( $e->slug ?? '' ) );
	chk( 'entry plugin is the plugin file', F_ALPHA === ( $e->plugin ?? null ) );
	chk( 'entry new_version from the manifest', '1.1.0' === ( $e->new_version ?? null ) );
	chk( 'entry package is the validated release asset', pkg( 'jpkcom-alpha', 'v1.1.0' ) === ( $e->package ?? null ), (string) ( $e->package ?? '' ) );
	chk( 'entry icons is an ARRAY (core indexes it with [])', is_array( $e->icons ?? null ) && isset( $e->icons['2x'], $e->icons['1x'] ), show( $e->icons ?? null ) );
	chk( 'entry carries no autoupdate', ! property_exists( $e, 'autoupdate' ) );
	chk( 'entry carries no translations', ! property_exists( $e, 'translations' ) );
	chk( 'entry carries requires/tested/requires_php', '6.9' === ( $e->requires ?? null ) && '7.1' === ( $e->tested ?? null ) && '8.3' === ( $e->requires_php ?? null ) );
}
chk( 'an offered member is not also in no_update', null === entry_of( $r, 'no_update', F_ALPHA ) );

$e = entry_of( $r, 'no_update', F_BETA );
chk( 'current member (beta) goes to no_update', null !== $e );
chk( 'no_update entry has an empty package and array icons', null !== $e && '' === ( $e->package ?? null ) && is_array( $e->icons ?? null ) );
chk( 'a stale response row for a current member is removed', null === entry_of( $r, 'response', F_BETA ) );
chk( 'the leader\'s own plugin is served too', null !== entry_of( $r, 'no_update', F_SELF ) );

$e = entry_of( $r, 'response', F_VCS );
chk( 'VCS checkout is told about the release, without a package', null !== $e && '9.0.0' === ( $e->new_version ?? null ) && '' === ( $e->package ?? null ) );
chk( 'VCS checkout: no no_update row at the same time', null === entry_of( $r, 'no_update', F_VCS ) );

foreach ( [ F_GAMMA => 'renamed folder (file named after the slug)', F_DELTA => 'renamed folder (file named after the folder)', F_FOREIGN => 'foreign Plugin URI', F_SUFFIX => 'Plugin URI with a path suffix', F_AKISMET => 'non-JPKCom plugin' ] as $file => $what ) {
	chk( "ignored: {$what}", null === entry_of( $r, 'response', $file ) && null === entry_of( $r, 'no_update', $file ) );
}

$e = entry_of( $r, 'no_update', F_EPSILON );
chk( 'renamed folder that IS registered is served under its real file', null !== $e, show( $r->no_update ?? null ) );
chk( 'renamed registered member keeps its folder as slug', null !== $e && 'jpkcom-epsilon-dev' === ( $e->slug ?? null ) );

chk( 'loaded-but-unregistered plugin (old copy): its row is the very object the old copy put there', ( $r->response[ F_OLD ] ?? null ) === $old_entry );
chk( 'loaded-but-unregistered plugin: package untouched', pkg( 'jpkcom-old', 'v2.0.0' ) === $old_entry->package );
chk( 'loaded-but-unregistered plugin: no no_update row added', null === entry_of( $r, 'no_update', F_OLD ) );

$r = attempt( static fn() => read_transient( false ), $err );
chk( 'a missing transient (false) still gets the family entries', null !== entry_of( $r, 'response', F_ALPHA ), $err ?? show( $r ) );

$errs_before = count( $GLOBALS['__php_errors'] );
$r           = attempt( static fn() => read_transient( (object) [ 'response' => 'garbage', 'no_update' => 7 ] ), $err );
chk( 'non-array response/no_update are replaced, not fatal', null !== entry_of( $r, 'response', F_ALPHA ) && count( $GLOBALS['__php_errors'] ) === $errs_before, $err ?? show( $r ) );

// Manifest download_url that is not this plugin's release asset: no offer.
reset_world();
seed_caches();
$GLOBALS['__st'][ cache_key( 'jpkcom-alpha' ) ] = manifest_object( 'jpkcom-alpha', [ 'download_url' => 'https://evil.example/jpkcom-alpha.zip' ] );
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'manifest pointing outside the release assets: alpha is not offered', is_object( $r ) && null === entry_of( $r, 'response', F_ALPHA ), $err ?? show( $r->response ?? null ) );
chk( '... while the rest of the family is still served in the same read', null !== entry_of( $r, 'no_update', F_BETA ) );
$GLOBALS['__st'][ cache_key( 'jpkcom-alpha' ) ] = manifest_object( 'jpkcom-alpha', [ 'download_url' => pkg( 'jpkcom-beta', 'v1.1.0' ) ] );
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'manifest pointing at a sibling\'s release asset: alpha is not offered', is_object( $r ) && null === entry_of( $r, 'response', F_ALPHA ), $err ?? show( $r->response ?? null ) );

// Late registration after a first read (a read at init priority 5 before every copy registered).
reset_world();
seed_caches();
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
attempt( static fn() => read_transient( stored_transient() ) );
$tie = copy_for( CLS_TIE, F_SECOND, '3.0.0' ); // Registers after the first read; A stays leader (tie).
$r   = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'a copy registered after the first read is served on the next read', null !== entry_of( $r, 'no_update', F_SECOND ), $err ?? 'jpkcom-second missing: the leader kept a member list computed before it registered' );

// ===========================================================================
section( 'strip_packages before the transient is stored' );

reset_world();
seed_caches();
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
// A third party writes a JPKCom package late; the strip must still run after it.
add_filter( 'pre_set_site_transient_update_plugins', static function ( $v ) {
	if ( is_object( $v ) && isset( $v->response ) && is_array( $v->response ) ) {
		$v = clone $v;
		$v->response['jpkcom-late/jpkcom-late.php'] = (object) [ 'package' => pkg( 'jpkcom-late', 'v1.0.0' ) ];
	}
	return $v;
}, 1000 );
$alpha_in  = (object) [ 'slug' => 'jpkcom-alpha', 'plugin' => F_ALPHA, 'new_version' => '1.1.0', 'package' => pkg( 'jpkcom-alpha', 'v1.1.0' ) ];
$squat_in  = (object) [ 'slug' => 'jpkcom-beta', 'plugin' => F_BETA, 'new_version' => '9.9', 'package' => 'https://downloads.wordpress.org/plugin/jpkcom-beta.9.9.zip' ];
$stray_in  = (object) [ 'slug' => 'jpkcom-zzz', 'plugin' => 'jpkcom-zzz/x.php', 'new_version' => '1.0', 'package' => pkg( 'jpkcom-zzz', 'v1.0' ) ];
$foreign_in = (object) [ 'slug' => 'akismet', 'plugin' => F_AKISMET, 'new_version' => '5.4', 'package' => 'https://downloads.wordpress.org/plugin/akismet.5.4.zip' ];
$input = (object) [
	'last_checked' => 1700000000,
	'checked'      => [],
	'response'     => [ F_ALPHA => $alpha_in, F_BETA => $squat_in, 'jpkcom-zzz/x.php' => $stray_in, F_AKISMET => $foreign_in ],
	'no_update'    => [],
];
$out = attempt( static fn() => apply_filters( 'pre_set_site_transient_update_plugins', $input, 'update_plugins' ), $err );
chk( 'family member package blanked', '' === ( entry_of( $out, 'response', F_ALPHA )->package ?? null ), $err ?? show( $out ) );
chk( 'member package blanked even when it is a wordpress.org URL (squatted slug)', '' === ( entry_of( $out, 'response', F_BETA )->package ?? null ) );
chk( 'JPKCom release URL on a non-member entry blanked', '' === ( entry_of( $out, 'response', 'jpkcom-zzz/x.php' )->package ?? null ) );
chk( 'JPKCom package added by a later-priority filter is still blanked (runs at PHP_INT_MAX)', '' === ( entry_of( $out, 'response', 'jpkcom-late/jpkcom-late.php' )->package ?? null ) );
chk( 'non-family package untouched', 'https://downloads.wordpress.org/plugin/akismet.5.4.zip' === ( entry_of( $out, 'response', F_AKISMET )->package ?? null ) );
chk( 'other entry fields survive', '1.1.0' === ( entry_of( $out, 'response', F_ALPHA )->new_version ?? null ) );
chk( 'input object not mutated: entry package still set', pkg( 'jpkcom-alpha', 'v1.1.0' ) === $alpha_in->package && 'https://downloads.wordpress.org/plugin/jpkcom-beta.9.9.zip' === $squat_in->package );
chk( 'input object not mutated: same entries in the input response', $input->response[ F_ALPHA ] === $alpha_in && ! isset( $input->response['jpkcom-late/jpkcom-late.php'] ) );
chk( 'a different object is returned', is_object( $out ) && $out !== $input );
$res = attempt( static fn() => $a->strip_packages( false ), $err );
chk( 'non-object value passes through', false === $res, $err ?? show( $res ) );

// ===========================================================================
section( 'Checksum gate (upgrader_pre_download)' );

reset_world();
seed_caches();
$a = copy_for( CLS_A, F_SELF, '1.4.6' );

$res = attempt( static fn() => pre_download( false, 'https://downloads.wordpress.org/plugin/akismet.5.4.zip', update_extra( F_AKISMET ) ), $err );
chk( 'non-family package: $reply returned, nothing downloaded', false === $res && [] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
$res = attempt( static fn() => pre_download( '/tmp/some/local.zip', 'https://downloads.wordpress.org/plugin/akismet.5.4.zip', update_extra( F_AKISMET ) ), $err );
chk( 'non-family package: an earlier reply is left alone', '/tmp/some/local.zip' === $res, $err ?? show( $res ) );

// Member by hook_extra, stripped package.
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( false, '', update_extra( F_ALPHA ) ), $err );
chk( 'inactive member by hook_extra: a verified temp file is returned', is_string( $res ) && is_file( $res ), $err ?? show( $res ) );
chk( 'inactive member: the file holds the release bytes', is_string( $res ) && is_file( $res ) && hash_file( 'sha256', $res ) === hash( 'sha256', zip_bytes( 'jpkcom-alpha', '1.1.0' ) ) );
chk( 'inactive member: the MANIFEST url was downloaded', [ pkg( 'jpkcom-alpha', 'v1.1.0' ) ] === $GLOBALS['__dl_log'], json_encode( $GLOBALS['__dl_log'] ) );

// Member by hook_extra, package from a squatted wordpress.org entry.
$GLOBALS['__dl_log']     = [];
$GLOBALS['__downloads']['https://downloads.wordpress.org/plugin/jpkcom-alpha.9.9.zip'] = 'squatter bytes';
$res = attempt( static fn() => pre_download( false, 'https://downloads.wordpress.org/plugin/jpkcom-alpha.9.9.zip', update_extra( F_ALPHA ) ), $err );
chk( 'member with a foreign package URL: the manifest URL is installed instead', is_string( $res ) && is_file( $res ) && [ pkg( 'jpkcom-alpha', 'v1.1.0' ) ] === $GLOBALS['__dl_log'], ( $err ?? show( $res ) ) . ' ' . json_encode( $GLOBALS['__dl_log'] ) );

// Mismatch.
$GLOBALS['__st'][ cache_key( 'jpkcom-alpha' ) ] = manifest_object( 'jpkcom-alpha', [ 'checksum_sha256' => str_repeat( 'ab', 32 ) ] );
$GLOBALS['__dl_files'] = [];
$res = attempt( static fn() => pre_download( false, '', update_extra( F_ALPHA ) ), $err );
chk( 'checksum mismatch: WP_Error checksum_mismatch', $res instanceof WP_Error && 'checksum_mismatch' === $res->get_error_code(), $err ?? show( $res ) );
chk( 'checksum mismatch: the downloaded temp file is deleted', 1 === count( $GLOBALS['__dl_files'] ) && ! file_exists( $GLOBALS['__dl_files'][0] ), json_encode( $GLOBALS['__dl_files'] ) );

// Checksum missing.
$GLOBALS['__st'][ cache_key( 'jpkcom-alpha' ) ] = manifest_object( 'jpkcom-alpha', [ 'checksum_sha256' => '' ] );
$res = attempt( static fn() => pre_download( false, '', update_extra( F_ALPHA ) ), $err );
chk( 'manifest without checksum: WP_Error checksum_missing', $res instanceof WP_Error && 'checksum_missing' === $res->get_error_code(), $err ?? show( $res ) );

// Manifest with a download_url that is not a release asset of this plugin.
$GLOBALS['__st'][ cache_key( 'jpkcom-alpha' ) ] = manifest_object( 'jpkcom-alpha', [ 'download_url' => 'https://evil.example/jpkcom-alpha.zip' ] );
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( false, '', update_extra( F_ALPHA ) ), $err );
chk( 'manifest download_url outside the release assets: WP_Error, nothing downloaded', $res instanceof WP_Error && [] === $GLOBALS['__dl_log'], ( $err ?? show( $res ) ) . ' ' . json_encode( $GLOBALS['__dl_log'] ) );
seed_caches( [ 'jpkcom-alpha' ] );

// Manifest missing (uncached, 404).
unset( $GLOBALS['__st'][ cache_key( 'jpkcom-beta' ) ] );
$GLOBALS['__http'][ manifest_url( 'jpkcom-beta' ) ] = [ 404, 'Not Found' ];
$res = attempt( static fn() => pre_download( false, '', update_extra( F_BETA ) ), $err );
chk( 'manifest unavailable: WP_Error manifest_unavailable', $res instanceof WP_Error && 'manifest_unavailable' === $res->get_error_code(), $err ?? show( $res ) );

// Earlier WP_Error.
$earlier = new WP_Error( 'earlier', 'refused earlier' );
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( $earlier, '', update_extra( F_ALPHA ) ), $err );
chk( 'earlier WP_Error reply is returned as is', $res === $earlier && [] === $GLOBALS['__dl_log'], $err ?? show( $res ) );

// Earlier path reply: re-hashed.
$good = $tmp . '/dl/earlier-good.zip';
file_put_contents( $good, zip_bytes( 'jpkcom-alpha', '1.1.0' ) );
$bad = $tmp . '/dl/earlier-bad.zip';
file_put_contents( $bad, 'tampered' );
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( $good, '', update_extra( F_ALPHA ) ), $err );
chk( 'earlier path reply with matching hash is returned', $res === $good && [] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
$res = attempt( static fn() => pre_download( $bad, '', update_extra( F_ALPHA ) ), $err );
chk( 'earlier path reply with wrong hash: WP_Error checksum_mismatch', $res instanceof WP_Error && 'checksum_mismatch' === $res->get_error_code(), $err ?? show( $res ) );
chk( 'earlier path reply with wrong hash: the file is NOT deleted (not ours)', is_file( $bad ) );
$res = attempt( static fn() => pre_download( $tmp . '/dl/does-not-exist.zip', '', update_extra( F_ALPHA ) ), $err );
chk( 'earlier path reply that does not exist: WP_Error', $res instanceof WP_Error, $err ?? show( $res ) );

// Earlier non-string truthy replies.
$res = attempt( static fn() => pre_download( true, '', update_extra( F_ALPHA ) ), $err );
chk( 'earlier reply `true`: WP_Error', $res instanceof WP_Error, $err ?? show( $res ) );
$res = attempt( static fn() => pre_download( [ 'x' ], '', update_extra( F_ALPHA ) ), $err );
chk( 'earlier reply array: WP_Error', $res instanceof WP_Error, $err ?? show( $res ) );

// A filter at the default priority supplies a tampered file: the gate runs after it.
add_filter( 'upgrader_pre_download', static function ( $reply, $package, $up = null, $extra = [] ) use ( $bad ) {
	return ( is_array( $extra ) && F_ALPHA === ( $extra['plugin'] ?? '' ) ) ? $bad : $reply;
}, 10, 4 );
$res = attempt( static fn() => pre_download( false, pkg( 'jpkcom-alpha', 'v1.1.0' ), update_extra( F_ALPHA ) ), $err );
chk( 'a priority-10 filter\'s tampered file is caught (gate runs late)', $res instanceof WP_Error && 'checksum_mismatch' === $res->get_error_code(), $err ?? show( $res ) );
$GLOBALS['__hooks']['upgrader_pre_download'][10] = array_slice( $GLOBALS['__hooks']['upgrader_pre_download'][10] ?? [], 0, -1 );

// Install path: no hook_extra['plugin'], identified by the package URL.
$install = [ 'type' => 'plugin', 'action' => 'install' ];
$GLOBALS['__dl_log'] = [];
$real_url = 'https://github.com/JPKCom/jpkcom-post-filter/releases/download/v1.4.6/jpkcom-post-filter.zip';
$res = attempt( static fn() => pre_download( false, $real_url, $install ), $err );
chk( 'install by real release URL (v1.4.6 format) is identified and verified', is_string( $res ) && is_file( $res ) && hash_file( 'sha256', $res ) === hash( 'sha256', zip_bytes( 'jpkcom-post-filter', '1.4.6' ) ), $err ?? show( $res ) );
chk( 'install path downloads the URL it was given', [ $real_url ] === $GLOBALS['__dl_log'], json_encode( $GLOBALS['__dl_log'] ) );

$older = pkg( 'jpkcom-alpha', 'v0.9.0' );
$GLOBALS['__downloads'][ $older ] = zip_bytes( 'jpkcom-alpha', '0.9.0' );
$res = attempt( static fn() => pre_download( false, $older, $install ), $err );
chk( 'install of a release whose hash is not the manifest\'s: WP_Error checksum_mismatch', $res instanceof WP_Error && 'checksum_mismatch' === $res->get_error_code(), $err ?? show( $res ) );

$GLOBALS['__http_log'] = [];
$res = attempt( static fn() => pre_download( false, pkg( 'jpkcom-newbie', 'v0.5.0' ), $install ), $err );
chk( 'install of a not-installed JPKCom plugin: verified against its derived manifest', is_string( $res ) && is_file( $res ), $err ?? show( $res ) );
chk( 'derived manifest URL is https://jpkcom.github.io/<slug>/plugin_<slug>.json', in_array( manifest_url( 'jpkcom-newbie' ), $GLOBALS['__http_log'], true ), json_encode( $GLOBALS['__http_log'] ) );

$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( false, 'https://github.com/JPKCom/jpkcom-alpha/releases/download/v1/jpkcom-beta.zip', $install ), $err );
chk( 'install of any URL ending in a JPKCom asset name: claimed by the file name, never installed unverified', false !== $res, $err ?? show( $res ) );
$res = attempt( static fn() => pre_download( false, 'https://example.com/some-other-plugin.zip', $install ), $err );
chk( 'install of a non-JPKCom ZIP: not claimed', false === $res, $err ?? show( $res ) );

// Every spelling GitHub serves for the same asset is claimed and verified (1.4 did this by file name).
foreach ( [
	'latest permalink' => 'https://github.com/JPKCom/jpkcom-alpha/releases/latest/download/jpkcom-alpha.zip',
	'repo case'        => 'https://github.com/JPKCom/JPKCom-Alpha/releases/download/v1.1.0/jpkcom-alpha.zip',
	'host case'        => 'https://GITHUB.COM/JPKCom/jpkcom-alpha/releases/download/v1.1.0/jpkcom-alpha.zip',
	'www host'         => 'https://www.github.com/JPKCom/jpkcom-alpha/releases/download/v1.1.0/jpkcom-alpha.zip',
	'query string'     => 'https://github.com/JPKCom/jpkcom-alpha/releases/download/v1.1.0/jpkcom-alpha.zip?dl=1',
	'http scheme'      => 'http://github.com/JPKCom/jpkcom-alpha/releases/download/v1.1.0/jpkcom-alpha.zip',
	'asset case'       => 'https://github.com/JPKCom/jpkcom-alpha/releases/download/v1.1.0/JPKCom-Alpha.zip',
] as $what => $variant ) {
	$GLOBALS['__dl_log']              = [];
	$GLOBALS['__downloads'][ $variant ] = zip_bytes( 'jpkcom-alpha', '1.1.0' );
	$res = attempt( static fn() => pre_download( false, $variant, $install ), $err );
	chk( "install spelling ({$what}): claimed and verified", is_string( $res ) && is_file( $res ) && [ $variant ] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
	$GLOBALS['__downloads'][ $variant ] = 'tampered bytes';
	$res = attempt( static fn() => pre_download( false, $variant, $install ), $err );
	chk( "install spelling ({$what}): tampered ZIP refused", $res instanceof WP_Error && 'checksum_mismatch' === $res->get_error_code(), $err ?? show( $res ) );
}
$GLOBALS['__dl_log'] = [];

// Old copy (loaded, unregistered): left to its own copy.
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( false, pkg( 'jpkcom-old', 'v2.0.0' ), update_extra( F_OLD ) ), $err );
chk( 'old-copy plugin update the old copy declined: the offered URL is verified, not passed through unchecked', is_string( $res ) && is_file( $res ) && hash_file( 'sha256', $res ) === hash( 'sha256', zip_bytes( 'jpkcom-old', '2.0.0' ) ) && [ pkg( 'jpkcom-old', 'v2.0.0' ) ] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
$GLOBALS['__dl_log'] = [];
$old_tmp = $tmp . '/dl/old-verified.zip';
file_put_contents( $old_tmp, 'whatever the old copy verified against its own manifest' );
$res = attempt( static fn() => pre_download( $old_tmp, pkg( 'jpkcom-old', 'v2.0.0' ), update_extra( F_OLD ) ), $err );
chk( 'old-copy plugin update: the old copy\'s verified file is passed through', $res === $old_tmp, $err ?? show( $res ) );
$res = attempt( static fn() => pre_download( false, pkg( 'jpkcom-old', 'v2.0.0' ), $install ), $err );
chk( 'old-copy plugin install by URL the old copy declined: verified, not installed unchecked', is_string( $res ) && is_file( $res ) && [ pkg( 'jpkcom-old', 'v2.0.0' ) ] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( $old_tmp, pkg( 'jpkcom-old', 'v2.0.0' ), $install ), $err );
chk( 'old-copy plugin install by URL: a file the old copy supplied is passed through', $res === $old_tmp && [] === $GLOBALS['__dl_log'], $err ?? show( $res ) );

// Front end, no capability: an action may still fetch.
reset_world();
$a                 = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS['__can']  = [];
$res = attempt( static fn() => pre_download( false, '', update_extra( F_ALPHA ) ), $err );
chk( 'the gate fetches an uncached manifest without the read-side capability', is_string( $res ) && in_array( manifest_url( 'jpkcom-alpha' ), $GLOBALS['__http_log'], true ), $err ?? show( $res ) );

// ===========================================================================
section( 'plugins_api (View details)' );

reset_world();
seed_caches();
$a        = copy_for( CLS_A, F_SELF, '1.4.6' );
$args_for = static fn( string $slug ) => (object) [ 'slug' => $slug, 'fields' => [] ];

$prior = (object) [ 'name' => 'answered earlier' ];
$res   = attempt( static fn() => apply_filters( 'plugins_api', $prior, 'plugin_information', $args_for( 'jpkcom-alpha' ) ), $err );
chk( 'a non-false earlier result passes through', $res === $prior, $err ?? show( $res ) );

$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'jpkcom-alpha' ) ), $err );
chk( 'inactive member is answered', is_object( $res ) && ! $res instanceof WP_Error, $err ?? show( $res ) );
chk( 'answer carries the requested slug, not the manifest\'s', 'jpkcom-alpha' === ( $res->slug ?? null ), (string) ( $res->slug ?? '' ) );
chk( 'answer download_link is the validated release asset', pkg( 'jpkcom-alpha', 'v1.1.0' ) === ( $res->download_link ?? null ), (string) ( $res->download_link ?? '' ) );
chk( 'answer version from the manifest', '1.1.0' === ( $res->version ?? null ) );
chk( 'answer icons is an array', is_array( $res->icons ?? null ) );

$GLOBALS['__st'][ cache_key( 'jpkcom-beta' ) ] = manifest_object( 'jpkcom-beta', [ 'download_url' => 'https://evil.example/jpkcom-beta.zip' ] );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'jpkcom-beta' ) ), $err );
chk( 'download_link outside the release assets is dropped', is_object( $res ) && '' === ( $res->download_link ?? null ), $err ?? show( $res ) );

$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'akismet' ) ), $err );
chk( 'non-family slug untouched', false === $res, $err ?? show( $res ) );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'jpkcom-old' ) ), $err );
chk( 'old-copy slug left to the old copy', false === $res, $err ?? show( $res ) );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'jpkcom-gamma' ) ), $err );
chk( 'non-member JPKCom-looking slug (renamed, unregistered) untouched', false === $res, $err ?? show( $res ) );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'jpkcom-delta-copy' ) ), $err );
chk( 'folder whose Plugin URI names another repository is not a member', false === $res, $err ?? show( $res ) );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'jpkcom-foreign' ) ), $err );
chk( 'foreign Plugin URI is not a member', false === $res, $err ?? show( $res ) );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'query_plugins', $args_for( 'jpkcom-alpha' ) ), $err );
chk( 'other actions untouched', false === $res, $err ?? show( $res ) );

unset( $GLOBALS['__st'][ cache_key( 'jpkcom-alpha' ) ] );
$GLOBALS['__http'][ manifest_url( 'jpkcom-alpha' ) ] = [ 404, 'Not Found' ];
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', $args_for( 'jpkcom-alpha' ) ), $err );
chk( 'member with no manifest: WP_Error, never a fall-through to wordpress.org', $res instanceof WP_Error, $err ?? show( $res ) );

// ===========================================================================
section( 'clear_cache after updates' );

reset_world();
seed_caches();
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS['__st'][ cache_key( 'jpkcom-vcs' ) ] = manifest_object( 'jpkcom-vcs' );

attempt( static fn() => do_action( 'upgrader_process_complete', $GLOBALS['upgrader'], [ 'action' => 'install', 'type' => 'plugin', 'plugin' => F_ALPHA ] ), $err );
chk( 'an install clears nothing', false !== get_site_transient( cache_key( 'jpkcom-alpha' ) ), (string) $err );

attempt( static fn() => do_action( 'upgrader_process_complete', $GLOBALS['upgrader'], [ 'action' => 'update', 'type' => 'plugin', 'plugin' => F_ALPHA ] ), $err );
chk( 'single update (`plugin`) clears that member\'s manifest', false === get_site_transient( cache_key( 'jpkcom-alpha' ) ), (string) $err );
chk( 'single update leaves other members\' manifests', false !== get_site_transient( cache_key( 'jpkcom-beta' ) ) );

attempt( static fn() => do_action( 'upgrader_process_complete', $GLOBALS['upgrader'], [ 'action' => 'update', 'type' => 'plugin', 'bulk' => true, 'plugins' => [ F_BETA, F_VCS, F_OLD, F_AKISMET ] ] ), $err );
chk( 'bulk update (`plugins`) clears every listed member', false === get_site_transient( cache_key( 'jpkcom-beta' ) ) && false === get_site_transient( cache_key( 'jpkcom-vcs' ) ), (string) $err );
chk( 'bulk update leaves the old copy\'s cache to the old copy', false !== get_site_transient( cache_key( 'jpkcom-old' ) ) );
chk( 'bulk update leaves unlisted members', false !== get_site_transient( cache_key( 'jpkcom-post-filter' ) ) );

// ===========================================================================
section( 'Fetch policy on read' );

// Anonymous front end: no capability, not cron.
reset_world();
$a                = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS['__can'] = [];
attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'front-end read without update_plugins performs no HTTP fetch', [] === $GLOBALS['__http_log'], ( $err ?? '' ) . json_encode( $GLOBALS['__http_log'] ) );

reset_world();
$a                 = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS['__can']  = [];
$GLOBALS['__cron'] = true;
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'cron read fetches', [] !== $GLOBALS['__http_log'] && null !== entry_of( $r, 'response', F_ALPHA ), $err ?? json_encode( $GLOBALS['__http_log'] ) );

// Read budget.
reset_world();
foreach ( [ 'jpkcom-b1', 'jpkcom-b2', 'jpkcom-b3', 'jpkcom-b4' ] as $b ) {
	make_plugin( "{$b}/{$b}.php", "https://github.com/JPKCom/{$b}", '1.0.0' );
}
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
attempt( static fn() => read_transient( stored_transient() ), $err );
$first = count( $GLOBALS['__http_log'] );
chk( 'one read fetches at most 5 uncached manifests (7 members need one)', 5 === $first, ( $err ?? '' ) . " fetched {$first}: " . json_encode( $GLOBALS['__http_log'] ) );
attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'the budget is per request: a second read fetches nothing more', count( $GLOBALS['__http_log'] ) === $first, count( $GLOBALS['__http_log'] ) . ' fetches' );
$unfetched = array_values( array_diff( [ 'jpkcom-post-filter', 'jpkcom-alpha', 'jpkcom-beta', 'jpkcom-b1', 'jpkcom-b2', 'jpkcom-b3', 'jpkcom-b4' ], array_map( static fn( $u ) => (string) preg_replace( '~^https://jpkcom\.github\.io/([^/]+)/.*$~', '$1', $u ), $GLOBALS['__http_log'] ) ) );
if ( count( $unfetched ) >= 2 ) {
	$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => $unfetched[0] ] ), $err );
	chk( 'an action (View details) still fetches after the read budget is spent', is_object( $res ) && ! $res instanceof WP_Error && in_array( manifest_url( $unfetched[0] ), $GLOBALS['__http_log'], true ), $err ?? show( $res ) );
	$res = attempt( static fn() => pre_download( false, '', update_extra( "{$unfetched[1]}/{$unfetched[1]}.php" ) ), $err );
	chk( 'an action (update) still fetches after the read budget is spent', is_string( $res ) && in_array( manifest_url( $unfetched[1] ), $GLOBALS['__http_log'], true ), $err ?? show( $res ) );
} else {
	chk( 'budget left members unfetched to test actions with', false, json_encode( $unfetched ) );
}
foreach ( [ 'jpkcom-b1', 'jpkcom-b2', 'jpkcom-b3', 'jpkcom-b4' ] as $b ) {
	remove_plugin( "{$b}/{$b}.php" );
}

// TTL spread.
$exp_a = $GLOBALS['__st_exp'][ cache_key( 'jpkcom-alpha' ) ] ?? null;
$exp_p = $GLOBALS['__st_exp'][ cache_key( 'jpkcom-post-filter' ) ] ?? null;
chk( 'manifest cache TTL lies in [24 h, 30 h)', is_int( $exp_a ) && $exp_a >= DAY_IN_SECONDS && $exp_a < DAY_IN_SECONDS + 6 * HOUR_IN_SECONDS, var_export( $exp_a, true ) );
chk( 'manifest cache TTL is spread per plugin', is_int( $exp_a ) && is_int( $exp_p ) && $exp_a !== $exp_p, var_export( [ $exp_a, $exp_p ], true ) );

// Host breaker.
reset_world();
foreach ( array_keys( $GLOBALS['__http'] ) as $u ) {
	$GLOBALS['__http'][ $u ] = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
}
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'transport error: one fetch, then the host breaker stops the rest', 1 === count( $GLOBALS['__http_log'] ), ( $err ?? '' ) . json_encode( $GLOBALS['__http_log'] ) );
chk( 'transport error: the host breaker is set', false !== get_site_transient( HOST_FAIL ) );
$GLOBALS['__http_log'] = [];
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'jpkcom-beta' ] ), $err );
chk( 'while the breaker is set, an action still tries the network once (and fails closed)', 1 === count( $GLOBALS['__http_log'] ) && $res instanceof WP_Error, ( $err ?? show( $res ) ) . json_encode( $GLOBALS['__http_log'] ) );

reset_world();
foreach ( array_keys( $GLOBALS['__http'] ) as $u ) {
	$GLOBALS['__http'][ $u ] = [ 503, 'Service Unavailable' ];
}
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'HTTP 503: the host breaker trips after one fetch', 1 === count( $GLOBALS['__http_log'] ) && false !== get_site_transient( HOST_FAIL ), json_encode( $GLOBALS['__http_log'] ) );

reset_world();
foreach ( array_keys( $GLOBALS['__http'] ) as $u ) {
	$GLOBALS['__http'][ $u ] = [ 404, 'Not Found' ];
}
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'HTTP 404 is about one plugin: no host breaker, the others are still fetched', count( $GLOBALS['__http_log'] ) > 1 && false === get_site_transient( HOST_FAIL ), count( $GLOBALS['__http_log'] ) . ' fetches' );
chk( 'HTTP 404 marks that plugin as failed', false !== get_site_transient( cache_key( 'jpkcom-post-filter' ) . '_fail' ) );

// ===========================================================================
section( 'Review round 2: robustness fixes' );

// Background requests (cron, WP-CLI) are not bound by the read budget.
reset_world();
foreach ( [ 'jpkcom-b1', 'jpkcom-b2', 'jpkcom-b3', 'jpkcom-b4' ] as $b ) {
	make_plugin( "{$b}/{$b}.php", "https://github.com/JPKCom/{$b}", '1.0.0' );
}
$a                 = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS['__cron'] = true;
attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'cron read is not capped at 5 fetches (every cold member is fetched)', count( $GLOBALS['__http_log'] ) > 5, ( $err ?? '' ) . count( $GLOBALS['__http_log'] ) . ' fetches' );
foreach ( [ 'jpkcom-b1', 'jpkcom-b2', 'jpkcom-b3', 'jpkcom-b4' ] as $b ) {
	remove_plugin( "{$b}/{$b}.php" );
}

// A manifest with objects where strings belong must not throw.
reset_world();
$odd                 = manifest_array( 'jpkcom-alpha' );
$odd['tested']       = [ 'nested' => true ];
$odd['requires_php'] = (object) [ 'min' => '8.3' ];
$odd['name']         = [ 'x' ];
$odd['homepage']     = [ 'x' ];
$odd['sections']     = 'not an object';
$odd['contributors'] = [ 'someone' => [ 'display_name' => [ 'x' ], 'profile' => [ 'x' ] ] ];
$GLOBALS['__http'][ manifest_url( 'jpkcom-alpha' ) ] = [ 200, (string) json_encode( $odd ) ];
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
$e = entry_of( $r, 'response', F_ALPHA );
chk( 'object-valued manifest fields: the transient read does not throw', null === $err, (string) $err );
chk( 'object-valued manifest fields: the update is still offered, with empty text', null !== $e && '' === ( $e->tested ?? null ) && '' === ( $e->requires_php ?? null ) );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'jpkcom-alpha' ] ), $err );
chk( 'object-valued manifest fields: View details does not throw', null === $err && is_object( $res ) && ! $res instanceof WP_Error, $err ?? show( $res ) );
chk( 'object-valued manifest fields: fallbacks apply', is_object( $res ) && '' === ( $res->name ?? null ) && '8.3' === ( $res->requires_php ?? null ) && [] === ( $res->sections ?? null ) );

// Listed as active but not loaded (wp --skip-plugins=<slug>): served like an inactive plugin.
reset_world();
make_plugin( 'jpkcom-skipped/jpkcom-skipped.php', 'https://github.com/JPKCom/jpkcom-skipped', '1.0.0' );
$GLOBALS['__offer']['jpkcom-skipped'] = '2.0.0';
reset_world();
$GLOBALS['__active'][] = WP_PLUGIN_DIR . '/jpkcom-skipped/jpkcom-skipped.php';
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'active in the option but never included: offered like an inactive member', null !== entry_of( $r, 'response', 'jpkcom-skipped/jpkcom-skipped.php' ), $err ?? show( $r->response ?? null ) );
remove_plugin( 'jpkcom-skipped/jpkcom-skipped.php' );
unset( $GLOBALS['__offer']['jpkcom-skipped'] );

// The install path is claimed for plugin upgraders only.
reset_world();
$a       = copy_for( CLS_A, F_SELF, '1.4.6' );
$install = [ 'type' => 'plugin', 'action' => 'install' ];
$res     = attempt( static fn() => apply_filters( 'upgrader_pre_download', false, pkg( 'jpkcom-alpha', 'v1.1.0' ), new WP_Upgrader(), $install ), $err );
chk( 'install path with a non-plugin upgrader: not claimed', false === $res && [] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
$lower = 'https://github.com/jpkcom/jpkcom-alpha/releases/download/v1.1.0/jpkcom-alpha.zip';
$GLOBALS['__downloads'][ $lower ] = zip_bytes( 'jpkcom-alpha', '1.1.0' );
$res = attempt( static fn() => pre_download( false, $lower, $install ), $err );
chk( 'install by a lowercase-organisation URL is claimed and verified', is_string( $res ) && is_file( $res ) && [ $lower ] === $GLOBALS['__dl_log'], $err ?? show( $res ) );
$res = attempt( static fn() => pre_download( false, $lower . "\n", $install ), $err );
chk( 'a package URL with a trailing newline is not claimed as a release asset', false === $res, $err ?? show( $res ) );

// Registry entries that do not describe a JPKCom plugin take no part.
reset_world();
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => '9.9.9', 'id' => 424242, 'file' => F_AKISMET, 'slug' => 'akismet', 'manifest' => 'https://evil.example/m.json', 'version' => '1.0' ];
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => '9.9.9', 'id' => 424243, 'file' => F_ALPHA, 'slug' => 'jpkcom-alpha', 'manifest' => 'https://evil.example/m.json', 'version' => '1.0' ];
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'a newer jpkcom-* copy with another manifest location wins the election (one leader, not two)', null === entry_of( $r, 'response', F_ALPHA ) && null === entry_of( $r, 'no_update', F_AKISMET ), $err ?? show( $r->response ?? null ) );
reset_world();
$a = copy_for( CLS_A, F_SELF, '1.4.6' );
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => '9.9.9', 'id' => 424242, 'file' => F_AKISMET, 'slug' => 'akismet', 'manifest' => 'https://evil.example/m.json', 'version' => '1.0' ];
$GLOBALS[ REGISTRY_KEY ][] = [ 'v' => 'not-a-version', 'id' => 424244, 'file' => F_ALPHA, 'manifest' => manifest_url( 'jpkcom-alpha' ), 'version' => '1.0' ];
$r = attempt( static fn() => read_transient( stored_transient() ), $err );
chk( 'a registry entry outside jpkcom-* or without a comparable version cannot win the election', null !== entry_of( $r, 'response', F_ALPHA ), $err ?? show( $r->response ?? null ) );
chk( 'a foreign registry entry is never served', null === entry_of( $r, 'response', F_AKISMET ) && null === entry_of( $r, 'no_update', F_AKISMET ) );

// A frozen member's details offer no install.
reset_world();
$a   = copy_for( CLS_A, F_SELF, '1.4.6' );
$res = attempt( static fn() => apply_filters( 'plugins_api', false, 'plugin_information', (object) [ 'slug' => 'jpkcom-vcs' ] ), $err );
chk( 'VCS checkout: View details shows the release but has no download link', is_object( $res ) && '' === ( $res->download_link ?? null ) && '9.0.0' === ( $res->version ?? null ), $err ?? show( $res ) );
$GLOBALS['__dl_log'] = [];
$res = attempt( static fn() => pre_download( false, pkg( 'jpkcom-vcs', 'v9.0.0' ), update_extra( F_VCS ) ), $err );
chk( 'VCS checkout: an update through a stale row is refused before any download', $res instanceof WP_Error && 'no_package' === $res->get_error_code() && [] === $GLOBALS['__dl_log'], $err ?? show( $res ) );

// Every regex literal in the class compiles (a delimiter inside a character class does not).
$src = (string) file_get_contents( $updater_file );
$found_all = preg_match_all( "{'([~/#])\\^.*?\\1[a-zA-Z]*'}", $src, $lits );
chk( 'the regex-literal scan itself compiles', false !== $found_all );
$lits = is_array( $lits ) ? $lits : [ [] ];
$bad = [];
foreach ( $lits[0] as $lit ) {
	$re = substr( $lit, 1, -1 );
	if ( false === @preg_match( $re, '' ) ) {
		$bad[] = $re;
	}
}
chk( 'every regex literal in the updater compiles (' . count( $lits[0] ) . ' found)', count( $lits[0] ) >= 5 && [] === $bad, implode( ' | ', $bad ) );

// The plugin's own manifest URL literal is the derived one, or the leader would not serve it.
$main = glob( dirname( $updater_file, 2 ) . '/*.php' ) ?: [];
$lit  = '';
foreach ( $main as $f ) {
	if ( preg_match( "~manifest_url:\\s*'([^']+)'~", (string) file_get_contents( $f ), $m ) ) {
		$lit  = $m[1];
		$slug = basename( $f, '.php' );
	}
}
if ( '' === $lit ) {
	echo "  SKIP  manifest URL literal (no main file next to UPDATER_FILE)\n";
} else {
	chk( 'the main file\'s manifest_url literal is https://jpkcom.github.io/<slug>/plugin_<slug>.json', "https://jpkcom.github.io/{$slug}/plugin_{$slug}.json" === $lit, $lit );
}

// ===========================================================================
section( 'Hygiene' );

chk( 'no PHP warning, notice or deprecation during the run', [] === $GLOBALS['__php_errors'], implode( ' | ', array_slice( array_unique( $GLOBALS['__php_errors'] ), 0, 6 ) ) );

exit( summary() );
