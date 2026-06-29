<?php
/**
 * Parallel image sideloader for the product CSV import flow.
 *
 * @package TejCart\Admin\Import
 */

declare( strict_types=1 );

namespace TejCart\Admin\Import;

use TejCart\Security\Network;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sideloads remote product images in parallel batches.
 *
 * The default per-row inline sideload in {@see \TejCart\Admin\Product_Import_Export::sideload_product_image()}
 * is fine for 10–50 images but dominates wall-clock time on a 15K-row catalog
 * (a single round-trip * 15K = hours, even on a fast connection). This helper
 * compresses the three biggest per-image costs into batched primitives:
 *
 *   1. Duplicate detection — one indexed SELECT per batch maps every URL to
 *      an existing attachment id, replacing N `get_posts()` + meta_query
 *      round-trips. See {@see prefetch_existing()}.
 *
 *   2. HTTP fetch — URLs flow through {@see \WpOrg\Requests\Requests::request_multiple()}
 *      so curl_multi pipelines them concurrently. Concurrency is auto-derived
 *      from the PHP memory_limit (clamped 4..32) and overridable per call.
 *
 *   3. Sideload + thumbnails — still per-image (subsizes are CPU-heavy and
 *      not parallelisable in-process) but the post-fetch survivors are the
 *      only ones we pay for.
 *
 * SSRF / DNS-rebinding guards from sideload_product_image() are preserved
 * per URL: each URL is validated with {@see Network::is_safe_remote_url()}
 * before queuing, and the host is re-resolved after the fetch to catch
 * an authoritative DNS flip between validation and request (see
 * {@see post_fetch_validate()}).
 */
final class Image_Sideloader {

    /**
     * Internal cap on the auto-derived default concurrency.
     *
     * Real-world payment-grade hosts handle 32 parallel CDN fetches comfortably;
     * shared hosts saturate earlier. This is the upper bound only — the actual
     * default scales with PHP memory_limit and can be raised by the caller.
     */
    private const MAX_AUTO_CONCURRENCY = 32;

    /**
     * Lower bound on auto-derived concurrency. A box with 64MB of PHP memory
     * still benefits from at least 4 parallel fetches vs. the sequential
     * baseline; below that the gain is negligible compared to the request
     * setup overhead.
     */
    private const MIN_AUTO_CONCURRENCY = 4;

    /**
     * Chunk size for the duplicate-URL prefetch SELECT. MySQL packet defaults
     * are 4MB-16MB; 500 URLs * ~200 bytes = 100KB, well under the wire.
     */
    private const PREFETCH_CHUNK = 500;

    /**
     * Recommended concurrency for the current PHP process.
     *
     * Scales linearly with `memory_limit` (one slot per ~64MB of headroom),
     * clamped to [{@see MIN_AUTO_CONCURRENCY}, {@see MAX_AUTO_CONCURRENCY}].
     * The clamp exists because (a) Requests::request_multiple uses curl_multi
     * which serialises beyond ~32 sockets per process on most hosts, and
     * (b) public CDNs (Cloudfront / Cloudflare) tend to rate-limit a single
     * source IP past that band anyway.
     *
     * Filterable via `tejcart_import_image_concurrency_default` so ops can
     * pin a value globally (e.g. force 16 on a host with quirky curl).
     */
    public static function default_concurrency(): int {
        $bytes = self::memory_limit_bytes();
        if ( $bytes <= 0 ) {
            $auto = self::MIN_AUTO_CONCURRENCY;
        } else {
            $auto = (int) floor( $bytes / ( 64 * 1024 * 1024 ) );
            $auto = max( self::MIN_AUTO_CONCURRENCY, min( self::MAX_AUTO_CONCURRENCY, $auto ) );
        }

        /**
         * Filter the auto-derived default image-sideload concurrency.
         *
         * Returned value is clamped to [1, 64] regardless of filter — going
         * higher than 64 produces marginal speedup and risks exhausting
         * ephemeral socket ports on the host.
         *
         * @param int $auto Auto-derived value (4..32).
         */
        $value = (int) apply_filters( 'tejcart_import_image_concurrency_default', $auto );
        return max( 1, min( 64, $value ) );
    }

    /**
     * Resolve `php_ini` `memory_limit` to bytes. Returns 0 when the value
     * is `-1` (unlimited) or unparseable so callers fall back to a safe
     * default rather than an unbounded slot count.
     */
    private static function memory_limit_bytes(): int {
        $raw = function_exists( 'ini_get' ) ? (string) ini_get( 'memory_limit' ) : '';
        if ( '' === $raw || '-1' === $raw ) {
            return 0;
        }
        $raw = trim( $raw );
        $unit = strtolower( substr( $raw, -1 ) );
        $num  = (int) $raw;
        if ( $num <= 0 ) {
            return 0;
        }
        switch ( $unit ) {
            case 'g':
                return $num * 1024 * 1024 * 1024;
            case 'm':
                return $num * 1024 * 1024;
            case 'k':
                return $num * 1024;
            default:
                return $num;
        }
    }

    /**
     * Look up which of the given source URLs already correspond to an
     * existing attachment, in one indexed SELECT per {@see PREFETCH_CHUNK}.
     *
     * The legacy per-URL `get_posts()` with `meta_query` performs a
     * `posts JOIN postmeta` and fires the full posts_results filter chain
     * per call — 15K of those is the single largest hidden cost in the
     * old import path. The bulk lookup hits the `meta_value` partial
     * index (WP 5.0+) once per chunk.
     *
     * @param string[] $urls Source URLs to resolve.
     * @return array<string, int> Map of url => attachment_id. URLs absent
     *                            from the map have never been imported.
     */
    public function prefetch_existing( array $urls ): array {
        global $wpdb;

        $urls = array_values(
            array_unique(
                array_filter(
                    array_map( 'strval', $urls ),
                    static fn( $u ) => '' !== $u
                )
            )
        );
        if ( empty( $urls ) ) {
            return array();
        }

        $map = array();
        foreach ( array_chunk( $urls, self::PREFETCH_CHUNK ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $params       = array_merge( array( '_tejcart_source_url' ), $chunk );

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $sql = $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ({$placeholders})",
                $params
            );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( ! is_array( $rows ) ) {
                continue;
            }
            foreach ( $rows as $row ) {
                $url = (string) ( $row['meta_value'] ?? '' );
                $pid = (int) ( $row['post_id'] ?? 0 );
                if ( '' !== $url && $pid > 0 && ! isset( $map[ $url ] ) ) {
                    $map[ $url ] = $pid;
                }
            }
        }

        return $map;
    }

    /**
     * Sideload a batch of remote images in parallel.
     *
     * Each entry in `$jobs` describes one URL to sideload. The shape is:
     *
     *     [
     *       'url'          => 'https://cdn.example.com/widget.jpg',  // required
     *       'product_id'   => 42,                                     // required, used for attachment post_parent
     *       'product_name' => 'Blue Widget',                          // used for title/alt
     *       'key'          => 'unique-row-tag',                       // optional caller key for the return map
     *     ]
     *
     * Returns an array keyed by the caller's `key` (or the URL if no key was
     * given), with each value being one of:
     *
     *     [ 'attachment_id' => int,  'url' => string ]        // success
     *     [ 'error' => \WP_Error,    'url' => string ]        // SSRF reject / HTTP fail / sideload fail
     *
     * Options:
     *
     *     - `concurrency` (int)  — parallel sockets; defaults to {@see default_concurrency()}
     *     - `timeout`     (int)  — per-request timeout in seconds (default 15)
     *     - `prefetched`  (array)— url => attachment_id map skipping re-download
     *                              (built by {@see prefetch_existing()}; callers can
     *                              pre-resolve across the whole import for one
     *                              SELECT total instead of per-batch)
     *
     * @param array<int, array<string, mixed>> $jobs
     * @param array<string, mixed>             $options
     * @return array<string, array<string, mixed>>
     */
    public function sideload_batch( array $jobs, array $options = array() ): array {
        if ( empty( $jobs ) ) {
            return array();
        }

        $concurrency = isset( $options['concurrency'] ) ? (int) $options['concurrency'] : self::default_concurrency();
        $concurrency = max( 1, min( 64, $concurrency ) );
        $timeout     = isset( $options['timeout'] ) ? (int) $options['timeout'] : 0;
        if ( $timeout < 1 ) {
            $timeout = (int) apply_filters( 'tejcart_import_image_download_timeout', 15 );
            $timeout = max( 1, $timeout );
        }
        $prefetched = isset( $options['prefetched'] ) && is_array( $options['prefetched'] )
            ? $options['prefetched']
            : array();

        $results = array();

        $allow_remote = (bool) apply_filters(
            'tejcart_allow_remote_image_import',
            'no' !== (string) get_option( 'tejcart_allow_remote_image_import', 'yes' )
        );
        if ( ! $allow_remote ) {
            $err = new \WP_Error(
                'tejcart_remote_image_disabled',
                __( 'Remote image imports are disabled in TejCart settings.', 'tejcart' )
            );
            foreach ( $jobs as $job ) {
                $key             = $this->job_key( $job );
                $results[ $key ] = array( 'error' => $err, 'url' => (string) ( $job['url'] ?? '' ) );
            }
            return $results;
        }

        // Pre-validate URLs and short-circuit any that resolve to a known
        // existing attachment (either via the caller-supplied prefetch or
        // an inline lookup over the unsubmitted set).
        $to_fetch = array();
        $batch_urls = array();
        foreach ( $jobs as $job ) {
            $url = isset( $job['url'] ) ? (string) $job['url'] : '';
            $url = esc_url_raw( $url );
            if ( '' === $url ) {
                continue;
            }
            $batch_urls[] = $url;
        }
        $batch_urls = array_values( array_unique( $batch_urls ) );

        // If the caller didn't pre-resolve, do a single batch lookup now
        // so the per-URL existence probe is O(1) instead of O(N).
        $local_existing = $prefetched;
        $unknown_urls   = array_values( array_diff( $batch_urls, array_keys( $local_existing ) ) );
        if ( ! empty( $unknown_urls ) ) {
            $local_existing = $local_existing + $this->prefetch_existing( $unknown_urls );
        }

        foreach ( $jobs as $job ) {
            $key  = $this->job_key( $job );
            $url  = esc_url_raw( (string) ( $job['url'] ?? '' ) );
            $name = (string) ( $job['product_name'] ?? '' );

            if ( '' === $url ) {
                $results[ $key ] = array(
                    'error' => new \WP_Error(
                        'tejcart_image_empty_url',
                        __( 'Empty image URL.', 'tejcart' )
                    ),
                    'url'   => '',
                );
                continue;
            }

            if ( isset( $local_existing[ $url ] ) ) {
                $results[ $key ] = array(
                    'attachment_id' => (int) $local_existing[ $url ],
                    'url'           => $url,
                    'cached'        => true,
                );
                continue;
            }

            if ( ! Network::is_safe_remote_url( $url ) ) {
                $results[ $key ] = array(
                    'error' => new \WP_Error(
                        'tejcart_unsafe_image_url',
                        sprintf(
                            /* translators: %s: URL */
                            __( 'Refused to fetch image from unsafe URL: %s', 'tejcart' ),
                            $url
                        )
                    ),
                    'url'   => $url,
                );
                continue;
            }

            $to_fetch[ $key ] = array(
                'url'          => $url,
                'product_id'   => isset( $job['product_id'] ) ? (int) $job['product_id'] : 0,
                'product_name' => '' !== $name ? $name : $url,
            );
        }

        if ( empty( $to_fetch ) ) {
            return $results;
        }

        // Slice into concurrency-sized waves so peak memory stays bounded
        // at `concurrency * avg_image_size` rather than the whole batch.
        $waves = array_chunk( $to_fetch, $concurrency, true );
        foreach ( $waves as $wave ) {
            $wave_results = $this->fetch_wave( $wave, $timeout );
            foreach ( $wave_results as $k => $r ) {
                $results[ $k ] = $r;
            }
        }

        return $results;
    }

    /**
     * Fetch up to `concurrency` URLs in one Requests::request_multiple()
     * call, then sideload the successful responses individually. Failures
     * never block their wave-siblings: each URL gets its own result entry.
     *
     * @param array<string, array<string, mixed>> $wave    Subset of $to_fetch sized for one wave.
     * @param int                                 $timeout Per-request timeout in seconds.
     * @return array<string, array<string, mixed>>
     */
    private function fetch_wave( array $wave, int $timeout ): array {
        $results = array();

        $requests = array();
        foreach ( $wave as $key => $spec ) {
            $requests[ $key ] = array(
                'url'     => (string) $spec['url'],
                'type'    => \WpOrg\Requests\Requests::GET,
                'headers' => array(
                    'User-Agent' => $this->user_agent(),
                ),
            );
        }

        $options = array(
            'timeout'          => $timeout,
            'connect_timeout'  => min( 10, $timeout ),
            'follow_redirects' => true,
            'redirects'        => 5,
            'useragent'        => $this->user_agent(),
        );

        // SSRF defense: validate every redirect hop, not just the initial URL.
        // Without this an allowlisted public URL can 302 to
        // http://169.254.169.254/ (cloud metadata) or an internal service and
        // Requests would follow it server-side. The before_redirect hook fires
        // for each Location before Requests re-issues; throwing aborts the
        // redirect, and the resulting Exception is mapped to a per-URL error
        // by the response loop below (so one poisoned URL never blocks its
        // wave-siblings).
        $hooks = new \WpOrg\Requests\Hooks();
        $hooks->register(
            'requests.before_redirect',
            static function ( $location ) {
                if ( ! Network::is_safe_remote_url( (string) $location ) ) {
                    throw new \WpOrg\Requests\Exception(
                        'Refused to follow redirect to an unsafe (private/metadata/blocked) host: ' . (string) $location,
                        'tejcart.ssrf_redirect_blocked'
                    );
                }
            }
        );
        $options['hooks'] = $hooks;

        try {
            $responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
        } catch ( \Throwable $e ) {
            // The whole wave blew up at the curl_multi layer (very rare —
            // usually a missing curl ext). Map every URL to a generic error
            // so the caller doesn't lose them silently.
            foreach ( $wave as $key => $spec ) {
                $results[ $key ] = array(
                    'error' => new \WP_Error( 'tejcart_image_fetch_failed', $e->getMessage() ),
                    'url'   => (string) $spec['url'],
                );
            }
            return $results;
        }

        // Ensure WP's media helpers are available before sideloading.
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        foreach ( $wave as $key => $spec ) {
            $response = $responses[ $key ] ?? null;
            $url      = (string) $spec['url'];

            if ( $response instanceof \WpOrg\Requests\Exception ) {
                $results[ $key ] = array(
                    'error' => new \WP_Error( 'tejcart_image_http_error', $response->getMessage() ),
                    'url'   => $url,
                );
                continue;
            }

            if ( ! ( $response instanceof \WpOrg\Requests\Response ) ) {
                $results[ $key ] = array(
                    'error' => new \WP_Error(
                        'tejcart_image_http_error',
                        __( 'Image fetch returned no response.', 'tejcart' )
                    ),
                    'url'   => $url,
                );
                continue;
            }

            if ( ! $response->success ) {
                $status          = (int) $response->status_code;
                $results[ $key ] = array(
                    'error' => new \WP_Error(
                        'tejcart_image_http_error',
                        sprintf(
                            /* translators: 1: HTTP status code, 2: URL */
                            __( 'Image fetch failed (HTTP %1$d) for %2$s', 'tejcart' ),
                            $status,
                            $url
                        )
                    ),
                    'url'   => $url,
                );
                continue;
            }

            // Validate both the original URL's host (DNS-rebinding window) and
            // the final post-redirect URL (belt-and-braces with the
            // before_redirect hook above, in case a transport followed a hop
            // without firing it).
            $rebind_error = $this->post_fetch_validate( $url );
            if ( null === $rebind_error ) {
                $final_url = isset( $response->url ) ? (string) $response->url : '';
                if ( '' !== $final_url && $final_url !== $url ) {
                    $rebind_error = $this->post_fetch_validate( $final_url );
                }
            }
            if ( null !== $rebind_error ) {
                $results[ $key ] = array( 'error' => $rebind_error, 'url' => $url );
                continue;
            }

            $tmp = $this->write_temp_body( $response->body, $url );
            if ( is_wp_error( $tmp ) ) {
                $results[ $key ] = array( 'error' => $tmp, 'url' => $url );
                continue;
            }

            $attachment_id = $this->sideload_one(
                $tmp,
                $url,
                (string) $spec['product_name']
            );

            if ( is_wp_error( $attachment_id ) ) {
                $results[ $key ] = array( 'error' => $attachment_id, 'url' => $url );
                continue;
            }

            $results[ $key ] = array(
                'attachment_id' => (int) $attachment_id,
                'url'           => $url,
            );
        }

        return $results;
    }

    /**
     * Re-resolve the host post-fetch and reject the body if any returned
     * address falls in the private / metadata range. Mirrors the M-04
     * mitigation already in {@see \TejCart\Admin\Product_Import_Export::sideload_product_image()}.
     */
    private function post_fetch_validate( string $url ): ?\WP_Error {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            return null;
        }
        $ips = Network::resolve_host( strtolower( $host ) );
        foreach ( $ips as $ip ) {
            if ( Network::is_private_ip( $ip ) ) {
                return new \WP_Error(
                    'tejcart_unsafe_image_url',
                    sprintf(
                        /* translators: %s: URL */
                        __( 'Refused to import image — host resolved to a private/metadata IP after download (possible DNS rebinding): %s', 'tejcart' ),
                        $url
                    )
                );
            }
        }
        return null;
    }

    /**
     * Persist an in-memory response body to a temp file so
     * {@see media_handle_sideload()} can take it from there.
     *
     * @return string|\WP_Error Temp file path on success.
     */
    private function write_temp_body( string $body, string $url ) {
        if ( '' === $body ) {
            return new \WP_Error(
                'tejcart_image_empty_body',
                sprintf(
                    /* translators: %s: URL */
                    __( 'Image fetch returned an empty body for %s', 'tejcart' ),
                    $url
                )
            );
        }

        $url_path = wp_parse_url( $url, PHP_URL_PATH );
        $name     = $url_path ? basename( (string) $url_path ) : '';
        $name     = sanitize_file_name( $name );
        if ( '' === $name ) {
            $name = 'image-' . wp_generate_password( 8, false );
        }

        if ( ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = wp_tempnam( $name );
        if ( ! $tmp ) {
            return new \WP_Error(
                'tejcart_image_tempfile_failed',
                __( 'Could not allocate a temporary file for the downloaded image.', 'tejcart' )
            );
        }

        $written = file_put_contents( $tmp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === $written || $written !== strlen( $body ) ) {
            if ( file_exists( $tmp ) ) {
                wp_delete_file( $tmp );
            }
            return new \WP_Error(
                'tejcart_image_tempfile_write_failed',
                __( 'Could not write the downloaded image to disk.', 'tejcart' )
            );
        }

        return $tmp;
    }

    /**
     * Hand a single downloaded temp file to WP's sideload pipeline and
     * tag the resulting attachment with `_tejcart_source_url` so future
     * imports of the same catalog can dedup without re-fetching.
     *
     * @return int|\WP_Error Attachment id.
     */
    private function sideload_one( string $tmp_path, string $url, string $product_name ) {
        $base_name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        if ( '' === $base_name || false === strpos( $base_name, '.' ) ) {
            $mime = wp_check_filetype( $tmp_path );
            if ( empty( $mime['ext'] ) && function_exists( 'mime_content_type' ) ) {
                $detected = mime_content_type( $tmp_path );
                $map      = array(
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                );
                $ext = $map[ $detected ] ?? 'jpg';
            } else {
                $ext = ! empty( $mime['ext'] ) ? $mime['ext'] : 'jpg';
            }
            $base_name = sanitize_title( '' !== $product_name ? $product_name : 'image' ) . '.' . $ext;
        }

        $file_array = array(
            'name'     => $base_name,
            'tmp_name' => $tmp_path,
        );

        $attachment_id = media_handle_sideload(
            $file_array,
            0,
            $product_name,
            array(
                'post_title'   => $product_name,
                'post_excerpt' => $product_name,
            )
        );

        if ( is_wp_error( $attachment_id ) ) {
            if ( file_exists( $tmp_path ) ) {
                wp_delete_file( $tmp_path );
            }
            return $attachment_id;
        }

        update_post_meta( (int) $attachment_id, '_tejcart_source_url', $url );
        update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $product_name );

        return (int) $attachment_id;
    }

    /**
     * Stable User-Agent so origin servers can identify TejCart traffic in
     * their access logs and apply whatever rate-limit they want without
     * blocking the rest of "WordPress".
     */
    private function user_agent(): string {
        $version = defined( 'TEJCART_VERSION' ) ? (string) TEJCART_VERSION : '1.0';
        return 'TejCart/' . $version . ' Importer';
    }

    /**
     * Per-job identifier used in the result map. Caller-supplied `key` wins
     * so multi-image rows (main + N gallery) don't collide on URL.
     *
     * @param array<string, mixed> $job
     */
    private function job_key( array $job ): string {
        if ( isset( $job['key'] ) && '' !== (string) $job['key'] ) {
            return (string) $job['key'];
        }
        return (string) ( $job['url'] ?? '' );
    }
}
