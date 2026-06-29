<?php
/**
 * Single-product tabs.
 *
 * Renders the tabbed section (Description, Additional information,
 * Reviews) on the single-product page. Uses the WAI-ARIA
 * tabs pattern; without JavaScript the panels remain stacked and visible
 * (the JS enhancer hides non-active panels on init).
 *
 * @package TejCart\Templates\Product
 *
 * @var \TejCart\Product\Product_Types\Abstract_Product $product Product instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$tejcart_tabs = \TejCart\Frontend\Product_Tabs::get( $product );

if ( empty( $tejcart_tabs ) ) {
    return;
}

$tejcart_tab_keys   = array_keys( $tejcart_tabs );
$tejcart_active_key = (string) $tejcart_tab_keys[0];
?>
<div class="tejcart-single-product-tabs" data-tejcart-product-tabs>
    <div
        class="tejcart-single-product-tabs-nav"
        role="tablist"
        aria-label="<?php esc_attr_e( 'Product information tabs', 'tejcart' ); ?>"
    >
        <?php foreach ( $tejcart_tabs as $tejcart_tab_key => $tejcart_tab ) :
            $tejcart_tab_id    = 'tejcart-tab-' . sanitize_html_class( (string) $tejcart_tab_key );
            $tejcart_panel_id  = 'tejcart-tab-panel-' . sanitize_html_class( (string) $tejcart_tab_key );
            $tejcart_is_active = ( $tejcart_tab_key === $tejcart_active_key );
            ?>
            <button
                type="button"
                role="tab"
                id="<?php echo esc_attr( $tejcart_tab_id ); ?>"
                class="tejcart-single-product-tab<?php echo $tejcart_is_active ? ' is-active' : ''; ?>"
                aria-controls="<?php echo esc_attr( $tejcart_panel_id ); ?>"
                aria-selected="<?php echo $tejcart_is_active ? 'true' : 'false'; ?>"
                tabindex="<?php echo $tejcart_is_active ? '0' : '-1'; ?>"
                data-tejcart-tab="<?php echo esc_attr( (string) $tejcart_tab_key ); ?>"
            >
                <?php echo esc_html( (string) $tejcart_tab['title'] ); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ( $tejcart_tabs as $tejcart_tab_key => $tejcart_tab ) :
        $tejcart_tab_id    = 'tejcart-tab-' . sanitize_html_class( (string) $tejcart_tab_key );
        $tejcart_panel_id  = 'tejcart-tab-panel-' . sanitize_html_class( (string) $tejcart_tab_key );
        $tejcart_is_active = ( $tejcart_tab_key === $tejcart_active_key );
        ?>
        <section
            role="tabpanel"
            id="<?php echo esc_attr( $tejcart_panel_id ); ?>"
            class="tejcart-single-product-tab-panel<?php echo $tejcart_is_active ? ' is-active' : ''; ?>"
            aria-labelledby="<?php echo esc_attr( $tejcart_tab_id ); ?>"
            data-tejcart-tab-panel="<?php echo esc_attr( (string) $tejcart_tab_key ); ?>"
        >
            <?php call_user_func( $tejcart_tab['callback'], (string) $tejcart_tab_key, $product ); ?>
        </section>
    <?php endforeach; ?>
</div>
