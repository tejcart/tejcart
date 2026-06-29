<?php
/**
 * Admin bootstrap class.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initializes the TejCart admin area, enqueues assets,
 * and bootstraps admin sub-components.
 */
class Admin {
    /**
     * Menu instance.
     *
     * @var Menu
     */
    protected $menu;

    /**
     * Initialize admin hooks and components.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_init', array( $this, 'handle_permalink_notice_dismiss' ) );
        add_action( 'admin_init', array( $this, 'handle_no_object_cache_notice_dismiss' ) );
        add_action( 'admin_notices', array( $this, 'maybe_render_permalink_notice' ) );
        // Audit #28 / 08 #7 — warn merchants who run without an
        // external object cache. The rate-limiter then writes one
        // transient row per (action, IP) into wp_options and a
        // busy guest-traffic store can balloon the autoload set
        // until the standard WP transient cleanup catches up.
        add_action( 'admin_notices', array( $this, 'maybe_render_no_object_cache_notice' ) );

        $this->menu = new Menu();
        $this->menu->init();

        ( new Menu_Aliases() )->init();
        Sales_Channels_Page::init();

        $settings_page = new \TejCart\Settings\Settings_Page();
        $settings_page->init();

        $import_export = new Product_Import_Export();
        $import_export->init();

        $order_customer_export = new Order_Customer_Export();
        $order_customer_export->init();

        $system_status = new System_Status();
        $system_status->init();

        $reports = new Reports();
        $reports->init();

        $tax_rates = new Tax_Rates_Table();
        add_action( 'admin_init', array( $tax_rates, 'handle_actions' ) );

        $shipping_zones = new Shipping_Zones_Table();
        add_action( 'admin_init', array( $shipping_zones, 'handle_actions' ) );

        ( new API_Keys_Page() )->init();
        ( new Tools_Page() )->init();
        ( new Scheduled_Actions_Page() )->init();
        ( new Log_Viewer() )->init();
        // Captcha settings UI moved to the optional `captcha` module
        // (modules/captcha/), which self-registers its Advanced → Captcha
        // section via the tejcart_settings_advanced_sub_nav_items seam.
        // 06 F-M6 / F-M7 — Emails settings tab gains a "Send test"
        // button per registered email and the wp_tejcart_email_log
        // viewer that the Tier-2 Template_System has been silently
        // populating with no admin surface.
        ( new Email_Admin_Page() )->init();
        ( new \TejCart\Modules\Modules_Page() )->init();

        new Order_Admin();

        ( new Product_Form() )->init();

        ( new Dashboard_Widget() )->init();

        ( new Order_Preview() )->init();
    }

    /**
     * Enqueue admin CSS and JS on TejCart pages only.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public function enqueue_assets( $hook ) {
        if ( ! $this->is_tejcart_page( $hook ) ) {
            return;
        }

        $version    = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';

        wp_enqueue_media();
        wp_enqueue_style( 'wp-color-picker' );

        wp_enqueue_style(
            'tejcart-admin',
            tejcart_asset_url( 'assets/css/tejcart-admin.css' ),
            array( 'wp-color-picker' ),
            $version
        );

        wp_enqueue_script(
            'tejcart-admin',
            tejcart_asset_url( 'assets/js/tejcart-admin.js' ),
            array( 'jquery', 'wp-color-picker' ),
            $version,
            true
        );

        $is_dashboard     = 'toplevel_page_tejcart' === $hook;
        // Only the add/edit screens render a non-list view; every other
        // `action` value (including the bulk-actions dropdown's `-1`, which
        // the single GET form serialises into the URL on search/filter)
        // still renders the list table, so allowlist the detail actions
        // rather than treating any `action` param as "not the list".
        $is_products_list = 'tejcart_page_tejcart-products' === $hook
            && ! in_array( isset( $_GET['action'] ) ? $_GET['action'] : '', array( 'add', 'edit' ), true ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
            && ! isset( $_GET['section'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_orders_list   = 'tejcart_page_tejcart-orders' === $hook
            && ! in_array( isset( $_GET['action'] ) ? $_GET['action'] : '', array( 'view', 'edit', 'new' ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_customers_list = 'tejcart_page_tejcart-customers' === $hook
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && 'view' !== ( isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '' );
        $is_coupons_list   = 'tejcart_page_tejcart-coupons' === $hook
            && ! in_array( isset( $_GET['action'] ) ? $_GET['action'] : '', array( 'add', 'edit' ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_reports_page   = 'tejcart_page_tejcart-reports' === $hook;

        $is_paypal_manage  = 'tejcart_page_' . PayPal_Manage_Page::PAGE_SLUG === $hook;
        $is_modules_page   = 'tejcart_page_tejcart-modules' === $hook;

        $core_list_pages = array();
        if ( $is_dashboard )      { $core_list_pages[] = 'toplevel_page_tejcart'; }
        if ( $is_products_list )  { $core_list_pages[] = 'tejcart_page_tejcart-products'; }
        if ( $is_orders_list )    { $core_list_pages[] = 'tejcart_page_tejcart-orders'; }
        if ( $is_customers_list ) { $core_list_pages[] = 'tejcart_page_tejcart-customers'; }
        if ( $is_coupons_list )   { $core_list_pages[] = 'tejcart_page_tejcart-coupons'; }
        if ( $is_reports_page )   { $core_list_pages[] = 'tejcart_page_tejcart-reports'; }
        if ( $is_paypal_manage )  { $core_list_pages[] = 'tejcart_page_' . PayPal_Manage_Page::PAGE_SLUG; }
        if ( $is_modules_page )   { $core_list_pages[] = 'tejcart_page_tejcart-modules'; }

        /**
         * Filter the list of admin-page hook suffixes that should also
         * receive the `admin-list.css` bundle (status badges, nxc-card,
         * tablenav, empty-state, etc.). Bundled modules / addons that
         * render their own WP_List_Table-style screen append their hook
         * here so they stay visually aligned with the core list pages.
         *
         * @param string[] $core_list_pages Hook suffixes already opted in by core.
         */
        $list_pages = (array) apply_filters( 'tejcart_admin_list_page_hooks', $core_list_pages );

        if ( in_array( $hook, $list_pages, true ) ) {
            wp_enqueue_style(
                'tejcart-admin-list',
                tejcart_asset_url( 'assets/css/admin-list.css' ),
                array( 'tejcart-admin' ),
                $version
            );
        }

        if ( $is_products_list || $is_orders_list || $is_customers_list || $is_coupons_list || $is_reports_page ) {
            wp_enqueue_script(
                'tejcart-admin-list',
                tejcart_asset_url( 'assets/js/admin-list.js' ),
                array( 'tejcart-admin' ),
                $version,
                true
            );
        }

        wp_enqueue_script(
            'tejcart-admin-pages',
            tejcart_asset_url( 'assets/js/tejcart-admin-pages.js' ),
            array( 'tejcart-admin' ),
            $version,
            true
        );

        // The locale.js swapper looks for any country select tagged with
        // `data-tejcart-state-pair` and swaps its paired state field
        // between <select> (when the country has states) and <input>
        // (when it doesn't). It used to be Settings-only but several
        // other admin screens now use the same pairing convention
        // (Tax Rates editor, New Order form), so enqueue it everywhere
        // — it is a no-op when no paired controls exist on the page.
        wp_enqueue_script(
            'tejcart-admin-settings-locale',
            tejcart_asset_url( 'assets/js/tejcart-admin-settings-locale.js' ),
            array(),
            $version,
            true
        );

        $states_for_locale_file = TEJCART_PLUGIN_DIR . 'src/Tax/Data/states.php';
        $states_for_locale_data = is_readable( $states_for_locale_file ) ? require $states_for_locale_file : array();

        wp_localize_script(
            'tejcart-admin-settings-locale',
            'tejcart_admin_settings_locale',
            array(
                'states' => is_array( $states_for_locale_data ) ? $states_for_locale_data : array(),
                'i18n'   => array(
                    'selectState' => __( '— Select a state —', 'tejcart' ),
                ),
            )
        );

        $countries_file = TEJCART_PLUGIN_DIR . 'src/Tax/Data/countries.php';
        $countries_data = is_readable( $countries_file ) ? require $countries_file : array();
        if ( ! is_array( $countries_data ) ) {
            $countries_data = array();
        }
        // Decode HTML entities once for the JS side — the picker renders
        // labels via .textContent, so it can show "São Tomé" rather than
        // the raw "S&atilde;o Tom&eacute;" entities the dataset stores.
        $countries_data = array_map(
            static fn( $name ) => html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            $countries_data
        );
        asort( $countries_data, SORT_NATURAL | SORT_FLAG_CASE );

        $states_file = TEJCART_PLUGIN_DIR . 'src/Tax/Data/states.php';
        $states_data = is_readable( $states_file ) ? require $states_file : array();
        if ( ! is_array( $states_data ) ) {
            $states_data = array();
        }

        wp_localize_script(
            'tejcart-admin-pages',
            'tejcart_admin_pages',
            array(
                'shippingZones' => array(
                    'methodTypes' => Shipping_Zones_Table::method_type_labels(),
                    'regions'     => array(
                        'countries' => $countries_data,
                        'states'    => $states_data,
                    ),
                    'i18n'        => array(
                        'remove'           => __( 'Remove', 'tejcart' ),
                        'serviceCodeLabel' => __( 'Service code (optional)', 'tejcart' ),
                        'serviceCodeHint'  => __( 'Leave blank to fan out into all carrier services', 'tejcart' ),
                        'carrierNote'      => __( 'Live rates are fetched from the carrier at checkout. Cost, min amount and weight brackets do not apply.', 'tejcart' ),
                        'bracketHint'      => __( 'One bracket per line. Use 0 for unlimited "to".', 'tejcart' ),
                        'regionSearch'     => __( 'Search countries or states…', 'tejcart' ),
                        'regionAll'        => __( 'Entire country', 'tejcart' ),
                        'regionStates'     => __( 'States & provinces', 'tejcart' ),
                        'regionNoResults'  => __( 'No countries or states match your search.', 'tejcart' ),
                        /* translators: %s: region (country or state) name. */
                        'regionRemove'     => __( 'Remove %s', 'tejcart' ),
                        'regionBack'       => __( '← Back to all countries', 'tejcart' ),
                    ),
                ),
                'orderPreview'  => array(
                    'loading' => __( 'Loading…', 'tejcart' ),
                    'error'   => __( 'Preview failed.', 'tejcart' ),
                    'network' => __( 'Network error.', 'tejcart' ),
                ),
                'systemStatus'  => array(
                    'copied' => __( 'System status copied to clipboard.', 'tejcart' ),
                ),
            )
        );

        $currency = get_option( 'tejcart_currency', 'USD' );

        wp_localize_script(
            'tejcart-admin',
            'tejcart_admin',
            array(
                'ajax_url'      => admin_url( 'admin-ajax.php' ),
                'nonce'         => wp_create_nonce( 'tejcart_admin_nonce' ),
                'currency'      => $currency,
                'i18n_enabled'  => __( 'Enabled', 'tejcart' ),
                'i18n_disabled' => __( 'Disabled', 'tejcart' ),
                'i18n_upload'   => __( 'Upload', 'tejcart' ),
                'i18n_replace'  => __( 'Replace', 'tejcart' ),
            )
        );

        if ( 'tejcart_page_tejcart-settings' === $hook ) {
            // The default gateway provider is null; Settings_Search_Index
            // will fall back to `Gateway_Registry::get_gateways()` which
            // is the right thing at admin-page render time. Explicit
            // injection here is documented for tests + future refactors.
            $search_index = ( new \TejCart\Settings\Settings_Search_Index() )->build();

            wp_localize_script(
                'tejcart-admin',
                'tejcartSettingsSearch',
                array(
                    'entries' => $search_index,
                    'i18n'    => array(
                        'placeholder' => __( 'Search every setting…', 'tejcart' ),
                        'noResults'   => __( 'No settings match your search.', 'tejcart' ),
                        'in'          => _x( 'in', 'Breadcrumb separator: "Store Name in General"', 'tejcart' ),
                        /* translators: %s: setting / section name to jump to. */
                        'jumpTo'      => __( 'Jump to %s', 'tejcart' ),
                        'sectionLabel'=> _x( 'Section', 'Result type badge in the search palette', 'tejcart' ),
                        'tabLabel'    => _x( 'Tab', 'Result type badge in the search palette', 'tejcart' ),
                        'fieldLabel'  => _x( 'Setting', 'Result type badge in the search palette', 'tejcart' ),
                        'resultsAnnounce' => /* translators: %d: number of search results. */ __( '%d results', 'tejcart' ),
                    ),
                )
            );
        }

        if ( $this->is_paypal_settings_page( $hook ) ) {
            // PayPal-hosted onboarding SDK: must load by stable URL with no
            // cache-buster query string, hence intentional null version.
            wp_enqueue_script(
                'tejcart-paypal-partner-sdk',
                'https://www.paypal.com/webapps/merchantboarding/js/lib/lightbox/partner.js',
                array(),
                null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion,WordPress.WP.EnqueuedResourceParameters.NotInFooter
                true
            );

            wp_enqueue_script(
                'tejcart-paypal-onboarding',
                tejcart_asset_url( 'assets/js/tejcart-paypal-onboarding.js' ),
                array( 'jquery', 'tejcart-admin', 'tejcart-paypal-partner-sdk' ),
                $version,
                true
            );
            wp_localize_script(
                'tejcart-paypal-onboarding',
                'tejcart_paypal_onboarding',
                array(
                    'ajax_url'      => admin_url( 'admin-ajax.php' ),
                    'nonce'         => \TejCart\Gateways\PayPal\PayPal_Onboarding::ajax_nonce(),
                    'test_nonce'    => wp_create_nonce( 'tejcart_paypal_test_connection' ),
                    'i18n'          => array(
                        'preparing'         => __( 'Preparing secure PayPal window…', 'tejcart' ),
                        'opened'            => __( 'Continue inside the PayPal window.', 'tejcart' ),
                        'finalising'        => __( 'Finalising connection…', 'tejcart' ),
                        'connected'         => __( 'PayPal connected successfully.', 'tejcart' ),
                        'disconnecting'     => __( 'Disconnecting…', 'tejcart' ),
                        'disconnected'      => __( 'PayPal disconnected.', 'tejcart' ),
                        'error'             => __( 'Something went wrong. Please try again.', 'tejcart' ),
                        'network'           => __( 'Network error. Please try again.', 'tejcart' ),
                        'confirmDisconnect' => __( "Disconnect this PayPal environment?\n\nThis immediately stops PayPal, Card, Google Pay, Apple Pay, and Pay Later from accepting new payments at checkout until you reconnect. Existing orders are unaffected.\n\nYou can reconnect at any time from this page.", 'tejcart' ),
                        'testConnection'    => __( 'Test connection', 'tejcart' ),
                        'testing'           => __( 'Testing credentials…', 'tejcart' ),
                        'testSuccess'       => __( 'Connection successful — credentials are valid.', 'tejcart' ),
                        'testError'         => __( 'Connection failed. PayPal rejected the current credentials.', 'tejcart' ),
                        'unsavedChanges'    => __( 'You have unsaved changes. Leave this section without saving?', 'tejcart' ),
                    ),
                )
            );
        }
    }

    /**
     * Check whether we're currently rendering the PayPal gateway's settings
     * page so PayPal-specific assets can be enqueued just there.
     */
    private function is_paypal_settings_page( string $hook ): bool {
        if ( 'tejcart_page_' . PayPal_Manage_Page::PAGE_SLUG === $hook ) {
            return true;
        }

        if ( 'tejcart_page_tejcart-payment-method-settings' !== $hook ) {
            return false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $gateway = isset( $_GET['gateway'] ) ? sanitize_text_field( wp_unslash( $_GET['gateway'] ) ) : '';
        return 'tejcart_paypal' === $gateway;
    }

    /**
     * Check whether the current admin page belongs to TejCart.
     *
     * @param string $hook The current admin page hook suffix.
     * @return bool
     */
    public function is_tejcart_page( $hook ) {
        $tejcart_pages = array(
            'toplevel_page_tejcart',
            'tejcart_page_tejcart-products',
            'tejcart_page_tejcart-orders',
            'tejcart_page_tejcart-customers',
            'tejcart_page_tejcart-coupons',
            'tejcart_page_tejcart-reports',
            'tejcart_page_tejcart-settings',
            'tejcart_page_tejcart-payment-method-settings',
            'tejcart_page_tejcart-modules',
            'tejcart_page_' . PayPal_Manage_Page::PAGE_SLUG,
        );

        /**
         * Filter the list of admin-page hook suffixes considered "TejCart pages".
         *
         * Bundled modules and addons append their submenu hooks here so the
         * core admin CSS/JS (tejcart-admin.css, page-header tokens) loads on
         * their screens and the UI stays visually aligned with core.
         *
         * @param string[] $tejcart_pages List of admin hook suffixes.
         */
        $tejcart_pages = (array) apply_filters( 'tejcart_admin_page_hooks', $tejcart_pages );

        return in_array( $hook, $tejcart_pages, true );
    }

    /**
     * User meta key used to remember a permalink-notice dismissal.
     */
    private const PERMALINK_NOTICE_META = 'tejcart_dismissed_permalink_notice';

    /**
     * User meta key used to remember a no-object-cache-notice dismissal.
     */
    private const NO_OBJECT_CACHE_NOTICE_META = 'tejcart_dismissed_no_object_cache_notice';

    /**
     * Transient that caches the count of rate-limiter rows in wp_options
     * so the threshold check doesn't re-scan on every admin page load.
     */
    private const NO_OBJECT_CACHE_COUNT_TRANSIENT = 'tejcart_no_object_cache_rl_count';

    /**
     * Default number of `_transient_tejcart_rl_*` rows below which the
     * notice stays quiet. Below this threshold the wp_options growth
     * this warning is about hasn't started, so the notice has no
     * actionable signal for the merchant.
     */
    private const NO_OBJECT_CACHE_DEFAULT_THRESHOLD = 50;

    /**
     * Surface a warning when no external object cache is installed.
     * Audit #28 / 08 #7 — the rate-limiter falls back to `set_transient`
     * which writes one row per `(action, IP)` into wp_options. Busy
     * guest-traffic stores can balloon the autoload set until WP's
     * standard transient cleanup catches up.
     *
     * The notice stays quiet until rate-limiter rows actually start
     * accumulating in wp_options (see the threshold helper) and can be
     * dismissed per-user once acknowledged, so low-traffic stores and
     * merchants who've already made the trade-off don't keep getting
     * nagged.
     */
    public function maybe_render_no_object_cache_notice(): void {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
            return;
        }
        if ( wp_using_ext_object_cache() ) {
            return;
        }

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $user_id > 0 && function_exists( 'get_user_meta' ) ) {
            $dismissed = get_user_meta( $user_id, self::NO_OBJECT_CACHE_NOTICE_META, true );
            if ( $dismissed ) {
                return;
            }
        }

        if ( function_exists( 'apply_filters' ) && ! apply_filters( 'tejcart_show_no_object_cache_notice', true ) ) {
            return;
        }

        if ( ! $this->no_object_cache_notice_threshold_reached() ) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            admin_url( 'admin.php?tejcart_dismiss_notice=no_object_cache' ),
            'tejcart_dismiss_no_object_cache_notice'
        );

        echo '<div class="notice notice-warning is-dismissible tejcart-no-object-cache-notice"><p><strong>TejCart:</strong> ';
        echo esc_html__(
            'No persistent object cache is installed. TejCart\'s rate-limiter falls back to WordPress transients, which write one wp_options row per (action, IP). Under busy guest traffic this can balloon the autoload set. Install an object-cache drop-in (Redis, Memcached) or accept the wp_options growth — TejCart will continue to function, but rate-limit hits will be visible in the options table.',
            'tejcart'
        );
        echo ' ';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tejcart_doc_link returns pre-escaped HTML.
        echo tejcart_doc_link( 'troubleshooting/notices/object-cache', __( 'How to install an object cache', 'tejcart' ) );
        echo ' &middot; ';
        printf(
            '<a href="%s">%s</a>',
            esc_url( $dismiss_url ),
            esc_html__( 'Dismiss', 'tejcart' )
        );
        echo '</p></div>';
    }

    /**
     * True when enough `_transient_tejcart_rl_*` rows exist in wp_options
     * that the no-object-cache warning is actionable. The count is cached
     * for an hour so the LIKE scan doesn't run on every admin page load.
     *
     * A filtered threshold of 0 (or less) disables the gate entirely and
     * is treated as "always show", which is useful for tests and for
     * merchants who want the notice up immediately.
     */
    private function no_object_cache_notice_threshold_reached(): bool {
        $threshold = self::NO_OBJECT_CACHE_DEFAULT_THRESHOLD;
        if ( function_exists( 'apply_filters' ) ) {
            $threshold = (int) apply_filters( 'tejcart_no_object_cache_notice_threshold', $threshold );
        }
        if ( $threshold <= 0 ) {
            return true;
        }

        $count = function_exists( 'get_transient' )
            ? get_transient( self::NO_OBJECT_CACHE_COUNT_TRANSIENT )
            : false;
        if ( false === $count ) {
            $count = $this->count_rate_limiter_transients();
            if ( function_exists( 'set_transient' ) ) {
                set_transient(
                    self::NO_OBJECT_CACHE_COUNT_TRANSIENT,
                    $count,
                    defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600
                );
            }
        }

        return (int) $count >= $threshold;
    }

    /**
     * Count rate-limiter transient rows currently parked in wp_options.
     * Matches both the value row (`_transient_tejcart_rl_*`) and its
     * timeout sibling — that's intentional: both rows contribute to the
     * autoload pressure this warning is about, so counting them together
     * keeps the threshold math honest.
     */
    private function count_rate_limiter_transients(): int {
        global $wpdb;

        $like = $wpdb->esc_like( '_transient_' ) . '%tejcart_rl_%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached for an hour via the count transient above.
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            )
        );

        return (int) $count;
    }

    /**
     * Persist a no-object-cache-notice dismissal against the current
     * user. Mirrors the permalink dismiss flow: fires on admin_init so
     * the redirect lands before admin_notices renders.
     */
    public function handle_no_object_cache_notice_dismiss(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['tejcart_dismiss_notice'] ) || 'no_object_cache' !== $_GET['tejcart_dismiss_notice'] ) {
            return;
        }
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_dismiss_no_object_cache_notice' ) ) {
            return;
        }

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $user_id > 0 && function_exists( 'update_user_meta' ) ) {
            update_user_meta( $user_id, self::NO_OBJECT_CACHE_NOTICE_META, 1 );
        }

        $referer = function_exists( 'wp_get_referer' ) ? wp_get_referer() : '';
        if ( is_string( $referer ) && '' !== $referer ) {
            wp_safe_redirect( $referer );
        } else {
            wp_safe_redirect( admin_url() );
        }
        exit;
    }

    /**
     * Render a persistent admin notice when the site is running on Plain
     * permalinks. TejCart supports that mode (every shop URL falls back to
     * `?product=<id>`) but loses the SEO-friendly rewrite — admins should
     * know so they can switch.
     */
    public function maybe_render_permalink_notice(): void {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( '' !== (string) get_option( 'permalink_structure', '' ) ) {
            return;
        }

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $user_id > 0 && function_exists( 'get_user_meta' ) ) {
            $dismissed = get_user_meta( $user_id, self::PERMALINK_NOTICE_META, true );
            if ( $dismissed ) {
                return;
            }
        }

        $permalinks_url = admin_url( 'options-permalink.php' );
        $dismiss_url    = wp_nonce_url(
            admin_url( 'admin.php?tejcart_dismiss_notice=permalink' ),
            'tejcart_dismiss_permalink_notice'
        );

        echo '<div class="notice notice-warning is-dismissible tejcart-permalink-notice">';
        echo '<p>';
        echo esc_html__( 'TejCart works best with pretty permalinks enabled. Please go to Settings → Permalinks and choose any option other than Plain.', 'tejcart' );
        echo ' ';
        printf(
            '<a href="%s">%s</a> &middot; %s &middot; <a href="%s">%s</a>',
            esc_url( $permalinks_url ),
            esc_html__( 'Open Permalink Settings', 'tejcart' ),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tejcart_doc_link returns pre-escaped HTML.
            tejcart_doc_link( 'troubleshooting/notices/permalinks', __( 'Step-by-step guide', 'tejcart' ) ),
            esc_url( $dismiss_url ),
            esc_html__( 'Dismiss', 'tejcart' )
        );
        echo '</p>';
        echo '</div>';
    }

    /**
     * Persist a permalink-notice dismissal against the current user. Fires
     * on admin_init because we need the redirect to land before admin_notices.
     */
    public function handle_permalink_notice_dismiss(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['tejcart_dismiss_notice'] ) || 'permalink' !== $_GET['tejcart_dismiss_notice'] ) {
            return;
        }
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_dismiss_permalink_notice' ) ) {
            return;
        }

        $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
        if ( $user_id > 0 && function_exists( 'update_user_meta' ) ) {
            update_user_meta( $user_id, self::PERMALINK_NOTICE_META, 1 );
        }

        $referer = function_exists( 'wp_get_referer' ) ? wp_get_referer() : '';
        if ( is_string( $referer ) && '' !== $referer ) {
            wp_safe_redirect( $referer );
        } else {
            wp_safe_redirect( admin_url() );
        }
        exit;
    }
}
