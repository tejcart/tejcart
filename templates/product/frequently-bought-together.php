<?php
/**
 * Frequently Bought Together template.
 *
 * Suggests 2-3 companion products with checkboxes and a combined price,
 * allowing the customer to add the bundle to their cart in one click.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The current product instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\\TejCart\\Product\\Recommendations' ) ) {
    return;
}

$recommendations = new \TejCart\Product\Recommendations();
$fbt_ids         = $recommendations->get_frequently_bought_together( $product->get_id(), 2 );

if ( empty( $fbt_ids ) ) {
    return;
}

$fbt_products = \TejCart\Product\Product_Factory::get_products( $fbt_ids );

if ( empty( $fbt_products ) ) {
    return;
}

$current_price = (float) $product->get_price();
$total_price   = $current_price;
$fbt_items     = array();

foreach ( $fbt_ids as $fbt_id ) {
    $fbt_product = $fbt_products[ $fbt_id ] ?? null;
    if ( ! $fbt_product || ! $fbt_product->is_in_stock() || ! $fbt_product->is_purchasable() ) {
        continue;
    }
    $item_price  = (float) $fbt_product->get_price();
    $total_price += $item_price;
    $fbt_items[] = array(
        'product' => $fbt_product,
        'price'   => $item_price,
    );
}

if ( empty( $fbt_items ) ) {
    return;
}

/**
 * Filter the "Frequently bought together" heading text.
 *
 * @param string $title      The section heading.
 * @param int    $product_id The product being viewed.
 */
$section_title = (string) apply_filters(
    'tejcart_fbt_title',
    __( 'Frequently bought together', 'tejcart' ),
    $product->get_id()
);
?>

<div class="tejcart-fbt" data-tejcart-fbt data-current-product-id="<?php echo esc_attr( $product->get_id() ); ?>">

    <h3 class="tejcart-fbt-title"><?php echo esc_html( $section_title ); ?></h3>

    <div class="tejcart-fbt-items">

        <div class="tejcart-fbt-item tejcart-fbt-item--current">
            <div class="tejcart-fbt-item-checkbox">
                <input
                    type="checkbox"
                    checked
                    disabled
                    aria-label="<?php echo esc_attr( $product->get_name() ); ?>"
                />
            </div>
            <div class="tejcart-fbt-item-image">
                <?php
                $current_image = $product->get_image_id()
                    ? wp_get_attachment_image( (int) $product->get_image_id(), 'thumbnail' )
                    : '';
                if ( $current_image ) {
                    echo wp_kses_post( $current_image );
                }
                ?>
            </div>
            <div class="tejcart-fbt-item-info">
                <span class="tejcart-fbt-item-label"><?php esc_html_e( 'This item', 'tejcart' ); ?></span>
                <span class="tejcart-fbt-item-name"><?php echo esc_html( $product->get_name() ); ?></span>
                <span class="tejcart-fbt-item-price"><?php echo wp_kses_post( tejcart_price( $current_price ) ); ?></span>
            </div>
        </div>

        <?php foreach ( $fbt_items as $index => $item ) :
            $fbt_prod = $item['product'];
            $fbt_url  = method_exists( $fbt_prod, 'get_permalink' ) ? $fbt_prod->get_permalink() : '';
            ?>
            <span class="tejcart-fbt-plus" aria-hidden="true">+</span>
            <div class="tejcart-fbt-item" data-tejcart-fbt-item data-product-id="<?php echo esc_attr( $fbt_prod->get_id() ); ?>" data-price="<?php echo esc_attr( $item['price'] ); ?>">
                <div class="tejcart-fbt-item-checkbox">
                    <input
                        type="checkbox"
                        checked
                        data-tejcart-fbt-check
                        aria-label="<?php echo esc_attr( $fbt_prod->get_name() ); ?>"
                    />
                </div>
                <div class="tejcart-fbt-item-image">
                    <?php
                    $fbt_image = $fbt_prod->get_image_id()
                        ? wp_get_attachment_image( (int) $fbt_prod->get_image_id(), 'thumbnail' )
                        : '';
                    if ( $fbt_image ) {
                        echo wp_kses_post( $fbt_image );
                    }
                    ?>
                </div>
                <div class="tejcart-fbt-item-info">
                    <?php if ( $fbt_url ) : ?>
                        <a href="<?php echo esc_url( $fbt_url ); ?>" class="tejcart-fbt-item-name"><?php echo esc_html( $fbt_prod->get_name() ); ?></a>
                    <?php else : ?>
                        <span class="tejcart-fbt-item-name"><?php echo esc_html( $fbt_prod->get_name() ); ?></span>
                    <?php endif; ?>
                    <span class="tejcart-fbt-item-price"><?php echo wp_kses_post( tejcart_price( $item['price'] ) ); ?></span>
                </div>
            </div>
        <?php endforeach; ?>

    </div>

    <div class="tejcart-fbt-footer">
        <div class="tejcart-fbt-total">
            <span class="tejcart-fbt-total-label"><?php esc_html_e( 'Total for selected items:', 'tejcart' ); ?></span>
            <span class="tejcart-fbt-total-price" data-tejcart-fbt-total data-base-price="<?php echo esc_attr( $current_price ); ?>">
                <?php echo wp_kses_post( tejcart_price( $total_price ) ); ?>
            </span>
        </div>
        <button
            type="button"
            class="tejcart-button tejcart-button--primary tejcart-fbt-add-btn"
            data-tejcart-fbt-add
        >
            <?php esc_html_e( 'Add selected to cart', 'tejcart' ); ?>
        </button>
    </div>

</div>
