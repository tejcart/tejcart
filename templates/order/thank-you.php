<?php
/**
 * Thank you / order confirmation template.
 *
 * Displayed after a successful checkout. Shows a success hero, the
 * order summary, purchased items with totals, billing / shipping
 * addresses (when present), and primary follow-up actions.
 *
 * @package TejCart\Templates\Order
 *
 * @var \TejCart\Order\Order $order The order instance.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fires at the top of the thank-you page.
 *
 * @param \TejCart\Order\Order $order The order instance.
 */
do_action( 'tejcart_thankyou', $order );

$tejcart_thankyou_gateway = (string) $order->get_payment_method();
if ( '' !== $tejcart_thankyou_gateway ) {
    /**
     * Fires at the top of the thank-you page, scoped to the order's
     * payment method id (e.g. `tejcart_thankyou_bacs`).
     *
     * @param \TejCart\Order\Order $order The order instance.
     */
    do_action( 'tejcart_thankyou_' . $tejcart_thankyou_gateway, $order );
}

$order_number  = $order->get_order_number();
$billing_email = $order->get_customer_email();

$order_date_raw = $order->get_created_at();
$order_date     = '';
if ( ! empty( $order_date_raw ) ) {
    $timestamp      = strtotime( $order_date_raw );
    $date_format    = get_option( 'date_format' );
    $time_format    = get_option( 'time_format' );
    $order_date     = $timestamp
        ? date_i18n( $date_format . ' \a\t ' . $time_format, $timestamp )
        : $order_date_raw;
}

$payment_method_id = (string) $order->get_payment_method();
$payment_method    = $payment_method_id;
// Prefer the funding-aware title so a Google Pay / Apple Pay / Venmo capture
// reads as the wallet the buyer actually used rather than the generic
// "PayPal" gateway title. Falls back to the live gateway title for everything
// else (and is what the order emails already display).
if ( method_exists( $order, 'get_payment_method_title' ) && '' !== (string) $order->get_payment_method_title() ) {
    $payment_method = (string) $order->get_payment_method_title();
} elseif ( function_exists( 'tejcart' ) ) {
    $registry = tejcart()->gateways();
    if ( $registry ) {
        $gateway = $registry->get_gateway( $payment_method_id );
        if ( $gateway && method_exists( $gateway, 'get_title' ) ) {
            $payment_method = $gateway->get_title();
        }
    }
}

$billing_address_raw  = $order->get_billing_address();
$shipping_address_raw = $order->get_shipping_address();
$billing_address      = tejcart_format_order_address( $billing_address_raw );
$shipping_address     = tejcart_format_order_address( $shipping_address_raw );
$has_any_address      = ( $billing_address || $shipping_address );

$first_name = '';
if ( is_array( $billing_address_raw ) && ! empty( $billing_address_raw['first_name'] ) ) {
    $first_name = (string) $billing_address_raw['first_name'];
}

$items          = $order->get_items();
$order_currency = (string) $order->get_currency();

$shop_url    = tejcart_get_page_url( 'shop' );
$account_url = tejcart_get_page_url( 'myaccount' );
?>

<div class="tejcart-thankyou">

    <section class="tejcart-thankyou-hero">
        <div class="tejcart-thankyou-success-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="4 12.5 10 18.5 20 6.5"></polyline>
            </svg>
        </div>
        <h1 class="tejcart-thankyou-title">
            <?php
            if ( $first_name ) {
                /* translators: %s: customer first name. */
                echo esc_html( sprintf( __( 'Thank you, %s!', 'tejcart' ), $first_name ) );
            } else {
                esc_html_e( 'Thank you for your order!', 'tejcart' );
            }
            ?>
        </h1>
        <p class="tejcart-thankyou-subtitle">
            <?php if ( $billing_email ) : ?>
                <?php
                echo wp_kses_post( sprintf(
                    /* translators: %s: customer email address. */
                    __( 'Your order has been received. A confirmation has been sent to <strong>%s</strong>.', 'tejcart' ),
                    esc_html( $billing_email )
                ) );
                ?>
            <?php else : ?>
                <?php esc_html_e( 'Your order has been received and is now being processed.', 'tejcart' ); ?>
            <?php endif; ?>
        </p>
        <p class="tejcart-thankyou-order-badge">
            <span class="tejcart-thankyou-order-badge-label">
                <?php esc_html_e( 'Order', 'tejcart' ); ?>
            </span>
            <span class="tejcart-thankyou-order-badge-value">
                <?php echo esc_html( $order_number ); ?>
            </span>
        </p>
    </section>

    <section class="tejcart-thankyou-card tejcart-thankyou-card--summary" aria-labelledby="tejcart-thankyou-summary-title">
        <h2 id="tejcart-thankyou-summary-title" class="tejcart-thankyou-card-title">
            <?php esc_html_e( 'Order summary', 'tejcart' ); ?>
        </h2>
        <dl class="tejcart-thankyou-meta">
            <div class="tejcart-thankyou-meta-item">
                <dt><?php esc_html_e( 'Order number', 'tejcart' ); ?></dt>
                <dd><?php echo esc_html( $order_number ); ?></dd>
            </div>
            <div class="tejcart-thankyou-meta-item">
                <dt><?php esc_html_e( 'Date', 'tejcart' ); ?></dt>
                <dd><?php echo esc_html( $order_date ); ?></dd>
            </div>
            <?php if ( $billing_email ) : ?>
                <div class="tejcart-thankyou-meta-item">
                    <dt><?php esc_html_e( 'Email', 'tejcart' ); ?></dt>
                    <dd><?php echo esc_html( $billing_email ); ?></dd>
                </div>
            <?php endif; ?>
            <?php if ( $payment_method ) : ?>
                <div class="tejcart-thankyou-meta-item">
                    <dt><?php esc_html_e( 'Payment method', 'tejcart' ); ?></dt>
                    <dd><?php echo esc_html( $payment_method ); ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </section>

    <section class="tejcart-thankyou-card tejcart-thankyou-card--items" aria-labelledby="tejcart-thankyou-items-title">
        <h2 id="tejcart-thankyou-items-title" class="tejcart-thankyou-card-title">
            <?php esc_html_e( 'Items ordered', 'tejcart' ); ?>
        </h2>

        <table class="tejcart-thankyou-table" cellspacing="0">
            <thead>
                <tr>
                    <th class="tejcart-thankyou-col-product" scope="col"><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                    <th class="tejcart-thankyou-col-qty" scope="col"><?php esc_html_e( 'Qty', 'tejcart' ); ?></th>
                    <th class="tejcart-thankyou-col-total" scope="col"><?php esc_html_e( 'Total', 'tejcart' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( (array) $items as $item ) :

                    $item_name      = is_object( $item ) ? (string) ( $item->product_name ?? '' ) : (string) ( $item['product_name'] ?? '' );
                    $item_qty       = is_object( $item ) ? (int)    ( $item->quantity     ?? 0 )  : (int)    ( $item['quantity']     ?? 0 );
                    $item_total_raw = is_object( $item ) ? (int)    ( $item->line_total   ?? 0 )  : (int)    ( $item['line_total']   ?? 0 );
                    // line_total is BIGINT minor units in the order's currency — convert for display.
                    $item_total = \TejCart\Money\Currency::from_minor_units( $item_total_raw, $order_currency );
                ?>
                    <tr class="tejcart-thankyou-item">
                        <td class="tejcart-thankyou-col-product" data-label="<?php esc_attr_e( 'Product', 'tejcart' ); ?>">
                            <span class="tejcart-thankyou-item-name"><?php echo esc_html( $item_name ); ?></span>
                        </td>
                        <td class="tejcart-thankyou-col-qty" data-label="<?php esc_attr_e( 'Qty', 'tejcart' ); ?>">
                            <?php echo esc_html( $item_qty ); ?>
                        </td>
                        <td class="tejcart-thankyou-col-total" data-label="<?php esc_attr_e( 'Total', 'tejcart' ); ?>">
                            <?php echo wp_kses_post( tejcart_price( $item_total, $order_currency ) ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <dl class="tejcart-thankyou-totals">
            <div class="tejcart-thankyou-totals-row">
                <dt><?php esc_html_e( 'Subtotal', 'tejcart' ); ?></dt>
                <dd><?php echo wp_kses_post( tejcart_price( $order->get_subtotal(), $order_currency ) ); ?></dd>
            </div>

            <?php if ( $order->get_shipping_total() > 0 ) : ?>
                <div class="tejcart-thankyou-totals-row">
                    <dt><?php esc_html_e( 'Shipping', 'tejcart' ); ?></dt>
                    <dd><?php echo wp_kses_post( tejcart_price( $order->get_shipping_total(), $order_currency ) ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( $order->get_tax_total() > 0 ) : ?>
                <div class="tejcart-thankyou-totals-row">
                    <dt><?php esc_html_e( 'Tax', 'tejcart' ); ?></dt>
                    <dd><?php echo wp_kses_post( tejcart_price( $order->get_tax_total(), $order_currency ) ); ?></dd>
                </div>
            <?php endif; ?>

            <?php if ( $order->get_discount_total() > 0 ) : ?>
                <div class="tejcart-thankyou-totals-row tejcart-thankyou-totals-row--discount">
                    <dt><?php esc_html_e( 'Discount', 'tejcart' ); ?></dt>
                    <dd>&minus;<?php echo wp_kses_post( tejcart_price( $order->get_discount_total(), $order_currency ) ); ?></dd>
                </div>
            <?php endif; ?>

            <?php
            // Cart-level fees (gift wrap, …) stamped as order meta at checkout.
            // Amounts are minor units in the order currency.
            foreach ( tejcart_get_order_fee_lines( $order ) as $tejcart_ty_fee ) :
                ?>
                <div class="tejcart-thankyou-totals-row tejcart-thankyou-totals-row--fee">
                    <dt><?php echo esc_html( $tejcart_ty_fee['label'] ); ?></dt>
                    <dd><?php echo wp_kses_post( tejcart_price_from_minor_units( (int) $tejcart_ty_fee['amount'], $order_currency ) ); ?></dd>
                </div>
            <?php endforeach; ?>

            <div class="tejcart-thankyou-totals-row tejcart-thankyou-totals-row--grand">
                <dt><?php esc_html_e( 'Total', 'tejcart' ); ?></dt>
                <dd><?php echo wp_kses_post( tejcart_price( $order->get_total(), $order_currency ) ); ?></dd>
            </div>
        </dl>
    </section>

    <?php if ( $has_any_address ) : ?>
        <section class="tejcart-thankyou-card tejcart-thankyou-card--addresses" aria-labelledby="tejcart-thankyou-addresses-title">
            <h2 id="tejcart-thankyou-addresses-title" class="tejcart-thankyou-card-title">
                <?php esc_html_e( 'Delivery details', 'tejcart' ); ?>
            </h2>
            <div class="tejcart-thankyou-addresses">
                <?php if ( $billing_address ) : ?>
                    <div class="tejcart-thankyou-address">
                        <h3 class="tejcart-thankyou-address-title"><?php esc_html_e( 'Billing address', 'tejcart' ); ?></h3>
                        <address><?php echo wp_kses_post( $billing_address ); ?></address>
                    </div>
                <?php endif; ?>

                <?php if ( $shipping_address ) : ?>
                    <div class="tejcart-thankyou-address">
                        <h3 class="tejcart-thankyou-address-title"><?php esc_html_e( 'Shipping address', 'tejcart' ); ?></h3>
                        <address><?php echo wp_kses_post( $shipping_address ); ?></address>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <nav class="tejcart-thankyou-actions" aria-label="<?php esc_attr_e( 'Next steps', 'tejcart' ); ?>">
        <a class="tejcart-btn tejcart-btn--primary" href="<?php echo esc_url( $shop_url ); ?>">
            <?php esc_html_e( 'Continue shopping', 'tejcart' ); ?>
        </a>
        <?php if ( is_user_logged_in() ) : ?>
            <a class="tejcart-btn tejcart-btn--secondary" href="<?php echo esc_url( $account_url ); ?>">
                <?php esc_html_e( 'View my orders', 'tejcart' ); ?>
            </a>
        <?php endif; ?>
    </nav>

</div>
