<?php
/**
 * Pins the admin display currency to an order's transacted currency
 * for the duration of the single-order detail screen render.
 *
 * @package TejCart\Currency_Switcher\Admin
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Admin;

use TejCart\Currency_Switcher\Options;
use TejCart\Currency_Switcher\Switcher\Currency_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Industry-standard accounting behaviour for admin currency display:
 *
 *   - Reports, dashboards, orders list, customer screens → store base
 *     currency (handled by {@see Currency_Resolver::resolve_admin()}).
 *   - Single-order detail screen → the order's transacted currency, i.e.
 *     the currency the customer was actually charged in.
 *
 * The second rule is what this class implements. Without it, admins
 * who toggle the storefront switcher would see order totals re-formatted
 * to their cookie currency on the order detail screen — exactly the
 * bug we're fixing. With it, every order screen renders in its own
 * stored currency regardless of who is viewing it.
 *
 * The wiring is deliberately query-string based rather than tied to
 * WP's `current_screen` API: TejCart's order screen mounts under
 * `admin.php?page=tejcart-orders&action=view&order_id=N`, which is
 * detectable before WP would emit `current_screen`. That lets us
 * install the filter early enough that the `tejcart-page-header`'s
 * partially-refunded badge (which calls `tejcart_price()` very early
 * in the render) sees the right currency.
 */
final class Admin_Order_Context {
    /**
     * Resolved order currency for this request, or null if not on an
     * order detail screen. Lazily computed on first filter call.
     *
     * @var string|null
     */
    private ?string $pinned_currency = null;
    private bool $resolved = false;

    public function register(): void {
        // `admin_init` runs before page render, after `is_admin()` is
        // true and after query vars are available. We install the
        // filter unconditionally and let the filter callback decide
        // whether to emit an override — that keeps the wiring trivial
        // and side-effect-free for screens we don't recognise.
        add_action( 'admin_init', array( $this, 'install_filter' ), 1 );
    }

    public function install_filter(): void {
        add_filter(
            'tejcart_csw_admin_display_currency',
            array( $this, 'filter_display_currency' ),
            10,
            1
        );
        // The resolver memo may have been primed before the filter was
        // installed (e.g. by a hook on `init`). Drop it so the next
        // `current()` call re-reads the override.
        Currency_Resolver::flush_shared();
    }

    /**
     * Filter callback. Returns the order's stored display currency
     * when we're on its detail screen; otherwise returns the prior
     * value unchanged so other listeners can still contribute.
     *
     * @param mixed $prior Previous filter value.
     * @return mixed
     */
    public function filter_display_currency( $prior ) {
        $currency = $this->resolve_currency();
        return null !== $currency ? $currency : $prior;
    }

    /**
     * Detect the order detail screen and read the order's transacted
     * currency from its meta. Result is memoised per request.
     */
    private function resolve_currency(): ?string {
        if ( $this->resolved ) {
            return $this->pinned_currency;
        }
        $this->resolved = true;

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page     = isset( $_GET['page'] )     ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) )     : '';
        $action   = isset( $_GET['action'] )   ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) )   : '';
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] )                                     : 0;
        // phpcs:enable

        if ( 'tejcart-orders' !== $page || 'view' !== $action || $order_id <= 0 ) {
            return $this->pinned_currency = null;
        }

        if ( ! function_exists( 'tejcart_get_order' ) ) {
            return $this->pinned_currency = null;
        }

        $order = tejcart_get_order( $order_id );
        if ( ! is_object( $order ) ) {
            return $this->pinned_currency = null;
        }

        // Preference order:
        //   1. The order's own currency column — set by core's checkout
        //      and (in mode A) by Order_Meta_Writer::filter_order_data().
        //      This is the canonical "what was the customer charged in".
        //   2. The `_tejcart_csw_order_currency` meta — present on
        //      mode-A orders even when the row currency lags.
        //   3. null — let the resolver fall through to base.
        $from_column = method_exists( $order, 'get_currency' )
            ? strtoupper( trim( (string) $order->get_currency() ) )
            : '';
        if ( '' !== $from_column ) {
            return $this->pinned_currency = $from_column;
        }

        if ( method_exists( $order, 'get_meta' ) ) {
            $from_meta = strtoupper( trim( (string) $order->get_meta( Options::ORDER_META_ORDER_CURRENCY ) ) );
            if ( '' !== $from_meta ) {
                return $this->pinned_currency = $from_meta;
            }
        }

        return $this->pinned_currency = null;
    }

    /** Test helper — drops the resolved-currency memo. */
    public function reset(): void {
        $this->resolved        = false;
        $this->pinned_currency = null;
    }
}
