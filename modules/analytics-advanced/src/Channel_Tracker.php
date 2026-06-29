<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Channel_Tracker {

    private const COOKIE_NAME = 'tejcart_acq_channel';
    private const COOKIE_TTL  = 30 * DAY_IN_SECONDS;

    private const SEARCH_DOMAINS = array(
        'google.', 'bing.com', 'yahoo.', 'duckduckgo.com', 'baidu.com',
        'yandex.', 'ecosia.org', 'ask.com',
    );

    private const SOCIAL_DOMAINS = array(
        'facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 'x.com',
        'tiktok.com', 'linkedin.com', 'pinterest.com', 'youtube.com',
        'reddit.com', 'threads.net', 'snapchat.com',
    );

    private const SOCIAL_SOURCE_KEYWORDS = array(
        'facebook', 'fb', 'instagram', 'twitter', 'tiktok',
        'linkedin', 'pinterest', 'youtube', 'reddit', 'snapchat', 'threads',
    );

    public static function init(): void {
        add_action( 'wp_loaded', array( __CLASS__, 'capture_landing' ) );
        add_action( 'tejcart_order_created', array( __CLASS__, 'write_order_meta' ), 10, 2 );
    }

    public static function capture_landing(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return;
        }

        $channel = self::classify_visit();
        setcookie( self::COOKIE_NAME, $channel, time() + self::COOKIE_TTL, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        $_COOKIE[ self::COOKIE_NAME ] = $channel;
    }

    public static function write_order_meta( int $order_id, object $order ): void {
        $channel = isset( $_COOKIE[ self::COOKIE_NAME ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) )
            : 'direct';

        $valid = array( 'direct', 'referral', 'search', 'social', 'email', 'paid' );
        if ( ! in_array( $channel, $valid, true ) ) {
            $channel = 'direct';
        }

        if ( function_exists( 'tejcart_update_order_meta' ) ) {
            tejcart_update_order_meta( $order_id, '_acquisition_channel', $channel );
        }
    }

    public static function classify_visit(): string {
        $utm_medium = self::get_param( 'utm_medium' );
        $utm_source = self::get_param( 'utm_source' );
        $referrer   = wp_get_raw_referer();

        if ( '' !== $utm_medium ) {
            $medium_lower = strtolower( $utm_medium );
            if ( in_array( $medium_lower, array( 'cpc', 'paid', 'ppc', 'display', 'retargeting' ), true ) ) {
                return 'paid';
            }
            if ( 'email' === $medium_lower || 'newsletter' === $medium_lower ) {
                return 'email';
            }
            if ( 'social' === $medium_lower ) {
                return 'social';
            }
        }

        if ( '' !== $utm_source ) {
            $source_lower = strtolower( $utm_source );
            foreach ( self::SOCIAL_SOURCE_KEYWORDS as $keyword ) {
                if ( str_contains( $source_lower, $keyword ) ) {
                    return 'social';
                }
            }
        }

        if ( ! $referrer || '' === $referrer ) {
            return 'direct';
        }

        $ref_host = wp_parse_url( $referrer, PHP_URL_HOST );
        if ( ! $ref_host ) {
            return 'direct';
        }

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( $site_host && strcasecmp( $ref_host, $site_host ) === 0 ) {
            return 'direct';
        }

        $ref_lower = strtolower( $ref_host );
        foreach ( self::SEARCH_DOMAINS as $domain ) {
            if ( str_contains( $ref_lower, $domain ) ) {
                return 'search';
            }
        }

        foreach ( self::SOCIAL_DOMAINS as $domain ) {
            if ( str_contains( $ref_lower, $domain ) ) {
                return 'social';
            }
        }

        return 'referral';
    }

    private static function get_param( string $key ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
    }
}
