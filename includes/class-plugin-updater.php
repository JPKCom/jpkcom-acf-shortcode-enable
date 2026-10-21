<?php
/**
 * JPKCom Plugin Updater – GitHub Self-Hosted Updates
 *
 * This class provides a secure, self-hosted update mechanism for WordPress plugins
 * hosted on GitHub. It integrates with the WordPress plugin update system and provides
 * comprehensive security features including:
 *
 * - SHA256 checksum verification of downloaded packages (fail closed)
 * - URL validation and sanitization of all remote data
 * - Race condition prevention for manifest fetching
 * - Comprehensive error logging in WP_DEBUG mode
 * - Site-transient caching with a ~24-hour TTL (one cache per multisite network)
 * - Family updates: one loaded copy serves every installed JPKCom plugin,
 *   including inactive ones whose own code never runs
 *
 * Security Features:
 * - All URLs are validated before use; package URLs must be JPKCom release assets
 * - All manifest data is sanitized before display
 * - Download packages are verified against SHA256 checksum from manifest
 * - Failed verifications prevent installation and log errors
 * - Package URLs are never persisted in the update transient
 *
 * Namespace: JPKComAcfShortcodeEnableGitUpdate
 * PHP Version: 8.3+
 * WordPress Version: 6.9+ (matches the plugin header)
 *
 * @since 1.5.0 Family updates: every JPKCom plugin carries this class, the copy
 *              with the highest UPDATER_VERSION is elected leader and serves all
 *              installed JPKCom plugins (active and inactive); package URLs are
 *              stripped before the update transient is stored; the checksum gate
 *              verifies a package another filter already supplied instead of
 *              trusting it
 * @since 1.4.0 Manifest cache, failure marker and fetch lock are site
 *              transients, so a multisite network fetches the manifest once
 *              instead of once per site
 * @since 1.3.0 Checksum is now mandatory (fail closed) and the verified file is
 *              handed to WP_Upgrader instead of being re-downloaded; failed
 *              manifest fetches are negatively cached
 * @since 1.2.0 Added SHA256 checksum verification
 * @since 1.0.0 Initial release with GitHub integration
 */

declare(strict_types=1);

namespace JPKComAcfShortcodeEnableGitUpdate;

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class JPKComGitPluginUpdater
 *
 * Handles plugin updates from GitHub-hosted JSON manifests.
 *
 * Why a family: WordPress only runs the code of ACTIVE plugins, so a copy of this
 * class inside an inactive plugin never hooks anything and that plugin would never
 * be offered an update. Every JPKCom plugin therefore registers its copy in a
 * shared registry, and the copy with the highest UPDATER_VERSION (ties: the first
 * registered) does the work for every installed JPKCom plugin. All other 1.5+
 * copies stay passive. Copies older than 1.5 do not know the registry and keep
 * serving their own plugin; the leader leaves plugins whose old copy is loaded
 * alone, so the two never disagree about a manifest or a package.
 *
 * @package JPKComAcfShortcodeEnableGitUpdate
 */
final class JPKComGitPluginUpdater {

    /**
     * Version of this updater implementation. Decides the leader election, so it
     * must be raised with every change to the family logic.
     */
    public const UPDATER_VERSION = '1.5.0';

    /**
     * Global registry shared by all copies (each copy lives in its own namespace,
     * so class statics are not shared). Entries are plain arrays with the scalar
     * keys v, id, file, slug, manifest, version. Later versions may add keys but
     * must never rename or retype these.
     */
    private const REGISTRY = 'jpkcom_git_updater_registry';

    /**
     * A JPKCom plugin is recognised by its Plugin URI, which every released version
     * of every JPKCom plugin carries (unlike the Update URI header, added only in
     * the 2026-10-19 releases), together with the canonical file <slug>/<slug>.php.
     * The `~` delimiter matters: none of these patterns may contain it.
     */
    private const PLUGIN_URI_PATTERN = '~^https://github\.com/JPKCom/(jpkcom-[a-z0-9-]+)/?\z~';

    /**
     * Release assets are published as <slug>.zip on the plugin's own repository.
     * The organisation is matched case-insensitively because GitHub resolves it
     * that way: a hand-typed lowercase URL must not slip past the checksum gate.
     */
    private const PACKAGE_PATTERN = '~^https://github\.com/(?i:JPKCom)/(jpkcom-[a-z0-9-]+)/releases/download/[^/?#]+/\1\.zip\z~';

    /** File name of a JPKCom release asset, lower-cased, as the install gate claims it. */
    private const ASSET_PATTERN = '~^(jpkcom-[a-z0-9-]+)\.zip\z~';

    /** Slug of a JPKCom plugin, as used for folder and main file. */
    private const SLUG_PATTERN = '~^jpkcom-[a-z0-9-]+\z~';

    /** Manifest location for a plugin whose copy is not loaded. */
    private const MANIFEST_URL_FORMAT = 'https://jpkcom.github.io/%1$s/plugin_%1$s.json';

    /** Host-wide breaker: one dead or slow github.io must not cost a timeout per plugin. */
    private const HOST_FAIL_KEY = 'jpk_git_update_host_fail';

    /** Uncached manifest fetches allowed per request while rendering update data. */
    private const READ_FETCH_BUDGET = 5;

    /** A successful fetch slower than this (seconds) also trips the host breaker. */
    private const SLOW_FETCH_SECONDS = 3.0;

    /** @var string Plugin slug (directory name) */
    private string $plugin_slug;

    /** @var string Path to main plugin file */
    private string $plugin_file;

    /** @var string Current plugin version */
    private string $current_version;

    /** @var string Remote manifest URL */
    private string $manifest_url;

    /** @var string Cache key for transient */
    private string $cache_key;

    /** @var array<string, array{slug: string, file: string, version: string, manifest: string, frozen: bool}>|null Per-request scan of installed JPKCom plugins */
    private ?array $discovered = null;

    /** @var array<string, array{slug: string, file: string, version: string, manifest: string, frozen: bool}>|null Per-request family member cache */
    private ?array $members = null;

    /** @var string Registry signature the member cache was built for */
    private string $members_signature = '';

    /** @var int Registry size the cached election result belongs to */
    private int $leader_for_size = -1;

    /** @var int|null Object id of the elected leader */
    private ?int $leader_id = null;

    /** @var int Uncached manifest fetches made by the read filter in this request */
    private int $read_fetches = 0;

    /** @var array<string, bool> Per-request memo of wp_http_validate_url() results */
    private array $validated_urls = [];

    /**
     * Constructor
     *
     * @param string $plugin_file      Absolute path to the main plugin file (__FILE__).
     * @param string $current_version  Current plugin version.
     * @param string $manifest_url     Full URL to the remote JSON manifest.
     */
    public function __construct( string $plugin_file, string $current_version, string $manifest_url ) {
        global $wp_version;

        // Environment check
        if ( version_compare( version1: PHP_VERSION, version2: '8.3', operator: '<' ) || version_compare( version1: $wp_version, version2: '6.9', operator: '<' ) ) {
            return;
        }

        // Security: Validate and sanitize manifest URL
        $manifest_url = esc_url_raw( $manifest_url );
        if ( ! wp_http_validate_url( $manifest_url ) ) {
            $this->log( sprintf( 'Invalid manifest URL provided: %s', $manifest_url ) );
            return; // Invalid URL, abort initialization
        }

        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = dirname( path: plugin_basename( $plugin_file ) );
        $this->current_version = $current_version;
        $this->manifest_url    = $manifest_url;
        $this->cache_key       = $this->cache_key_for( $this->plugin_slug );

        $this->register();

        // Hook into WordPress update system. Every copy registers every hook; each
        // callback returns early unless this copy is the elected leader, which is
        // decided per call because copies register one by one during init.
        add_filter( 'plugins_api', [$this, 'plugin_info'], 20, 3 );
        add_filter( 'site_transient_update_plugins', [$this, 'check_update'] );
        add_filter( 'pre_set_site_transient_update_plugins', [$this, 'strip_packages'], PHP_INT_MAX );
        add_action( 'upgrader_process_complete', [$this, 'clear_cache'], 10, 2 );
        add_action( 'deleted_plugin', [$this, 'forget_members'] );
        // Late priority: the gate must see what earlier callbacks returned (an old
        // copy's verified file, or a third party's cached package) and verify it,
        // and as little as possible should run after it.
        add_filter( 'upgrader_pre_download', [$this, 'verify_download_checksum'], PHP_INT_MAX - 100, 4 );
    }

    /**
     * Add this copy to the shared registry.
     *
     * @return void
     */
    private function register(): void {
        if ( ! isset( $GLOBALS[ self::REGISTRY ] ) || ! is_array( value: $GLOBALS[ self::REGISTRY ] ) ) {
            $GLOBALS[ self::REGISTRY ] = [];
        }

        $GLOBALS[ self::REGISTRY ][] = [
            'v'        => self::UPDATER_VERSION,
            'id'       => spl_object_id( $this ),
            'file'     => plugin_basename( $this->plugin_file ),
            'slug'     => $this->plugin_slug,
            'manifest' => $this->manifest_url,
            'version'  => $this->current_version,
        ];
    }

    /**
     * Valid registry entries, in registration order.
     *
     * Malformed entries and entries that do not describe a JPKCom plugin (main
     * file <slug>.php, manifest on jpkcom.github.io for that slug) are skipped,
     * also for the leader election. No method is ever called on an entry, so a
     * broken entry cannot break this copy.
     *
     * @return array<int, array{v: string, id: int, file: string, slug: string, manifest: string, version: string}>
     */
    private function registry(): array {
        $entries = $GLOBALS[ self::REGISTRY ] ?? [];
        if ( ! is_array( value: $entries ) ) {
            return [];
        }

        $valid = [];
        foreach ( $entries as $entry ) {
            if ( ! is_array( value: $entry )
                || ! is_string( value: $entry['v'] ?? null ) || 1 !== preg_match( '~^\d+\.\d+\.\d+\z~', $entry['v'] )
                || ! is_int( value: $entry['id'] ?? null )
                || ! is_string( value: $entry['file'] ?? null )
                || ! is_string( value: $entry['manifest'] ?? null )
                || ! is_string( value: $entry['version'] ?? null ) ) {
                continue;
            }
            $slug = basename( path: $entry['file'], suffix: '.php' );
            if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) || $entry['manifest'] !== sprintf( self::MANIFEST_URL_FORMAT, $slug ) ) {
                continue;
            }
            $entry['slug'] = $slug;
            $valid[]       = $entry;
        }

        return $valid;
    }

    /**
     * Whether this copy is the elected leader.
     *
     * Highest UPDATER_VERSION wins, ties go to the first registered copy. Not
     * memoised: a transient read at init priority 5 can happen before every copy
     * has registered, and the answer must settle once they have.
     *
     * @return bool
     */
    private function is_leader(): bool {
        if ( ! isset( $this->plugin_slug ) ) {
            return false; // Constructor bailed out (environment or manifest URL).
        }

        // The registry only ever grows, so its size says whether a copy has
        // registered since the last election.
        $entries = $GLOBALS[ self::REGISTRY ] ?? [];
        $size    = is_array( value: $entries ) ? count( value: $entries ) : 0;
        if ( $size !== $this->leader_for_size ) {
            $this->leader_for_size = $size;
            $this->leader_id       = $this->elect( is_array( value: $entries ) ? $entries : [] );
        }

        return $this->leader_id === spl_object_id( $this );
    }

    /**
     * Elect the leader among the registered copies.
     *
     * Deliberately looser than registry(): any copy inside a jpkcom-* plugin
     * with a comparable version takes part, so a future copy that changes its
     * manifest location or the shape of its entry still wins over this one
     * instead of leaving two leaders.
     *
     * @param array<mixed> $entries Raw registry.
     * @return int|null Object id of the leader.
     */
    private function elect( array $entries ): ?int {
        $best = null;
        foreach ( $entries as $entry ) {
            if ( ! is_array( value: $entry )
                || ! is_int( value: $entry['id'] ?? null )
                || ! is_string( value: $entry['v'] ?? null ) || 1 !== preg_match( '/^\d+(\.\d+)*([-+._~][0-9A-Za-z.-]*)?\z/', $entry['v'] )
                || ! is_string( value: $entry['file'] ?? null ) || 1 !== preg_match( self::SLUG_PATTERN, basename( path: $entry['file'], suffix: '.php' ) ) ) {
                continue;
            }
            if ( null === $best || version_compare( version1: $entry['v'], version2: $best['v'], operator: '>' ) ) {
                $best = $entry;
            }
        }

        return null !== $best ? $best['id'] : null;
    }

    /**
     * Every installed plugin in the canonical <slug>/<slug>.php layout whose
     * Plugin URI is its JPKCom repository, keyed by plugin file.
     *
     * Reads the headers of jpkcom-* candidates only, not the whole plugin
     * directory, because this runs on every read of the update transient.
     *
     * @return array<string, array{slug: string, folder: string, file: string, version: string, manifest: string, frozen: bool}>
     */
    private function discovered(): array {
        if ( null !== $this->discovered ) {
            return $this->discovered;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $found = [];
        // scandir(), not glob(): a plugins path containing [ or ] would turn a
        // glob pattern into a character class and silently find nothing.
        $entries = is_dir( WP_PLUGIN_DIR ) ? scandir( WP_PLUGIN_DIR ) : false;
        foreach ( is_array( value: $entries ) ? $entries : [] as $slug ) {
            $path = WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php';
            if ( 1 !== preg_match( self::SLUG_PATTERN, $slug ) || ! is_file( $path ) ) {
                continue;
            }

            $data = get_plugin_data( $path, false, false );
            $uri  = is_string( value: $data['PluginURI'] ?? null ) ? $data['PluginURI'] : '';
            if ( 1 !== preg_match( self::PLUGIN_URI_PATTERN, $uri, $match ) || $match[1] !== $slug ) {
                continue;
            }

            $file           = $slug . '/' . $slug . '.php';
            $found[ $file ] = [
                'slug'     => $slug,
                'folder'   => $slug,
                'file'     => $file,
                'version'  => is_string( value: $data['Version'] ?? null ) ? $data['Version'] : '',
                'manifest' => sprintf( self::MANIFEST_URL_FORMAT, $slug ),
                'frozen'   => $this->is_vcs_checkout( dirname( path: $path ) ),
            ];
        }

        return $this->discovered = $found;
    }

    /**
     * The JPKCom plugins this leader serves, keyed by plugin file.
     *
     * Members are (a) every loaded 1.5+ copy from the registry and (b) every
     * discovered JPKCom plugin whose code is NOT loaded in this request. A plugin
     * that is loaded but not registered runs an older updater copy, which serves
     * it against its own cache; the leader stays out of its way.
     *
     * A member is "frozen" (only ever listed as up to date, never offered a
     * package) when it is a version-control checkout, which an update would wipe,
     * or when its folder is not named after its slug (e.g. jpkcom-foo-main from a
     * GitHub source download): the release ZIP unpacks into jpkcom-foo/, so an
     * update would delete the renamed folder and leave the active plugin missing.
     *
     * The cache is tied to the registry, because copies register one by one
     * during init and a transient read can happen in between.
     *
     * @return array<string, array{slug: string, folder: string, file: string, version: string, manifest: string, frozen: bool}>
     */
    private function members(): array {
        $registry  = $this->registry();
        $signature = implode( separator: '|', array: array_column( array: $registry, column_key: 'id' ) );

        if ( null !== $this->members && $signature === $this->members_signature ) {
            return $this->members;
        }

        $members = [];

        foreach ( $registry as $entry ) {
            $path = WP_PLUGIN_DIR . '/' . $entry['file'];
            if ( ! file_exists( $path ) ) {
                continue;
            }
            $folder = dirname( path: $entry['file'] );
            $members[ $entry['file'] ] = [
                'slug'     => $entry['slug'],
                'folder'   => $folder,
                'file'     => $entry['file'],
                'version'  => $entry['version'],
                'manifest' => $entry['manifest'],
                'frozen'   => $folder !== $entry['slug'] || $this->is_vcs_checkout( dirname( path: $path ) ),
            ];
        }

        foreach ( $this->discovered() as $file => $member ) {
            if ( isset( $members[ $file ] ) || $this->is_plugin_loaded( $file ) ) {
                continue;
            }
            $members[ $file ] = $member;
        }

        $this->members_signature = $signature;

        return $this->members = $members;
    }

    /**
     * Whether a plugin's main file was actually included in this request.
     *
     * Asks PHP, not the active_plugins option: `wp --skip-plugins=<slug>` keeps
     * the option but never loads the file, and a plugin listed as active but not
     * loaded must be served like an inactive one.
     *
     * @param string $file Plugin file relative to the plugins directory.
     * @return bool
     */
    private function is_plugin_loaded( string $file ): bool {
        $path = WP_PLUGIN_DIR . '/' . $file;
        $real = realpath( $path );
        $seen = get_included_files();

        return in_array( needle: $path, haystack: $seen, strict: true )
            || ( false !== $real && in_array( needle: $real, haystack: $seen, strict: true ) );
    }

    /**
     * Whether a plugin directory is a version-control checkout.
     *
     * Updating one replaces the directory and deletes the working tree, so such a
     * member is never offered an update. Same rule and filter as core's
     * automatic updater.
     *
     * @param string $dir Absolute plugin directory.
     * @return bool
     */
    private function is_vcs_checkout( string $dir ): bool {
        $is_vcs = false;
        foreach ( [ '.git', '.svn', '.hg', '.bzr' ] as $vcs_dir ) {
            if ( is_dir( $dir . '/' . $vcs_dir ) ) {
                $is_vcs = true;
                break;
            }
        }

        return (bool) apply_filters( 'automatic_updates_is_vcs_checkout', $is_vcs, $dir );
    }

    /**
     * Drop the plugin scan and member cache after a plugin was deleted or updated.
     *
     * @return void
     */
    public function forget_members(): void {
        $this->discovered = null;
        $this->members    = null;
    }

    /**
     * Find a member by its folder name (the slug WordPress uses for it).
     *
     * @param string $folder Plugin folder.
     * @return array{slug: string, folder: string, file: string, version: string, manifest: string, frozen: bool}|null
     */
    private function member_by_folder( string $folder ): ?array {
        foreach ( $this->members() as $member ) {
            if ( $member['folder'] === $folder ) {
                return $member;
            }
        }

        return null;
    }

    /**
     * A manifest value as plain text, or the fallback when it is not a scalar.
     *
     * Manifests are remote data and are cached by every copy since 1.4.0; an
     * object or array where a string belongs must not turn into a fatal error on
     * every admin page.
     *
     * @param mixed  $value    Manifest value.
     * @param string $fallback Value for a missing or non-scalar field.
     * @return string
     */
    private function text( mixed $value, string $fallback = '' ): string {
        return ( is_string( value: $value ) || is_int( value: $value ) || is_float( value: $value ) )
            ? sanitize_text_field( (string) $value )
            : $fallback;
    }

    /**
     * A manifest value as a sanitised URL, or the fallback.
     *
     * @param mixed  $value    Manifest value.
     * @param string $fallback Value for a missing or non-string field.
     * @return string
     */
    private function url( mixed $value, string $fallback = '' ): string {
        return is_string( value: $value ) ? esc_url_raw( $value ) : $fallback;
    }

    /**
     * Manifest cache key, shared with every copy back to 1.4.0.
     *
     * @param string $slug Plugin slug.
     * @return string
     */
    private function cache_key_for( string $slug ): string {
        return 'jpk_git_update_' . md5( string: $slug );
    }

    /**
     * Whether rendering update data may fetch an uncached manifest.
     *
     * Only for users who can act on updates, cron and WP-CLI. An anonymous front
     * end request never fetches; installs, updates and the details modal always
     * may (they pass $context 'action').
     *
     * @return bool
     */
    private function may_fetch_on_read(): bool {
        return $this->is_background()
            || ( function_exists( 'current_user_can' ) && current_user_can( 'update_plugins' ) );
    }

    /**
     * Whether this request is cron or WP-CLI, where latency does not matter.
     *
     * @return bool
     */
    private function is_background(): bool {
        return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
    }

    /**
     * Fetch and decode a remote manifest file.
     *
     * Uses a locking mechanism to prevent race conditions when multiple requests
     * try to fetch the manifest simultaneously.
     *
     * @param string $slug         Plugin slug.
     * @param string $manifest_url Manifest URL.
     * @param string $context      'read' (rendering update data: may be denied a
     *                             fetch, counts against the request budget of an
     *                             interactive request, honours the host breaker)
     *                             or 'action' (install, update, details modal).
     * @return ?object Decoded manifest or null on failure.
     */
    private function get_remote_manifest( string $slug, string $manifest_url, string $context = 'action' ): ?object {
        $cache_key = $this->cache_key_for( $slug );
        $remote    = get_site_transient( $cache_key );

        if ( is_object( value: $remote ) ) {
            return $remote;
        }

        $is_read = 'read' === $context;

        if ( $is_read && ( ! $this->may_fetch_on_read() || ( ! $this->is_background() && $this->read_fetches >= self::READ_FETCH_BUDGET ) ) ) {
            return null;
        }

        // Negative caching: if a recent fetch failed, stay quiet for a while.
        // Without this guard a slow or unreachable manifest host costs one
        // blocking request per plugin on every admin request that reads the
        // update transient. The host breaker only holds back rendering; an
        // update someone just clicked still tries the network.
        // Cron and WP-CLI ignore a breaker tripped only by a slow answer: they
        // are not latency-bound, and honouring it would let them refresh one
        // manifest per run on a slow host.
        $host_fail = $is_read ? get_site_transient( self::HOST_FAIL_KEY ) : false;
        if ( get_site_transient( $cache_key . '_fail' ) || ( $host_fail && ! ( 'slow' === $host_fail && $this->is_background() ) ) ) {
            return null;
        }

        // Race condition prevention: Check if another request is already fetching
        $lock_key = $cache_key . '_lock';
        if ( get_site_transient( $lock_key ) ) {
            // Another request is fetching, return null to avoid duplicate API calls
            return null;
        }

        // Acquire lock for 30 seconds
        set_site_transient( $lock_key, true, 30 );

        if ( $is_read ) {
            ++$this->read_fetches;
        }

        $started  = microtime( true );
        $response = wp_safe_remote_get( $manifest_url, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ] );
        $elapsed  = microtime( true ) - $started;

        // Release lock
        delete_site_transient( $lock_key );

        // Error handling with logging
        if ( is_wp_error( $response ) ) {
            $this->log( sprintf( 'Failed to fetch manifest from %s - Error: %s', $manifest_url, $response->get_error_message() ) );
            $this->remember_failure( $cache_key, true );
            return null;
        }

        $response_code = (int) wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            $this->log( sprintf( 'Invalid response code %d from %s', $response_code, $manifest_url ) );
            // A 404 is about this plugin; a 429 or 5xx is about the host.
            $this->remember_failure( $cache_key, 429 === $response_code || $response_code >= 500 );
            return null;
        }

        $remote = json_decode( json: wp_remote_retrieve_body( $response ) );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_object( value: $remote ) ) {
            $this->log( sprintf( 'JSON decode error: %s', json_last_error_msg() ) );
            $this->remember_failure( $cache_key, false );
            return null;
        }

        if ( $is_read && ! $this->is_background() && $elapsed > self::SLOW_FETCH_SECONDS ) {
            set_site_transient( self::HOST_FAIL_KEY, 'slow', 15 * MINUTE_IN_SECONDS );
        }

        // Spread the expiry per plugin so the whole family does not go cold in
        // the same request once a day.
        set_site_transient( $cache_key, $remote, DAY_IN_SECONDS + ( crc32( $slug ) % ( 6 * HOUR_IN_SECONDS ) ) );

        return $remote;
    }

    /**
     * Back off after a failed manifest fetch.
     *
     * Keeps a short-lived marker so the next requests skip the remote call
     * instead of re-running it (and its timeout) on every page load.
     *
     * @param string $cache_key Manifest cache key.
     * @param bool   $host_wide Whether the failure concerns the host, not the plugin.
     * @return void
     */
    private function remember_failure( string $cache_key, bool $host_wide ): void {
        set_site_transient( $cache_key . '_fail', true, HOUR_IN_SECONDS );
        if ( $host_wide ) {
            set_site_transient( self::HOST_FAIL_KEY, 'error', 15 * MINUTE_IN_SECONDS );
        }
    }

    /**
     * The manifest's download URL, if it is a release asset of this very plugin.
     *
     * Anything else is never handed to WordPress, so the checksum gate always
     * recognises what this class emits.
     *
     * @param object $remote Manifest.
     * @param string $slug   Plugin slug.
     * @return string Validated URL or ''.
     */
    private function package_url( object $remote, string $slug ): string {
        $url = is_string( value: $remote->download_url ?? null ) ? $remote->download_url : '';
        if ( 1 !== preg_match( self::PACKAGE_PATTERN, $url, $match ) || $match[1] !== $slug ) {
            return '';
        }

        if ( ! isset( $this->validated_urls[ $url ] ) ) {
            $this->validated_urls[ $url ] = (bool) wp_http_validate_url( $url );
        }

        return $this->validated_urls[ $url ] ? $url : '';
    }

    /**
     * Icon URLs from the manifest as an array (core indexes it with []).
     *
     * @param object $remote Manifest.
     * @param string $slug   Plugin slug.
     * @return array<string, string>
     */
    private function icons( object $remote, string $slug ): array {
        $icons = [];
        $raw   = $remote->icons ?? null;
        if ( is_object( value: $raw ) || is_array( value: $raw ) ) {
            foreach ( (array) $raw as $key => $url ) {
                if ( is_string( value: $url ) && str_starts_with( haystack: $url, needle: 'https://' ) ) {
                    $icons[ sanitize_key( (string) $key ) ] = esc_url_raw( $url );
                }
            }
        } elseif ( is_string( value: $remote->icon ?? null ) && str_starts_with( haystack: $remote->icon, needle: 'https://' ) ) {
            $icons['default'] = esc_url_raw( $remote->icon );
        }

        if ( [] === $icons ) {
            $icons['default'] = esc_url_raw( "https://s.w.org/plugins/geopattern-icon/{$slug}.svg" );
        }

        return $icons;
    }

    /**
     * Provide detailed plugin info in the “View Details” modal.
     *
     * Answers for every family member, so the modal of an inactive plugin works
     * and never falls through to wordpress.org, where a squatted slug would show
     * someone else's plugin.
     *
     * @param mixed  $result Default response.
     * @param mixed  $action Current action.
     * @param mixed  $args   API request arguments.
     * @return mixed
     */
    public function plugin_info( mixed $result, mixed $action, mixed $args ): mixed {
        if ( false !== $result || 'plugin_information' !== $action || ! is_object( value: $args ) || empty( $args->slug ) || ! is_string( value: $args->slug ) ) {
            return $result;
        }

        if ( ! $this->is_leader() ) {
            return $result;
        }

        $member = $this->member_by_folder( $args->slug );
        if ( null === $member ) {
            return $result;
        }

        $remote = $this->get_remote_manifest( $member['slug'], $member['manifest'] );
        if ( ! $remote ) {
            return new \WP_Error(
                'manifest_unavailable',
                __( 'Security verification failed: the update manifest could not be loaded, so the package cannot be verified.', 'jpkcom-acf-shortcode-enable' )
            );
        }

        $sections     = [];
        $raw_sections = is_object( value: $remote->sections ?? null ) ? $remote->sections : new \stdClass();
        foreach ( ['description','installation','changelog','faq'] as $key ) {
            if ( ! empty( $raw_sections->$key ) && is_string( value: $raw_sections->$key ) ) {
                $sections[$key] = wp_kses_post( trim( string: $raw_sections->$key ) );
            }
        }

        if ( ! empty( $remote->readme_html ) && is_string( value: $remote->readme_html ) ) {
            $sections['readme'] = wp_kses_post( $remote->readme_html );
        }

        $info = new \stdClass();
        $info->name             = $this->text( $remote->name ?? null );
        $info->display_name     = $this->text( $remote->display_name ?? null, $info->name );
        // The installed folder, never the manifest's slug: core matches it
        // against the update transient and the folder on disk.
        $info->slug             = $member['folder'];
        // Same version as the update row; a frozen member gets no download link
        // below, so the modal offers no install button.
        $info->version          = $this->text( $remote->version ?? null, $member['version'] );
        $info->author           = wp_kses_post( $this->text( $remote->author ?? null ) );
        $info->author_profile   = $this->url( $remote->author_profile ?? null );

        $contributors = $remote->contributors ?? [];

        if ( is_object( value: $contributors ) ) {
            $contributors = (array) $contributors;
        } elseif ( is_string( value: $contributors ) ) {
            $contributors = [$contributors];
        } elseif ( ! is_array( value: $contributors ) ) {
            $contributors = [];
        }

        $wp_contributors = [];
        foreach ( $contributors as $key => $value ) {
            if ( is_string( value: $value ) ) {
                $wp_contributors[$value] = [
                    'display_name' => sanitize_text_field( $value ),
                    // Positional args only: 'values' is sprintf()'s variadic
                    // parameter, and PHP rejects named arguments bound to a
                    // variadic with ArgumentCountError.
                    'profile'      => sprintf( 'https://profiles.wordpress.org/%s', $value ),
                    'avatar'       => sprintf( 'https://wordpress.org/grav-redirect.php?user=%s&s=36', $value ),
                ];
            } elseif ( is_array( value: $value ) || is_object( value: $value ) ) {
                $value = (array) $value;
                $wp_contributors[$key] = [
                    // `??` only catches null/missing; an empty-string display_name falls through to WP core's own username fallback.
                    'display_name' => $this->text( $value['display_name'] ?? null, (string) $key ),
                    'profile'      => $this->url( $value['profile'] ?? null, sprintf( 'https://profiles.wordpress.org/%s', $key ) ),
                    'avatar'       => $this->url( $value['avatar'] ?? null, sprintf( 'https://wordpress.org/grav-redirect.php?user=%s&s=36', $key ) ),
                ];
            }
        }

        $info->contributors     = $wp_contributors;

        $info->homepage         = $this->url( $remote->homepage ?? null );
        $info->download_link    = $member['frozen'] ? '' : $this->package_url( $remote, $member['slug'] );
        // Fallbacks only apply when the manifest omits these fields; keep them
        // in sync with the plugin header (Requires at least / Tested up to).
        $info->requires         = $this->text( $remote->requires ?? null, '6.9' );
        $info->tested           = $this->text( $remote->tested ?? null, '7.0' );
        $info->requires_php     = $this->text( $remote->requires_php ?? null, '8.3' );
        $info->license          = $this->text( $remote->license ?? null, 'GPL-2.0-or-later' );
        $info->license_uri      = $this->url( $remote->license_uri ?? null, 'https://www.gnu.org/licenses/gpl-2.0.html' );

        $tags = $remote->tags ?? [];
        if ( ! is_array( value: $tags ) ) {
            $tags = [$tags];
        }
        $info->tags             = array_map( callback: 'sanitize_text_field', array: array_map( callback: 'trim', array: array_map( callback: 'strval', array: array_filter( array: $tags, callback: 'is_scalar' ) ) ) );

        $info->network          = (bool) ( $remote->network ?? false );
        // Resolve once: the previous form applied `?? []` to the is_array()
        // check but then passed $remote->requires_plugins straight to
        // array_map(), so a manifest omitting the field hit array_map(null)
        // and fatalled the "View Details" modal with a TypeError.
        $requires_plugins       = $remote->requires_plugins ?? [];
        $info->requires_plugins = is_array( $requires_plugins ) ? array_map( 'sanitize_text_field', array_map( 'strval', array_filter( $requires_plugins, 'is_scalar' ) ) ) : [];
        $info->text_domain      = $this->text( $remote->text_domain ?? null );
        $info->domain_path      = $this->text( $remote->domain_path ?? null );
        $info->last_updated     = $this->text( $remote->last_updated ?? null );
        $info->sections         = $sections;

        // Sanitize banner URLs
        $banners = $remote->banners ?? [];
        $info->banners = [];
        foreach ( ( is_object( value: $banners ) || is_array( value: $banners ) ) ? (array) $banners : [] as $key => $url ) {
            if ( is_string( value: $url ) && wp_http_validate_url( $url ) ) {
                $info->banners[ sanitize_key( (string) $key ) ] = esc_url_raw( $url );
            }
        }

        $info->icons = $this->icons( $remote, $member['slug'] );

        return $info;
    }

    /**
     * Inject update information for every family member.
     *
     * Runs on every read of the update_plugins site transient. Entries are built
     * fresh on each read (so the package that strip_packages() removed before
     * storage is back for the upgrader) and never carry `autoupdate` (core would
     * treat it as a forced auto-update) or `translations` (installed unverified).
     *
     * @param mixed $transient WordPress transient data.
     * @return mixed
     */
    public function check_update( mixed $transient ): mixed {
        if ( ! $this->is_leader() ) {
            return $transient;
        }

        // Defensive initialisation (WordPress may pass false on first run). Only
        // response and no_update: writing `checked` or `last_checked` would make
        // core believe it had already asked wordpress.org and skip its own check.
        if ( ! is_object( value: $transient ) ) {
            $transient = new \stdClass();
        }
        if ( ! isset( $transient->response ) || ! is_array( value: $transient->response ) ) {
            $transient->response = [];
        }
        if ( ! isset( $transient->no_update ) || ! is_array( value: $transient->no_update ) ) {
            $transient->no_update = [];
        }

        foreach ( $this->members() as $file => $member ) {
            $remote = $this->get_remote_manifest( $member['slug'], $member['manifest'], 'read' );

            if ( ! $remote ) {
                // No manifest at hand: keep whatever is there, but drop an update
                // row the installed version has already caught up with (for
                // example after a deploy), so it does not linger uninstallable.
                $stale = $transient->response[ $file ] ?? null;
                if ( $member['frozen'] || ( is_object( value: $stale ) && isset( $stale->new_version ) && is_string( value: $stale->new_version )
                    && version_compare( version1: $stale->new_version, version2: $member['version'], operator: '<=' ) ) ) {
                    unset( $transient->response[ $file ] );
                }
                if ( $member['frozen'] ) {
                    $transient->no_update[ $file ] = $this->entry( $member, $member['version'], '', [], '', '', '' );
                }
                continue;
            }

            $new_version = is_string( value: $remote->version ?? null ) ? sanitize_text_field( $remote->version ) : '';
            if ( '' === $new_version ) {
                continue;
            }

            $tested       = $this->text( $remote->tested ?? null );
            $requires     = $this->text( $remote->requires ?? null );
            $requires_php = $this->text( $remote->requires_php ?? null );
            $icons        = $this->icons( $remote, $member['slug'] );

            if ( version_compare( version1: $member['version'], version2: $new_version, operator: '<' ) ) {
                // A frozen member (version-control checkout, renamed folder) is
                // told about the release but gets no package: core then shows
                // "Automatic update is unavailable for this plugin", and the
                // working tree or the renamed folder stays untouched.
                $package = $member['frozen'] ? '' : $this->package_url( $remote, $member['slug'] );
                if ( '' === $package && ! $member['frozen'] ) {
                    $this->log( sprintf( 'Invalid download URL in manifest for %s', $member['slug'] ) );
                    continue; // Never offer an update the checksum gate would not recognise.
                }
                unset( $transient->no_update[ $file ] );
                $transient->response[ $file ] = $this->entry( $member, $new_version, $package, $icons, $tested, $requires, $requires_php );
            } else {
                unset( $transient->response[ $file ] );
                $transient->no_update[ $file ] = $this->entry( $member, $new_version, '', $icons, $tested, $requires, $requires_php );
            }
        }

        return $transient;
    }

    /**
     * Build one update entry.
     *
     * @param array{slug: string, folder: string, file: string, version: string, manifest: string, frozen: bool} $member Member.
     * @param string                $new_version  Manifest version.
     * @param string                $package      Validated package URL or ''.
     * @param array<string, string> $icons        Icons.
     * @param string                $tested       Tested up to.
     * @param string                $requires     Requires at least.
     * @param string                $requires_php Requires PHP.
     * @return object
     */
    private function entry( array $member, string $new_version, string $package, array $icons, string $tested, string $requires, string $requires_php ): object {
        return (object) [
            'slug'         => $member['folder'],
            'plugin'       => $member['file'],
            'new_version'  => $new_version,
            'url'          => sprintf( 'https://github.com/JPKCom/%s', $member['slug'] ),
            'package'      => $package,
            'tested'       => $tested,
            'requires'     => $requires,
            'requires_php' => $requires_php,
            'icons'        => $icons,
        ];
    }

    /**
     * Remove JPKCom package URLs before the update transient is stored.
     *
     * The leader re-injects them on every read. A request in which no 1.5+ copy is
     * loaded (every JPKCom plugin deactivated, WP-CLI --skip-plugins, a multisite
     * main site without JPKCom code) then finds an empty package, which core
     * refuses ("Automatic update is unavailable"), instead of a URL it would
     * install without any checksum. Best effort: a third party that writes the
     * option directly bypasses this filter.
     *
     * @param mixed $value Transient value about to be stored.
     * @return mixed
     */
    public function strip_packages( mixed $value ): mixed {
        if ( ! $this->is_leader() || ! is_object( value: $value ) || empty( $value->response ) || ! is_array( value: $value->response ) ) {
            return $value;
        }

        $members = $this->members();
        $value   = clone $value;
        foreach ( $value->response as $file => $entry ) {
            if ( ! is_object( value: $entry ) ) {
                continue;
            }
            $package = is_string( value: $entry->package ?? null ) ? $entry->package : '';
            if ( isset( $members[ $file ] ) || ( '' !== $package && 1 === preg_match( self::PACKAGE_PATTERN, $package ) ) ) {
                // Clone: the caller may keep using the object it passed in.
                $entry          = clone $entry;
                $entry->package = '';
                $value->response[ $file ] = $entry;
            }
        }

        return $value;
    }

    /**
     * Clear cached manifests after an update.
     *
     * Single updates pass `plugin`, bulk updates (AJAX, update-core, WP-CLI) pass
     * `plugins`.
     *
     * @param mixed $upgrader WordPress upgrader instance.
     * @param mixed $options  Upgrade options.
     * @return void
     */
    public function clear_cache( mixed $upgrader, mixed $options ): void {
        if ( ! $this->is_leader()
            || ! is_array( value: $options )
            || ( $options['action'] ?? '' ) !== 'update'
            || ( $options['type'] ?? '' ) !== 'plugin' ) {
            return;
        }

        $files = [];
        if ( isset( $options['plugin'] ) && is_string( value: $options['plugin'] ) ) {
            $files[] = $options['plugin'];
        }
        if ( isset( $options['plugins'] ) && is_array( value: $options['plugins'] ) ) {
            $files = array_merge( $files, array_filter( $options['plugins'], 'is_string' ) );
        }

        $members = $this->members();
        foreach ( $files as $file ) {
            if ( isset( $members[ $file ] ) ) {
                delete_site_transient( $this->cache_key_for( $members[ $file ]['slug'] ) );
            }
        }

        $this->forget_members();
    }

    /**
     * Verify download checksum before installation.
     *
     * Ownership is decided first: a package belongs to the family when the
     * upgrader names a member plugin (`$hook_extra['plugin']`, set for every
     * update) or when its URL is a JPKCom release asset (installs, which carry no
     * plugin name). Anything else passes through untouched. For a family package
     * nothing is trusted without a hash match against the manifest, including a
     * file an earlier filter already returned.
     *
     * @param mixed $reply      Whether to bail without returning the package (default false).
     * @param mixed $package    The package file name or URL.
     * @param mixed $upgrader   The WP_Upgrader instance.
     * @param mixed $hook_extra Extra arguments passed to hooked filters.
     * @return mixed Path to the verified package, $reply when the package is not
     *               ours, WP_Error if verification fails.
     */
    public function verify_download_checksum( mixed $reply, mixed $package, mixed $upgrader = null, mixed $hook_extra = [] ): mixed {
        if ( ! $this->is_leader() ) {
            return $reply;
        }

        $members  = $this->members();
        $slug     = '';
        $manifest = '';
        $url      = '';
        $plugin   = is_array( value: $hook_extra ) && is_string( value: $hook_extra['plugin'] ?? null ) ? $hook_extra['plugin'] : '';

        if ( '' !== $plugin && isset( $members[ $plugin ] ) && $members[ $plugin ]['frozen'] ) {
            // Never update a version-control checkout or a renamed folder, even
            // through a stale row someone else wrote. Core's own wording.
            if ( is_wp_error( $reply ) ) {
                return $reply;
            }
            $strings = is_object( value: $upgrader ) && isset( $upgrader->strings ) && is_array( value: $upgrader->strings ) ? $upgrader->strings : [];
            return new \WP_Error( 'no_package', is_string( value: $strings['no_package'] ?? null ) ? $strings['no_package'] : 'Update package not available.' );
        } elseif ( '' !== $plugin && isset( $members[ $plugin ] ) ) {
            // An update of a member: installs what the manifest vouches for.
            $slug     = $members[ $plugin ]['slug'];
            $manifest = $members[ $plugin ]['manifest'];
        } elseif ( '' !== $plugin && $this->runs_old_copy( $plugin ) ) {
            // An older copy inside that plugin serves it against its own cache,
            // at priority 10. Step in only when it declined to supply a package
            // (unknown URL, or a generation without a gate), and then verify the
            // URL that is actually offered.
            if ( false !== $reply || ! is_string( value: $package ) || '' === $package ) {
                return $reply;
            }
            $slug     = basename( path: $plugin, suffix: '.php' );
            $manifest = sprintf( self::MANIFEST_URL_FORMAT, $slug );
            $url      = $package;
        } elseif ( is_string( value: $package ) && '' !== $package && is_a( $upgrader, 'Plugin_Upgrader' ) ) {
            // An install (no plugin name in hook_extra). Claimed by the file
            // name, as 1.4 did, not by one spelling of the release URL: GitHub
            // serves the same asset under releases/latest/download/, any case
            // of owner, repo and host, www., http and with a query string, and
            // every one of those must meet the checksum. Claiming too much is
            // safe; a ZIP that does not match the manifest is refused.
            $scheme  = strtolower( (string) wp_parse_url( $package, PHP_URL_SCHEME ) );
            $asset   = strtolower( rawurldecode( basename( path: (string) wp_parse_url( $package, PHP_URL_PATH ) ) ) );
            $matched = in_array( needle: $scheme, haystack: [ 'http', 'https' ], strict: true ) ? preg_match( self::ASSET_PATTERN, $asset, $match ) : 0;
            if ( false === $matched ) {
                return $this->checksum_error( '(pattern error)' );
            }
            if ( 1 !== $matched ) {
                return $reply;
            }
            $slug = $match[1];
            $file = $slug . '/' . $slug . '.php';
            if ( false !== $reply && $this->runs_old_copy( $file ) ) {
                // The plugin's older copy supplied (and verified) the file.
                return $reply;
            }
            $manifest = isset( $members[ $file ] ) ? $members[ $file ]['manifest'] : sprintf( self::MANIFEST_URL_FORMAT, $slug );
            $url      = $package;
        } else {
            return $reply;
        }

        if ( is_wp_error( $reply ) ) {
            return $reply; // An earlier filter already refused this package.
        }

        // Fail closed. A manifest without a checksum is an error, not a legacy
        // case: anyone able to tamper with the manifest could otherwise disable
        // the integrity check by dropping a single field.
        $remote = $this->get_remote_manifest( $slug, $manifest );
        if ( ! $remote ) {
            return new \WP_Error(
                'manifest_unavailable',
                __( 'Security verification failed: the update manifest could not be loaded, so the package cannot be verified.', 'jpkcom-acf-shortcode-enable' )
            );
        }

        if ( empty( $remote->checksum_sha256 ) || ! is_string( value: $remote->checksum_sha256 ) ) {
            return new \WP_Error(
                'checksum_missing',
                __( 'Security verification failed: the update manifest contains no SHA-256 checksum, so the package cannot be verified.', 'jpkcom-acf-shortcode-enable' )
            );
        }

        $expected_hash = strtolower( trim( $remote->checksum_sha256 ) );

        if ( false !== $reply ) {
            // Someone already supplied the package: an older copy's verified temp
            // file, or a third party's cache. Either way, hash what will be
            // installed. Not ours to delete on a mismatch.
            if ( ! is_string( value: $reply ) || ! is_file( $reply ) || ! is_readable( $reply ) ) {
                return $this->checksum_error( '(invalid package)', $expected_hash );
            }
            $calculated_hash = hash_file( 'sha256', $reply );
            if ( ! is_string( $calculated_hash ) || ! hash_equals( $expected_hash, $calculated_hash ) ) {
                return $this->checksum_error( is_string( $calculated_hash ) ? $calculated_hash : '(hash failed)', $expected_hash );
            }
            return $reply;
        }

        // An update installs what the manifest vouches for, whatever URL the
        // stored transient holds (it is empty after strip_packages()). An install
        // by URL downloads that URL and must match the same checksum.
        if ( '' === $url ) {
            $url = $this->package_url( $remote, $slug );
            if ( '' === $url ) {
                return new \WP_Error(
                    'manifest_unavailable',
                    __( 'Security verification failed: the update manifest could not be loaded, so the package cannot be verified.', 'jpkcom-acf-shortcode-enable' )
                );
            }
        }

        // Download package temporarily
        $temp_file = download_url( $url );
        if ( is_wp_error( $temp_file ) ) {
            return new \WP_Error(
                'download_failed',
                sprintf(
                    /* translators: %s: Error message from the failed download */
                    __( 'Download failed: %s', 'jpkcom-acf-shortcode-enable' ),
                    $temp_file->get_error_message()
                )
            );
        }

        // Calculate SHA256 hash
        $calculated_hash = hash_file( 'sha256', $temp_file );

        // Verify checksum (timing-safe).
        if ( ! is_string( $calculated_hash ) || ! hash_equals( $expected_hash, $calculated_hash ) ) {
            wp_delete_file( $temp_file );
            return $this->checksum_error( is_string( $calculated_hash ) ? $calculated_hash : '(hash failed)', $expected_hash );
        }

        $this->log( sprintf( 'Checksum verification successful for %s', $slug ) );

        // Hand the *verified* file to WP_Upgrader instead of returning $reply.
        //
        // Returning false here would let WP_Upgrader::download_package() fetch
        // the package a second time and install those bytes — the ones we just
        // hashed would be discarded, so the check verified something other than
        // what gets installed (TOCTOU), at the cost of a duplicate download.
        // Any non-false return value is used as the package, and because the
        // returned path differs from $package, WP_Upgrader::run() sets
        // $delete_package = true and removes this temp file after unpacking.
        return $temp_file;
    }

    /**
     * Whether a JPKCom plugin's own pre-1.5 updater copy runs in this request.
     *
     * @param string $file Plugin file.
     * @return bool
     */
    private function runs_old_copy( string $file ): bool {
        return isset( $this->discovered()[ $file ] ) && $this->is_plugin_loaded( $file ) && ! $this->is_registered_file( $file );
    }

    /**
     * Whether a plugin file belongs to a registered (1.5+) copy.
     *
     * @param string $file Plugin file.
     * @return bool
     */
    private function is_registered_file( string $file ): bool {
        foreach ( $this->registry() as $entry ) {
            if ( $entry['file'] === $file ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checksum mismatch error.
     *
     * @param string $calculated Calculated hash or a placeholder.
     * @param string $expected   Expected hash.
     * @return \WP_Error
     */
    private function checksum_error( string $calculated, string $expected = '' ): \WP_Error {
        $error_msg = sprintf(
            /* translators: 1: expected SHA-256 hash, 2: calculated SHA-256 hash */
            __( 'Security verification failed: Plugin checksum mismatch. Expected: %1$s, Got: %2$s', 'jpkcom-acf-shortcode-enable' ),
            $expected,
            $calculated
        );

        $this->log( $error_msg );

        return new \WP_Error( 'checksum_mismatch', $error_msg );
    }

    /**
     * Write a log line in WP_DEBUG mode.
     *
     * @param string $message Message.
     * @return void
     */
    private function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'JPKCom Plugin Updater: ' . $message );
        }
    }
}
