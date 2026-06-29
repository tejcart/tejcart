<?php
/**
 * Grouped product add-to-cart partial.
 *
 * Lists each child product with its name, price, and a per-row quantity +
 * add button. Each button reuses the standard tejcart-add-to-cart-btn JS
 * handler so no new server endpoint is needed.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Grouped_Product $product
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$children = $product->get_grouped_products();

if ( empty( $children ) ) :
    ?>
    <p class="tejcart-grouped-empty">
        <?php esc_html_e( 'This grouped product has no items configured yet.', 'tejcart' ); ?>
    </p>
    <?php
    return;
endif;
?>

<div class="tejcart-grouped-products">
    <table class="tejcart-grouped-products__table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                <th><?php esc_html_e( 'Price', 'tejcart' ); ?></th>
                <th><?php esc_html_e( 'Quantity', 'tejcart' ); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $children as $child ) :
                if ( ! $child ) {
                    continue;
                }
                $child_id           = (int) $child->get_id();
                $child_name         = $child->get_name();
                $child_price        = $child->get_price();
                $child_purchasable  = method_exists( $child, 'is_purchasable' ) ? $child->is_purchasable() : true;
                $child_in_stock     = method_exists( $child, 'is_in_stock' )    ? $child->is_in_stock()    : true;
                $row_disabled       = ! $child_in_stock || ! $child_purchasable;
                ?>
                <tr class="tejcart-grouped-products__row<?php echo $row_disabled ? ' is-disabled' : ''; ?>">
                    <td class="tejcart-grouped-products__name">
                        <a href="<?php echo esc_url( get_permalink( $child_id ) ); ?>">
                            <?php echo esc_html( $child_name ); ?>
                        </a>
                    </td>
                    <td class="tejcart-grouped-products__price">
                        <?php
                        if ( '' !== $child_price ) {
                            echo wp_kses_post( tejcart_price( (float) $child_price ) );
                        } else {
                            echo '&mdash;';
                        }
                        ?>
                    </td>
                    <td class="tejcart-grouped-products__qty">
                        <input
                            type="number"
                            class="tejcart-grouped-products__qty-input"
                            min="1"
                            step="1"
                            value="1"
                            <?php disabled( $row_disabled, true ); ?>
                            data-target="tejcart-grouped-add-<?php echo esc_attr( $child_id ); ?>"
                            aria-label="<?php
                            /* translators: %s: child product name in a grouped product. */
                            echo esc_attr( sprintf( __( 'Quantity of %s', 'tejcart' ), $child_name ) );
                            ?>"
                        />
                    </td>
                    <td class="tejcart-grouped-products__action">
                        <button
                            type="button"
                            id="tejcart-grouped-add-<?php echo esc_attr( $child_id ); ?>"
                            class="tejcart-button tejcart-add-to-cart-btn<?php echo $row_disabled ? ' is-disabled' : ''; ?>"
                            data-product-id="<?php echo esc_attr( $child_id ); ?>"
                            data-quantity="1"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'tejcart_add_to_cart_' . $child_id ) ); ?>"
                            data-default-text="<?php esc_attr_e( 'Add to cart', 'tejcart' ); ?>"
                            data-confirmed-text="<?php esc_attr_e( 'Added', 'tejcart' ); ?>"
                            <?php disabled( $row_disabled, true ); ?>
                        >
                            <span class="tejcart-add-to-cart-label"><?php esc_html_e( 'Add to cart', 'tejcart' ); ?></span>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
