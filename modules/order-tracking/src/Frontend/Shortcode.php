<?php
/**
 * `[tejcart_track_order]` shortcode.
 *
 * Renders the customer-facing "Track my order" form. The form posts to
 * the public admin-AJAX endpoint (`tejcart_tracking_list` for nopriv),
 * which is double-rate-limited (burst + miss) and requires a matching
 * (order_number, customer_email) pair so order numbers can't be
 * enumerated.
 *
 * The shortcode is template-overridable via `tejcart_get_template()`:
 * sites can drop `tejcart/track-order.php` into their theme to fully
 * replace the markup.
 *
 * Usage:
 *   [tejcart_track_order]
 *   [tejcart_track_order title="Where's my order?" submit="Track"]
 *
 * @package TejCart\Tier2\Order_Tracking\Frontend
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Frontend;

use TejCart\Tier2\Order_Tracking\Admin_Metabox;
use TejCart\Tier2\Order_Tracking\Carriers;
use TejCart\Tier2\Order_Tracking\Shipment_Status;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shortcode {
    public const TAG          = 'tejcart_track_order';
    public const HANDLE_CSS   = 'tejcart-order-tracking-frontend';
    public const HANDLE_JS    = 'tejcart-order-tracking-frontend';

    public function register(): void {
        add_shortcode( self::TAG, array( $this, 'render' ) );
        // Late enqueue so we don't block render — only when the
        // shortcode actually appears on the page.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets(): void {
        $base_url = plugins_url( 'assets/', TEJCART_ORDER_TRACKING_FILE );
        $suffix   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

        wp_register_style(
            self::HANDLE_CSS,
            $base_url . 'frontend/order-tracking' . $suffix . '.css',
            array(),
            TEJCART_ORDER_TRACKING_VERSION
        );
        wp_register_script(
            self::HANDLE_JS,
            $base_url . 'frontend/order-tracking' . $suffix . '.js',
            array(),
            TEJCART_ORDER_TRACKING_VERSION,
            true
        );
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function render( $atts = array(), ?string $content = null, string $tag = '' ): string {
        $atts = shortcode_atts(
            array(
                'title'  => __( 'Track your order', 'tejcart' ),
                'submit' => __( 'Track', 'tejcart' ),
            ),
            is_array( $atts ) ? $atts : array(),
            self::TAG
        );

        wp_enqueue_style( self::HANDLE_CSS );
        wp_enqueue_script( self::HANDLE_JS );
        wp_localize_script(
            self::HANDLE_JS,
            'tejcartOrderTrackingPublic',
            array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'ajaxAction' => 'tejcart_tracking_lookup',
                'nonce'      => wp_create_nonce( 'tejcart_nonce' ),
                'i18n'       => array(
                    'invalidEmail'   => __( 'Please enter a valid email address.', 'tejcart' ),
                    'missingFields'  => __( 'Order number and email are required.', 'tejcart' ),
                    'noResults'      => __( 'No order found for that email.', 'tejcart' ),
                    'tooMany'        => __( 'Too many lookups. Please try again later.', 'tejcart' ),
                    'genericError'   => __( 'Something went wrong. Please try again.', 'tejcart' ),
                    'noShipments'    => __( 'No tracking has been added to this order yet.', 'tejcart' ),
                    'carrierLabel'   => __( 'Carrier', 'tejcart' ),
                    'numberLabel'    => __( 'Tracking number', 'tejcart' ),
                    'statusLabel'    => __( 'Status', 'tejcart' ),
                ),
                'statusLabels' => $this->status_label_map(),
            )
        );

        $args = array(
            'title'   => (string) $atts['title'],
            'submit'  => (string) $atts['submit'],
            'form_id' => 'tejcart-track-order-' . wp_generate_uuid4(),
        );

        // Theme-overridable template; falls back to the inline default.
        if ( function_exists( 'tejcart_get_template' ) ) {
            ob_start();
            tejcart_get_template( 'order-tracking/track-order.php', $args );
            $output = ob_get_clean();
            if ( '' !== trim( (string) $output ) ) {
                return (string) $output;
            }
        }

        return $this->default_template( $args );
    }

    /**
     * @return array<string, string>
     */
    private function status_label_map(): array {
        $map = array();
        foreach ( Shipment_Status::all() as $status ) {
            $map[ $status ] = Admin_Metabox::status_label( $status );
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function default_template( array $args ): string {
        $title   = (string) ( $args['title']   ?? '' );
        $submit  = (string) ( $args['submit']  ?? 'Track' );
        $form_id = (string) ( $args['form_id'] ?? 'tejcart-track-order' );

        ob_start();
        ?>
        <div class="tejcart-track-order" data-tejcart-track-root>
            <?php if ( '' !== $title ) : ?>
                <h2 class="tejcart-track-order__title"><?php echo esc_html( $title ); ?></h2>
            <?php endif; ?>
            <form class="tejcart-track-order__form" id="<?php echo esc_attr( $form_id ); ?>" data-tejcart-track-form novalidate>
                <label class="tejcart-track-order__field">
                    <span><?php esc_html_e( 'Order number', 'tejcart' ); ?></span>
                    <input type="text" name="order_number" required autocomplete="off" inputmode="numeric" />
                </label>
                <label class="tejcart-track-order__field">
                    <span><?php esc_html_e( 'Email used for the order', 'tejcart' ); ?></span>
                    <input type="email" name="email" required autocomplete="email" />
                </label>
                <div class="tejcart-track-order__actions">
                    <button type="submit" class="tejcart-track-order__submit">
                        <?php echo esc_html( $submit ); ?>
                    </button>
                </div>
                <p class="tejcart-track-order__feedback" data-tejcart-track-feedback role="status" aria-live="polite"></p>
            </form>
            <div class="tejcart-track-order__results" data-tejcart-track-results hidden></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
