<?php
/**
 * Product archive layout — faceted sidebar + product grid.
 *
 * Renders the two-column shop layout with the faceted filter sidebar
 * on the left and the product grid content on the right. On mobile,
 * the sidebar is hidden behind a "Filters" toggle button that opens
 * a slide-out drawer.
 *
 * @package TejCart\Templates\Product
 *
 * @var string $sidebar_html   Pre-rendered faceted sidebar HTML.
 * @var string $active_filters Pre-rendered active filter chips HTML.
 * @var string $grid_html      Pre-rendered shop content (meta + grid + pagination).
 * @var int    $active_count   Number of currently active filters.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<button type="button" class="tejcart-facets-mobile-toggle" aria-controls="tejcart-facets-sidebar" aria-expanded="false">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14" /><line x1="4" y1="10" x2="4" y2="3" /><line x1="12" y1="21" x2="12" y2="12" /><line x1="12" y1="8" x2="12" y2="3" /><line x1="20" y1="21" x2="20" y2="16" /><line x1="20" y1="12" x2="20" y2="3" /><line x1="1" y1="14" x2="7" y2="14" /><line x1="9" y1="8" x2="15" y2="8" /><line x1="17" y1="16" x2="23" y2="16" /></svg>
    <?php esc_html_e( 'Filters', 'tejcart' ); ?>
    <?php if ( $active_count > 0 ) : ?>
        <span class="tejcart-facets-badge"><?php echo esc_html( (string) $active_count ); ?></span>
    <?php endif; ?>
</button>

<div class="tejcart-shop-layout">
    <?php
    echo $sidebar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by Product_Filter::render_sidebar().
    ?>

    <div class="tejcart-shop-content">
        <?php
        echo $active_filters; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by Product_Filter::render_active_filters().
        echo $grid_html;       // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped by render_products_grid internals.
        ?>
    </div>
</div>
