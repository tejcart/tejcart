<?php
/**
 * Content Generator page — uses TejCart core's admin chrome.
 *
 * @var string $field
 * @var string $settings_url
 * @var bool   $has_api_key
 *
 * @package TejCart\AI_Content_Smartsuite\Admin
 */

declare(strict_types=1);

use TejCart\AI_Content_Smartsuite\Admin\Generator_Page;
use TejCart\Product\Product_Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope locals; this file is included inside a method, not at the top-level.

$field_tabs = array(
    'name'        => __( 'Name', 'tejcart' ),
    'shortdesc'   => __( 'Short Desc', 'tejcart' ),
    'description' => __( 'Description', 'tejcart' ),
    'tags'        => __( 'Tags', 'tejcart' ),
    'faqs'        => __( 'FAQs', 'tejcart' ),
);

$categories = get_terms(
    array(
        'taxonomy'   => Product_Taxonomy::CATEGORY_TAXONOMY,
        'hide_empty' => false,
        'number'     => 0,
    )
);
if ( is_wp_error( $categories ) ) {
    $categories = array();
}
$cat_tree = array();
foreach ( $categories as $term ) {
    $cat_tree[ (int) $term->parent ][] = $term;
}
$render_cat_options = static function ( int $parent, int $depth ) use ( &$render_cat_options, $cat_tree ): void {
    if ( empty( $cat_tree[ $parent ] ) ) {
        return;
    }
    foreach ( $cat_tree[ $parent ] as $term ) {
        $indent = str_repeat( '— ', $depth );
        printf(
            '<option value="%1$d">%2$s (%3$d)</option>',
            (int) $term->term_id,
            esc_html( $indent . $term->name ),
            (int) $term->count
        );
        $render_cat_options( (int) $term->term_id, $depth + 1 );
    }
};

$product_types = array(
    ''         => __( 'All product types', 'tejcart' ),
    'physical' => __( 'Physical', 'tejcart' ),
    'digital'  => __( 'Digital', 'tejcart' ),
    'virtual'  => __( 'Virtual', 'tejcart' ),
    'variable' => __( 'Variable', 'tejcart' ),
    'bundle'   => __( 'Bundle', 'tejcart' ),
    'grouped'  => __( 'Grouped', 'tejcart' ),
    'external' => __( 'External', 'tejcart' ),
);

$stock_statuses = array(
    ''             => __( 'Any stock status', 'tejcart' ),
    'instock'      => __( 'In stock', 'tejcart' ),
    'outofstock'   => __( 'Out of stock', 'tejcart' ),
    'onbackorder'  => __( 'On backorder', 'tejcart' ),
);
?>
<div class="wrap tejcart-admin-wrap tejcart-ai-content-wrap">
    <div class="tejcart-page-header">
        <div class="tejcart-page-header-content">
            <h1><?php esc_html_e( 'AI Content Generator', 'tejcart' ); ?></h1>
            <p class="tejcart-page-subtitle">
                <?php esc_html_e( 'Generate, review, and apply AI-written titles, descriptions, tags and FAQs for your products.', 'tejcart' ); ?>
            </p>
        </div>
        <div class="tejcart-page-header-actions">
            <a class="button tejcart-ai-settings-btn" href="<?php echo esc_url( $settings_url ); ?>">
                <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <?php esc_html_e( 'Settings', 'tejcart' ); ?>
            </a>
        </div>
    </div>

    <?php if ( ! $has_api_key ) : ?>
        <div class="tejcart-card tejcart-ai-callout" role="status">
            <div class="tejcart-card-body tejcart-ai-callout__body">
                <span class="dashicons dashicons-warning tejcart-ai-callout__icon" aria-hidden="true"></span>
                <div class="tejcart-ai-callout__text">
                    <strong><?php esc_html_e( 'OpenAI API key not configured.', 'tejcart' ); ?></strong>
                    <p class="tejcart-ai-callout__detail">
                        <?php esc_html_e( 'Add your API key to enable AI generation.', 'tejcart' ); ?>
                    </p>
                </div>
                <a class="button button-primary tejcart-ai-callout__cta" href="<?php echo esc_url( $settings_url ); ?>">
                    <?php esc_html_e( 'Add API key', 'tejcart' ); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="tejcart-card tejcart-ai-tabs-card">
        <div class="tejcart-card-header tejcart-ai-tabs-card__header">
            <nav class="tejcart-ai-field-tabs" aria-label="<?php esc_attr_e( 'AI Content fields', 'tejcart' ); ?>">
                <?php foreach ( $field_tabs as $slug => $label ) :
                    $is_current = $slug === $field;
                    ?>
                    <a class="tejcart-ai-field-tab<?php echo $is_current ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url( Generator_Page::field_url( $slug ) ); ?>"
                       <?php echo $is_current ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="tejcart-card-body tejcart-ai-toolbar" data-field="<?php echo esc_attr( $field ); ?>">
            <div class="tejcart-ai-filters">
                <select id="tejcart-ai-filter-category">
                    <option value=""><?php esc_html_e( 'All categories', 'tejcart' ); ?></option>
                    <?php $render_cat_options( 0, 0 ); ?>
                </select>
                <select id="tejcart-ai-filter-type">
                    <?php foreach ( $product_types as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="tejcart-ai-filter-stock">
                    <?php foreach ( $stock_statuses as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="search" id="tejcart-ai-filter-search"
                       placeholder="<?php esc_attr_e( 'Search products…', 'tejcart' ); ?>" />
                <select id="tejcart-ai-filter-perpage">
                    <?php foreach ( array( 10, 25, 50, 100 ) as $n ) : ?>
                        <option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $n, 25 ); ?>>
                            <?php echo esc_html( sprintf( /* translators: %d items per page */ __( '%d per page', 'tejcart' ), $n ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary" id="tejcart-ai-filter-apply">
                    <?php esc_html_e( 'Filter', 'tejcart' ); ?>
                </button>
                <button type="button" class="button" id="tejcart-ai-filter-reset">
                    <?php esc_html_e( 'Reset', 'tejcart' ); ?>
                </button>
                <span class="spinner tejcart-ai-spinner" id="tejcart-ai-filter-spinner" aria-hidden="true"></span>
            </div>
        </div>

        <div class="tejcart-ai-bulk-bar">
            <div class="tejcart-ai-bulk-bar__actions">
                <button type="button" class="button button-primary" id="tejcart-ai-bulk-generate">
                    <?php esc_html_e( 'Generate Selected', 'tejcart' ); ?>
                </button>
                <button type="button" class="button" id="tejcart-ai-bulk-apply">
                    <?php esc_html_e( 'Apply Selected', 'tejcart' ); ?>
                </button>
                <span class="spinner tejcart-ai-spinner" id="tejcart-ai-bulk-spinner" aria-hidden="true"></span>
                <span class="tejcart-ai-bulk-status" id="tejcart-ai-bulk-status" aria-live="polite"></span>
            </div>
            <div class="tejcart-ai-pagination" id="tejcart-ai-pagination-top"></div>
        </div>

        <table class="wp-list-table widefat striped tejcart-ai-table" data-field="<?php echo esc_attr( $field ); ?>">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <label class="screen-reader-text" for="tejcart-ai-select-all"><?php esc_html_e( 'Select All', 'tejcart' ); ?></label>
                        <input id="tejcart-ai-select-all" type="checkbox" />
                    </td>
                    <th class="manage-column column-image" style="width:60px;"><?php esc_html_e( 'Image', 'tejcart' ); ?></th>
                    <th class="manage-column column-name"><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                    <th class="manage-column column-existing"><?php esc_html_e( 'Existing', 'tejcart' ); ?></th>
                    <th class="manage-column column-generated"><?php esc_html_e( 'AI-Generated', 'tejcart' ); ?></th>
                    <th class="manage-column column-actions" style="width:240px;"><?php esc_html_e( 'Actions', 'tejcart' ); ?></th>
                </tr>
            </thead>
            <tbody id="tejcart-ai-rows">
                <tr class="tejcart-ai-empty">
                    <td colspan="6" class="tejcart-ai-empty-cell">
                        <?php esc_html_e( 'Loading products…', 'tejcart' ); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="tejcart-ai-bulk-bar tejcart-ai-bulk-bar--bottom">
            <div></div>
            <div class="tejcart-ai-pagination" id="tejcart-ai-pagination-bottom"></div>
        </div>
    </div>

    <template id="tejcart-ai-row-template">
        <tr class="tejcart-ai-row" data-product-id="">
            <th scope="row" class="check-column">
                <input type="checkbox" class="tejcart-ai-row-check" />
            </th>
            <td class="column-image">
                <img class="tejcart-ai-row-image" src="" alt="" loading="lazy" />
            </td>
            <td class="column-name">
                <strong class="tejcart-ai-row-title"></strong>
                <div class="tejcart-ai-row-id"></div>
            </td>
            <td class="column-existing">
                <div class="tejcart-ai-existing"></div>
            </td>
            <td class="column-generated">
                <div class="tejcart-ai-generated"></div>
            </td>
            <td class="column-actions">
                <button type="button" class="button tejcart-ai-btn-generate"><?php esc_html_e( 'Generate', 'tejcart' ); ?></button>
                <button type="button" class="button tejcart-ai-btn-edit"><?php esc_html_e( 'Edit', 'tejcart' ); ?></button>
                <button type="button" class="button button-primary tejcart-ai-btn-apply"><?php esc_html_e( 'Apply', 'tejcart' ); ?></button>
                <button type="button" class="button tejcart-ai-btn-revert" style="display:none;"><?php esc_html_e( 'Revert', 'tejcart' ); ?></button>
                <span class="spinner tejcart-ai-spinner tejcart-ai-row-spinner"></span>
            </td>
        </tr>
    </template>

    <div id="tejcart-ai-edit-modal" class="tejcart-ai-modal" hidden aria-modal="true" role="dialog" aria-labelledby="tejcart-ai-edit-modal-title">
        <div class="tejcart-ai-modal__overlay" data-tejcart-ai-modal-close="1"></div>
        <div class="tejcart-ai-modal__panel">
            <header class="tejcart-ai-modal__header">
                <h2 id="tejcart-ai-edit-modal-title"><?php esc_html_e( 'Edit AI Content', 'tejcart' ); ?></h2>
                <button type="button" class="tejcart-ai-modal__close" data-tejcart-ai-modal-close="1" aria-label="<?php esc_attr_e( 'Close', 'tejcart' ); ?>">×</button>
            </header>
            <div class="tejcart-ai-modal__body">
                <div class="tejcart-ai-edit-body" data-tejcart-ai-edit-body></div>
                <div class="tejcart-ai-faq-editor" data-tejcart-ai-faq-editor hidden>
                    <div class="tejcart-ai-faq-editor__list" data-tejcart-ai-faq-list></div>
                    <button type="button" class="button" data-tejcart-ai-faq-add>
                        <?php esc_html_e( 'Add Q&A', 'tejcart' ); ?>
                    </button>
                </div>
                <label class="tejcart-ai-extra-label">
                    <span class="screen-reader-text"><?php esc_html_e( 'Additional instruction', 'tejcart' ); ?></span>
                    <textarea data-tejcart-ai-extra
                              rows="2"
                              placeholder="<?php esc_attr_e( 'Optional additional instruction for Regenerate (e.g. shorter, more playful tone)…', 'tejcart' ); ?>"></textarea>
                </label>
            </div>
            <footer class="tejcart-ai-modal__footer">
                <button type="button" class="button" data-tejcart-ai-modal-action="regenerate">
                    <?php esc_html_e( 'Regenerate', 'tejcart' ); ?>
                </button>
                <span class="tejcart-ai-modal__sp">
                    <button type="button" class="button" data-tejcart-ai-modal-close="1">
                        <?php esc_html_e( 'Cancel', 'tejcart' ); ?>
                    </button>
                    <button type="button" class="button button-primary" data-tejcart-ai-modal-action="save">
                        <?php esc_html_e( 'Save', 'tejcart' ); ?>
                    </button>
                </span>
                <span class="spinner tejcart-ai-spinner" data-tejcart-ai-modal-spinner></span>
            </footer>
        </div>
    </div>

    <template id="tejcart-ai-faq-row-template">
        <div class="tejcart-ai-faq-row">
            <div class="tejcart-ai-faq-row__handle" data-tejcart-ai-faq-handle aria-label="<?php esc_attr_e( 'Drag to reorder', 'tejcart' ); ?>">≡</div>
            <input type="text" class="tejcart-ai-faq-row__q" data-tejcart-ai-faq-q placeholder="<?php esc_attr_e( 'Question', 'tejcart' ); ?>" />
            <textarea class="tejcart-ai-faq-row__a" data-tejcart-ai-faq-a rows="3" placeholder="<?php esc_attr_e( 'Answer', 'tejcart' ); ?>"></textarea>
            <button type="button" class="button-link-delete tejcart-ai-faq-row__delete" data-tejcart-ai-faq-delete>
                <?php esc_html_e( 'Remove', 'tejcart' ); ?>
            </button>
        </div>
    </template>
</div>
<?php
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
