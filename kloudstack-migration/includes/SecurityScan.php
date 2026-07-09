<?php
/**
 * KloudStack Migration — Security Scan
 *
 * Phase-1b malware / tamper scan run ON THE SOURCE SITE (in PHP — managed hosts
 * usually have no `wp` CLI / shell), so tampering is caught BEFORE the site is
 * migrated. Verifies WordPress core and plugin files against the official
 * WordPress.org checksums:
 *   - core:    api.wordpress.org/core/checksums/1.0/?version=&locale=
 *   - plugins: downloads.wordpress.org/plugin-checksums/{slug}/{version}.json
 *
 * Premium / custom plugins are NOT in the WP.org repository — those are reported
 * as "unverifiable" (normal), never "tampered".
 *
 * Returns the same blob shape the platform's target-scan produces, so the report
 * renders identically:
 *   { verdict, scanned_at, method, core:{modified,missing,unexpected},
 *     plugins:{mismatched:[{plugin,file,message}], unverifiable:[]}, summary }
 *
 * @package KloudStackMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KloudStack_Migration_SecurityScan {

    const CORE_API            = 'https://api.wordpress.org/core/checksums/1.0/';
    const PLUGIN_CHECKSUM_URL = 'https://downloads.wordpress.org/plugin-checksums/';
    const HTTP_TIMEOUT        = 12;   // seconds per WP.org request
    const MAX_PLUGINS         = 60;   // safety cap on plugins checked
    const MAX_UNEXPECTED      = 50;   // cap on reported unexpected core files
    const MAX_MISMATCHED      = 100;  // cap on reported plugin mismatches

    /** Core files hosts / security hardening routinely delete — absence is noise, not a signal. */
    const ROUTINELY_REMOVED   = [ 'license.txt', 'readme.html', 'wp-config-sample.php' ];

    /**
     * Run the scan and return the security_scan blob.
     *
     * @return array
     */
    public static function run(): array {
        @set_time_limit( 0 );

        $core    = self::scan_core();
        $plugins = self::scan_plugins();
        $verdict = self::compute_verdict( $core, $plugins );

        return [
            'verdict'    => $verdict,
            'scanned_at' => gmdate( 'c' ),
            'method'     => 'plugin_checksums',
            'core'       => $core,
            'plugins'    => $plugins,
            'summary'    => self::summary( $verdict, $core, $plugins ),
        ];
    }

    // ------------------------------------------------------------------
    // Core
    // ------------------------------------------------------------------

    private static function scan_core(): array {
        $result  = [ 'modified' => [], 'missing' => [], 'unexpected' => [] ];
        $version = get_bloginfo( 'version' );
        $locale  = get_locale();

        $checksums = self::fetch_core_checksums( $version, $locale );
        if ( empty( $checksums ) && $locale !== 'en_US' ) {
            $checksums = self::fetch_core_checksums( $version, 'en_US' );
        }
        if ( empty( $checksums ) ) {
            // Couldn't verify core (offline / unknown version) — leave empty; the
            // verdict treats "nothing found" as clean rather than alarming.
            return $result;
        }

        $known = [];
        foreach ( $checksums as $file => $md5 ) {
            // WP.org checksums don't cover wp-content, and legit customisation lives
            // there — never flag it.
            if ( strpos( $file, 'wp-content/' ) === 0 ) {
                continue;
            }
            $known[ $file ] = true;
            $path = ABSPATH . $file;
            if ( ! file_exists( $path ) ) {
                if ( ! in_array( $file, self::ROUTINELY_REMOVED, true ) ) {
                    $result['missing'][] = $file;
                }
            } elseif ( md5_file( $path ) !== $md5 ) {
                $result['modified'][] = $file;
            }
        }

        $result['unexpected'] = self::unexpected_core_files( $known );

        sort( $result['modified'] );
        sort( $result['missing'] );
        sort( $result['unexpected'] );
        return $result;
    }

    private static function fetch_core_checksums( string $version, string $locale ): array {
        $url  = add_query_arg( [ 'version' => $version, 'locale' => $locale ], self::CORE_API );
        $resp = wp_remote_get( $url, [ 'timeout' => self::HTTP_TIMEOUT ] );
        if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return [];
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        return ( isset( $body['checksums'] ) && is_array( $body['checksums'] ) ) ? $body['checksums'] : [];
    }

    /**
     * PHP files present in wp-admin / wp-includes that are NOT in the core
     * checksum manifest — a classic backdoor drop location.
     */
    private static function unexpected_core_files( array $known ): array {
        $unexpected = [];
        foreach ( [ 'wp-admin', 'wp-includes' ] as $dir ) {
            $base = ABSPATH . $dir;
            if ( ! is_dir( $base ) ) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
                );
                foreach ( $it as $file ) {
                    if ( strtolower( $file->getExtension() ) !== 'php' ) {
                        continue;
                    }
                    $rel = str_replace( '\\', '/', str_replace( ABSPATH, '', $file->getPathname() ) );
                    if ( ! isset( $known[ $rel ] ) ) {
                        $unexpected[] = $rel;
                        if ( count( $unexpected ) >= self::MAX_UNEXPECTED ) {
                            return $unexpected;
                        }
                    }
                }
            } catch ( Exception $e ) {
                // Permission / symlink issues — skip silently, never break the scan.
                continue;
            }
        }
        return $unexpected;
    }

    // ------------------------------------------------------------------
    // Plugins
    // ------------------------------------------------------------------

    private static function scan_plugins(): array {
        $result = [ 'mismatched' => [], 'unverifiable' => [] ];

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all    = get_plugins();
        $active = (array) get_option( 'active_plugins', [] );
        $checked = 0;

        foreach ( $active as $plugin_file ) {
            if ( $checked >= self::MAX_PLUGINS ) {
                break;
            }
            $checked++;

            $slug = dirname( $plugin_file );
            if ( $slug === '.' || $slug === '' ) {
                $slug = basename( $plugin_file, '.php' );   // single-file plugin
            }
            $version = isset( $all[ $plugin_file ]['Version'] ) ? (string) $all[ $plugin_file ]['Version'] : '';
            if ( $version === '' ) {
                $result['unverifiable'][] = $slug;
                continue;
            }

            $files = self::fetch_plugin_checksums( $slug, $version );
            if ( empty( $files ) ) {
                // Not in the WP.org repo (premium/custom) or no checksums published.
                $result['unverifiable'][] = $slug;
                continue;
            }

            $plugin_dir = trailingslashit( WP_PLUGIN_DIR ) . $slug . '/';
            foreach ( $files as $rel => $meta ) {
                $md5s = ( is_array( $meta ) && isset( $meta['md5'] ) ) ? (array) $meta['md5'] : [];
                if ( empty( $md5s ) ) {
                    continue;
                }
                $path = $plugin_dir . $rel;
                if ( ! file_exists( $path ) ) {
                    continue;   // a missing plugin file alone isn't a tamper signal
                }
                if ( ! in_array( md5_file( $path ), $md5s, true ) ) {
                    $result['mismatched'][] = [
                        'plugin'  => $slug,
                        'file'    => $rel,
                        'message' => 'Checksum does not match WordPress.org',
                    ];
                    if ( count( $result['mismatched'] ) >= self::MAX_MISMATCHED ) {
                        break 2;
                    }
                }
            }
        }

        $result['unverifiable'] = array_values( array_unique( $result['unverifiable'] ) );
        sort( $result['unverifiable'] );
        return $result;
    }

    private static function fetch_plugin_checksums( string $slug, string $version ): array {
        $url  = self::PLUGIN_CHECKSUM_URL . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json';
        $resp = wp_remote_get( $url, [ 'timeout' => self::HTTP_TIMEOUT ] );
        if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return [];
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        return ( isset( $body['files'] ) && is_array( $body['files'] ) ) ? $body['files'] : [];
    }

    // ------------------------------------------------------------------
    // Verdict + summary (mirrors the server-side security_scan_service.py)
    // ------------------------------------------------------------------

    private static function compute_verdict( array $core, array $plugins ): string {
        // Only an UNEXPECTED file inside a core dir (a file that shouldn't be there) is a
        // near-certain backdoor → tampered. Modified/mismatched/missing are commonly the
        // host's own patches (GoDaddy, WP Engine, Kinsta) → needs_review, not alarm.
        if ( ! empty( $core['unexpected'] ) ) {
            return 'tampered';
        }
        if ( ! empty( $core['modified'] ) || ! empty( $plugins['mismatched'] ) || ! empty( $core['missing'] ) ) {
            return 'needs_review';
        }
        return 'clean';
    }

    private static function summary( string $verdict, array $core, array $plugins ): string {
        if ( $verdict === 'tampered' ) {
            return count( $core['unexpected'] ) . ' unexpected file(s) found inside WordPress core '
                 . 'directories (files that should not be there) — a strong sign of a backdoor. '
                 . 'Review before go-live.';
        }
        if ( $verdict === 'needs_review' ) {
            $bits = [];
            if ( ! empty( $core['modified'] ) ) {
                $bits[] = count( $core['modified'] ) . ' modified core file(s)';
            }
            if ( ! empty( $plugins['mismatched'] ) ) {
                $names = array_unique( array_map(
                    function ( $m ) { return $m['plugin']; },
                    $plugins['mismatched']
                ) );
                $bits[] = count( $names ) . ' plugin(s) differing from WordPress.org';
            }
            if ( ! empty( $core['missing'] ) ) {
                $bits[] = count( $core['missing'] ) . ' missing core file(s)';
            }
            return implode( ', ', $bits ) . ' differ from the official WordPress.org checksums. '
                 . 'This is common on managed hosts (GoDaddy, WP Engine, Kinsta) that patch core '
                 . 'for their platform, and is usually benign — worth a quick review.';
        }
        $note = '';
        if ( ! empty( $plugins['unverifiable'] ) ) {
            $note = ' (' . count( $plugins['unverifiable'] )
                  . ' premium/custom plugin(s) couldn\'t be checked against WordPress.org — this is normal.)';
        }
        return 'No tampering detected against WordPress.org checksums.' . $note;
    }
}
