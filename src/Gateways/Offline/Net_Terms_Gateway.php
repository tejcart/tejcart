<?php
/**
 * Net Terms payment gateway.
 *
 * Allows B2B customers whose company has net-15/30/60/90 payment
 * terms to place orders on credit. The order is created with an
 * "on-hold" status and a due date stored as order meta.
 *
 * @package TejCart\Gateways\Offline
 */

declare( strict_types=1 );

namespace TejCart\Gateways\Offline;

use TejCart\Gateways\Abstract_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Net_Terms_Gateway extends Abstract_Gateway {
    public function __construct() {
        $this->id          = 'net_terms';
        $this->title       = __( 'Net Terms', 'tejcart' );
        $this->description = __( 'Pay within the agreed payment terms.', 'tejcart' );
        $this->supports    = array( 'products' );

        parent::__construct();

        add_action( 'tejcart_thankyou_' . $this->id, array( $this, 'render_terms_info' ), 10, 1 );
    }

    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'     => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable Net Terms as a payment method for B2B customers.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'       => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Net Terms', 'tejcart' ),
            ),
            'description' => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Pay within your agreed payment terms. An invoice will be sent with the order.', 'tejcart' ),
            ),
            'reminder_days_before' => array(
                'type'        => 'text',
                'title'       => __( 'Payment reminder (days before due)', 'tejcart' ),
                'description' => __( 'Send a payment reminder email this many days before the due date. Set 0 to disable.', 'tejcart' ),
                'default'     => '5',
            ),
        );
    }

    /**
     * Only available to logged-in B2B company members whose company
     * has non-immediate payment terms.
     */
    public function is_available(): bool {
        if ( ! parent::is_available() ) {
            return false;
        }

        $company = $this->get_current_company();
        if ( ! $company ) {
            return false;
        }

        if ( ! $company->is_active() ) {
            return false;
        }

        if ( 'immediate' === $company->get_payment_terms() ) {
            return false;
        }

        return (bool) apply_filters( 'tejcart_net_terms_is_available', true, $company, $this );
    }

    /** @return array{result: string, redirect: string, message?: string} */
    public function process_payment( int $order_id ): array {
        $order = new \TejCart\Order\Order( $order_id );

        if ( ! $order->get_id() ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Order not found.', 'tejcart' ),
            );
        }

        $company  = $this->get_current_company();
        $terms    = $company ? $company->get_payment_terms() : 'net-30';
        $days     = $this->terms_to_days( $terms );
        $due_date = gmdate( 'Y-m-d', time() + ( $days * DAY_IN_SECONDS ) );

        if ( function_exists( 'tejcart_update_order_meta' ) ) {
            tejcart_update_order_meta( $order_id, '_net_terms_days', (string) $days );
            tejcart_update_order_meta( $order_id, '_net_terms_due_date', $due_date );
            tejcart_update_order_meta( $order_id, '_payment_terms', $terms );
        }

        if ( $company && function_exists( 'tejcart_update_order_meta' ) ) {
            tejcart_update_order_meta( $order_id, '_b2b_company_id', (string) $company->get_id() );
        }

        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: 1: payment terms, 2: due date. */
                __( 'Awaiting payment via %1$s. Invoice due by %2$s.', 'tejcart' ),
                $terms,
                $due_date
            )
        );

        $this->maybe_schedule_reminder( $order_id, $due_date );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Render payment-terms info on the thank-you page.
     *
     * @param int $order_id Order ID.
     */
    public function render_terms_info( $order_id ): void {
        $due_date = function_exists( 'tejcart_get_order_meta' )
            ? tejcart_get_order_meta( (int) $order_id, '_net_terms_due_date', true )
            : '';
        $terms = function_exists( 'tejcart_get_order_meta' )
            ? tejcart_get_order_meta( (int) $order_id, '_payment_terms', true )
            : '';

        if ( '' === $due_date && '' === $terms ) {
            return;
        }

        echo '<section class="tejcart-net-terms-info">';
        echo '<h2>' . esc_html__( 'Payment Terms', 'tejcart' ) . '</h2>';
        if ( '' !== $terms ) {
            echo '<p>' . sprintf(
                /* translators: %s: payment terms (e.g. "net-30"). */
                esc_html__( 'Payment method: %s', 'tejcart' ),
                '<strong>' . esc_html( $terms ) . '</strong>'
            ) . '</p>';
        }
        if ( '' !== $due_date ) {
            echo '<p>' . sprintf(
                /* translators: %s: due date. */
                esc_html__( 'Payment due by: %s', 'tejcart' ),
                '<strong>' . esc_html( $due_date ) . '</strong>'
            ) . '</p>';
        }
        echo '</section>';
    }

    private function get_return_url( \TejCart\Order\Order $order ): string {
        $page_id = (int) get_option( 'tejcart_thankyou_page_id', 0 );
        $url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        return add_query_arg(
            array(
                'order_id'  => $order->get_id(),
                'order_key' => method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '',
            ),
            $url
        );
    }

    /**
     * @return \TejCart\B2B\Company|null
     */
    private function get_current_company() {
        if ( ! function_exists( 'get_current_user_id' ) || 0 === get_current_user_id() ) {
            return null;
        }
        if ( ! class_exists( '\\TejCart\\B2B\\Member_Repository' ) ) {
            return null;
        }

        $member = \TejCart\B2B\Member_Repository::find_by_user( get_current_user_id() );
        if ( ! $member || ! $member->is_active() ) {
            return null;
        }

        return \TejCart\B2B\Company_Repository::find( $member->get_company_id() );
    }

    private function terms_to_days( string $terms ): int {
        $map = array(
            'net-15' => 15,
            'net-30' => 30,
            'net-60' => 60,
            'net-90' => 90,
        );

        return $map[ $terms ] ?? 30;
    }

    private function maybe_schedule_reminder( int $order_id, string $due_date ): void {
        $days_before = (int) $this->get_option( 'reminder_days_before', '5' );
        if ( $days_before <= 0 ) {
            return;
        }

        $remind_at = strtotime( $due_date ) - ( $days_before * DAY_IN_SECONDS );
        if ( $remind_at <= time() ) {
            return;
        }

        if ( function_exists( 'wp_schedule_single_event' ) ) {
            wp_schedule_single_event( $remind_at, 'tejcart_net_terms_payment_reminder', array( $order_id ) );
        }
    }
}
