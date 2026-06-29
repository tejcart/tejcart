<?php
/**
 * Save-for-later section rendered inside the cart drawer.
 *
 * Displays items the customer moved out of the active cart,
 * with one-click restore. Collapsed by default; expands on click.
 *
 * @package TejCart\Templates\Cart
 *
 * @var array $saved_items Array of saved item entries.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $saved_items ) ) {
    return;
}

$saved_count = count( $saved_items );
?>

<div class="tejcart-saved-for-later" data-tejcart-saved-section>
    <button
        type="button"
        class="tejcart-saved-for-later-toggle"
        aria-expanded="false"
        aria-controls="tejcart-saved-items-list"
    >
        <span class="tejcart-saved-for-later-toggle-label">
            <?php esc_html_e( 'Saved for later', 'tejcart' ); ?>
        </span>
        <span class="tejcart-saved-for-later-badge"><?php echo esc_html( $saved_count ); ?></span>
        <svg class="tejcart-saved-for-later-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <div id="tejcart-saved-items-list" class="tejcart-saved-for-later-items" hidden>
        <?php foreach ( $saved_items as $index => $entry ) :
            $product_id = (int) ( $entry['product_id'] ?? 0 );
            $product    = class_exists( '\\TejCart\\Product\\Product_Factory' )
                ? \TejCart\Product\Product_Factory::get_product( $product_id )
                : null;

            $name     = $product ? (string) $product->get_name() : ( $entry['name'] ?? __( 'Product', 'tejcart' ) );
            $price    = $product ? (float) $product->get_price() : (float) ( $entry['price'] ?? 0 );
            $image_id = $product ? (int) $product->get_image_id() : (int) ( $entry['image_id'] ?? 0 );
            $quantity = max( 1, (int) ( $entry['quantity'] ?? 1 ) );
            $in_stock = $product && method_exists( $product, 'is_in_stock' ) ? $product->is_in_stock() : true;
            $permalink = $product ? get_permalink( $product_id ) : '';
        ?>
            <div class="tejcart-saved-item" data-saved-index="<?php echo esc_attr( $index ); ?>" data-product-id="<?php echo esc_attr( $product_id ); ?>">
                <div class="tejcart-saved-item-thumb">
                    <?php if ( $image_id ) : ?>
                        <?php echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'alt' => esc_attr( $name ), 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
                    <?php else : ?>
                        <span class="tejcart-saved-item-thumb-placeholder" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 5h18v14H3z"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-8 8"/></svg>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="tejcart-saved-item-info">
                    <?php if ( $permalink ) : ?>
                        <a class="tejcart-saved-item-name" href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $name ); ?></a>
                    <?php else : ?>
                        <span class="tejcart-saved-item-name"><?php echo esc_html( $name ); ?></span>
                    <?php endif; ?>

                    <span class="tejcart-saved-item-meta">
                        <?php if ( $price > 0 ) : ?>
                            <span class="tejcart-saved-item-price"><?php echo wp_kses_post( tejcart_price( $price ) ); ?></span>
                        <?php endif; ?>
                        <?php if ( $quantity > 1 ) : ?>
                            <span class="tejcart-saved-item-qty">&times;<?php echo esc_html( $quantity ); ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="tejcart-saved-item-actions">
                    <?php if ( $in_stock ) : ?>
                        <button
                            type="button"
                            class="tejcart-saved-item-restore"
                            data-saved-index="<?php echo esc_attr( $index ); ?>"
                            aria-label="<?php
                            /* translators: %s: product name. */
                            echo esc_attr( sprintf( __( 'Move %s back to cart', 'tejcart' ), $name ) ); ?>"
                        >
                            <?php esc_html_e( 'Move to cart', 'tejcart' ); ?>
                        </button>
                    <?php else : ?>
                        <span class="tejcart-saved-item-oos"><?php esc_html_e( 'Out of stock', 'tejcart' ); ?></span>
                    <?php endif; ?>

                    <button
                        type="button"
                        class="tejcart-saved-item-remove"
                        data-saved-index="<?php echo esc_attr( $index ); ?>"
                        aria-label="<?php
                        /* translators: %s: product name. */
                        echo esc_attr( sprintf( __( 'Remove %s from saved items', 'tejcart' ), $name ) ); ?>"
                    ></button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
