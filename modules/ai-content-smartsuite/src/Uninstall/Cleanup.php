<?php
/**
 * Uninstall cleanup — removes all module-owned data.
 *
 * @package TejCart\AI_Content_Smartsuite\Uninstall
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Uninstall;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Cleanup {
    public static function run(): void {
        global $wpdb;

        // 1. Settings + install bookkeeping.
        delete_option( 'tejcart_ai_content_settings' );
        delete_option( 'tejcart_ai_content_activation_redirect' );
        delete_option( 'tejcart_ai_content_db_version' );

        // 2. Cancel any pending bulk-generate jobs.
        if ( class_exists( '\\TejCart\\Core\\Action_Scheduler' ) ) {
            \TejCart\Core\Action_Scheduler::instance()->cancel( 'tejcart_ai_content_generate_single' );
        }

        // 3. Temp + applied product meta keys. Capture the affected
        // product ids first so we can issue per-product cache deletes
        // when `wp_cache_flush_group()` isn't supported by the active
        // object cache drop-in (it returns false silently on installs
        // without group-aware caches like Redis).
        $meta_table = $wpdb->prefix . 'tejcart_product_meta';

        $keys = array(
            '_tejcart_ai_name',
            '_tejcart_ai_shortdesc',
            '_tejcart_ai_description',
            '_tejcart_ai_tags',
            '_tejcart_ai_faqs',
            '_tejcart_ai_live_faqs',
            '_tejcart_ai_pre_apply_name',
            '_tejcart_ai_pre_apply_shortdesc',
            '_tejcart_ai_pre_apply_description',
            '_tejcart_ai_pre_apply_tags',
            '_tejcart_ai_pre_apply_faqs',
        );
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

        $affected_ids = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; SQL composed from a whitelisted snippet array; runtime values bound via prepare().
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are built dynamically and values bound via ...$keys
                "SELECT DISTINCT product_id FROM {$meta_table} WHERE meta_key IN ({$placeholders})",
                ...$keys
            )
        );
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $affected_ids[] = (int) $row;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; SQL composed from a whitelisted snippet array; runtime values bound via prepare().
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are built dynamically and values bound via ...$keys
                "DELETE FROM {$meta_table} WHERE meta_key IN ({$placeholders})",
                ...$keys
            )
        );

        // 4. Bulk progress transients + rate-limit transients (best-effort sweep).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_tejcart_ai_bulk_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tejcart_ai_bulk_' ) . '%',
                $wpdb->esc_like( '_transient_tejcart_ai_daily_tokens' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tejcart_ai_daily_tokens' ) . '%',
                $wpdb->esc_like( '_transient_tejcart_ai_hourly_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tejcart_ai_hourly_' ) . '%'
            )
        );

        // 5. Drop the cached meta entries. `wp_cache_flush_group()` is
        // a no-op on plain installs without a group-aware drop-in, so
        // fall back to per-product `wp_cache_delete` for every meta
        // key we touched. Empty `$affected_ids` is the no-data case
        // (clean install) — nothing to evict.
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'tejcart_product_meta' );
        }
        if ( ! empty( $affected_ids ) && function_exists( 'wp_cache_delete' ) ) {
            foreach ( $affected_ids as $product_id ) {
                if ( $product_id <= 0 ) {
                    continue;
                }
                foreach ( $keys as $key ) {
                    wp_cache_delete( $product_id . ':' . $key, 'tejcart_product_meta' );
                    wp_cache_delete( $product_id, 'tejcart_product_meta' );
                }
            }
        }
    }
}
