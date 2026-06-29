<?php
/**
 * Admin metabox: tracking management on the order-edit screen.
 *
 * Binds to core's `tejcart_admin_order_after_sidebar` hook and renders a
 * card containing:
 *
 *   - The list of existing shipments for the order, with carrier label,
 *     tracking-number deep-link, status pill, and a delete button.
 *   - An "Add tracking" form: carrier select, tracking number, optional
 *     service, status select. Submit button.
 *
 * All write paths route through the same admin AJAX endpoints
 * (`tejcart_tracking_add|update|delete|list`) — the JS is the only new
 * surface; the data plane is already covered by Service / Repository.
 *
 * Assets are enqueued only on the order-view screen
 * (`page=tejcart-orders&action=view`), so we don't bloat the admin
 * footprint elsewhere.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Metabox {
    private Tracking_Service $service;

    public function __construct( Tracking_Service $service ) {
        $this->service = $service;
    }

    public function register(): void {
        add_action( 'tejcart_admin_order_after_sidebar', array( $this, 'render' ) );
        add_action( 'admin_enqueue_scripts',             array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets( string $hook_suffix ): void {
        if ( ! $this->is_order_view_screen( $hook_suffix ) ) {
            return;
        }
        if ( ! Capability::current_user_can_manage() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only; reading order_id from admin screen URL
        $order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
        if ( $order_id <= 0 ) {
            return;
        }

        $base_url = plugins_url( 'assets/', TEJCART_ORDER_TRACKING_FILE );
        $suffix   = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

        wp_enqueue_style(
            'tejcart-order-tracking-admin',
            $base_url . 'admin/order-tracking-admin' . $suffix . '.css',
            array(),
            TEJCART_ORDER_TRACKING_VERSION
        );
        wp_enqueue_script(
            'tejcart-order-tracking-admin',
            $base_url . 'admin/order-tracking-admin' . $suffix . '.js',
            array( 'wp-i18n' ),
            TEJCART_ORDER_TRACKING_VERSION,
            true
        );

        $carriers = array();
        foreach ( Carriers::all() as $slug => $entry ) {
            $carriers[] = array( 'slug' => $slug, 'label' => $entry['label'] );
        }

        $statuses = array();
        foreach ( Shipment_Status::all() as $status ) {
            $statuses[] = array(
                'slug'  => $status,
                'label' => self::status_label( $status ),
            );
        }

        wp_localize_script(
            'tejcart-order-tracking-admin',
            'tejcartOrderTracking',
            array(
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'tejcart_nonce' ),
                'orderId'  => $order_id,
                'carriers' => $carriers,
                'statuses' => $statuses,
                'i18n'     => array(
                    'addedOk'           => __( 'Tracking added.', 'tejcart' ),
                    'deletedOk'         => __( 'Tracking deleted.', 'tejcart' ),
                    'updatedOk'         => __( 'Tracking updated.', 'tejcart' ),
                    'repollOk'          => __( 'Refresh requested.', 'tejcart' ),
                    'genericError'      => __( 'Something went wrong. Please try again.', 'tejcart' ),
                    'confirmDelete'     => __( 'Delete this tracking number? This cannot be undone.', 'tejcart' ),
                    'noShipments'       => __( 'No tracking attached to this order yet.', 'tejcart' ),
                    'addLabel'          => __( 'Add tracking', 'tejcart' ),
                    'carrierLabel'      => __( 'Carrier', 'tejcart' ),
                    'serviceLabel'      => __( 'Service (optional)', 'tejcart' ),
                    'numberLabel'       => __( 'Tracking number', 'tejcart' ),
                    'statusLabel'       => __( 'Status', 'tejcart' ),
                    'deleteLabel'       => __( 'Delete', 'tejcart' ),
                    'editLabel'         => __( 'Edit', 'tejcart' ),
                    'repollLabel'       => __( 'Re-poll', 'tejcart' ),
                    'editCarrierPrompt' => __( 'Carrier slug:', 'tejcart' ),
                    'editNumberPrompt'  => __( 'Tracking number:', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * Render the metabox card.
     *
     * @param mixed $order Core Order object passed by the action.
     */
    public function render( $order ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
            return;
        }
        if ( ! Capability::current_user_can_manage() ) {
            return;
        }

        $order_id  = (int) $order->get_id();
        $shipments = $this->service->for_order( $order_id );

        ?>
        <div class="tejcart-card tejcart-card--tracking" id="tejcart-order-tracking-card">
            <div class="tejcart-card-header">
                <h3><span class="dashicons dashicons-location"></span>
                    <?php esc_html_e( 'Shipment Tracking', 'tejcart' ); ?>
                </h3>
            </div>
            <div class="tejcart-card-body">
                <table class="widefat striped tejcart-tracking-table"
                       data-tejcart-tracking-table
                       aria-live="polite">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Carrier', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Tracking #', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tejcart' ); ?></span></th>
                        </tr>
                    </thead>
                    <tbody data-tejcart-tracking-rows>
                        <?php if ( empty( $shipments ) ) : ?>
                            <tr data-tejcart-tracking-empty>
                                <td colspan="4"><?php esc_html_e( 'No tracking attached to this order yet.', 'tejcart' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $shipments as $row ) : ?>
                                <?php $this->render_row( $row ); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <form class="tejcart-tracking-form" data-tejcart-tracking-form>
                    <h4><?php esc_html_e( 'Add tracking', 'tejcart' ); ?></h4>
                    <div class="tejcart-tracking-form__row">
                        <label>
                            <span><?php esc_html_e( 'Carrier', 'tejcart' ); ?></span>
                            <select name="carrier" required data-tejcart-carrier-select>
                                <?php foreach ( Carriers::all() as $slug => $entry ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $entry['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e( 'Tracking number', 'tejcart' ); ?></span>
                            <input type="text" name="tracking_number" required maxlength="190" autocomplete="off" />
                        </label>
                    </div>
                    <div class="tejcart-tracking-form__row">
                        <label>
                            <span><?php esc_html_e( 'Service (optional)', 'tejcart' ); ?></span>
                            <input type="text" name="service" maxlength="80" autocomplete="off" />
                        </label>
                        <label>
                            <span><?php esc_html_e( 'Status', 'tejcart' ); ?></span>
                            <select name="status">
                                <?php foreach ( Shipment_Status::all() as $status ) : ?>
                                    <option value="<?php echo esc_attr( $status ); ?>" <?php selected( Shipment_Status::SHIPPED, $status ); ?>>
                                        <?php echo esc_html( self::status_label( $status ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="tejcart-tracking-form__actions">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Add tracking', 'tejcart' ); ?>
                        </button>
                        <span class="tejcart-tracking-feedback" data-tejcart-tracking-feedback role="status"></span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single shipment row.
     *
     * @param array<string, mixed> $row
     */
    private function render_row( array $row ): void {
        $id              = (int) ( $row['id'] ?? 0 );
        $carrier         = (string) ( $row['carrier'] ?? '' );
        $carrier_label   = (string) ( $row['carrier_label'] ?? Carriers::label( $carrier ) );
        $tracking_number = (string) ( $row['tracking_number'] ?? '' );
        $tracking_url    = (string) ( $row['tracking_url'] ?? '' );
        $status          = (string) ( $row['status'] ?? Shipment_Status::PENDING );
        ?>
        <tr data-tejcart-tracking-row
            data-shipment-id="<?php echo esc_attr( (string) $id ); ?>"
            data-carrier="<?php echo esc_attr( $carrier ); ?>">
            <td data-tejcart-tracking-col="carrier"><?php echo esc_html( $carrier_label ); ?></td>
            <td data-tejcart-tracking-col="number">
                <?php if ( '' !== $tracking_url ) : ?>
                    <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer"
                       aria-label="<?php echo esc_attr( sprintf( /* translators: 1: tracking number, 2: carrier name */ __( 'Track %1$s via %2$s', 'tejcart' ), $tracking_number, $carrier_label ) ); ?>">
                        <?php echo esc_html( $tracking_number ); ?>
                    </a>
                <?php else : ?>
                    <?php echo esc_html( $tracking_number ); ?>
                <?php endif; ?>
            </td>
            <td data-tejcart-tracking-col="status">
                <select class="tejcart-tracking-status-select"
                        data-tejcart-tracking-status
                        data-shipment-id="<?php echo esc_attr( (string) $id ); ?>"
                        data-current-status="<?php echo esc_attr( $status ); ?>"
                        aria-label="<?php esc_attr_e( 'Change status', 'tejcart' ); ?>">
                    <?php foreach ( Shipment_Status::all() as $candidate ) : ?>
                        <option value="<?php echo esc_attr( $candidate ); ?>" <?php selected( $candidate, $status ); ?>>
                            <?php echo esc_html( self::status_label( $candidate ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="tejcart-status-pill tejcart-status-pill--<?php echo esc_attr( $status ); ?>"
                      data-tejcart-tracking-pill>
                    <?php echo esc_html( self::status_label( $status ) ); ?>
                </span>
            </td>
            <td class="tejcart-tracking-row__actions">
                <button type="button" class="button-link"
                        data-tejcart-tracking-edit
                        data-shipment-id="<?php echo esc_attr( (string) $id ); ?>"
                        data-current-carrier="<?php echo esc_attr( $carrier ); ?>"
                        data-current-number="<?php echo esc_attr( $tracking_number ); ?>"
                        title="<?php esc_attr_e( 'Edit tracking number', 'tejcart' ); ?>">
                    <?php esc_html_e( 'Edit', 'tejcart' ); ?>
                </button>
                <button type="button" class="button-link"
                        data-tejcart-tracking-repoll
                        data-shipment-id="<?php echo esc_attr( (string) $id ); ?>"
                        title="<?php esc_attr_e( 'Refresh from carrier API', 'tejcart' ); ?>">
                    <?php esc_html_e( 'Re-poll', 'tejcart' ); ?>
                </button>
                <button type="button" class="button-link button-link-delete"
                        data-tejcart-tracking-delete
                        data-shipment-id="<?php echo esc_attr( (string) $id ); ?>">
                    <?php esc_html_e( 'Delete', 'tejcart' ); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    public static function status_label( string $status ): string {
        $map = array(
            Shipment_Status::PENDING          => __( 'Pending', 'tejcart' ),
            Shipment_Status::LABEL_CREATED    => __( 'Label created', 'tejcart' ),
            Shipment_Status::SHIPPED          => __( 'Shipped', 'tejcart' ),
            Shipment_Status::IN_TRANSIT       => __( 'In transit', 'tejcart' ),
            Shipment_Status::OUT_FOR_DELIVERY => __( 'Out for delivery', 'tejcart' ),
            Shipment_Status::DELIVERED        => __( 'Delivered', 'tejcart' ),
            Shipment_Status::EXCEPTION        => __( 'Exception', 'tejcart' ),
            Shipment_Status::RETURNED         => __( 'Returned', 'tejcart' ),
        );
        return $map[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
    }

    /**
     * True only on the order-view admin page.
     */
    private function is_order_view_screen( string $hook_suffix ): bool {
        // The TejCart admin pages are sub-screens of `admin.php`, so the
        // hook suffix is the WP-generated `toplevel_page_…` or
        // `tejcart_page_…`. Match liberally on the page query parameter.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only; admin screen identification
        $page   = isset( $_GET['page'] )   ? sanitize_key( wp_unslash( (string) $_GET['page'] ) )   : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only; admin screen identification
        $action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
        return 'tejcart-orders' === $page && 'view' === $action;
    }
}
