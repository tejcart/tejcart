<?php
/**
 * WP option keys used by the Currency Switcher module.
 *
 * @package TejCart\Currency_Switcher
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralised option-key registry. Defined as class constants so a typo
 * is a fatal at parse time rather than a silent option-read miss.
 */
final class Options {
    public const CURRENCIES               = 'tejcart_csw_options';
    public const PRICE_ADJUSTMENT         = 'tejcart_csw_price_adjustment_settings';
    public const CHECKOUT_GATEWAYS        = 'tejcart_csw_checkout_options';
    public const CHECKOUT_DIFF_CURRENCY   = 'tejcart_csw_checkout_diff_currency';
    public const PRODUCT_PAGE_ENABLE      = 'tejcart_csw_product_page_enable';
    public const SWITCHER_POSITION        = 'tejcart_csw_switcher_position';
    public const SIDEBAR_PAGES            = 'tejcart_csw_sidebar_pages';
    public const SIDEBAR_POSITION         = 'tejcart_csw_sidebar_position';
    public const SIDEBAR_DESIGN           = 'tejcart_csw_sidebar_design';
    public const SIDEBAR_DEFAULT_COLOR    = 'tejcart_csw_design2_default_color';
    public const SIDEBAR_ACTIVE_COLOR     = 'tejcart_csw_design2_active_color';
    public const MENU_PAGES               = 'tejcart_csw_menu_pages';
    public const SHOW_FLAG                = 'tejcart_csw_global_flag';
    public const SHOW_NAME                = 'tejcart_csw_global_name';
    public const SHOW_SYMBOL              = 'tejcart_csw_global_symbol';
    public const SHOW_CODE                = 'tejcart_csw_global_code';
    public const ENABLE_GEOLOCATION       = 'tejcart_csw_enable_geolocation';
    public const LAST_RATE_UPDATE         = 'tejcart_csw_last_rate_update';

    public const COOKIE_CURRENCY     = 'tejcart_csw_currency';
    public const COOKIE_CURRENCY_GEO = 'tejcart_csw_currency_geo';

    public const ORDER_META_RATE              = '_tejcart_csw_fx_rate';
    public const ORDER_META_ORDER_CURRENCY    = '_tejcart_csw_order_currency';
    public const ORDER_META_BASE_CURRENCY     = '_tejcart_csw_base_currency';
    public const ORDER_META_BASE_TOTAL        = '_tejcart_csw_base_total';
    public const ORDER_META_BASE_TAX_TOTAL    = '_tejcart_csw_base_tax_total';
    public const ORDER_META_BASE_SHIP_TOTAL   = '_tejcart_csw_base_shipping_total';
    public const ORDER_META_BASE_NET_TOTAL    = '_tejcart_csw_base_net_total';

    public const NONCE_ACTION  = 'tejcart_csw_currency_action';
    public const NONCE_SWITCH  = 'tejcart_csw_currency_switch';

    public const CRON_HOOK = 'tejcart_csw_update_rates_cron';

    public const RATE_TYPE_AUTO  = 'Auto';
    public const RATE_TYPE_FIXED = 'Fixed';

    public const FEE_FIXED      = 'fixed';
    public const FEE_PERCENTAGE = 'percentage';

    public const POS_LEFT        = 'left';
    public const POS_RIGHT       = 'right';
    public const POS_LEFT_SPACE  = 'left_space';
    public const POS_RIGHT_SPACE = 'right_space';
}
