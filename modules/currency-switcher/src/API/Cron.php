<?php
/**
 * Hourly exchange-rate refresh cron.
 *
 * @package TejCart\Currency_Switcher\API
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\API;

use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the `tejcart_csw_update_rates_cron` schedule (hourly) and
 * walks every Auto-rate currency on each tick.
 *
 * The whole batch is wrapped in a `\TejCart\Core\Lock` claim (when
 * core's lock primitive is available) so two cron events firing close
 * together — or an admin clicking "refresh" while the hourly cron is
 * mid-flight — never double-burn the merchant's upstream API quota.
 * On lock contention we return immediately with `success=false,
 * error=lock_held` so the caller (manual AJAX) can surface that to
 * the admin.
 */
final class Cron {
    /**
     * Lock key + TTL. Picked to be longer than the worst-case API
     * roundtrip × number-of-currencies but well under the cron
     * interval so a stuck lock self-heals before the next tick.
     */
    private const LOCK_KEY = 'csw_refresh';
    private const LOCK_TTL = 120;

    public static function schedule(): void {
        if ( ! wp_next_scheduled( Options::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', Options::CRON_HOOK );
        }
    }

    public static function run(): void {
        ( new self() )->refresh_all();
    }

    /**
     * Refresh every Auto-rate currency. Manual refresh from the admin
     * UI reuses this method via {@see self::refresh()}.
     *
     * @return array<string, array<string, mixed>> Per-code refresh result.
     */
    public function refresh_all(): array {
        $repo   = new Currency_Repository();
        $codes  = array_keys( $repo->all() );
        return $this->refresh( $codes );
    }

    /**
     * Refresh a subset of currencies. Codes that aren't configured or
     * are Fixed-rate are skipped (and reported).
     *
     * @param string[] $codes
     * @return array<string, array<string, mixed>>
     */
    public function refresh( array $codes ): array {
        if ( empty( $codes ) ) {
            return array();
        }

        // Concurrency guard. Uses core's atomic-claim lock when the
        // class is loaded (i.e. at runtime); in unit tests where the
        // Lock class isn't booted we fall through and exercise the
        // refresh path directly.
        $lock_class = '\\TejCart\\Core\\Lock';
        $acquired   = true;
        if ( class_exists( $lock_class ) && method_exists( $lock_class, 'claim' ) ) {
            $acquired = (bool) $lock_class::claim( self::LOCK_KEY, self::LOCK_TTL, 'csw_refresh' );
            if ( ! $acquired ) {
                $skipped = array();
                foreach ( $codes as $code ) {
                    $skipped[ strtoupper( (string) $code ) ] = array(
                        'success' => false,
                        'error'   => 'lock_held',
                        'skipped' => true,
                    );
                }
                return $skipped;
            }
        }

        try {
            return $this->do_refresh( $codes );
        } finally {
            if ( $acquired && class_exists( $lock_class ) && method_exists( $lock_class, 'release' ) ) {
                $lock_class::release( self::LOCK_KEY );
            }
        }
    }

    /**
     * Actual per-currency refresh loop. Split out so the caller can
     * own the lock acquisition / release lifecycle.
     *
     * @param string[] $codes
     * @return array<string, array<string, mixed>>
     */
    /**
     * Total wall-clock budget for the refresh loop (seconds). If the
     * budget is exhausted mid-loop, remaining currencies are skipped
     * with `error=budget_exhausted` and whatever rates were fetched so
     * far are persisted. The next hourly tick picks up the rest.
     */
    private const BUDGET_SECONDS = 45;

    private function do_refresh( array $codes ): array {
        $repo   = new Currency_Repository();
        $base   = $repo->base_currency();
        $client = $this->client();
        $logger = new Logger();
        $out    = array();
        $batch  = array();
        $start  = microtime( true );

        foreach ( $codes as $code ) {
            $code = strtoupper( (string) $code );
            $cfg  = $repo->get( $code );

            if ( null === $cfg ) {
                $out[ $code ] = array( 'success' => false, 'error' => 'not_configured' );
                continue;
            }
            if ( Options::RATE_TYPE_AUTO !== $cfg->rate_type ) {
                $out[ $code ] = array( 'success' => false, 'error' => 'rate_type_fixed', 'skipped' => true );
                continue;
            }

            if ( ( microtime( true ) - $start ) >= self::BUDGET_SECONDS ) {
                $out[ $code ] = array( 'success' => false, 'error' => 'budget_exhausted', 'skipped' => true );
                $logger->warning( "Skipped {$code}: refresh budget exhausted after " . count( $batch ) . ' currencies' );
                continue;
            }

            $result = $client->fetch( $base, $code );
            if ( $result['success'] && null !== $result['rate'] ) {
                // Defer the actual write — all successful rates land
                // in a single `update_option` call after the loop. Per
                // 30-currency tick this turns 30 alloptions-cache
                // invalidations into 1.
                $batch[ $code ] = (float) $result['rate'];
                $logger->info( "Updated {$code}: rate {$result['rate']}" );
            } else {
                $logger->warning(
                    sprintf( 'Failed to update %s: %s', $code, (string) ( $result['error'] ?? 'unknown' ) )
                );
            }

            $out[ $code ] = $result;
        }

        if ( ! empty( $batch ) ) {
            $repo->bulk_update_rates( $batch );
        }

        update_option( Options::LAST_RATE_UPDATE, time(), false );
        return $out;
    }

    private function client(): Exchange_Rate_Client {
        /**
         * Filter the rate-fetching client. Tests inject a recording
         * double here; sites that proxy the API can swap in their own.
         */
        $client = apply_filters( 'tejcart_csw_exchange_rate_client', new Exchange_Rate_Client() );
        if ( ! $client instanceof Exchange_Rate_Client ) {
            $client = new Exchange_Rate_Client();
        }
        return $client;
    }
}
