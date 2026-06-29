<?php
/**
 * Template: tejcart/filter-by-price block.
 *
 * @var array{min:float,max:float} $range
 * @var array{buckets:int[],bucket_width:float,max_count:int} $histogram
 * @var array  $state
 * @var string $heading
 * @var bool   $show_histogram
 * @var bool   $show_inputs
 *
 * @package TejCart\Templates\Blocks
 */

defined( 'ABSPATH' ) || exit;

use TejCart\Product_Filters\Product_Filter;

// $range/$histogram are computed from the BASE-currency price columns. Convert
// the facet into the ACTIVE display currency so the slider, histogram, and
// labels are in the shopper's currency — on the same scale as the selected
// min/max ($state, already active). Posted values are reversed back to base for
// the SQL comparison in Product_Filter. Passthrough on a single-currency store.
$range['min'] = (float) apply_filters( 'tejcart_amount_to_currency', $range['min'], tejcart_get_currency() );
$range['max'] = (float) apply_filters( 'tejcart_amount_to_currency', $range['max'], tejcart_get_currency() );
$tejcart_bucket_count = ( isset( $histogram['buckets'] ) && is_array( $histogram['buckets'] ) ) ? count( $histogram['buckets'] ) : 0;
if ( $tejcart_bucket_count > 0 && $range['max'] > $range['min'] ) {
	$histogram['bucket_width'] = ( $range['max'] - $range['min'] ) / $tejcart_bucket_count;
}

$current_min = $state['min_price'] > 0 ? $state['min_price'] : $range['min'];
$current_max = $state['max_price'] > 0 ? $state['max_price'] : $range['max'];
$step        = $range['max'] > 100 ? 1 : 0.01;
$block_label = '' !== $heading ? $heading : __( 'Price', 'tejcart' );
?>
<div class="wp-block-tejcart-filter-by-price tejcart-filter-block"
     data-tejcart-filter="price">
    <form class="tejcart-filter-block-form tejcart-facets-form" method="get"
          action="<?php echo esc_url( get_permalink( (int) get_option( 'tejcart_shop_page_id', 0 ) ) ?: home_url( '/' ) ); ?>">

        <details class="tejcart-facet-section" open>
            <summary class="tejcart-facet-heading">
                <?php echo esc_html( $block_label ); ?>
                <span class="tejcart-facet-chevron" aria-hidden="true"></span>
            </summary>
            <div class="tejcart-facet-body">
                <div class="tejcart-facet-price"
                     data-min="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                     data-max="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                     data-step="<?php echo esc_attr( (string) $step ); ?>">

                    <?php if ( $show_histogram && ! empty( $histogram['buckets'] ) && $histogram['max_count'] > 0 ) : ?>
                    <div class="tejcart-facet-price-histogram" aria-hidden="true">
                        <?php foreach ( $histogram['buckets'] as $idx => $count ) :
                            $pct       = (int) round( ( $count / $histogram['max_count'] ) * 100 );
                            $bar_min   = $range['min'] + ( $idx * $histogram['bucket_width'] );
                            $bar_max   = $bar_min + $histogram['bucket_width'];
                            $is_active = $bar_max >= $current_min && $bar_min <= $current_max;
                        ?>
                            <div class="tejcart-facet-price-bar<?php echo $is_active ? ' is-active' : ''; ?>"
                                 style="height: <?php echo esc_attr( (string) max( 2, $pct ) ); ?>%"
                                 data-count="<?php echo esc_attr( (string) $count ); ?>"
                                 title="<?php echo esc_attr( sprintf(
                                     /* translators: 1: price range start, 2: price range end, 3: product count */
                                     __( '%1$s – %2$s: %3$d products', 'tejcart' ),
                                     tejcart_price( $bar_min ),
                                     tejcart_price( $bar_max ),
                                     $count
                                 ) ); ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="tejcart-facet-price-slider" aria-hidden="true">
                        <div class="tejcart-facet-price-track">
                            <div class="tejcart-facet-price-fill"></div>
                        </div>
                        <div class="tejcart-facet-price-handle tejcart-facet-price-handle--min"></div>
                        <div class="tejcart-facet-price-handle tejcart-facet-price-handle--max"></div>
                        <input type="range" class="tejcart-facet-price-thumb tejcart-facet-price-thumb--min"
                               min="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                               max="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                               value="<?php echo esc_attr( (string) $current_min ); ?>"
                               step="<?php echo esc_attr( (string) $step ); ?>"
                               aria-label="<?php esc_attr_e( 'Minimum price', 'tejcart' ); ?>" />
                        <input type="range" class="tejcart-facet-price-thumb tejcart-facet-price-thumb--max"
                               min="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                               max="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                               value="<?php echo esc_attr( (string) $current_max ); ?>"
                               step="<?php echo esc_attr( (string) $step ); ?>"
                               aria-label="<?php esc_attr_e( 'Maximum price', 'tejcart' ); ?>" />
                    </div>

                    <?php if ( $show_inputs ) : ?>
                    <div class="tejcart-facet-price-inputs">
                        <label>
                            <span class="screen-reader-text"><?php esc_html_e( 'Min price', 'tejcart' ); ?></span>
                            <input type="number" name="<?php echo esc_attr( Product_Filter::PARAM_MIN_PRICE ); ?>"
                                   class="tejcart-facet-price-input"
                                   value="<?php echo $state['min_price'] > 0 ? esc_attr( (string) $state['min_price'] ) : ''; ?>"
                                   placeholder="<?php echo esc_attr( (string) floor( $range['min'] ) ); ?>"
                                   min="0" step="<?php echo esc_attr( (string) $step ); ?>" />
                        </label>
                        <span class="tejcart-facet-price-sep" aria-hidden="true">&ndash;</span>
                        <label>
                            <span class="screen-reader-text"><?php esc_html_e( 'Max price', 'tejcart' ); ?></span>
                            <input type="number" name="<?php echo esc_attr( Product_Filter::PARAM_MAX_PRICE ); ?>"
                                   class="tejcart-facet-price-input"
                                   value="<?php echo $state['max_price'] > 0 ? esc_attr( (string) $state['max_price'] ) : ''; ?>"
                                   placeholder="<?php echo esc_attr( (string) ceil( $range['max'] ) ); ?>"
                                   min="0" step="<?php echo esc_attr( (string) $step ); ?>" />
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
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
