<?php
/**
 * Shared inline-style strings for transactional email content templates.
 *
 * Loaded via `include __DIR__ . '/parts/styles.php';` at the top of each
 * content template. Centralising the values here avoids style drift
 * between templates without forcing a CSS inliner at runtime (B2: no
 * Composer-runtime dependencies). Each variable is a fully-quoted
 * `style="..."` payload — escape it with `esc_attr()` before outputting,
 * or echo it raw inside an attribute (it is already pre-escaped against
 * its origin values, which are constants).
 *
 * Class names referenced here (`nx-text`, `nx-muted`, `nx-th`,
 * `nx-table-row`, `nx-h2`, `nx-link`) are picked up by the dark-mode
 * media query in `email-header.php`.
 *
 * @package TejCart\Templates\Emails\Parts
 *
 * Provides the following variables in caller scope:
 *
 * @var string $nx_font_stack
 * @var string $nx_brand_color
 * @var string $nx_h3_style
 * @var string $nx_p_style
 * @var string $nx_muted_style
 * @var string $nx_table_style
 * @var string $nx_th_style
 * @var string $nx_th_center
 * @var string $nx_th_right
 * @var string $nx_td_style
 * @var string $nx_tdc_style
 * @var string $nx_tdr_style
 * @var string $nx_tot_label
 * @var string $nx_tot_value
 * @var string $nx_addr_style
 * @var string $nx_link_style
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- These are template-scope variables exposed to the parent template via include(); they are not true globals. The "nx-" prefix matches the email CSS class namespace used across all transactional templates.
$nx_font_stack  = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
$nx_brand_color = function_exists( 'tejcart_email_brand_color' ) ? tejcart_email_brand_color() : '#0073aa';

$nx_muted   = '#6b7280';
$nx_border  = '#e5e7eb';
$nx_th_bg   = '#f4f5f7';

$nx_h3_style    = 'margin:24px 0 8px;padding:0;font-family:' . $nx_font_stack . ';font-size:16px;line-height:22px;font-weight:700;color:#2b2f33;';
$nx_p_style     = 'margin:0 0 14px;padding:0;font-family:' . $nx_font_stack . ';font-size:15px;line-height:22px;color:#2b2f33;';
$nx_muted_style = 'margin:18px 0 0;padding:0;font-family:' . $nx_font_stack . ';font-size:13px;line-height:20px;color:' . $nx_muted . ';';
$nx_table_style = 'width:100%;border-collapse:collapse;margin:0 0 12px;font-family:' . $nx_font_stack . ';font-size:14px;color:#2b2f33;';
$nx_th_style    = 'padding:10px 12px;text-align:left;background-color:' . $nx_th_bg . ';border-bottom:1px solid ' . $nx_border . ';font-weight:600;font-size:13px;color:#2b2f33;';
$nx_th_center   = 'padding:10px 12px;text-align:center;background-color:' . $nx_th_bg . ';border-bottom:1px solid ' . $nx_border . ';font-weight:600;font-size:13px;color:#2b2f33;';
$nx_th_right    = 'padding:10px 12px;text-align:right;background-color:' . $nx_th_bg . ';border-bottom:1px solid ' . $nx_border . ';font-weight:600;font-size:13px;color:#2b2f33;';
$nx_td_style    = 'padding:10px 12px;text-align:left;border-bottom:1px solid ' . $nx_border . ';color:#2b2f33;vertical-align:top;';
$nx_tdc_style   = 'padding:10px 12px;text-align:center;border-bottom:1px solid ' . $nx_border . ';color:#2b2f33;vertical-align:top;';
$nx_tdr_style   = 'padding:10px 12px;text-align:right;border-bottom:1px solid ' . $nx_border . ';color:#2b2f33;vertical-align:top;';
$nx_tot_label   = 'padding:12px;text-align:right;font-weight:700;border-top:2px solid ' . $nx_border . ';color:#2b2f33;';
$nx_tot_value   = 'padding:12px;text-align:right;font-weight:700;border-top:2px solid ' . $nx_border . ';color:' . $nx_brand_color . ';';
$nx_addr_style  = 'margin:0 0 14px;padding:12px 14px;background-color:' . $nx_th_bg . ';border:1px solid ' . $nx_border . ';border-radius:4px;font-style:normal;font-family:' . $nx_font_stack . ';font-size:14px;line-height:20px;color:#2b2f33;';
$nx_link_style  = 'color:' . $nx_brand_color . ';text-decoration:underline;';
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
