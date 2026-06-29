<?php
/**
 * Empty cart template.
 *
 * Displayed when the shopping cart has no items. Uses an inline SVG
 * illustration (no external image request) and a friendly CTA.
 *
 * @package TejCart\Templates\Cart
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fires before the empty cart message.
 */
do_action( 'tejcart_before_cart_empty' );
?>

<section class="tejcart-cart tejcart-cart--empty" aria-labelledby="tejcart-cart-empty-title">

    <div class="tejcart-cart-empty">
        <div class="tejcart-cart-empty-illustration" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="36" cy="78" r="5"/>
                <circle cx="70" cy="78" r="5"/>
                <path d="M10 14h10l8 46a6 6 0 0 0 6 5h34a6 6 0 0 0 6-5l5-28H28"/>
                <path d="M44 34l6 6 14-14" opacity="0.4"/>
            </svg>
        </div>

        <h1 class="tejcart-cart-empty-title" id="tejcart-cart-empty-title">
            <?php esc_html_e( 'Your cart is empty', 'tejcart' ); ?>
        </h1>

        <p class="tejcart-cart-empty-desc">
            <?php esc_html_e( 'Looks like you haven\'t added anything yet. Browse the shop to find something you\'ll love.', 'tejcart' ); ?>
        </p>

        <a class="tejcart-button tejcart-button--lg tejcart-continue-shopping-btn" href="<?php echo esc_url( apply_filters( 'tejcart_continue_shopping_url', home_url( '/shop/' ) ) ); ?>">
            <?php esc_html_e( 'Start shopping', 'tejcart' ); ?>
        </a>
    </div>

    <?php
    /**
     * Recovery rail for the empty cart.
     *
     * Re-uses the recently-viewed shortcode so empty-cart visitors land on a
     * familiar product strip instead of a dead-end. Wrapped in a filter so
     * merchants can swap the rail for a featured collection or disable it
     * entirely.
     *
     * @param string $shortcode Shortcode markup, return empty string to hide.
     */
    $tejcart_empty_recovery = (string) apply_filters(
        'tejcart_empty_cart_recovery_shortcode',
        '[tejcart_recently_viewed limit="6"]'
    );

    if ( '' !== trim( $tejcart_empty_recovery ) && shortcode_exists( 'tejcart_recently_viewed' ) ) {
        $tejcart_empty_rail_html = do_shortcode( $tejcart_empty_recovery );
        if ( '' !== trim( wp_strip_all_tags( $tejcart_empty_rail_html ) ) ) {
            ?>
            <div class="tejcart-cart-empty-recovery" aria-label="<?php esc_attr_e( 'Recently viewed products', 'tejcart' ); ?>">
                <h2 class="tejcart-cart-empty-recovery-title">
                    <?php esc_html_e( 'Pick up where you left off', 'tejcart' ); ?>
                </h2>
                <?php
                // Defence-in-depth: shortcode output may be extended by
                // third-party plugins via the `tejcart_empty_cart_recovery_shortcode`
                // filter, so wrap in wp_kses_post() rather than trusting the
                // upstream renderer's escaping contract.
                echo wp_kses_post( $tejcart_empty_rail_html );
                ?>
            </div>
            <?php
        }
    }
    ?>

</section>

<?php
/**
 * Fires after the empty cart message.
 */
do_action( 'tejcart_after_cart_empty' );
