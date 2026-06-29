<?php
/**
 * Payment methods template.
 *
 * Lists available payment gateways as accordion-style cards. Each card
 * has its own radio control inside the label, so the entire card is a
 * tap target. The selected card reveals the gateway's payment_fields()
 * output. The express checkout buttons are rendered above this list
 * by checkout.php (context: checkout).
 *
 * @package TejCart\Templates\Checkout
 *
 * @var \TejCart\Gateways\Abstract_Gateway[] $gateways Available payment gateways.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fires before the payment methods list.
 */
do_action( 'tejcart_before_payment_methods' );

if ( empty( $gateways ) ) : ?>

    <p class="tejcart-no-payment-methods">
        <?php esc_html_e( 'No payment methods are currently available. Please contact the store owner.', 'tejcart' ); ?>
    </p>

<?php else :
    /**
     * Default card-brand logo set rendered next to the Credit / Debit
     * Card method title. Each entry maps a brand slug to its display
     * label; the artwork is the matching SVG shipped under
     * assets/images/icons/credit-cards/<slug>.svg and is rendered as an
     * <img>, so the bundled brand logos paint pixel-accurate (gradients,
     * even-odd fills) instead of being flattened by the inline-SVG kses
     * allowlist. The wrapping list is aria-hidden, so the logos are
     * decorative. A theme/plugin can still hook tejcart_payment_method_icons
     * to return raw <svg> markup keyed by slug — that path is preserved.
     *
     * @param array<string,string> $icons Map of brand slug => display label.
     */
    $tejcart_default_card_icons = array(
        'visa'       => __( 'Visa', 'tejcart' ),
        'mastercard' => __( 'Mastercard', 'tejcart' ),
        'amex'       => __( 'American Express', 'tejcart' ),
        'discover'   => __( 'Discover', 'tejcart' ),
    );

    /**
     * Redirect-style gateways are called out with a small muted
     * "You'll be redirected…" helper line under the method title so
     * the customer knows the click doesn't stay on-page. Extended via
     * the tejcart_payment_method_is_redirect filter.
     */
    $tejcart_redirect_gateways = array( 'tejcart_paypal' );

    /**
     * Whether PayPal Pay Later is enabled — if so we render a secondary
     * marketing-weight line ("4 interest-free payments") inside the
     * PayPal method title row.
     */
    $tejcart_paypal_gateway_obj  = function_exists( 'tejcart' ) ? tejcart()->gateways()->get_gateway( 'tejcart_paypal' ) : null;
    $tejcart_paylater_enabled_pm = $tejcart_paypal_gateway_obj
        && $tejcart_paypal_gateway_obj->is_available()
        && 'yes' === $tejcart_paypal_gateway_obj->get_option( 'enable_paylater', 'yes' );
    ?>

    <ul class="tejcart-payment-methods-list">
        <?php
        $first = true;
        foreach ( $gateways as $gateway ) :
            $gateway_id    = $gateway->get_id();
            $gateway_title = $gateway->get_title();
            $li_classes    = array(
                'tejcart-payment-method',
                'tejcart-payment-method-' . sanitize_html_class( $gateway_id ),
            );
            if ( $first ) {
                $li_classes[] = 'is-active';
                $li_classes[] = 'active';
            }

            $tejcart_pm_icons = array();
            if ( 'tejcart_card' === $gateway_id ) {
                $tejcart_pm_icons = $tejcart_default_card_icons;
            }
            /**
             * Filter the inline logo SVGs rendered next to a payment
             * method title. Keyed by brand slug => SVG markup.
             *
             * @param array  $icons      Map of brand slug => SVG markup.
             * @param string $gateway_id Gateway identifier.
             */
            $tejcart_pm_icons = (array) apply_filters( 'tejcart_payment_method_icons', $tejcart_pm_icons, $gateway_id );

            $tejcart_pm_hints = array();
            $tejcart_is_redirect = in_array( $gateway_id, $tejcart_redirect_gateways, true );
            if ( method_exists( $gateway, 'is_redirect' ) ) {
                $tejcart_is_redirect = (bool) $gateway->is_redirect();
            }
            /**
             * Filter whether a gateway is treated as redirect-style.
             * Redirect-style gateways get a "You'll be redirected…" hint
             * rendered under the method title.
             *
             * @param bool   $is_redirect Current value.
             * @param string $gateway_id  Gateway identifier.
             */
            $tejcart_is_redirect = (bool) apply_filters( 'tejcart_payment_method_is_redirect', $tejcart_is_redirect, $gateway_id );
            if ( $tejcart_is_redirect ) {
                $tejcart_pm_hints[] = array(
                    'text' => sprintf(
                        /* translators: %s: gateway display title (e.g. PayPal). */
                        __( 'You will be redirected to %s to complete payment.', 'tejcart' ),
                        $gateway_title
                    ),
                    'tone' => 'muted',
                );
            }

            $tejcart_pm_render_paylater = ( 'tejcart_paypal' === $gateway_id && $tejcart_paylater_enabled_pm );
            /**
             * Filter the secondary hint lines rendered under a payment
             * method title. Each entry: ['text' => string, 'tone' => 'muted'|'marketing'].
             *
             * @param array  $hints      Hint entries.
             * @param string $gateway_id Gateway identifier.
             */
            $tejcart_pm_hints = (array) apply_filters( 'tejcart_payment_method_hint', $tejcart_pm_hints, $gateway_id );
        ?>
            <li class="<?php echo esc_attr( implode( ' ', $li_classes ) ); ?>">

                <label for="tejcart_payment_method_<?php echo esc_attr( $gateway_id ); ?>" id="tejcart_payment_method_label_<?php echo esc_attr( $gateway_id ); ?>">
                    <input
                        type="radio"
                        id="tejcart_payment_method_<?php echo esc_attr( $gateway_id ); ?>"
                        name="tejcart_payment_method"
                        value="<?php echo esc_attr( $gateway_id ); ?>"
                        class="tejcart-payment-method-radio"
                        aria-controls="tejcart_payment_method_fields_<?php echo esc_attr( $gateway_id ); ?>"
                        <?php checked( $first, true ); ?>
                    />
                    <span class="tejcart-payment-method-title-wrap">
                        <span class="tejcart-payment-method-title"><?php echo esc_html( $gateway_title ); ?></span>
                        <?php foreach ( $tejcart_pm_hints as $tejcart_pm_hint ) :
                            $tejcart_pm_hint_text = isset( $tejcart_pm_hint['text'] ) ? (string) $tejcart_pm_hint['text'] : '';
                            $tejcart_pm_hint_tone = isset( $tejcart_pm_hint['tone'] ) ? sanitize_html_class( (string) $tejcart_pm_hint['tone'] ) : 'muted';
                            if ( '' === $tejcart_pm_hint_text ) { continue; }
                            ?>
                            <span class="tejcart-payment-method-hint tejcart-payment-method-hint--<?php echo esc_attr( $tejcart_pm_hint_tone ); ?>">
                                <?php echo esc_html( $tejcart_pm_hint_text ); ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if ( $tejcart_pm_render_paylater ) :
                            $tejcart_pl_cart      = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
                            $tejcart_pl_amount    = ( $tejcart_pl_cart && method_exists( $tejcart_pl_cart, 'get_total' ) ) ? (float) $tejcart_pl_cart->get_total() : 0.0;
                            $tejcart_pl_currency  = function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : 'USD';
                            $tejcart_pl_layout    = (string) $tejcart_paypal_gateway_obj->get_option( 'paylater_style_layout', 'text' );
                            $tejcart_pl_logo      = (string) $tejcart_paypal_gateway_obj->get_option( 'paylater_style_logo_type', 'primary' );
                            $tejcart_pl_color     = (string) $tejcart_paypal_gateway_obj->get_option( 'paylater_style_text_color', 'black' );
                            ?>
                            <span class="tejcart-payment-method-hint tejcart-payment-method-hint--marketing tejcart-payment-method-hint--paylater">
                                <paypal-message class="tejcart-paylater-message tejcart-paylater-method-hint"
                                    amount="<?php echo esc_attr( (string) $tejcart_pl_amount ); ?>"
                                    currency-code="<?php echo esc_attr( $tejcart_pl_currency ); ?>"
                                    data-pp-placement="payment"
                                    data-pp-style-layout="<?php echo esc_attr( $tejcart_pl_layout ); ?>"
                                    data-pp-style-logo-type="<?php echo esc_attr( $tejcart_pl_logo ); ?>"
                                    data-pp-style-text-color="<?php echo esc_attr( $tejcart_pl_color ); ?>"
                                    data-pp-style-text-size="12"
                                    data-pp-style-text-align="left"></paypal-message>
                            </span>
                        <?php endif; ?>
                    </span>
                    <?php if ( ! empty( $tejcart_pm_icons ) ) : ?>
                        <span class="tejcart-payment-method-icons" aria-hidden="true">
                            <?php foreach ( $tejcart_pm_icons as $tejcart_pm_icon_slug => $tejcart_pm_icon_value ) :
                                $tejcart_pm_icon_slug_attr = sanitize_html_class( (string) $tejcart_pm_icon_slug );
                                $tejcart_pm_icon_value     = (string) $tejcart_pm_icon_value;

                                // A filter may still supply raw inline <svg>
                                // markup keyed by slug — keep rendering that
                                // through the icon kses allowlist as before.
                                $tejcart_pm_icon_is_svg = ( 0 === stripos( ltrim( $tejcart_pm_icon_value ), '<svg' ) );

                                // Otherwise the value is a brand label and the
                                // artwork is the bundled SVG file for the slug,
                                // rendered as an <img> (skip if it's missing).
                                $tejcart_pm_icon_rel = 'assets/images/icons/credit-cards/' . $tejcart_pm_icon_slug_attr . '.svg';
                                $tejcart_pm_icon_dir = defined( 'TEJCART_PLUGIN_DIR' ) ? TEJCART_PLUGIN_DIR : trailingslashit( dirname( __DIR__, 2 ) );
                                if ( ! $tejcart_pm_icon_is_svg && ! file_exists( $tejcart_pm_icon_dir . $tejcart_pm_icon_rel ) ) {
                                    continue;
                                }
                                ?>
                                <span class="tejcart-payment-method-icon tejcart-payment-method-icon--<?php echo esc_attr( $tejcart_pm_icon_slug_attr ); ?>">
                                    <?php if ( $tejcart_pm_icon_is_svg ) : ?>
                                        <?php echo wp_kses( $tejcart_pm_icon_value, tejcart_payment_icon_allowed_html() ); ?>
                                    <?php else : ?>
                                        <img
                                            src="<?php echo esc_url( tejcart_asset_url( $tejcart_pm_icon_rel ) ); ?>"
                                            alt="<?php echo esc_attr( $tejcart_pm_icon_value ); ?>"
                                            class="tejcart-payment-method-icon-img"
                                            width="40"
                                            height="25"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </span>
                    <?php endif; ?>
                </label>

                <div
                    class="tejcart-payment-method-fields tejcart-payment-method-fields-<?php echo esc_attr( $gateway_id ); ?>"
                    id="tejcart_payment_method_fields_<?php echo esc_attr( $gateway_id ); ?>"
                    role="region"
                    aria-labelledby="tejcart_payment_method_label_<?php echo esc_attr( $gateway_id ); ?>"
                    <?php
                    // Collapsed gateway sections are hidden visually via CSS
                    // (max-height: 0; overflow: hidden) but their focusable
                    // descendants — PayPal Smart Buttons, vaulted-method
                    // radios, the "Save this method" checkbox — remain in
                    // the keyboard tab order, so a buyer who has selected
                    // Credit / Debit Card still has to Tab through the
                    // invisible PayPal controls before landing on the card
                    // number field. `inert` removes the whole subtree from
                    // tab order + accessibility tree without changing layout;
                    // initPaymentMethods() in tejcart-checkout.js keeps this
                    // attribute in sync as the buyer switches methods.
                    if ( ! $first ) {
                        echo ' inert';
                    }
                    ?>
                >
                    <?php
                    /**
                     * Fires before a gateway's payment fields.
                     *
                     * @param string $gateway_id The gateway ID.
                     */
                    do_action( 'tejcart_before_gateway_payment_fields', $gateway_id );

                    $gateway->payment_fields();

                    /**
                     * Fires after a gateway's payment fields.
                     *
                     * @param string $gateway_id The gateway ID.
                     */
                    do_action( 'tejcart_after_gateway_payment_fields', $gateway_id );
                    ?>
                </div>

            </li>
        <?php
            $first = false;
        endforeach;
        ?>
    </ul>

<?php endif; ?>

<?php
/**
 * Fires after the payment methods list.
 */
do_action( 'tejcart_after_payment_methods' );
