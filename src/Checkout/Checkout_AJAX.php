<?php
/**
 * Checkout address AJAX handlers.
 *
 * @package TejCart\Checkout
 */

declare( strict_types=1 );

namespace TejCart\Checkout;

use TejCart\Cart\Cart_Ajax;
use TejCart\Shipping\Shipping_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AJAX requests that re-render the shipping method list and
 * the state/province options whenever the customer changes their
 * address on the checkout page.
 */
class Checkout_AJAX {
    /**
     * Register the AJAX handlers.
     */
    public function register() {
        add_action( 'wp_ajax_tejcart_refresh_shipping_methods', array( $this, 'refresh_shipping_methods' ) );
        add_action( 'wp_ajax_nopriv_tejcart_refresh_shipping_methods', array( $this, 'refresh_shipping_methods' ) );
        add_action( 'wp_ajax_tejcart_set_shipping_method', array( $this, 'set_shipping_method' ) );
        add_action( 'wp_ajax_nopriv_tejcart_set_shipping_method', array( $this, 'set_shipping_method' ) );
    }

    /**
     * Return the shipping methods HTML for the posted destination.
     */
    public function refresh_shipping_methods() {
        if ( false === check_ajax_referer( 'tejcart_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }

        // Defence-in-depth: the cart AJAX layer pairs the nonce with a
        // same-origin Origin/Referer check. Mirror it here so a leaked
        // nonce alone can't be cross-origin replayed to mutate the
        // victim's persisted shipping_destination (M-3).
        if ( ! Cart_Ajax::verify_origin() ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }

        $country  = isset( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '';
        $state    = isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
        $postcode = isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '';
        $city     = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
        $line1    = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';

        if ( '' === $country ) {
            $country = (string) get_option( 'tejcart_store_country', 'US' );
        }

        if ( function_exists( 'tejcart_tax_log' ) ) {
            tejcart_tax_log(
                'tax_registry',
                'checkout_ajax: tejcart_refresh_shipping_methods received',
                array(
                    'country'  => $country,
                    'state'    => $state,
                    'postcode' => $postcode,
                )
            );
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;

        if ( ! $cart ) {
            wp_send_json_success(
                array(
                    'needs_shipping' => false,
                    'html'           => '',
                    'summary_html'   => '',
                )
            );
        }

        if ( method_exists( $cart, 'get_customer' ) ) {
            $customer = $cart->get_customer();
            if ( is_object( $customer ) ) {
                if ( method_exists( $customer, 'set_shipping_country' ) ) {
                    $customer->set_shipping_country( $country );
                }
                if ( method_exists( $customer, 'set_shipping_state' ) ) {
                    $customer->set_shipping_state( $state );
                }
                if ( method_exists( $customer, 'set_shipping_postcode' ) ) {
                    $customer->set_shipping_postcode( $postcode );
                }
                if ( 'billing_address' === (string) get_option( 'tejcart_tax_based_on', 'billing_address' ) ) {
                    if ( method_exists( $customer, 'set_billing_country' ) ) {
                        $customer->set_billing_country( $country );
                    }
                    if ( method_exists( $customer, 'set_billing_state' ) ) {
                        $customer->set_billing_state( $state );
                    }
                    if ( method_exists( $customer, 'set_billing_postcode' ) ) {
                        $customer->set_billing_postcode( $postcode );
                    }
                }
            }
        }

        // Cart::get_customer() doesn't currently exist, so the customer
        // setters above silently no-op and the calculator never sees the
        // posted state/postcode — which makes live tax providers reject
        // the call at the address-completeness gate. Persist the
        // destination directly on the cart session as the authoritative
        // copy that Cart_Calculator falls back to.
        if ( method_exists( $cart, 'set_shipping_destination' ) ) {
            $cart->set_shipping_destination( $country, $state, $postcode, $city, $line1 );
        }

        // Address changed — drop the cached totals so the calculator
        // picks up the new destination on its next pass. We deliberately
        // avoid recalculate() here: it would batch-fetch every product,
        // reset every Cart_Item's lazy cache, and re-validate every
        // applied coupon on a single postcode keystroke. None of that
        // work is required to recompute shipping + tax for a new address
        // — Cart_Calculator already reads the live destination on the
        // next calculate() call.
        if ( method_exists( $cart, 'invalidate_totals' ) ) {
            $cart->invalidate_totals();
        } elseif ( method_exists( $cart, 'recalculate' ) ) {
            // Backwards-compat for cart shims (sibling plugins, test
            // doubles) that pre-date the lighter API.
            $cart->recalculate();
        }

        // Force tax computation now (rather than waiting for the template
        // to call get_tax_total()) so the resulting value is visible to
        // the do_action hook below and to any observers that need to see
        // the recomputed total before the JSON response is built.
        $tejcart_tax_total      = method_exists( $cart, 'get_tax_total' ) ? (float) $cart->get_tax_total() : 0.0;
        $tejcart_shipping_total = method_exists( $cart, 'get_shipping_total' ) ? (float) $cart->get_shipping_total() : 0.0;
        $tejcart_grand_total    = method_exists( $cart, 'get_total' ) ? (float) $cart->get_total() : 0.0;

        if ( function_exists( 'tejcart_tax_log' ) ) {
            tejcart_tax_log(
                'tax_registry',
                'checkout_ajax: cart recalculated for address',
                array(
                    'country'        => $country,
                    'state'          => $state,
                    'postcode'       => $postcode,
                    'tax_total'      => $tejcart_tax_total,
                    'shipping_total' => $tejcart_shipping_total,
                    'grand_total'    => $tejcart_grand_total,
                )
            );
        }

        /**
         * Fires after the cart has been recomputed in response to a
         * customer address change on the checkout page. Observability
         * tools (Query Monitor, custom audit logs, third-party analytics
         * exporters) can listen on this to confirm tax + shipping were
         * recalculated and to capture the resulting totals.
         *
         * @param object $cart           Cart instance.
         * @param string $country        Two-letter destination country.
         * @param string $state          Destination state / province.
         * @param string $postcode       Destination postcode.
         * @param float  $tax_total      Tax amount after recompute.
         * @param float  $shipping_total Shipping amount after recompute.
         */
        do_action(
            'tejcart_after_checkout_address_recalculate',
            $cart,
            $country,
            $state,
            $postcode,
            $tejcart_tax_total,
            $tejcart_shipping_total
        );

        $needs_shipping = method_exists( $cart, 'needs_shipping' ) ? (bool) $cart->needs_shipping() : false;

        if ( ! $needs_shipping ) {
            wp_send_json_success(
                array(
                    'needs_shipping' => false,
                    'html'           => '',
                    'summary_html'   => $this->render_order_summary( $cart ),
                )
            );
        }

        $manager = new Shipping_Manager();
        $methods = $manager->get_available_methods( $country, $state, $cart, $postcode );
        $chosen  = method_exists( $cart, 'get_chosen_shipping_method' ) ? (string) $cart->get_chosen_shipping_method() : '';

        if ( '' !== $chosen ) {
            $still_available = false;
            foreach ( $methods as $method_instance ) {
                if ( method_exists( $method_instance, 'get_id' )
                     && $method_instance->get_id() === $chosen ) {
                    $still_available = true;
                    break;
                }
            }
            if ( ! $still_available && method_exists( $cart, 'set_chosen_shipping_method' ) ) {
                $cart->set_chosen_shipping_method( '' );
                $chosen = '';
            }
        }

        $has_address = ( '' !== $state ) || ( '' !== $postcode );

        ob_start();
        $template = TEJCART_PLUGIN_DIR . 'templates/checkout/_shipping-methods.php';
        if ( file_exists( $template ) ) {
            include $template;
        }
        $html = (string) ob_get_clean();

        wp_send_json_success(
            array(
                'needs_shipping' => true,
                'html'           => $html,
                'count'          => count( $methods ),
                'summary_html'   => $this->render_order_summary( $cart ),
            )
        );
    }

    /**
     * Persist the chosen shipping method on the cart session and return
     * the recomputed order summary.
     *
     * The checkout JS calls this on every radio-button toggle in the
     * shipping-method picker, so the sidebar totals (shipping + tax +
     * grand total) reflect the buyer's current selection BEFORE they
     * hit "Complete order" — without this round-trip the summary keeps
     * showing whichever method Cart_Calculator picked as the default
     * (typically Flat Rate / the cheapest), even after the buyer
     * picked something else.
     */
    public function set_shipping_method() {
        if ( false === check_ajax_referer( 'tejcart_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }

        if ( ! Cart_Ajax::verify_origin() ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'tejcart' ) ),
                403
            );
        }

        $method = isset( $_POST['method'] ) ? sanitize_text_field( wp_unslash( $_POST['method'] ) ) : '';
        if ( '' === $method ) {
            wp_send_json_error(
                array( 'message' => __( 'A shipping method is required.', 'tejcart' ) ),
                400
            );
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( ! $cart || ! method_exists( $cart, 'set_chosen_shipping_method' ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Cart is not available.', 'tejcart' ) ),
                500
            );
        }

        // Validate the posted id against the methods currently available
        // for the cart's destination. Without this gate a buyer (or a
        // tampered client) could persist any arbitrary string and have it
        // survive into the order. Mirrors Shipping_Method_Capture::method_is_available.
        $country  = '';
        $state    = '';
        $postcode = '';
        if ( method_exists( $cart, 'get_shipping_destination' ) ) {
            $dest     = $cart->get_shipping_destination();
            $country  = isset( $dest['country'] )  ? (string) $dest['country']  : '';
            $state    = isset( $dest['state'] )    ? (string) $dest['state']    : '';
            $postcode = isset( $dest['postcode'] ) ? (string) $dest['postcode'] : '';
        }
        if ( '' === $country ) {
            $country = (string) get_option( 'tejcart_store_country', 'US' );
        }

        $manager   = new Shipping_Manager();
        $available = $manager->get_available_methods( $country, $state, $cart, $postcode );
        $matched   = false;
        foreach ( $available as $instance ) {
            if ( is_object( $instance ) && method_exists( $instance, 'get_id' ) && $instance->get_id() === $method ) {
                $matched = true;
                break;
            }
        }
        if ( ! $matched ) {
            wp_send_json_error(
                array( 'message' => __( 'That shipping method is not available for your address.', 'tejcart' ) ),
                400
            );
        }

        $cart->set_chosen_shipping_method( $method );

        // Shipping method changed — same reasoning as
        // refresh_shipping_methods(): the calculator already reads the
        // chosen method on its next pass, so we just drop the totals
        // cache and let the next get_*_total() lazy-recompute. Avoids
        // the per-keystroke cost of a full recalculate().
        if ( method_exists( $cart, 'invalidate_totals' ) ) {
            $cart->invalidate_totals();
        } elseif ( method_exists( $cart, 'recalculate' ) ) {
            $cart->recalculate();
        }

        wp_send_json_success(
            array(
                'method'       => $method,
                'summary_html' => $this->render_order_summary( $cart ),
            )
        );
    }

    /**
     * Render the order-summary partial for AJAX consumption.
     *
     * The same partial is included into both the mobile accordion and
     * the desktop sticky sidebar at full-page render. After the customer
     * updates their address we re-run it server-side so the tax row
     * (and total) reflect whatever the active live tax provider — or the
     * fallback rate table — now computes.
     */
    private function render_order_summary( $cart ): string {
        $template = TEJCART_PLUGIN_DIR . 'templates/checkout/_order-summary.php';
        if ( ! file_exists( $template ) ) {
            return '';
        }

        $tejcart_summary_context = 'ajax';

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
