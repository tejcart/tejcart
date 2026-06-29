<?php
/**
 * Account Dashboard partial.
 *
 * Rendered when $current_tab === 'dashboard'. Surfaces the most common
 * next actions for a returning customer: resume shopping, check in-
 * progress orders, review recent orders, and jump to payment methods
 * or addresses.
 *
 * @package TejCart\Templates\Account
 *
 * @var int   $customer_id Current customer user ID.
 * @var array $orders      Customer orders (most-recent first).
 * @var array $addresses   Customer addresses keyed by type.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_user = wp_get_current_user();
$account_url  = get_permalink();

$total_orders     = count( $orders );
$in_progress      = 0;
$in_progress_list = array( 'pending', 'processing', 'on-hold' );
foreach ( $orders as $nxa_order ) {
    if ( in_array( $nxa_order->get_status(), $in_progress_list, true ) ) {
        $in_progress++;
    }
}

$has_billing  = ! empty( $addresses['billing'] ) && array_filter( $addresses['billing'] );
$has_shipping = ! empty( $addresses['shipping'] ) && array_filter( $addresses['shipping'] );
$addresses_on_file = (int) $has_billing + (int) $has_shipping;

$downloads_available = 0;
if ( function_exists( 'tejcart_get_customer_downloads' ) ) {
    $nxa_downloads       = tejcart_get_customer_downloads( $customer_id );
    $downloads_available = is_array( $nxa_downloads ) ? count( $nxa_downloads ) : 0;
}

$shop_url    = apply_filters( 'tejcart_shop_url', home_url( '/shop/' ) );
$greeting    = wp_get_current_user();
$first_name  = $greeting->first_name ? $greeting->first_name : $greeting->display_name;
?>

<div class="tejcart-account-dashboard">

    <section class="tejcart-account-greeting" aria-labelledby="tejcart-greeting-title">
        <div class="tejcart-account-greeting__text">
            <h2 id="tejcart-greeting-title" class="tejcart-account-greeting__title">
                <?php
                printf(
                    /* translators: %s: customer first or display name */
                    esc_html__( 'Welcome back, %s', 'tejcart' ),
                    esc_html( $first_name )
                );
                ?>
            </h2>
            <p class="tejcart-account-greeting__body">
                <?php esc_html_e( 'Review recent orders, manage your addresses and payment methods, or pick up where you left off.', 'tejcart' ); ?>
            </p>
        </div>
        <div class="tejcart-account-greeting__actions">
            <a class="tejcart-btn tejcart-btn--primary" href="<?php echo esc_url( $shop_url ); ?>">
                <?php esc_html_e( 'Continue shopping', 'tejcart' ); ?>
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
            </a>
        </div>
    </section>

    <div class="tejcart-account-metrics" role="list">

        <a class="tejcart-account-metric" role="listitem" href="<?php echo esc_url( add_query_arg( 'tab', 'orders', $account_url ) ); ?>">
            <span class="tejcart-account-metric__label">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/></svg>
                <?php esc_html_e( 'Total orders', 'tejcart' ); ?>
            </span>
            <span class="tejcart-account-metric__value"><?php echo esc_html( (string) $total_orders ); ?></span>
            <span class="tejcart-account-metric__sub">
                <?php esc_html_e( 'Across your account history', 'tejcart' ); ?>
            </span>
        </a>

        <a class="tejcart-account-metric" role="listitem" href="<?php echo esc_url( add_query_arg( 'tab', 'orders', $account_url ) ); ?>">
            <span class="tejcart-account-metric__label">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <?php esc_html_e( 'In progress', 'tejcart' ); ?>
            </span>
            <span class="tejcart-account-metric__value"><?php echo esc_html( (string) $in_progress ); ?></span>
            <span class="tejcart-account-metric__sub">
                <?php esc_html_e( 'Awaiting processing or shipment', 'tejcart' ); ?>
            </span>
        </a>

        <a class="tejcart-account-metric" role="listitem" href="<?php echo esc_url( add_query_arg( 'tab', 'downloads', $account_url ) ); ?>">
            <span class="tejcart-account-metric__label">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                <?php esc_html_e( 'Downloads', 'tejcart' ); ?>
            </span>
            <span class="tejcart-account-metric__value"><?php echo esc_html( (string) $downloads_available ); ?></span>
            <span class="tejcart-account-metric__sub">
                <?php esc_html_e( 'Digital products ready to use', 'tejcart' ); ?>
            </span>
        </a>

        <a class="tejcart-account-metric" role="listitem" href="<?php echo esc_url( add_query_arg( 'tab', 'addresses', $account_url ) ); ?>">
            <span class="tejcart-account-metric__label">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                <?php esc_html_e( 'Addresses', 'tejcart' ); ?>
            </span>
            <span class="tejcart-account-metric__value"><?php echo esc_html( (string) $addresses_on_file ); ?><span class="tejcart-account-metric__sub tejcart-account-metric__value-suffix">/ 2</span></span>
            <span class="tejcart-account-metric__sub">
                <?php
                if ( 2 === $addresses_on_file ) {
                    esc_html_e( 'Billing and shipping on file', 'tejcart' );
                } else {
                    esc_html_e( 'Add billing and shipping for faster checkout', 'tejcart' );
                }
                ?>
            </span>
        </a>

    </div>

    <section class="tejcart-account-card tejcart-account-card--flush" aria-labelledby="tejcart-recent-orders-title">
        <header class="tejcart-account-card__header">
            <div>
                <h2 id="tejcart-recent-orders-title" class="tejcart-account-card__title">
                    <?php esc_html_e( 'Recent orders', 'tejcart' ); ?>
                </h2>
                <p class="tejcart-account-card__subtitle">
                    <?php esc_html_e( 'Your five most recent orders. View all to see the full history.', 'tejcart' ); ?>
                </p>
            </div>
            <?php if ( ! empty( $orders ) ) : ?>
                <div class="tejcart-account-card__actions">
                    <a class="tejcart-btn tejcart-btn--secondary tejcart-btn--small" href="<?php echo esc_url( add_query_arg( 'tab', 'orders', $account_url ) ); ?>">
                        <?php esc_html_e( 'View all', 'tejcart' ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </header>

        <?php if ( empty( $orders ) ) : ?>
            <div class="tejcart-account-card__body">
                <div class="tejcart-account-empty">
                    <span class="tejcart-account-empty__icon" aria-hidden="true">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/></svg>
                    </span>
                    <h3 class="tejcart-account-empty__title"><?php esc_html_e( 'No orders yet', 'tejcart' ); ?></h3>
                    <p class="tejcart-account-empty__body"><?php esc_html_e( 'When you place your first order, it will show up here with its status and total.', 'tejcart' ); ?></p>
                    <div class="tejcart-account-empty__actions">
                        <a class="tejcart-btn tejcart-btn--primary" href="<?php echo esc_url( $shop_url ); ?>">
                            <?php esc_html_e( 'Browse products', 'tejcart' ); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <div class="tejcart-account-recent" role="list">
                <?php foreach ( array_slice( $orders, 0, 5 ) as $nxa_order ) :
                    $order_url = add_query_arg(
                        array( 'tab' => 'view-order', 'order_id' => $nxa_order->get_id() ),
                        $account_url
                    );
                    ?>
                    <a class="tejcart-account-recent__row" role="listitem" href="<?php echo esc_url( $order_url ); ?>">
                        <span class="tejcart-account-recent__number">#<?php echo esc_html( $nxa_order->get_order_number() ); ?></span>
                        <span class="tejcart-account-recent__date"><?php echo esc_html( $nxa_order->get_date_created() ); ?></span>
                        <span class="tejcart-status-badge tejcart-status-badge--<?php echo esc_attr( $nxa_order->get_status() ); ?>">
                            <?php echo esc_html( tejcart_get_order_status_label( $nxa_order->get_status() ) ); ?>
                        </span>
                        <span class="tejcart-account-recent__total"><?php echo wp_kses_post( tejcart_price( (float) ( $nxa_order->get_total() ?? 0 ), (string) $nxa_order->get_currency() ) ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</div>
