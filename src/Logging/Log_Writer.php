<?php
/**
 * Central file-based log writer.
 *
 * @package TejCart\Logging
 */

declare( strict_types=1 );

namespace TejCart\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Industry-grade file writer that powers every TejCart log helper.
 *
 * Why this class exists: prior to its introduction the file-writing
 * logic was duplicated across `tejcart_log()`, `tejcart_tax_log()`,
 * `tejcart_shipping_log()` and `tejcart_discount_log()` — ~50 lines of
 * `error_log( $entry, 3, $file )` plus `.htaccess` and `index.html`
 * boilerplate, copied four times. That duplication meant:
 *
 *   - Three of the four helpers silently bypassed the
 *     `tejcart_log_writer` filter, so operators on Datadog / CloudWatch
 *     saw a partial view of TejCart's chatter.
 *   - `error_log(..., 3, $file)` is not atomic on POSIX — two PHP-FPM
 *     workers writing to the same file inside the same microsecond can
 *     interleave bytes and corrupt a line. Production stores running
 *     more than one worker see this in practice.
 *   - There was no size-based rotation, so a single noisy day could
 *     balloon a file to multi-gigabyte and exhaust inodes.
 *   - There was no PSR-3 `{placeholder}` interpolation and no
 *     `$context['exception']` handling despite the class advertising
 *     PSR-3 compatibility.
 *
 * What this class does that the previous implementation did not:
 *
 *   - Atomic writes via `file_put_contents( ..., FILE_APPEND | LOCK_EX )`
 *     — workers serialise on the OS-level file lock so each entry
 *     lands intact.
 *   - Explicit `chmod( 0640 )` on the log file so it is not
 *     world-readable irrespective of the host's umask.
 *   - Size-based rotation: when the active file exceeds
 *     `tejcart_log_max_bytes` (default 10 MiB), it is renamed with a
 *     `.1`, `.2`, …`.N` suffix before the next line is written.
 *   - Optional NDJSON / JSON-Lines output (`tejcart_log_format` =
 *     `json`) for sites that ship logs to Datadog / Loki / CloudWatch.
 *     Text remains the default for backwards-compatible operator
 *     workflows (`tail -f` + `grep ERROR`).
 *   - PSR-3 message-template interpolation: `{user_id}` placeholders
 *     are replaced from the context array before redaction.
 *   - PSR-3 `$context['exception']` handling: stringifies the class,
 *     message, file, line and a compact frame list.
 *   - Control-character escaping in the rendered message so a hostile
 *     buyer-supplied string cannot inject newlines into the log.
 *   - Web-server access denial for both Apache (`.htaccess`) and IIS
 *     (`web.config`).
 *   - Failure tracking: when a write fails (disk full, perms changed
 *     out from under us) the last error is parked in a transient so
 *     the admin "System status" screen can surface it.
 *
 * All four helpers in `src/functions.php` now route through
 * {@see write()} so the `tejcart_log_writer` filter, the
 * `tejcart_log_level` gate, the {@see Redactor}, and the rotation /
 * format / interpolation features apply uniformly.
 *
 * Public API is deliberately narrow: callers should keep using
 * `tejcart_log()`, the channel-aware {@see Logger::channel()}, or the
 * domain-specific helpers. This class is the engine, not the steering
 * wheel.
 */
final class Log_Writer {

	/** Default max bytes per file before size-based rotation kicks in. 10 MiB. */
	public const DEFAULT_MAX_BYTES = 10485760;

	/** Default number of rotated siblings kept on disk. */
	public const DEFAULT_ROTATIONS = 5;

	/** File mode applied to every log file we create. */
	public const FILE_MODE = 0640;

	/** Directory mode applied to the log directory. */
	public const DIR_MODE = 0750;

	/** Transient key for the last-write-failure surface. */
	public const FAILURE_TRANSIENT = 'tejcart_log_writer_last_failure';

	/** PSR-3 levels in ascending severity order. */
	private const LEVELS = array(
		'emergency',
		'alert',
		'critical',
		'error',
		'warning',
		'notice',
		'info',
		'debug',
	);

	/**
	 * Public entry point — write a log entry to disk (or wherever the
	 * `tejcart_log_writer` filter routes it).
	 *
	 * Handles, in order:
	 *
	 *   1. Level-gating (drops entries below `tejcart_log_level`,
	 *      unless `$bypass_threshold` is true — see the always-on
	 *      `tejcart_tax_log()`/`tejcart_shipping_log()`/
	 *      `tejcart_discount_log()` helpers).
	 *   2. `off`-kill-switch enforcement (drops everything when the
	 *      operator has fully disabled logging, even in bypass mode).
	 *   3. PSR-3 placeholder interpolation in the message template.
	 *   4. `$context['exception']` Throwable formatting.
	 *   5. Redaction of message + context (PCI / PII / secrets) —
	 *      runs BEFORE the writer filter so no sink (Datadog,
	 *      CloudWatch, STDOUT, on-disk file) ever sees a raw secret.
	 *   6. `tejcart_log_writer` filter — short-circuits to a custom
	 *      sink with the redacted payload.
	 *   7. `TEJCART_LOG_TO_STDERR` constant / env — 12-factor STDERR
	 *      sink (also receives the redacted payload).
	 *   8. Control-character escaping in the rendered message.
	 *   9. Line rendering (text or JSON Lines).
	 *  10. Directory + web-server-config bootstrap.
	 *  11. Size-based rotation of the target file.
	 *  12. Atomic append-with-lock to disk + chmod 0640.
	 *  13. Opportunistic retention sweep.
	 *
	 * @param string               $message          Human-readable message. May
	 *                                               contain `{placeholders}` resolved
	 *                                               from `$context`.
	 * @param string               $level            PSR-3 level. Unknown values fall
	 *                                               back to `info`.
	 * @param array<string, mixed> $context          Structured fields. `source`
	 *                                               selects the per-channel file.
	 *                                               `exception` receives PSR-3
	 *                                               special handling.
	 * @param bool                 $bypass_threshold When true, skip the
	 *                                               `tejcart_log_level` severity gate
	 *                                               (used by the always-on tax /
	 *                                               shipping / discount helpers).
	 *                                               The `off` kill-switch is still
	 *                                               enforced.
	 */
	public static function write( string $message, string $level, array $context = array(), bool $bypass_threshold = false ): void {
		$level = strtolower( $level );
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		// "off" master switch — non-negotiable, applied even in
		// bypass mode so a merchant who explicitly turned logging off
		// never sees any TejCart writes.
		if ( function_exists( 'tejcart_log_level_threshold' ) && PHP_INT_MAX === tejcart_log_level_threshold() ) {
			return;
		}

		// Severity gate (skipped for always-on helpers).
		if ( ! $bypass_threshold && function_exists( 'tejcart_log_level_passes' ) && ! tejcart_log_level_passes( $level ) ) {
			return;
		}

		// Resolve channel name from `source` (canonical) — keep it short
		// and on-disk-safe.
		$source = self::resolve_source( $context );

		// --- Sanitisation pipeline -------------------------------
		// All redaction runs HERE, BEFORE the writer filter, so every
		// sink (custom writer, STDERR, disk) receives an already-safe
		// payload. Any operator-supplied sink can trust that
		// `$context` carries no PCI/PII/secret material.

		// PSR-3 placeholder interpolation. Done before redaction so
		// `{api_key}` substitution is also subject to scrubbing.
		$interpolated = self::interpolate( $message, $context );

		// Stringify the optional `exception` per PSR-3. Keep the raw
		// Throwable out of the downstream payload — it isn't
		// json_encodable and would otherwise leak the bound variables
		// referenced in its trace via spl_object_id.
		$exception_summary = null;
		if ( isset( $context['exception'] ) && $context['exception'] instanceof \Throwable ) {
			$exception_summary = self::format_exception( $context['exception'] );
			unset( $context['exception'] );
		}

		// Per-string truncation cap. When the operator has set the global
		// log level to `debug` they have explicitly opted into verbose,
		// development-only logging, so keep full-fidelity payloads (e.g.
		// complete PayPal request/response bodies). Production levels keep
		// the 2KB guard to bound log growth. 0 = no truncation.
		$max_length = self::max_string_length();

		// Redact context (sensitive keys + recursive scrub of string
		// values) and the message string.
		$safe_context = Redactor::redact( $context, $max_length );
		if ( '' !== $source ) {
			// `source` is our own channel name; preserve it post-redact.
			$safe_context['source'] = $source;
		}
		if ( null !== $exception_summary ) {
			$safe_context['exception'] = $exception_summary;
		}
		$safe_message = Redactor::scrub_string( $interpolated, $max_length );

		// --- Sinks --------------------------------------------------

		// Custom writer override. Receives the SAFE payload.
		$writer = function_exists( 'apply_filters' )
			? apply_filters( 'tejcart_log_writer', null, $safe_message, $level, $safe_context )
			: null;
		if ( is_callable( $writer ) ) {
			try {
				call_user_func( $writer, $safe_message, $level, $safe_context );
			} catch ( \Throwable $e ) {
				// Never let a custom writer crash the request.
				unset( $e );
			}
			return;
		}

		// 12-factor STDERR sink.
		if ( self::is_stderr_sink() ) {
			self::emit_stderr( $source, $level, $safe_message, $safe_context );
			return;
		}

		// --- On-disk path -----------------------------------------

		// Escape control chars now that the message is finalised —
		// prevents log injection from a buyer-supplied newline.
		$rendered_message = self::escape_control_chars( $safe_message );

		$log_dir = function_exists( 'tejcart_log_dir' )
			? tejcart_log_dir()
			: '';
		if ( '' === $log_dir ) {
			return;
		}

		if ( ! self::ensure_directory( $log_dir ) ) {
			self::record_failure( 'mkdir_failed', $log_dir );
			return;
		}

		$file = self::file_path( $log_dir, $source );

		self::rotate_if_needed( $file );

		$line = self::render_line( $rendered_message, $level, $safe_context, $source );

		$bytes = @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes ) {
			self::record_failure( 'write_failed', $file );
			return;
		}

		// Tighten file mode the first time we touch a new file. chmod
		// is idempotent and cheap; we don't gate it on a "first write"
		// flag to avoid an extra is_file() stat.
		@chmod( $file, self::FILE_MODE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.chmod_chmod

		// Opportunistic retention sweep — runs ~0.5% of the time so
		// merchants without WP-Cron still see old files pruned. The
		// authoritative sweep is the scheduled `tejcart_cleanup_logs`
		// action.
		if ( function_exists( 'wp_rand' ) && function_exists( 'tejcart_log_dir_prune' ) && wp_rand( 1, 200 ) === 1 ) {
			tejcart_log_dir_prune( $log_dir );
		}
	}

	/**
	 * Per-string truncation cap applied to every log entry.
	 *
	 * Returns 0 (no truncation — full fidelity) only when the operator has
	 * set the global `tejcart_log_level` to `debug`, the most verbose,
	 * development-only level, so complete payloads (e.g. full PayPal
	 * request/response bodies) survive instead of being clipped. Every
	 * other level returns the 2KB {@see Redactor::MAX_STRING_LENGTH} guard
	 * so production logs stay bounded.
	 *
	 * Shared by {@see write()} and {@see Log_Channel} so both code paths
	 * apply the same rule.
	 */
	public static function max_string_length(): int {
		if ( ! function_exists( 'tejcart_log_level_threshold' ) || ! function_exists( 'tejcart_log_level_severity' ) ) {
			return Redactor::MAX_STRING_LENGTH;
		}
		return tejcart_log_level_threshold() <= tejcart_log_level_severity( 'debug' )
			? 0
			: Redactor::MAX_STRING_LENGTH;
	}

	/**
	 * Ensure the log directory exists and is locked down to outsiders.
	 *
	 * Idempotent — repeated calls re-stat then bail. Writes the
	 * `.htaccess` (Apache) and `web.config` (IIS) deny rules the first
	 * time we see the directory. Returns whether the directory is now
	 * usable.
	 *
	 * @param string $log_dir Trailing-slashed absolute path.
	 */
	public static function ensure_directory( string $log_dir ): bool {
		if ( '' === $log_dir ) {
			return false;
		}

		if ( ! is_dir( $log_dir ) ) {
			if ( ! function_exists( 'wp_mkdir_p' ) || ! wp_mkdir_p( $log_dir ) ) {
				return false;
			}
			@chmod( $log_dir, self::DIR_MODE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.chmod_chmod
		}

		// Apache deny — supports 2.2 and 2.4 in the same block.
		$htaccess = $log_dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# TejCart — deny public access to log files.\n";
			$rules .= "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
			$rules .= "<FilesMatch \"\\.log$\">\n";
			$rules .= "    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n";
			$rules .= "    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n";
			$rules .= "</FilesMatch>\n";
			@file_put_contents( $htaccess, $rules, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// IIS deny.
		$webconfig = $log_dir . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			$xml .= "<configuration>\n";
			$xml .= "    <system.webServer>\n";
			$xml .= "        <authorization>\n";
			$xml .= "            <deny users=\"*\" />\n";
			$xml .= "        </authorization>\n";
			$xml .= "    </system.webServer>\n";
			$xml .= "</configuration>\n";
			@file_put_contents( $webconfig, $xml, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// Empty index.html guards against directory listings on hosts
		// that ignore .htaccess entirely.
		$index = $log_dir . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '', LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return true;
	}

	/**
	 * Resolve the on-disk filename for a given channel + date.
	 *
	 * Hash suffix obscures the file from external probing — even if
	 * `uploads/tejcart-logs/` is exposed by misconfiguration the
	 * attacker doesn't know the wp_hash() salt and so can't enumerate
	 * filenames.
	 *
	 * @param string $log_dir Trailing-slashed absolute path.
	 * @param string $source  Channel slug (already sanitized).
	 */
	public static function file_path( string $log_dir, string $source ): string {
		$date   = gmdate( 'Y-m-d' );
		$handle = $source . '-' . $date;
		$base   = function_exists( 'sanitize_file_name' )
			? sanitize_file_name( $handle . '-' . wp_hash( $handle ) )
			: ( $handle . '-' . substr( hash( 'sha256', $handle ), 0, 12 ) );
		return $log_dir . $base . '.log';
	}

	/**
	 * Resolve the configured per-file size cap.
	 *
	 * Wraps the `tejcart_log_max_bytes` filter — operators on tiny
	 * shared hosts can dial this down so a single bad day doesn't fill
	 * the disk; operators on a beefy box can dial it up so rotation
	 * doesn't fragment the log into a hundred 1 MiB shards.
	 *
	 * Returns 0 when rotation should be disabled.
	 */
	public static function max_bytes(): int {
		$raw = self::DEFAULT_MAX_BYTES;
		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( 'tejcart_log_max_bytes', $raw );
			if ( is_numeric( $opt ) ) {
				$raw = (int) $opt;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the per-file size cap before size-based rotation.
			 *
			 * Set to 0 to disable size-based rotation entirely (date-
			 * based rollover via the YYYY-MM-DD filename still applies).
			 *
			 * @since 1.0.1
			 * @param int $bytes Default 10 MiB.
			 */
			$raw = (int) apply_filters( 'tejcart_log_max_bytes', $raw );
		}
		return max( 0, $raw );
	}

	/**
	 * How many rotated siblings to keep on disk per active file.
	 *
	 * Filter: `tejcart_log_max_rotations`.
	 */
	public static function max_rotations(): int {
		$raw = self::DEFAULT_ROTATIONS;
		if ( function_exists( 'apply_filters' ) ) {
			$raw = (int) apply_filters( 'tejcart_log_max_rotations', $raw );
		}
		return max( 1, $raw );
	}

	/**
	 * Render one log line in the active format.
	 *
	 * Text format (default — backwards-compatible with the legacy
	 * tejcart_log() shape):
	 *
	 *     2026-05-18T12:34:56+00:00 ERROR Some message {"key":"value"}\n
	 *
	 * JSON Lines format (`tejcart_log_format` = `json`):
	 *
	 *     {"ts":"…","level":"error","msg":"…","source":"…","context":{…}}\n
	 *
	 * @param string               $message Message (already redacted + escaped).
	 * @param string               $level   PSR-3 level (lower case).
	 * @param array<string, mixed> $context Already-redacted context.
	 * @param string               $source  Channel slug.
	 */
	public static function render_line( string $message, string $level, array $context, string $source ): string {
		$ts = gmdate( 'Y-m-d\TH:i:s+00:00' );

		if ( 'json' === self::active_format() ) {
			$payload = array(
				'ts'      => $ts,
				'level'   => $level,
				'msg'     => $message,
				'source'  => $source,
				'context' => $context,
			);
			$json = function_exists( 'wp_json_encode' )
				? wp_json_encode( $payload )
				: json_encode( $payload );
			return ( is_string( $json ) ? $json : '{"ts":"' . $ts . '","level":"error","msg":"[encoding-failed]"}' ) . PHP_EOL;
		}

		// Text format. Strip `source` from the appended JSON since it
		// is implicit in the filename — keeps lines smaller.
		$extras = $context;
		unset( $extras['source'] );

		$line = sprintf( '%s %s %s', $ts, strtoupper( $level ), $message );
		if ( ! empty( $extras ) ) {
			$json = function_exists( 'wp_json_encode' )
				? wp_json_encode( $extras )
				: json_encode( $extras );
			if ( is_string( $json ) ) {
				$line .= ' ' . $json;
			}
		}
		return $line . PHP_EOL;
	}

	/**
	 * Resolve the active line format.
	 *
	 * Reads the `tejcart_log_format` option (`text` | `json`); falls
	 * back to `text` for backwards-compatible operator workflows.
	 */
	public static function active_format(): string {
		$format = 'text';
		if ( function_exists( 'get_option' ) ) {
			$format = strtolower( (string) get_option( 'tejcart_log_format', 'text' ) );
		}
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the log line format.
			 *
			 * `text` (default) keeps the legacy single-line shape that
			 * the Admin log viewer parses. `json` emits NDJSON for
			 * downstream log aggregators (Datadog / Loki / CloudWatch).
			 *
			 * @since 1.0.1
			 * @param string $format `text` or `json`.
			 */
			$format = (string) apply_filters( 'tejcart_log_format', $format );
		}
		$format = strtolower( $format );
		return 'json' === $format ? 'json' : 'text';
	}

	/**
	 * If the active file has grown past the configured size cap, roll
	 * it: rename to `.1`, shifting any prior `.1`→`.2`, …, up to
	 * `max_rotations()`. The oldest sibling is deleted.
	 *
	 * No-ops when:
	 *  - The file doesn't exist yet (first write of the day).
	 *  - The size cap is 0 (rotation disabled).
	 *  - The file is still under the cap.
	 *
	 * @param string $file Absolute path to the active log file.
	 */
	public static function rotate_if_needed( string $file ): void {
		$max_bytes = self::max_bytes();
		if ( 0 === $max_bytes ) {
			return;
		}
		if ( ! is_file( $file ) ) {
			return;
		}
		$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $size || $size < $max_bytes ) {
			return;
		}

		$max_rotations = self::max_rotations();

		// Drop the oldest sibling first so the rename chain doesn't
		// collide with it.
		$oldest = $file . '.' . $max_rotations;
		if ( is_file( $oldest ) ) {
			@unlink( $oldest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		// Walk N-1 → 1 renaming each to the next-higher slot.
		for ( $i = $max_rotations - 1; $i >= 1; $i-- ) {
			$src = $file . '.' . $i;
			$dst = $file . '.' . ( $i + 1 );
			if ( is_file( $src ) ) {
				@rename( $src, $dst ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}

		// Finally roll the active file to .1.
		@rename( $file, $file . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * PSR-3 placeholder interpolation.
	 *
	 * Replaces `{key}` in the message template with the scalar
	 * `$context[key]` value. Non-scalar context values are skipped so
	 * a `{order}` placeholder with an Order object doesn't dump the
	 * whole object into the message.
	 *
	 * @param string               $message Template.
	 * @param array<string, mixed> $context Source.
	 */
	public static function interpolate( string $message, array $context ): string {
		if ( false === strpos( $message, '{' ) ) {
			return $message;
		}
		$replace = array();
		foreach ( $context as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$replace[ '{' . $key . '}' ] = (string) ( null === $value ? '' : $value );
			} elseif ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
				$replace[ '{' . $key . '}' ] = (string) $value;
			}
		}
		if ( empty( $replace ) ) {
			return $message;
		}
		return strtr( $message, $replace );
	}

	/**
	 * Stringify a Throwable in a log-friendly form.
	 *
	 * Returns a structured array (class, message, file, line, short
	 * stack frame list, caused-by chain) so the JSON Lines format can
	 * keep it queryable and the text format can render it inline.
	 *
	 * @param \Throwable $e Exception or Error.
	 * @return array<string, mixed>
	 */
	public static function format_exception( \Throwable $e ): array {
		$frames = array();
		$count  = 0;
		foreach ( $e->getTrace() as $frame ) {
			if ( $count++ >= 20 ) {
				break;
			}
			$file = isset( $frame['file'] ) ? self::strip_abspath( (string) $frame['file'] ) : '';
			$line = isset( $frame['line'] ) ? (int) $frame['line'] : 0;
			$call = '';
			if ( isset( $frame['class'] ) ) {
				$call = (string) $frame['class'] . ( isset( $frame['type'] ) ? (string) $frame['type'] : '::' );
			}
			if ( isset( $frame['function'] ) ) {
				$call .= (string) $frame['function'] . '()';
			}
			$frames[] = trim( sprintf( '%s:%d %s', $file, $line, $call ) );
		}

		$out = array(
			'class'   => get_class( $e ),
			'message' => Redactor::scrub_string( $e->getMessage() ),
			'file'    => self::strip_abspath( $e->getFile() ),
			'line'    => $e->getLine(),
			'trace'   => $frames,
		);

		$previous = $e->getPrevious();
		if ( $previous instanceof \Throwable ) {
			$out['caused_by'] = self::format_exception( $previous );
		}

		return $out;
	}

	/**
	 * Escape ASCII control characters so a hostile buyer-supplied
	 * input cannot inject a newline and forge a fake log line.
	 *
	 * Preserves tab (0x09) — it's the only control character that
	 * legitimately appears inside log messages (column alignment in
	 * pretty-printed JSON snippets).
	 */
	public static function escape_control_chars( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}
		// Match every control char EXCEPT tab (0x09). Newline (0x0A)
		// and carriage return (0x0D) are explicitly in scope — they
		// are the log-injection vectors this defence exists to close.
		return (string) preg_replace_callback(
			'/[\x00-\x08\x0A-\x1F\x7F]/',
			static function ( array $m ): string {
				$ord = ord( $m[0] );
				if ( 0x0A === $ord ) {
					return '\\n';
				}
				if ( 0x0D === $ord ) {
					return '\\r';
				}
				return sprintf( '\\x%02X', $ord );
			},
			$value
		);
	}

	/**
	 * Resolve the channel-routing source from a context array. Falls
	 * back to `tejcart` so a misuse never escapes the namespace.
	 *
	 * @param array<string, mixed> $context Caller context.
	 */
	private static function resolve_source( array $context ): string {
		$raw = isset( $context['source'] ) ? (string) $context['source'] : '';
		if ( '' === $raw ) {
			return 'tejcart';
		}
		if ( function_exists( 'sanitize_key' ) ) {
			$source = sanitize_key( $raw );
		} else {
			$source = strtolower( (string) preg_replace( '/[^a-z0-9_\-]+/i', '_', $raw ) );
		}
		return '' === $source ? 'tejcart' : $source;
	}

	/**
	 * Whether the 12-factor STDERR sink is active for this request.
	 */
	private static function is_stderr_sink(): bool {
		if ( defined( 'TEJCART_LOG_TO_STDERR' ) && TEJCART_LOG_TO_STDERR ) {
			return true;
		}
		if ( function_exists( 'getenv' ) ) {
			$env = getenv( 'TEJCART_LOG_TO_STDERR' );
			if ( false !== $env && '' !== $env && '0' !== $env && 'false' !== strtolower( (string) $env ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Emit a single NDJSON line to STDERR for the host's log shipper.
	 *
	 * Caller guarantees $message and $context are already redacted.
	 *
	 * @param string               $source  Channel slug.
	 * @param string               $level   PSR-3 level.
	 * @param string               $message Already-scrubbed message.
	 * @param array<string, mixed> $context Already-redacted context.
	 */
	private static function emit_stderr( string $source, string $level, string $message, array $context ): void {
		$payload = array(
			'ts'      => gmdate( 'Y-m-d\TH:i:s+00:00' ),
			'level'   => $level,
			'msg'     => $message,
			'source'  => $source,
			'context' => $context,
		);
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $payload )
			: json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return;
		}
		$stderr = defined( 'STDERR' ) ? STDERR : @fopen( 'php://stderr', 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( is_resource( $stderr ) ) {
			@fwrite( $stderr, $json . PHP_EOL ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
	}

	/**
	 * Park a write failure in a short-lived transient so the admin
	 * "System status" screen can surface it without us having to spool
	 * yet-another log file.
	 *
	 * @param string $reason Short slug.
	 * @param string $detail Optional detail (path, errno).
	 */
	private static function record_failure( string $reason, string $detail = '' ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		$ttl = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		set_transient(
			self::FAILURE_TRANSIENT,
			array(
				'ts'     => time(),
				'reason' => $reason,
				'detail' => $detail,
			),
			$ttl
		);
	}

	/**
	 * Strip ABSPATH from a file path so stack traces don't leak the
	 * host filesystem layout.
	 */
	private static function strip_abspath( string $path ): string {
		if ( '' === $path ) {
			return $path;
		}
		if ( defined( 'ABSPATH' ) ) {
			$abspath = (string) ABSPATH;
			if ( '' !== $abspath && 0 === strpos( $path, $abspath ) ) {
				return substr( $path, strlen( $abspath ) );
			}
		}
		return $path;
	}
}
