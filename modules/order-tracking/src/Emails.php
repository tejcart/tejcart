<?php
/**
 * Transactional emails for shipment lifecycle events.
 *
 * Two outbound emails:
 *  - "Your order has shipped!"  — fires when a shipment transitions
 *    into a non-terminal in-transit state (shipped/in_transit/etc.) for
 *    the first time on an order.
 *  - "Your order has been delivered" — fires on transition to delivered.
 *
 * Plus one passive injection:
 *  - When a customer-facing order email is being assembled core fires
 *    `tejcart_order_email_after_summary`. We listen there and append a
 *    "Track your shipment" block when tracking is attached. (No-op if
 *    the hook doesn't exist on this site — older core, sibling-only
 *    install — we just don't inject.)
 *
 * We do NOT extend core's Abstract_Email because siblings shouldn't
 * couple to internal class hierarchies. We send via `wp_mail` directly
 * with HTML content type swapped in only for our calls so we don't
 * affect the rest of the site.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Emails {
    public const MARK_TRANSIENT_PREFIX = 'tejcart_ot_email_';

    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_action( 'tejcart_shipment_status_changed',   array( $this, 'on_status_changed' ), 10, 4 );
        if ( ! self::injection_enabled() ) {
            return;
        }
        // Core fires `tejcart_order_email_after_summary` with a single arg
        // ($order); register for 1 to match (the handler's $context param
        // stays at its default).
        add_action( 'tejcart_order_email_after_summary', array( $this, 'inject_tracking_block' ), 10, 1 );
        // Fallback path for sites whose core templates don't fire the
        // injection action above. We tag inbound TejCart mail by
        // looking for the X-TejCart-Mail header that Abstract_Email
        // sets on every send, then splice the tracking block before
        // </body>. Filter only runs once per dispatch and is a no-op
        // for non-TejCart mail and orders without shipments.
        add_filter( 'wp_mail', array( $this, 'filter_wp_mail' ), 20, 1 );
    }

    /**
     * Honour the Display sub-tab's "Append tracking block to customer
     * order emails" toggle. Stand-alone shipped/delivered notifications
     * still fire — they are explicit lifecycle mail, not the
     * passive-injection path this gates.
     */
    private static function injection_enabled(): bool {
        if ( ! class_exists( Settings::class ) ) {
            return true;
        }
        return (bool) Settings::get( 'display_emails', 1 );
    }

    /**
     * @param int    $order_id
     * @param int    $shipment_id
     * @param string $from
     * @param string $to
     */
    public function on_status_changed( $order_id, $shipment_id, $from, $to ): void {
        $order_id    = (int) $order_id;
        $shipment_id = (int) $shipment_id;
        if ( $order_id <= 0 || $shipment_id <= 0 ) {
            return;
        }

        // First in-transit transition → "shipped" email.
        $in_transit_states = array(
            Shipment_Status::SHIPPED,
            Shipment_Status::IN_TRANSIT,
            Shipment_Status::OUT_FOR_DELIVERY,
        );
        if ( in_array( (string) $to, $in_transit_states, true ) && ! in_array( (string) $from, $in_transit_states, true ) ) {
            $this->send_once( 'shipped', $order_id, fn() => $this->send_shipped_email( $order_id ) );
        }

        if ( Shipment_Status::DELIVERED === (string) $to ) {
            $this->send_once( 'delivered', $order_id, fn() => $this->send_delivered_email( $order_id ) );
        }
    }

    /**
     * Send `$send` only the first time we observe `$kind` for `$order_id`.
     * Per-order de-dupe via a 30-day transient — multiple shipments per
     * order won't spam the customer with N "shipped" emails.
     */
    private function send_once( string $kind, int $order_id, callable $send ): void {
        $key = self::MARK_TRANSIENT_PREFIX . $kind . '_' . $order_id;
        if ( get_transient( $key ) ) {
            return;
        }
        $send();
        set_transient( $key, 1, 30 * DAY_IN_SECONDS );
    }

    private function send_shipped_email( int $order_id ): bool {
        $address = $this->customer_email_for( $order_id );
        if ( '' === $address ) {
            return false;
        }
        $shipments = $this->service->for_order( $order_id );
        if ( empty( $shipments ) ) {
            return false;
        }
        $subject = apply_filters(
            'tejcart_order_tracking_email_shipped_subject',
            sprintf(
                /* translators: %s: order number */
                __( 'Your order %s has shipped', 'tejcart' ),
                $this->order_number_for( $order_id )
            ),
            $order_id
        );
        $body = $this->compose_email(
            __( 'Your order is on its way', 'tejcart' ),
            __( 'Good news — your order has shipped. Track its progress below:', 'tejcart' ),
            $shipments,
            $order_id
        );
        return $this->dispatch( $address, (string) $subject, $body );
    }

    private function send_delivered_email( int $order_id ): bool {
        $address = $this->customer_email_for( $order_id );
        if ( '' === $address ) {
            return false;
        }
        $shipments = $this->service->for_order( $order_id );
        $subject   = apply_filters(
            'tejcart_order_tracking_email_delivered_subject',
            sprintf(
                /* translators: %s: order number */
                __( 'Your order %s has been delivered', 'tejcart' ),
                $this->order_number_for( $order_id )
            ),
            $order_id
        );
        $body = $this->compose_email(
            __( 'Your order has been delivered', 'tejcart' ),
            __( 'Your order has been delivered. Thanks for shopping with us!', 'tejcart' ),
            $shipments,
            $order_id
        );
        return $this->dispatch( $address, (string) $subject, $body );
    }

    /**
     * Append a "Track your shipment" block to outbound order emails.
     *
     * @param mixed  $order    Order object or order id (the
     *                         `tejcart_order_email_after_summary` hook in
     *                         core templates passes the Order instance).
     * @param string $context  Email kind (e.g. 'order_completed', 'buyer_receipt').
     */
    public function inject_tracking_block( $order, $context = '' ): void {
        if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
            $order_id = (int) $order->get_id();
        } else {
            $order_id = (int) $order;
        }
        if ( $order_id <= 0 ) {
            return;
        }
        $shipments = $this->service->for_order( $order_id );
        if ( empty( $shipments ) ) {
            return;
        }
        echo wp_kses_post( $this->render_shipment_block( $shipments ) );
    }

    /**
     * Splice the tracking block into the body of TejCart-tagged emails
     * just before `</body>`. We identify TejCart mail by the
     * `X-TejCart-Mail` header that `Abstract_Email::send()` always sets
     * — non-TejCart mail (and emails not carrying that header) is left
     * untouched.
     *
     * The customer email lookup is the source of truth for which
     * order is being notified: we resolve order id from the
     * recipient + the X-TejCart-Mail kind header, only acting on the
     * order-scoped flavours (`order_processing`, `order_completed`,
     * `order_on_hold`, `buyer_receipt`, `customer_invoice`).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filter_wp_mail( $args ): array {
        if ( ! is_array( $args ) ) {
            return is_array( $args ) ? $args : array();
        }
        $headers = $args['headers'] ?? array();
        if ( is_string( $headers ) ) {
            $headers = preg_split( "/\r?\n/", $headers ) ?: array();
        }
        if ( ! is_array( $headers ) ) {
            return $args;
        }
        $kind = $this->extract_kind_header( $headers );
        if ( '' === $kind ) {
            return $args;
        }
        if ( ! $this->is_order_scoped_kind( $kind ) ) {
            return $args;
        }
        $order_id = $this->resolve_order_id_from_args( $args );
        if ( $order_id <= 0 ) {
            return $args;
        }
        $shipments = $this->service->for_order( $order_id );
        if ( empty( $shipments ) ) {
            return $args;
        }
        $block   = $this->render_shipment_block( $shipments );
        $message = (string) ( $args['message'] ?? '' );
        if ( '' === $message || '' === $block ) {
            return $args;
        }
        $args['message'] = $this->splice_block( $message, $block );
        return $args;
    }

    /**
     * @param array<int, string> $headers
     */
    private function extract_kind_header( array $headers ): string {
        foreach ( $headers as $line ) {
            if ( ! is_string( $line ) ) {
                continue;
            }
            if ( 0 === stripos( $line, 'X-TejCart-Mail:' ) ) {
                $value = trim( substr( $line, strlen( 'X-TejCart-Mail:' ) ) );
                return strtolower( $value );
            }
        }
        return '';
    }

    private function is_order_scoped_kind( string $kind ): bool {
        $allowed = (array) apply_filters(
            'tejcart_order_tracking_email_kinds',
            array(
                'order_processing',
                'order_completed',
                'order_on_hold',
                'buyer_receipt',
                'customer_invoice',
            )
        );
        return in_array( $kind, array_map( 'strtolower', $allowed ), true );
    }

    /**
     * Find the order id from a recipient address by scanning the
     * `wp_tejcart_orders` table. The query is bounded to the most
     * recent five matching rows so a single shared customer email
     * can't blow up the lookup; the *most recent* order wins, which
     * matches user expectation for "your order has shipped"
     * notifications.
     *
     * @param array<string, mixed> $args
     */
    private function resolve_order_id_from_args( array $args ): int {
        $to = $args['to'] ?? '';
        if ( is_array( $to ) ) {
            $to = (string) reset( $to );
        }
        $to = trim( (string) $to );
        if ( '' === $to || ! is_email( $to ) ) {
            return 0;
        }
        $resolved = (int) apply_filters( 'tejcart_order_tracking_email_order_id', 0, $to, $args );
        if ( $resolved > 0 ) {
            return $resolved;
        }
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! property_exists( $wpdb, 'prefix' ) ) {
            return 0;
        }
        $orders_tbl = $wpdb->prefix . 'tejcart_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders_tbl} WHERE customer_email = %s ORDER BY id DESC LIMIT 1", $to ) );
        return null === $row ? 0 : (int) $row;
    }

    private function splice_block( string $message, string $block ): string {
        $lower = strtolower( $message );
        $needle = '</body>';
        $pos    = strrpos( $lower, $needle );
        if ( false === $pos ) {
            return $message . "\n" . $block;
        }
        return substr( $message, 0, $pos ) . $block . substr( $message, $pos );
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     */
    private function compose_email( string $heading, string $intro, array $shipments, int $order_id ): string {
        // Allow themes to override the email body via the same template
        // resolution path that core's user-facing emails use. Themes
        // copy the template to
        // `<theme>/tejcart/emails/order-tracking-shipment.php` and
        // receive `$heading`, `$intro`, `$shipments`, `$order_number`.
        if ( function_exists( 'tejcart_get_template' ) ) {
            $args = array(
                'heading'      => $heading,
                'intro'        => $intro,
                'shipments'    => $shipments,
                'order_number' => $this->order_number_for( $order_id ),
            );
            ob_start();
            tejcart_get_template( 'emails/order-tracking-shipment.php', $args );
            $rendered = (string) ob_get_clean();
            if ( '' !== trim( $rendered ) ) {
                return $rendered;
            }
        }

        ob_start();
        ?>
        <html>
        <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937; max-width: 600px; margin: 0 auto; padding: 24px;">
            <h1 style="font-size: 22px; margin-top: 0;"><?php echo esc_html( $heading ); ?></h1>
            <p style="font-size: 15px; line-height: 1.5;"><?php echo esc_html( $intro ); ?></p>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_shipment_block returns sanitised HTML.
            echo $this->render_shipment_block( $shipments );
            ?>
            <p style="font-size: 13px; color: #6b7280; margin-top: 32px;">
                <?php
                printf(
                    /* translators: %s: order number */
                    esc_html__( 'Order reference: %s', 'tejcart' ),
                    esc_html( $this->order_number_for( $order_id ) )
                );
                ?>
            </p>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     */
    private function render_shipment_block( array $shipments ): string {
        ob_start();
        ?>
        <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin: 16px 0;">
            <h2 style="font-size: 16px; margin: 0 0 12px;">
                <?php esc_html_e( 'Tracking', 'tejcart' ); ?>
            </h2>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                <?php foreach ( $shipments as $row ) :
                    $carrier_label = (string) ( $row['carrier_label'] ?? '' );
                    $number        = (string) ( $row['tracking_number'] ?? '' );
                    $url           = (string) ( $row['tracking_url'] ?? '' );
                    ?>
                    <tr>
                        <td style="padding: 6px 0; color: #6b7280;"><?php echo esc_html( $carrier_label ); ?></td>
                        <td style="padding: 6px 0; text-align: right;">
                            <?php if ( '' !== $url ) : ?>
                                <a href="<?php echo esc_url( $url ); ?>" style="color: #2563eb; text-decoration: none;">
                                    <?php echo esc_html( $number ); ?>
                                </a>
                            <?php else : ?>
                                <?php echo esc_html( $number ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Send an HTML mail without leaking the content-type filter to the
     * rest of the site.
     */
    private function dispatch( string $to, string $subject, string $body ): bool {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        /**
         * Allow recipients/subject/body/headers overrides.
         *
         * @param array{to:string,subject:string,body:string,headers:array<int,string>} $msg
         */
        $msg = (array) apply_filters( 'tejcart_order_tracking_email', array(
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'headers' => $headers,
        ) );

        $sent = (bool) wp_mail(
            (string) ( $msg['to']      ?? $to ),
            (string) ( $msg['subject'] ?? $subject ),
            (string) ( $msg['body']    ?? $body ),
            (array)  ( $msg['headers'] ?? $headers )
        );

        do_action( 'tejcart_order_tracking_email_sent', $msg, $sent );
        return $sent;
    }

    private function customer_email_for( int $order_id ): string {
        if ( function_exists( 'tejcart_get_order' ) ) {
            $order = tejcart_get_order( $order_id );
            if ( is_object( $order ) && method_exists( $order, 'get_customer_email' ) ) {
                $email = (string) $order->get_customer_email();
                if ( '' !== $email && is_email( $email ) ) {
                    return $email;
                }
            }
        }
        global $wpdb;
        $orders_tbl = $wpdb->prefix . 'tejcart_orders';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
        $email = (string) $wpdb->get_var( $wpdb->prepare( "SELECT customer_email FROM {$orders_tbl} WHERE id = %d LIMIT 1", $order_id ) );
        return is_email( $email ) ? $email : '';
    }

    private function order_number_for( int $order_id ): string {
        if ( function_exists( 'tejcart_get_order' ) ) {
            $order = tejcart_get_order( $order_id );
            if ( is_object( $order ) && method_exists( $order, 'get_order_number' ) ) {
                $num = (string) $order->get_order_number();
                if ( '' !== $num ) {
                    return $num;
                }
            }
        }
        return '#' . $order_id;
    }
}
