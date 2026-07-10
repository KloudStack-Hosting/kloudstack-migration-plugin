# Changelog

All notable changes to the KloudStack Migration Plugin will be documented here.

## [1.13.0] - 2026-07-10

### Fixed
- **`/diagnostics` reported a lock state it could never observe.** `lock_active` was read with
  `get_transient('ks_mig_queue_lock')`, but `BackgroundExport::process_queue()` stores the
  processing lock as a **raw `wp_options` row** (written with `INSERT IGNORE`, removed with
  `$wpdb->delete`). The transient key `_transient_ks_mig_queue_lock` is never written by anything,
  so `lock_active` was **structurally always `false`** — it could not report a running worker even
  while one held the lock. KloudStack's poller used that flag as its "a worker is mid-batch, give it
  time" guard, so the guard never fired and every stalled job was fast-failed with
  *"queue is empty ... worker cannot recover without a restart."*
- **`/diagnostics` read the queue through the object cache.** `queue_depth` used `get_option()`,
  which answers from the per-process cache. On a multi-worker PHP-FPM host that cache reflects only
  the current worker's writes — the very reason `process_queue()` has read the queue with a direct
  `$wpdb` query since v1.7. Diagnostics now reads the DB too, so `queue_depth=0` means the queue is
  empty rather than "this worker hasn't seen it yet".

### Added
- `queue_depth_cached` — the object-cache view alongside the DB truth, so a divergence is visible
  instead of silently deciding the verdict.
- `lock_stale` and `lock_age_seconds` — `process_queue()` force-claims a lock older than 600s, so a
  stale lock is the one unambiguous signal that a worker was **killed before releasing it**. That is
  the common failure on managed hosts (e.g. GoDaddy) which terminate PHP after
  `fastcgi_finish_request()`, especially when WP-Cron is disabled and the job must drain in a single
  shutdown call. We had no way to see it.

## [1.12.2] - 2026-07-09

### Added
- **Site-health extras in `/discover`.** A `site_health` block with migration-critical data pulled
  directly (the same signals WP's Site Health surfaces): **drop-ins** (`object-cache.php` /
  `advanced-cache.php` / `db.php` — these reference the source host's cache/DB config and are a
  leading cause of broken-after-migration sites), **mu-plugins** (host platform code), relevant
  **PHP extensions** (curl/imagick/gd/mysqli/zip/…), **server software**, permalink structure,
  HTTPS, and external-object-cache status. Feeds the migration risk analysis + diagnostic agent.

## [1.12.1] - 2026-07-09

### Added
- **Managed-host detection.** `/discover` now identifies **GoDaddy Managed WordPress** (WPaaS —
  `gd-system-plugin` MU-plugin / `GD_SYSTEM_PLUGIN_DIR` / `*.myftpupload.com`), plus WP Engine,
  Kinsta, Pantheon, Flywheel, SiteGround and Pressable — previously GoDaddy MWP reported
  `hosting_platform: "other"`. Adds a `managed_host` boolean so the report and security scan can
  treat these hosts' expected core patches as normal rather than tampering.

### Changed
- **Security scan: don't cry wolf on managed hosts.** GoDaddy / WP Engine / Kinsta patch core
  files (post/query/meta) for their platform and delete `license.txt` / `readme.html` /
  `wp-config-sample.php` — the v1.12.0 scan flagged all of that as `tampered`. Now only an
  **unexpected file inside a core directory** (a file that shouldn't be there → likely backdoor)
  is `tampered`; modified core, mismatched plugin files, and missing core files are `needs_review`
  with a "common on managed hosts, usually benign" note; the routinely-deleted files are ignored.

## [1.12.0] - 2026-07-07

### Added
- **`GET /security-scan`** — a core + plugin tamper scan run on the source site, in PHP (no
  WP-CLI/shell needed, works on managed hosts). Verifies WordPress core and each active plugin
  against the official WordPress.org checksums (`api.wordpress.org` core checksums +
  `downloads.wordpress.org/plugin-checksums`). Premium/custom plugins with no WP.org checksums are
  reported as `unverifiable` (normal), never `tampered`. Returns a structured verdict
  (`clean` / `needs_review` / `tampered`) + findings; the platform runs this during Analysis and
  surfaces it in the migration report, so a compromised source is flagged **before** cutover.
  Token-authed like every other endpoint; bounded (caps on plugins/files scanned) and
  `set_time_limit(0)` so a large site can't half-run.

## [1.11.0] - 2026-06-30

### Added
- **Per-folder zip export** (worker-driven, server-side migration): the KloudStack worker can now transfer one bounded zip per plugin/theme folder instead of thousands of individual files. A normal WordPress site is thousands of files because plugins bundle vendor trees (e.g. `wpdatatables` ships PhpSpreadsheet) — collapsing each folder into a single STORE-mode (uncompressed, near-zero CPU) zip cuts a ~12,000-file export down to ~22 transfers, and lets the deploy side unzip locally instead of doing ~12,000 per-file blob GETs.
  - `POST /content-folders` `{artifact}` → `{folders:[name], loose_files:[{path,size}]}` — the top-level layout of an artifact root (whitelisted: plugins/themes/mu-plugins/uploads).
  - `POST /zip-folder` `{artifact, folder, url, loose?}` → zips one top-level folder (or, with `loose:true`, only the artifact root's stray files like `index.php`/`hello.php`) and PUTs the archive to the worker-supplied blob URL. `folder` is confined to a single direct child of the artifact root (no traversal).
  - `BackgroundExport::zip_path_to_blob()` primitive: bounded per-folder zip build (no whole-site giant zip → no PHP execution-time/OOM risk) + blob PUT, reusing the existing streaming uploader.
- **Backwards compatible:** the per-file (`/content-manifest` + `/upload-file`) and legacy queue/ZIP paths are unchanged; the worker only uses the new endpoints when on plugin ≥ 1.11.0 with `MIGRATION_WORKER_DRIVEN_EXPORT` on.

## [1.10.0] - 2026-06-30

### Added
- **Thin worker-driven export primitives** (server-side migration, Phase 1): the KloudStack worker can now drive per-file content uploads itself instead of this plugin self-draining a WP-Cron queue.
  - `POST /content-manifest` `{artifact, offset?, limit?}` → resumable `{files:[{path,size}], total, next_offset}`; whitelisted artifacts (plugins/themes/mu-plugins/uploads), no path traversal.
  - `POST /upload-file` `{artifact, path, url}` → PUTs one file to a worker-supplied (already-encoded) blob URL; `path` is confined to the artifact root.
  - `BackgroundExport::upload_single_file()` primitive wrapping the existing blob PUT.
- **Backwards compatible:** the existing queue/drain export path is unchanged; the worker only uses these endpoints when its `MIGRATION_WORKER_DRIVEN_EXPORT` flag is on. Sites can run 1.10.0 with no behaviour change.

## [1.9.3] - 2026-06-29

### Fixed
- **Content/media upload fails on filenames with special characters**: `_run_content_stream()` and `_run_media_stream()` built the Azure Blob PUT URL by raw string interpolation of the file path, so any file with a space or URL-illegal character (e.g. brackets in PhpSpreadsheet fixtures such as `[Content_Types].xml`, or media files with spaces) produced a malformed URL and cURL aborted the **entire** content/media job with `URL using bad/illegal format or missing URL`. Each path segment is now `rawurlencode`d (preserving `/`); the blob is still stored under its real decoded name (Azure decodes the URL path).

## [1.9.2] - 2026-06-29

### Changed
- **Much faster content/media export (drain-loop)**: the background runner now drains the whole queue back-to-back within its `set_time_limit(600)` window via the new `BackgroundExport::drain_queue()`, instead of doing a single ~25 s batch and exiting — which left the rest of the work waiting ~60–90 s for the next WP-Cron tick / server nudge. On a low-traffic source site that turns a multi-hour large-plugin export (e.g. `wpdatatables` + PhpSpreadsheet, hundreds of files) into minutes. The per-batch time budget is capped at ~25 s so each batch checkpoints frequently and yields well before any host execution limit, so a process killed early loses nothing.

## [1.9.1] - 2026-06-29

### Fixed
- **export_media stall on large plugins**: `BackgroundExport::_run_content_stream()` and `_run_media_stream()` now yield (checkpoint + reschedule) on a **wall-clock time budget** derived from the host's `max_execution_time`, not just a fixed file count. A heavy plugin such as `wpdatatables` (which bundles the PhpSpreadsheet library — hundreds of per-file uploads) could previously exceed the PHP execution limit mid-batch and be killed *before* a checkpoint was written, causing the content export job to resume from the same point on every WP-Cron tick and **stall indefinitely**. The time guard guarantees durable incremental progress on every tick.

## [1.4.0] - 2026-06-05

### Changed
- **DB export: plain SQL stream, no gzip**: `BackgroundExport::_run_db_export()` now streams the `mysqldump` output directly to Azure Blob Storage as uncompressed SQL (`dump.sql`). The `gzip` pipe, temp `.sql.gz` file, and pre-upload gzip integrity check have been removed. Eliminates the CPU spike from compression on shared hosting accounts where MySQL/MariaDB runs locally on the same server.
- **`--quick` flag added to mysqldump**: Reads one row at a time rather than buffering the entire table in memory — reduces RAM usage on constrained shared hosting accounts.
- **`gzip_level` hint replaced with `stream_rate_limit_kbps`**: The agent can now throttle the mysqldump pipe rate (via `pv` if available) when CPU is elevated, instead of adjusting compression level. Falls back gracefully if `pv` is not installed.
- **MIME type updated**: Blob upload content type changed from `application/gzip` to `application/octet-stream`.

## [1.3.0] - 2026-03-24

### Added
- **`/discover` table analysis**: Added `table_count` (total tables in DB) and `table_row_counts` (per-core-table row counts for options, posts, postmeta, users, usermeta, terms, comments) to the `/discover` endpoint response. Used by the import task to cross-validate that imported data matches the source site analysis.

## [1.2.9] - 2026-03-24

### Fixed
- **DB export pipeline reliability**: Wrapped `mysqldump | gzip` in `bash -c 'set -o pipefail; ...'` so `exec()` returns mysqldump's exit code (not just gzip's) — previously a mid-stream mysqldump failure was silently swallowed.
- **Gzip output corruption**: Changed `2>&1` to `2>/dev/null` on the gzip side of the pipe to prevent stderr messages from being written into the `.gz` file.
- **Pre-upload integrity check**: Added `gzip -t` validation on the temp file before uploading to Azure Blob — catches truncated dumps from interrupted mysqldump, PHP timeout, or OOM scenarios.

## [1.2.8] - 2026-03-23

### Added
- **`/diagnostics` endpoint**: New `GET` endpoint returning a comprehensive snapshot of the export queue depth, per-job status/progress, WP-Cron schedule, PHP runtime capabilities (memory, exec, ZipArchive, fastcgi), hosting platform detection, and temp disk space. Used by the migration agent to diagnose stalled jobs before deciding on recovery actions.
- **`/process-queue` endpoint**: New `POST` endpoint that directly triggers the export queue processor. Intended as a recovery mechanism on Azure App Service where WP-Cron URL nudges via the Front Door CDN URL are unreliable. Uses `fastcgi_finish_request()` to respond immediately while processing continues in the background.

## [1.2.7] - 2026-07-14

### Added
- **`/export-site-content` endpoint**: New consolidated REST endpoint that accepts a
  `sas_urls` map (keyed by artifact: `plugins`, `themes`, `media`, `mu-plugins`,
  `custom-root`) plus optional `hints`, creates one background job per artifact, and
  returns `{ "jobs": { "<artifact>": "<job_id>" } }` with HTTP 202. Replaces the need
  to call `/upload-media` separately for each artifact type.
- **`BackgroundExport::_run_content_export()`**: New handler that ZIPs a given source
  directory (resolved from the artifact name) and uploads it to Azure Blob via SAS PUT.
  Supports the same `skip_extensions` and `max_file_size_mb` agent hints as
  `_run_media_upload()`. The special `'custom-root'` artifact exports only root-level
  files in `WP_CONTENT_DIR`, excluding standard sub-directories.
- **`BackgroundExport::enqueue()` extended**: Accepts an optional `$extra_data` array
  merged into the queue item, enabling the new `content_export` job type to carry
  `source_path` without breaking existing `db_export`/`media_upload` callers.

## [1.2.6] - 2026-03-22

### Added
- **Server-side hosting detection** (`/discover`): New fields `hosting_platform`,
  `exec_available`, `php_memory_limit_mb`, `php_max_execution_time`, and `disk_free_mb`
  added to the `/discover` response. `hosting_platform` is detected via environment
  variables and constants (`azure_app_service`, `wpe`, `wpvip`, `kinsta`, `other`),
  giving the migration agent reliable server-side truth rather than URL heuristics.
- **Agent hints channel**: `export_db` and `upload_media` endpoints now accept an
  optional `hints` JSON object in the request body. The hints are sanitised and stored
  in the job transient so `BackgroundExport` can apply them during processing.
- **Adaptive gzip compression**: `BackgroundExport::_run_db_export()` reads
  `gzip_level` from agent hints (default `4`). On a retry after a CPU timeout the
  agent can send `gzip_level: 1` to trade file size for significantly lower CPU cost.
- **Selective media export**: `BackgroundExport::_run_media_upload()` reads
  `skip_extensions` (array, e.g. `[".mp4",".mkv"]`) and `max_file_size_mb` (int)
  from agent hints. Files matching either filter are skipped and counted; the total
  is logged and stored in the job record so the agent can report on skipped content.

## [1.2.5] - 2026-03-22

### Fixed
- **Concurrency lock**: `process_queue()` now acquires a 10-minute transient mutex
  before processing. Prevents double-execution when the shutdown function and
  WP-Cron both fire simultaneously, which previously doubled CPU/memory usage.
- **Memory — streaming upload**: `_upload_file_to_blob()` now uses cURL streaming
  PUT instead of `wp_remote_request()` with `file_get_contents()`. The old approach
  loaded the entire file into PHP memory, causing OOM crashes on Azure App Service
  (default 128 MB limit) with large databases or media archives.
- **CPU — gzip level**: Changed `gzip -9` (max compression) to `gzip -4` in the
  mysqldump pipeline. `-9` caused CPU spikes on Azure App Service Consumption plans;
  `-4` gives a good compression ratio with significantly lower CPU cost.
- **ZIP progress reporting**: Removed incorrect `ZipArchive::close()` + `open()`
  flush loop (files added after the first 500 could be silently skipped). Progress
  is now updated every 100 files without closing/reopening the archive.
- **exec() guard**: `_run_db_export()` now checks `exec()` availability at the start
  and throws a clear error if it is disabled, rather than silently producing an empty
  dump file.
- **Time limits**: Both shutdown functions now allow 600 s (10 min) instead of 300 s
  to accommodate large sites on slower Azure App Service tiers.

## [1.2.4] - 2026-03-22

### Fixed
- Media upload job stuck at 0% on Azure App Service: applied the same
  `register_shutdown_function` + `fastcgi_finish_request()` fix to the
  `upload_media` endpoint that was applied to `export_db` in v1.2.3.
  The unreliable loopback `wp_remote_get('/?doing_wp_cron')` is now
  removed from both export stages.

## [1.2.3] - 2026-03-22

### Fixed
- DB export job stuck at 0% on Azure App Service: replaced unreliable loopback
  `wp_remote_get( '/?doing_wp_cron' )` (0.01 s timeout — too short for an Azure Front
  Door round-trip) with a `register_shutdown_function` + `fastcgi_finish_request()`
  approach. `process_queue()` now runs directly in the same PHP-FPM worker immediately
  after the 202 response is flushed, with no WP-Cron or loopback HTTP dependency.
  WP-Cron scheduling is retained as a belt-and-suspenders fallback.

## [1.2.2] - 2026-03-22

### Added
- Admin notice if plugin is installed to a wrong-named folder (e.g. `kloudstack-migration-3/`).
  Clearly instructs the user to deactivate, delete, and reinstall the ZIP clean.

## [1.2.1] - 2026-03-22

### Fixed
- `KLOUDSTACK_MIGRATION_VERSION` constant corrected to match plugin header version (was `1.0.0`, now `1.2.1`)

## [1.2.0] - 2026-03-22

### Added
- `POST /cancel-jobs` REST endpoint: clears the export queue and marks pending job
  transients as `cancelled`. Accepts an optional `{ "job_ids": [...] }` body to cancel
  specific jobs; when omitted, flushes the entire queue.
- KloudStack backend now calls `/cancel-jobs` best-effort whenever a migration fails or
  is paused, so orphaned export jobs are not left running on the source site.

## [1.1.0] - 2026-03-21

### Fixed
- WP-Cron not processing queued export jobs on low-traffic sites: after enqueuing a DB
  or media job, the endpoint now immediately fires a non-blocking request to
  `/?doing_wp_cron` so the job starts processing without waiting for organic site traffic.

### Added
- `discover()` endpoint now returns `wp_cron_enabled`, `cron_next_scheduled`, and
  `jobs_in_queue` so the migration agent can flag WP-Cron problems during profiling.

## [1.0.0] - 2026-03-18

### Added
- Initial release
- REST endpoint: site validation and connectivity check
- REST endpoint: database export (streamed)
- REST endpoint: media file enumeration and upload
- Admin settings page with token configuration and connection status
- Background export support via `BackgroundExport`
- WordPress 6.0+ and PHP 8.1+ compatibility
