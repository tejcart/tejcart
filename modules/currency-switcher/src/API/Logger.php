<?php
/**
 * Thin logger wrapper used by the exchange-rate cron and the manual
 * AJAX refresh handler.
 *
 * @package TejCart\Currency_Switcher\API
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\API;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Writes to the `tejcart_csw_exchange_rates` log source.
 *
 * TejCart core doesn't ship its own logger surface so we route through
 * `error_log` by default. Sites that bind a real logger via the
 * `tejcart_csw_logger` filter (a callable accepting `(level, message)`)
 * get structured logs instead.
 */
final class Logger {
    public function info( string $message ): void {
        $this->log( 'info', $message );
    }

    public function warning( string $message ): void {
        $this->log( 'warning', $message );
    }

    public function error( string $message ): void {
        $this->log( 'error', $message );
    }

    private function log( string $level, string $message ): void {
        $callable = apply_filters( 'tejcart_csw_logger', null );
        if ( is_callable( $callable ) ) {
            $callable( $level, $message );
            return;
        }
        // Prefer the redacted/rotated TejCart logger when available so
        // exchange-rate log lines land in the same file as the rest of
        // the plugin's diagnostics. Direct error_log() is the
        // shared-host-unfriendly fallback when the logger isn't loaded.
        $formatted = '[tejcart_csw_exchange_rates] ' . $message;
        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log( $formatted, $level );
            return;
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[' . $level . '] ' . $formatted );
        }
    }
}
