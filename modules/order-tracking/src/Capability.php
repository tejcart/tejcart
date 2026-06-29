<?php
/**
 * Capability helper.
 *
 * Centralises the capability check for tracking management so the rest of
 * the plugin (REST, AJAX, CLI) all answer the same way. Default cap is
 * TejCart's `tejcart_manage_orders`; sites can override via the
 * `tejcart_order_tracking_capability` filter.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Capability {
    public const DEFAULT_CAP = 'tejcart_manage_orders';

    public static function manage(): string {
        /**
         * Filter the capability required to manage shipment tracking.
         *
         * @param string $capability Defaults to `tejcart_manage_orders`.
         */
        $cap = (string) apply_filters( 'tejcart_order_tracking_capability', self::DEFAULT_CAP );
        return '' !== $cap ? $cap : self::DEFAULT_CAP;
    }

    public static function current_user_can_manage(): bool {
        return current_user_can( self::manage() );
    }

    /**
     * Grant the management capability to the standard store-staff roles on
     * module toggle-ON (invoked by `tejcart_order_tracking_install()` in
     * module.php).
     *
     * The default cap (`tejcart_manage_orders`) is core-owned and already
     * granted by core, so for the default this is a harmless no-op. The
     * call matters when a site overrides `tejcart_order_tracking_capability`
     * with a custom cap: without this, nothing would ever grant it.
     * Idempotent — `WP_Role::add_cap()` is safe to call repeatedly.
     */
    public static function install(): void {
        $cap = self::manage();
        if ( '' === $cap ) {
            return;
        }
        foreach ( array( 'administrator', 'tejcart_shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( $role instanceof \WP_Role && ! $role->has_cap( $cap ) ) {
                $role->add_cap( $cap );
            }
        }
    }
}
