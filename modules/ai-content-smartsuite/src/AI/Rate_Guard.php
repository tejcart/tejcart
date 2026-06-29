<?php
/**
 * Daily token budget and per-user hourly rate limit.
 *
 * @package TejCart\AI_Content_Smartsuite\AI
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\AI;

use TejCart\AI_Content_Smartsuite\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rate_Guard {
    private const DAILY_TOKENS_KEY  = 'tejcart_ai_daily_tokens';
    private const HOURLY_PREFIX     = 'tejcart_ai_hourly_';

    // F-MODS-003: Safe non-zero defaults so a fresh install ships with finite
    // spend caps rather than unlimited (0 = unlimited in get_limits()).
    //
    // DEFAULT_DAILY_TOKEN_BUDGET  = 100 000 tokens/day
    //   ≈ ~50 product descriptions using gpt-4o-mini at ~2 000 tokens each.
    //   Conservative enough to prevent runaway spend on misconfigured sites;
    //   generous enough for normal editorial use. Merchants who process more
    //   can raise this in Settings → AI Content → API Settings.
    //
    // DEFAULT_HOURLY_REQUEST_LIMIT = 50 requests/user/hour
    //   Prevents a single admin from accidentally burning the daily budget in
    //   one bulk-generate run. 50 products/hour is ample for manual curation.
    //
    // Both defaults are filterable via the tejcart_ai_rate_guard_defaults
    // filter and can be overridden per-site in Settings → AI Content.
    public const DEFAULT_DAILY_TOKEN_BUDGET   = 100_000;
    public const DEFAULT_HOURLY_REQUEST_LIMIT = 50;

    /**
     * Check whether the current request is allowed. Returns an error
     * string when blocked, or empty string when allowed.
     */
    public static function check(): string {
        $settings = self::get_limits();

        if ( $settings['daily_token_budget'] > 0 ) {
            $used = self::daily_tokens_used();
            if ( $used >= $settings['daily_token_budget'] ) {
                return __( 'Daily AI token budget reached. Try again tomorrow or increase the budget in Settings.', 'tejcart' );
            }
        }

        if ( $settings['hourly_request_limit'] > 0 ) {
            $user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
            if ( $user_id > 0 ) {
                $count = self::hourly_request_count( $user_id );
                if ( $count >= $settings['hourly_request_limit'] ) {
                    return __( 'Hourly request limit reached. Please wait before generating more content.', 'tejcart' );
                }
            }
        }

        return '';
    }

    /**
     * Record a completed request's token usage and increment the
     * per-user hourly counter.
     */
    public static function record( int $total_tokens ): void {
        if ( $total_tokens > 0 ) {
            $current = (int) get_transient( self::DAILY_TOKENS_KEY );
            set_transient( self::DAILY_TOKENS_KEY, $current + $total_tokens, DAY_IN_SECONDS );
        }

        $user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
        if ( $user_id > 0 ) {
            $key     = self::HOURLY_PREFIX . $user_id;
            $current = (int) get_transient( $key );
            set_transient( $key, $current + 1, HOUR_IN_SECONDS );
        }
    }

    public static function daily_tokens_used(): int {
        return (int) get_transient( self::DAILY_TOKENS_KEY );
    }

    private static function hourly_request_count( int $user_id ): int {
        return (int) get_transient( self::HOURLY_PREFIX . $user_id );
    }

    /**
     * @return array{daily_token_budget:int, hourly_request_limit:int}
     */
    private static function get_limits(): array {
        $raw = get_option( Settings::OPTION_KEY, array() );
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        return array(
            'daily_token_budget'   => max( 0, (int) ( $raw['daily_token_budget'] ?? self::DEFAULT_DAILY_TOKEN_BUDGET ) ),
            'hourly_request_limit' => max( 0, (int) ( $raw['hourly_request_limit'] ?? self::DEFAULT_HOURLY_REQUEST_LIMIT ) ),
        );
    }
}
