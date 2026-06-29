<?php
/**
 * Recurring sweeper for the wp_tejcart_locks table (S-4).
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hourly cron that purges expired entries from `wp_tejcart_locks` so the
 * table stays small even on hosts where Lock::release() races a fatal
 * (the row's TTL is the last line of defence).
 */
class Lock_Sweeper {

    public const HOOK = 'tejcart_locks_sweep';

    public function init(): void {
        add_action( self::HOOK, array( $this, 'run' ) );
        if ( function_exists( 'add_action' ) ) {
            add_action( 'init', array( $this, 'maybe_schedule' ) );
        }
    }

    public function maybe_schedule(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            \TejCart\Core\Action_Scheduler::instance()->schedule_recurring(
                time() + 5 * MINUTE_IN_SECONDS,
                HOUR_IN_SECONDS,
                self::HOOK
            );
        }
    }

    public function run(): void {
        $deleted = Lock::sweep_expired( 5000 );
        if ( $deleted > 0 && function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'tejcart_locks sweeper purged %d expired rows.', $deleted ),
                'debug'
            );
        }
    }
}
