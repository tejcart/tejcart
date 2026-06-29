<?php

declare(strict_types=1);

namespace TejCart\Analytics_Advanced;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Schema {

    public static function cohorts_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_cohorts';
    }

    public static function cohort_retention_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_cohort_retention';
    }

    public static function customer_ltv_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_customer_ltv';
    }

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix          = $wpdb->prefix;

        $sql_cohorts = "CREATE TABLE {$prefix}tejcart_cohorts (
            cohort_month CHAR(7) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            customer_count INT UNSIGNED NOT NULL DEFAULT 0,
            first_order_revenue BIGINT NOT NULL DEFAULT 0,
            total_revenue BIGINT NOT NULL DEFAULT 0,
            total_orders INT UNSIGNED NOT NULL DEFAULT 0,
            avg_ltv BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (cohort_month, currency)
        ) $charset_collate;";

        $sql_retention = "CREATE TABLE {$prefix}tejcart_cohort_retention (
            cohort_month CHAR(7) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            months_after TINYINT UNSIGNED NOT NULL DEFAULT 0,
            returning_customers INT UNSIGNED NOT NULL DEFAULT 0,
            revenue BIGINT NOT NULL DEFAULT 0,
            order_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (cohort_month, currency, months_after)
        ) $charset_collate;";

        $sql_ltv = "CREATE TABLE {$prefix}tejcart_customer_ltv (
            customer_email VARCHAR(200) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'USD',
            first_order_date DATETIME DEFAULT NULL,
            last_order_date DATETIME DEFAULT NULL,
            order_count INT UNSIGNED NOT NULL DEFAULT 0,
            total_revenue BIGINT NOT NULL DEFAULT 0,
            avg_order_value BIGINT NOT NULL DEFAULT 0,
            acquisition_channel VARCHAR(50) NOT NULL DEFAULT 'direct',
            cohort_month CHAR(7) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (customer_email(191), currency),
            KEY cohort_month (cohort_month),
            KEY acquisition_channel (acquisition_channel),
            KEY total_revenue (total_revenue)
        ) $charset_collate;";

        \dbDelta( $sql_cohorts );
        \dbDelta( $sql_retention );
        \dbDelta( $sql_ltv );

        update_option( TEJCART_ANALYTICS_ADVANCED_DB_VERSION_OPTION, TEJCART_ANALYTICS_ADVANCED_DB_VERSION, false );
    }

    public static function maybe_upgrade(): void {
        $installed = get_option( TEJCART_ANALYTICS_ADVANCED_DB_VERSION_OPTION, '0' );
        if ( version_compare( $installed, TEJCART_ANALYTICS_ADVANCED_DB_VERSION, '<' ) ) {
            self::install();
        }
    }
}
