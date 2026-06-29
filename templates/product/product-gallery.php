<?php
/**
 * Product gallery template with lightbox.
 *
 * Displays the main product image, a thumbnail strip, and a lightbox overlay.
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product The product instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$image_id    = $product->get_image_id();
$gallery_ids = $product->get_gallery_ids();

$all_image_ids = array();
if ( $image_id ) {
    $all_image_ids[] = $image_id;
}
if ( ! empty( $gallery_ids ) ) {
    $all_image_ids = array_merge( $all_image_ids, $gallery_ids );
}

$variation_thumbs = array();
if ( $product instanceof \TejCart\Product\Product_Types\Variable_Product ) {
    foreach ( $product->get_variations() as $variation ) {
        $vid_img = method_exists( $variation, 'get_image_id' ) ? (int) $variation->get_image_id() : 0;
        if ( ! $vid_img || in_array( $vid_img, $all_image_ids, true ) || isset( $variation_thumbs[ $vid_img ] ) ) {
            continue;
        }
        $v_attrs = method_exists( $variation, 'get_attributes' ) ? (array) $variation->get_attributes() : array();
        $variation_thumbs[ $vid_img ] = array(
            'image_id'      => $vid_img,
            'variation_id'  => (int) $variation->get_id(),
            'attributes'    => $v_attrs,
        );
    }
}

if ( empty( $all_image_ids ) && ! empty( $variation_thumbs ) ) {
    $first_v_thumb   = reset( $variation_thumbs );
    $all_image_ids[] = (int) $first_v_thumb['image_id'];
    unset( $variation_thumbs[ $first_v_thumb['image_id'] ] );
}

$product_name = $product->get_name();

$use_placeholder_main = empty( $all_image_ids );
?>

<div class="tejcart-product-gallery" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">

    <?php

    $main_size = 'tejcart-product-main';
    $main_src  = '';
    $main_full = '';

    if ( ! $use_placeholder_main ) {
        $main_src = wp_get_attachment_image_url( $all_image_ids[0], $main_size );
        if ( ! $main_src ) {
            $main_size = 'large';
            $main_src  = wp_get_attachment_image_url( $all_image_ids[0], $main_size );
        }
        $main_full = wp_get_attachment_image_url( $all_image_ids[0], 'full' );

        if ( ! $main_src ) {
            $use_placeholder_main = true;
        }
    }

    if ( $use_placeholder_main ) {
        $main_src  = tejcart_get_placeholder_image_src( 'tejcart-product-main' );
        $main_full = $main_src;
    }

    $gallery_is_on_sale = method_exists( $product, 'is_on_sale' ) ? (bool) $product->is_on_sale() : false;
    $gallery_is_featured = method_exists( $product, 'is_featured' ) ? (bool) $product->is_featured() : false;
    ?>
    <div class="tejcart-gallery-main">
        <?php if ( $gallery_is_on_sale ) : ?>
            <span class="tejcart-gallery-sale-badge" aria-label="<?php esc_attr_e( 'On sale', 'tejcart' ); ?>">
                <?php esc_html_e( 'Sale!', 'tejcart' ); ?>
            </span>
        <?php endif; ?>
        <?php if ( $gallery_is_featured ) : ?>
            <span class="tejcart-featured-badge tejcart-gallery-featured-badge">
                <?php esc_html_e( 'Featured', 'tejcart' ); ?>
            </span>
        <?php endif; ?>
        <button type="button" class="tejcart-gallery-zoom-btn" aria-label="<?php esc_attr_e( 'Zoom image', 'tejcart' ); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="9" cy="9" r="6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M13.5 13.5l4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M6 9h6M9 6v6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        </button>
        <?php

        if ( $use_placeholder_main ) {
            echo tejcart_get_placeholder_image_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns pre-escaped HTML.
                'tejcart-product-main',
                array(
                    'class'             => 'tejcart-gallery-main-image tejcart-placeholder-image',
                    'alt'               => $product_name,
                    'data-full'         => $main_full,
                    'data-image-id'     => '0',
                    'data-default-src'  => $main_src,
                    'data-default-full' => $main_full,
                    'fetchpriority'     => 'high',
                    'decoding'          => 'async',
                    'loading'           => 'eager',
                )
            );
        } else {
            echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image is pre-escaped.
                (int) $all_image_ids[0],
                $main_size,
                false,
                array(
                    'class'             => 'tejcart-gallery-main-image',
                    'alt'               => esc_attr( $product_name ),
                    'data-full'         => esc_url( (string) $main_full ),
                    'data-image-id'     => esc_attr( (string) $all_image_ids[0] ),
                    'data-default-src'  => esc_url( (string) $main_src ),
                    'data-default-full' => esc_url( (string) $main_full ),
                    'fetchpriority'     => 'high',
                    'decoding'          => 'async',
                )
            );
        }
        ?>
    </div>

    <?php if ( count( $all_image_ids ) + count( $variation_thumbs ) > 1 ) : ?>
        <div class="tejcart-gallery-thumbnails">
            <?php foreach ( $all_image_ids as $index => $img_id ) :
                $thumb_src = wp_get_attachment_image_url( $img_id, 'tejcart-product-thumb' );
                if ( ! $thumb_src ) {
                    $thumb_src = wp_get_attachment_image_url( $img_id, 'thumbnail' );
                }
                $large_src = wp_get_attachment_image_url( $img_id, 'tejcart-product-main' );
                if ( ! $large_src ) {
                    $large_src = wp_get_attachment_image_url( $img_id, 'large' );
                }
                $full_src  = wp_get_attachment_image_url( $img_id, 'full' );

                if ( ! $thumb_src ) {
                    continue;
                }

                $is_first = 0 === $index;
                ?>
                <button
                    type="button"
                    class="tejcart-gallery-thumb<?php echo $is_first ? ' active' : ''; ?>"
                    data-large="<?php echo esc_url( $large_src ); ?>"
                    data-full="<?php echo esc_url( $full_src ); ?>"
                    data-image-id="<?php echo esc_attr( $img_id ); ?>"
                    aria-label="<?php
                    /* translators: %d: 1-based index of the gallery image. */
                    echo esc_attr( sprintf( __( 'View image %d', 'tejcart' ), $index + 1 ) );
                    ?>"
                >
                    <img src="<?php echo esc_url( $thumb_src ); ?>"
                         alt="<?php echo esc_attr( $product_name ); ?>"
                         <?php echo $is_first ? '' : 'loading="lazy"'; ?>
                         decoding="async" />
                </button>
            <?php endforeach; ?>

            <?php

            $v_index = count( $all_image_ids );
            foreach ( $variation_thumbs as $v_thumb ) :
                $img_id    = (int) $v_thumb['image_id'];
                $thumb_src = wp_get_attachment_image_url( $img_id, 'tejcart-product-thumb' );
                if ( ! $thumb_src ) {
                    $thumb_src = wp_get_attachment_image_url( $img_id, 'thumbnail' );
                }
                $large_src = wp_get_attachment_image_url( $img_id, 'tejcart-product-main' );
                if ( ! $large_src ) {
                    $large_src = wp_get_attachment_image_url( $img_id, 'large' );
                }
                $full_src = wp_get_attachment_image_url( $img_id, 'full' );

                if ( ! $thumb_src ) {
                    continue;
                }
                $v_label = $product_name;
                if ( ! empty( $v_thumb['attributes'] ) ) {
                    $v_label .= ' — ' . implode( ', ', array_values( (array) $v_thumb['attributes'] ) );
                }
                ?>
                <button
                    type="button"
                    class="tejcart-gallery-thumb tejcart-gallery-thumb--variation"
                    data-large="<?php echo esc_url( $large_src ); ?>"
                    data-full="<?php echo esc_url( $full_src ); ?>"
                    data-image-id="<?php echo esc_attr( (string) $img_id ); ?>"
                    data-variation-id="<?php echo esc_attr( (string) $v_thumb['variation_id'] ); ?>"
                    data-variation-attrs="<?php echo esc_attr( wp_json_encode( (array) $v_thumb['attributes'] ) ); ?>"
                    aria-label="<?php echo esc_attr( sprintf( /* translators: %d: thumbnail position */ __( 'View image %d', 'tejcart' ), ++$v_index ) ); ?>"
                >
                    <img src="<?php echo esc_url( $thumb_src ); ?>"
                         alt="<?php echo esc_attr( $v_label ); ?>"
                         loading="lazy"
                         decoding="async" />
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Lightbox overlay (one per gallery, rendered once) -->
<div class="tejcart-lightbox" aria-hidden="true" role="dialog" aria-label="<?php esc_attr_e( 'Image lightbox', 'tejcart' ); ?>">
    <div class="tejcart-lightbox-overlay"></div>
    <div class="tejcart-lightbox-content">
        <button type="button" class="tejcart-lightbox-close" aria-label="<?php esc_attr_e( 'Close lightbox', 'tejcart' ); ?>">&times;</button>
        <?php if ( count( $all_image_ids ) > 1 ) : ?>
            <button type="button" class="tejcart-lightbox-prev" aria-label="<?php esc_attr_e( 'Previous image', 'tejcart' ); ?>">&lsaquo;</button>
            <button type="button" class="tejcart-lightbox-next" aria-label="<?php esc_attr_e( 'Next image', 'tejcart' ); ?>">&rsaquo;</button>
        <?php endif; ?>
        <img class="tejcart-lightbox-image" src="" alt="<?php echo esc_attr( $product_name ); ?>" decoding="async" />
    </div>
</div>
