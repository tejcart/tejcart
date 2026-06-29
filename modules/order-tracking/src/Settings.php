<?php
/**
 * Settings page — admin UI for non-developer merchants.
 *
 * Registers an "Order Tracking" tab inside the unified TejCart Settings page
 * (under the "Selling" group, alongside Shipping and Tax) so the module's UI
 * matches core's design language. Within the tab a horizontal sub-nav exposes
 * a Settings sub-section, one sub-section per registered carrier provider
 * (EasyPost, Shippo, AfterShip, plus anything 3rd parties register via the
 * `tejcart_order_tracking_providers` filter), and a Tools sub-section — same
 * shape as Tax. Putting each provider on its own page keeps the page focused
 * (one set of credentials at a time) and makes "Test connection" / status
 * messaging easier to scan.
 *
 * Storage strategy:
 *  - All settings live under a single option key
 *    (`tejcart_order_tracking_settings`) so reads are a single DB hit
 *    on every page load; writes go through `Settings::update()`.
 *  - API keys and webhook secrets are encrypted at rest using core's
 *    `TejCart\Security\Crypto` when available (AES-256-GCM). When the
 *    crypto class is absent (older core or test) the plain value is
 *    written and a notice is shown.
 *  - When a `TEJCART_ORDER_TRACKING_*` constant is defined it ALWAYS
 *    wins over the stored option — this matches TejCart core's
 *    "constants are king" pattern for staging / CI lockdown.
 *
 * The Providers sub-section also exposes a "Test connection" button per
 * provider that drives `Tracking_Provider::fetch_status` against a
 * known-bad tracking number (so we trip the API auth path without
 * actually creating real trackers) and reports the round-trip status.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

use TejCart\Tier2\Order_Tracking\Providers\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    public const OPTION_KEY     = 'tejcart_order_tracking_settings';
    public const TAB_ID         = 'order-tracking';
    public const NONCE          = 'tejcart_order_tracking_settings';
    public const SECTION_PREFIX = 'provider-';

    /**
     * Legacy slug retained as a redirect target so old bookmarks continue to work.
     *
     * MUST NOT be `tejcart`: that is core's top-level menu slug
     * ({@see \TejCart\Admin\Menu} — `admin.php?page=tejcart` is the TejCart
     * Dashboard). Using it here made `maybe_redirect_legacy_menu()` 302 the
     * core dashboard to this tab on every load, rendering the dashboard
     * unreachable whenever the module was enabled.
     */
    public const PAGE_SLUG = 'tejcart-order-tracking';

    private Provider_Registry $providers;
    private ?Admin_Tools $tools = null;

    public function __construct( Provider_Registry $providers ) {
        $this->providers = $providers;
    }

    public function set_tools( Admin_Tools $tools ): void {
        $this->tools = $tools;
    }

    public function register(): void {
        add_action( 'admin_init',                array( $this, 'maybe_save' ) );
        add_action( 'admin_menu',                array( $this, 'maybe_redirect_legacy_menu' ), 100 );
        add_action( 'admin_enqueue_scripts',     array( $this, 'maybe_enqueue_assets' ) );
        add_action( 'wp_ajax_tejcart_tracking_test_provider', array( $this, 'ajax_test_provider' ) );

        // Slot the module into core's Settings page.
        add_filter( 'tejcart_settings_tabs',                            array( $this, 'register_tab' ) );
        add_filter( 'tejcart_settings_tab_groups',                      array( $this, 'register_tab_group' ), 10, 2 );
        add_filter( 'tejcart_settings_subnav_items_' . self::TAB_ID,    array( $this, 'register_subnav_items' ) );
        add_action( 'tejcart_settings_render_tab_' . self::TAB_ID,      array( $this, 'render_tab' ), 10, 2 );

        // Bridge settings → existing filters so the rest of the plugin
        // doesn't need to know the option exists.
        add_filter( 'tejcart_order_tracking_easypost_api_key',        array( $this, 'filter_easypost_api_key' ), 5 );
        add_filter( 'tejcart_order_tracking_easypost_webhook_secret', array( $this, 'filter_easypost_webhook_secret' ), 5 );
        add_filter( 'tejcart_order_tracking_shippo_api_key',          array( $this, 'filter_shippo_api_key' ), 5 );
        add_filter( 'tejcart_order_tracking_aftership_api_key',       array( $this, 'filter_aftership_api_key' ), 5 );
        add_filter( 'tejcart_order_tracking_aftership_webhook_secret', array( $this, 'filter_aftership_webhook_secret' ), 5 );
        add_filter( 'tejcart_order_tracking_easypost_mode',           array( $this, 'filter_provider_mode' ), 5, 2 );
        add_filter( 'tejcart_order_tracking_shippo_mode',             array( $this, 'filter_provider_mode' ), 5, 2 );
        add_filter( 'tejcart_order_tracking_aftership_mode',          array( $this, 'filter_provider_mode' ), 5, 2 );
        add_filter( 'tejcart_order_tracking_retention_days',          array( $this, 'filter_retention_days' ), 5 );
        add_filter( 'tejcart_order_tracking_delivered_retention_days', array( $this, 'filter_delivered_retention_days' ), 5 );
        add_filter( 'tejcart_order_tracking_poll_interval',           array( $this, 'filter_poll_interval' ), 5 );
    }

    /**
     * Enqueue the module's admin CSS on the Settings → Order Tracking tab so
     * the in-tab UI inherits the same look as the order edit metabox.
     */
    public function maybe_enqueue_assets( string $hook_suffix ): void {
        // Settings page lives under tejcart_page_tejcart-settings (or toplevel
        // when TejCart is the top-level menu — both forms are tolerated).
        if ( false === strpos( (string) $hook_suffix, 'tejcart-settings' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        if ( self::TAB_ID !== $tab ) {
            return;
        }
        $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
        wp_enqueue_style(
            'tejcart-order-tracking-admin',
            plugins_url( 'assets/admin/order-tracking-admin' . $suffix . '.css', TEJCART_ORDER_TRACKING_FILE ),
            array(),
            TEJCART_ORDER_TRACKING_VERSION
        );
    }

    public static function settings_url( string $section = '' ): string {
        $url = admin_url( 'admin.php?page=tejcart-settings&tab=' . self::TAB_ID );
        if ( '' !== $section ) {
            $url = add_query_arg( 'section', $section, $url );
        }
        return $url;
    }

    /**
     * Redirect anyone hitting the legacy standalone submenu URLs to the
     * new Settings tab so old bookmarks don't 404.
     */
    public function maybe_redirect_legacy_menu(): void {
        if ( ! is_admin() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( '' === $page ) {
            return;
        }
        if ( self::PAGE_SLUG === $page ) {
            wp_safe_redirect( self::settings_url() );
            exit;
        }
        if ( Admin_Tools::PAGE_SLUG === $page ) {
            wp_safe_redirect( self::settings_url( 'tools' ) );
            exit;
        }

        // Legacy combined "Providers" section URL — redirect to the first
        // registered provider's dedicated sub-tab so old bookmarks land
        // somewhere meaningful.
        if ( 'tejcart-settings' === $page ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : '';
            if ( self::TAB_ID === $tab && 'providers' === $section ) {
                $first = $this->first_provider_slug();
                if ( null !== $first ) {
                    wp_safe_redirect( self::settings_url( self::SECTION_PREFIX . $first ) );
                    exit;
                }
            }
        }
    }

    /**
     * Register the "Order Tracking" tab.
     *
     * @param array<string,array<string,mixed>> $tabs
     * @return array<string,array<string,mixed>>
     */
    public function register_tab( array $tabs ): array {
        $tabs[ self::TAB_ID ] = array(
            'id'       => self::TAB_ID,
            'label'    => __( 'Order Tracking', 'tejcart' ),
            'icon'     => 'dashicons-location-alt',
            'desc'     => __( 'Carrier tracking, polling, and bulk import.', 'tejcart' ),
            'sections' => array(),
        );
        return $tabs;
    }

    /**
     * Place the tab inside the "Selling" group, just after Shipping/Tax.
     *
     * @param array<string,array{label:string,tabs:string[]}> $groups
     * @param array<string,mixed>                              $tabs
     * @return array<string,array{label:string,tabs:string[]}>
     */
    public function register_tab_group( array $groups, array $tabs ): array {
        if ( isset( $groups['commerce'] ) && is_array( $groups['commerce']['tabs'] ?? null ) ) {
            if ( ! in_array( self::TAB_ID, $groups['commerce']['tabs'], true ) ) {
                $groups['commerce']['tabs'][] = self::TAB_ID;
            }
        }
        return $groups;
    }

    /**
     * Sub-nav for the tab — empty key is the default "Settings" view, then
     * one entry per registered carrier provider, then Tools. Building from
     * the registry means 3rd-party providers added via the
     * `tejcart_order_tracking_providers` filter show up automatically.
     *
     * @return array<string,string>
     */
    public function register_subnav_items(): array {
        $items = array(
            ''        => __( 'Settings', 'tejcart' ),
            'display' => __( 'Display', 'tejcart' ),
        );
        foreach ( $this->providers->all() as $provider ) {
            $items[ self::SECTION_PREFIX . $provider->slug() ] = $provider->label();
        }
        $items['tools'] = __( 'Tools', 'tejcart' );
        return $items;
    }

    private function first_provider_slug(): ?string {
        foreach ( $this->providers->all() as $provider ) {
            return $provider->slug();
        }
        return null;
    }

    /**
     * Allowed per-provider modes. Anything else collapses to MODE_LIVE so a
     * corrupt option (or a stray constant) can never silently route traffic
     * at the wrong environment.
     */
    public const MODE_LIVE = 'live';
    public const MODE_TEST = 'test';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return array(
            // Live credentials (the existing keys — names preserved for
            // back-compat with sites that have already saved them).
            'easypost_api_key'                  => '',
            'easypost_webhook_secret'           => '',
            'shippo_api_key'                    => '',
            'aftership_api_key'                 => '',
            'aftership_webhook_secret'          => '',
            // Sandbox / test credentials. Empty until the merchant opts in.
            'easypost_test_api_key'             => '',
            'easypost_test_webhook_secret'      => '',
            'shippo_test_api_key'               => '',
            'aftership_test_api_key'            => '',
            'aftership_test_webhook_secret'     => '',
            // Per-provider mode. Defaults to "live" so existing installs
            // keep using their stored credentials with zero behaviour change.
            'easypost_mode'                     => self::MODE_LIVE,
            'shippo_mode'                       => self::MODE_LIVE,
            'aftership_mode'                    => self::MODE_LIVE,
            'retention_days'                    => Retention_Cron::DEFAULT_SOFT_DELETE_DAYS,
            'delivered_retention_days'          => Retention_Cron::DEFAULT_DELIVERED_DAYS,
            'poll_interval'                     => 'hourly',
            'enable_polling'                    => 1,
            // Display defaults — all surfaces ON for parity with pre-1.x
            // behaviour. Merchants who don't want a panel can opt out.
            'display_customer_view'             => 1,
            'display_thankyou'                  => 1,
            'display_events'                    => 1,
            'display_orders_column'             => 1,
            'display_emails'                    => 1,
            'display_heading'                   => '',
        );
    }

    /**
     * Per-request mode overrides keyed by slug. Populated by the "Test
     * connection" AJAX handler so the merchant can exercise sandbox keys
     * without having to first save the form (and so we don't have to
     * mutate the option mid-request).
     *
     * @var array<string,string>
     */
    private static array $mode_overrides = array();

    /**
     * Resolve the active mode for a provider. Order: per-request override
     * (used by the Test-connection AJAX path) → matching
     * `TEJCART_ORDER_TRACKING_<SLUG>_MODE` constant → stored option →
     * default of "live".
     */
    public static function resolve_mode( string $slug ): string {
        $value = self::resolve_mode_unfiltered( $slug );

        // N-M2: surface the documented per-provider mode filter so 3rd
        // parties can override sandbox/live without writing to the option.
        // The core Settings::filter_provider_mode() listener intentionally
        // skips the filter step (it reads via resolve_mode_unfiltered) so
        // this apply_filters() call cannot recurse into itself.
        if ( function_exists( 'apply_filters' ) ) {
            /**
             * Filter the resolved sandbox/live mode for an order-tracking
             * provider. The hook name is dynamic on the provider slug —
             * e.g. `tejcart_order_tracking_easypost_mode`.
             *
             * @param string $value Resolved mode, either `live` or `test`.
             * @param string $slug  Provider slug (easypost, shippo, aftership, ...).
             */
            $filtered = (string) apply_filters( 'tejcart_order_tracking_' . $slug . '_mode', $value, $slug );
            $value    = self::MODE_TEST === strtolower( $filtered ) ? self::MODE_TEST : self::MODE_LIVE;
        }

        return $value;
    }

    /**
     * Build the "Live" / "Sandbox" / "Not connected" status pill for the
     * provider settings card. Showing a "Live" badge on an empty
     * credentials form is misleading (the integration isn't actually
     * talking to anything), so we gate the live/sandbox label on
     * is_configured() and fall back to a muted "Not connected" state —
     * the industry-standard pattern used by Stripe Connect and similar.
     *
     * Returned shape: `[ 'class' => 'tejcart-ot-mode-badge--<state>',
     * 'label' => '<localised label>' ]`. The CSS modifier is the
     * "<state>" half (live, test, inactive) without the base class so
     * callers can compose it however they like.
     *
     * @return array{class:string,label:string}
     */
    public static function provider_status_badge( bool $is_configured, string $mode ): array {
        if ( ! $is_configured ) {
            return array(
                'class' => 'tejcart-ot-mode-badge--inactive',
                'label' => __( 'Not connected', 'tejcart' ),
            );
        }
        $mode = self::MODE_TEST === $mode ? self::MODE_TEST : self::MODE_LIVE;
        return array(
            'class' => 'tejcart-ot-mode-badge--' . $mode,
            'label' => self::MODE_TEST === $mode
                ? __( 'Sandbox', 'tejcart' )
                : __( 'Live', 'tejcart' ),
        );
    }

    /**
     * Resolve the active mode WITHOUT applying the
     * `tejcart_order_tracking_<slug>_mode` filter. Used by the core
     * filter listener to short-circuit the recursion that would otherwise
     * occur when resolve_mode() applies the same filter that called us.
     */
    private static function resolve_mode_unfiltered( string $slug ): string {
        if ( isset( self::$mode_overrides[ $slug ] ) ) {
            return self::MODE_TEST === self::$mode_overrides[ $slug ] ? self::MODE_TEST : self::MODE_LIVE;
        }
        $const = 'TEJCART_ORDER_TRACKING_' . strtoupper( $slug ) . '_MODE';
        if ( defined( $const ) ) {
            $value = strtolower( (string) constant( $const ) );
            return self::MODE_TEST === $value ? self::MODE_TEST : self::MODE_LIVE;
        }
        $value = strtolower( (string) self::get( $slug . '_mode', self::MODE_LIVE ) );
        return self::MODE_TEST === $value ? self::MODE_TEST : self::MODE_LIVE;
    }

    /**
     * Run a callback with a one-shot mode override in place — used by the
     * AJAX test endpoint to exercise sandbox credentials without mutating
     * the saved option. The override is always cleared, even on exception.
     */
    public static function with_mode_override( string $slug, string $mode, callable $fn ): mixed {
        $mode = self::MODE_TEST === $mode ? self::MODE_TEST : self::MODE_LIVE;
        $had  = array_key_exists( $slug, self::$mode_overrides );
        $prev = self::$mode_overrides[ $slug ] ?? null;
        self::$mode_overrides[ $slug ] = $mode;
        try {
            return $fn();
        } finally {
            if ( $had ) {
                self::$mode_overrides[ $slug ] = (string) $prev;
            } else {
                unset( self::$mode_overrides[ $slug ] );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_all(): array {
        $stored = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        return array_merge( self::defaults(), self::decrypt_secrets( $stored ) );
    }

    public static function get( string $key, mixed $default = '' ): mixed {
        $all = self::get_all();
        return $all[ $key ] ?? $default;
    }

    public function maybe_save(): void {
        if ( ! isset( $_POST['tejcart_ot_settings_submit'] ) ) {
            return;
        }
        if ( ! Capability::current_user_can_manage() ) {
            return;
        }
        check_admin_referer( self::NONCE );

        $raw = wp_unslash( $_POST );

        // Start from the currently-stored values so saving from one
        // provider's sub-tab does NOT reset fields belonging to the
        // other providers or to the Settings sub-tab.
        $current = self::get_all();

        $string_fields = array(
            'easypost_api_key',
            'easypost_webhook_secret',
            'easypost_test_api_key',
            'easypost_test_webhook_secret',
            'shippo_api_key',
            'shippo_test_api_key',
            'aftership_api_key',
            'aftership_webhook_secret',
            'aftership_test_api_key',
            'aftership_test_webhook_secret',
        );
        foreach ( $string_fields as $field ) {
            if ( array_key_exists( $field, $raw ) ) {
                $current[ $field ] = sanitize_text_field( (string) $raw[ $field ] );
            }
        }

        // Per-provider sandbox/live mode. Sanitise to the known enum so a
        // tampered form post can't poison the routing.
        foreach ( array( 'easypost_mode', 'shippo_mode', 'aftership_mode' ) as $mode_field ) {
            if ( array_key_exists( $mode_field, $raw ) ) {
                $current[ $mode_field ] = self::MODE_TEST === strtolower( (string) $raw[ $mode_field ] )
                    ? self::MODE_TEST
                    : self::MODE_LIVE;
            }
        }

        if ( array_key_exists( 'retention_days', $raw ) ) {
            $current['retention_days'] = max( 0, (int) $raw['retention_days'] );
        }
        if ( array_key_exists( 'delivered_retention_days', $raw ) ) {
            $current['delivered_retention_days'] = max( 0, (int) $raw['delivered_retention_days'] );
        }
        if ( array_key_exists( 'poll_interval', $raw ) ) {
            $current['poll_interval'] = sanitize_key( (string) $raw['poll_interval'] );
        }
        // Checkboxes only appear in $_POST when checked — gate on the
        // hidden marker that the Settings form always submits so we
        // don't flip "enable_polling" off from a provider form.
        if ( isset( $raw['tejcart_ot_section'] ) && 'settings' === $raw['tejcart_ot_section'] ) {
            $current['enable_polling'] = isset( $raw['enable_polling'] ) ? 1 : 0;
        }

        // Display section — five toggles + a heading. Saved together so
        // each checkbox reads as "off when absent" without bleeding into
        // the Settings / provider forms.
        if ( isset( $raw['tejcart_ot_section'] ) && 'display' === $raw['tejcart_ot_section'] ) {
            $current['display_customer_view'] = isset( $raw['display_customer_view'] ) ? 1 : 0;
            $current['display_thankyou']      = isset( $raw['display_thankyou'] )      ? 1 : 0;
            $current['display_events']        = isset( $raw['display_events'] )        ? 1 : 0;
            $current['display_orders_column'] = isset( $raw['display_orders_column'] ) ? 1 : 0;
            $current['display_emails']        = isset( $raw['display_emails'] )        ? 1 : 0;
            $current['display_heading']       = sanitize_text_field( (string) ( $raw['display_heading'] ?? '' ) );
        }

        update_option( self::OPTION_KEY, self::encrypt_secrets( $current ) );

        add_settings_error(
            'tejcart_order_tracking',
            'saved',
            __( 'Settings saved.', 'tejcart' ),
            'success'
        );
    }

    /**
     * Body renderer for the Order Tracking tab. Dispatches to the
     * appropriate sub-section based on the current `?section=` value.
     *
     * @param string $section Selected sub-section key (or '').
     */
    public function render_tab( string $section = '', string $tab = '' ): void {
        if ( ! Capability::current_user_can_manage() ) {
            esc_html_e( 'You do not have permission to access this page.', 'tejcart' );
            return;
        }
        settings_errors( 'tejcart_order_tracking' );

        echo '<div class="tejcart-ot-settings-content">';
        if ( 'tools' === $section ) {
            $this->render_tools_section();
        } elseif ( 'display' === $section ) {
            $this->render_display_section();
        } elseif ( 'providers' === $section ) {
            // Legacy combined section — if the admin_init redirect didn't
            // fire (e.g. headers already sent), fall back to rendering the
            // first provider so the page is never blank.
            $first = $this->first_provider_slug();
            if ( null !== $first ) {
                $this->render_provider_section( $first );
            } else {
                $this->render_no_providers_section();
            }
        } elseif ( 0 === strpos( $section, self::SECTION_PREFIX ) ) {
            $slug = substr( $section, strlen( self::SECTION_PREFIX ) );
            $this->render_provider_section( $slug );
        } else {
            $this->render_settings_section();
        }
        echo '</div>';
    }

    private function render_settings_section(): void {
        $settings = self::get_all();
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h3><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'Polling & retention', 'tejcart' ); ?></h3>
            </div>
            <form method="post" action="" class="tejcart-card-body">
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="tejcart_ot_section" value="settings" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="enable_polling"><?php esc_html_e( 'Enable carrier polling', 'tejcart' ); ?></label></th>
                        <td>
                            <label class="tejcart-toggle">
                                <input type="checkbox" id="enable_polling" name="enable_polling" value="1" <?php checked( ! empty( $settings['enable_polling'] ) ); ?> />
                                <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                            </label>
                            <p class="description"><?php esc_html_e( 'Periodically refresh in-flight shipments via configured providers.', 'tejcart' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="poll_interval"><?php esc_html_e( 'Polling interval', 'tejcart' ); ?></label></th>
                        <td>
                            <select name="poll_interval" id="poll_interval">
                                <?php foreach ( $this->poll_intervals() as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['poll_interval'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="retention_days"><?php esc_html_e( 'Soft-delete retention (days)', 'tejcart' ); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="retention_days" name="retention_days" value="<?php echo esc_attr( (string) $settings['retention_days'] ); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Soft-deleted shipments older than this are hard-purged by the daily retention cron. 0 disables retention.', 'tejcart' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="delivered_retention_days"><?php esc_html_e( 'Delivered retention (days)', 'tejcart' ); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="delivered_retention_days" name="delivered_retention_days" value="<?php echo esc_attr( (string) $settings['delivered_retention_days'] ); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Optional: delivered shipments older than this are also hard-purged. 0 keeps them forever.', 'tejcart' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="tejcart_ot_settings_submit" class="button button-primary">
                        <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_provider_section( string $slug ): void {
        $provider = $this->providers->get( $slug );
        if ( null === $provider ) {
            $this->render_no_providers_section();
            return;
        }

        $settings  = self::get_all();
        $fields    = $this->provider_field_map( $slug );
        $label     = $provider->label();
        $constants = $this->provider_constants( $slug, $fields );
        $mode      = self::resolve_mode( $slug );
        $mode_locked = defined( 'TEJCART_ORDER_TRACKING_' . strtoupper( $slug ) . '_MODE' );
        $badge       = self::provider_status_badge( $provider->is_configured(), $mode );
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h3>
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php
                    /* translators: %s: provider label (e.g. EasyPost). */
                    echo esc_html( sprintf( __( '%s credentials', 'tejcart' ), $label ) );
                    ?>
                    <span class="tejcart-ot-mode-badge <?php echo esc_attr( $badge['class'] ); ?>" data-tejcart-ot-mode-badge="<?php echo esc_attr( $slug ); ?>">
                        <?php echo esc_html( $badge['label'] ); ?>
                    </span>
                </h3>
            </div>
            <form method="post" action="" class="tejcart-card-body">
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="tejcart_ot_section" value="<?php echo esc_attr( self::SECTION_PREFIX . $slug ); ?>" />
                <p class="description tejcart-ot-credentials-help">
                    <?php
                    if ( empty( $constants ) ) {
                        esc_html_e( 'API keys and webhook secrets are encrypted at rest.', 'tejcart' );
                    } else {
                        echo esc_html(
                            sprintf(
                                /* translators: %s: comma-separated list of PHP constant names. */
                                __( 'API keys and webhook secrets are encrypted at rest. Constants defined in wp-config.php (%s) always override the values below — useful for staging / CI lockdown.', 'tejcart' ),
                                implode( ', ', $constants )
                            )
                        );
                    }
                    ?>
                </p>

                <?php if ( null !== $fields ) : ?>
                    <table class="form-table tejcart-ot-providers tejcart-ot-providers--mode" role="presentation">
                        <tr class="tejcart-ot-provider-row tejcart-ot-provider-row--mode">
                            <th scope="row">
                                <label><?php esc_html_e( 'Mode', 'tejcart' ); ?></label>
                            </th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text">
                                        <?php esc_html_e( 'Mode', 'tejcart' ); ?>
                                    </legend>
                                    <label>
                                        <input type="radio"
                                               name="<?php echo esc_attr( $slug . '_mode' ); ?>"
                                               value="<?php echo esc_attr( self::MODE_LIVE ); ?>"
                                               <?php checked( $mode, self::MODE_LIVE ); ?>
                                               <?php disabled( $mode_locked ); ?> />
                                        <?php esc_html_e( 'Live — talk to the production API with real shipments.', 'tejcart' ); ?>
                                    </label><br/>
                                    <label>
                                        <input type="radio"
                                               name="<?php echo esc_attr( $slug . '_mode' ); ?>"
                                               value="<?php echo esc_attr( self::MODE_TEST ); ?>"
                                               <?php checked( $mode, self::MODE_TEST ); ?>
                                               <?php disabled( $mode_locked ); ?> />
                                        <?php esc_html_e( 'Sandbox — use test credentials for end-to-end QA.', 'tejcart' ); ?>
                                    </label>
                                    <?php if ( $mode_locked ) : ?>
                                        <p class="description">
                                            <?php
                                            echo esc_html(
                                                sprintf(
                                                    /* translators: %s: PHP constant name. */
                                                    __( 'Mode is pinned by the %s constant in wp-config.php and cannot be changed from this screen.', 'tejcart' ),
                                                    'TEJCART_ORDER_TRACKING_' . strtoupper( $slug ) . '_MODE'
                                                )
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </fieldset>
                            </td>
                        </tr>
                    </table>
                <?php endif; ?>

                <table class="form-table tejcart-ot-providers" role="presentation">
                    <?php
                    if ( null === $fields ) {
                        ?>
                        <tr class="tejcart-ot-provider-row">
                            <td colspan="2">
                                <p class="description">
                                    <?php esc_html_e( 'This provider does not expose configurable credentials here. Configure it via the `tejcart_order_tracking_providers` filter or its own constants.', 'tejcart' ); ?>
                                </p>
                            </td>
                        </tr>
                        <?php
                    } else {
                        $this->render_provider_row(
                            $slug,
                            self::MODE_LIVE,
                            $fields['api_key'],
                            (string) ( $settings[ $fields['api_key'] ] ?? '' ),
                            $fields['webhook_secret'],
                            null === $fields['webhook_secret'] ? null : (string) ( $settings[ $fields['webhook_secret'] ] ?? '' )
                        );
                        if ( null !== $fields['test_api_key'] ) {
                            $this->render_provider_row(
                                $slug,
                                self::MODE_TEST,
                                $fields['test_api_key'],
                                (string) ( $settings[ $fields['test_api_key'] ] ?? '' ),
                                $fields['test_webhook_secret'],
                                null === $fields['test_webhook_secret'] ? null : (string) ( $settings[ $fields['test_webhook_secret'] ] ?? '' )
                            );
                        }
                    }
                    ?>
                </table>

                <p class="submit">
                    <button type="submit" name="tejcart_ot_settings_submit" class="button button-primary">
                        <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
        $this->print_test_button_script();
    }

    /**
     * Build the list of `TEJCART_ORDER_TRACKING_*` constant names that
     * override the given provider's stored credentials, so the help text
     * accurately reflects the provider being configured (not the
     * hard-coded EasyPost example we used to ship for every tab).
     *
     * @param array{api_key:string,webhook_secret:?string}|null $fields
     * @return string[]
     */
    private function provider_constants( string $slug, ?array $fields ): array {
        if ( null === $fields ) {
            return array();
        }
        $base   = 'TEJCART_ORDER_TRACKING_' . strtoupper( $slug );
        $names  = array( $base . '_API_KEY', $base . '_TEST_API_KEY', $base . '_MODE' );
        if ( ! empty( $fields['webhook_secret'] ) ) {
            $names[] = $base . '_WEBHOOK_SECRET';
        }
        if ( ! empty( $fields['test_webhook_secret'] ) ) {
            $names[] = $base . '_TEST_WEBHOOK_SECRET';
        }
        return $names;
    }

    private function render_no_providers_section(): void {
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-body">
                <p><?php esc_html_e( 'No carrier providers are registered.', 'tejcart' ); ?></p>
            </div>
        </div>
        <?php
    }

    private function render_display_section(): void {
        $settings = self::get_all();
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h3><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Display', 'tejcart' ); ?></h3>
            </div>
            <form method="post" action="" class="tejcart-card-body">
                <?php wp_nonce_field( self::NONCE ); ?>
                <input type="hidden" name="tejcart_ot_section" value="display" />

                <p class="description" style="margin-top:0;">
                    <?php esc_html_e( 'Control where the tracking panel appears for customers and operators. Defaults show tracking on every order surface.', 'tejcart' ); ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Customer surfaces', 'tejcart' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="display_customer_view" value="1" <?php checked( ! empty( $settings['display_customer_view'] ) ); ?> />
                                <?php esc_html_e( 'Show tracking on the My Account view-order page', 'tejcart' ); ?>
                            </label><br/>
                            <label>
                                <input type="checkbox" name="display_thankyou" value="1" <?php checked( ! empty( $settings['display_thankyou'] ) ); ?> />
                                <?php esc_html_e( 'Show tracking on the post-checkout thank-you page', 'tejcart' ); ?>
                            </label><br/>
                            <label>
                                <input type="checkbox" name="display_events" value="1" <?php checked( ! empty( $settings['display_events'] ) ); ?> />
                                <?php esc_html_e( 'Include the per-shipment event timeline', 'tejcart' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Customer emails', 'tejcart' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="display_emails" value="1" <?php checked( ! empty( $settings['display_emails'] ) ); ?> />
                                <?php esc_html_e( 'Append the "Track your shipment" block to customer order emails', 'tejcart' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Order processing / completed / on-hold / invoice / buyer-receipt emails. Standalone shipped & delivered emails are unaffected.', 'tejcart' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin Orders list', 'tejcart' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="display_orders_column" value="1" <?php checked( ! empty( $settings['display_orders_column'] ) ); ?> />
                                <?php esc_html_e( 'Show the "Tracking" column on the wp-admin Orders list', 'tejcart' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="display_heading"><?php esc_html_e( 'Panel heading', 'tejcart' ); ?></label></th>
                        <td>
                            <input type="text" id="display_heading" name="display_heading" class="regular-text"
                                   value="<?php echo esc_attr( (string) ( $settings['display_heading'] ?? '' ) ); ?>"
                                   placeholder="<?php esc_attr_e( 'Tracking', 'tejcart' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Optional override for the heading shown above the customer tracking panel. Leave blank to use the default.', 'tejcart' ); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="tejcart_ot_settings_submit" class="button button-primary">
                        <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_tools_section(): void {
        if ( null === $this->tools ) {
            ?>
            <div class="tejcart-card">
                <div class="tejcart-card-body">
                    <p><?php esc_html_e( 'Bulk-import tools are unavailable.', 'tejcart' ); ?></p>
                </div>
            </div>
            <?php
            return;
        }
        $this->tools->render_section();
    }

    /**
     * Map a provider slug to the option keys that hold its credentials.
     * 3rd-party providers can declare their own fields by hooking
     * `tejcart_order_tracking_provider_fields_<slug>` and returning an
     * array shaped `[ 'api_key' => 'foo_api_key', 'webhook_secret' => 'foo_webhook_secret'|null ]`.
     *
     * @return array{api_key:string,webhook_secret:?string}|null
     */
    private function provider_field_map( string $slug ): ?array {
        $builtin = array(
            'easypost'  => array(
                'api_key'             => 'easypost_api_key',
                'webhook_secret'      => 'easypost_webhook_secret',
                'test_api_key'        => 'easypost_test_api_key',
                'test_webhook_secret' => 'easypost_test_webhook_secret',
                'mode'                => 'easypost_mode',
            ),
            'shippo'    => array(
                'api_key'             => 'shippo_api_key',
                'webhook_secret'      => null,
                'test_api_key'        => 'shippo_test_api_key',
                'test_webhook_secret' => null,
                'mode'                => 'shippo_mode',
            ),
            'aftership' => array(
                'api_key'             => 'aftership_api_key',
                'webhook_secret'      => 'aftership_webhook_secret',
                'test_api_key'        => 'aftership_test_api_key',
                'test_webhook_secret' => 'aftership_test_webhook_secret',
                'mode'                => 'aftership_mode',
            ),
        );
        /**
         * Filter the credential fields for a single provider.
         *
         * @param array{api_key:string,webhook_secret:?string,test_api_key?:?string,test_webhook_secret?:?string,mode?:?string}|null $fields
         */
        $fields = apply_filters(
            'tejcart_order_tracking_provider_fields_' . $slug,
            $builtin[ $slug ] ?? null
        );
        if ( ! is_array( $fields ) || empty( $fields['api_key'] ) ) {
            return null;
        }
        $optional = static function ( $value ): ?string {
            return is_string( $value ) && '' !== $value ? $value : null;
        };
        return array(
            'api_key'             => (string) $fields['api_key'],
            'webhook_secret'      => $optional( $fields['webhook_secret'] ?? null ),
            'test_api_key'        => $optional( $fields['test_api_key'] ?? null ),
            'test_webhook_secret' => $optional( $fields['test_webhook_secret'] ?? null ),
            'mode'                => $optional( $fields['mode'] ?? null ),
        );
    }

    private function render_provider_row(
        string $slug,
        string $mode,
        string $api_key_field,
        string $api_key_value,
        ?string $webhook_field = null,
        ?string $webhook_value = null
    ): void {
        $is_test     = self::MODE_TEST === $mode;
        $heading     = $is_test
            ? __( 'Sandbox credentials', 'tejcart' )
            : __( 'Live credentials', 'tejcart' );
        $api_key_lbl = $is_test
            ? __( 'Test API key', 'tejcart' )
            : __( 'Live API key', 'tejcart' );
        $hook_lbl    = $is_test
            ? __( 'Test webhook secret', 'tejcart' )
            : __( 'Live webhook secret', 'tejcart' );
        $placeholder = $is_test
            ? __( 'Paste your test/sandbox API key', 'tejcart' )
            : __( 'Paste your live API key', 'tejcart' );
        $result_key  = $slug . '-' . $mode;
        ?>
        <tr class="tejcart-ot-provider-row tejcart-ot-provider-row--heading">
            <th scope="row" colspan="2">
                <h4 class="tejcart-ot-credentials-heading"><?php echo esc_html( $heading ); ?></h4>
            </th>
        </tr>
        <tr class="tejcart-ot-provider-row">
            <th scope="row"><label for="<?php echo esc_attr( $api_key_field ); ?>"><?php echo esc_html( $api_key_lbl ); ?></label></th>
            <td>
                <div class="tejcart-ot-field-row">
                    <input type="password" autocomplete="new-password" class="regular-text tejcart-ot-secret"
                           id="<?php echo esc_attr( $api_key_field ); ?>"
                           name="<?php echo esc_attr( $api_key_field ); ?>"
                           value="<?php echo esc_attr( $api_key_value ); ?>"
                           placeholder="<?php echo esc_attr( $placeholder ); ?>" />
                    <button type="button" class="button"
                            data-tejcart-ot-test="<?php echo esc_attr( $slug ); ?>"
                            data-tejcart-ot-test-mode="<?php echo esc_attr( $mode ); ?>">
                        <?php echo $is_test
                            ? esc_html__( 'Test sandbox connection', 'tejcart' )
                            : esc_html__( 'Test live connection', 'tejcart' );
                        ?>
                    </button>
                    <span class="description tejcart-ot-test-result" data-tejcart-ot-test-result="<?php echo esc_attr( $result_key ); ?>"></span>
                </div>
            </td>
        </tr>
        <?php if ( null !== $webhook_field ) : ?>
            <tr class="tejcart-ot-provider-row tejcart-ot-provider-row--webhook">
                <th scope="row"><label for="<?php echo esc_attr( $webhook_field ); ?>"><?php echo esc_html( $hook_lbl ); ?></label></th>
                <td>
                    <div class="tejcart-ot-field-row">
                        <input type="password" autocomplete="new-password" class="regular-text tejcart-ot-secret"
                               id="<?php echo esc_attr( $webhook_field ); ?>"
                               name="<?php echo esc_attr( $webhook_field ); ?>"
                               value="<?php echo esc_attr( (string) $webhook_value ); ?>" />
                    </div>
                </td>
            </tr>
        <?php endif; ?>
        <?php
    }

    private function print_test_button_script(): void {
        $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
        wp_enqueue_script(
            'tejcart-order-tracking-settings',
            plugins_url( 'assets/admin/order-tracking-settings' . $suffix . '.js', TEJCART_ORDER_TRACKING_FILE ),
            array(),
            TEJCART_ORDER_TRACKING_VERSION,
            true
        );
        wp_localize_script(
            'tejcart-order-tracking-settings',
            'tejcartOrderTrackingSettings',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'tejcart_nonce' ),
                'i18n'    => array(
                    'ok'            => __( 'OK', 'tejcart' ),
                    'failed'        => __( 'Failed', 'tejcart' ),
                    'network_error' => __( 'Network error', 'tejcart' ),
                ),
            )
        );
    }

    public function ajax_test_provider(): void {
        check_ajax_referer( 'tejcart_nonce', 'nonce' );
        if ( ! Capability::current_user_can_manage() ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tejcart' ) ), 403 );
        }
        $slug = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';
        $provider = $this->providers->get( $slug );
        if ( null === $provider ) {
            wp_send_json_error( array( 'message' => __( 'Unknown provider.', 'tejcart' ) ), 404 );
        }
        // Mode is optional — when absent we fall through to whatever the
        // merchant has saved, preserving the pre-1.x behaviour for callers
        // that pre-date the sandbox toggle.
        $requested_mode = isset( $_POST['mode'] )
            ? strtolower( sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) )
            : '';
        $mode = self::MODE_TEST === $requested_mode ? self::MODE_TEST : self::MODE_LIVE;

        $invoke = function () use ( $provider ) {
            if ( ! $provider->is_configured() ) {
                return new \WP_Error( 'not_configured', __( 'Provider has no API key configured.', 'tejcart' ) );
            }
            // Hit the API with a syntactically-valid but never-existing
            // tracking number — we want to trip auth, not create a real
            // tracker. A 4xx with `provider_4xx` means auth worked.
            return $provider->fetch_status( 'TEJCART_TEST_TRACKING_NUMBER_DO_NOT_USE', 'usps' );
        };

        $result = '' === $requested_mode
            ? $invoke()
            : self::with_mode_override( $slug, $mode, $invoke );

        if ( is_wp_error( $result ) ) {
            $code = $result->get_error_code();
            if ( 'not_configured' === $code ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
            }
            if ( 'provider_4xx' === $code ) {
                wp_send_json_success(
                    array(
                        'message' => self::MODE_TEST === $mode
                            ? __( 'Authenticated OK (sandbox).', 'tejcart' )
                            : __( 'Authenticated OK (live).', 'tejcart' ),
                        'mode'    => $mode,
                    )
                );
            }
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'mode' => $mode ), 502 );
        }
        wp_send_json_success(
            array(
                'message' => self::MODE_TEST === $mode
                    ? __( 'OK (sandbox).', 'tejcart' )
                    : __( 'OK (live).', 'tejcart' ),
                'mode'    => $mode,
            )
        );
    }

    /**
     * @param array<string, mixed> $clean
     * @return array<string, mixed>
     */
    private static function encrypt_secrets( array $clean ): array {
        $secret_keys = array(
            'easypost_api_key', 'easypost_webhook_secret',
            'easypost_test_api_key', 'easypost_test_webhook_secret',
            'shippo_api_key', 'shippo_test_api_key',
            'aftership_api_key', 'aftership_webhook_secret',
            'aftership_test_api_key', 'aftership_test_webhook_secret',
        );
        if ( ! class_exists( '\\TejCart\\Security\\Crypto' ) ) {
            return $clean;
        }
        foreach ( $secret_keys as $key ) {
            if ( isset( $clean[ $key ] ) && '' !== $clean[ $key ] ) {
                $encrypted = \TejCart\Security\Crypto::encrypt( (string) $clean[ $key ] );
                if ( is_string( $encrypted ) && '' !== $encrypted ) {
                    $clean[ $key ] = '__tejcart_enc__' . $encrypted;
                }
            }
        }
        return $clean;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private static function decrypt_secrets( array $stored ): array {
        if ( ! class_exists( '\\TejCart\\Security\\Crypto' ) ) {
            return $stored;
        }
        foreach ( $stored as $key => $value ) {
            if ( is_string( $value ) && 0 === strpos( $value, '__tejcart_enc__' ) ) {
                $payload   = substr( $value, strlen( '__tejcart_enc__' ) );
                $decrypted = \TejCart\Security\Crypto::decrypt( $payload );
                $stored[ $key ] = is_string( $decrypted ) ? $decrypted : '';
            }
        }
        return $stored;
    }

    /**
     * @return array<string, string>
     */
    private function poll_intervals(): array {
        return array(
            '900'   => __( 'Every 15 minutes', 'tejcart' ),
            '1800'  => __( 'Every 30 minutes', 'tejcart' ),
            'hourly' => __( 'Hourly (recommended)', 'tejcart' ),
            'twicedaily' => __( 'Twice daily', 'tejcart' ),
            'daily' => __( 'Daily', 'tejcart' ),
        );
    }

    // ---- Filter bridges ----------------------------------------------

    public function filter_easypost_api_key( $value ) {
        return $this->resolve_credential( 'easypost', 'api_key', $value );
    }
    public function filter_easypost_webhook_secret( $value ) {
        return $this->resolve_credential( 'easypost', 'webhook_secret', $value );
    }
    public function filter_shippo_api_key( $value ) {
        return $this->resolve_credential( 'shippo', 'api_key', $value );
    }
    public function filter_aftership_api_key( $value ) {
        return $this->resolve_credential( 'aftership', 'api_key', $value );
    }
    public function filter_aftership_webhook_secret( $value ) {
        return $this->resolve_credential( 'aftership', 'webhook_secret', $value );
    }

    /**
     * Resolve a credential ("api_key" or "webhook_secret") for a provider,
     * honouring the active mode and the constant-overrides-everything
     * precedence the rest of the module follows.
     *
     * Order: matching mode-specific constant → upstream filter preempt
     * (non-empty $value) → stored option for the active mode.
     */
    private function resolve_credential( string $slug, string $type, mixed $value ): string {
        $mode    = self::resolve_mode( $slug );
        $is_test = self::MODE_TEST === $mode;

        $upper = strtoupper( $slug );
        // The live API-key constant has shipped since 1.0 without an _LIVE_
        // infix, so we keep that name. Test credentials use the explicit
        // _TEST_ infix to match user expectations.
        $live_const_suffix = 'api_key' === $type ? '_API_KEY' : '_WEBHOOK_SECRET';
        $test_const_suffix = 'api_key' === $type ? '_TEST_API_KEY' : '_TEST_WEBHOOK_SECRET';
        $const_name        = 'TEJCART_ORDER_TRACKING_' . $upper . ( $is_test ? $test_const_suffix : $live_const_suffix );

        if ( defined( $const_name ) ) {
            return (string) constant( $const_name );
        }

        if ( '' !== (string) $value ) {
            return (string) $value;
        }

        $option_key = $slug . ( $is_test ? '_test_' : '_' ) . $type;
        return (string) self::get( $option_key, '' );
    }

    /**
     * Public filter for the active mode of a provider. Exposed so 3rd-party
     * extensions can render "Sandbox mode" badges in their UIs without
     * duplicating the constant + option resolution logic.
     */
    public function filter_provider_mode( $value, string $slug = '' ): string {
        $slug = (string) $slug;
        if ( '' === $slug ) {
            return self::MODE_LIVE === (string) $value ? self::MODE_LIVE : self::MODE_TEST;
        }
        // Must NOT call resolve_mode() here: this method is registered as
        // a listener on the `tejcart_order_tracking_<slug>_mode` filter
        // that resolve_mode() applies, so recursing through resolve_mode()
        // would re-enter the filter and exhaust memory.
        return self::resolve_mode_unfiltered( $slug );
    }
    public function filter_retention_days( $value ) {
        $stored = (int) self::get( 'retention_days', Retention_Cron::DEFAULT_SOFT_DELETE_DAYS );
        return $stored;
    }
    public function filter_delivered_retention_days( $value ) {
        return (int) self::get( 'delivered_retention_days', 0 );
    }
    public function filter_poll_interval( $value ) {
        if ( ! self::get( 'enable_polling', 1 ) ) {
            return 0;
        }
        $raw = (string) self::get( 'poll_interval', 'hourly' );
        return match ( $raw ) {
            '900'        => 900,
            '1800'       => 1800,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            default      => HOUR_IN_SECONDS,
        };
    }
}
