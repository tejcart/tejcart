<?php
/**
 * Shipping methods partial.
 *
 * Rendered inline on checkout load and re-rendered via AJAX whenever
 * the customer's address fields change so the available methods and
 * their costs always reflect the current destination.
 *
 * @package TejCart\Templates\Checkout
 *
 * @var \TejCart\Cart\Cart                                       $cart    The cart instance.
 * @var \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method[] $methods Available methods.
 * @var string                                                   $chosen  Currently chosen method ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $methods ) ) :

    $tejcart_has_address_local = isset( $has_address ) ? (bool) $has_address : false;
    ?>
    <p class="tejcart-notice<?php echo $tejcart_has_address_local ? ' tejcart-notice--warning' : ' tejcart-notice--info'; ?>">
        <?php
        if ( $tejcart_has_address_local ) {
            esc_html_e( 'No shipping methods are available for your address. Please update your address details above.', 'tejcart' );
        } else {
            esc_html_e( 'Enter your address above to see shipping options.', 'tejcart' );
        }
        ?>
    </p>
<?php else : ?>
    <ul class="tejcart-shipping-method-list">
        <?php foreach ( $methods as $tejcart_index => $tejcart_method ) :
            $tejcart_method_id    = method_exists( $tejcart_method, 'get_id' )    ? $tejcart_method->get_id()    : '';
            $tejcart_method_title = method_exists( $tejcart_method, 'get_title' ) ? $tejcart_method->get_title() : $tejcart_method_id;
            $tejcart_method_eta   = method_exists( $tejcart_method, 'get_eta' )   ? (string) $tejcart_method->get_eta()   : '';
            $tejcart_method_cost  = method_exists( $tejcart_method, 'calculate' ) ? (float) $tejcart_method->calculate( $cart ) : 0.0;
            // `calculate()` returns the raw base-currency rate. Run it through
            // the same FINAL shipping filter the cart calculator uses
            // (`tejcart_calculated_shipping_with_classes`) so multi-currency
            // conversion (the currency-switcher module) applies exactly once to
            // the listed cost — matching the converted cart total. Using the
            // final filter (not `tejcart_calculated_shipping`) is deliberate:
            // the switcher converts on the final filter only, to avoid the
            // double-conversion that hooking both would cause. On a
            // single-currency store the filter is a passthrough, and conversion
            // is monotonic so the cheapest-first sort order is preserved.
            $tejcart_method_cost  = (float) apply_filters( 'tejcart_calculated_shipping_with_classes', $tejcart_method_cost, $cart );
            $tejcart_checked      = ( $chosen === $tejcart_method_id ) || ( '' === $chosen && 0 === $tejcart_index );
            ?>
            <li class="tejcart-shipping-method">
                <label>
                    <input type="radio"
                           name="tejcart_shipping_method"
                           value="<?php echo esc_attr( $tejcart_method_id ); ?>"
                           <?php checked( $tejcart_checked ); ?> />
                    <span class="tejcart-shipping-method-body">
                        <span class="tejcart-shipping-method-title"><?php echo esc_html( $tejcart_method_title ); ?></span>
                        <?php if ( $tejcart_method_eta ) : ?>
                            <span class="tejcart-shipping-method-meta">
                                <svg class="tejcart-shipping-method-eta-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 14 14" aria-hidden="true" focusable="false">
                                    <circle cx="7" cy="7" r="5.5" fill="none" stroke="currentColor" stroke-width="1.3"/>
                                    <path d="M7 4v3.2L9.2 8.6" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span><?php echo esc_html( $tejcart_method_eta ); ?></span>
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="tejcart-shipping-method-cost <?php echo $tejcart_method_cost <= 0 ? 'is-free' : ''; ?>">
                        <?php
                        if ( $tejcart_method_cost <= 0 ) {
                            esc_html_e( 'Free', 'tejcart' );
                        } else {
                            echo wp_kses_post( tejcart_price( $tejcart_method_cost ) );
                        }
                        ?>
                    </span>
                </label>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif;
