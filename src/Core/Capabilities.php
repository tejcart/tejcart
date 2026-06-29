<?php
/**
 * TejCart capability + role definitions.
 *
 * Provides finer-grained gates than the blanket `manage_options` check
 * that the plugin started with. Callers use Capabilities::check() (or the
 * tejcart_can() wrapper) instead of current_user_can() directly so a
 * merchant can, for example, let a shop manager edit products without
 * handing them the full WP admin.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TejCart capability constants + role bootstrapping.
 */
class Capabilities {
    /** View & edit the shop's own products. */
    public const EDIT_PRODUCTS = 'tejcart_edit_products';

    /** Edit products owned by other users. */
    public const EDIT_OTHERS_PRODUCTS = 'tejcart_edit_others_products';

    /** Delete products. */
    public const DELETE_PRODUCTS = 'tejcart_delete_products';

    /** Move a product from draft to publish. */
    public const PUBLISH_PRODUCTS = 'tejcart_publish_products';

    /** Umbrella cap for anything product-admin-related (list view, tools). */
    public const MANAGE_PRODUCTS = 'tejcart_manage_products';

    /** Umbrella cap for the orders admin (list, view, edit non-money fields). */
    public const MANAGE_ORDERS = 'tejcart_manage_orders';

    /** Process refunds against an order — money-moving, separated for least-privilege. */
    public const REFUND_ORDERS = 'tejcart_refund_orders';

    /**
     * Store-administration umbrella cap (setup wizard, customer admin
     * lookup, order-preview tooltip). Distinct from `manage_options`
     * (WordPress site administration) so a Shop Manager role can be
     * promoted on multisite without handing them `manage_options`.
     */
    public const MANAGE_STORE = 'tejcart_manage_store';

    /** Slug of the Shop Manager role created on activation. */
    public const SHOP_MANAGER_ROLE = 'tejcart_shop_manager';

    /**
     * Full list of product-admin caps, in the order they'd appear in a
     * role matrix. Keep this in sync with install().
     *
     * @var string[]
     */
    public const ALL = array(
        self::MANAGE_PRODUCTS,
        self::EDIT_PRODUCTS,
        self::EDIT_OTHERS_PRODUCTS,
        self::DELETE_PRODUCTS,
        self::PUBLISH_PRODUCTS,
        self::MANAGE_ORDERS,
        self::REFUND_ORDERS,
        self::MANAGE_STORE,
    );

    /**
     * Idempotently create the Shop Manager role and grant every TejCart
     * cap to administrator. Safe to call on every activation.
     */
    public static function install(): void {
        if ( ! function_exists( 'get_role' ) || ! function_exists( 'add_role' ) ) {
            return;
        }

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::ALL as $cap ) {
                $admin->add_cap( $cap );
            }
        }

        $shop_manager = get_role( self::SHOP_MANAGER_ROLE );
        if ( ! $shop_manager ) {
            add_role(
                self::SHOP_MANAGER_ROLE,
                // Audit L-15: use a literal string instead of __()
                // because install() runs during activation which fires
                // before 'init' — WP 6.7+ triggers _doing_it_wrong
                // for translations loaded before init. The role label
                // is only used in the admin UI and WP caches it; the
                // translation happens when the UI renders, not here.
                'Shop Manager',
                array(
                    'read'                       => true,
                    'upload_files'               => true,
                    self::MANAGE_PRODUCTS        => true,
                    self::EDIT_PRODUCTS          => true,
                    self::EDIT_OTHERS_PRODUCTS   => true,
                    self::PUBLISH_PRODUCTS       => true,
                    self::DELETE_PRODUCTS        => true,
                    self::MANAGE_ORDERS          => true,
                    self::REFUND_ORDERS          => true,
                    self::MANAGE_STORE           => true,
                )
            );
        } else {
            foreach ( self::ALL as $cap ) {
                $shop_manager->add_cap( $cap );
            }
        }
    }

    /**
     * Remove every TejCart cap from all roles. Called on uninstall.
     */
    public static function uninstall(): void {
        if ( ! function_exists( 'wp_roles' ) ) {
            return;
        }
        foreach ( wp_roles()->roles as $role_slug => $_info ) {
            $role = get_role( $role_slug );
            if ( ! $role ) {
                continue;
            }
            foreach ( self::ALL as $cap ) {
                $role->remove_cap( $cap );
            }
        }
        if ( get_role( self::SHOP_MANAGER_ROLE ) ) {
            remove_role( self::SHOP_MANAGER_ROLE );
        }
    }

    /**
     * Check whether the current user can perform a TejCart action.
     *
     * F-CORE-007: the previous `manage_options` fallback has been removed.
     * Capability checks must fail closed — a deliberate role mapping that
     * excludes a TejCart cap should be respected, not silently bypassed.
     * `Installer::activate()` runs `Capabilities::install()` before any
     * page load, so the caps are always installed by the time this fires
     * on a legitimate request.
     *
     * @param string $cap     A TejCart cap constant (EDIT_PRODUCTS, etc.)
     *                        or any string accepted by current_user_can.
     * @param mixed  ...$args Extra args forwarded to current_user_can.
     * @return bool
     */
    public static function check( string $cap, ...$args ): bool {
        /**
         * Map a TejCart cap onto a different WP cap before the check runs.
         *
         * Return a non-empty string to short-circuit with that cap.
         * Return null (default) to fall through to the normal chain.
         *
         * @param string|null $mapped  Cap to check instead (or null).
         * @param string      $cap     Original TejCart cap.
         * @param array       $args    Extra arguments.
         */
        $mapped = apply_filters( 'tejcart_map_meta_cap', null, $cap, $args );
        if ( is_string( $mapped ) && '' !== $mapped ) {
            return current_user_can( $mapped, ...$args );
        }

        return current_user_can( $cap, ...$args );
    }
}
