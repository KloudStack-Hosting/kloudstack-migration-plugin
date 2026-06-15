<?php
/**
 * KloudStack Migration REST API Endpoints
 *
 * Registers the following endpoints under /wp-json/kloudstack/v1/:
 *
 *   GET  /discover           — return site profile (WP version, plugins, theme, DB info)
 *   POST /validate           — validate plugin token, confirm connectivity
 *   POST /export-db          — start async DB export job (adds to BackgroundExport queue)
 *   GET  /job-status/{id}    — poll job status and progress percentage
 *   POST /upload-media       — start async media ZIP upload to Azure Blob (SAS URL provided)
 *   POST /media-files        — paginated list of media file paths (for incremental upload)
 *
 * Authentication:
 *   All endpoints require the X-KloudStack-Token header matching the stored plugin token.
 *   Constant-time comparison used to prevent timing attacks.
 *
 * @package KloudStackMigration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KloudStack_Migration_RestEndpoints {

    const NAMESPACE = 'kloudstack/v1';

    /** Transient prefix for async job records */
    const JOB_TRANSIENT_PREFIX = 'ks_mig_job_';

    /** Job TTL — 24 hours */
    const JOB_TTL = 86400;

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public static function register_routes(): void {
        $ns = self::NAMESPACE;

        register_rest_route( $ns, '/discover', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'discover' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'validate' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/export-db', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'export_db' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/job-status/(?P<job_id>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'job_status' ],
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/upload-media', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'upload_media' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/media-files', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'media_files' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/cancel-jobs', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'cancel_jobs' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/export-site-content', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'export_site_content' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/diagnostics', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'diagnostics' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/process-queue', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'process_queue_trigger' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/pre-flight', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'pre_flight' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );

        register_rest_route( $ns, '/job-debug/(?P<job_id>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'job_debug' ],
            'args'                => [
                'job_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ] );
    }

    // ------------------------------------------------------------------
    // Authentication middleware
    // ------------------------------------------------------------------

    /**
     * Verify the X-KloudStack-Token header using constant-time comparison.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function verify_token( WP_REST_Request $request ) {
        $stored_token = get_option( 'kloudstack_migration_token', '' );

        if ( empty( $stored_token ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Plugin token not configured. Visit Settings → KloudStack Migration.',
                [ 'status' => 403 ]
            );
        }

        $provided = $request->get_header( 'X-KloudStack-Token' );
        if ( empty( $provided ) ) {
            return new WP_Error(
                'rest_forbidden',
                'X-KloudStack-Token header is required.',
                [ 'status' => 403 ]
            );
        }

        // Constant-time comparison to prevent timing attacks
        if ( ! hash_equals( $stored_token, $provided ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Invalid token.',
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Endpoint: GET /discover
    // ------------------------------------------------------------------

    /**
     * Return the site profile for migration risk assessment.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function discover( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        // WordPress version
        $wp_version = get_bloginfo( 'version' );

        // Active plugins list
        $active_plugins  = get_option( 'active_plugins', [] );
        $plugin_count    = count( $active_plugins );
        $plugin_slugs    = array_map( function ( $path ) {
            return dirname( $path ) ?: basename( $path, '.php' );
        }, $active_plugins );

        // Active theme
        $theme = wp_get_theme();

        // Database size (MB)
        $db_size_result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ROUND( SUM( data_length + index_length ) / 1024 / 1024, 2 )
                 FROM information_schema.TABLES
                 WHERE table_schema = %s",
                DB_NAME
            )
        );
        $db_size_mb = (float) ( $db_size_result ?? 0 );

        // DB storage engine (InnoDB / MyISAM) from most-used table
        $db_engine_result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ENGINE, VERSION FROM information_schema.TABLES
                 WHERE table_schema = %s
                 ORDER BY data_length DESC
                 LIMIT 1",
                DB_NAME
            ),
            ARRAY_A
        );
        $db_engine  = $db_engine_result['ENGINE']  ?? 'InnoDB';
        $db_version = $db_engine_result['VERSION'] ?? '';

        // Actual DB server type and version (MySQL vs MariaDB)
        $db_server_version_raw = $wpdb->get_var( 'SELECT VERSION()' ) ?? '';
        // MariaDB reports e.g. "10.11.4-MariaDB" or "11.2.2-MariaDB"
        // MySQL reports e.g. "8.0.35"
        if ( stripos( $db_server_version_raw, 'mariadb' ) !== false ) {
            $db_server_type = 'MariaDB';
        } else {
            $db_server_type = 'MySQL';
        }
        $db_server_version = $db_server_version_raw;

        // Table count and per-table row counts for post-import validation
        $table_count_result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = %s",
                DB_NAME
            )
        );
        $table_count = (int) ( $table_count_result ?? 0 );

        // Per-table row counts for core WP tables (used by import validation)
        $core_tables = [ 'options', 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'comments' ];
        $table_row_counts = [];
        foreach ( $core_tables as $short_name ) {
            $full_name = $wpdb->prefix . $short_name;
            $row_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT TABLE_ROWS FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $full_name
                )
            );
            if ( $row_count !== null ) {
                $table_row_counts[ $short_name ] = (int) $row_count;
            }
        }

        // Media library count
        $media_count = wp_count_attachments()->total ?? 0;

        // wp-content/uploads size estimate
        $uploads_dir  = wp_upload_dir();
        $uploads_size = self::_dir_size_mb( $uploads_dir['basedir'] );

        // Multisite check
        $is_multisite = is_multisite();

        // WP-Cron health
        $wp_cron_enabled   = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
        $cron_next_time    = wp_next_scheduled( KloudStack_Migration_BackgroundExport::CRON_HOOK );
        $export_queue      = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $jobs_in_queue     = count( $export_queue );

        return new WP_REST_Response( [
            'site_url'      => get_site_url(),
            'home_url'      => get_home_url(),
            'plugin_version' => defined( 'KLOUDSTACK_MIGRATION_VERSION' ) ? KLOUDSTACK_MIGRATION_VERSION : 'unknown',
            'wp_version'    => $wp_version,
            'php_version'   => PHP_VERSION,
            'plugins'       => $plugin_slugs,
            'plugin_count'  => $plugin_count,
            'theme'         => $theme->get( 'Name' ),
            'theme_version' => $theme->get( 'Version' ),
            'db_engine'          => $db_engine,
            'db_version'         => $db_version,
            'db_server_type'     => $db_server_type,
            'db_server_version'  => $db_server_version,
            'db_size_mb'         => $db_size_mb,
            'db_name'       => DB_NAME,
            'table_count'        => $table_count,
            'table_row_counts'   => $table_row_counts,
            'media_count'   => (int) $media_count,
            'uploads_size_mb' => $uploads_size,
            'is_multisite'  => $is_multisite,
            'table_prefix'  => $wpdb->prefix,
            'wp_cron_enabled'      => $wp_cron_enabled,
            'cron_next_scheduled'  => $cron_next_time ? (int) $cron_next_time : null,
            'jobs_in_queue'        => $jobs_in_queue,
            // Hosting environment — detected server-side so the migration agent has
            // accurate context without guessing from URLs.
            'hosting_platform'       => self::_detect_hosting_platform(),
            'exec_available'         => ( function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true ) ),
            'php_memory_limit_mb'    => self::_php_memory_limit_mb(),
            'php_max_execution_time' => (int) ini_get( 'max_execution_time' ),
            'disk_free_mb'           => ( disk_free_space( ABSPATH ) !== false ) ? round( disk_free_space( ABSPATH ) / 1024 / 1024, 1 ) : null,
            // WP7+ ships with native MCP support — agent uses this to skip /discover for WP7 sites.
            'mcp_native'             => version_compare( $wp_version, '7.0', '>=' ),
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /validate
    // ------------------------------------------------------------------

    /**
     * Validate that the plugin can connect and the token is correct.
     * Called by the Django backend after pairing to confirm connectivity.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function validate( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( [
            'valid'      => true,
            'site_url'   => get_site_url(),
            'wp_version' => get_bloginfo( 'version' ),
            'timestamp'  => time(),
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /export-db
    // ------------------------------------------------------------------

    /**
     * Start an async database export job.
     *
     * The actual export is performed by BackgroundExport::process_queue()
     * which runs via WP-Cron every minute. This endpoint enqueues the job
     * and returns a job_id for polling.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function export_db( WP_REST_Request $request ): WP_REST_Response {
        $params  = $request->get_json_params();
        $sas_url = sanitize_url( $params['sas_url'] ?? '' );

        // Agent-provided hints for this attempt (e.g. stream_rate_limit_kbps after a CPU spike).
        // Stored in the job transient so BackgroundExport can apply them when it runs.
        $hints = self::_sanitize_hints( $params['hints'] ?? [] );

        $job_id = 'db_' . wp_generate_uuid4();

        $job = [
            'type'       => 'db_export',
            'status'     => 'queued',
            'progress'   => 0,
            'sas_url'    => $sas_url,
            'hints'      => $hints,
            'created_at' => time(),
            'blob_path'  => '',
            'error'      => null,
        ];

        set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );
        self::_shadow_write_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job );

        // Enqueue into BackgroundExport queue
        KloudStack_Migration_BackgroundExport::enqueue( $job_id, 'db_export', $sas_url );

        // Process the queue immediately after this response is sent.
        //
        // A loopback wp_remote_get to /?doing_wp_cron is unreliable on Azure App Service
        // (the 0.01 s timeout is shorter than a Front Door round-trip, and loopback
        // requests are often blocked by the platform).  Instead we register a PHP
        // shutdown function so process_queue() runs in the same PHP-FPM worker after
        // the 202 has been flushed to the caller — no WP-Cron required.
        //
        // fastcgi_finish_request() closes the client connection first so Django's
        // httpx call completes immediately; PHP continues running in the background.
        register_shutdown_function( function () {
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request(); // Flush + close client connection
            }
            ignore_user_abort( true );
            set_time_limit( 600 ); // Allow up to 10 min (large DB dump + upload combined)
            KloudStack_Migration_BackgroundExport::process_queue();
        } );

        // Also schedule via WP-Cron as a belt-and-suspenders fallback
        // (fires on the next page load if the shutdown function was cut short).
        if ( ! wp_next_scheduled( KloudStack_Migration_BackgroundExport::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), KloudStack_Migration_BackgroundExport::CRON_HOOK );
        }

        return new WP_REST_Response( [ 'job_id' => $job_id ], 202 );
    }

    // ------------------------------------------------------------------
    // Endpoint: GET /job-status/{job_id}
    // ------------------------------------------------------------------

    /**
     * Return current status and progress for an async job.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function job_status( WP_REST_Request $request ) {
        $job_id = $request->get_param( 'job_id' );

        // Read directly from wp_options rather than relying on get_transient().
        //
        // BackgroundExport::_update_job() writes progress updates to wp_options via
        // direct $wpdb calls (both its DB-fallback path and its normal path since v1.7.2).
        // However when the object cache driver is Redis or a process-local store (APCu /
        // W3TC in-memory), get_transient() returns a stale 0% snapshot from the cache
        // even though the DB already has the real progress — the cache is either
        // disconnected (shutdown FIFO order) or was written by a different PHP-FPM worker
        // and can't be invalidated from the updating worker.
        //
        // Bypassing the cache here guarantees Django always sees the most recently
        // written DB value, regardless of which PHP worker ran the export or whether the
        // object cache is still alive.
        global $wpdb;
        $db_val = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            '_transient_' . self::JOB_TRANSIENT_PREFIX . $job_id
        ) );
        $job = ( null !== $db_val ) ? maybe_unserialize( $db_val ) : false;

        // Fall back to cache if no DB row exists (job predates shadow-write or was never
        // committed to wp_options by an earlier plugin version).
        if ( false === $job || ! is_array( $job ) ) {
            $job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
        }

        if ( false === $job ) {
            // Transient missing from both DB and cache. Check the queue so the poller
            // keeps waiting rather than failing prematurely.
            $queue = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
            foreach ( $queue as $item ) {
                if ( ( $item['job_id'] ?? '' ) === $job_id ) {
                    return new WP_REST_Response( [
                        'job_id'   => $job_id,
                        'type'     => $item['type'] ?? 'unknown',
                        'status'   => 'queued',
                        'progress' => 0,
                        'message'  => 'Job is queued and waiting to process.',
                    ], 200 );
                }
            }
            return new WP_Error(
                'not_found',
                "Job {$job_id} not found or expired.",
                [ 'status' => 404 ]
            );
        }

        // Expose rich progress fields for media_stream jobs; omit the uploaded_files
        // checkpoint array (can be large) and the raw sas_token from the response.
        $safe_fields = [
            'job_id', 'type', 'status', 'progress', 'error', 'created_at',
            'files_uploaded', 'total_files', 'bytes_uploaded', 'total_bytes',
            'artifact', 'blob_path', 'message',
        ];
        $response = [ 'job_id' => $job_id ];
        foreach ( $safe_fields as $field ) {
            if ( array_key_exists( $field, $job ) ) {
                $response[ $field ] = $job[ $field ];
            }
        }
        return new WP_REST_Response( $response, 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /upload-media
    // ------------------------------------------------------------------

    /**
     * Start an async per-file media upload to Azure Blob Storage (Sprint M).
     *
     * Accepts a container-level write SAS token rather than a single blob SAS URL.
     * The plugin streams each file in wp-content/uploads/ directly to Azure Blob —
     * no ZIP archive, no temp disk usage.
     *
     * Request body:
     *   {
     *     "container_base_url": "https://{account}.blob.core.windows.net/kloudstack-migrations",
     *     "blob_prefix":        "migrations/{id}/media/uploads",
     *     "sas_token":          "<container write SAS — no leading ?>",
     *     "hints":              { ... }  // optional
     *   }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function upload_media( WP_REST_Request $request ): WP_REST_Response {
        $params             = $request->get_json_params();
        $container_base_url = sanitize_url( $params['container_base_url'] ?? '' );
        $blob_prefix        = sanitize_text_field( $params['blob_prefix'] ?? '' );
        $sas_token          = sanitize_text_field( $params['sas_token'] ?? '' );

        if ( empty( $container_base_url ) || empty( $blob_prefix ) || empty( $sas_token ) ) {
            return new WP_REST_Response(
                [ 'error' => 'container_base_url, blob_prefix, and sas_token are required.' ],
                400
            );
        }

        // Agent-provided hints (e.g. skip_extensions after a timeout on large files).
        $hints = self::_sanitize_hints( $params['hints'] ?? [] );

        $job_id = 'media_' . wp_generate_uuid4();

        $job = [
            'type'               => 'media_stream',
            'status'             => 'queued',
            'progress'           => 0,
            'files_uploaded'     => 0,
            'total_files'        => 0,
            'bytes_uploaded'     => 0,
            'total_bytes'        => 0,
            'uploaded_files'     => [],   // checkpoint — relative paths already uploaded
            'container_base_url' => $container_base_url,
            'blob_prefix'        => $blob_prefix,
            'sas_token'          => $sas_token,
            'hints'              => $hints,
            'created_at'         => time(),
            'error'              => null,
        ];

        set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );
        self::_shadow_write_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job );

        KloudStack_Migration_BackgroundExport::enqueue(
            $job_id,
            'media_stream',
            '',  // sas_url unused for media_stream — real params in extra_data
            [
                'container_base_url' => $container_base_url,
                'blob_prefix'        => $blob_prefix,
                'sas_token'          => $sas_token,
            ]
        );

        // Flush the 202 response, then process immediately in this PHP-FPM worker.
        // Large media libraries require multiple WP-Cron ticks to complete (checkpointing).
        // set_time_limit covers one batch (~500 files × ~100 ms = ~50 s) with headroom.
        register_shutdown_function( function () {
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            }
            ignore_user_abort( true );
            set_time_limit( 300 );
            KloudStack_Migration_BackgroundExport::process_queue();
        } );

        if ( ! wp_next_scheduled( KloudStack_Migration_BackgroundExport::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), KloudStack_Migration_BackgroundExport::CRON_HOOK );
        }

        return new WP_REST_Response( [ 'job_id' => $job_id ], 202 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /export-site-content
    // ------------------------------------------------------------------

    /**
     * Start parallel async export jobs for each requested wp-content artifact.
     *
     * Request body:
     *   {
     *     "sas_urls": {
     *       "plugins":     "https://...plugins.zip?sas",
     *       "themes":      "https://...themes.zip?sas",
     *       "media":       "https://...media.zip?sas",
     *       "mu-plugins":  "https://...mu-plugins.zip?sas",  // optional
     *       "custom-root": "https://...custom-root.zip?sas"  // optional
     *     },
     *     "hints": { ... }  // optional agent hints forwarded to each job
     *   }
     *
     * Response (202):
     *   {
     *     "jobs": {
     *       "plugins": "job_id_abc",
     *       "themes":  "job_id_def",
     *       "media":   "job_id_ghi"
     *     }
     *   }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function export_site_content( WP_REST_Request $request ): WP_REST_Response {
        $params   = $request->get_json_params();
        $sas_urls = $params['sas_urls'] ?? [];
        $hints    = self::_sanitize_hints( $params['hints'] ?? [] );

        if ( ! is_array( $sas_urls ) || empty( $sas_urls ) ) {
            return new WP_REST_Response( [ 'error' => 'sas_urls is required and must be a non-empty object.' ], 400 );
        }

        // Resolve artifact name → absolute filesystem path.
        // Only well-known artifact names are accepted to prevent path traversal.
        $uploads_basedir = wp_upload_dir()['basedir'];
        $artifact_paths  = [
            'plugins'     => WP_CONTENT_DIR . '/plugins',
            'themes'      => WP_CONTENT_DIR . '/themes',
            'media'       => $uploads_basedir,
            'mu-plugins'  => WP_CONTENT_DIR . '/mu-plugins',
            'custom-root' => 'custom-root',  // sentinel — handled specially in BackgroundExport
        ];

        $jobs = [];

        foreach ( $sas_urls as $artifact => $sas_url ) {
            // Reject unknown artifact names
            if ( ! array_key_exists( $artifact, $artifact_paths ) ) {
                continue;
            }

            $source_path = $artifact_paths[ $artifact ];

            // Skip artifacts whose directory doesn't exist (e.g. mu-plugins on a stock site)
            if ( 'custom-root' !== $source_path && ! is_dir( $source_path ) ) {
                continue;
            }

            $sas_url = sanitize_url( $sas_url );
            $job_id  = 'content_' . sanitize_key( $artifact ) . '_' . wp_generate_uuid4();

            $job = [
                'type'        => 'content_export',
                'status'      => 'queued',
                'progress'    => 0,
                'sas_url'     => $sas_url,
                'source_path' => $source_path,
                'artifact'    => $artifact,
                'hints'       => $hints,
                'created_at'  => time(),
                'error'       => null,
            ];

            set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, self::JOB_TTL );
            self::_shadow_write_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job );

            KloudStack_Migration_BackgroundExport::enqueue(
                $job_id,
                'content_export',
                $sas_url,
                [ 'source_path' => $source_path, 'artifact' => $artifact ]
            );

            $jobs[ $artifact ] = $job_id;
        }

        if ( empty( $jobs ) ) {
            return new WP_REST_Response( [ 'error' => 'No valid artifact paths found for the requested sas_urls.' ], 422 );
        }

        // Flush the 202 response, then kick the queue in the same PHP-FPM worker.
        // Loopback WP-Cron is unreliable on Azure App Service + Front Door.
        register_shutdown_function( function () {
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            }
            ignore_user_abort( true );
            set_time_limit( 600 );
            KloudStack_Migration_BackgroundExport::process_queue();
        } );

        if ( ! wp_next_scheduled( KloudStack_Migration_BackgroundExport::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), KloudStack_Migration_BackgroundExport::CRON_HOOK );
        }

        return new WP_REST_Response( [ 'jobs' => $jobs ], 202 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /media-files
    // ------------------------------------------------------------------

    /**
     * Return a paginated list of media file paths in wp-content/uploads.
     * Used for incremental upload verification.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function media_files( WP_REST_Request $request ): WP_REST_Response {
        $params   = $request->get_json_params();
        $page     = max( 1, (int) ( $params['page'] ?? 1 ) );
        $per_page = min( 500, max( 1, (int) ( $params['per_page'] ?? 100 ) ) );

        $uploads_dir = wp_upload_dir();
        $base_dir    = $uploads_dir['basedir'];
        $all_files   = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                // Return path relative to uploads dir
                $all_files[] = str_replace( $base_dir . DIRECTORY_SEPARATOR, '', $file->getPathname() );
            }
        }

        $total  = count( $all_files );
        $offset = ( $page - 1 ) * $per_page;
        $slice  = array_slice( $all_files, $offset, $per_page );

        return new WP_REST_Response( [
            'files'      => $slice,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'has_more'   => ( $offset + $per_page ) < $total,
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: GET /diagnostics
    // ------------------------------------------------------------------

    /**
     * Return a comprehensive diagnostic snapshot of the export queue and PHP environment.
     *
     * Called by the migration agent when a job stalls at 0% to understand WHY
     * processing has not started. The response gives the agent enough context to
     * decide whether to call /process-queue, escalate to the user, or abort.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function diagnostics( WP_REST_Request $request ): WP_REST_Response {
        $queue      = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $lock_key   = 'ks_mig_queue_lock';
        $lock_value = get_transient( $lock_key );

        // Collect per-job status from transients so the agent can cross-reference
        // queue entries against their current transient state.
        $queue_jobs = [];
        foreach ( $queue as $item ) {
            $job_id    = $item['job_id'] ?? '';
            $transient = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
            $queue_jobs[] = [
                'job_id'   => $job_id,
                'type'     => $item['type'] ?? 'unknown',
                'artifact' => $item['artifact'] ?? null,
                'status'   => is_array( $transient ) ? ( $transient['status'] ?? 'unknown' ) : 'transient_missing',
                'progress' => is_array( $transient ) ? ( $transient['progress'] ?? 0 ) : 0,
                'error'    => is_array( $transient ) ? ( $transient['error'] ?? null ) : null,
            ];
        }

        // Memory usage
        $mem_used_mb  = round( memory_get_usage( true ) / 1024 / 1024, 1 );
        $mem_peak_mb  = round( memory_get_peak_usage( true ) / 1024 / 1024, 1 );
        $mem_limit_mb = self::_php_memory_limit_mb();

        // WP-Cron next scheduled run for our hook
        $cron_next = wp_next_scheduled( KloudStack_Migration_BackgroundExport::CRON_HOOK );

        // PHP environment: which potentially-restricted functions are available
        $disable_fns = array_filter( array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ) );

        return new WP_REST_Response( [
            // Queue state
            'queue_depth'          => count( $queue ),
            'queue_jobs'           => $queue_jobs,

            // Processing lock (TTL = 10 min; active means process_queue() is running)
            'lock_active'          => ( false !== $lock_value ),

            // WP-Cron
            'wpcron_enabled'       => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            'cron_next_scheduled'  => $cron_next ? (int) $cron_next : null,
            'cron_overdue_seconds' => $cron_next ? max( 0, time() - (int) $cron_next ) : null,

            // PHP runtime capabilities
            'fastcgi_available'      => function_exists( 'fastcgi_finish_request' ),
            'exec_available'         => ( function_exists( 'exec' ) && ! in_array( 'exec', $disable_fns, true ) ),
            'ziparchive_available'   => class_exists( 'ZipArchive' ),
            'php_memory_used_mb'     => $mem_used_mb,
            'php_memory_peak_mb'     => $mem_peak_mb,
            'php_memory_limit_mb'    => $mem_limit_mb,
            'php_memory_near_limit'  => $mem_limit_mb > 0 && ( $mem_used_mb / $mem_limit_mb ) > 0.8,
            'php_max_execution_time' => (int) ini_get( 'max_execution_time' ),

            // Hosting environment
            'hosting_platform'       => self::_detect_hosting_platform(),
            'object_cache_active'    => wp_using_ext_object_cache(),

            // Temp disk space available for dump/zip files
            'tmp_free_mb'            => ( disk_free_space( sys_get_temp_dir() ) !== false )
                ? round( disk_free_space( sys_get_temp_dir() ) / 1024 / 1024, 1 )
                : null,
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: GET /pre-flight
    // ------------------------------------------------------------------

    /**
     * Return a comprehensive pre-flight snapshot before export begins.
     *
     * Called by the MAE at the start of run_db_export so the LLM has full
     * environment context before committing to an export strategy. Key decisions
     * driven by this data:
     *   - exec_available=false  → use PHP-native DB export fallback
     *   - wpcron_enabled=false  → assume all content jobs must drain in one shutdown call
     *   - tmp_free_mb too low   → warn customer before attempting DB dump
     *   - blockers non-empty    → agent surfaces issue to customer before starting
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function pre_flight( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $wp_version = get_bloginfo( 'version' );

        // DB size
        $db_size_mb = (float) ( $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ROUND( SUM( data_length + index_length ) / 1024 / 1024, 2 )
                 FROM information_schema.TABLES WHERE table_schema = %s",
                DB_NAME
            )
        ) ?? 0 );

        // Media file count and size
        $uploads_dir     = wp_upload_dir();
        $uploads_basedir = $uploads_dir['basedir'];
        $media_file_count = 0;
        $media_size_bytes = 0;
        if ( is_dir( $uploads_basedir ) ) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $uploads_basedir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iter as $f ) {
                if ( $f->isFile() ) {
                    $media_file_count++;
                    $media_size_bytes += $f->getSize();
                }
            }
        }
        $media_size_mb = round( $media_size_bytes / 1024 / 1024, 1 );

        // Plugins and themes directory sizes
        $plugins_size_mb = self::_dir_size_mb( WP_CONTENT_DIR . '/plugins' );
        $themes_size_mb  = self::_dir_size_mb( WP_CONTENT_DIR . '/themes' );

        // Capabilities
        $disable_fns    = array_filter( array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ) );
        $exec_available = function_exists( 'exec' ) && ! in_array( 'exec', $disable_fns, true );

        // Temp disk space
        $tmp_free_mb = ( disk_free_space( sys_get_temp_dir() ) !== false )
            ? round( disk_free_space( sys_get_temp_dir() ) / 1024 / 1024, 1 )
            : null;

        // Estimated export duration (rough — 1 min per 100 MB DB + 1 min per 500 media files)
        $db_minutes    = max( 1, ceil( $db_size_mb / 100 ) );
        $media_minutes = max( 1, ceil( $media_file_count / 500 ) );
        $estimated_export_minutes = $db_minutes + $media_minutes;

        // Blockers — conditions that will cause export to fail without intervention.
        // Note: exec_disabled is NOT a blocker — _run_db_export() falls back to
        // PHP-native wpdb export automatically when exec() is unavailable.
        $blockers = [];
        if ( ! class_exists( 'ZipArchive' ) ) {
            $blockers[] = 'ziparchive_missing';
        }
        if ( $tmp_free_mb !== null && $tmp_free_mb < ( $db_size_mb * 1.5 ) ) {
            $blockers[] = 'tmp_disk_too_small';
        }

        return new WP_REST_Response( [
            'exec_available'           => $exec_available,
            'ziparchive_available'     => class_exists( 'ZipArchive' ),
            'fastcgi_available'        => function_exists( 'fastcgi_finish_request' ),
            'wpcron_enabled'           => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            'object_cache_active'      => wp_using_ext_object_cache(),
            'hosting_platform'         => self::_detect_hosting_platform(),
            'wp_version'               => $wp_version,
            'mcp_native'               => version_compare( $wp_version, '7.0', '>=' ),
            'db_size_mb'               => $db_size_mb,
            'media_file_count'         => $media_file_count,
            'media_size_mb'            => $media_size_mb,
            'plugins_size_mb'          => $plugins_size_mb,
            'themes_size_mb'           => $themes_size_mb,
            'tmp_free_mb'              => $tmp_free_mb,
            'estimated_export_minutes' => $estimated_export_minutes,
            'blockers'                 => $blockers,
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Endpoint: POST /process-queue
    // ------------------------------------------------------------------

    /**
     * Directly trigger the export queue processor and return immediately.
     *
     * Called by the migration agent as a recovery mechanism when a job stalls —
     * specifically on Azure App Service where WP-Cron URL nudges via the Front Door
     * CDN URL are unreliable (requests may not reach the PHP-FPM worker).
     *
     * Uses the same fastcgi_finish_request() pattern as export-db / upload-media:
     * the 202 is flushed to the caller immediately, then process_queue() runs in
     * the same PHP-FPM worker in the background. The agent's polling loop is
     * never blocked.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function process_queue_trigger( WP_REST_Request $request ): WP_REST_Response {
        $queue      = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $queue_size = count( $queue );

        if ( 0 === $queue_size ) {
            return new WP_REST_Response( [
                'triggered'     => false,
                'reason'        => 'queue_empty',
                'jobs_in_queue' => 0,
            ], 200 );
        }

        // Flush the 202 immediately, then process in the same PHP-FPM worker.
        register_shutdown_function( function () {
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            }
            ignore_user_abort( true );
            set_time_limit( 600 );
            KloudStack_Migration_BackgroundExport::process_queue();
        } );

        return new WP_REST_Response( [
            'triggered'     => true,
            'jobs_in_queue' => $queue_size,
        ], 202 );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Cancel all queued/in-flight export jobs.
     *
     * Called by the KloudStack backend when a migration fails or is cancelled
     * so that the export queue is cleared and orphaned jobs do not keep running.
     *
     * Accepts an optional JSON body: { "job_ids": ["db_xxx", "media_xxx"] }
     * When job_ids is provided only those specific transients are cancelled;
     * when omitted the entire queue is flushed.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function cancel_jobs( WP_REST_Request $request ): WP_REST_Response {
        $params      = $request->get_json_params() ?? [];
        $target_ids  = isset( $params['job_ids'] ) && is_array( $params['job_ids'] )
            ? array_map( 'sanitize_key', $params['job_ids'] )
            : [];

        $queue    = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $removed  = [];
        $kept     = [];

        foreach ( $queue as $item ) {
            $job_id = $item['job_id'] ?? '';
            $should_remove = empty( $target_ids ) || in_array( $job_id, $target_ids, true );

            if ( $should_remove ) {
                // Mark the transient as cancelled so job-status polling gets a
                // definitive answer rather than returning 'queued' indefinitely.
                $existing = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );
                if ( false !== $existing ) {
                    set_transient(
                        self::JOB_TRANSIENT_PREFIX . $job_id,
                        array_merge( $existing, [ 'status' => 'cancelled', 'error' => 'Cancelled by KloudStack platform.' ] ),
                        self::JOB_TTL
                    );
                }
                $removed[] = $job_id;
            } else {
                $kept[] = $item;
            }
        }

        // Persist the pruned queue.
        update_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, $kept, false );

        return new WP_REST_Response( [
            'cancelled' => $removed,
            'remaining' => count( $kept ),
        ], 200 );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Recursively calculate directory size in MB.
     * Returns 0 if directory does not exist.
     */
    private static function _dir_size_mb( string $dir ): float {
        if ( ! is_dir( $dir ) ) {
            return 0.0;
        }
        $size     = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }
        return round( $size / 1024 / 1024, 2 );
    }

    /**
     * Detect the hosting platform from server-side environment variables.
     * More reliable than URL heuristics; used by the migration agent for
     * context-aware risk assessment and export strategy.
     */
    private static function _detect_hosting_platform(): string {
        // Azure App Service sets WEBSITE_SITE_NAME on all Linux/Windows plans
        if ( getenv( 'WEBSITE_SITE_NAME' ) !== false ) {
            return 'azure_app_service';
        }
        if ( defined( 'WPE_APIKEY' ) ) {
            return 'wpe';
        }
        if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
            return 'wpvip';
        }
        if ( getenv( 'KINSTA_CACHE_ZONE' ) !== false ) {
            return 'kinsta';
        }
        // GoDaddy: cPanel hosting sets GD_PHP_HANDLER; some plans define GD_COMMAND_LINE;
        // hostnames often contain 'secureserver.net' (GoDaddy's internal network domain).
        if ( getenv( 'GD_PHP_HANDLER' ) !== false
            || defined( 'GD_COMMAND_LINE' )
            || strpos( php_uname( 'n' ), 'secureserver' ) !== false
        ) {
            return 'godaddy';
        }
        return 'other';
    }

    /**
     * Return PHP memory_limit in MB. Returns -1 for unlimited.
     */
    private static function _php_memory_limit_mb(): int {
        $val = ini_get( 'memory_limit' );
        if ( '-1' === $val ) {
            return -1;
        }
        return (int) ( wp_convert_hr_to_bytes( $val ) / 1024 / 1024 );
    }

    /**
     * Write the job transient directly to wp_options as a shadow copy.
     *
     * set_transient() stores data only in the active object cache when an external
     * driver is installed (W3TC with APCu, Redis, Memcached). If the driver is
     * process-local (APCu) the data is invisible to other PHP-FPM workers. When a
     * different worker runs BackgroundExport::process_queue() in shutdown context, its
     * _update_job() DB-fallback reads wp_options — and finds nothing, so every update
     * silently no-ops and progress never reports.
     *
     * Calling this after set_transient() guarantees there is always a row in wp_options
     * that the DB-fallback can read and overwrite, regardless of which object cache
     * driver is in use or whether a different worker created the record.
     *
     * @param string $transient_key  Full transient key (without _transient_ prefix)
     * @param array  $job            Job data array to serialise
     */
    /**
     * Return raw DB diagnostic state for a job — used by Django when media stalls at 0%.
     *
     * Reports whether the shadow-write row exists, what value it holds, what the cache
     * returns, and whether a test DB write round-trips correctly. Gives definitive
     * evidence of which failure layer is active without requiring PHP error-log access.
     */
    public static function job_debug( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $job_id      = $request->get_param( 'job_id' );
        $option_name = '_transient_' . self::JOB_TRANSIENT_PREFIX . $job_id;

        // Raw DB read — same query _update_job DB-fallback uses.
        $db_val  = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option_name
        ) );
        $db_job  = ( null !== $db_val ) ? maybe_unserialize( $db_val ) : null;

        // Cache read — what get_transient() returns in a normal (non-shutdown) request.
        $cache_job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );

        // Queue state.
        $queue         = get_option( KloudStack_Migration_BackgroundExport::QUEUE_OPTION, [] );
        $job_in_queue  = false;
        foreach ( $queue as $item ) {
            if ( ( $item['job_id'] ?? '' ) === $job_id ) {
                $job_in_queue = true;
                break;
            }
        }

        // DB write round-trip test — confirms $wpdb can write and read back in this context.
        $test_key = '_ks_dbtest_' . time();
        $wpdb->replace( $wpdb->options, [ 'option_name' => $test_key, 'option_value' => 'ok', 'autoload' => 'no' ] );
        $test_read = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $test_key ) );
        $wpdb->delete( $wpdb->options, [ 'option_name' => $test_key ] );

        // DB write round-trip test using UPDATE specifically — distinguishes hosts where
        // INSERT/REPLACE works but UPDATE privilege is absent (the root cause of 0%-stall).
        $update_test_key = '_ks_dbtest_upd_' . time();
        $wpdb->replace( $wpdb->options, [ 'option_name' => $update_test_key, 'option_value' => 'initial', 'autoload' => 'no' ] );
        $wpdb->update( $wpdb->options, [ 'option_value' => 'updated' ], [ 'option_name' => $update_test_key ] );
        $update_test_read = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $update_test_key ) );
        $wpdb->delete( $wpdb->options, [ 'option_name' => $update_test_key ] );

        // Last _update_job() audit entry (written by BackgroundExport::_update_job()).
        $audit_option = 'ks_mig_audit_' . substr( $job_id, -12 );
        $audit_raw    = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $audit_option
        ) );

        return new WP_REST_Response( [
            'job_id'                 => $job_id,
            'plugin_version'         => defined( 'KLOUDSTACK_MIGRATION_VERSION' ) ? KLOUDSTACK_MIGRATION_VERSION : 'unknown',
            'db_row_exists'          => ( null !== $db_val ),
            'db_status'              => is_array( $db_job ) ? ( $db_job['status']   ?? 'missing_key' ) : null,
            'db_progress'            => is_array( $db_job ) ? ( $db_job['progress'] ?? -1 )            : null,
            'db_last_error'          => $wpdb->last_error ?: null,
            'cache_row_exists'       => ( false !== $cache_job ),
            'cache_status'           => is_array( $cache_job ) ? ( $cache_job['status']   ?? 'missing_key' ) : null,
            'cache_progress'         => is_array( $cache_job ) ? ( $cache_job['progress'] ?? -1 )            : null,
            'queue_depth'            => count( $queue ),
            'job_in_queue'           => $job_in_queue,
            'db_write_roundtrip'     => ( $test_read === 'ok' ? 'pass' : 'fail' ),
            'db_update_roundtrip'    => ( $update_test_read === 'updated' ? 'pass' : 'fail' ),
            'ext_object_cache'       => wp_using_ext_object_cache(),
            'php_version'            => PHP_VERSION,
            'timestamp'              => time(),
            'last_update_audit'      => $audit_raw ? json_decode( $audit_raw, true ) : null,
        ], 200 );
    }

    private static function _shadow_write_transient( string $transient_key, array $job ): void {
        global $wpdb;
        $result = $wpdb->replace(
            $wpdb->options,
            [
                'option_name'  => '_transient_' . $transient_key,
                'option_value' => maybe_serialize( $job ),
                'autoload'     => 'no',
            ]
        );
        if ( false === $result ) {
            error_log( '[KS Migration] shadow_write FAILED for ' . $transient_key . ' — wpdb error: ' . $wpdb->last_error );
        } else {
            error_log( '[KS Migration] shadow_write OK for ' . $transient_key . ' (rows=' . $result . ')' );
        }
    }

    /**
     * Sanitise agent-provided hints from the request body.
     * Only allows known, typed keys — all values are validated and clamped.
     *
     * @param mixed $raw  Untrusted input from request body
     * @return array      Safe hints array
     */
    private static function _sanitize_hints( $raw ): array {
        if ( ! is_array( $raw ) || empty( $raw ) ) {
            return [];
        }
        $hints = [];

        // Stream rate limit for mysqldump pipe (kbps) — throttles DB export when CPU is high
        if ( isset( $raw['stream_rate_limit_kbps'] ) ) {
            $hints['stream_rate_limit_kbps'] = max( 128, (int) $raw['stream_rate_limit_kbps'] );
        }

        // File extensions to skip during media ZIP (e.g. large video files)
        if ( isset( $raw['skip_extensions'] ) && is_array( $raw['skip_extensions'] ) ) {
            $hints['skip_extensions'] = array_values(
                array_filter(
                    array_map( 'sanitize_text_field', array_slice( $raw['skip_extensions'], 0, 20 ) )
                )
            );
        }

        // Maximum individual file size to include in media export (0 = no limit)
        if ( isset( $raw['max_file_size_mb'] ) ) {
            $hints['max_file_size_mb'] = max( 0, (int) $raw['max_file_size_mb'] );
        }

        return $hints;
    }
}
