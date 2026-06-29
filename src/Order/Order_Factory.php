<?php
/**
 * Order Factory.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Factory class for creating and retrieving Order instances.
 */
class Order_Factory {
    /**
     * Create a new order from an array of data.
     *
     * The order row and all order items are inserted inside a single database
     * transaction so that a partial failure never leaves an order without items.
     *
     * @param array $data Associative array of order data.
     * @return Order|\WP_Error The created Order object, or WP_Error on failure.
     */
    public static function create( $data, bool $use_transaction = true ) {
        global $wpdb;

        $order = new Order();

        $items = array();
        if ( is_array( $data ) && isset( $data['items'] ) ) {
            $items = (array) $data['items'];
            unset( $data['items'] );
        }

        if ( is_array( $data ) ) {
            if ( ! isset( $data['ip_address'] ) || '' === (string) $data['ip_address'] ) {
                $data['ip_address'] = self::resolve_ip();
            }

            /**
             * F-L8 / #958: filter the order_data array immediately
             * before the column values are written onto the new Order
             * instance. Addons can transform / add / strip fields here
             * (custom columns, computed fields, fraud-tagging, etc.).
             *
             * NOTE: $items has already been extracted and unset from
             * $data above; the filter sees the column-level payload only.
             *
             * @param array $data    Column-level order data.
             * @param array $items   Line items extracted from the original input.
             */
            $data = (array) apply_filters( 'tejcart_order_data_before_insert', $data, $items );

            foreach ( $data as $key => $value ) {
                $order->set( $key, $value );
            }
        }

        if ( $use_transaction ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( 'START TRANSACTION' );
        }

        $saved = $order->save();

        if ( ! $saved ) {
            // Capture the underlying MySQL error BEFORE issuing ROLLBACK —
            // wpdb::query() resets last_error on the next successful query
            // (ROLLBACK succeeds), and without this snapshot the FK / NOT
            // NULL / charset diagnostic that callers actually need to fix
            // the problem is silently lost behind the generic
            // "Could not save the order." message.
            $db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
            if ( $use_transaction ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( 'ROLLBACK' );
            }
            if ( '' !== $db_error && function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Order_Factory: order INSERT failed — ' . $db_error, 'error' );
            }
            return new \WP_Error(
                'order_save_failed',
                __( 'Could not save the order.', 'tejcart' ),
                array( 'db_error' => $db_error )
            );
        }

        if ( ! empty( $items ) ) {
            $items_table = $wpdb->prefix . 'tejcart_order_items';

            // First pass: validate and normalise every row. We do this in
            // its own loop so a forged variation_id rolls back the whole
            // order before any item INSERT runs (same semantics as the
            // pre-batching code).
            $prepared = array();

            foreach ( $items as $item ) {
                $line_meta = array();
                $product_id_int   = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
                $variation_id_int = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
                if ( $variation_id_int > 0 ) {
                    // Validate that the variation actually belongs to the
                    // claimed parent product and still exists. Without this,
                    // a forged or stale variation_id from a stale cart line
                    // (or a non-cart caller) lands an order item whose admin
                    // / refund UI later renders a broken variation reference.
                    if ( ! self::variation_belongs_to_product( $variation_id_int, $product_id_int ) ) {
                        if ( $use_transaction ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                            $wpdb->query( 'ROLLBACK' );
                        }
                        return new \WP_Error(
                            'tejcart_invalid_variation',
                            __( 'A product variation in this order is no longer available.', 'tejcart' )
                        );
                    }
                    $line_meta['variation_id'] = $variation_id_int;
                }
                if ( isset( $item['variation_attributes'] ) && is_array( $item['variation_attributes'] ) ) {
                    $line_meta['variation_attributes'] = $item['variation_attributes'];
                }
                if ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) {
                    $line_meta = array_merge( $line_meta, $item['meta'] );
                }

                // unit_price stays DECIMAL(20,4) (sub-minor-unit prices like
                // $0.0125/unit are legitimate per spec §2.4) and is written
                // as a 4-decimal C-locale string so KWD/BHD/OMR precision
                // isn't truncated by wpdb's %f → sprintf('%F') pipeline.
                //
                // line_total is the CHARGED amount and is stored as integer
                // minor units in the order's currency. Cart code may pass
                // either a Money object (preferred) or a decimal-string /
                // float major-unit value (back-compat); both normalise via
                // Money::from_decimal_string / Currency::to_minor_units.
                $unit_price_raw = isset( $item['unit_price'] ) ? (float) $item['unit_price'] : 0.00;

                if ( $unit_price_raw < 0 ) {
                    $unit_price_raw = 0.00;
                }

                $currency       = $order->get_currency();
                $line_total_in  = $item['line_total'] ?? 0;
                if ( $line_total_in instanceof \TejCart\Money\Money ) {
                    $line_total_minor = $line_total_in->as_minor_units();
                } else {
                    $line_total_minor = (int) \TejCart\Money\Currency::to_minor_units( (float) $line_total_in, $currency );
                }

                // N-L1: clamp non-negative. Coupons that exceed the item
                // subtotal must never push line_total below 0 — the
                // Cart_Calculator enforces this at the grand-total level,
                // but a custom Tier-2 coupon could in theory over-discount
                // a single line. A negative line_total in BIGINT would
                // silently break SUM(line_total) reports and refund caps.
                if ( $line_total_minor < 0 ) {
                    $line_total_minor = 0;
                }

                // Consolidated base-currency line total, derived from the
                // order's stamped fx_rate so per-product / per-category
                // reports aggregate in the store base currency. Identity for
                // single-currency stores (fx_rate 1, base == order currency).
                $base_line_total_minor = \TejCart\Money\Currency::to_base_minor(
                    $line_total_minor,
                    $currency,
                    method_exists( $order, 'get_base_currency' ) ? $order->get_base_currency() : $currency,
                    method_exists( $order, 'get_fx_rate' ) ? $order->get_fx_rate() : 1.0
                );

                $prepared[] = array(
                    'order_id'        => $order->get_id(),
                    'product_id'      => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
                    'product_name'    => isset( $item['product_name'] ) ? sanitize_text_field( $item['product_name'] ) : '',
                    'quantity'        => isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1,
                    'unit_price'      => number_format( $unit_price_raw, 4, '.', '' ),
                    'line_total'      => $line_total_minor,
                    'base_line_total' => $base_line_total_minor,
                    'meta'            => ! empty( $line_meta ) ? wp_json_encode( $line_meta ) : null,
                );
            }

            $row_count = count( $prepared );
            $formats   = array( '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%s' );

            if ( $row_count === 1 ) {
                // Single-item orders are still the common path (express
                // checkout, single-SKU buys, BNPL flows). Keep the
                // wpdb->insert() codepath here — it's the same shape as
                // before, so all existing per-item assertions in the
                // unit suite still hold.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $result = $wpdb->insert( $items_table, $prepared[0], $formats );

                if ( false === $result ) {
                    // Snapshot last_error BEFORE ROLLBACK clears it
                    // (see the order INSERT branch above for context).
                    // The single most common failure here is the
                    // fk_order_items_product_id constraint firing for a
                    // cart line whose product was deleted between
                    // add-to-cart and checkout, which is impossible to
                    // diagnose without the raw MySQL message.
                    $db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
                    if ( $use_transaction ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->query( 'ROLLBACK' );
                    }
                    if ( '' !== $db_error && function_exists( 'tejcart_log' ) ) {
                        tejcart_log( 'Order_Factory: order item INSERT failed — ' . $db_error, 'error' );
                    }
                    return new \WP_Error(
                        'order_item_save_failed',
                        __( 'Could not save order items.', 'tejcart' ),
                        array( 'db_error' => $db_error )
                    );
                }
            } else {
                // Multi-item orders go through a single multi-row INSERT
                // instead of N round-trips. At peak that's the
                // difference between O(N×RTT) and O(RTT) per checkout —
                // visible on carts with 5+ lines, decisive on large B2B
                // bulk orders.
                //
                // Batch size keeps very large orders from blowing past
                // `max_allowed_packet`. Default 500 rows ≈ ~250 KB
                // payload, well under MySQL's 64 MB default; orders
                // with more lines than that just split into multiple
                // statements.
                $batch_size = (int) apply_filters( 'tejcart_order_items_insert_batch_size', 500 );
                if ( $batch_size <= 0 ) {
                    $batch_size = 500;
                }

                $columns           = '(order_id, product_id, product_name, quantity, unit_price, line_total, base_line_total, meta)';
                // The `meta` column is JSON DEFAULT NULL. Rows without
                // variation/meta context pass meta=null, and wpdb::prepare()
                // with %s coerces null to '' — which MySQL rejects against
                // the JSON column (empty string is not valid JSON). Emit
                // the SQL `NULL` literal inline for those rows so they
                // match the wpdb::insert() codepath used by single-item
                // orders, where NULL is written directly.
                $row_template_meta = '(%d, %d, %s, %d, %s, %d, %d, %s)';
                $row_template_null = '(%d, %d, %s, %d, %s, %d, %d, NULL)';

                for ( $offset = 0; $offset < $row_count; $offset += $batch_size ) {
                    $chunk        = array_slice( $prepared, $offset, $batch_size );

                    $placeholders = array();
                    $values       = array();
                    foreach ( $chunk as $row ) {
                        $has_meta       = null !== $row['meta'];
                        $placeholders[] = $has_meta ? $row_template_meta : $row_template_null;
                        $values[]       = $row['order_id'];
                        $values[]       = $row['product_id'];
                        $values[]       = $row['product_name'];
                        $values[]       = $row['quantity'];
                        $values[]       = $row['unit_price'];
                        $values[]       = $row['line_total'];
                        $values[]       = $row['base_line_total'];
                        if ( $has_meta ) {
                            $values[] = $row['meta'];
                        }
                    }

                    $sql = "INSERT INTO {$items_table} {$columns} VALUES " . implode( ', ', $placeholders );

                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                    $result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

                    if ( false === $result ) {
                        // Snapshot last_error BEFORE ROLLBACK clears it
                        // (see the order INSERT branch above for context).
                        $db_error = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
                        if ( $use_transaction ) {
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                            $wpdb->query( 'ROLLBACK' );
                        }
                        if ( '' !== $db_error && function_exists( 'tejcart_log' ) ) {
                            tejcart_log( 'Order_Factory: order items batched INSERT failed — ' . $db_error, 'error' );
                        }
                        return new \WP_Error(
                            'order_item_save_failed',
                            __( 'Could not save order items.', 'tejcart' ),
                            array( 'db_error' => $db_error )
                        );
                    }
                }
            }
        }

        if ( $use_transaction ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( 'COMMIT' );
        }

        // The "Order created" timeline note is now written from
        // Order::save()'s INSERT branch so it covers every entry point
        // (factory, direct admin instantiation, sibling plugins).

        /**
         * Fires after a new order is created.
         *
         * @param int                  $order_id The new order ID.
         * @param \TejCart\Order\Order $order    The order object.
         */
        do_action( 'tejcart_new_order', $order->get_id(), $order );

        /**
         * Alias action fired for subscribers using the "order_created"
         * vocabulary (e.g. Outgoing_Webhooks). Kept as a separate action so
         * the two audiences can be unhooked independently.
         *
         * @since 1.0.0
         *
         * @param int                  $order_id
         * @param \TejCart\Order\Order $order
         */
        do_action( 'tejcart_order_created', $order->get_id(), $order );

        return $order;
    }

    /**
     * Resolve a sanitized client IP for the order being created.
     *
     * Centralised so every entry point (checkout AJAX, checkout block, REST,
     * CLI) records a consistent value. Empty IPs defeat IP-locked downloads
     * and remove forensic evidence, so we never return an empty string —
     * REST callers that omit the field still get the request's REMOTE_ADDR
     * (with trusted-proxy headers respected by Rate_Limiter::get_client_ip),
     * and CLI invocations get the literal token "cli" so the column is
     * still queryable.
     */
    public static function resolve_ip(): string {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return 'cli';
        }

        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' ) ) {
            $ip = (string) \TejCart\Security\Rate_Limiter::get_client_ip();
            if ( '' !== $ip && '0.0.0.0' !== $ip ) {
                return $ip;
            }
        }

        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Validate that a variation actually belongs to a given parent product.
     *
     * Skips the check when the parent product is unknown (variation_id
     * present without product_id) — the order won't be useful in that case
     * anyway, so reject by returning false. The variation must exist as a
     * \TejCart\Product\Variation child of the claimed product_id.
     */
    private static function variation_belongs_to_product( int $variation_id, int $product_id ): bool {
        if ( $variation_id <= 0 || $product_id <= 0 ) {
            return false;
        }
        if ( ! function_exists( 'tejcart_get_product' ) ) {
            // Defensive — cannot validate without the product loader; allow
            // through rather than block all order creation in environments
            // where the product subsystem isn't bootstrapped.
            return true;
        }
        $parent = tejcart_get_product( $product_id );
        if ( ! $parent || ! is_object( $parent ) ) {
            return false;
        }
        if ( ! method_exists( $parent, 'get_variation' ) ) {
            // Parent isn't a Variable_Product — nothing to validate against.
            return false;
        }
        $variation = $parent->get_variation( $variation_id );
        return ! empty( $variation );
    }

    /**
     * Get an existing order by ID.
     *
     * @param int $id Order ID.
     * @return Order|null The Order object, or null if not found.
     */
    public static function get_order( $id ) {
        $id = absint( $id );

        if ( ! $id ) {
            return null;
        }

        $order = new Order( $id );

        if ( ! $order->get_id() ) {
            return null;
        }

        return $order;
    }
}
