<?php
/**
 * Template: tejcart/filter-by-rating block.
 *
 * @var array  $state
 * @var array  $buckets     min_stars => cumulative count
 * @var string $heading
 * @var bool   $show_counts
 *
 * @package TejCart\Templates\Blocks
 */

defined( 'ABSPATH' ) || exit;

use TejCart\Product_Filters\Product_Filter;

$active      = $state['rating'];
$block_label = '' !== $heading ? $heading : __( 'Rating', 'tejcart' );
$shop_url    = get_permalink( (int) get_option( 'tejcart_shop_page_id', 0 ) ) ?: home_url( '/' );
?>
<div class="wp-block-tejcart-filter-by-rating tejcart-filter-block"
     data-tejcart-filter="rating">
    <form class="tejcart-filter-block-form tejcart-facets-form" method="get"
          action="<?php echo esc_url( $shop_url ); ?>">

        <details class="tejcart-facet-section" open>
            <summary class="tejcart-facet-heading">
                <?php echo esc_html( $block_label ); ?>
                <span class="tejcart-facet-chevron" aria-hidden="true"></span>
            </summary>
            <div class="tejcart-facet-body">
                <ul class="tejcart-facet-list tejcart-facet-list--rating" role="list">
                    <?php for ( $stars = 5; $stars >= 1; $stars-- ) :
                        $cnt = $buckets[ $stars ] ?? 0;
                        if ( 0 === $cnt && $active !== $stars ) {
                            continue;
                        }
                    ?>
                        <li class="tejcart-facet-item">
                            <label class="tejcart-facet-label">
                                <input type="radio"
                                       name="<?php echo esc_attr( Product_Filter::PARAM_RATING ); ?>"
                                       value="<?php echo esc_attr( (string) $stars ); ?>"
                                       <?php checked( $active, $stars ); ?> />
                                <span class="tejcart-facet-stars" aria-label="<?php echo esc_attr( sprintf(
                                    /* translators: %d: star count */
                                    __( '%d stars & up', 'tejcart' ),
                                    $stars
                                ) ); ?>">
                                    <?php echo esc_html( str_repeat( "\u{2605}", $stars ) . str_repeat( "\u{2606}", 5 - $stars ) ); ?>
                                </span>
                                <span class="tejcart-facet-text"><?php esc_html_e( '& Up', 'tejcart' ); ?></span>
                                <?php if ( $show_counts ) : ?>
                                    <span class="tejcart-facet-count"><?php echo esc_html( (string) $cnt ); ?></span>
                                <?php endif; ?>
                            </label>
                        </li>
                    <?php endfor; ?>
                </ul>
            </div>
        </details>

        <noscript>
            <div class="tejcart-facets-actions">
                <button type="submit" class="tejcart-facets-apply tejcart-button">
                    <?php esc_html_e( 'Apply', 'tejcart' ); ?>
                </button>
            </div>
        </noscript>
    </form>
</div>
