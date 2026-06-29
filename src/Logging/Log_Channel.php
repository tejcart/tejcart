<?php
/**
 * Named log channel — a PSR-3 logger bound to a single source file.
 *
 * @package TejCart\Logging
 */

declare( strict_types=1 );

namespace TejCart\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One log channel per module (payment, tax, shipping, discount, ...).
 *
 * Every entry written through this class is routed into its own
 * per-source file under `{uploads}/tejcart-logs/` by `tejcart_log()`,
 * which keeps each module's history isolated and replayable. Channels
 * are PSR-3-compatible (`emergency`/`alert`/`critical`/`error`/
 * `warning`/`notice`/`info`/`debug`) and respect the global
 * `tejcart_log_level` gate.
 *
 * Channels also carry a per-instance correlation id so a single HTTP
 * round trip (request → response → finalization) can be reconstructed
 * from disk without grepping by timestamp. Use {@see request()} /
 * {@see response()} for outbound API calls and {@see span()} to scope
 * a unit of work under a fresh correlation id.
 */
final class Log_Channel {

	/** Mirror of PSR-3 levels for fast iteration / validation. */
	public const LEVELS = array(
		'emergency',
		'alert',
		'critical',
		'error',
		'warning',
		'notice',
		'info',
		'debug',
	);

	private string $name;

	private string $correlation_id;

	/** Common context merged into every entry on this channel. */
	private array $base_context = array();

	/**
	 * @param string $name Channel name (becomes the log filename prefix
	 *                     after `sanitize_key`, e.g. `payment`,
	 *                     `payment_paypal`, `tax`, `tax_taxjar`).
	 */
	public function __construct( string $name ) {
		$name = function_exists( 'sanitize_key' )
			? sanitize_key( $name )
			: strtolower( preg_replace( '/[^a-z0-9_\-]+/i', '_', $name ) ?? '' );
		if ( '' === $name ) {
			$name = 'tejcart';
		}
		$this->name           = $name;
		$this->correlation_id = self::generate_correlation_id();
	}

	/** Channel name (sanitized). */
	public function name(): string {
		return $this->name;
	}

	/** Active correlation id for this channel instance. */
	public function correlation_id(): string {
		return $this->correlation_id;
	}

	/**
	 * Force a specific correlation id (e.g. inherit one from an upstream
	 * request id header). Returns the channel for chaining.
	 */
	public function with_correlation_id( string $id ): self {
		$id = trim( $id );
		if ( '' !== $id ) {
			$this->correlation_id = substr( $id, 0, 64 );
		}
		return $this;
	}

	/**
	 * Merge static fields into every subsequent entry on this channel
	 * (e.g. `gateway => paypal`, `mode => sandbox`, `order_id => 42`).
	 */
	public function with_context( array $context ): self {
		$this->base_context = array_merge( $this->base_context, $context );
		return $this;
	}

	/**
	 * Open a sub-span: returns a clone with a fresh correlation id so
	 * concurrent work under the same channel doesn't share an id.
	 */
	public function span( ?string $correlation_id = null ): self {
		$child                 = clone $this;
		$child->correlation_id = '' !== (string) $correlation_id
			? substr( (string) $correlation_id, 0, 64 )
			: self::generate_correlation_id();
		return $child;
	}

	public function emergency( string $message, array $context = array() ): void { $this->log( 'emergency', $message, $context ); }
	public function alert(     string $message, array $context = array() ): void { $this->log( 'alert',     $message, $context ); }
	public function critical(  string $message, array $context = array() ): void { $this->log( 'critical',  $message, $context ); }
	public function error(     string $message, array $context = array() ): void { $this->log( 'error',     $message, $context ); }
	public function warning(   string $message, array $context = array() ): void { $this->log( 'warning',   $message, $context ); }
	public function notice(    string $message, array $context = array() ): void { $this->log( 'notice',    $message, $context ); }
	public function info(      string $message, array $context = array() ): void { $this->log( 'info',      $message, $context ); }
	public function debug(     string $message, array $context = array() ): void { $this->log( 'debug',     $message, $context ); }

	/**
	 * Emit an entry at the given PSR-3 level.
	 *
	 * Unknown levels fall back to `info` to match `tejcart_log()`.
	 *
	 * @param string               $level   PSR-3 level.
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Extra structured fields.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'tejcart_log' ) ) {
			return;
		}

		$payload = array_merge(
			$this->base_context,
			array(
				'channel'        => $this->name,
				'correlation_id' => $this->correlation_id,
			),
			$context,
			array( 'source' => $this->name )
		);

		// PSR-3 placeholder interpolation. Done BEFORE redaction so
		// the substituted value also gets scrubbed (e.g. a
		// `{api_key}` template renders to `[REDACTED]` instead of
		// leaking the raw value into the message).
		if ( false !== strpos( $message, '{' ) ) {
			$message = Log_Writer::interpolate( $message, $payload );
		}

		// PSR-3 `$context['exception']` Throwable special case —
		// stringify into a queryable structure so the JSON Lines
		// format can keep it as a sub-object, and the text format
		// renders the class + message inline.
		if ( isset( $payload['exception'] ) && $payload['exception'] instanceof \Throwable ) {
			$payload['exception'] = Log_Writer::format_exception( $payload['exception'] );
		}

		$max_length = Log_Writer::max_string_length();

		$payload = Redactor::redact( $payload, $max_length );

		// `source` is consumed by tejcart_log() as the per-channel
		// file router. Keep it intact post-redaction (it cannot contain
		// secrets — it's our own channel name).
		$payload['source'] = $this->name;

		$message = Redactor::scrub_string( $message, $max_length );

		tejcart_log( $message, $level, $payload );
	}

	/**
	 * Log an outbound HTTP request. Returns the correlation id used so
	 * the caller can pass it back to {@see response()} when the round
	 * trip completes.
	 *
	 * @param string               $method  HTTP verb.
	 * @param string               $url     Target URL (query string is logged as-is — sanitize at the call site if needed).
	 * @param array<string, mixed> $context Extra fields. Recognised: `headers`, `body`, `request_id`.
	 */
	public function request( string $method, string $url, array $context = array() ): string {
		$correlation = isset( $context['request_id'] ) && '' !== (string) $context['request_id']
			? substr( (string) $context['request_id'], 0, 64 )
			: $this->correlation_id;

		$entry = array_merge(
			$context,
			array(
				'event'          => 'http.request',
				'http_method'    => strtoupper( $method ),
				'url'            => $url,
				'correlation_id' => $correlation,
			)
		);
		unset( $entry['request_id'] );

		$this->log( 'debug', sprintf( '→ %s %s', strtoupper( $method ), $url ), $entry );
		return $correlation;
	}

	/**
	 * Log an inbound HTTP response paired with a previously logged
	 * request. `status >= 500` is logged at `error`, `4xx` at `warning`,
	 * everything else at `debug`.
	 *
	 * @param int                  $status      HTTP status code (0 for transport errors).
	 * @param array<string, mixed> $context     Extra fields. Recognised: `headers`, `body`,
	 *                                          `duration_ms`, `request_id`, `error`.
	 */
	public function response( int $status, array $context = array() ): void {
		$level = 'debug';
		if ( $status >= 500 || 0 === $status ) {
			$level = 'error';
		} elseif ( $status >= 400 ) {
			$level = 'warning';
		}

		$correlation = isset( $context['request_id'] ) && '' !== (string) $context['request_id']
			? substr( (string) $context['request_id'], 0, 64 )
			: $this->correlation_id;

		$entry = array_merge(
			$context,
			array(
				'event'          => 'http.response',
				'http_status'    => $status,
				'correlation_id' => $correlation,
			)
		);
		unset( $entry['request_id'] );

		$this->log( $level, sprintf( '← HTTP %d', $status ), $entry );
	}

	/**
	 * Generate a 16-byte hex correlation id. Falls back to `uniqid()`
	 * if `random_bytes` isn't available (shouldn't happen on PHP 8.2+,
	 * but the fallback keeps the test bootstrap happy).
	 */
	private static function generate_correlation_id(): string {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return substr( str_replace( '.', '', uniqid( '', true ) ), 0, 16 );
		}
	}
}
