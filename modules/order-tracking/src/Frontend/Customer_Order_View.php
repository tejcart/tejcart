<?php
/**
 * Inline tracking display for customer-facing order pages.
 *
 * Hooks into core's `tejcart_view_order` (account → view-order) and
 * `tejcart_thankyou` (post-checkout) actions to render a "Tracking"
 * panel listing every shipment attached to the order, with carrier
 * label, deep-linked tracking number, status pill, and the public-safe
 * carrier event timeline (when populated by the polling/webhook
 * pipeline).
 *
 * The panel is only rendered when shipments exist — orders without
 * tracking emit nothing, so themes don't see an empty placeholder.
 *
 * Assets reuse the frontend CSS bundle that ships with the
 * `[tejcart_track_order]` shortcode; no extra HTTP request.
 *
 * @package TejCart\Tier2\Order_Tracking\Frontend
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Frontend;

use TejCart\Tier2\Order_Tracking\Admin_Metabox;
use TejCart\Tier2\Order_Tracking\Settings;
use TejCart\Tier2\Order_Tracking\Tracking_Service;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Customer_Order_View {
    public const HANDLE_CSS = 'tejcart-order-tracking-frontend';

    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        if ( $this->setting_enabled( 'display_customer_view' ) ) {
            add_action( 'tejcart_view_order', array( $this, 'render_for_order' ), 20, 1 );
        }
        if ( $this->setting_enabled( 'display_thankyou' ) ) {
            add_action( 'tejcart_thankyou',   array( $this, 'render_for_order' ), 20, 1 );
        }
    }

    /**
     * Resolve a display toggle. Defaults ON so sites that never visit
     * the new Display sub-tab keep the pre-1.x rendering behaviour.
     */
    private function setting_enabled( string $key ): bool {
        if ( ! class_exists( Settings::class ) ) {
            return true;
        }
        return (bool) Settings::get( $key, 1 );
    }

    /**
     * @param mixed $order Either an order id, an order object exposing
     *                     `get_id()`, or anything coercible to int.
     */
    public function render_for_order( $order ): void {
        $order_id = $this->resolve_order_id( $order );
        if ( $order_id <= 0 ) {
            return;
        }

        $shipments = $this->service->for_order( $order_id );
        if ( empty( $shipments ) ) {
            return;
        }

        $configured = '';
        if ( class_exists( Settings::class ) ) {
            $configured = trim( (string) Settings::get( 'display_heading', '' ) );
        }
        $default_heading = '' !== $configured ? $configured : __( 'Tracking', 'tejcart' );
        $heading = (string) apply_filters(
            'tejcart_order_tracking_customer_view_heading',
            $default_heading,
            $order_id,
            $shipments
        );

        $this->maybe_enqueue_assets();

        echo $this->render( $heading, $shipments ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns escaped HTML.
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     */
    public function render( string $heading, array $shipments ): string {
        $show_events = $this->setting_enabled( 'display_events' );
        ob_start();
        ?>
        <section class="tejcart-order-tracking-panel" data-tejcart-order-tracking>
            <?php if ( '' !== $heading ) : ?>
                <h2 class="tejcart-order-tracking-panel__title"><?php echo esc_html( $heading ); ?></h2>
            <?php endif; ?>
            <ul class="tejcart-order-tracking-panel__list">
                <?php foreach ( $shipments as $row ) :
                    $carrier_label = (string) ( $row['carrier_label'] ?? $row['carrier'] ?? '' );
                    $number        = (string) ( $row['tracking_number'] ?? '' );
                    $url           = (string) ( $row['tracking_url'] ?? '' );
                    $status        = (string) ( $row['status'] ?? '' );
                    $events        = $show_events && isset( $row['events'] ) && is_array( $row['events'] ) ? $row['events'] : array();
                    ?>
                    <li class="tejcart-order-tracking-panel__row">
                        <div class="tejcart-order-tracking-panel__head">
                            <span class="tejcart-order-tracking-panel__carrier">
                                <?php echo esc_html( $carrier_label ); ?>
                            </span>
                            <span class="tejcart-order-tracking-panel__number">
                                <?php if ( '' !== $url ) : ?>
                                    <a href="<?php echo esc_url( $url ); ?>"
                                       rel="noopener noreferrer external"
                                       target="_blank"
                                       aria-label="<?php echo esc_attr( sprintf( /* translators: 1: tracking number, 2: carrier name */ __( 'Track shipment %1$s via %2$s', 'tejcart' ), $number, $carrier_label ) ); ?>">
                                        <?php echo esc_html( $number ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html( $number ); ?>
                                <?php endif; ?>
                            </span>
                            <?php if ( '' !== $status ) : ?>
                                <span class="tejcart-order-tracking-panel__status tejcart-order-tracking-panel__status--<?php echo esc_attr( $status ); ?>">
                                    <?php echo esc_html( Admin_Metabox::status_label( $status ) ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $events ) ) : ?>
                            <ol class="tejcart-order-tracking-panel__events">
                                <?php foreach ( $events as $event ) :
                                    $event_status   = (string) ( $event['status']   ?? '' );
                                    $event_message  = (string) ( $event['message']  ?? '' );
                                    $event_location = (string) ( $event['location'] ?? '' );
                                    $event_time     = (string) ( $event['time']     ?? '' );
                                    ?>
                                    <li class="tejcart-order-tracking-panel__event">
                                        <?php if ( '' !== $event_time ) : ?>
                                            <time class="tejcart-order-tracking-panel__event-time"
                                                  datetime="<?php echo esc_attr( $event_time ); ?>">
                                                <?php echo esc_html( $event_time ); ?>
                                            </time>
                                        <?php endif; ?>
                                        <?php if ( '' !== $event_status ) : ?>
                                            <span class="tejcart-order-tracking-panel__event-status">
                                                <?php echo esc_html( Admin_Metabox::status_label( $event_status ) ); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ( '' !== $event_message ) : ?>
                                            <span class="tejcart-order-tracking-panel__event-message">
                                                <?php echo esc_html( $event_message ); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ( '' !== $event_location ) : ?>
                                            <span class="tejcart-order-tracking-panel__event-location">
                                                <?php echo esc_html( $event_location ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function maybe_enqueue_assets(): void {
        if ( ! function_exists( 'wp_style_is' ) || ! function_exists( 'wp_enqueue_style' ) ) {
            return;
        }
        if ( wp_style_is( self::HANDLE_CSS, 'registered' ) ) {
            wp_enqueue_style( self::HANDLE_CSS );
            return;
        }
        // Standalone enqueue when the shortcode hasn't registered yet
        // (the shortcode's wp_enqueue_scripts hook may not have fired
        // for this request — view-order can be rendered very early).
        if ( ! defined( 'TEJCART_ORDER_TRACKING_FILE' ) ) {
            return;
        }
        $base_url = plugins_url( 'assets/', TEJCART_ORDER_TRACKING_FILE );
        $suffix   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
        wp_register_style(
            self::HANDLE_CSS,
            $base_url . 'frontend/order-tracking' . $suffix . '.css',
            array(),
            defined( 'TEJCART_ORDER_TRACKING_VERSION' ) ? TEJCART_ORDER_TRACKING_VERSION : null
        );
        wp_enqueue_style( self::HANDLE_CSS );
    }

    /**
     * @param mixed $order
     */
    private function resolve_order_id( $order ): int {
        if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
            return (int) $order->get_id();
        }
        if ( is_array( $order ) && isset( $order['id'] ) ) {
            return (int) $order['id'];
        }
        if ( is_numeric( $order ) ) {
            return (int) $order;
        }
        return 0;
    }
}
