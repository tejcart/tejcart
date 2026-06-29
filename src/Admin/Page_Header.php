<?php
/**
 * Shared admin page-header renderer.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the canonical TejCart admin page header so every screen
 * (core, bundled modules, sibling addons) gets the same band of title,
 * subtitle and optional actions without each surface hand-rolling the
 * markup.
 *
 * Two skins map to the two header patterns already in use across the
 * admin surface:
 *
 *   - STYLE_LIST   — the SaaS-style header used by list / landing
 *                    pages (Dashboard, Orders, Products, Customers,
 *                    Coupons, Disputes, Returns, Gift Cards, Modules,
 *                    Subscriptions). Renders `<header class="nxc-page-header">`
 *                    with the `nxc-page-header__title / __subtitle /
 *                    __actions` blocks.
 *   - STYLE_FORM   — the form/detail-page header used by Order_Admin,
 *                    Payment_Method_Settings, the gateway-config screen,
 *                    the store migration tool, and the subscriptions
 *                    detail screen. Renders `<div class="tejcart-page-header">`
 *                    with the `tejcart-page-header-content` /
 *                    `tejcart-page-subtitle` / `tejcart-page-header-actions`
 *                    blocks, and supports an optional "back" link.
 *
 * Both skins emit a trailing `<span class="wp-header-end"></span>` so
 * WordPress's admin-notices machinery can target the right insertion
 * point — matching what every hand-rolled site already does.
 *
 * Use:
 *
 *   \TejCart\Admin\Page_Header::list(
 *       __( 'Disputes', 'tejcart' ),
 *       __( 'Triage chargebacks across gateways.', 'tejcart' ),
 *       '<a class="nxc-btn" href="' . esc_url( $export_url ) . '">…</a>'
 *   );
 *
 *   \TejCart\Admin\Page_Header::form(
 *       sprintf( __( 'Order %s', 'tejcart' ), $order_number ),
 *       __( 'Review items, process refunds and manage order details.', 'tejcart' ),
 *       [
 *           'back_url'   => admin_url( 'admin.php?page=tejcart-orders' ),
 *           'back_label' => __( 'Back to Orders', 'tejcart' ),
 *           'title_html' => $status_badge_markup,
 *       ]
 *   );
 *
 * The helper is intentionally stateless and final — it is presentation,
 * not a service. All HTML is pre-escaped; callers pass plain-text titles
 * and supply already-escaped HTML through the `_html` keys.
 */
final class Page_Header {

    public const STYLE_LIST = 'list';
    public const STYLE_FORM = 'form';

    /**
     * Render the SaaS-style header used by list / landing pages.
     *
     * @param string $title        Plain-text title.
     * @param string $subtitle     Plain-text subtitle. Empty hides it.
     * @param string $actions_html Pre-escaped HTML for the right-aligned actions slot.
     */
    public static function list( string $title, string $subtitle = '', string $actions_html = '' ): void {
        $title = trim( $title );
        if ( '' === $title ) {
            return;
        }
        ?>
        <header class="nxc-page-header">
            <div class="nxc-page-header__title">
                <h1><?php echo esc_html( $title ); ?></h1>
                <?php if ( '' !== $subtitle ) : ?>
                    <p class="nxc-page-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( '' !== $actions_html ) : ?>
                <div class="nxc-page-header__actions">
                    <?php echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-escaped HTML. ?>
                </div>
            <?php endif; ?>
        </header>
        <span class="wp-header-end"></span>
        <?php
    }

    /**
     * Render the form/detail-page header used by Order_Admin and
     * gateway-config-style screens.
     *
     * Supported `$options` keys:
     *   - back_url   (string)  Optional URL for the "back to…" link.
     *   - back_label (string)  Label for the back link. Defaults to "Back".
     *   - title_html (string)  Extra pre-escaped HTML appended inside the
     *                          <h1> (used for status badges next to the title).
     *   - actions_html (string) Pre-escaped HTML for a right-aligned actions block.
     *
     * @param string               $title    Plain-text title.
     * @param string               $subtitle Plain-text subtitle. Empty hides it.
     * @param array<string,string> $options  Optional extras (see above).
     */
    public static function form( string $title, string $subtitle = '', array $options = array() ): void {
        $title = trim( $title );
        if ( '' === $title ) {
            return;
        }

        $back_url     = isset( $options['back_url'] ) ? (string) $options['back_url'] : '';
        $back_label   = isset( $options['back_label'] ) ? (string) $options['back_label'] : __( 'Back', 'tejcart' );
        $title_html   = isset( $options['title_html'] ) ? (string) $options['title_html'] : '';
        $actions_html = isset( $options['actions_html'] ) ? (string) $options['actions_html'] : '';
        ?>
        <div class="tejcart-page-header">
            <div class="tejcart-page-header-content">
                <?php if ( '' !== $back_url ) : ?>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="tejcart-back-link">
                        <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                        <?php echo esc_html( $back_label ); ?>
                    </a>
                <?php endif; ?>
                <h1>
                    <?php echo esc_html( $title ); ?>
                    <?php
                    if ( '' !== $title_html ) {
                        echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-escaped HTML.
                    }
                    ?>
                </h1>
                <?php if ( '' !== $subtitle ) : ?>
                    <p class="tejcart-page-subtitle"><?php echo esc_html( $subtitle ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( '' !== $actions_html ) : ?>
                <div class="tejcart-page-header-actions">
                    <?php echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-escaped HTML. ?>
                </div>
            <?php endif; ?>
        </div>
        <span class="wp-header-end"></span>
        <?php
    }
}
