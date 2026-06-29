<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Report_Cache {

    private const TTL           = HOUR_IN_SECONDS;
    private const PREFIX        = 'tejcart_aa_';
    private const LAST_BUILT_AT = 'tejcart_aa_last_built_at';

    public static function get( string $report, string $currency ): mixed {
        $key = self::key( $report, $currency );
        return get_transient( $key );
    }

    public static function set( string $report, string $currency, mixed $data ): void {
        $key = self::key( $report, $currency );
        set_transient( $key, $data, self::TTL );
    }

    public static function flush_all(): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . self::PREFIX . '%',
                '_transient_timeout_' . self::PREFIX . '%'
            )
        );

        update_option( self::LAST_BUILT_AT, time(), false );
    }

    public static function last_built_at(): int {
        return (int) get_option( self::LAST_BUILT_AT, 0 );
    }

    public static function render_staleness_banner(): void {
        $last    = self::last_built_at();
        $nonce   = wp_create_nonce( 'tejcart_analytics_advanced_rebuild' );
        $btn     = '<button type="button" class="button button-small tejcart-aa-rebuild-btn" '
                 . 'data-nonce="' . esc_attr( $nonce ) . '">'
                 . esc_html__( 'Rebuild Now', 'tejcart' ) . '</button>';

        if ( 0 === $last ) {
            ?>
            <div class="notice notice-warning inline tejcart-aa-staleness-banner">
                <p>
                    <?php esc_html_e( 'Analytics data has not been built yet. It will rebuild automatically when order statuses change.', 'tejcart' ); ?>
                    <?php echo $btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped above ?>
                </p>
            </div>
            <?php
            return;
        }

        $ago     = time() - $last;
        $display = $ago < 60
            ? __( 'just now', 'tejcart' )
            : human_time_diff( $last, time() ) . ' ' . __( 'ago', 'tejcart' );

        ?>
        <div class="notice notice-info inline tejcart-aa-staleness-banner">
            <p>
                <?php
                printf(
                    /* translators: %s: time since last rebuild */
                    esc_html__( 'Data last updated: %s.', 'tejcart' ),
                    '<strong>' . esc_html( $display ) . '</strong>'
                );
                echo ' ';
                echo $btn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </p>
        </div>
        <?php
    }

    private static function key( string $report, string $currency ): string {
        return self::PREFIX . $report . '_' . strtolower( $currency );
    }
}
