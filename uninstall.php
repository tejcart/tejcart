<?php
/**
 * TejCart Uninstall
 *
 * Fired when the plugin is uninstalled and `tejcart_delete_data_on_uninstall`
 * is set to `yes`. Removes only the data the plugin owns — no broad
 * `LIKE 'tejcart_%'` wildcard, so a third-party extension that namespaces
 * its own options under (e.g.) `tejcart_reports_*` is not collateral.
 *
 * Tables: still prefix-matched against `{$wpdb->prefix}tejcart_` because
 * the table namespace IS exclusively owned by this plugin.
 *
 * Extension authors who add new options or transients should register
 * them via the `tejcart_uninstall_owned_options` /
 * `tejcart_uninstall_owned_option_prefixes` filters defined here so
 * their data is cleaned up alongside core's.
 *
 * @package TejCart
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

global $wpdb;
if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
    return;
}

if ( 'yes' !== (string) get_option( 'tejcart_delete_data_on_uninstall', 'no' ) ) {
    return;
}

/*
 * F-M7 / #941: on multisite, the network-activated plugin owns tables
 * on every site (each $wpdb->prefix is per-site). Iterate every site
 * with switch_to_blog so the purge below runs per-site. Single-site
 * installs fall through to the legacy single-pass behaviour.
 */
$tejcart_uninstall_sites = array( 0 ); // 0 = no switch; single-site mode.
if ( is_multisite() && function_exists( 'get_sites' ) ) {
    $site_objects = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    if ( is_array( $site_objects ) && ! empty( $site_objects ) ) {
        $tejcart_uninstall_sites = array_map( 'intval', $site_objects );
    }
}

foreach ( $tejcart_uninstall_sites as $tejcart_site_id ) {
    if ( $tejcart_site_id > 0 && function_exists( 'switch_to_blog' ) ) {
        // Audit L-28: validate switch_to_blog return so a missing
        // site row doesn't run DROP TABLE against the wrong prefix.
        if ( false === switch_to_blog( $tejcart_site_id ) ) {
            continue;
        }
    }
    // Re-resolve global $wpdb in the new site context — $wpdb->prefix
    // changes with switch_to_blog so the SQL below targets the right
    // tables / options.
    $wpdb = $GLOBALS['wpdb'];

/*
 * --- Step 1: drop tables. The `{$prefix}tejcart_` namespace is the
 * plugin's exclusively, so a prefix wildcard is the right tool here.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$tables = $wpdb->get_col(
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    $wpdb->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
        DB_NAME,
        $wpdb->esc_like( $wpdb->prefix . 'tejcart_' ) . '%'
    )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

if ( is_array( $tables ) ) {
    foreach ( $tables as $table ) {
        $safe_table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
        if ( $safe_table === $table && '' !== $safe_table ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( "DROP TABLE IF EXISTS `{$safe_table}`" );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        }
    }
}

/*
 * --- Step 1b: drop the helper index TejCart adds to WordPress core's
 * commentmeta table (Installer::create_tables() adds
 * `idx_tejcart_review_lookup` so review meta lookups stay fast). The
 * tejcart_ tables above are wildcard-dropped, but this index lives on a
 * core table that survives uninstall, so it must be removed explicitly
 * or it orphans. MySQL has no DROP INDEX IF EXISTS, so probe
 * information_schema first.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$commentmeta_table   = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $wpdb->commentmeta );
$review_index_exists = (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
        DB_NAME,
        $commentmeta_table,
        'idx_tejcart_review_lookup'
    )
);
if ( $review_index_exists > 0 && '' !== $commentmeta_table ) {
    $wpdb->query( "DROP INDEX idx_tejcart_review_lookup ON `{$commentmeta_table}`" );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

/*
 * --- Step 2: delete options the plugin owns. Two registries:
 *   - $exact_keys: literal option names written by core.
 *   - $prefix_patterns: prefixes core uses for variable-keyed settings
 *     (per-gateway, per-email, per-shipping-zone, per-user transients,
 *     etc.). Each prefix is matched as `<prefix>%` via $wpdb->esc_like.
 *
 * Both sets are filterable so Tier-2 modules and add-ons can opt their
 * data into the same uninstall sweep without editing this file.
 */

$exact_keys = array(
    // Versioning / install bookkeeping.
    'tejcart_version',
    'tejcart_customers_backfilled',
    'tejcart_orders_base_backfilled',
    'tejcart_delete_data_on_uninstall',
    'tejcart_owns_customer_role',

    // Onboarding / setup — Audit H-19 (Core F-003): added the 6 missing
    // wizard + installer keys and fixed the typo'd key that nothing writes.
    'tejcart_setup_completed',
    'tejcart_wizard_completed',
    'tejcart_wizard_skipped_steps',
    'tejcart_wizard_current_step',
    'tejcart_store_email',

    // Currency & money formatting.
    'tejcart_currency',
    'tejcart_currency_code',
    'tejcart_currency_symbol',
    'tejcart_currency_position',
    'tejcart_thousand_separator',
    'tejcart_decimal_separator',
    'tejcart_num_decimals',
    'tejcart_price_display_suffix',

    // Tax (top-level keys; per-rate config covered by prefix below).
    'tejcart_enable_tax',
    'tejcart_tax_enabled',
    'tejcart_prices_include_tax',
    'tejcart_tax_based_on',
    'tejcart_tax_round_at_subtotal',
    'tejcart_tax_display_shop',
    'tejcart_tax_display_cart',
    'tejcart_tax_classes',
    'tejcart_tax_rates',
    'tejcart_tax_rate',
    'tejcart_tax_io_notice',

    // Shipping (top-level keys; per-zone covered by prefix below).
    'tejcart_enable_shipping',
    'tejcart_shipping_zones',
    'tejcart_shipping_classes',
    'tejcart_shipping_class_fees',
    'tejcart_shipping_origin_country',
    'tejcart_shipping_flat_rate',
    'tejcart_shipping_free_threshold',
    'tejcart_default_shipping_method',

    // Checkout / cart UX.
    'tejcart_show_checkout_menu',
    'tejcart_pending_order_timeout',
    'tejcart_guest_checkout',
    'tejcart_enable_registration',
    'tejcart_create_account_default',
    'tejcart_enable_cart_drawer',
    'tejcart_redirect_after_add',
    'tejcart_enable_order_notes',
    'tejcart_require_phone',
    'tejcart_address_autocomplete_provider',
    'tejcart_google_places_api_key',
    'tejcart_cart_minimum_amount',
    'tejcart_cart_maximum_amount',
    'tejcart_auto_apply_coupons',
    'tejcart_require_cookie_consent',

    // Catalogue / product display.
    'tejcart_products_columns',
    'tejcart_products_per_page',
    'tejcart_hide_out_of_stock',
    'tejcart_low_stock_threshold',
    'tejcart_stock_display_format',
    'tejcart_enable_reviews',
    'tejcart_review_rating_required',
    'tejcart_review_show_verified_label',
    'tejcart_review_verified_only',

    // Store identity / locale.
    'tejcart_store_name',
    'tejcart_store_address',
    'tejcart_store_city',
    'tejcart_store_state',
    'tejcart_store_postcode',
    'tejcart_store_country',
    'tejcart_timezone_string',
    'tejcart_weight_unit',
    'tejcart_dimension_unit',
    'tejcart_footer_text',

    // Theme.
    'tejcart_theme_color_primary',
    'tejcart_theme_color_accent',
    'tejcart_theme_color_sale',

    // Page IDs.
    'tejcart_shop_page_id',
    'tejcart_cart_page_id',
    'tejcart_checkout_page_id',
    'tejcart_account_page_id',
    'tejcart_myaccount_page_id',
    'tejcart_thankyou_page_id',
    'tejcart_terms_page_id',
    'tejcart_order_pay_page_id',

    // API.
    'tejcart_api_enabled',

    // Email defaults (per-email subject/body covered by prefix).
    'tejcart_from_name',
    'tejcart_from_email',
    'tejcart_email_from_address',

    // Downloads.
    'tejcart_download_expiry_hours',
    'tejcart_download_ip_lock',
    'tejcart_allow_remote_image_import',

    // Webhooks + circuit breaker + admin notices.
    'tejcart_webhooks',
    'tejcart_paypal_circuit',
    'tejcart_status_notice',
    'tejcart_tools_notice',

    // Logging.
    'tejcart_log_level',

    // Action Scheduler — written at runtime.
    'tejcart_dynamic_cron_intervals',
    'tejcart_log_retention_days',

    // Maintenance bookkeeping.
    'tejcart_last_session_cleanup',
    'tejcart_low_stock_digest',
    'tejcart_import_summary',
    'tejcart_redirect_to_setup',

    // Schema / migration bookkeeping written by the installer + Tier-2 boot.
    // Previously left behind on an opt-in "delete data" uninstall.
    'tejcart_money_minor_units_migrated',
    'tejcart_tier2_schema_version',
    'tejcart_schema_warnings',
    'tejcart_active_tax_provider',

    // Bundled modules (modules/<slug>/) — toggle map + per-module
    // schema-version bookkeeping. The modules' DB tables are dropped
    // by the prefix wildcard above; these are the option rows the
    // module installers leave behind. Per-module options + transients
    // (encrypted credentials, dispatcher state, breaker counters) are
    // covered by the `tejcart_<slug>_` prefix patterns below.
    'tejcart_modules_enabled',
    'tejcart_analytics_db_version',
    'tejcart_disputes_db_version',
    'tejcart_order_tracking_db_version',
    'tejcart_returns_db_version',
    'tejcart_shipping_db_version',
    // #1212: schema-version keys for the three newer bundled modules.
    'tejcart_gift_cards_db_version',
    'tejcart_currency_switcher_db_version',
    // The AI Content SmartSuite module namespaces its own options under
    // `tejcart_ai_content_` (not `..._smartsuite_`); list the literal
    // version + redirect keys here and sweep the rest via the prefix
    // pattern below. Product meta + bulk/rate transients are removed by
    // the module's Cleanup::run() invoked in the module-teardown step.
    'tejcart_ai_content_db_version',
    'tejcart_ai_content_activation_redirect',
    'tejcart_b2b_db_version',
);

$prefix_patterns = array(
    'tejcart_gateway_',                // per-gateway settings (PayPal, COD, Bank, Cheque, Apple/Google Pay, Fastlane).
    'tejcart_email_',                  // per-email subject/body/heading settings.
    'tejcart_shipping_zone_',          // per-zone method/location config.
    'tejcart_delete_account_error_',   // per-user error flash flags.
    'tejcart_tax_',                    // tax sub-settings beyond the literal keys above.
    'tejcart_review_',                 // review sub-settings beyond the literal keys above.

    // Bundled modules — sweep every option whose key starts with the
    // module's slug. Catches the analytics dispatcher's encrypted
    // credentials (ga4 / meta_capi / klaviyo / mailchimp) + counters,
    // the shipping module's encrypted carrier credentials and breaker
    // state, and any future per-module setting the modules add. The
    // matching `_transient_<prefix>` envelope is generated by the
    // transient sweep further down.
    'tejcart_analytics_',
    'tejcart_aa_',
    'tejcart_mailchimp_',
    'tejcart_disputes_',
    'tejcart_order_tracking_',
    'tejcart_returns_',
    'tejcart_shipping_',
    // #1212: newer bundled modules. Each owns its own option namespace.
    'tejcart_gift_cards_',
    'tejcart_currency_switcher_',
    // Matches tejcart_ai_content_settings / _db_version / _activation_redirect.
    'tejcart_ai_content_',
    'tejcart_b2b_',
    // tax-providers module: per-provider credential blobs
    // (tejcart_tax_provider_taxjar / _stripe_tax / _avalara), the
    // strict-failover toggle, and the decrypt / manual-fallback admin
    // notice stores. (The active-provider pointer is a literal key above.)
    'tejcart_tax_provider_',
);

/**
 * Filter the literal option keys cleaned up on uninstall.
 *
 * Add-ons should register any persistent option they create here; do
 * not rely on a `LIKE 'tejcart_%'` wildcard, which this file
 * intentionally no longer uses.
 *
 * @param string[] $exact_keys
 */
$exact_keys      = (array) apply_filters( 'tejcart_uninstall_owned_options', $exact_keys );

/**
 * Filter the option-name prefixes cleaned up on uninstall.
 *
 * Each entry is matched as `<prefix>%`. Use this for variable-keyed
 * settings (per-gateway, per-email, per-zone, per-user, etc.).
 *
 * @param string[] $prefix_patterns
 */
$prefix_patterns = (array) apply_filters( 'tejcart_uninstall_owned_option_prefixes', $prefix_patterns );

if ( ! empty( $exact_keys ) ) {
    $placeholders = implode( ', ', array_fill( 0, count( $exact_keys ), '%s' ) );
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
            ...array_values( $exact_keys )
        )
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

foreach ( $prefix_patterns as $prefix ) {
    if ( ! is_string( $prefix ) || '' === $prefix ) {
        continue;
    }
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( $prefix ) . '%'
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

/*
 * --- Step 3: delete transients owned by the plugin.
 *
 * Transients are stored as `_transient_<key>` and `_transient_timeout_<key>`
 * in wp_options (and as cache entries when an external object cache is
 * present, handled separately below). Match the same prefix patterns
 * plus the literal keys, both with the transient envelopes.
 */

$transient_prefixes = array();
foreach ( $prefix_patterns as $prefix ) {
    if ( ! is_string( $prefix ) || '' === $prefix ) {
        continue;
    }
    $transient_prefixes[] = '_transient_' . $prefix;
    $transient_prefixes[] = '_transient_timeout_' . $prefix;
}

foreach ( $transient_prefixes as $prefix ) {
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( $prefix ) . '%'
        )
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

if ( ! empty( $exact_keys ) ) {
    $transient_keys = array();
    foreach ( $exact_keys as $key ) {
        $transient_keys[] = '_transient_' . $key;
        $transient_keys[] = '_transient_timeout_' . $key;
    }
    $placeholders = implode( ', ', array_fill( 0, count( $transient_keys ), '%s' ) );
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
            ...array_values( $transient_keys )
        )
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

/*
 * Audit #36 / 09 F-005 — sweep TejCart-owned user_meta. Eight known
 * key families survived opt-in uninstall before this, several
 * containing PII (saved cart blobs, vaulted-token references,
 * billing/shipping address fragments). GDPR Article 17 expects all
 * personal data linked to the user to be removed when the merchant
 * explicitly opts into the "delete data on uninstall" path.
 *
 * Literal keys + LIKE-prefixes are both filterable so addons can
 * opt their own keys into the sweep without forking this file.
 */
$tejcart_user_meta_keys = apply_filters(
    'tejcart_uninstall_owned_user_meta_keys',
    array(
        '_tejcart_wishlist',
        '_tejcart_saved_cart',
        'tejcart_saved_payment_methods',
        'tejcart_vault_notice',
    )
);
$tejcart_user_meta_prefixes = apply_filters(
    'tejcart_uninstall_owned_user_meta_prefixes',
    array(
        '_tejcart_pp_token_',
        'tejcart_billing_',
        'tejcart_shipping_',
        'tejcart_delete_account_error_',
    )
);

if ( is_array( $tejcart_user_meta_keys ) && array() !== $tejcart_user_meta_keys ) {
    $meta_placeholders = implode( ',', array_fill( 0, count( $tejcart_user_meta_keys ), '%s' ) );
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ({$meta_placeholders})",
            $tejcart_user_meta_keys
        )
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
if ( is_array( $tejcart_user_meta_prefixes ) ) {
    foreach ( $tejcart_user_meta_prefixes as $prefix ) {
        $prefix = (string) $prefix;
        if ( '' === $prefix ) {
            continue;
        }
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }
}

/*
 * Only remove the 'customer' role if TejCart was the one that created
 * it on activation. The role name is generic and shared with several
 * other ecommerce / membership plugins; stripping it unconditionally
 * would break their ACL.
 */
if ( function_exists( 'remove_role' ) && 'yes' === (string) get_option( 'tejcart_owns_customer_role', 'no' ) ) {
    remove_role( 'customer' );
}
delete_option( 'tejcart_owns_customer_role' );

/*
 * --- Step 4: bundled module role / cap teardown.
 *
 * WordPress only invokes uninstall.php for the registered plugin file
 * (`tejcart.php`), not for files inside the bundled modules under
 * `modules/`. The TejCart autoloader is also NOT registered during
 * uninstall (WP loads this file directly). Manually require each
 * module's Capabilities class — gated on file existence so the absence
 * of a module directory is non-fatal — then call its uninstall() to
 * strip caps from every role and remove module-owned roles (e.g. the
 * Returns Agent role).
 */
$module_capability_classes = array(
    __DIR__ . '/modules/disputes/src/Capabilities.php'         => '\\TejCart\\Tier2\\Disputes\\Capabilities',
    __DIR__ . '/modules/returns/src/Capabilities.php'          => '\\TejCart\\Tier2\\Returns\\Capabilities',
    __DIR__ . '/modules/shipping/src/Core/Capabilities.php'    => '\\TejCart\\Shipping_Plugin\\Core\\Capabilities',
    __DIR__ . '/modules/gift-cards/src/Capabilities.php'       => '\\TejCart\\Gift_Cards\\Capabilities',
    __DIR__ . '/modules/currency-switcher/src/Capabilities.php' => '\\TejCart\\Currency_Switcher\\Capabilities',
    __DIR__ . '/modules/ai-content-smartsuite/src/Capabilities.php' => '\\TejCart\\AI_Content_Smartsuite\\Capabilities',
    __DIR__ . '/modules/b2b/src/Capabilities.php'                   => '\\TejCart\\B2B\\Capabilities',
);
foreach ( $module_capability_classes as $caps_file => $class ) {
    if ( ! is_readable( $caps_file ) ) {
        continue;
    }
    require_once $caps_file;
    if ( class_exists( $class ) && method_exists( $class, 'uninstall' ) ) {
        call_user_func( array( $class, 'uninstall' ) );
    }
}

/*
 * Bundled-module data teardown that goes beyond options + capabilities.
 *
 * Some modules persist product meta and transients that the option /
 * prefix sweeps above cannot reach. They expose a global `*_uninstall()`
 * function in their `module.php` bootstrap (which also registers the
 * module's scoped autoloader) for exactly this path. Require the
 * bootstrap defensively — a deleted module folder must stay non-fatal —
 * then invoke the cleanup. The AI Content SmartSuite module owns
 * `_tejcart_ai_*` product meta + `tejcart_ai_bulk_/daily_tokens/hourly_`
 * transients removed by its Cleanup::run().
 */
$module_uninstall_bootstraps = array(
    __DIR__ . '/modules/ai-content-smartsuite/module.php' => 'tejcart_ai_content_smartsuite_uninstall',
);
foreach ( $module_uninstall_bootstraps as $bootstrap_file => $uninstall_fn ) {
    if ( ! is_readable( $bootstrap_file ) ) {
        continue;
    }
    require_once $bootstrap_file;
    if ( function_exists( $uninstall_fn ) ) {
        call_user_func( $uninstall_fn );
    }
}

// #1212: core Capabilities — same lazy-include + uninstall() pattern
// as the modules. Previously skipped, leaving manage_tejcart and
// friends on administrator / shop_manager roles after uninstall.
$core_caps_file = __DIR__ . '/src/Core/Capabilities.php';
if ( is_readable( $core_caps_file ) ) {
    require_once $core_caps_file;
    // Audit #35 / 09 F-004 — the method is named `uninstall()`, not
    // `remove_caps`. The mis-named guard returned false on every
    // uninstall, so `tejcart_*` capabilities and the `tejcart_shop_manager`
    // role survived even when the merchant explicitly opted into the
    // "delete data on uninstall" path.
    if ( class_exists( '\\TejCart\\Core\\Capabilities' ) && method_exists( '\\TejCart\\Core\\Capabilities', 'uninstall' ) ) {
        call_user_func( array( '\\TejCart\\Core\\Capabilities', 'uninstall' ) );
    }
}

// Modules also schedule WP-cron / Action Scheduler jobs. Best-effort
// cancel — the as_unschedule_all_actions() helper from Action Scheduler
// is only available if the plugin loaded it earlier, so fall back to
// the WP-cron API.
//
// #1212: the eight CORE Action Scheduler hooks registered by
// src/Core/Action_Scheduler.php were previously missing from this
// list. Without them the hooks fire-and-no-op forever after uninstall
// if Action Scheduler tables outlive TejCart. Added below alongside
// the module-owned hooks.
$module_scheduled_hooks = array(
    // Module hooks.
    'tejcart_analytics_fanout',
    'tejcart_analytics_advanced_incremental',
    'tejcart_analytics_advanced_rebuild_cohorts',
    'tejcart_analytics_advanced_rebuild_chunk',
    'tejcart_disputes_evidence_reminder',
    // Order-tracking: carrier polling + retention purge. Recurring; would
    // fire-and-no-op forever after uninstall if Action Scheduler outlives
    // TejCart. Names match Polling_Job::HOOK / Retention_Cron::HOOK and the
    // module's own disable() drain.
    'tejcart_order_tracking_poll',
    'tejcart_order_tracking_retention',
    // AI Content SmartSuite: per-product bulk-generate job. Cancelled
    // here directly (the module's Action_Scheduler wrapper isn't
    // autoloadable during uninstall) so queued jobs don't fire into a
    // removed handler.
    'tejcart_ai_content_generate_single',
    // Core hooks (see src/Core/Action_Scheduler.php::init).
    'tejcart_cleanup_sessions',
    'tejcart_check_pending_orders',
    'tejcart_send_low_stock_notifications',
    'tejcart_cleanup_logs',
    'tejcart_sweep_scheduled_sales',
    'tejcart_cleanup_webhook_option',
    'tejcart_co_lock_cleanup',
    'tejcart_webhook_deliveries_cleanup',
    // #1194 added the daily reconciliation hook; clear it too.
    'tejcart_webhook_reconcile',
    // Audit H-20 (Core F-004): three more recurring hooks from
    // Lock_Sweeper, Partition_Roller, and Tier-2 Abandoned_Cart.
    'tejcart_locks_sweep',
    'tejcart_partitions_roll',
    'tejcart_tier2_abandoned_cart_run',
    // 1.0.2: two more recurring hooks that were absent here — the PayPal
    // orphan-order sweep (PayPal_AJAX) and the stock-reservation prune
    // (Stock_Reservation) survived uninstall and fired into nothing.
    'tejcart_paypal_orphan_order_sweep',
    'tejcart_stock_reservation_prune',
    // 1.0.3: the on-demand product-image sideload single action
    // (Action_Scheduler::init) could outlive uninstall as a queued
    // no-op; clear any pending instances too.
    'tejcart_import_image_sideload',
);
foreach ( $module_scheduled_hooks as $hook ) {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( $hook );
    } elseif ( function_exists( 'wp_clear_scheduled_hook' ) ) {
        wp_clear_scheduled_hook( $hook );
    }
}

// F-L4 / #954: cache cleanup. wp_cache_flush_group() requires the
// active object-cache dropin to support group flushing (most modern
// dropins do; the default WP object cache always does in-memory).
// For belt-and-braces on dropins that silently no-op group flush,
// the merchant can opt in to a hard wp_cache_flush() via the
// tejcart_uninstall_force_cache_flush filter (defaults to false to
// avoid blowing away unrelated cache entries on shared installs).
if ( function_exists( 'wp_cache_flush_group' ) ) {
    wp_cache_flush_group( 'tejcart' );
    wp_cache_flush_group( 'tejcart_rate_limiter' );
    // Bundled-module cache groups.
    wp_cache_flush_group( 'tejcart_analytics' );
    wp_cache_flush_group( 'tejcart_disputes' );
    wp_cache_flush_group( 'tejcart_order_tracking' );
    wp_cache_flush_group( 'tejcart_returns' );
    wp_cache_flush_group( 'tejcart_shipping' );
} elseif ( function_exists( 'wp_cache_flush' ) ) {
    wp_cache_flush();
}

/**
 * F-L4 / #954: opt-in hard flush.
 *
 * @param bool $force Default false.
 */
if ( apply_filters( 'tejcart_uninstall_force_cache_flush', false ) && function_exists( 'wp_cache_flush' ) ) {
    wp_cache_flush();
}

    // End of per-site uninstall body (F-M7 / #941).
    if ( $tejcart_site_id > 0 && function_exists( 'restore_current_blog' ) ) {
        restore_current_blog();
    }
}
