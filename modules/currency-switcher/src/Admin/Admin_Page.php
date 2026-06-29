<?php
/**
 * Currency Switcher settings — rendered as a tab inside the unified TejCart
 * Settings page so the UI inherits core's sidebar / card / form-table design
 * tokens 1:1. Mirrors the integration pattern used by the bundled
 * `order-tracking` module ({@see \TejCart\Tier2\Order_Tracking\Settings}):
 *
 *  - `tejcart_settings_tabs`              — registers the "Currency" tab
 *  - `tejcart_settings_tab_groups`        — slots it into the "Selling" group
 *  - `tejcart_settings_subnav_items_<tab>` — declares the four sub-sections
 *  - `tejcart_settings_render_tab_<tab>`  — renders the body
 *
 * The four sub-sections borrow the WooCommerce Currency Switcher information
 * architecture (so merchants moving across feel at home) but are entirely
 * re-skinned in TejCart's `.nxc-*` design system:
 *
 *  - Buttons → `.nxc-btn`, `.nxc-btn--primary`, `.nxc-btn--icon`
 *  - Status badges → `.nxc-status .nxc-status--completed`
 *  - Cards / surface → `.tejcart-card`, `.tejcart-card-header`, `.tejcart-card-body`
 *  - Empty state → `.nxc-empty-state`
 *  - Tables → `wp-list-table widefat fixed striped` (matches `Modules_Page`)
 *  - Notices → WP-native `.notice.notice-info.inline` + `.notice.notice-warning.inline`
 *
 * `admin-list.css` only loads on a small allowlist of core admin pages; we
 * extend that allowlist via `tejcart_admin_list_page_hooks` so the
 * `.nxc-status`, `.nxc-btn--icon`, `.nxc-empty-state` and `.nxc-toolbar`
 * tokens render correctly on the Settings → Currency tab.
 *
 * The legacy `?page=tejcart_csw_settings[&tab=...]` URL still 302s to the
 * matching new sub-section so old bookmarks keep working.
 *
 * @package TejCart\Currency_Switcher\Admin
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Admin;

use TejCart\Currency_Switcher\Conversion\Price_Adjustment;
use TejCart\Currency_Switcher\Currency_Catalog;
use TejCart\Currency_Switcher\Currency_Config;
use TejCart\Currency_Switcher\Currency_Repository;
use TejCart\Currency_Switcher\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the "Currency" tab inside core's Settings page and handles
 * its POST submissions.
 *
 * Capability is filterable via `tejcart_csw_setting_cap` (default
 * `manage_options`).
 */
final class Admin_Page {
    /** Tab id used in `?page=tejcart-settings&tab=currency`. */
    public const TAB_ID = 'currency';

    /** Legacy submenu slug kept around so old bookmarks 302 cleanly. */
    public const LEGACY_MENU_SLUG = 'tejcart_csw_settings';

    /** Hook suffix emitted by core for the unified Settings page. */
    private const SETTINGS_HOOK = 'tejcart_page_tejcart-settings';

    /** Hidden field name posted with every section save. */
    private const SUBMIT_FIELD = 'tejcart_csw_settings_submit';

    /** Hidden field name carrying the active sub-section on save. */
    private const SECTION_FIELD = 'tejcart_csw_section';

    public function register(): void {
        add_action( 'admin_init',                                       array( $this, 'maybe_save' ) );
        add_action( 'admin_init',                                       array( $this, 'maybe_redirect_legacy_menu' ), 0 );
        add_filter( 'tejcart_settings_tabs',                            array( $this, 'register_tab' ) );
        add_filter( 'tejcart_settings_tab_groups',                      array( $this, 'register_tab_group' ), 10, 2 );
        add_filter( 'tejcart_settings_subnav_items_' . self::TAB_ID,    array( $this, 'register_subnav_items' ) );
        add_action( 'tejcart_settings_render_tab_' . self::TAB_ID,      array( $this, 'render_tab' ), 10, 2 );

        // Pull `admin-list.css` onto the Settings page so the `.nxc-*` design
        // system (status pills, icon buttons, empty state, toolbar) is fully
        // styled inside the Currency tab. The filter is keyed by hook suffix,
        // so it's a no-op for tabs that don't use those classes.
        add_filter( 'tejcart_admin_list_page_hooks', array( $this, 'register_list_page_hook' ) );
    }

    /**
     * Append the Settings page hook to the admin-list.css allowlist so the
     * `.nxc-status` / `.nxc-btn--icon` / `.nxc-empty-state` / `.nxc-toolbar`
     * classes render with their canonical TejCart styles inside the
     * Currency tab.
     *
     * @param string[] $hooks
     * @return string[]
     */
    public function register_list_page_hook( array $hooks ): array {
        if ( ! in_array( self::SETTINGS_HOOK, $hooks, true ) ) {
            $hooks[] = self::SETTINGS_HOOK;
        }
        return $hooks;
    }

    /**
     * Build the canonical settings URL for the Currency tab.
     */
    public static function settings_url( string $section = '' ): string {
        $url = admin_url( 'admin.php?page=tejcart-settings&tab=' . self::TAB_ID );
        if ( '' !== $section ) {
            $url = add_query_arg( 'section', $section, $url );
        }
        return $url;
    }

    /**
     * Redirect requests to the old standalone submenu URL to the new
     * Settings tab. Runs at admin_init priority 0 so we get in before
     * any rendering happens.
     */
    public function maybe_redirect_legacy_menu(): void {
        if ( ! is_admin() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( self::LEGACY_MENU_SLUG !== $page ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $legacy_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
        $section    = $this->legacy_tab_to_section( $legacy_tab );
        wp_safe_redirect( self::settings_url( $section ) );
        exit;
    }

    /**
     * Map a legacy `?tab=` value to the new `?section=` key, so deep links
     * (e.g. "Price Adjustment") survive the menu reshuffle.
     */
    private function legacy_tab_to_section( string $legacy_tab ): string {
        return match ( $legacy_tab ) {
            'checkout_options'  => 'checkout',
            'display_options'   => 'display',
            'price_adjustment'  => 'pricing',
            default             => '',
        };
    }

    /**
     * Register the "Currency" tab definition.
     *
     * @param array<string,array<string,mixed>> $tabs
     * @return array<string,array<string,mixed>>
     */
    public function register_tab( array $tabs ): array {
        $tabs[ self::TAB_ID ] = array(
            'id'       => self::TAB_ID,
            'label'    => __( 'Currency', 'tejcart' ),
            'icon'     => 'dashicons-money-alt',
            'desc'     => __( 'Multi-currency display, FX rates, gateway routing and converted-price rules.', 'tejcart' ),
            'sections' => array(),
        );
        return $tabs;
    }

    /**
     * Slot the tab into the "Selling" group, alongside Payments / Shipping / Tax.
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
     * Sub-nav: four sub-sections, default is the "Currencies" list.
     *
     * @return array<string,string>
     */
    public function register_subnav_items(): array {
        return array(
            ''         => __( 'Set Up Currencies', 'tejcart' ),
            'checkout' => __( 'Choose Payment Methods', 'tejcart' ),
            'display'  => __( 'Display Options', 'tejcart' ),
            'pricing'  => __( 'Price Adjustment', 'tejcart' ),
        );
    }

    /**
     * Tab body dispatcher. Called by core's Settings page from inside the
     * `.tejcart-settings-content` wrapper, so we just render the cards.
     */
    public function render_tab( string $section = '', string $tab = '' ): void {
        $cap = (string) apply_filters( 'tejcart_csw_setting_cap', 'manage_options' );
        if ( ! current_user_can( $cap ) ) {
            esc_html_e( 'You do not have permission to access this page.', 'tejcart' );
            return;
        }
        settings_errors( 'tejcart_csw_settings' );

        switch ( $section ) {
            case 'checkout':
                $this->render_checkout_section();
                break;
            case 'display':
                $this->render_display_section();
                break;
            case 'pricing':
                $this->render_pricing_section();
                break;
            default:
                $this->render_currencies_section();
        }
    }

    /**
     * Handle the section POST. Mirrors order-tracking's pattern: read the
     * hidden `tejcart_csw_section` discriminator and only mutate options
     * that belong to that sub-section, then PRG-redirect back.
     */
    public function maybe_save(): void {
        if ( empty( $_POST[ self::SUBMIT_FIELD ] ) ) {
            return;
        }
        $cap = (string) apply_filters( 'tejcart_csw_setting_cap', 'manage_options' );
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'Forbidden.', 'tejcart' ), 403 );
        }
        check_admin_referer( Options::NONCE_ACTION, 'tejcart_csw_nonce' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $section = isset( $_POST[ self::SECTION_FIELD ] )
            ? sanitize_key( wp_unslash( (string) $_POST[ self::SECTION_FIELD ] ) )
            : '';

        switch ( $section ) {
            case 'checkout':
                $this->save_checkout_section();
                break;
            case 'display':
                $this->save_display_section();
                break;
            case 'pricing':
                $this->save_pricing_section();
                break;
            default:
                $this->save_currencies_section();
                $section = '';
        }

        add_settings_error(
            'tejcart_csw_settings',
            'saved',
            __( 'Settings saved.', 'tejcart' ),
            'success'
        );

        $redirect = self::settings_url( $section );
        $redirect = add_query_arg( 'settings-updated', '1', $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    // ------------------------------------------------------------------
    // Section savers
    // ------------------------------------------------------------------

    private function save_currencies_section(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // Each row field is sanitized below in the foreach via sanitize_text_field / casts.
        $rows = isset( $_POST['currencies'] ) && is_array( $_POST['currencies'] )
            ? wp_unslash( $_POST['currencies'] )
            : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $clean = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $clean[] = Currency_Config::from_array(
                array(
                    'code'         => sanitize_text_field( (string) ( $row['code'] ?? '' ) ),
                    'rate'         => (float) ( $row['rate'] ?? 1.0 ),
                    'rate_type'    => sanitize_text_field( (string) ( $row['rate_type'] ?? Options::RATE_TYPE_AUTO ) ),
                    'fee'          => (float) ( $row['fee'] ?? 0.0 ),
                    'fee_type'     => sanitize_text_field( (string) ( $row['fee_type'] ?? Options::FEE_FIXED ) ),
                    'currency_pos' => sanitize_text_field( (string) ( $row['currency_pos'] ?? Options::POS_LEFT ) ),
                    'thousand_sep' => substr( sanitize_text_field( (string) ( $row['thousand_sep'] ?? ',' ) ), 0, 1 ),
                    'decimal_sep'  => substr( sanitize_text_field( (string) ( $row['decimal_sep'] ?? '.' ) ), 0, 1 ),
                    'num_decimals' => (int) ( $row['num_decimals'] ?? 2 ),
                )
            );
        }
        ( new Currency_Repository() )->replace( $clean );
    }

    private function save_checkout_section(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // Each entry sanitized below via sanitize_key/sanitize_text_field in the loop.
        $diff = isset( $_POST['checkout_diff_currency'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['checkout_diff_currency'] ) ) ? 'yes' : 'no';
        update_option( Options::CHECKOUT_DIFF_CURRENCY, $diff, false );

        $map_in = isset( $_POST['checkout_gateways'] ) && is_array( $_POST['checkout_gateways'] )
            ? wp_unslash( $_POST['checkout_gateways'] )
            : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $clean = array();
        foreach ( $map_in as $code => $gateways ) {
            $code = strtoupper( sanitize_text_field( (string) $code ) );
            if ( ! preg_match( '/^[A-Z]{3}$/', $code ) || ! is_array( $gateways ) ) {
                continue;
            }
            $clean[ $code ] = array_values( array_filter( array_map( 'sanitize_key', $gateways ) ) );
            if ( empty( $clean[ $code ] ) ) {
                // Empty selection means "all gateways" — drop the entry so
                // it never confuses Gateway_Filter.
                unset( $clean[ $code ] );
            }
        }
        update_option( Options::CHECKOUT_GATEWAYS, $clean, false );
    }

    /**
     * Saves only the two fields the simplified Display UI surfaces. All
     * the other Display options (sidebar design / position, geolocation,
     * color tokens, …) are still read by frontend code paths, so we
     * deliberately leave their stored values alone — merchants who set
     * them via wp-cli, an importer, or an older release keep their
     * customisation.
     */
    private function save_display_section(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        update_option( Options::PRODUCT_PAGE_ENABLE, isset( $_POST['product_page_enable'] ) ? 1 : 0, false );

        $pages = isset( $_POST['sidebar_pages'] ) && is_array( $_POST['sidebar_pages'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['sidebar_pages'] ) )
            : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        update_option( Options::SIDEBAR_PAGES, $pages, false );
    }

    private function save_pricing_section(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // Each row field is cast/sanitized in the loop below.
        $rows = isset( $_POST['price_adjustment'] ) && is_array( $_POST['price_adjustment'] )
            ? wp_unslash( $_POST['price_adjustment'] )
            : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $ranges = Price_Adjustment::ranges();
        $clean  = array();
        foreach ( $ranges as $idx => $range ) {
            $row      = $rows[ $idx ] ?? array();
            $rounding = is_array( $row ) && isset( $row['rounding'] ) ? (int) $row['rounding'] : 0;
            $charm    = is_array( $row ) && isset( $row['charm'] ) ? (float) $row['charm'] : 0.0;

            if ( ! in_array( $rounding, $range['allowed_rounding'], true ) ) {
                $rounding = 0;
            }
            if ( ! in_array( $charm, $range['allowed_charm'], true ) ) {
                $charm = 0.0;
            }

            $clean[ $idx ] = array(
                'enabled'  => is_array( $row ) && ! empty( $row['enabled'] ),
                'rounding' => $rounding,
                'charm'    => $charm,
            );
        }
        update_option( Options::PRICE_ADJUSTMENT, $clean, false );
    }

    // ------------------------------------------------------------------
    // Section renderers — every section is a single <form> wrapped in
    // a `.tejcart-card`, matching the order-tracking module 1:1.
    // ------------------------------------------------------------------

    /**
     * Open the standard card + form for a section. Always emits the nonce,
     * action, and the section discriminator so {@see maybe_save()} routes
     * the submit back to the correct save handler.
     */
    private function open_section_form( string $section, string $title, string $icon, string $description = '' ): void {
        ?>
        <div class="tejcart-card tejcart-csw-section">
            <?php if ( '' !== $title ) : ?>
                <div class="tejcart-card-header">
                    <h3>
                        <span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
                        <?php echo esc_html( $title ); ?>
                    </h3>
                </div>
            <?php endif; ?>
            <form method="post" action="" class="tejcart-card-body tejcart-csw-form">
                <?php wp_nonce_field( Options::NONCE_ACTION, 'tejcart_csw_nonce' ); ?>
                <input type="hidden" name="<?php echo esc_attr( self::SECTION_FIELD ); ?>" value="<?php echo esc_attr( $section ); ?>" />
                <?php if ( '' !== $description ) : ?>
                    <p class="description tejcart-csw-section__intro"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
        <?php
    }

    private function close_section_form(): void {
        ?>
                <div class="tejcart-csw-form-footer">
                    <button type="submit" name="<?php echo esc_attr( self::SUBMIT_FIELD ); ?>" value="1" class="nxc-btn nxc-btn--primary">
                        <?php esc_html_e( 'Save changes', 'tejcart' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Reusable "Default" status pill — matches the `.nxc-status` token used
     * by Modules_Page so the visual vocabulary is shared across the admin.
     */
    private function render_default_badge(): void {
        ?>
        <span class="nxc-status nxc-status--completed tejcart-csw-default-pill">
            <span class="nxc-status__dot" aria-hidden="true"></span>
            <span class="nxc-status__label"><?php esc_html_e( 'Default', 'tejcart' ); ?></span>
        </span>
        <?php
    }

    private function render_currencies_section(): void {
        $repo       = new Currency_Repository();
        $all        = $repo->all();
        $base       = $repo->base_currency();
        $last       = (int) get_option( Options::LAST_RATE_UPDATE, 0 );
        $catalog    = Currency_Catalog::all();
        $base_entry = $catalog[ $base ] ?? array( 'name' => $base, 'symbol' => $base );
        $base_name  = (string) $base_entry['name'];
        $base_meta  = sprintf( '%s — %s', $base_entry['symbol'], $base );
        // Compact label used in the summary strap headline ("Name (symbol)").
        $base_label = isset( $catalog[ $base ] )
            ? sprintf( '%s (%s)', $catalog[ $base ]['name'], $catalog[ $base ]['symbol'] )
            : $base;

        $rate_status = $last > 0
            ? sprintf(
                /* translators: %s: human-readable diff e.g. "5 minutes". */
                __( 'Exchange rates auto-refresh every hour — last updated %s ago.', 'tejcart' ),
                human_time_diff( $last )
            )
            : __( 'Exchange rates auto-refresh every hour — pending first refresh.', 'tejcart' );

        $change_url = admin_url( 'admin.php?page=tejcart-settings&tab=general' );

        $this->open_section_form( '', '', '', '' );
        ?>
        <div class="tejcart-csw-summary">
            <div class="tejcart-csw-summary__main">
                <p class="tejcart-csw-summary__title">
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: 1: base currency label, 2: base currency code, 3: change link URL. */
                            __( 'Your default currency is <strong>%1$s &mdash; %2$s</strong>. <a href="%3$s">Change</a>', 'tejcart' ),
                            esc_html( $base_label ),
                            esc_html( $base ),
                            esc_url( $change_url )
                        ),
                        array( 'strong' => array(), 'a' => array( 'href' => array() ) )
                    );
                    ?>
                </p>
                <p class="tejcart-csw-summary__meta"><?php echo esc_html( $rate_status ); ?></p>
            </div>
            <div class="tejcart-csw-summary__actions">
                <button type="button" class="nxc-btn" data-csw-action="add-currency">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e( 'Add Currency', 'tejcart' ); ?>
                </button>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped tejcart-csw-currency-table">
            <thead>
                <tr>
                    <th scope="col" class="column-currency"><?php esc_html_e( 'Currency', 'tejcart' ); ?></th>
                    <th scope="col" class="column-rate"><?php esc_html_e( 'Rate', 'tejcart' ); ?></th>
                    <th scope="col" class="column-action"><?php esc_html_e( 'Actions', 'tejcart' ); ?></th>
                </tr>
            </thead>
            <tbody data-csw-rows>
                <tr class="tejcart-csw-row tejcart-csw-row--base">
                    <td class="column-currency">
                        <span class="tejcart-csw-currency-name"><?php echo esc_html( $base_name ); ?></span>
                        <span class="nxc-sku"><?php echo esc_html( $base_meta ); ?></span>
                        <?php $this->render_default_badge(); ?>
                    </td>
                    <td class="column-rate">
                        <span class="tejcart-csw-base-rate">1.000000</span>
                    </td>
                    <td class="column-action">
                        <span class="nxc-muted"><?php esc_html_e( 'Locked', 'tejcart' ); ?></span>
                    </td>
                </tr>
                <?php $i = 0; foreach ( $all as $cfg ) : $this->render_currency_row( $cfg, (string) $i, $catalog ); $i++; endforeach; ?>
            </tbody>
        </table>

        <template data-csw-row-template>
            <?php $this->render_currency_row_template( $catalog ); ?>
        </template>

        <?php
        $this->close_section_form();
        // The modal contains a nested <form method="dialog">. HTML5 parsing
        // ignores a nested <form> start tag but treats its </form> as closing
        // the outer form, which would push the Save button out of the form
        // and silently break submission. Render it as a sibling of the
        // outer form to keep the dialog's inner form parsing intact.
        $this->render_currency_modal();
    }

    /**
     * Render one editable currency row. `$index_token` is either a number
     * (existing rows) or a `new-N` token used by the JS-cloned rows; PHP
     * doesn't care about the index since the save handler iterates by value.
     *
     * @param array<string, array{name:string, symbol:string}> $catalog
     */
    private function render_currency_row( Currency_Config $cfg, string $index_token, array $catalog ): void {
        $row_id = 'currencies[' . $index_token . ']';
        ?>
        <tr class="tejcart-csw-row" data-csw-row data-csw-code="<?php echo esc_attr( $cfg->code ); ?>">
            <td class="column-currency">
                <select name="<?php echo esc_attr( $row_id ); ?>[code]" class="tejcart-csw-code-select" data-csw-field="code" required>
                    <?php foreach ( $catalog as $code => $entry ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $cfg->code, $code ); ?>>
                            <?php echo esc_html( sprintf( '%s (%s) — %s', $entry['name'], $entry['symbol'], $code ) ); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if ( ! isset( $catalog[ $cfg->code ] ) ) : ?>
                        <option value="<?php echo esc_attr( $cfg->code ); ?>" selected><?php echo esc_html( $cfg->code ); ?></option>
                    <?php endif; ?>
                </select>
            </td>
            <td class="column-rate">
                <div class="tejcart-csw-rate-group">
                    <input type="number" step="0.000001" min="0" class="tejcart-csw-rate-input"
                           name="<?php echo esc_attr( $row_id ); ?>[rate]"
                           value="<?php echo esc_attr( (string) $cfg->rate ); ?>"
                           data-csw-field="rate" required />
                    <select name="<?php echo esc_attr( $row_id ); ?>[rate_type]" class="tejcart-csw-rate-type" data-csw-field="rate_type">
                        <option value="Auto" <?php selected( $cfg->rate_type, 'Auto' ); ?>>Auto</option>
                        <option value="Fixed" <?php selected( $cfg->rate_type, 'Fixed' ); ?>>Fixed</option>
                    </select>
                </div>
            </td>
            <td class="column-action">
                <div class="tejcart-csw-row-actions">
                    <button type="button" class="nxc-btn nxc-btn--icon" data-csw-action="open-settings" aria-label="<?php esc_attr_e( 'Advanced settings', 'tejcart' ); ?>">
                        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="nxc-btn nxc-btn--icon" data-csw-action="refresh-rate" aria-label="<?php esc_attr_e( 'Refresh rate', 'tejcart' ); ?>">
                        <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="nxc-btn nxc-btn--icon tejcart-csw-icon-danger" data-csw-action="delete-row" aria-label="<?php esc_attr_e( 'Delete', 'tejcart' ); ?>">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    </button>
                </div>
            </td>
            <input type="hidden" name="<?php echo esc_attr( $row_id ); ?>[fee]"          value="<?php echo esc_attr( (string) $cfg->fee ); ?>"       data-csw-field="fee" />
            <input type="hidden" name="<?php echo esc_attr( $row_id ); ?>[fee_type]"     value="<?php echo esc_attr( $cfg->fee_type ); ?>"           data-csw-field="fee_type" />
            <input type="hidden" name="<?php echo esc_attr( $row_id ); ?>[currency_pos]" value="<?php echo esc_attr( $cfg->currency_pos ); ?>"       data-csw-field="currency_pos" />
            <input type="hidden" name="<?php echo esc_attr( $row_id ); ?>[thousand_sep]" value="<?php echo esc_attr( $cfg->thousand_sep ); ?>"       data-csw-field="thousand_sep" />
            <input type="hidden" name="<?php echo esc_attr( $row_id ); ?>[decimal_sep]"  value="<?php echo esc_attr( $cfg->decimal_sep ); ?>"        data-csw-field="decimal_sep" />
            <input type="hidden" name="<?php echo esc_attr( $row_id ); ?>[num_decimals]" value="<?php echo esc_attr( (string) $cfg->num_decimals ); ?>" data-csw-field="num_decimals" />
        </tr>
        <?php
    }

    /**
     * Hidden template row cloned by the JS "Add Currency" handler. The
     * `__INDEX__` token gets rewritten at append-time so the cloned row's
     * name attributes don't collide.
     *
     * @param array<string, array{name:string, symbol:string}> $catalog
     */
    private function render_currency_row_template( array $catalog ): void {
        $blank = Currency_Config::from_array(
            array(
                'code'      => array_key_first( $catalog ) ?? 'USD',
                'rate'      => 1.0,
                'rate_type' => Options::RATE_TYPE_AUTO,
            )
        );
        $this->render_currency_row( $blank, '__INDEX__', $catalog );
    }

    /**
     * Hidden modal markup. Lives at the bottom of the form so the JS can
     * swap field values per-row, then write them back to the row's hidden
     * inputs when the merchant clicks Apply.
     */
    private function render_currency_modal(): void {
        ?>
        <dialog class="tejcart-csw-modal" data-csw-modal aria-labelledby="tejcart-csw-modal-title">
            <form method="dialog" class="tejcart-csw-modal__form">
                <header class="tejcart-csw-modal__head">
                    <div class="tejcart-csw-modal__heading">
                        <h3 id="tejcart-csw-modal-title" class="tejcart-csw-modal__title">
                            <?php esc_html_e( 'Currency settings', 'tejcart' ); ?>
                        </h3>
                        <span class="nxc-sku" data-csw-modal-code></span>
                    </div>
                    <button type="button" class="nxc-btn nxc-btn--icon" data-csw-action="close-modal" aria-label="<?php esc_attr_e( 'Close', 'tejcart' ); ?>">
                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    </button>
                </header>
                <div class="tejcart-csw-modal__body">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="tejcart-csw-modal-fee"><?php esc_html_e( 'Exchange fee', 'tejcart' ); ?></label></th>
                            <td>
                                <div class="tejcart-csw-modal__inline">
                                    <input type="number" step="0.0001" min="0" id="tejcart-csw-modal-fee" data-csw-modal-field="fee" />
                                    <select data-csw-modal-field="fee_type">
                                        <option value="fixed"><?php esc_html_e( 'Fixed amount', 'tejcart' ); ?></option>
                                        <option value="percentage"><?php esc_html_e( 'Percent', 'tejcart' ); ?></option>
                                    </select>
                                </div>
                                <p class="description"><?php esc_html_e( 'Added on top of the FX rate to cover card / acquirer costs.', 'tejcart' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tejcart-csw-modal-pos"><?php esc_html_e( 'Symbol position', 'tejcart' ); ?></label></th>
                            <td>
                                <select id="tejcart-csw-modal-pos" data-csw-modal-field="currency_pos">
                                    <option value="left"><?php esc_html_e( 'Left ($99.99)', 'tejcart' ); ?></option>
                                    <option value="right"><?php esc_html_e( 'Right (99.99$)', 'tejcart' ); ?></option>
                                    <option value="left_space"><?php esc_html_e( 'Left with space ($ 99.99)', 'tejcart' ); ?></option>
                                    <option value="right_space"><?php esc_html_e( 'Right with space (99.99 $)', 'tejcart' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tejcart-csw-modal-thousand"><?php esc_html_e( 'Thousand separator', 'tejcart' ); ?></label></th>
                            <td><input type="text" maxlength="1" id="tejcart-csw-modal-thousand" data-csw-modal-field="thousand_sep" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tejcart-csw-modal-decimal"><?php esc_html_e( 'Decimal separator', 'tejcart' ); ?></label></th>
                            <td><input type="text" maxlength="1" id="tejcart-csw-modal-decimal" data-csw-modal-field="decimal_sep" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="tejcart-csw-modal-decimals"><?php esc_html_e( 'Decimals', 'tejcart' ); ?></label></th>
                            <td><input type="number" min="0" max="4" id="tejcart-csw-modal-decimals" data-csw-modal-field="num_decimals" class="small-text" /></td>
                        </tr>
                    </table>
                </div>
                <footer class="tejcart-csw-modal__foot">
                    <button type="button" class="nxc-btn" data-csw-action="close-modal"><?php esc_html_e( 'Cancel', 'tejcart' ); ?></button>
                    <button type="button" class="nxc-btn nxc-btn--primary" data-csw-action="apply-modal"><?php esc_html_e( 'Apply', 'tejcart' ); ?></button>
                </footer>
            </form>
        </dialog>
        <?php
    }

    private function render_checkout_section(): void {
        $diff      = (string) get_option( Options::CHECKOUT_DIFF_CURRENCY, 'yes' );
        $map       = (array) get_option( Options::CHECKOUT_GATEWAYS, array() );
        $repo      = new Currency_Repository();
        $gateways  = $this->registered_gateways();
        $catalog   = Currency_Catalog::all();
        $base      = $repo->base_currency();
        $currencies = array_keys( $repo->all() );
        array_unshift( $currencies, $base );
        $currencies = array_values( array_unique( $currencies ) );

        $this->open_section_form(
            'checkout',
            __( 'Choose Payment Methods', 'tejcart' ),
            'dashicons-yes-alt',
            __( 'Pick which payment methods are available for each currency at checkout. Leave a row empty to allow every registered gateway.', 'tejcart' )
        );
        ?>
        <table class="form-table tejcart-csw-form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Checkout in different currency', 'tejcart' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="checkout_diff_currency" value="1" <?php checked( 'yes', $diff ); ?> />
                        <?php esc_html_e( 'Allow customers to pay in their selected currency.', 'tejcart' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php if ( empty( $gateways ) ) : ?>
            <div class="nxc-empty-state tejcart-csw-empty">
                <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                <p>
                    <strong><?php esc_html_e( 'No payment gateways are registered yet.', 'tejcart' ); ?></strong><br/>
                    <?php esc_html_e( 'Enable a gateway under TejCart → Settings → Payments first, then return here to scope it to specific currencies.', 'tejcart' ); ?>
                </p>
            </div>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped tejcart-csw-gateways-table">
                <thead>
                    <tr>
                        <th scope="col" class="column-currency"><?php esc_html_e( 'Currency', 'tejcart' ); ?></th>
                        <th scope="col" class="column-methods"><?php esc_html_e( 'Payment Methods', 'tejcart' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $currencies as $code ) :
                    $entry    = $catalog[ $code ] ?? array( 'name' => $code, 'symbol' => $code );
                    $selected = isset( $map[ $code ] ) && is_array( $map[ $code ] )
                        ? array_map( 'strval', $map[ $code ] )
                        : array();
                    $all_allowed = empty( $selected );
                    ?>
                    <tr>
                        <td class="column-currency">
                            <span class="tejcart-csw-currency-name"><?php echo esc_html( $entry['name'] ); ?></span>
                            <span class="nxc-sku"><?php echo esc_html( sprintf( '%s — %s', $entry['symbol'], $code ) ); ?></span>
                            <?php if ( $code === $base ) : ?>
                                <?php $this->render_default_badge(); ?>
                            <?php endif; ?>
                        </td>
                        <td class="column-methods">
                            <fieldset class="tejcart-csw-gateways">
                                <legend class="screen-reader-text">
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: currency code */
                                            __( 'Allowed payment methods for %s', 'tejcart' ),
                                            $code
                                        )
                                    );
                                    ?>
                                </legend>
                                <?php foreach ( $gateways as $gateway_id => $gateway_label ) : ?>
                                    <label class="tejcart-csw-gateway">
                                        <input type="checkbox"
                                               name="checkout_gateways[<?php echo esc_attr( $code ); ?>][]"
                                               value="<?php echo esc_attr( $gateway_id ); ?>"
                                               <?php checked( in_array( $gateway_id, $selected, true ) ); ?> />
                                        <span><?php echo esc_html( $gateway_label ); ?></span>
                                    </label>
                                <?php endforeach; ?>
                                <?php if ( $all_allowed ) : ?>
                                    <p class="description tejcart-csw-gateways-hint">
                                        <?php esc_html_e( 'No selection — all payment methods are accepted.', 'tejcart' ); ?>
                                    </p>
                                <?php endif; ?>
                            </fieldset>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        $this->close_section_form();
    }

    private function render_display_section(): void {
        $page_enable = (int) get_option( Options::PRODUCT_PAGE_ENABLE, 1 );
        $pages       = (array) get_option( Options::SIDEBAR_PAGES, array( 'shop', 'cart', 'checkout' ) );

        $this->open_section_form(
            'display',
            __( 'Display Options', 'tejcart' ),
            'dashicons-visibility',
            __( 'Control where the currency switcher appears on the storefront.', 'tejcart' )
        );
        ?>
        <table class="form-table tejcart-csw-form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Product page dropdown', 'tejcart' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="product_page_enable" value="1" <?php checked( 1, $page_enable ); ?> />
                        <?php esc_html_e( 'Show the currency dropdown on single product pages.', 'tejcart' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Sidebar pages', 'tejcart' ); ?></th>
                <td>
                    <fieldset class="tejcart-csw-chip-group">
                        <legend class="screen-reader-text"><?php esc_html_e( 'Show sidebar on', 'tejcart' ); ?></legend>
                        <?php foreach ( $this->sidebar_page_labels() as $page => $label ) :
                            $checked = in_array( $page, $pages, true );
                            ?>
                            <label class="tejcart-csw-chip <?php echo $checked ? 'is-selected' : ''; ?>">
                                <input type="checkbox" name="sidebar_pages[]" value="<?php echo esc_attr( $page ); ?>" <?php checked( $checked ); ?> />
                                <span class="tejcart-csw-chip__label"><?php echo esc_html( $label ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description"><?php esc_html_e( 'Display the floating switcher sidebar on the selected pages.', 'tejcart' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
        $this->close_section_form();
    }

    private function render_pricing_section(): void {
        $stored = (array) get_option( Options::PRICE_ADJUSTMENT, array() );
        $ranges = Price_Adjustment::ranges();

        $this->open_section_form(
            'pricing',
            __( 'Price Adjustment for Converted Currencies', 'tejcart' ),
            'dashicons-tag',
            __( 'Adjust pricing for converted currencies so prices look attractive and structured after FX conversion.', 'tejcart' )
        );
        ?>
        <div class="notice notice-info inline tejcart-csw-notice">
            <p>
                <strong><?php esc_html_e( 'Why is this needed?', 'tejcart' ); ?></strong>
                <?php esc_html_e( 'After currency conversion, product prices can look odd (for example $100.00 → €91.34). These settings round prices neatly and apply charm-pricing endings for better sales psychology.', 'tejcart' ); ?>
            </p>
        </div>

        <div class="notice notice-warning inline tejcart-csw-notice">
            <p>
                <strong><?php esc_html_e( 'Important', 'tejcart' ); ?>:</strong>
                <?php
                echo wp_kses(
                    __( 'These adjustments <strong>only</strong> apply to product prices in converted currencies. They <strong>do not</strong> affect the default store currency.', 'tejcart' ),
                    array( 'strong' => array() )
                );
                ?>
            </p>
        </div>

        <table class="wp-list-table widefat fixed striped tejcart-csw-pricing-table" data-csw-pricing>
            <thead>
                <tr>
                    <th scope="col" class="column-enable"><?php esc_html_e( 'Enable', 'tejcart' ); ?></th>
                    <th scope="col" class="column-range"><?php esc_html_e( 'Price Range', 'tejcart' ); ?></th>
                    <th scope="col" class="column-rounding"><?php esc_html_e( 'Price Rounding', 'tejcart' ); ?></th>
                    <th scope="col" class="column-charm"><?php esc_html_e( 'Price Charm', 'tejcart' ); ?></th>
                    <th scope="col" class="column-test"><?php esc_html_e( 'Test Price', 'tejcart' ); ?></th>
                    <th scope="col" class="column-final"><?php esc_html_e( 'Final Price', 'tejcart' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $ranges as $idx => $range ) :
                $row      = isset( $stored[ $idx ] ) && is_array( $stored[ $idx ] ) ? $stored[ $idx ] : array();
                $enabled  = ! empty( $row['enabled'] );
                $rounding = $row['rounding'] ?? '';
                $charm    = $row['charm'] ?? '';
                $range_label = sprintf(
                    PHP_FLOAT_MAX === $range['max'] ? '%s+' : '%s — %s',
                    number_format( $range['min'], 2 ),
                    PHP_FLOAT_MAX === $range['max'] ? '' : number_format( $range['max'], 2 )
                );
                // Default test price is the midpoint, clamped for the open-ended top range.
                $test_default = PHP_FLOAT_MAX === $range['max']
                    ? $range['min'] + 1234.56
                    : ( $range['min'] + $range['max'] ) / 2;
                ?>
                <tr data-csw-pricing-row
                    data-csw-range-min="<?php echo esc_attr( (string) $range['min'] ); ?>"
                    data-csw-range-max="<?php echo esc_attr( PHP_FLOAT_MAX === $range['max'] ? '' : (string) $range['max'] ); ?>">
                    <td class="column-enable">
                        <input type="checkbox" name="price_adjustment[<?php echo (int) $idx; ?>][enabled]" value="1" <?php checked( $enabled ); ?> data-csw-pricing-field="enabled" />
                    </td>
                    <td class="column-range"><?php echo esc_html( $range_label ); ?></td>
                    <td class="column-rounding">
                        <select name="price_adjustment[<?php echo (int) $idx; ?>][rounding]" data-csw-pricing-field="rounding">
                            <option value=""><?php esc_html_e( '— none —', 'tejcart' ); ?></option>
                            <?php foreach ( $range['allowed_rounding'] as $value ) : ?>
                                <option value="<?php echo (int) $value; ?>" <?php selected( (string) $rounding, (string) $value ); ?>>
                                    <?php
                                    echo esc_html(
                                        99 === (int) $value
                                            ? __( 'Round to Nearest 99', 'tejcart' )
                                            : sprintf(
                                                /* translators: %d: rounding step */
                                                __( 'Round Up to Nearest %d', 'tejcart' ),
                                                (int) $value
                                            )
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="column-charm">
                        <select name="price_adjustment[<?php echo (int) $idx; ?>][charm]" data-csw-pricing-field="charm">
                            <?php foreach ( $range['allowed_charm'] as $value ) : ?>
                                <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $charm, (string) $value ); ?>>
                                    <?php
                                    echo 0.0 === $value
                                        ? esc_html__( 'No Charm', 'tejcart' )
                                        : esc_html( '.' . str_pad( (string) (int) round( $value * 100 ), 2, '0', STR_PAD_LEFT ) );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="column-test">
                        <input type="number" step="0.01" min="0" class="tejcart-csw-test-input"
                               value="<?php echo esc_attr( number_format( $test_default, 2, '.', '' ) ); ?>"
                               data-csw-pricing-field="test" />
                    </td>
                    <td class="column-final">
                        <span class="tejcart-csw-pricing-final" data-csw-pricing-final>&mdash;</span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $this->close_section_form();
    }

    /**
     * Localised labels for the "Display sidebar on" chip group.
     *
     * @return array<string, string>
     */
    private function sidebar_page_labels(): array {
        return array(
            'home'     => __( 'Home', 'tejcart' ),
            'shop'     => __( 'Shop', 'tejcart' ),
            'product'  => __( 'Product', 'tejcart' ),
            'category' => __( 'Category', 'tejcart' ),
            'cart'     => __( 'Cart', 'tejcart' ),
            'checkout' => __( 'Checkout', 'tejcart' ),
        );
    }

    /**
     * Resolve the list of payment gateways the merchant should be able to
     * scope per currency. We match the **customer-facing** checkout list
     * by going through `get_available_gateways()` rather than every
     * registered gateway — that filters out PayPal sub-methods whose
     * `is_available()` returns false (e.g. Apple/Google Pay when the
     * environment can't render them) and any gateway the merchant has
     * disabled in TejCart → Settings → Payments.
     *
     * Returns `[]` when core isn't booted yet (e.g. very early
     * admin_init) so callers can degrade gracefully to a "no gateways"
     * empty state instead of fatalling.
     *
     * Filterable via `tejcart_csw_admin_gateways` so addon plugins can
     * shape the merchant-facing list without touching the registry.
     *
     * @return array<string, string> Map of gateway id => display label.
     */
    private function registered_gateways(): array {
        if ( ! function_exists( 'tejcart' ) ) {
            return array();
        }
        $tejcart = tejcart();
        if ( null === $tejcart || ! method_exists( $tejcart, 'gateways' ) ) {
            return array();
        }
        $registry = $tejcart->gateways();
        if ( null === $registry ) {
            return array();
        }
        // Prefer the checkout-facing list; fall back to the full registry
        // only when the build predates `get_available_gateways()`.
        $gateways = method_exists( $registry, 'get_available_gateways' )
            ? $registry->get_available_gateways()
            : ( method_exists( $registry, 'get_gateways' ) ? $registry->get_gateways() : array() );

        $out = array();
        foreach ( $gateways as $id => $gateway ) {
            $id = (string) $id;
            if ( '' === $id ) {
                continue;
            }
            $title = method_exists( $gateway, 'get_title' ) ? (string) $gateway->get_title() : '';
            $out[ $id ] = '' !== $title ? $title : $id;
        }

        /**
         * Filter the per-currency payment gateway picker list.
         *
         * @param array<string, string> $gateways Map of gateway id => display label.
         */
        $filtered = apply_filters( 'tejcart_csw_admin_gateways', $out );
        return is_array( $filtered ) ? $filtered : $out;
    }
}
