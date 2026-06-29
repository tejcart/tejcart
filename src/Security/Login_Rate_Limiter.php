<?php
/**
 * Account login rate limiter.
 *
 * Throttles brute-force attempts against wp-login. Keyed by IP + username
 * so concurrent legitimate sessions on the same network are not impacted
 * by a single bad actor targeting one account.
 *
 * @package TejCart\Security
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace TejCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Login attempt throttler.
 */
class Login_Rate_Limiter {
    const MAX_ATTEMPTS   = 5;
    const WINDOW_SECONDS = 900;
    const LOCKOUT_ACTION = 'tejcart_login';

    /**
     * Defence-in-depth: even with the per-(IP, username) lock at
     * MAX_ATTEMPTS, an attacker spraying many usernames from one IP
     * can still try MAX_ATTEMPTS × N_usernames within the window. The
     * IP-only counter caps the total attempts from one IP across all
     * usernames at GLOBAL_IP_MAX in the same window. #1213.
     */
    const GLOBAL_IP_MAX     = 30;
    const GLOBAL_IP_ACTION  = 'tejcart_login_ip_global';

    /**
     * Register WP login hooks.
     */
    public function init(): void {
        add_filter( 'authenticate', array( $this, 'check_authenticate' ), 30, 3 );
        add_action( 'wp_login_failed', array( $this, 'record_failure' ), 10, 1 );
        add_action( 'wp_login', array( $this, 'reset_on_success' ), 10, 2 );
    }

    /**
     * Block authentication when the IP+username is locked out.
     *
     * Runs after WP's own authenticate pipeline (priority 30 > WP's 20)
     * so it sees the username in $user_login/$username regardless of
     * how the form routed.
     *
     * @param \WP_User|\WP_Error|null $user     Current auth result.
     * @param string                  $username Submitted username.
     * @param string                  $password Submitted password.
     * @return \WP_User|\WP_Error
     */
    public function check_authenticate( $user, $username, $password ) {
        if ( '' === (string) $username && '' === (string) $password ) {
            return $user;
        }

        if ( $this->is_exempt( $username ) ) {
            return $user;
        }

        $identifier = $this->identifier_for( (string) $username );

        if ( ! $identifier ) {
            return $user;
        }

        if ( Rate_Limiter::is_rate_limited(
            self::LOCKOUT_ACTION,
            $identifier,
            self::MAX_ATTEMPTS,
            self::WINDOW_SECONDS
        ) ) {
            return new \WP_Error(
                'tejcart_login_locked',
                sprintf(
                    /* translators: %d: lockout duration in minutes */
                    __( 'Too many failed login attempts. Please try again in %d minutes.', 'tejcart' ),
                    (int) ( self::WINDOW_SECONDS / MINUTE_IN_SECONDS )
                )
            );
        }

        // #1213: per-IP global cap. Filterable so a NAT-heavy site
        // (large corporate, school, hotspot) can raise it.
        $global_max = (int) apply_filters( 'tejcart_login_global_ip_max', self::GLOBAL_IP_MAX );
        $ip         = Rate_Limiter::get_client_ip();
        if ( '' !== $ip && $global_max > 0 && Rate_Limiter::is_rate_limited(
            self::GLOBAL_IP_ACTION,
            $ip,
            $global_max,
            self::WINDOW_SECONDS
        ) ) {
            return new \WP_Error(
                'tejcart_login_ip_locked',
                sprintf(
                    /* translators: %d: lockout duration in minutes */
                    __( 'Too many failed login attempts from this network. Please try again in %d minutes.', 'tejcart' ),
                    (int) ( self::WINDOW_SECONDS / MINUTE_IN_SECONDS )
                )
            );
        }

        return $user;
    }

    /**
     * Record a failed attempt so the next one approaches / exceeds the
     * lockout threshold.
     *
     * @param string $username The username that was attempted.
     */
    public function record_failure( $username ): void {
        if ( $this->is_exempt( (string) $username ) ) {
            return;
        }

        $identifier = $this->identifier_for( (string) $username );
        if ( ! $identifier ) {
            return;
        }

        Rate_Limiter::record( self::LOCKOUT_ACTION, $identifier, self::WINDOW_SECONDS );

        // #1213: every failed attempt also increments the per-IP
        // global counter so username-spray attacks trip the IP cap
        // even when each per-(IP, username) bucket stays under
        // MAX_ATTEMPTS.
        $ip = Rate_Limiter::get_client_ip();
        if ( '' !== $ip ) {
            Rate_Limiter::record( self::GLOBAL_IP_ACTION, $ip, self::WINDOW_SECONDS );
        }
    }

    /**
     * Clear the counter when the user finally logs in successfully, so the
     * next legitimate user on the same IP isn't penalized by stale failures.
     *
     * @param string   $user_login Username.
     * @param \WP_User $user       The logged-in user.
     */
    public function reset_on_success( $user_login, $user = null ): void {
        $identifier = $this->identifier_for( (string) $user_login );
        if ( ! $identifier ) {
            return;
        }

        Rate_Limiter::reset( self::LOCKOUT_ACTION, $identifier );
    }

    /**
     * Build the rate-limit bucket key for an attempt.
     *
     * @param string $username Username as submitted.
     * @return string Identifier (empty string when we should skip).
     */
    private function identifier_for( string $username ): string {
        $ip = Rate_Limiter::get_client_ip();

        $key_user = strtolower( trim( $username ) );

        if ( '' === $key_user ) {
            return 'ip|' . $ip;
        }

        return $ip . '|' . $key_user;
    }

    /**
     * Whether the current attempt should bypass the rate limiter.
     *
     * Admin-bypass is on by default so an admin recovering from an
     * accidental lockout can still log in via a trusted network. Disable
     * this by returning false from the filter.
     *
     * @param string $username Attempted username.
     * @return bool
     */
    private function is_exempt( string $username ): bool {
        /**
         * Filter whether an attempt is exempt from login rate limiting.
         *
         * @since 1.0.0
         *
         * @param bool   $exempt   Default false.
         * @param string $username Submitted username.
         */
        return (bool) apply_filters( 'tejcart_login_rate_limit_exempt', false, $username );
    }
}
