<?php
/**
 * Per-provider usage counter and circuit breaker.
 *
 * @package TejCart\Tax_Providers
 */

namespace TejCart\Tax_Providers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Tracks live tax provider call volume and health, and enforces the daily
 * call cap and consecutive-failure circuit breaker that protect high-volume
 * merchants from runaway billing and from synchronously hammering a sick
 * upstream during a Stripe / TaxJar / Avalara incident.
 *
 * Two storage layers:
 *
 *   - **Object cache** (memcached / Redis) is preferred for the day counter
 *     because `wp_cache_incr` is atomic across web workers and dramatically
 *     reduces option-table churn on busy stores.
 *   - **WordPress option** is the durable store: holds rolling totals,
 *     last-error metadata, and the breaker state. Updated through a
 *     read-modify-write loop guarded by a short-lived transient lock so
 *     two concurrent webhooks cannot both clobber the snapshot.
 *
 * The day/month keys roll automatically: when the stored `day_key` no longer
 * matches the current YYYYMMDD the counter is reset on the next read. We use
 * `gmdate()` so the rollover timezone is stable across deployments — for a
 * billing-safety counter, predictable beats locale-correct.
 */
class Tax_Provider_Usage_Tracker {
    /**
     * Option key prefix. The full key is `tejcart_tax_usage_<provider_id>`.
     */
    private const OPTION_PREFIX = 'tejcart_tax_usage_';

    /**
     * Object-cache group used for the atomic day counter.
     */
    private const CACHE_GROUP = 'tejcart_tax_usage';

    /**
     * Read-modify-write lock key prefix.
     */
    private const LOCK_PREFIX = 'tejcart_tax_usage_lock_';

    /**
     * Lock TTL — short enough that a crashed worker doesn't wedge the counter
     * for long, generous enough for a real read/write to complete.
     */
    private const LOCK_TTL = 5;

    /**
     * Default circuit-breaker cooldown when the provider opens it.
     */
    public const BREAKER_COOLDOWN = 60;

    /**
     * Default consecutive-failure threshold before the breaker opens.
     */
    public const BREAKER_THRESHOLD = 5;

    /**
     * Singleton.
     */
    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Decide whether a fresh upstream call is allowed for this provider.
     *
     * @param string $provider_id      Provider identifier (e.g. 'stripe_tax').
     * @param int    $daily_cap        Hard cap on calls per UTC day. 0 disables.
     * @param int    $breaker_threshold Consecutive failures before the breaker opens.
     *                                  0 disables the breaker.
     * @param int    $breaker_cooldown  Seconds the breaker stays open.
     * @return array{allowed: bool, reason: string} Tuple — `reason` is `''` when allowed,
     *                                              otherwise one of `cap`, `breaker`.
     */
    public function should_allow_call( string $provider_id, int $daily_cap, int $breaker_threshold, int $breaker_cooldown ): array {
        $state = $this->get_state( $provider_id );

        if ( $breaker_threshold > 0 && $state['breaker_until'] > time() ) {
            return array( 'allowed' => false, 'reason' => 'breaker' );
        }

        if ( $daily_cap > 0 && $state['day_count'] >= $daily_cap ) {
            return array( 'allowed' => false, 'reason' => 'cap' );
        }

        return array( 'allowed' => true, 'reason' => '' );
    }

    /**
     * Atomically increment the day counter (object cache fast path).
     *
     * Returns the post-increment count. Falls back to the option-snapshot
     * counter when no persistent object cache is available.
     */
    public function increment_day_count( string $provider_id ): int {
        $day_key   = $this->day_key();
        $cache_key = $provider_id . ':' . $day_key;

        if ( wp_using_ext_object_cache() ) {
            $count = wp_cache_incr( $cache_key, 1, self::CACHE_GROUP );
            if ( false === $count ) {
                // First write of the day — seed the slot. Use a 36h TTL so it
                // cannot strand a stale count past the next day's rollover.
                wp_cache_add( $cache_key, 1, self::CACHE_GROUP, DAY_IN_SECONDS + ( 12 * HOUR_IN_SECONDS ) );
                $count = 1;
            }
            return (int) $count;
        }

        // No persistent cache: use the option snapshot. Concurrent writers may
        // under-count slightly but the cap is a safety net, not invoiced data.
        $state = $this->mutate_state(
            $provider_id,
            static function ( array $state ) use ( $day_key ): array {
                if ( $state['day_key'] !== $day_key ) {
                    $state['day_key']   = $day_key;
                    $state['day_count'] = 0;
                }
                ++$state['day_count'];
                return $state;
            }
        );

        return (int) $state['day_count'];
    }

    /**
     * Record a successful upstream call.
     */
    public function record_success( string $provider_id, int $latency_ms ): void {
        $month_key = $this->month_key();

        $this->mutate_state(
            $provider_id,
            static function ( array $state ) use ( $month_key, $latency_ms ): array {
                if ( $state['month_key'] !== $month_key ) {
                    $state['month_key']   = $month_key;
                    $state['month_count'] = 0;
                }
                ++$state['month_count'];

                $state['last_call_at']     = time();
                $state['failures']         = 0;
                $state['breaker_until']    = 0;
                $state['last_latency_ms']  = max( 0, $latency_ms );

                $samples                   = isset( $state['latency_samples'] ) && is_array( $state['latency_samples'] )
                    ? $state['latency_samples']
                    : array();
                $samples[]                 = max( 0, $latency_ms );
                if ( count( $samples ) > 50 ) {
                    $samples = array_slice( $samples, -50 );
                }
                $state['latency_samples']  = $samples;

                return $state;
            }
        );
    }

    /**
     * Record a failed upstream call. Opens the breaker once the consecutive
     * failure threshold is reached.
     *
     * @param string $provider_id
     * @param string $reason   Short error message; truncated to 240 chars.
     * @param int    $threshold
     * @param int    $cooldown
     */
    public function record_failure( string $provider_id, string $reason, int $threshold, int $cooldown ): void {
        $reason = mb_substr( $reason, 0, 240 );
        $now    = time();

        $this->mutate_state(
            $provider_id,
            static function ( array $state ) use ( $reason, $threshold, $cooldown, $now ): array {
                ++$state['failures'];
                $state['last_error'] = array(
                    'at'      => $now,
                    'message' => $reason,
                );
                if ( $threshold > 0 && $state['failures'] >= $threshold ) {
                    $state['breaker_until'] = $now + max( 1, $cooldown );
                }
                return $state;
            }
        );
    }

    /**
     * Read the current state, normalised against the current day/month.
     *
     * @return array{
     *     day_key:string, day_count:int,
     *     month_key:string, month_count:int,
     *     last_call_at:int, last_latency_ms:int,
     *     last_error:array{at:int,message:string},
     *     failures:int, breaker_until:int,
     *     latency_samples: array<int,int>
     * }
     */
    public function get_state( string $provider_id ): array {
        $state = $this->read_raw_state( $provider_id );

        $day_key = $this->day_key();
        if ( $state['day_key'] !== $day_key ) {
            $state['day_key']   = $day_key;
            $state['day_count'] = 0;
        }

        // The object-cache day counter is the source of truth when present —
        // mirror it back into the state shape so admin reads are accurate.
        if ( wp_using_ext_object_cache() ) {
            $cached = wp_cache_get( $provider_id . ':' . $day_key, self::CACHE_GROUP );
            if ( false !== $cached && (int) $cached >= $state['day_count'] ) {
                $state['day_count'] = (int) $cached;
            }
        }

        $month_key = $this->month_key();
        if ( $state['month_key'] !== $month_key ) {
            $state['month_key']   = $month_key;
            $state['month_count'] = 0;
        }

        return $state;
    }

    /**
     * Average latency across the most recent samples, or 0 when none recorded.
     */
    public function average_latency_ms( string $provider_id ): int {
        $samples = $this->get_state( $provider_id )['latency_samples'];
        if ( empty( $samples ) ) {
            return 0;
        }
        return (int) round( array_sum( $samples ) / count( $samples ) );
    }

    /**
     * Reset the counters and breaker for one provider. Admin "reset" action.
     */
    public function reset( string $provider_id ): void {
        delete_option( self::OPTION_PREFIX . $provider_id );
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( $provider_id . ':' . $this->day_key(), self::CACHE_GROUP );
        }
    }

    /**
     * Run a closure over the persisted state under a short-lived transient
     * lock and persist the result. Returns the post-mutation state.
     *
     * @param callable(array):array $mutator
     * @return array
     */
    private function mutate_state( string $provider_id, callable $mutator ): array {
        $lock_key = self::LOCK_PREFIX . $provider_id;
        $token    = wp_generate_password( 12, false, false );

        // Cooperative best-effort lock: small spin window to absorb worker
        // collisions, then proceed unconditionally so a wedged process never
        // strands the counter.
        $acquired = false;
        for ( $i = 0; $i < 10; $i++ ) {
            if ( false === get_transient( $lock_key ) ) {
                set_transient( $lock_key, $token, self::LOCK_TTL );
                if ( get_transient( $lock_key ) === $token ) {
                    $acquired = true;
                    break;
                }
            }
            usleep( 10_000 ); // 10 ms.
        }

        $state = $this->read_raw_state( $provider_id );
        $state = $mutator( $state );

        update_option( self::OPTION_PREFIX . $provider_id, $state, false );

        if ( $acquired && get_transient( $lock_key ) === $token ) {
            delete_transient( $lock_key );
        }

        return $state;
    }

    /**
     * Read the option as-is, normalising the shape for callers.
     *
     * @return array
     */
    private function read_raw_state( string $provider_id ): array {
        $stored = get_option( self::OPTION_PREFIX . $provider_id, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return array(
            'day_key'         => isset( $stored['day_key'] ) ? (string) $stored['day_key'] : '',
            'day_count'       => isset( $stored['day_count'] ) ? (int) $stored['day_count'] : 0,
            'month_key'       => isset( $stored['month_key'] ) ? (string) $stored['month_key'] : '',
            'month_count'     => isset( $stored['month_count'] ) ? (int) $stored['month_count'] : 0,
            'last_call_at'    => isset( $stored['last_call_at'] ) ? (int) $stored['last_call_at'] : 0,
            'last_latency_ms' => isset( $stored['last_latency_ms'] ) ? (int) $stored['last_latency_ms'] : 0,
            'last_error'      => isset( $stored['last_error'] ) && is_array( $stored['last_error'] )
                ? array(
                    'at'      => (int) ( $stored['last_error']['at'] ?? 0 ),
                    'message' => (string) ( $stored['last_error']['message'] ?? '' ),
                )
                : array( 'at' => 0, 'message' => '' ),
            'failures'        => isset( $stored['failures'] ) ? (int) $stored['failures'] : 0,
            'breaker_until'   => isset( $stored['breaker_until'] ) ? (int) $stored['breaker_until'] : 0,
            'latency_samples' => isset( $stored['latency_samples'] ) && is_array( $stored['latency_samples'] )
                ? array_values( array_map( 'intval', $stored['latency_samples'] ) )
                : array(),
        );
    }

    private function day_key(): string {
        return gmdate( 'Ymd' );
    }

    private function month_key(): string {
        return gmdate( 'Ym' );
    }
}
