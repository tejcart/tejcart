<?php
/**
 * Additional information tab body.
 *
 * Renders the weight, dimensions (when present) and any non-variation
 * product attributes flagged as visible.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product    Product instance.
 * @var array<int, array<string, mixed>>                $attributes Visible attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tejcart_weight_raw = method_exists( $product, 'get_weight' ) ? $product->get_weight() : '';
$tejcart_weight     = ( '' !== $tejcart_weight_raw && (float) $tejcart_weight_raw > 0 )
    ? tejcart_format_weight( $tejcart_weight_raw )
    : '';

$tejcart_dim = method_exists( $product, 'get_dimensions' )
    ? tejcart_format_dimensions( $product->get_dimensions() )
    : '';

$tejcart_attrs = isset( $attributes ) && is_array( $attributes ) ? $attributes : array();
?>
<dl class="tejcart-product-attributes">
    <?php if ( '' !== $tejcart_weight ) : ?>
        <dt><?php esc_html_e( 'Weight', 'tejcart' ); ?></dt>
        <dd><?php echo esc_html( $tejcart_weight ); ?></dd>
    <?php endif; ?>

    <?php if ( '' !== $tejcart_dim ) : ?>
        <dt><?php esc_html_e( 'Dimensions', 'tejcart' ); ?></dt>
        <dd><?php echo esc_html( $tejcart_dim ); ?></dd>
    <?php endif; ?>

    <?php foreach ( $tejcart_attrs as $tejcart_attr ) :
        if ( empty( $tejcart_attr['name'] ) || empty( $tejcart_attr['values'] ) ) {
            continue;
        }
        $tejcart_values = is_array( $tejcart_attr['values'] )
            ? array_map( 'strval', $tejcart_attr['values'] )
            : array( (string) $tejcart_attr['values'] );
        $tejcart_label  = (string) $tejcart_attr['name'];

        if ( 0 === strpos( $tejcart_label, 'pa_' ) ) {
            $tejcart_label = substr( $tejcart_label, 3 );
        }
        $tejcart_label = ucwords( str_replace( array( '-', '_' ), ' ', $tejcart_label ) );
        ?>
        <dt><?php echo esc_html( $tejcart_label ); ?></dt>
        <dd><?php echo esc_html( implode( ', ', $tejcart_values ) ); ?></dd>
    <?php endforeach; ?>
</dl>
