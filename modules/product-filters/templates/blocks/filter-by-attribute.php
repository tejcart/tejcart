<?php
/**
 * Template: tejcart/filter-by-attribute block.
 *
 * @var array  $state
 * @var array  $all_attributes   attr_name => [values]
 * @var array  $attribute_counts attr_key => (value => count)
 * @var string $target_attr      Specific attribute slug to show (empty = all)
 * @var string $heading
 * @var bool   $show_counts
 * @var string $display_style    'list' or 'inline'
 *
 * @package TejCart\Templates\Blocks
 */

defined( 'ABSPATH' ) || exit;

use TejCart\Product_Filters\Product_Filter;

$shop_url    = get_permalink( (int) get_option( 'tejcart_shop_page_id', 0 ) ) ?: home_url( '/' );
$list_class  = 'inline' === $display_style ? 'tejcart-facet-list tejcart-facet-list--inline' : 'tejcart-facet-list';
$rendered    = false;
?>
<div class="wp-block-tejcart-filter-by-attribute tejcart-filter-block"
     data-tejcart-filter="attribute">
    <form class="tejcart-filter-block-form tejcart-facets-form" method="get"
          action="<?php echo esc_url( $shop_url ); ?>">

        <?php foreach ( $all_attributes as $attr_name => $attr_values ) :
            $attr_key = sanitize_title( $attr_name );

            if ( '' !== $target_attr && $attr_key !== sanitize_title( $target_attr ) ) {
                continue;
            }

            $value_counts  = $attribute_counts[ $attr_key ] ?? array();
            $active_values = $state['attributes'][ $attr_key ] ?? array();

            $visible = array();
            foreach ( $attr_values as $val ) {
                $cnt = $value_counts[ $val ] ?? 0;
                if ( $cnt > 0 || in_array( $val, $active_values, true ) ) {
                    $visible[] = $val;
                }
            }

            if ( empty( $visible ) ) {
                continue;
            }

            $rendered    = true;
            $param_name  = Product_Filter::PARAM_ATTR_PREFIX . $attr_key;
            $section_label = '' !== $heading ? $heading : $attr_name;
        ?>
            <details class="tejcart-facet-section" open>
                <summary class="tejcart-facet-heading">
                    <?php echo esc_html( $section_label ); ?>
                    <span class="tejcart-facet-chevron" aria-hidden="true"></span>
                </summary>
                <div class="tejcart-facet-body">
                    <ul class="<?php echo esc_attr( $list_class ); ?>" role="list">
                        <?php foreach ( $visible as $val ) :
                            $cnt        = $value_counts[ $val ] ?? 0;
                            $is_checked = in_array( $val, $active_values, true );
                        ?>
                            <li class="tejcart-facet-item">
                                <label class="tejcart-facet-label">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $param_name ); ?>[]"
                                           value="<?php echo esc_attr( $val ); ?>"
                                           <?php checked( $is_checked ); ?> />
                                    <span class="tejcart-facet-text"><?php echo esc_html( $val ); ?></span>
                                    <?php if ( $show_counts ) : ?>
                                        <span class="tejcart-facet-count"><?php echo esc_html( (string) $cnt ); ?></span>
                                    <?php endif; ?>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </details>
        <?php endforeach; ?>

        <?php if ( ! $rendered ) : ?>
            <?php return; ?>
        <?php endif; ?>

        <noscript>
            <div class="tejcart-facets-actions">
                <button type="submit" class="tejcart-facets-apply tejcart-button">
                    <?php esc_html_e( 'Apply', 'tejcart' ); ?>
                </button>
            </div>
        </noscript>
    </form>
</div>
