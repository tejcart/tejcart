<?php
/**
 * Template: tejcart/filter-by-stock block.
 *
 * @var array  $state
 * @var array  $stock       {instock:int, outofstock:int}
 * @var string $heading
 * @var bool   $show_counts
 *
 * @package TejCart\Templates\Blocks
 */

defined( 'ABSPATH' ) || exit;

use TejCart\Product_Filters\Product_Filter;

$block_label = '' !== $heading ? $heading : __( 'Availability', 'tejcart' );
$shop_url    = get_permalink( (int) get_option( 'tejcart_shop_page_id', 0 ) ) ?: home_url( '/' );
?>
<div class="wp-block-tejcart-filter-by-stock tejcart-filter-block"
     data-tejcart-filter="stock">
    <form class="tejcart-filter-block-form tejcart-facets-form" method="get"
          action="<?php echo esc_url( $shop_url ); ?>">

        <details class="tejcart-facet-section" open>
            <summary class="tejcart-facet-heading">
                <?php echo esc_html( $block_label ); ?>
                <span class="tejcart-facet-chevron" aria-hidden="true"></span>
            </summary>
            <div class="tejcart-facet-body">
                <ul class="tejcart-facet-list" role="list">
                    <li class="tejcart-facet-item">
                        <label class="tejcart-facet-label tejcart-facet-toggle">
                            <input type="checkbox"
                                   name="<?php echo esc_attr( Product_Filter::PARAM_STOCK ); ?>"
                                   value="1"
                                   <?php checked( $state['in_stock'] ); ?> />
                            <span class="tejcart-facet-text"><?php esc_html_e( 'In Stock', 'tejcart' ); ?></span>
                            <?php if ( $show_counts ) : ?>
                                <span class="tejcart-facet-count"><?php echo esc_html( (string) ( $stock['instock'] ?? 0 ) ); ?></span>
                            <?php endif; ?>
                        </label>
                    </li>
                    <li class="tejcart-facet-item">
                        <label class="tejcart-facet-label tejcart-facet-toggle">
                            <input type="checkbox" disabled aria-hidden="true"
                                   tabindex="-1" />
                            <span class="tejcart-facet-text"><?php esc_html_e( 'Out of Stock', 'tejcart' ); ?></span>
                            <?php if ( $show_counts ) : ?>
                                <span class="tejcart-facet-count"><?php echo esc_html( (string) ( $stock['outofstock'] ?? 0 ) ); ?></span>
                            <?php endif; ?>
                        </label>
                    </li>
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
