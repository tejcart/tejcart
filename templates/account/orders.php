<?php
/**
 * Order history template.
 *
 * Paginated list of the customer's past orders. Uses a single
 * semantic <table> that collapses into stacked cards below 640px
 * via CSS (no markup duplication).
 *
 * @package TejCart\Templates\Account
 *
 * @var int    $customer_id Current customer user ID.
 * @var array  $orders      Customer orders.
 * @var string $account_url Account page permalink.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$account_url = get_permalink();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current_page = isset( $_GET['order_page'] ) ? max( 1, absint( $_GET['order_page'] ) ) : 1;
$per_page     = apply_filters( 'tejcart_account_orders_per_page', 10 );
$total_orders = count( $orders );
$total_pages  = max( 1, (int) ceil( $total_orders / $per_page ) );
$paged_orders = array_slice( $orders, ( $current_page - 1 ) * $per_page, $per_page );

// Audit #22 / 08 #1 — pre-fetch the per-order line-item counts in one
// query so the render loop doesn't issue `Order::get_items()` (which
// hits `tejcart_order_items` with a fresh SELECT each call) once per
// row. Previously a 20-order page produced 1 + 20 queries just for the
// "N items" subtitle.
$item_counts = array();
$order_ids   = array();
foreach ( $paged_orders as $nxa_order ) {
    if ( is_object( $nxa_order ) && method_exists( $nxa_order, 'get_id' ) ) {
        $oid = (int) $nxa_order->get_id();
        if ( $oid > 0 ) {
            $order_ids[] = $oid;
        }
    }
}
if ( array() !== $order_ids ) {
    global $wpdb;
    $items_table = $wpdb->prefix . 'tejcart_order_items';
    $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT order_id, COUNT(*) AS item_count FROM {$items_table} WHERE order_id IN ({$placeholders}) GROUP BY order_id",
            $order_ids
        )
    );
    // phpcs:enable
    if ( is_array( $rows ) ) {
        foreach ( $rows as $row ) {
            $item_counts[ (int) $row->order_id ] = (int) $row->item_count;
        }
    }
}

$shop_url     = apply_filters( 'tejcart_shop_url', home_url( '/shop/' ) );

$reorderable_statuses = apply_filters(
    'tejcart_reorderable_statuses',
    array( 'completed', 'processing', 'refunded', 'cancelled' )
);
?>

<div class="tejcart-account-orders">

    <header class="tejcart-account-subpage-header">
        <div class="tejcart-account-subpage-header__text">
            <h2 class="tejcart-account-subpage-header__title"><?php esc_html_e( 'Orders', 'tejcart' ); ?></h2>
            <?php if ( $total_orders > 0 ) : ?>
                <p class="tejcart-account-subpage-header__subtitle">
                    <?php
                    printf(
                        /* translators: %d: total number of orders */
                        esc_html( _n( '%d order on file', '%d orders on file', $total_orders, 'tejcart' ) ),
                        (int) $total_orders
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php if ( $total_orders > 0 ) : ?>
            <div class="tejcart-account-subpage-header__actions">
                <a class="tejcart-btn tejcart-btn--secondary" href="<?php echo esc_url( $shop_url ); ?>">
                    <?php esc_html_e( 'Continue shopping', 'tejcart' ); ?>
                </a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ( empty( $orders ) ) : ?>

        <section class="tejcart-account-card">
            <div class="tejcart-account-empty">
                <span class="tejcart-account-empty__icon" aria-hidden="true">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/></svg>
                </span>
                <h3 class="tejcart-account-empty__title"><?php esc_html_e( 'No orders yet', 'tejcart' ); ?></h3>
                <p class="tejcart-account-empty__body"><?php esc_html_e( 'When you place your first order it will appear here with its status, total, and a reorder shortcut.', 'tejcart' ); ?></p>
                <div class="tejcart-account-empty__actions">
                    <a class="tejcart-btn tejcart-btn--primary" href="<?php echo esc_url( $shop_url ); ?>">
                        <?php esc_html_e( 'Browse products', 'tejcart' ); ?>
                    </a>
                </div>
            </div>
        </section>

    <?php else : ?>

        <section class="tejcart-account-card tejcart-account-card--flush">
            <div class="tejcart-account-card__body tejcart-account-card__body--table">
                <table class="tejcart-account-table" aria-label="<?php esc_attr_e( 'Order history', 'tejcart' ); ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e( 'Order', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Date', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Total', 'tejcart' ); ?></th>
                            <th scope="col" class="tejcart-account-table__align-end">
                                <span class="screen-reader-text"><?php esc_html_e( 'Actions', 'tejcart' ); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $paged_orders as $nxa_order ) :
                            $order_url = add_query_arg(
                                array( 'tab' => 'view-order', 'order_id' => $nxa_order->get_id() ),
                                $account_url
                            );
                            // Audit #22 / 08 #1 — look up from the
                            // pre-fetched map populated above; fall
                            // back to a fresh get_items() only when
                            // the batch query didn't return a row
                            // for this id (e.g. concurrent insert).
                            $oid        = (int) $nxa_order->get_id();
                            $item_count = isset( $item_counts[ $oid ] )
                                ? (int) $item_counts[ $oid ]
                                : count( $nxa_order->get_items() );
                            ?>
                            <tr>
                                <td data-label="<?php esc_attr_e( 'Order', 'tejcart' ); ?>">
                                    <a class="tejcart-account-table__link" href="<?php echo esc_url( $order_url ); ?>">
                                        #<?php echo esc_html( $nxa_order->get_order_number() ); ?>
                                    </a>
                                    <div class="tejcart-account-table__muted">
                                        <?php
                                        printf(
                                            /* translators: %d: item count */
                                            esc_html( _n( '%d item', '%d items', $item_count, 'tejcart' ) ),
                                            (int) $item_count
                                        );
                                        ?>
                                    </div>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Date', 'tejcart' ); ?>">
                                    <?php echo esc_html( $nxa_order->get_date_created() ); ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Status', 'tejcart' ); ?>">
                                    <span class="tejcart-status-badge tejcart-status-badge--<?php echo esc_attr( $nxa_order->get_status() ); ?>">
                                        <?php echo esc_html( tejcart_get_order_status_label( $nxa_order->get_status() ) ); ?>
                                    </span>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Total', 'tejcart' ); ?>" class="tejcart-account-table__total">
                                    <?php echo wp_kses_post( tejcart_price( (float) ( $nxa_order->get_total() ?? 0 ), (string) $nxa_order->get_currency() ) ); ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Actions', 'tejcart' ); ?>" class="tejcart-account-table__align-end">
                                    <div class="tejcart-account-table__actions">
                                        <a class="tejcart-btn tejcart-btn--small tejcart-btn--secondary" href="<?php echo esc_url( $order_url ); ?>">
                                            <?php esc_html_e( 'View', 'tejcart' ); ?>
                                        </a>
                                        <?php if ( in_array( $nxa_order->get_status(), $reorderable_statuses, true )
                                            && class_exists( '\\TejCart\\Order\\Order_Reorder' ) ) : ?>
                                            <a class="tejcart-btn tejcart-btn--small tejcart-btn--primary" href="<?php echo esc_url( \TejCart\Order\Order_Reorder::get_url( $nxa_order->get_id() ) ); ?>">
                                                <?php esc_html_e( 'Order again', 'tejcart' ); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ( $total_pages > 1 ) : ?>
            <nav class="tejcart-account-pagination" aria-label="<?php esc_attr_e( 'Orders pagination', 'tejcart' ); ?>">
                <?php if ( $current_page > 1 ) : ?>
                    <a class="tejcart-account-pagination__btn" rel="prev" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'orders', 'order_page' => $current_page - 1 ), $account_url ) ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'tejcart' ); ?>">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                    </a>
                <?php endif; ?>

                <?php for ( $nxa_i = 1; $nxa_i <= $total_pages; $nxa_i++ ) : ?>
                    <?php if ( $nxa_i === $current_page ) : ?>
                        <span class="tejcart-account-pagination__btn" aria-current="page"><?php echo esc_html( (string) $nxa_i ); ?></span>
                    <?php else : ?>
                        <a class="tejcart-account-pagination__btn" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'orders', 'order_page' => $nxa_i ), $account_url ) ); ?>">
                            <?php echo esc_html( (string) $nxa_i ); ?>
                        </a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ( $current_page < $total_pages ) : ?>
                    <a class="tejcart-account-pagination__btn" rel="next" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'orders', 'order_page' => $current_page + 1 ), $account_url ) ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'tejcart' ); ?>">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

    <?php endif; ?>

</div>
