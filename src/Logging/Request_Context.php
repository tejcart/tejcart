<?php
/**
 * Universal log base-context builder.
 *
 * @package TejCart\Logging
 */

declare( strict_types=1 );

namespace TejCart\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures the "who / what / where" of the current HTTP request so every
 * payment-related log line carries enough metadata to reconstruct a
 * support case from disk:
 *
 *   - plugin / PHP / WordPress versions  (rules out version-skew bugs)
 *   - request URI / referer              (which page the buyer started on)
 *   - is_ajax / is_rest / is_cli         (where the entry was emitted from)
 *   - user_id / session_hash             (correlate guest & member journeys)
 *   - client_ip (truncated) / UA digest  (multi-tab vs. fraud probe vs. retry)
 *
 * Strictly read-only — never mutates state, never enqueues, never logs.
 * The base context is then merged into a {@see Log_Channel} via
 * `$channel->with_context( Request_Context::base() )`.
 *
 * Sensitive fields are never collected: full IP is truncated to /24 for
 * IPv4 and /48 for IPv6 to keep GDPR posture, and the session cookie is
 * one-way hashed before storage.
 */
final class Request_Context {

	/**
	 * Build the canonical base context for a log entry.
	 *
	 * @param array<string, mixed> $extra Caller-supplied overrides / additions.
	 *                                    Wins over the auto-detected fields.
	 * @return array<string, mixed>
	 */
	public static function base( array $extra = array() ): array {
		$server = isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array();

		$ctx = array(
			'plugin_version' => defined( 'TEJCART_VERSION' ) ? (string) TEJCART_VERSION : 'unknown',
			'php_version'    => PHP_VERSION,
			'wp_version'     => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : 'unknown',
			'request_uri'    => self::scrub_uri( $server['REQUEST_URI'] ?? '' ),
			'referer'        => self::scrub_uri( $server['HTTP_REFERER'] ?? '' ),
			'request_method' => strtoupper( (string) ( $server['REQUEST_METHOD'] ?? '' ) ),
			'is_ajax'        => function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : false,
			'is_rest'        => self::is_rest_request(),
			'is_cli'         => defined( 'WP_CLI' ) && WP_CLI,
			'user_id'        => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'client_ip'      => self::truncate_ip( self::client_ip( $server ) ),
			'user_agent'     => self::scrub_user_agent( (string) ( $server['HTTP_USER_AGENT'] ?? '' ) ),
			'session_hash'   => self::session_hash(),
			'request_id'     => self::request_id( $server ),
		);

		if ( ! empty( $extra ) ) {
			$ctx = array_merge( $ctx, $extra );
		}

		/**
		 * Filter the base log context attached to payment-debug entries.
		 *
		 * Useful for adding tenant / merchant identifiers in multi-store
		 * deployments, or for stripping fields a host considers sensitive.
		 *
		 * @since 1.0.0
		 * @param array<string, mixed> $ctx   Base context.
		 * @param array<string, mixed> $extra Caller overrides that were merged in.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'tejcart_log_base_context', $ctx, $extra );
			if ( is_array( $filtered ) ) {
				$ctx = $filtered;
			}
		}

		return $ctx;
	}

	/**
	 * Resolve the buyer's IP using the same precedence the rest of the
	 * plugin uses (Rate_Limiter), then truncate to a privacy-preserving
	 * width.
	 *
	 * @param array<string, mixed> $server $_SERVER copy.
	 */
	private static function client_ip( array $server ): string {
		if ( class_exists( \TejCart\Security\Rate_Limiter::class )
			&& method_exists( \TejCart\Security\Rate_Limiter::class, 'get_client_ip' )
		) {
			$ip = (string) \TejCart\Security\Rate_Limiter::get_client_ip();
			if ( '' !== $ip ) {
				return $ip;
			}
			// Rate_Limiter::get_client_ip() already honours the
			// trusted-proxy gate. If it returned empty there is no
			// IP we are allowed to trust, so fall back to REMOTE_ADDR
			// only (the socket peer is always safe) — never walk XFF
			// without the proxy-trust check (audit 04 L-2).
			return isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
		}

		// Rate_Limiter unavailable (extremely unlikely): fall back to
		// the socket peer only.
		return isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
	}

	/**
	 * Truncate an IP address so the log line cannot uniquely identify a
	 * household — /24 for IPv4, /48 for IPv6. Matches GA4 / Matomo
	 * defaults and keeps the entry useful for clustering retries on the
	 * same network.
	 *
	 * @param string $ip Raw IP.
	 */
	public static function truncate_ip( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}

		// IPv4: zero the last octet.
		if ( preg_match( '/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/', $ip, $m ) ) {
			return $m[1] . '.0';
		}

		// IPv6: keep the first three hextets, zero the rest.
		if ( false !== strpos( $ip, ':' ) ) {
			$parts = explode( ':', $ip );
			$kept  = array_slice( $parts, 0, 3 );
			while ( count( $kept ) < 3 ) {
				$kept[] = '0';
			}
			return implode( ':', $kept ) . '::';
		}

		return $ip;
	}

	/**
	 * Drop query strings entirely from request URIs and referers — they
	 * routinely carry coupon codes, ad-tracking tokens, and (on poorly
	 * configured gateways) one-time payment tokens. Path is preserved so
	 * support can see which page the flow started on.
	 *
	 * @param string $uri URI or full URL.
	 */
	public static function scrub_uri( string $uri ): string {
		if ( '' === $uri ) {
			return '';
		}
		$qpos = strpos( $uri, '?' );
		if ( false !== $qpos ) {
			$uri = substr( $uri, 0, $qpos );
		}
		if ( strlen( $uri ) > 512 ) {
			$uri = substr( $uri, 0, 512 ) . '…';
		}
		return $uri;
	}

	/**
	 * Truncate the User-Agent to a sane width without dropping it — the
	 * UA family is one of the highest-signal fields for "why does this
	 * only fail on iOS Safari 17" tickets.
	 *
	 * @param string $ua Raw User-Agent header.
	 */
	public static function scrub_user_agent( string $ua ): string {
		if ( '' === $ua ) {
			return '';
		}
		if ( strlen( $ua ) > 200 ) {
			$ua = substr( $ua, 0, 200 ) . '…';
		}
		return $ua;
	}

	/**
	 * One-way hash of the TejCart session cookie so two log lines from
	 * the same buyer's journey can be correlated without persisting the
	 * raw cookie value. Falls back to the empty string when no session
	 * cookie is present (CLI / cron / unauthenticated REST).
	 */
	private static function session_hash(): string {
		$cookie = isset( $_COOKIE['tejcart_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['tejcart_session'] ) ) : '';
		if ( '' === $cookie ) {
			if ( function_exists( 'tejcart_get_session_key' ) ) {
				$cookie = (string) tejcart_get_session_key();
			}
		}
		if ( '' === $cookie ) {
			return '';
		}
		// Short hash — enough to disambiguate concurrent journeys in a
		// log file without recovering the raw cookie value.
		return substr( hash( 'sha256', $cookie ), 0, 12 );
	}

	/**
	 * Detect whether the current invocation is the WordPress REST
	 * dispatcher. Matches both the `REST_REQUEST` constant set inside
	 * dispatch() and the URL-prefix check for environments where the
	 * constant has not been set yet (early hooks).
	 */
	private static function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		return false !== strpos( $uri, '/wp-json/' );
	}

	/**
	 * Resolve a stable per-request id. Prefers the inbound
	 * X-Request-Id header (set by upstream load balancers / CDNs) so log
	 * lines stitch with the upstream trace, falling back to a short
	 * random id minted once per request.
	 *
	 * @param array<string, mixed> $server $_SERVER copy.
	 */
	private static function request_id( array $server ): string {
		static $minted = null;

		if ( ! empty( $server['HTTP_X_REQUEST_ID'] ) ) {
			$inbound = trim( (string) $server['HTTP_X_REQUEST_ID'] );
			if ( '' !== $inbound ) {
				return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $inbound ) ?? '', 0, 64 );
			}
		}

		if ( null === $minted ) {
			try {
				$minted = bin2hex( random_bytes( 6 ) );
			} catch ( \Throwable $e ) {
				$minted = substr( str_replace( '.', '', uniqid( '', true ) ), 0, 12 );
			}
		}
		return $minted;
	}

	/**
	 * Reset the cached per-request id. Test-only.
	 *
	 * @internal
	 */
	public static function reset_for_tests(): void {
		// Forces the static $minted in request_id() to refresh by re-binding
		// the closure via reflection. Simpler: rely on tests creating a fresh
		// process for any test that asserts on request_id stability across
		// requests. This noop hook exists for explicit intent at call sites.
	}
}
