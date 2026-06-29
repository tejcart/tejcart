<?php
/**
 * Customer "Track my order" template.
 *
 * Override in a theme by copying this file to:
 *   {theme}/tejcart/order-tracking/track-order.php
 *
 * Available variables:
 *   string $title    Heading copy (filterable via shortcode atts).
 *   string $submit   Submit button copy.
 *   string $form_id  Unique DOM id for this form instance.
 *
 * @package TejCart\Tier2\Order_Tracking\Frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** @var string $title */
/** @var string $submit */
/** @var string $form_id */
$title   = isset( $title )   ? (string) $title   : '';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope local passed from caller
$submit  = isset( $submit )  ? (string) $submit  : __( 'Track', 'tejcart' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope local passed from caller
$form_id = isset( $form_id ) ? (string) $form_id : 'tejcart-track-order';
?>
<div class="tejcart-track-order" data-tejcart-track-root>
    <?php if ( '' !== $title ) : ?>
        <h2 class="tejcart-track-order__title"><?php echo esc_html( $title ); ?></h2>
    <?php endif; ?>

    <form class="tejcart-track-order__form" id="<?php echo esc_attr( $form_id ); ?>"
          data-tejcart-track-form novalidate>
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
