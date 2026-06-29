<?php
/**
 * Order edit-screen disputes card.
 *
 * Injects a sidebar card under the TejCart order admin screen that
 * surfaces every dispute filed against the order, with a deep-link to
 * the centralised queue. Hooks `tejcart_admin_order_after_sidebar`,
 * which core fires inside the sidebar column of the order edit page.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order_Panel {
    public function register(): void {
        add_action( 'tejcart_admin_order_after_sidebar', array( $this, 'render' ), 20, 1 );
    }

    /**
     * @param mixed $order TejCart Order (or anything exposing get_id()).
     */
    public function render( $order ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
            return;
        }
        $order_id = (int) $order->get_id();
        if ( $order_id <= 0 ) {
            return;
        }
        if ( ! Capabilities::check() ) {
            return;
        }

        $disputes = Dispute::query( array( 'order_id' => $order_id ), 25, 0 );
        if ( empty( $disputes ) ) {
            return;
        }

        echo '<div class="tejcart-card tejcart-disputes-order-card">';
        echo '<div class="tejcart-card-header"><h2>' . esc_html__( 'Disputes', 'tejcart' ) . '</h2></div>';
        echo '<div class="tejcart-card-body">';
        echo '<ul class="tejcart-disputes-order-list">';
        foreach ( $disputes as $d ) {
            $url = admin_url( 'admin.php?page=tejcart-disputes&dispute=' . (int) $d->id );
            echo '<li>';
            echo '<a href="' . esc_url( $url ) . '">' . esc_html( ucfirst( $d->gateway ) ) . ' ' . esc_html( $d->external_id ) . '</a>';
            echo ' <span class="tejcart-pill tejcart-pill--' . esc_attr( Admin_Queue::status_tone( $d->status ) ) . '">' . esc_html( str_replace( '_', ' ', $d->status ) ) . '</span>';
            echo '<br /><small>' . esc_html(
                sprintf(
                    /* translators: 1: localised currency amount, 2: opened-at timestamp */
                    __( '%1$s opened %2$s UTC', 'tejcart' ),
                    wp_strip_all_tags( tejcart_price( (float) $d->amount, (string) $d->currency ) ),
                    $d->opened_at
                )
            );
            if ( $d->evidence_due && ! $d->is_terminal() ) {
                echo ' · ' . esc_html(
                    sprintf(
                        /* translators: %s evidence-due timestamp */
                        __( 'evidence due %s UTC', 'tejcart' ),
                        $d->evidence_due
                    )
                );
            }
            echo '</small>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div></div>';
    }
}
