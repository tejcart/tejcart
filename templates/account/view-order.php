<?php
/**
 * Single order detail template.
 *
 * Two-column layout: items + totals on the left, meta (status, date,
 * addresses, notes) in a sticky side rail on the right. Collapses to
 * a single column below 900px via CSS.
 *
 * @package TejCart\Templates\Account
 *
 * @var int   $customer_id Current customer user ID.
 * @var array $orders      Customer orders.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
$order    = $order_id ? tejcart_get_order( $order_id ) : null;

$account_url = get_permalink();

if ( ! $order || (int) $order->get_customer_id() !== $customer_id ) : ?>
    <section class="tejcart-account-card">
        <div class="tejcart-account-empty">
            <span class="tejcart-account-empty__icon" aria-hidden="true">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
            </span>
            <h3 class="tejcart-account-empty__title"><?php esc_html_e( 'Order not found', 'tejcart' ); ?></h3>
            <p class="tejcart-account-empty__body"><?php esc_html_e( "This order either doesn't exist or belongs to a different account.", 'tejcart' ); ?></p>
            <div class="tejcart-account-empty__actions">
                <a class="tejcart-btn tejcart-btn--secondary" href="<?php echo esc_url( add_query_arg( 'tab', 'orders', $account_url ) ); ?>">
                    <?php esc_html_e( 'Back to orders', 'tejcart' ); ?>
                </a>
            </div>
        </div>
    </section>
    <?php return; ?>
<?php endif;

$items            = $order->get_items();
$order_currency   = (string) $order->get_currency();
$billing_address  = $order->get_formatted_billing_address();
$shipping_address = $order->get_formatted_shipping_address();
$order_notes      = $order->get_customer_notes();

/**
 * Fires at the top of the view-order template.
 *
 * @param \TejCart\Order\Order $order The order instance.
 */
do_action( 'tejcart_view_order', $order );
?>

<div class="tejcart-account-view-order-page">

    <a class="tejcart-account-backlink" href="<?php echo esc_url( add_query_arg( 'tab', 'orders', $account_url ) ); ?>">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
        <?php esc_html_e( 'Back to orders', 'tejcart' ); ?>
    </a>

    <header class="tejcart-account-subpage-header">
        <div class="tejcart-account-subpage-header__text">
            <h2 class="tejcart-account-subpage-header__title">
                <?php
                printf(
                    /* translators: %s: order number */
                    esc_html__( 'Order #%s', 'tejcart' ),
                    esc_html( $order->get_order_number() )
                );
                ?>
            </h2>
            <p class="tejcart-account-subpage-header__subtitle">
                <?php
                printf(
                    /* translators: %s: date */
                    esc_html__( 'Placed on %s', 'tejcart' ),
                    esc_html( $order->get_date_created() )
                );
                ?>
            </p>
        </div>
        <div class="tejcart-account-subpage-header__actions">
            <span class="tejcart-status-badge tejcart-status-badge--<?php echo esc_attr( $order->get_status() ); ?>">
                <?php echo esc_html( tejcart_get_order_status_label( $order->get_status() ) ); ?>
            </span>
        </div>
    </header>

    <div class="tejcart-account-view-order">

        <div class="tejcart-account-view-order__main">

            <section class="tejcart-account-card" aria-labelledby="tejcart-order-items-title">
                <header class="tejcart-account-card__header">
                    <h3 id="tejcart-order-items-title" class="tejcart-account-card__title">
                        <?php esc_html_e( 'Items', 'tejcart' ); ?>
                    </h3>
                </header>
                <div class="tejcart-account-order-items">
                    <?php foreach ( $items as $item ) :

                        // line_total is BIGINT minor units in the order's currency — convert for display.
                        $item_total_raw = isset( $item->line_total ) ? (int) $item->line_total : (int) ( $item->total ?? 0 );
                        $item_total     = \TejCart\Money\Currency::from_minor_units( $item_total_raw, $order_currency );
                        $item_name      = isset( $item->product_name ) ? (string) $item->product_name : '';
                        $item_qty       = isset( $item->quantity ) ? (int) $item->quantity : 0;
                        ?>
                        <div class="tejcart-account-order-item">
                            <div>
                                <span class="tejcart-account-order-item__name"><?php echo esc_html( $item_name ); ?></span>
                                <span class="tejcart-account-order-item__qty">&times;&nbsp;<?php echo esc_html( (string) $item_qty ); ?></span>
                            </div>
                            <span class="tejcart-account-order-item__total"><?php echo wp_kses_post( tejcart_price( $item_total, $order_currency ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php

                $nxa_subtotal = (float) ( $order->get_subtotal() ?? 0 );
                $nxa_shipping = (float) ( $order->get_shipping_total() ?? 0 );
                $nxa_tax      = (float) ( $order->get_tax_total() ?? 0 );
                $nxa_discount = (float) ( $order->get_discount_total() ?? 0 );
                $nxa_total    = (float) ( $order->get_total() ?? 0 );
                ?>
                <div class="tejcart-account-order-totals" aria-label="<?php esc_attr_e( 'Order totals', 'tejcart' ); ?>">
                    <div class="tejcart-account-order-totals__row">
                        <span><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></span>
                        <span><?php echo wp_kses_post( tejcart_price( $nxa_subtotal, $order_currency ) ); ?></span>
                    </div>
                    <?php if ( $nxa_shipping > 0 ) : ?>
                        <div class="tejcart-account-order-totals__row">
                            <span><?php esc_html_e( 'Shipping', 'tejcart' ); ?></span>
                            <span><?php echo wp_kses_post( tejcart_price( $nxa_shipping, $order_currency ) ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $nxa_tax > 0 ) : ?>
                        <div class="tejcart-account-order-totals__row">
                            <span><?php esc_html_e( 'Tax', 'tejcart' ); ?></span>
                            <span><?php echo wp_kses_post( tejcart_price( $nxa_tax, $order_currency ) ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ( $nxa_discount > 0 ) : ?>
                        <div class="tejcart-account-order-totals__row tejcart-account-order-totals__row--discount">
                            <span><?php esc_html_e( 'Discount', 'tejcart' ); ?></span>
                            <span>&minus;<?php echo wp_kses_post( tejcart_price( $nxa_discount, $order_currency ) ); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php foreach ( tejcart_get_order_fee_lines( $order ) as $nxa_fee ) : ?>
                        <div class="tejcart-account-order-totals__row tejcart-account-order-totals__row--fee">
                            <span><?php echo esc_html( $nxa_fee['label'] ); ?></span>
                            <span><?php echo wp_kses_post( tejcart_price_from_minor_units( (int) $nxa_fee['amount'], $order_currency ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="tejcart-account-order-totals__row tejcart-account-order-totals__row--grand">
                        <span><?php esc_html_e( 'Total', 'tejcart' ); ?></span>
                        <span><?php echo wp_kses_post( tejcart_price( $nxa_total, $order_currency ) ); ?></span>
                    </div>
                </div>
            </section>

            <?php if ( ! empty( $order_notes ) ) : ?>
                <section class="tejcart-account-card" aria-labelledby="tejcart-order-notes-title">
                    <header class="tejcart-account-card__header">
                        <h3 id="tejcart-order-notes-title" class="tejcart-account-card__title">
                            <?php esc_html_e( 'Order notes', 'tejcart' ); ?>
                        </h3>
                    </header>
                    <div class="tejcart-account-notes">
                        <?php foreach ( $order_notes as $note ) : ?>
                            <div class="tejcart-account-note">
                                <span class="tejcart-account-note__date"><?php echo esc_html( $note->get_date() ); ?></span>
                                <p class="tejcart-account-note__content"><?php echo wp_kses_post( $note->get_content() ); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        </div>

        <aside class="tejcart-account-view-order__side" aria-label="<?php esc_attr_e( 'Order details', 'tejcart' ); ?>">

            <section class="tejcart-account-card">
                <header class="tejcart-account-card__header">
                    <h3 class="tejcart-account-card__title"><?php esc_html_e( 'Details', 'tejcart' ); ?></h3>
                </header>
                <dl class="tejcart-account-meta">
                    <div class="tejcart-account-meta__row">
                        <dt class="tejcart-account-meta__label"><?php esc_html_e( 'Status', 'tejcart' ); ?></dt>
                        <dd class="tejcart-account-meta__value">
                            <span class="tejcart-status-badge tejcart-status-badge--<?php echo esc_attr( $order->get_status() ); ?>">
                                <?php echo esc_html( tejcart_get_order_status_label( $order->get_status() ) ); ?>
                            </span>
                        </dd>
                    </div>
                    <div class="tejcart-account-meta__row">
                        <dt class="tejcart-account-meta__label"><?php esc_html_e( 'Order #', 'tejcart' ); ?></dt>
                        <dd class="tejcart-account-meta__value">#<?php echo esc_html( $order->get_order_number() ); ?></dd>
                    </div>
                    <div class="tejcart-account-meta__row">
                        <dt class="tejcart-account-meta__label"><?php esc_html_e( 'Placed', 'tejcart' ); ?></dt>
                        <dd class="tejcart-account-meta__value"><?php echo esc_html( $order->get_date_created() ); ?></dd>
                    </div>
                </dl>
            </section>

            <section class="tejcart-account-card">
                <header class="tejcart-account-card__header">
                    <h3 class="tejcart-account-card__title"><?php esc_html_e( 'Billing address', 'tejcart' ); ?></h3>
                </header>
                <?php if ( $billing_address ) : ?>
                    <address class="tejcart-account-address-card__display"><?php echo wp_kses_post( $billing_address ); ?></address>
                <?php else : ?>
                    <p class="tejcart-account-address-card__empty"><?php esc_html_e( 'No billing address on file.', 'tejcart' ); ?></p>
                <?php endif; ?>
            </section>

            <?php if ( $shipping_address ) : ?>
                <section class="tejcart-account-card">
                    <header class="tejcart-account-card__header">
                        <h3 class="tejcart-account-card__title"><?php esc_html_e( 'Shipping address', 'tejcart' ); ?></h3>
                    </header>
                    <address class="tejcart-account-address-card__display"><?php echo wp_kses_post( $shipping_address ); ?></address>
                </section>
            <?php endif; ?>

        </aside>

    </div>

</div>
