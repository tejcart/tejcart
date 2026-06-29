<?php
/**
 * Payment Methods Management List
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Gateways\Abstract_Gateway;
use TejCart\Gateways\Gateway_Registry;
use TejCart\Gateways\PayPal\PayPal_Gateway;
use TejCart\Gateways\PayPal\PayPal_Onboarding;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the payment methods list.
 *
 * Displayed inside the Settings → Payments tab. Gateways (PayPal and every
 * offline method) are rendered as rows of a single table with columns:
 * Method, Enabled, Description, Set up. The Method column shows
 * the gateway logo and linked title; the Enabled column shows a toggle when
 * the gateway is connected (or a "Not Connected" pill otherwise); the Set up
 * column shows a primary "Set up" button when the gateway is not connected
 * and a secondary "Manage" button once connected.
 */
class Payment_Methods_List {
    /**
     * PayPal gateway IDs consolidated into the single PayPal card.
     */
    private const PAYPAL_GATEWAY_IDS = array(
        'tejcart_paypal',
        'tejcart_card',
        'tejcart_googlepay',
        'tejcart_applepay',
        'tejcart_fastlane',
    );

    /**
     * PayPal brand glyph used in the small payment-methods row — the
     * single-path Font Awesome 5/6 `fa-paypal` mark. Drawn with
     * `fill="currentColor"` so it inherits the row's text colour
     * (`--nc-heading` when disabled, `--nc-primary` when enabled).
     * Concatenated by `get_paypal_logo_svg()` with per-call-site
     * dimensions. Split across multiple string literals so no single
     * source line exceeds the phpcs absolute limit (#1234).
     */
    private const PAYPAL_LOGO_SVG_OPEN =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" aria-label="PayPal"';

    private const PAYPAL_LOGO_SVG_PATHS =
        '<path fill="currentColor" d="M111.4 295.9c-3.5 19.2-17.4 108.7-21.5 134-.3 1.8-1 2.5-3 2.5H12.3'
        . 'c-7.6 0-13.1-6.6-12.1-13.9L58.8 46.6c1.5-9.6 10.1-16.9 20-16.9 152.3 0 165.1-3.7 204 11.4'
        . ' 60.1 23.3 65.6 79.5 44 140.3-21.5 62.6-72.5 89.5-140.1 90.3-43.4 .7-69.5-7-75.3 24.2z'
        . 'M357.1 152c-1.8-1.2-2.5-1.8-3 1.2-2 11.5-5.1 22.8-8.8 33.8-39.9 113.8-150.5 110.1-211.4'
        . ' 110.1-6.9 0-11.4 3.7-12.3 10.5-22.6 140.3-27.1 169.3-27.1 169.3-1 7.1 3.5 12.9 10.6 12.9'
        . 'h63.5c8.6 0 15.7-6.3 17.4-14.9 .7-5.4-1.1 6.1 14.4-91.3 4.6-22 14.3-19.7 29.3-19.7 71 0'
        . ' 126.4-28.8 142.9-112.3 6.5-34.8 4.6-71.4-15.5-99.6z"/>';

    /**
     * Gateway registry instance.
     *
     * @var Gateway_Registry
     */
    private Gateway_Registry $registry;

    /**
     * Constructor.
     *
     * @param Gateway_Registry|null $registry Gateway registry instance.
     */
    public function __construct( ?Gateway_Registry $registry = null ) {
        $this->registry = $registry ?: tejcart()->gateways();
    }

    /**
     * Render the payment methods list.
     *
     * Outputs a single standard table with the PayPal row first, followed by
     * one row per offline gateway. The inline AJAX toggle nonce is attached
     * to the wrapper so the shared toggle JS can read it.
     *
     * @return void
     */
    public function render(): void {
        $toggle_nonce = wp_create_nonce( 'tejcart_toggle_payment_method' );

        ?>
        <div class="tejcart-payments-list-wrap" data-toggle-nonce="<?php echo esc_attr( $toggle_nonce ); ?>">
            <table class="tejcart-payments-table widefat">
                <thead>
                    <tr>
                        <th class="tejcart-payments-table__col-method" scope="col"><?php esc_html_e( 'Method', 'tejcart' ); ?></th>
                        <th class="tejcart-payments-table__col-enabled" scope="col"><?php esc_html_e( 'Enabled', 'tejcart' ); ?></th>
                        <th class="tejcart-payments-table__col-desc" scope="col"><?php esc_html_e( 'Description', 'tejcart' ); ?></th>
                        <th class="tejcart-payments-table__col-action" scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Set up', 'tejcart' ); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $this->render_paypal_row();

                    $offline_gateways = array_filter(
                        $this->registry->get_gateways(),
                        function ( Abstract_Gateway $gateway ): bool {
                            return ! in_array( $gateway->get_id(), self::PAYPAL_GATEWAY_IDS, true )
                                && $gateway->is_admin_visible();
                        }
                    );

                    foreach ( $offline_gateways as $gateway ) {
                        $this->render_offline_row( $gateway );
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the PayPal row using the unified payment-method row layout.
     *
     * @return void
     */
    private function render_paypal_row(): void {
        $is_connected = PayPal_Gateway::is_onboarded();
        $paypal       = $this->registry->get_gateway( 'tejcart_paypal' );
        $is_enabled   = $paypal instanceof Abstract_Gateway
            && 'yes' === $paypal->get_option( 'enabled', 'no' );

        $logo_html = '<span class="tejcart-payment-method-row__logo tejcart-payment-method-row__logo--paypal" aria-hidden="true">'
            . self::get_paypal_logo_svg()
            . '</span>';

        $this->render_method_row(
            array(
                'gateway_id'   => 'tejcart_paypal',
                'logo_html'    => $logo_html,
                'title'        => __( 'PayPal Payments', 'tejcart' ),
                'description'  => __( 'Accept PayPal, Cards, Google Pay, Apple Pay and more.', 'tejcart' ),
                'is_connected' => $is_connected,
                'is_enabled'   => $is_enabled,
                'manage_url'   => PayPal_Manage_Page::get_url(),
            )
        );
    }

    /**
     * Render a single offline payment method row.
     *
     * Offline gateways (COD, Bank Transfer) do not have an onboarding /
     * connection step, so they always render in the "connected" state —
     * meaning the enable/disable toggle is shown immediately without the
     * "Not Connected" / "Set up" call-to-action.
     *
     * @param Abstract_Gateway $gateway Gateway instance.
     * @return void
     */
    private function render_offline_row( Abstract_Gateway $gateway ): void {
        $gateway_id = $gateway->get_id();
        $is_enabled = 'yes' === $gateway->get_option( 'enabled', 'no' );
        $icon_class = self::get_gateway_icon( $gateway_id );
        $initial    = mb_strtoupper( mb_substr( $gateway->get_title(), 0, 1 ) );

        if ( $icon_class ) {
            $logo_inner = '<span class="dashicons ' . esc_attr( $icon_class ) . '"></span>';
        } else {
            $logo_inner = '<span class="tejcart-payment-method-row__initial">' . esc_html( $initial ) . '</span>';
        }

        $logo_html = '<span class="tejcart-payment-method-row__logo" aria-hidden="true">' . $logo_inner . '</span>';

        $this->render_method_row(
            array(
                'gateway_id'   => $gateway_id,
                'logo_html'    => $logo_html,
                'title'        => $gateway->get_title(),
                'description'  => $gateway->get_description(),
                'is_connected' => true,
                'is_enabled'   => $is_enabled,
                'manage_url'   => self::get_settings_url( $gateway_id ),
            )
        );
    }

    /**
     * Render a single payment method row using the shared layout.
     *
     * Expected keys:
     *  - gateway_id   (string) Gateway identifier, used for toggle AJAX.
     *  - logo_html    (string) Pre-rendered logo markup (already escaped).
     *  - title        (string) Row title.
     *  - description  (string) Short description line.
     *  - is_connected (bool)   Whether the gateway has been set up.
     *  - is_enabled   (bool)   Whether the gateway's enable flag is on.
     *  - manage_url   (string) Destination for the Set up / Manage button.
     *
     * @param array $args Row arguments.
     * @return void
     */
    private function render_method_row( array $args ): void {
        $gateway_id   = (string) ( $args['gateway_id'] ?? '' );
        $logo_html    = (string) ( $args['logo_html'] ?? '' );
        $title        = (string) ( $args['title'] ?? '' );
        $description  = (string) ( $args['description'] ?? '' );
        $is_connected = ! empty( $args['is_connected'] );
        $is_enabled   = ! empty( $args['is_enabled'] );
        $manage_url   = (string) ( $args['manage_url'] ?? '' );

        $row_classes = array( 'tejcart-payment-method-row' );
        if ( $is_connected ) {
            $row_classes[] = 'is-connected';
        }
        if ( $is_connected && $is_enabled ) {
            $row_classes[] = 'is-enabled';
        }

        $toggle_id = 'tejcart-toggle-' . sanitize_html_class( $gateway_id );
        ?>
        <tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>"
            data-gateway-id="<?php echo esc_attr( $gateway_id ); ?>">
            <td class="tejcart-payment-method-row__method" data-colname="<?php esc_attr_e( 'Method', 'tejcart' ); ?>">
                <div class="tejcart-payment-method-row__method-inner">
                    <?php
                    // Logo markup is a <span> wrapper around either the PayPal
                    // SVG or a dashicon/initial span — escape against the SVG
                    // allowlist plus the wrapper span.
                    echo wp_kses(
                        $logo_html,
                        tejcart_kses_svg_allowed_html() + array(
                            'span' => array(
                                'class'       => true,
                                'aria-hidden' => true,
                            ),
                        )
                    );
                    ?>
                    <a href="<?php echo esc_url( $manage_url ); ?>" class="tejcart-payment-method-row__title">
                        <?php echo esc_html( $title ); ?>
                    </a>
                </div>
            </td>
            <td class="tejcart-payment-method-row__enabled" data-colname="<?php esc_attr_e( 'Enabled', 'tejcart' ); ?>">
                <?php if ( ! $is_connected ) : ?>
                    <span class="tejcart-pill tejcart-pill--error">
                        <?php esc_html_e( 'Not Connected', 'tejcart' ); ?>
                    </span>
                <?php else : ?>
                    <label class="tejcart-toggle tejcart-payment-method-toggle" for="<?php echo esc_attr( $toggle_id ); ?>">
                        <input
                            type="checkbox"
                            id="<?php echo esc_attr( $toggle_id ); ?>"
                            class="tejcart-payment-method-toggle-input"
                            data-gateway-id="<?php echo esc_attr( $gateway_id ); ?>"
                            value="yes"
                            <?php checked( $is_enabled ); ?>
                        />
                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                        <span class="screen-reader-text">
                            <?php
                            printf(
                                /* translators: %s: gateway title. */
                                esc_html__( 'Enable %s', 'tejcart' ),
                                esc_html( $title )
                            );
                            ?>
                        </span>
                    </label>
                <?php endif; ?>
            </td>
            <td class="tejcart-payment-method-row__desc" data-colname="<?php esc_attr_e( 'Description', 'tejcart' ); ?>">
                <?php echo esc_html( $description ); ?>
            </td>
            <td class="tejcart-payment-method-row__action" data-colname="<?php esc_attr_e( 'Set up', 'tejcart' ); ?>">
                <?php if ( ! $is_connected ) : ?>
                    <a href="<?php echo esc_url( $manage_url ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Set up', 'tejcart' ); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url( $manage_url ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Manage', 'tejcart' ); ?>
                    </a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Return the inline PayPal brand SVG used on the primary card.
     *
     * @return string
     */
    private static function get_paypal_logo_svg(): string {
        return self::PAYPAL_LOGO_SVG_OPEN
            . ' width="27" height="32" focusable="false">'
            . self::PAYPAL_LOGO_SVG_PATHS
            . '</svg>';
    }

    /**
     * Map gateway ID to a dashicons icon class.
     *
     * @param string $gateway_id Gateway ID.
     * @return string Empty string when no icon mapping is known.
     */
    private static function get_gateway_icon( string $gateway_id ): string {
        $map = array(
            'tejcart_paypal'    => 'dashicons-money-alt',
            'tejcart_card'      => 'dashicons-credit-card',
            'tejcart_googlepay' => 'dashicons-smartphone',
            'tejcart_applepay'  => 'dashicons-smartphone',
            'tejcart_fastlane'  => 'dashicons-controls-forward',
            'cod'               => 'dashicons-money',
            'bank_transfer'     => 'dashicons-bank',
            'cheque'            => 'dashicons-media-document',
        );

        /**
         * Filter the dashicons icon used for a gateway in the payment methods list.
         *
         * @param string $icon       Dashicons class (without the leading "dashicons " group).
         * @param string $gateway_id Gateway ID.
         */
        return (string) apply_filters( 'tejcart_payment_method_icon', $map[ $gateway_id ] ?? '', $gateway_id );
    }

    /**
     * Get the settings URL for a specific gateway.
     *
     * PayPal-family gateways are routed to the unified PayPal Manage page.
     * Offline gateways fall through to the legacy per-gateway settings page.
     *
     * @param string $gateway_id Gateway ID.
     * @return string
     */
    public static function get_settings_url( string $gateway_id ): string {
        if ( in_array( $gateway_id, self::PAYPAL_GATEWAY_IDS, true ) ) {
            return PayPal_Manage_Page::get_url();
        }

        return add_query_arg(
            array(
                'page'    => 'tejcart-payment-method-settings',
                'gateway' => rawurlencode( $gateway_id ),
            ),
            admin_url( 'admin.php' )
        );
    }

    /**
     * Get the URL for the payment methods list (Settings → Payments tab).
     *
     * @return string
     */
    public static function get_list_url(): string {
        return add_query_arg(
            array(
                'page' => 'tejcart-settings',
                'tab'  => 'payments',
            ),
            admin_url( 'admin.php' )
        );
    }

    /**
     * AJAX handler for inline gateway enable/disable.
     *
     * Expects POST: gateway_id (string), enabled ('1'|'0'), nonce.
     * Responds with JSON success/error.
     *
     * @return void
     */
    public static function ajax_toggle_gateway(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'You do not have permission to manage payment methods.', 'tejcart' ) ),
                403
            );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_toggle_payment_method' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'tejcart' ) ),
                400
            );
        }

        $gateway_id = isset( $_POST['gateway_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_id'] ) ) : '';
        if ( '' === $gateway_id ) {
            wp_send_json_error(
                array( 'message' => __( 'Missing gateway ID.', 'tejcart' ) ),
                400
            );
        }

        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );

        $registry = tejcart()->gateways();
        $gateway  = $registry->get_gateway( $gateway_id );

        if ( ! $gateway ) {
            wp_send_json_error(
                array( 'message' => __( 'Payment method not found.', 'tejcart' ) ),
                404
            );
        }

        if ( ! $gateway->is_admin_visible() ) {
            wp_send_json_error(
                array(
                    'message' => __(
                        'This payment method is not yet available. Complete PayPal seller onboarding first.',
                        'tejcart'
                    ),
                ),
                403
            );
        }

        $gateway->update_option( 'enabled', $enabled ? 'yes' : 'no' );
        $gateway->save_settings();

        wp_send_json_success(
            array(
                'gateway_id' => $gateway_id,
                'enabled'    => $enabled,
                'message'    => $enabled
                    ? __( 'Payment method enabled.', 'tejcart' )
                    : __( 'Payment method disabled.', 'tejcart' ),
            )
        );
    }
}
