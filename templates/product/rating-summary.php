<?php
/**
 * Inline rating summary for the single product page.
 *
 * Renders a compact average-rating + review-count link that anchors
 * to the full reviews section. Returns silently when reviews are
 * disabled or the product has no approved reviews.
 *
 * @package TejCart\Templates\Product
 *
 * @var int $product_id The product ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( 'yes' !== get_option( 'tejcart_enable_reviews', 'yes' ) ) {
    return;
}

if ( ! class_exists( '\\TejCart\\Product\\Product_Reviews' ) ) {
    return;
}

$tejcart_review_count = (int) \TejCart\Product\Product_Reviews::get_review_count( $product_id );
if ( $tejcart_review_count < 1 ) {
    return;
}

$tejcart_avg = (float) \TejCart\Product\Product_Reviews::get_average_rating( $product_id );
?>
<a class="tejcart-rating-summary" href="#tejcart-reviews">
    <span class="tejcart-star-rating" aria-label="<?php echo esc_attr( sprintf(
        /* translators: %s: average rating, formatted to 1 decimal. */
        __( 'Rated %s out of 5', 'tejcart' ),
        number_format_i18n( $tejcart_avg, 1 )
    ) ); ?>">
        <?php for ( $tejcart_i = 1; $tejcart_i <= 5; $tejcart_i++ ) : ?>
            <?php if ( $tejcart_i <= round( $tejcart_avg ) ) : ?>
                <span class="tejcart-star tejcart-star-filled" aria-hidden="true">&#9733;</span>
            <?php else : ?>
                <span class="tejcart-star tejcart-star-empty" aria-hidden="true">&#9734;</span>
            <?php endif; ?>
        <?php endfor; ?>
    </span>
    <span class="tejcart-rating-summary-meta">
        <span class="tejcart-rating-summary-average"><?php echo esc_html( number_format_i18n( $tejcart_avg, 1 ) ); ?></span>
        <span class="tejcart-rating-summary-sep" aria-hidden="true">·</span>
        <span class="tejcart-rating-summary-count">
            <?php
            echo esc_html( sprintf(
                /* translators: %d: number of reviews. */
                _n( '%d review', '%d reviews', $tejcart_review_count, 'tejcart' ),
                $tejcart_review_count
            ) );
            ?>
        </span>
    </span>
</a>
