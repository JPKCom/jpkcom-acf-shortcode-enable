<?php
/**
 * JPKCom Plugin Updater – GitHub Self-Hosted Updates
 *
 * This class provides a secure, self-hosted update mechanism for WordPress plugins
 * hosted on GitHub. It integrates with the WordPress plugin update system and provides
 * comprehensive security features including:
 *
 * - SHA256 checksum verification of downloaded packages
 * - URL validation and sanitization of all remote data
 * - Race condition prevention for manifest fetching
 * - Comprehensive error logging in WP_DEBUG mode
 * - Transient caching with 24-hour TTL
 * - Backward compatibility with manifests without checksums
 *
 * Security Features:
 * - All URLs are validated using wp_http_validate_url() before use
 * - All manifest data is sanitized before display
 * - Download packages are verified against SHA256 checksum from manifest
 * - Failed verifications prevent installation and log errors
 *
 * Namespace: JPKComAcfShortcodeEnableGitUpdate
 * PHP Version: 8.3+
 * WordPress Version: 6.8+
 *
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
 * Handles plugin updates from a GitHub-hosted JSON manifest.
 *
 * @package JPKComAcfShortcodeEnableGitUpdate
 */
final class JPKComGitPluginUpdater {

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

    /** @var bool Whether caching is enabled */
    private bool $cache_enabled = true;

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
        if ( version_compare( version1: PHP_VERSION, version2: '8.3', operator: '<' ) || version_compare( version1: $wp_version, version2: '6.8', operator: '<' ) ) {
            return;
        }

        // Security: Validate and sanitize manifest URL
        $manifest_url = esc_url_raw( $manifest_url );
        if ( ! wp_http_validate_url( $manifest_url ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    'JPKCom Plugin Updater: Invalid manifest URL provided: %s',
                    $manifest_url
                ) );
            }
            return; // Invalid URL, abort initialization
        }

        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = dirname( path: plugin_basename( $plugin_file ) );
        $this->current_version = $current_version;
        $this->manifest_url    = $manifest_url;
        $this->cache_key       = 'jpk_git_update_' . md5( string: $this->plugin_slug );

        // Hook into WordPress update system
        add_filter( 'plugins_api', [$this, 'plugin_info'], 20, 3 );
        add_filter( 'site_transient_update_plugins', [$this, 'check_update'] );
        add_action( 'upgrader_process_complete', [$this, 'clear_cache'], 10, 2 );
        add_filter( 'upgrader_pre_download', [$this, 'verify_download_checksum'], 10, 3 );
        // Note: 'plugins_api_result' filter is not a standard WordPress filter, keeping for backward compatibility
        // add_filter( 'plugins_api_result', [$this, 'plugin_info'], 20, 3 );

    }

    /**
     * Fetch and decode the remote manifest file.
     *
     * Uses a locking mechanism to prevent race conditions when multiple requests
     * try to fetch the manifest simultaneously.
     *
     * @return ?object Decoded manifest or null on failure.
     */
    private function get_remote_manifest(): ?object {
        $remote = get_transient( $this->cache_key );

        if ( false === $remote || ! $this->cache_enabled ) {
            // Race condition prevention: Check if another request is already fetching
            $lock_key = $this->cache_key . '_lock';
            if ( get_transient( $lock_key ) ) {
                // Another request is fetching, return null to avoid duplicate API calls
                return null;
            }

            // Acquire lock for 30 seconds
            set_transient( $lock_key, true, 30 );

            $response = wp_safe_remote_get( $this->manifest_url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ] );

            // Release lock
            delete_transient( $lock_key );

            // Error handling with logging
            if ( is_wp_error( $response ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        'JPKCom Plugin Updater: Failed to fetch manifest from %s - Error: %s',
                        $this->manifest_url,
                        $response->get_error_message()
                    ) );
                }
                return null;
            }

            $response_code = wp_remote_retrieve_response_code( $response );
            if ( $response_code !== 200 ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        'JPKCom Plugin Updater: Invalid response code %d from %s',
                        $response_code,
                        $this->manifest_url
                    ) );
                }
                return null;
            }

            $remote = json_decode( json: wp_remote_retrieve_body( $response ) );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        'JPKCom Plugin Updater: JSON decode error: %s',
                        json_last_error_msg()
                    ) );
                }
                return null;
            }

            set_transient( $this->cache_key, $remote, DAY_IN_SECONDS );
        }

        return is_object( value: $remote ) ? $remote : null;
    }

    /**
     * Provide detailed plugin info in the “View Details” modal.
     *
     * @param mixed  $result Default response.
     * @param string $action Current action.
     * @param object $args   API request arguments.
     * @return mixed
     */
    public function plugin_info( mixed $result, string $action, object $args ): mixed {
        if ( $action !== 'plugin_information' || empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $remote = $this->get_remote_manifest();
        if ( ! $remote ) {
            return $result;
        }

        $sections = [];
        foreach ( ['description','installation','changelog','faq'] as $key ) {
            if ( ! empty($remote->sections->$key ) ) {
                $sections[$key] = wp_kses_post( trim( string: $remote->sections->$key ) );
            }
        }

        if ( ! empty( $remote->readme_html ) ) {
            $sections['readme'] = wp_kses_post( $remote->readme_html );
        }

        $info = new \stdClass();
        $info->name             = sanitize_text_field( $remote->name ?? '' );
        $info->display_name     = sanitize_text_field( $remote->display_name ?? ( $remote->name ?? '' ) );
        $info->slug             = sanitize_title( $remote->slug ?? $this->plugin_slug );
        $info->version          = sanitize_text_field( $remote->version ?? $this->current_version );
        $info->author           = wp_kses_post( $remote->author ?? '' );
        $info->author_profile   = esc_url_raw( $remote->author_profile ?? '' );

        $contributors = $remote->contributors ?? [];

        if ( is_object( value: $contributors ) ) {
            $contributors = (array) $contributors;
        } elseif ( is_string( value: $contributors ) ) {
            $contributors = [$contributors];
        }

        $wp_contributors = [];
        foreach ( $contributors as $key => $value ) {
            if ( is_string( value: $value ) ) {
                $wp_contributors[$value] = [
                    'display_name' => sanitize_text_field( $value ),
                    'profile'      => sprintf( format: 'https://profiles.wordpress.org/%s', values: $value ),
                    'avatar'       => sprintf( format: 'https://wordpress.org/grav-redirect.php?user=%s&s=36', values: $value ),
                ];
            } elseif ( is_array( value: $value ) || is_object( value: $value ) ) {
                $value = (array) $value;
                $wp_contributors[$key] = [
                    // `??` only catches null/missing; an empty-string display_name falls through to WP core's own username fallback.
                    'display_name' => sanitize_text_field( $value['display_name'] ?? $key ),
                    'profile'      => $value['profile'] ?? sprintf( format: 'https://profiles.wordpress.org/%s', values: $key ),
                    'avatar'       => $value['avatar']  ?? sprintf( format: 'https://wordpress.org/grav-redirect.php?user=%s&s=36', values: $key ),
                ];
            }
        }

        $info->contributors     = $wp_contributors;

        $info->homepage         = esc_url_raw( $remote->homepage ?? '' );
        $info->download_link    = ( ! empty( $remote->download_url ) && wp_http_validate_url( $remote->download_url ) )
            ? esc_url_raw( $remote->download_url )
            : '';
        $info->requires         = sanitize_text_field( $remote->requires ?? '6.8' );
        $info->tested           = sanitize_text_field( $remote->tested ?? '6.9' );
        $info->requires_php     = sanitize_text_field( $remote->requires_php ?? '8.3' );
        $info->license          = sanitize_text_field( $remote->license ?? 'GPL-2.0-or-later' );
        $info->license_uri      = esc_url_raw( $remote->license_uri ?? 'https://www.gnu.org/licenses/gpl-2.0.html' );

        $tags = $remote->tags ?? [];
        if ( ! is_array( value: $tags ) ) {
            $tags = [$tags];
        }
        $info->tags             = array_map( callback: 'sanitize_text_field', array: array_map( callback: 'trim', array: $tags ) );

        $info->network          = (bool) ( $remote->network ?? false );
        $info->requires_plugins = is_array( $remote->requires_plugins ?? [] ) ? array_map( 'sanitize_text_field', $remote->requires_plugins ) : [];
        $info->text_domain      = sanitize_text_field( $remote->text_domain ?? '' );
        $info->domain_path      = sanitize_text_field( $remote->domain_path ?? '' );
        $info->last_updated     = sanitize_text_field( $remote->last_updated ?? '' );
        $info->sections         = $sections;

        // Sanitize banner URLs
        $banners = (array) ( $remote->banners ?? [] );
        $info->banners = [];
        foreach ( $banners as $key => $url ) {
            if ( wp_http_validate_url( $url ) ) {
                $info->banners[ sanitize_key( $key ) ] = esc_url_raw( $url );
            }
        }

        // Sanitize icon URLs
        if ( ! empty( $remote->icons ) ) {
            $icons = (array) $remote->icons;
            $info->icons = [];
            foreach ( $icons as $key => $url ) {
                if ( wp_http_validate_url( $url ) ) {
                    $info->icons[ sanitize_key( $key ) ] = esc_url_raw( $url );
                }
            }
        } elseif ( ! empty( $remote->icon ) && wp_http_validate_url( $remote->icon ) ) {
            $info->icons = [ 'default' => esc_url_raw( $remote->icon ) ];
        }

        return $info;
    }

    /**
     * Check for available plugin updates.
     *
     * @param object $transient WordPress transient data.
     * @return object
     */
    public function check_update( mixed $transient ): object {

        // Defensive initialisation (WordPress may pass false on first run)
        if ( ! is_object( value: $transient ) ) {
            $transient = new \stdClass();
            $transient->checked  = [];
            $transient->response = [];
        }

        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->get_remote_manifest();
        if ( ! $remote || empty( $remote->version ) ) {
            return $transient;
        }

        if ( version_compare( version1: $this->current_version, version2: $remote->version, operator: '<' ) ) {
            $plugin_basename = plugin_basename( $this->plugin_file );

            // Validate and sanitize download URL
            $download_url = $remote->download_url ?? '';
            if ( ! empty( $download_url ) && ! wp_http_validate_url( $download_url ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        'JPKCom Plugin Updater: Invalid download URL in manifest: %s',
                        $download_url
                    ) );
                }
                return $transient; // Invalid download URL, skip update
            }

            $update               = new \stdClass();
            $update->slug         = $this->plugin_slug;
            $update->new_version  = sanitize_text_field( $remote->version ?? '' );
            $update->package      = esc_url_raw( $download_url );
            $update->tested       = sanitize_text_field( $remote->tested ?? '' );
            $update->requires_php = sanitize_text_field( $remote->requires_php ?? '' );
            $update->plugin       = $plugin_basename;

            // Sanitize icon URL
            $icon_url = $remote->icons->default ?? $remote->icon ?? "https://s.w.org/plugins/geopattern-icon/{$this->plugin_slug}.svg";
            $update->icons = [
                'default' => esc_url_raw( $icon_url )
            ];

            $transient->response[ $plugin_basename ] = $update;
        } else {
            $plugin_basename = plugin_basename( $this->plugin_file );

            // Sanitize icon URL for no_update entry
            $icon_url = $remote->icons->default ?? $remote->icon ?? "https://s.w.org/plugins/geopattern-icon/{$this->plugin_slug}.svg";

            $transient->no_update[ $plugin_basename ] = (object) [
                'slug'         => $this->plugin_slug,
                'plugin'       => $plugin_basename,
                'new_version'  => sanitize_text_field( $remote->version ?? $this->current_version ),
                'package'      => '',
                'tested'       => sanitize_text_field( $remote->tested ?? '' ),
                'requires_php' => sanitize_text_field( $remote->requires_php ?? '' ),
                'icons'        => [
                    'default' => esc_url_raw( $icon_url )
                ]
            ];
        }

        return $transient;
    }

    /**
     * Clear cached manifest after a successful update.
     *
     * @param \WP_Upgrader $upgrader WordPress upgrader instance.
     * @param array        $options  Upgrade options.
     */
    public function clear_cache( \WP_Upgrader $upgrader, array $options ): void {
        // Ensure array keys exist before accessing
        if ( $this->cache_enabled
             && isset( $options['action'], $options['type'] )
             && $options['action'] === 'update'
             && $options['type'] === 'plugin' ) {
            delete_transient( $this->cache_key );
        }
    }

    /**
     * Verify download checksum before installation.
     *
     * This hook fires before WordPress downloads the plugin package, allowing us to
     * verify the SHA256 checksum from the manifest matches the actual download.
     *
     * @param bool        $reply   Whether to bail without returning the package (default false).
     * @param string      $package The package file name or URL.
     * @param \WP_Upgrader $upgrader The WP_Upgrader instance.
     * @return bool|\WP_Error True to proceed, WP_Error if verification fails.
     */
    public function verify_download_checksum( $reply, string $package, \WP_Upgrader $upgrader ) {
        if ( ! wp_http_validate_url( $package ) ) {
            return $reply;
        }

        // Determine whether this download belongs to our plugin. We prefer
        // an exact match against the manifest's download_url over the slug
        // heuristic: a manifest with a download_url that does not contain
        // the slug should still go through the checksum gate, not bypass it.
        $remote         = $this->get_remote_manifest();
        $is_our_package = false;
        if ( $remote && ! empty( $remote->download_url ) ) {
            $manifest_url = esc_url_raw( (string) $remote->download_url );
            if ( '' !== $manifest_url && $package === $manifest_url ) {
                $is_our_package = true;
            }
        }
        if ( ! $is_our_package && strpos( $package, $this->plugin_slug ) !== false ) {
            $is_our_package = true;
        }
        if ( ! $is_our_package ) {
            return $reply;
        }

        if ( ! $remote || empty( $remote->checksum_sha256 ) ) {
            // No checksum in manifest, allow download (backward compatibility).
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'JPKCom Plugin Updater: No checksum found in manifest, skipping verification' );
            }
            return $reply;
        }

        // Download package temporarily
        $temp_file = download_url( $package );
        if ( is_wp_error( $temp_file ) ) {
            return new \WP_Error(
                'download_failed',
                sprintf(
                    __( 'Download failed: %s', 'jpkcom-acf-shortcode-enable' ),
                    $temp_file->get_error_message()
                )
            );
        }

        // Calculate SHA256 hash
        $calculated_hash = hash_file( 'sha256', $temp_file );

        // Clean up temp file
        @unlink( $temp_file );

        // Verify checksum (timing-safe).
        $expected_hash = strtolower( trim( $remote->checksum_sha256 ) );
        if ( ! is_string( $calculated_hash ) || ! hash_equals( $expected_hash, $calculated_hash ) ) {
            $error_msg = sprintf(
                /* translators: 1: expected SHA-256 hash, 2: calculated SHA-256 hash */
                __( 'Security verification failed: Plugin checksum mismatch. Expected: %1$s, Got: %2$s', 'jpkcom-acf-shortcode-enable' ),
                $expected_hash,
                is_string( $calculated_hash ) ? $calculated_hash : '(hash failed)'
            );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'JPKCom Plugin Updater: ' . $error_msg );
            }

            return new \WP_Error( 'checksum_mismatch', $error_msg );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'JPKCom Plugin Updater: Checksum verification successful' );
        }

        return $reply;
    }
}
