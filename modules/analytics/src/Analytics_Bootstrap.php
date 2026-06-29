<?php
/**
 * Bootstraps the bundled analytics drivers and their admin UI.
 *
 * @package TejCart\Analytics
 */

declare(strict_types=1);

namespace TejCart\Analytics;

use TejCart\Analytics\Drivers\GA4_Driver;
use TejCart\Analytics\Drivers\Klaviyo_Driver;
use TejCart\Analytics\Drivers\Mailchimp_Driver;
use TejCart\Analytics\Drivers\Meta_CAPI_Driver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wires the bundled analytics drivers (GA4, Meta CAPI, Klaviyo, Mailchimp)
 * onto the `tejcart_analytics_drivers` filter consumed by
 * {@see Analytics_Dispatcher}, registers a TejCart → Analytics submenu
 * page, and starts the dispatcher itself.
 *
 * Third-party plugins can register additional drivers by hooking the
 * filter; this class only adds entries, never replaces them.
 */
class Analytics_Bootstrap {
    public const PAGE_SLUG = 'tejcart-analytics';

    /**
     * Map of driver IDs that ship with core. Order matters — this is the
     * order shown in the admin page.
     *
     * @var array<string, class-string<Abstract_Analytics_Driver>>
     */
    public const BUNDLED = array(
        'ga4'        => GA4_Driver::class,
        'meta_capi'  => Meta_CAPI_Driver::class,
        'klaviyo'    => Klaviyo_Driver::class,
        'mailchimp'  => Mailchimp_Driver::class,
    );

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Hook registration. Idempotent.
     */
    public function init(): void {
        add_filter( 'tejcart_analytics_drivers', array( $this, 'register_bundled_drivers' ), 10, 1 );

        // Wake the dispatcher so it subscribes to the underlying domain
        // events. Calling instance()->init() registers the listeners; on
        // subsequent boots the call is a no-op because add_action is
        // deduped by callable identity.
        Analytics_Dispatcher::instance()->init();

        // GDPR exporter + per-driver eraser dispatch.
        ( new Privacy() )->register();

        // Frontend view tracker — fires `tejcart_view_product` on single
        // product page render so the dispatcher can pick it up.
        ( new View_Tracker() )->register();

        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
            add_action( 'admin_init', array( $this, 'handle_admin_save' ) );
            // Opt the Analytics page into core's tejcart-admin.css bundle
            // (page header, card, pill tokens) so the screen renders with
            // the same visual chrome as the rest of TejCart admin without
            // duplicating the --nc-* design tokens in this module's CSS.
            add_filter( 'tejcart_admin_page_hooks', array( $this, 'register_admin_page_hooks' ) );
        }
    }

    /**
     * Append the Analytics submenu hook to the list of pages that
     * receive core's `tejcart-admin.css` enqueue. The hook suffix
     * produced by `add_submenu_page( 'tejcart', … )` is
     * `tejcart_page_tejcart-analytics`.
     *
     * @param array<int,string> $hooks
     * @return array<int,string>
     */
    public function register_admin_page_hooks( $hooks ): array {
        $hooks   = is_array( $hooks ) ? $hooks : array();
        $hooks[] = 'tejcart_page_' . self::PAGE_SLUG;
        return $hooks;
    }

    /**
     * Register bundled drivers via the filter.
     *
     * @param array<string, string> $drivers
     * @return array<string, string>
     */
    public function register_bundled_drivers( $drivers ): array {
        if ( ! is_array( $drivers ) ) {
            $drivers = array();
        }
        foreach ( self::BUNDLED as $id => $class ) {
            if ( ! isset( $drivers[ $id ] ) ) {
                $drivers[ $id ] = $class;
            }
        }
        return $drivers;
    }

    /**
     * Register the admin submenu page.
     */
    public function register_menu(): void {
        // Sidebar slot is owned by TejCart core's reorder_submenu() hook
        // (admin_menu priority 9999) — see Menu::canonical_submenu_order().
        $hook = add_submenu_page(
            'tejcart',
            __( 'Analytics', 'tejcart' ),
            __( 'Analytics', 'tejcart' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_admin_page' )
        );
        if ( $hook ) {
            add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_admin_styles' ) );
        }
    }

    /**
     * Enqueue the analytics admin stylesheet on this page only. The
     * stylesheet defines the same `--nc-*` tokens as the core TejCart
     * admin design system, so the analytics page renders with the same
     * card / badge / divider treatments even when the core admin CSS
     * isn't loaded on this submenu.
     */
    public function enqueue_admin_styles(): void {
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        wp_enqueue_style(
            'tejcart-analytics-admin',
            plugins_url( 'assets/css/admin' . $suffix . '.css', TEJCART_ANALYTICS_FILE ),
            array(),
            TEJCART_ANALYTICS_VERSION
        );
    }

    /**
     * Process admin POSTs. Each driver has its own form + nonce, plus
     * a "reset health stats" maintenance action.
     */
    public function handle_admin_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['tejcart_analytics_reset_health'] ) ) {
            check_admin_referer( 'tejcart_analytics_reset_health' );
            delete_option( 'tejcart_analytics_counters' );
            delete_option( 'tejcart_analytics_last_error' );
            delete_option( 'tejcart_analytics_last_sent_at' );
            add_settings_error(
                'tejcart_analytics',
                'health_reset',
                __( 'Analytics health counters cleared.', 'tejcart' ),
                'success'
            );
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_POST['tejcart_analytics_save'] ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $driver_id = sanitize_key( wp_unslash( (string) $_POST['tejcart_analytics_save'] ) );
        if ( ! isset( self::BUNDLED[ $driver_id ] ) ) {
            return;
        }

        check_admin_referer( 'tejcart_analytics_save_' . $driver_id );

        $class  = self::BUNDLED[ $driver_id ];
        $driver = new $class();

        $settings = $driver->get_settings();
        foreach ( $class::credential_keys() as $field ) {
            $field_id = (string) ( $field['id'] ?? '' );
            if ( '' === $field_id ) {
                continue;
            }
            $type = (string) ( $field['type'] ?? 'text' );

            if ( 'checkbox' === $type ) {
                $settings[ $field_id ] = ! empty( $_POST[ $field_id ] ) ? 'yes' : 'no';
                continue;
            }

            $raw = isset( $_POST[ $field_id ] )
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field below
                ? wp_unslash( (string) $_POST[ $field_id ] )
                : '';

            if ( 'password' === $type && '' === $raw ) {
                continue;
            }

            $settings[ $field_id ] = sanitize_text_field( $raw );
        }

        $driver->save_settings( $settings );

        Analytics_Dispatcher::instance()->reset();

        add_settings_error(
            'tejcart_analytics',
            'saved',
            sprintf(
                /* translators: %s: driver title */
                __( '%s settings saved.', 'tejcart' ),
                $driver->get_title()
            ),
            'success'
        );
    }

    /**
     * Render the Analytics admin page.
     *
     * Each bundled driver lives on its own horizontal tab so its credential
     * form doesn't bleed into siblings, matching the Settings → Tax →
     * Providers UX. A dedicated `health` tab keeps the cross-driver
     * delivery counters and reset action available from a stable URL.
     * The active tab is resolved from the `provider` query arg, falling
     * back to the POST round-trip after a save, then to `health`.
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current = $this->resolve_current_tab();
        ?>
        <div class="wrap tejcart-admin-wrap tejcart-analytics">
            <?php
            \TejCart\Admin\Page_Header::list(
                __( 'Tracking & Pixels', 'tejcart' ),
                __( 'Connect TejCart to analytics and email-marketing platforms. Each driver runs out of process so a slow third party never blocks checkout. Failures are logged and retried by Action Scheduler.', 'tejcart' )
            );
            ?>

            <?php settings_errors( 'tejcart_analytics' ); ?>

            <?php $this->render_consent_notice(); ?>

            <?php $this->render_provider_tabs( $current ); ?>

            <?php if ( 'advanced' === $current && class_exists( '\\TejCart\\Analytics_Advanced\\Analytics_Advanced' ) ) : ?>
                <?php \TejCart\Analytics_Advanced\Analytics_Advanced::instance()->render_page(); ?>
            <?php elseif ( 'health' === $current ) : ?>
                <?php $this->render_health_panel(); ?>
            <?php else : ?>
                <?php $this->render_driver_card( $current ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Show a warning when no consent gate is active so merchants know
     * events fire for all visitors regardless of consent state.
     */
    private function render_consent_notice(): void {
        $consent_enabled = 'yes' === get_option( 'tejcart_require_cookie_consent', 'no' );
        $has_active      = ! empty( Analytics_Dispatcher::instance()->get_active_drivers() );

        if ( ! $has_active ) {
            return;
        }

        if ( $consent_enabled ) {
            ?>
            <div class="notice notice-info inline" style="margin:12px 0">
                <p>
                    <?php esc_html_e( 'Cookie consent gate is active. Events only fire for visitors who have granted marketing consent.', 'tejcart' ); ?>
                </p>
            </div>
            <?php
        } else {
            ?>
            <div class="notice notice-warning inline" style="margin:12px 0">
                <p>
                    <strong><?php esc_html_e( 'No consent gate active.', 'tejcart' ); ?></strong>
                    <?php esc_html_e( 'Analytics events fire for all visitors, including those who have not consented to marketing tracking. Enable cookie consent under TejCart > Settings > Privacy, or integrate a third-party consent plugin.', 'tejcart' ); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Resolve which tab should be shown.
     *
     * Precedence: explicit GET `provider` arg → POST round-trip after a
     * driver save → `health`. Unknown values fall through to the next
     * source.
     */
    private function resolve_current_tab(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['provider'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $candidate = sanitize_key( wp_unslash( (string) $_GET['provider'] ) );
            if ( 'health' === $candidate || 'advanced' === $candidate || isset( self::BUNDLED[ $candidate ] ) ) {
                return $candidate;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['tejcart_analytics_save'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $candidate = sanitize_key( wp_unslash( (string) $_POST['tejcart_analytics_save'] ) );
            if ( isset( self::BUNDLED[ $candidate ] ) ) {
                return $candidate;
            }
        }

        return 'health';
    }

    /**
     * Render the horizontal tab strip — one tab per bundled driver plus
     * a Health tab. Tabs link back to the analytics page with a
     * `provider=<id>` query arg so URLs are bookmarkable.
     */
    private function render_provider_tabs( string $current ): void {
        $base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        ?>
        <nav class="tejcart-settings-subnav tejcart-analytics-tabs"
             aria-label="<?php esc_attr_e( 'Analytics providers', 'tejcart' ); ?>">
            <a
                href="<?php echo esc_url( add_query_arg( 'provider', 'health', $base ) ); ?>"
                class="tejcart-settings-subnav-item<?php echo 'health' === $current ? ' is-active' : ''; ?>"
                <?php echo 'health' === $current ? 'aria-current="page"' : ''; ?>
            >
                <?php esc_html_e( 'Health', 'tejcart' ); ?>
            </a>
            <?php foreach ( self::BUNDLED as $id => $class ) :
                /** @var Abstract_Analytics_Driver $driver */
                $driver     = new $class();
                $url        = add_query_arg( 'provider', $id, $base );
                $is_current = ( $id === $current );
                $is_active  = $driver->is_enabled() && $driver->is_configured();
                ?>
                <a
                    href="<?php echo esc_url( $url ); ?>"
                    class="tejcart-settings-subnav-item<?php echo $is_current ? ' is-active' : ''; ?>"
                    <?php echo $is_current ? 'aria-current="page"' : ''; ?>
                >
                    <?php echo esc_html( $driver->get_title() ); ?>
                    <?php if ( $is_active ) : ?>
                        <span class="tejcart-analytics-tab-dot"
                              aria-label="<?php esc_attr_e( 'Currently active driver', 'tejcart' ); ?>"></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if ( class_exists( '\\TejCart\\Analytics_Advanced\\Analytics_Advanced' ) ) : ?>
                <a
                    href="<?php echo esc_url( add_query_arg( 'provider', 'advanced', $base ) ); ?>"
                    class="tejcart-settings-subnav-item<?php echo 'advanced' === $current ? ' is-active' : ''; ?>"
                    <?php echo 'advanced' === $current ? 'aria-current="page"' : ''; ?>
                >
                    <?php esc_html_e( 'Advanced', 'tejcart' ); ?>
                </a>
            <?php endif; ?>
        </nav>
        <?php
    }

    /**
     * Render the settings card for a single driver tab.
     */
    private function render_driver_card( string $id ): void {
        if ( ! isset( self::BUNDLED[ $id ] ) ) {
            return;
        }
        $class = self::BUNDLED[ $id ];

        /** @var Abstract_Analytics_Driver $driver */
        $driver        = new $class();
        $settings      = $driver->get_settings();
        $is_enabled    = $driver->is_enabled();
        $is_configured = $driver->is_configured();
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h2>
                    <?php echo esc_html( $driver->get_title() ); ?>
                    <?php if ( $is_enabled && $is_configured ) : ?>
                        <span class="tejcart-pill tejcart-pill--success">
                            <?php esc_html_e( 'Active', 'tejcart' ); ?>
                        </span>
                    <?php elseif ( $is_enabled ) : ?>
                        <span class="tejcart-pill tejcart-pill--error">
                            <?php esc_html_e( 'Enabled but missing credentials', 'tejcart' ); ?>
                        </span>
                    <?php elseif ( $is_configured ) : ?>
                        <span class="tejcart-pill tejcart-pill--warning">
                            <?php esc_html_e( 'Configured', 'tejcart' ); ?>
                        </span>
                    <?php endif; ?>
                </h2>
            </div>
            <div class="tejcart-card-body">
                <form method="post">
                    <?php wp_nonce_field( 'tejcart_analytics_save_' . $id ); ?>
                    <input type="hidden" name="tejcart_analytics_save" value="<?php echo esc_attr( $id ); ?>" />

                    <table class="form-table" role="presentation">
                        <?php foreach ( $class::credential_keys() as $field ) : ?>
                            <?php $this->render_field( $field, $settings ); ?>
                        <?php endforeach; ?>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render the per-driver health panel: last successful send, last
     * error, and rolling success/fail counters per event. Surfaces the
     * data {@see Analytics_Dispatcher::record_driver_health()} writes
     * so operators can answer "did GA4 fire today?" without log greps.
     */
    private function render_health_panel(): void {
        $last_sent = (array) get_option( 'tejcart_analytics_last_sent_at', array() );
        $last_err  = (array) get_option( 'tejcart_analytics_last_error',   array() );
        $counters  = (array) get_option( 'tejcart_analytics_counters',     array() );

        $counter_month = (string) ( $counters['__month'] ?? '' );

        // Pivot the flat bucket map into per-driver totals.
        $by_driver = array();
        foreach ( $counters as $bucket => $count ) {
            if ( str_starts_with( (string) $bucket, '__' ) ) {
                continue;
            }
            $parts = explode( ':', (string) $bucket );
            if ( count( $parts ) !== 3 ) {
                continue;
            }
            list( $driver_id, $event, $status ) = $parts;
            $by_driver[ $driver_id ][ $status ] = (int) ( $by_driver[ $driver_id ][ $status ] ?? 0 ) + (int) $count;
            $by_driver[ $driver_id ]['events'][ $event ][ $status ] = (int) ( $by_driver[ $driver_id ]['events'][ $event ][ $status ] ?? 0 ) + (int) $count;
        }
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h2><?php esc_html_e( 'Health & observability', 'tejcart' ); ?></h2>
            </div>
            <div class="tejcart-card-body">
            <p class="description">
                <?php esc_html_e( 'Per-driver delivery counters and the most recent error/success timestamp. Counters reset automatically each calendar month, or manually via the button below.', 'tejcart' ); ?>
                <?php if ( '' !== $counter_month ) : ?>
                    <?php
                    printf(
                        /* translators: %s: month in YYYY-MM format */
                        esc_html__( 'Showing: %s.', 'tejcart' ),
                        esc_html( $counter_month )
                    );
                    ?>
                <?php endif; ?>
            </p>

            <table class="widefat striped tejcart-analytics-health">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Driver', 'tejcart' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Successes', 'tejcart' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Failures', 'tejcart' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Last sent (UTC)', 'tejcart' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Last error', 'tejcart' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( self::BUNDLED as $id => $class ) :
                    /** @var Abstract_Analytics_Driver $driver */
                    $driver  = new $class();
                    $ok      = (int) ( $by_driver[ $id ]['ok']   ?? 0 );
                    $fail    = (int) ( $by_driver[ $id ]['fail'] ?? 0 );
                    $sent    = (string) ( $last_sent[ $id ] ?? '' );
                    $err_row = is_array( $last_err[ $id ] ?? null ) ? $last_err[ $id ] : array();
                    $err     = (string) ( $err_row['error'] ?? '' );
                    $err_at  = (string) ( $err_row['time']  ?? '' );
                    $err_ev  = (string) ( $err_row['event'] ?? '' );

                    if ( ! $driver->is_enabled() ) {
                        $status_label = __( 'Disabled', 'tejcart' );
                        $pill_tone    = 'muted';
                    } elseif ( ! $driver->is_configured() ) {
                        $status_label = __( 'Missing credentials', 'tejcart' );
                        $pill_tone    = 'error';
                    } else {
                        $status_label = __( 'Active', 'tejcart' );
                        $pill_tone    = 'success';
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $driver->get_title() ); ?></strong></td>
                        <td>
                            <span class="tejcart-pill tejcart-pill--<?php echo esc_attr( $pill_tone ); ?>">
                                <?php echo esc_html( $status_label ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( number_format_i18n( $ok ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $fail ) ); ?></td>
                        <td><?php echo '' !== $sent ? esc_html( $sent ) : '—'; ?></td>
                        <td>
                            <?php if ( '' === $err ) : ?>
                                —
                            <?php else : ?>
                                <code><?php echo esc_html( '' !== $err_ev ? $err_ev . ': ' : '' ); ?><?php echo esc_html( substr( $err, 0, 160 ) ); ?></code>
                                <?php if ( '' !== $err_at ) : ?>
                                    <br /><small><?php echo esc_html( $err_at ); ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post" class="tejcart-analytics-reset-form">
                <?php wp_nonce_field( 'tejcart_analytics_reset_health' ); ?>
                <input type="hidden" name="tejcart_analytics_reset_health" value="1" />
                <button type="submit" class="button button-secondary">
                    <?php esc_html_e( 'Reset counters', 'tejcart' ); ?>
                </button>
            </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render one credential field row.
     */
    private function render_field( array $field, array $settings ): void {
        $id          = (string) ( $field['id'] ?? '' );
        $label       = (string) ( $field['label'] ?? $id );
        $type        = (string) ( $field['type'] ?? 'text' );
        $description = (string) ( $field['description'] ?? '' );
        $required    = ! empty( $field['required'] );

        $value = $settings[ $id ] ?? '';
        ?>
        <tr>
            <th scope="row">
                <label for="tejcart_analytics_<?php echo esc_attr( $id ); ?>">
                    <?php echo esc_html( $label ); ?>
                    <?php if ( $required ) : ?><span class="tejcart-analytics-required">*</span><?php endif; ?>
                </label>
            </th>
            <td>
                <?php if ( 'checkbox' === $type ) : ?>
                    <input type="checkbox" id="tejcart_analytics_<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $id ); ?>" value="1"
                           <?php checked( 'yes', $value ); ?> />
                <?php elseif ( 'password' === $type ) : ?>
                    <input type="password" id="tejcart_analytics_<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $id ); ?>" class="regular-text"
                           autocomplete="new-password"
                           placeholder="<?php echo '' !== (string) $value ? esc_attr__( '••••••••', 'tejcart' ) : ''; ?>" />
                    <?php if ( '' !== (string) $value ) : ?>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep the existing value.', 'tejcart' ); ?></p>
                    <?php endif; ?>
                <?php elseif ( 'select' === $type ) : ?>
                    <select id="tejcart_analytics_<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>">
                        <option value=""><?php esc_html_e( '— Select —', 'tejcart' ); ?></option>
                        <?php foreach ( (array) ( $field['options'] ?? array() ) as $opt_value => $opt_label ) : ?>
                            <option value="<?php echo esc_attr( (string) $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
                                <?php echo esc_html( (string) $opt_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="text" id="tejcart_analytics_<?php echo esc_attr( $id ); ?>"
                           name="<?php echo esc_attr( $id ); ?>" class="regular-text"
                           value="<?php echo esc_attr( (string) $value ); ?>" />
                <?php endif; ?>

                <?php if ( '' !== $description ) : ?>
                    <p class="description"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}
