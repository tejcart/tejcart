<?php
/**
 * Settings tabs configuration.
 *
 * @package TejCart\Settings
 */

declare( strict_types=1 );

namespace TejCart\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the available settings tabs, their labels, icons and
 * the field definitions that belong to each tab.
 */
class Settings_Tabs {
    /**
     * Registered tabs.
     *
     * @var array
     */
    protected $tabs = array();

    /**
     * Sidebar groups – maps a group ID to its label and tab IDs.
     *
     * @var array
     */
    protected $groups = array();

    /**
     * Whether {@see ensure_loaded()} has built the translatable
     * tab/group arrays yet.
     */
    private bool $loaded = false;

    /**
     * Constructor — intentionally empty.
     *
     * The tab and group definitions contain `__()` calls. Building them
     * here would fire translation lookups at `plugins_loaded` (when this
     * class is instantiated by Admin::init()), which since WP 6.7 emits
     * a "translation loading too early" notice. The arrays are populated
     * lazily on first read instead — every consumer (get_tabs, get_groups,
     * get_current_tab, get_tab, get_tab_fields) calls ensure_loaded()
     * first, and those callers all run on admin_menu / admin page render
     * which is after the init action has fired.
     */
    public function __construct() {
    }

    /**
     * Build the translatable tab and group arrays on first access.
     */
    private function ensure_loaded(): void {
        if ( $this->loaded ) {
            return;
        }
        $this->loaded = true;

        $this->tabs = array(
            'general'  => array(
                'id'       => 'general',
                'label'    => __( 'General', 'tejcart' ),
                'icon'     => 'dashicons-admin-generic',
                'desc'     => __( 'Store identity, currency and number formatting.', 'tejcart' ),
                'sections' => array(),
            ),
            'products' => array(
                'id'       => 'products',
                'label'    => __( 'Products', 'tejcart' ),
                'icon'     => 'dashicons-products',
                'desc'     => __( 'Catalog layout, reviews and stock display.', 'tejcart' ),
                'sections' => array(),
            ),
            'design'   => array(
                'id'       => 'design',
                'label'    => __( 'Design', 'tejcart' ),
                'icon'     => 'dashicons-art',
                'desc'     => __( 'Match storefront colors to your WordPress theme.', 'tejcart' ),
                'sections' => array(),
            ),
            'cart'     => array(
                'id'       => 'cart',
                'label'    => __( 'Cart', 'tejcart' ),
                'icon'     => 'dashicons-cart',
                'desc'     => __( 'Cart drawer behaviour and order limits.', 'tejcart' ),
                'sections' => array(),
            ),
            'checkout' => array(
                'id'       => 'checkout',
                'label'    => __( 'Checkout', 'tejcart' ),
                'icon'     => 'dashicons-yes-alt',
                'desc'     => __( 'Checkout pages, guest checkout and order notes.', 'tejcart' ),
                'sections' => array(),
            ),
            'payments' => array(
                'id'       => 'payments',
                'label'    => __( 'Payments', 'tejcart' ),
                'icon'     => 'dashicons-money-alt',
                'desc'     => __( 'Manage installed payment methods and gateways.', 'tejcart' ),
                'sections' => array(),
            ),
            'shipping' => array(
                'id'       => 'shipping',
                'label'    => __( 'Shipping', 'tejcart' ),
                'icon'     => 'dashicons-car',
                'desc'     => __( 'Shipping origin, default method and free shipping rules.', 'tejcart' ),
                'sections' => array(),
            ),
            'tax'      => array(
                'id'       => 'tax',
                'label'    => __( 'Tax', 'tejcart' ),
                'icon'     => 'dashicons-chart-pie',
                'desc'     => __( 'Tax calculation, display rules and rounding.', 'tejcart' ),
                'sections' => array(),
            ),
            'emails'   => array(
                'id'       => 'emails',
                'label'    => __( 'Emails', 'tejcart' ),
                'icon'     => 'dashicons-email',
                'desc'     => __( 'Outgoing email branding and sender details.', 'tejcart' ),
                'sections' => array(),
            ),
            'advanced' => array(
                'id'       => 'advanced',
                'label'    => __( 'Advanced', 'tejcart' ),
                'icon'     => 'dashicons-admin-tools',
                'desc'     => __( 'API access, debugging and import / export.', 'tejcart' ),
                'sections' => array(),
            ),
        );

        $this->groups = array(
            'store'    => array(
                'label' => __( 'Store', 'tejcart' ),
                'tabs'  => array( 'general', 'products', 'design', 'cart', 'checkout', 'emails' ),
            ),
            'commerce' => array(
                'label' => __( 'Selling', 'tejcart' ),
                'tabs'  => array( 'payments', 'shipping', 'tax' ),
            ),
            'system'   => array(
                'label' => __( 'System', 'tejcart' ),
                'tabs'  => array( 'advanced' ),
            ),
        );

        /**
         * Filter the available settings tabs.
         *
         * @param array $tabs Default tabs.
         */
        $this->tabs = apply_filters( 'tejcart_settings_tabs', $this->tabs );

        /**
         * Filter the sidebar groups for the settings page.
         *
         * @param array $groups Default groups, each with `label` and `tabs` keys.
         * @param array $tabs   Currently registered tabs.
         */
        $this->groups = apply_filters( 'tejcart_settings_tab_groups', $this->groups, $this->tabs );
    }

    /**
     * Return all sidebar groups, filtered to only include registered tabs.
     *
     * Any tab not present in a group is appended to a fallback "Other" group
     * so extension-added tabs remain discoverable.
     *
     * @return array
     */
    public function get_groups() {
        $this->ensure_loaded();

        $known   = array();
        $groups  = array();

        foreach ( $this->groups as $id => $group ) {
            $tabs = array();
            foreach ( (array) $group['tabs'] as $tab_id ) {
                if ( isset( $this->tabs[ $tab_id ] ) ) {
                    $tabs[]   = $tab_id;
                    $known[]  = $tab_id;
                }
            }
            if ( ! empty( $tabs ) ) {
                $groups[ $id ] = array(
                    'label' => $group['label'],
                    'tabs'  => $tabs,
                );
            }
        }

        $orphans = array_diff( array_keys( $this->tabs ), $known );
        if ( ! empty( $orphans ) ) {
            $groups['other'] = array(
                'label' => __( 'Other', 'tejcart' ),
                'tabs'  => array_values( $orphans ),
            );
        }

        return $groups;
    }

    /**
     * Return all tabs.
     *
     * @return array
     */
    public function get_tabs() {
        $this->ensure_loaded();
        return $this->tabs;
    }

    /**
     * Determine the current tab from the query string.
     *
     * @return string Tab ID.
     */
    public function get_current_tab() {
        $this->ensure_loaded();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
        return isset( $this->tabs[ $tab ] ) ? $tab : 'general';
    }

    /**
     * Retrieve configuration for a single tab.
     *
     * @param string $id Tab ID.
     * @return array|null Tab config or null when not found.
     */
    public function get_tab( $id ) {
        $this->ensure_loaded();
        return isset( $this->tabs[ $id ] ) ? $this->tabs[ $id ] : null;
    }

    /**
     * Return the field definitions for a given tab.
     *
     * Each tab has a dedicated filter `tejcart_get_settings_{tab_id}` so
     * extensions can inject their own fields.
     *
     * @param string $tab_id Tab ID.
     * @return array Array of field definition arrays.
     */
    public function get_tab_fields( $tab_id ) {
        $fields = array();

        switch ( $tab_id ) {
            case 'general':
                $fields = array(
                    array(
                        'name'    => 'store_name',
                        'label'   => __( 'Store Name', 'tejcart' ),
                        'type'    => 'text',
                        'default' => get_bloginfo( 'name' ),
                    ),
                    array(
                        'name'        => 'store_country',
                        'label'       => __( 'Country / State', 'tejcart' ),
                        'type'        => 'select',
                        'default'     => 'US',
                        'options'     => $this->get_country_options(),
                        'class'       => 'tejcart-settings-country-select tejcart-country-select',
                        'data'        => array( 'tejcart-state-pair' => 'store' ),
                        'description' => __( 'Selecting a country with regional subdivisions turns the State / Province field below into a dropdown.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'store_address',
                        'label'   => __( 'Store Address', 'tejcart' ),
                        'type'    => 'textarea',
                        'default' => '',
                    ),
                    array(
                        // Audit #52 / 03 #9 — surfaced from Setup_Wizard
                        // so merchants can edit address line 2 without
                        // re-running the wizard.
                        'name'    => 'store_address_2',
                        'label'   => __( 'Address line 2', 'tejcart' ),
                        'type'    => 'text',
                        'default' => '',
                    ),
                    array(
                        'name'    => 'store_city',
                        'label'   => __( 'City', 'tejcart' ),
                        'type'    => 'text',
                        'default' => '',
                    ),
                    array(
                        // Audit #52 / 03 #9 — wizard-only orphan.
                        'name'    => 'store_email',
                        'label'   => __( 'Store contact email', 'tejcart' ),
                        'type'    => 'email',
                        'default' => get_option( 'admin_email' ),
                        'desc'    => __( 'Shown in transactional email footer "Need help?" links. Defaults to the site admin email.', 'tejcart' ),
                    ),
                    array(
                        // Audit #52 / 03 #9 — wizard-only orphan.
                        'name'    => 'weight_unit',
                        'label'   => __( 'Weight unit', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'kg',
                        'options' => array(
                            'kg' => __( 'Kilograms (kg)', 'tejcart' ),
                            'g'  => __( 'Grams (g)', 'tejcart' ),
                            'lb' => __( 'Pounds (lb)', 'tejcart' ),
                            'oz' => __( 'Ounces (oz)', 'tejcart' ),
                        ),
                    ),
                    array(
                        // Audit #52 / 03 #9 — wizard-only orphan.
                        'name'    => 'dimension_unit',
                        'label'   => __( 'Dimension unit', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'cm',
                        'options' => array(
                            'cm' => __( 'Centimeters (cm)', 'tejcart' ),
                            'mm' => __( 'Millimeters (mm)', 'tejcart' ),
                            'm'  => __( 'Meters (m)', 'tejcart' ),
                            'in' => __( 'Inches (in)', 'tejcart' ),
                            'yd' => __( 'Yards (yd)', 'tejcart' ),
                        ),
                    ),
                    $this->build_store_state_field(),
                    array(
                        'name'        => 'store_postcode',
                        'label'       => __( 'Postcode / ZIP', 'tejcart' ),
                        'type'        => 'text',
                        'default'     => '',
                        'description' => __( 'Postcode or pincode of your pickup location. Required for live-rate carriers (Shiprocket, FedEx, UPS, USPS, DHL, …) — leaving it blank makes the carrier reject every quote request.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'currency',
                        'label'   => __( 'Currency', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'USD',
                        'options' => $this->get_currency_options(),
                    ),
                    array(
                        'name'    => 'currency_position',
                        'label'   => __( 'Currency Position', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'left',
                        'options' => array(
                            'left'        => __( 'Left ($99.99)', 'tejcart' ),
                            'right'       => __( 'Right (99.99$)', 'tejcart' ),
                            'left_space'  => __( 'Left with space ($ 99.99)', 'tejcart' ),
                            'right_space' => __( 'Right with space (99.99 $)', 'tejcart' ),
                        ),
                    ),
                    array(
                        'name'    => 'thousand_separator',
                        'label'   => __( 'Thousand Separator', 'tejcart' ),
                        'type'    => 'text',
                        'default' => ',',
                    ),
                    array(
                        'name'    => 'decimal_separator',
                        'label'   => __( 'Decimal Separator', 'tejcart' ),
                        'type'    => 'text',
                        'default' => '.',
                    ),
                    array(
                        'name'    => 'num_decimals',
                        'label'   => __( 'Number of Decimals', 'tejcart' ),
                        'type'    => 'number',
                        'default' => '2',
                        'min'     => '0',
                        'max'     => '10',
                        'step'    => '1',
                    ),
                );
                break;

            case 'products':
                $fields = array(
                    array(
                        'name'    => 'shop_page_id',
                        'label'   => __( 'Shop Page', 'tejcart' ),
                        'type'    => 'select',
                        'default' => '',
                        'options' => $this->get_pages_options(),
                        'desc'    => __( 'The base page for your shop.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'products_per_page',
                        'label'   => __( 'Products Per Page', 'tejcart' ),
                        'type'    => 'number',
                        'default' => '12',
                        'min'     => '1',
                        'max'     => '100',
                        'step'    => '1',
                    ),
                    array(
                        'name'    => 'products_columns',
                        'label'   => __( 'Products Per Row', 'tejcart' ),
                        'type'    => 'select',
                        'default' => '4',
                        'options' => array(
                            '2' => '2',
                            '3' => '3',
                            '4' => '4',
                            '5' => '5',
                            '6' => '6',
                        ),
                        'desc'    => __( 'Number of product columns displayed on the shop page.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'enable_reviews',
                        'label'   => __( 'Enable Reviews', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Allow customers to leave product reviews.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'review_verified_only',
                        'label'   => __( 'Reviews by Verified Owners Only', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Only allow reviews from customers who have purchased the product.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'review_show_verified_label',
                        'label'   => __( 'Show Verified Owner Label', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Show the "verified owner" badge next to reviews left by confirmed purchasers.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'review_rating_required',
                        'label'   => __( 'Review Rating Required', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Require a star rating to be selected before a review can be submitted.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'hide_out_of_stock',
                        'label'   => __( 'Hide Out-of-Stock Items', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Exclude out-of-stock products from shop listings, search results, related products, and cross-sells.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'stock_display_format',
                        'label'   => __( 'Stock Display Format', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'always',
                        'options' => array(
                            'always'         => __( 'Always show stock (e.g. "12 in stock")', 'tejcart' ),
                            'only_when_low'  => __( 'Only show stock when low', 'tejcart' ),
                            'never'          => __( 'Never show stock amount', 'tejcart' ),
                        ),
                        'desc'    => __( 'Controls how remaining stock is displayed on product pages.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'low_stock_threshold',
                        'label'   => __( 'Low Stock Threshold', 'tejcart' ),
                        'type'    => 'number',
                        'default' => '5',
                        'desc'    => __( 'Stock quantity at or below which low-stock alerts are raised and the "low stock" label is shown. Set to 0 to disable low-stock alerts.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'product_shipping_policy',
                        'label'   => __( 'Shipping policy', 'tejcart' ),
                        'type'    => 'textarea',
                        'default' => '',
                        'desc'    => __( 'Optional. Shown as a collapsible "Shipping" panel on every product page. Leave blank to hide.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'product_returns_policy',
                        'label'   => __( 'Returns policy', 'tejcart' ),
                        'type'    => 'textarea',
                        'default' => '',
                        'desc'    => __( 'Optional. Shown as a collapsible "Returns" panel on every product page. Leave blank to hide.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'product_warranty_policy',
                        'label'   => __( 'Warranty / support', 'tejcart' ),
                        'type'    => 'textarea',
                        'default' => '',
                        'desc'    => __( 'Optional. Shown as a collapsible "Warranty & support" panel on every product page. Leave blank to hide.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'allow_remote_image_import',
                        'label'   => __( 'Allow Remote Image Imports', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Permit the CSV importer to download images from external URLs. Disable to block all outbound HTTP during imports.', 'tejcart' ),
                    ),
                );
                break;

            case 'design':
                $fields = array(
                    array(
                        'name'  => 'theme_colors_heading',
                        'label' => __( 'Theme colors', 'tejcart' ),
                        'type'  => 'heading',
                        'desc'  => __( 'Pick up to three brand colors to match your WordPress theme. Leave any color empty to keep the TejCart default. Hover, active, and focus-ring shades are derived automatically — you set one color, we generate the full state machine.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'theme_color_primary',
                        'label'   => __( 'Primary color', 'tejcart' ),
                        'type'    => 'color',
                        'default' => '',
                        'class'   => 'tejcart-theme-color-input',
                        'desc'    => __( 'Used for primary buttons like "Add to cart", selected swatches, and filled badges. Button label color is computed automatically from the background for AA contrast.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'theme_color_accent',
                        'label'   => __( 'Link & accent color', 'tejcart' ),
                        'type'    => 'color',
                        'default' => '',
                        'class'   => 'tejcart-theme-color-input',
                        'desc'    => __( 'Used for inline links and accent text. Leave empty to match the Primary color.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'theme_color_sale',
                        'label'   => __( 'Sale / urgency color', 'tejcart' ),
                        'type'    => 'color',
                        'default' => '',
                        'class'   => 'tejcart-theme-color-input',
                        'desc'    => __( 'Sale price, strikethrough, and urgency badges. Most stores should leave this on the Polaris default red for instant recognition.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'theme_colors_preview',
                        'label'   => __( 'Live preview', 'tejcart' ),
                        'type'    => 'preview',
                        'preview' => 'theme_colors',
                        'desc'    => '',
                    ),
                );
                break;

            case 'cart':
                $fields = array(
                    array(
                        'name'    => 'enable_cart_drawer',
                        'label'   => __( 'Enable Cart Drawer', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Show a slide-out cart drawer instead of redirecting to the cart page.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'cart_page_id',
                        'label'   => __( 'Cart Page', 'tejcart' ),
                        'type'    => 'select',
                        'default' => '',
                        'options' => $this->get_pages_options(),
                    ),
                    array(
                        'name'    => 'redirect_after_add',
                        'label'   => __( 'Redirect After Add to Cart', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Redirect to the cart page after a product is added.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'enable_save_for_later',
                        'label'   => __( 'Enable Save for Later', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Show a "Save for later" action on cart items so customers can move them to a persistent saved list and restore them later.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'enable_wishlist',
                        'label'   => __( 'Enable Wishlist', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Show a "Move to wishlist" action on cart items and enable the [tejcart_wishlist] shortcode. The cart action only appears for logged-in customers.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'cart_minimum_amount',
                        'label'   => __( 'Minimum Order Amount', 'tejcart' ),
                        'type'    => 'decimal',
                        'default' => '0',
                        'desc'    => __( 'Block checkout when the cart subtotal is below this amount. Set to 0 to disable.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'cart_maximum_amount',
                        'label'   => __( 'Maximum Order Amount', 'tejcart' ),
                        'type'    => 'decimal',
                        'default' => '0',
                        'desc'    => __( 'Block checkout when the cart subtotal exceeds this amount. Set to 0 to disable.', 'tejcart' ),
                    ),
                );
                break;

            case 'checkout':
                $fields = array(
                    array(
                        'name'    => 'checkout_page_id',
                        'label'   => __( 'Checkout Page', 'tejcart' ),
                        'type'    => 'select',
                        'default' => '',
                        'options' => $this->get_pages_options(),
                    ),
                    array(
                        'name'    => 'thankyou_page_id',
                        'label'   => __( 'Thank You Page', 'tejcart' ),
                        'type'    => 'select',
                        'default' => '',
                        'options' => $this->get_pages_options(),
                    ),
                    array(
                        'name'    => 'guest_checkout',
                        'label'   => __( 'Guest Checkout', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Allow customers to check out without creating an account.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'enable_registration',
                        'label'   => __( 'Allow Account Creation at Checkout', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Show a "create an account" checkbox on the checkout page for guests.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'create_account_default',
                        'label'   => __( 'Create Account Checked By Default', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'When account creation is offered at checkout, pre-check the box.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'terms_page_id',
                        'label'   => __( 'Terms & Conditions Page', 'tejcart' ),
                        'type'    => 'select',
                        'default' => '',
                        'options' => $this->get_pages_options(),
                    ),
                    array(
                        'name'    => 'enable_order_notes',
                        'label'   => __( 'Enable Order Notes', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Allow customers to add notes during checkout.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'require_phone',
                        'label'   => __( 'Require Phone Number', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Make the checkout phone field mandatory. Left off by default — a required phone field is a documented cause of checkout abandonment. Turn on only if you need it for delivery.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'show_checkout_menu',
                        'label'   => __( 'Show Menu on Checkout', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Display the active theme\'s primary navigation and footer on the checkout page. Turn off for a distraction-free, conversion-optimised checkout that hides site chrome.', 'tejcart' ),
                    ),
                    array(
                        // Audit #52 / 03 #9 — pending-order reaper TTL.
                        // Previously wizard-only; exposed here so
                        // merchants can tune without re-running the
                        // wizard.
                        'name'    => 'pending_order_timeout',
                        'label'   => __( 'Pending order timeout (hours)', 'tejcart' ),
                        'type'    => 'number',
                        'default' => 24,
                        'min'     => '1',
                        'max'     => '720',
                        'desc'    => __( 'Orders that stay in `pending` longer than this are auto-cancelled and their stock is released. Default 24h.', 'tejcart' ),
                    ),
                );
                break;

            case 'payments':

                $fields = array();
                break;

            case 'emails':
                $fields = array(
                    array(
                        'name'    => 'from_name',
                        'label'   => __( '"From" Name', 'tejcart' ),
                        'type'    => 'text',
                        'default' => get_bloginfo( 'name' ),
                        'desc'    => __( 'The name that appears in outgoing TejCart emails.', 'tejcart' ),
                    ),
                    array(
                        // Audit #49 / 03 #4 — typed sanitisation so
                        // garbage like "admin@" never round-trips into
                        // an outbound envelope or template mailto:.
                        'name'    => 'from_email',
                        'label'   => __( '"From" Email', 'tejcart' ),
                        'type'    => 'email',
                        'default' => get_option( 'admin_email' ),
                    ),
                    array(
                        // Audit #52 / 03 #9 — surfaced from wizard.
                        'name'    => 'support_email',
                        'label'   => __( 'Support email', 'tejcart' ),
                        'type'    => 'email',
                        'default' => get_option( 'admin_email' ),
                        'desc'    => __( 'Shown to customers as the "Need help?" address on receipts. Defaults to the admin email; set a dedicated support mailbox for higher-traffic stores.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'header_image',
                        'label'   => __( 'Header Image URL', 'tejcart' ),
                        'type'    => 'url',
                        'default' => '',
                        'desc'    => __( 'URL of the image shown in the email header.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'footer_text',
                        'label'   => __( 'Footer Text', 'tejcart' ),
                        'type'    => 'textarea',
                        'default' => get_bloginfo( 'name' ),
                    ),
                    array(
                        // Audit #51 / 03 #7 — label was misleading
                        // (admins read "Background Color" but it
                        // actually paints the email header band, link
                        // accents, and CTA totals — never the page
                        // background, which is hardcoded #f4f5f7 in
                        // email-header.php). Also aligned the UI
                        // default with the reader fallback in
                        // src/functions.php:1875 so a fresh install
                        // doesn't render the header in #0073aa while
                        // the admin sees the #f7f7f7 swatch.
                        'name'    => 'email_background_color',
                        'label'   => __( 'Brand / Accent Color', 'tejcart' ),
                        'type'    => 'color',
                        'default' => '#0073aa',
                        'desc'    => __( 'Paints the email header band, link colours, and totals row. Does not change the page background.', 'tejcart' ),
                    ),
                );
                break;

            case 'shipping':
                $fields = array(
                    array(
                        'name'    => 'enable_shipping',
                        'label'   => __( 'Enable Shipping', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Enable shipping calculations.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'shipping_origin_country',
                        'label'   => __( 'Shipping Origin Country', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'US',
                        'options' => $this->get_country_options(),
                    ),
                    array(
                        'name'    => 'default_shipping_method',
                        'label'   => __( 'Default Shipping Method', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'flat_rate',
                        'options' => array(
                            'flat_rate'    => __( 'Flat Rate', 'tejcart' ),
                            'free'         => __( 'Free Shipping', 'tejcart' ),
                            'local_pickup' => __( 'Local Pickup', 'tejcart' ),
                        ),
                    ),
                    array(
                        'name'    => 'shipping_flat_rate',
                        'label'   => __( 'Default Shipping Cost', 'tejcart' ),
                        'type'    => 'decimal',
                        'default' => '0',
                        'desc'    => __( 'Cost applied when the default shipping method is Flat Rate and no shipping zone matches the customer address.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'shipping_free_threshold',
                        'label'   => __( 'Free Shipping Threshold', 'tejcart' ),
                        'type'    => 'decimal',
                        'default' => '0',
                        'desc'    => __( 'Cart subtotal at or above which shipping becomes free. Set to 0 to disable.', 'tejcart' ),
                    ),
                    array(
                        'name'  => 'shipping_zones_note',
                        'label' => __( 'Shipping Zones', 'tejcart' ),
                        'type'  => 'note',
                        'desc'  => sprintf(
                            /* translators: %s: link to the Shipping Zones sub-section. */
                            __( 'Create per-region shipping methods and rates on the %s tab.', 'tejcart' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=shipping&section=zones' ) ) . '">' . esc_html__( 'Shipping Zones', 'tejcart' ) . '</a>'
                        ),
                    ),
                );
                break;

            case 'tax':
                $fields = array(
                    array(
                        'name'    => 'enable_tax',
                        'label'   => __( 'Enable Taxes', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Enable tax rates and calculations.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'prices_include_tax',
                        'label'   => __( 'Prices Include Tax', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Product prices are entered inclusive of tax.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'tax_based_on',
                        'label'   => __( 'Calculate Tax Based On', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'billing_address',
                        'options' => array(
                            'billing_address'  => __( 'Customer billing address', 'tejcart' ),
                            'shipping_address' => __( 'Customer shipping address', 'tejcart' ),
                            'store_address'    => __( 'Shop base address', 'tejcart' ),
                        ),
                    ),
                    array(
                        'name'    => 'tax_display_shop',
                        'label'   => __( 'Display Prices in Shop', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'exclusive',
                        'options' => array(
                            'inclusive' => __( 'Including tax', 'tejcart' ),
                            'exclusive' => __( 'Excluding tax', 'tejcart' ),
                        ),
                    ),
                    array(
                        'name'    => 'tax_display_cart',
                        'label'   => __( 'Display Prices in Cart', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'exclusive',
                        'options' => array(
                            'inclusive' => __( 'Including tax', 'tejcart' ),
                            'exclusive' => __( 'Excluding tax', 'tejcart' ),
                        ),
                    ),
                    array(
                        'name'    => 'price_display_suffix',
                        'label'   => __( 'Price Display Suffix', 'tejcart' ),
                        'type'    => 'text',
                        'default' => '',
                        'desc'    => __( 'Text appended to prices on the shop front-end (e.g. "incl. VAT"). Supports the {price_including_tax} and {price_excluding_tax} placeholders.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'tax_round_at_subtotal',
                        'label'   => __( 'Rounding', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Round tax at subtotal level, instead of rounding per line.', 'tejcart' ),
                    ),
                    array(
                        'name'  => 'tax_rates_note',
                        'label' => __( 'Tax Rates', 'tejcart' ),
                        'type'  => 'note',
                        'desc'  => sprintf(
                            /* translators: 1: link to the Tax Rates sub-section. 2: link to the Tax Providers sub-section. */
                            __( 'Configure per-country and per-state tax rates on the %1$s tab, or connect a live calculation service on the %2$s tab.', 'tejcart' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates' ) ) . '">' . esc_html__( 'Tax Rates', 'tejcart' ) . '</a>',
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=providers' ) ) . '">' . esc_html__( 'Tax Providers', 'tejcart' ) . '</a>'
                        ),
                    ),
                );
                break;

            case 'advanced':
                $fields = array(
                    array(
                        'name'  => 'advanced_api_heading',
                        'label' => __( 'REST API', 'tejcart' ),
                        'type'  => 'heading',
                        'desc'  => __( 'Control programmatic access to your store.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'api_enabled',
                        'label'   => __( 'Enable REST API', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'yes',
                        'desc'    => __( 'Enable the TejCart REST API.', 'tejcart' ),
                    ),
                    array(
                        'name'  => 'api_keys_note',
                        'label' => __( 'API Keys', 'tejcart' ),
                        'type'  => 'note',
                        'desc'  => sprintf(
                            /* translators: %s: link to the API Keys admin page. */
                            __( 'Create and revoke consumer keys on the %s page.', 'tejcart' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=api-keys' ) ) . '">' . esc_html__( 'API Keys', 'tejcart' ) . '</a>'
                        ),
                    ),
                    array(
                        'name'  => 'advanced_diagnostics_heading',
                        'label' => __( 'Diagnostics', 'tejcart' ),
                        'type'  => 'heading',
                        'desc'  => __( 'Troubleshooting and health checks.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'log_level',
                        'label'   => __( 'Log Level', 'tejcart' ),
                        'type'    => 'select',
                        'default' => 'error',
                        'options' => array(
                            'off'       => __( 'Off — no logs are written', 'tejcart' ),
                            'emergency' => __( 'Emergency', 'tejcart' ),
                            'alert'     => __( 'Alert', 'tejcart' ),
                            'critical'  => __( 'Critical', 'tejcart' ),
                            'error'     => __( 'Error (recommended for production)', 'tejcart' ),
                            'warning'   => __( 'Warning', 'tejcart' ),
                            'notice'    => __( 'Notice', 'tejcart' ),
                            'info'      => __( 'Info', 'tejcart' ),
                            'debug'     => __( 'Debug (verbose — development only)', 'tejcart' ),
                        ),
                        'desc'    => __( 'Minimum severity recorded in the TejCart log files. Entries below this level are dropped at the gate.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'log_retention_days',
                        'label'   => __( 'Log Retention (days)', 'tejcart' ),
                        'type'    => 'number',
                        'default' => '30',
                        'min'     => '0',
                        'max'     => '365',
                        'step'    => '1',
                        'desc'    => __( 'Log files older than this are deleted automatically. Set to 0 to keep logs forever (not recommended).', 'tejcart' ),
                    ),
                    array(
                        'name'  => 'diagnostics_note',
                        'label' => __( 'Status & Logs', 'tejcart' ),
                        'type'  => 'note',
                        'desc'  => sprintf(
                            /* translators: 1: link to System Status. 2: link to Logs. 3: link to Tools. 4: link to Scheduled Actions. */
                            __( 'Review environment info on %1$s, inspect recent events on %2$s, run maintenance actions from %3$s, or view background tasks on %4$s.', 'tejcart' ),
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=system-status' ) ) . '">' . esc_html__( 'System Status', 'tejcart' ) . '</a>',
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=logs' ) ) . '">' . esc_html__( 'Logs', 'tejcart' ) . '</a>',
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=tools' ) ) . '">' . esc_html__( 'Tools', 'tejcart' ) . '</a>',
                            '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=scheduled-actions' ) ) . '">' . esc_html__( 'Scheduled Actions', 'tejcart' ) . '</a>'
                        ),
                    ),
                    array(
                        'name'  => 'advanced_security_heading',
                        'label' => __( 'Security', 'tejcart' ),
                        'type'  => 'heading',
                        'desc'  => __( 'Bot mitigation and abuse protection.', 'tejcart' ),
                    ),
                    array(
                        'name'  => 'captcha_note',
                        'label' => __( 'Captcha / Bot Protection', 'tejcart' ),
                        'type'  => 'note',
                        // Bot mitigation ships as the optional `captcha`
                        // module. When it is enabled, link to its
                        // Advanced → Captcha section; when not, point the
                        // merchant at the Modules page to turn it on (the
                        // section does not exist until the module loads).
                        'desc'  => (
                            class_exists( '\\TejCart\\Modules\\Module_Manager' )
                            && \TejCart\Modules\Module_Manager::instance()->is_enabled( 'captcha' )
                        )
                            ? sprintf(
                                /* translators: %s: link to the Captcha settings sub-section. */
                                __( 'Configure Cloudflare Turnstile, hCaptcha or reCAPTCHA v3 in front of login, checkout, cart and coupon endpoints on the %s page.', 'tejcart' ),
                                '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=captcha' ) ) . '">' . esc_html__( 'Captcha', 'tejcart' ) . '</a>'
                            )
                            : sprintf(
                                /* translators: %s: link to the Modules admin page. */
                                __( 'Add Cloudflare Turnstile, hCaptcha or reCAPTCHA v3 in front of login, checkout, cart and coupon endpoints by enabling the Captcha / Bot Protection module on the %s page.', 'tejcart' ),
                                '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-modules' ) ) . '">' . esc_html__( 'Modules', 'tejcart' ) . '</a>'
                            ),
                    ),
                    array(
                        'name'  => 'advanced_data_heading',
                        'label' => __( 'Data', 'tejcart' ),
                        'type'  => 'heading',
                        'desc'  => __( 'How TejCart handles your data.', 'tejcart' ),
                    ),
                    array(
                        'name'    => 'delete_data_on_uninstall',
                        'label'   => __( 'Delete Data on Uninstall', 'tejcart' ),
                        'type'    => 'checkbox',
                        'default' => 'no',
                        'desc'    => __( 'Remove all TejCart data when the plugin is deleted.', 'tejcart' ),
                    ),
                );
                break;
        }

        /**
         * Filter the settings fields for a specific tab.
         *
         * @param array $fields Default fields for the tab.
         */
        return apply_filters( 'tejcart_get_settings_' . $tab_id, $fields );
    }

    /**
     * Helper – return a list of published pages as value => label pairs
     * suitable for select field options.
     *
     * @return array
     */
    protected function get_pages_options() {
        $pages   = get_pages( array( 'post_status' => 'publish' ) );
        $options = array( '' => __( '— Select a page —', 'tejcart' ) );

        if ( $pages ) {
            foreach ( $pages as $page ) {
                $options[ $page->ID ] = $page->post_title;
            }
        }

        return $options;
    }

    /**
     * Helper – return every ISO-3166 alpha-2 country code => English name pair,
     * sorted by display label so admins get an alphabetical dropdown. Data is
     * loaded from {@see \TejCart\Tax\Tax_Manager::get_countries()}.
     *
     * @return array<string, string>
     */
    protected function get_country_options() {
        $countries = \TejCart\Tax\Tax_Manager::get_countries();
        if ( ! is_array( $countries ) || empty( $countries ) ) {
            return array();
        }
        asort( $countries );
        return $countries;
    }

    /**
     * Helper – return every ISO 4217 currency code => "Name (symbol)" label
     * pair, sorted by name so the dropdown is alphabetical. Data is loaded
     * from {@see \TejCart\Money\Currencies::get_currencies()}.
     *
     * @return array<string, string>
     */
    protected function get_currency_options() {
        $dataset = \TejCart\Money\Currencies::get_currencies();
        if ( ! is_array( $dataset ) || empty( $dataset ) ) {
            return array();
        }

        $options = array();
        foreach ( $dataset as $code => $row ) {
            $name   = isset( $row['name'] ) ? (string) $row['name'] : (string) $code;
            $symbol = isset( $row['symbol'] ) ? (string) $row['symbol'] : '';
            $options[ (string) $code ] = '' !== $symbol
                ? sprintf( '%s (%s)', $name, $symbol )
                : $name;
        }

        natcasesort( $options );
        return $options;
    }

    /**
     * Build the `store_state` field definition. Renders as a `select` whose
     * options come from {@see \TejCart\Tax\Tax_Manager::get_states()} when the
     * currently-configured country has a state list, otherwise falls back to a
     * free-text input. The admin JS at assets/js/tejcart-admin-settings-locale.js
     * swaps the control live when the country dropdown changes.
     *
     * @return array<string, mixed>
     */
    protected function build_store_state_field() {
        $country = (string) get_option( 'tejcart_store_country', 'US' );
        $states  = \TejCart\Tax\Tax_Manager::get_states( $country );

        $field = array(
            'name'        => 'store_state',
            'label'       => __( 'State / Province', 'tejcart' ),
            'default'     => '',
            'description' => __( 'State or province of your shipping origin. Required by some carrier APIs (FedEx, UPS, …).', 'tejcart' ),
        );

        if ( is_array( $states ) && ! empty( $states ) ) {
            $field['type']    = 'select';
            $field['options'] = array_merge(
                array( '' => __( '— Select a state —', 'tejcart' ) ),
                $states
            );
            $field['class']   = 'tejcart-settings-state-select';
        } else {
            $field['type']  = 'text';
            $field['class'] = 'tejcart-settings-state-text';
        }

        // Tag so tejcart-admin-settings-locale.js can swap this field
        // when its paired country dropdown changes.
        $field['data'] = array( 'tejcart-state-pair' => 'store' );

        return $field;
    }
}
