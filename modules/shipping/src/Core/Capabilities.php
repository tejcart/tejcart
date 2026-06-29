<?php
/**
 * Shipping capability + role helpers.
 *
 * Decouples carrier-credential management from the WordPress admin role
 * so a merchant can hand the keys to a shipping ops lead without making
 * them a site administrator. Until a merchant has explicitly granted the
 * capability the gate falls back to `manage_options`, so existing
 * single-admin installs keep working without intervention on upgrade.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Capabilities {
    public const MANAGE_SHIPPING = 'manage_tejcart_shipping';

    /**
     * Idempotently grant MANAGE_SHIPPING to administrators (and to the
     * TejCart core "Shop Manager" role if it has been registered by
     * core or another sibling). Called from the activation hook.
     */
    public static function install(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( self::MANAGE_SHIPPING ) ) {
            $admin->add_cap( self::MANAGE_SHIPPING );
        }

        $shop_manager = get_role( 'tejcart_shop_manager' );
        if ( $shop_manager && ! $shop_manager->has_cap( self::MANAGE_SHIPPING ) ) {
            $shop_manager->add_cap( self::MANAGE_SHIPPING );
        }
    }

    /**
     * Strip MANAGE_SHIPPING from every role. Called from uninstall.php.
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
            $role->remove_cap( self::MANAGE_SHIPPING );
        }
    }

    /**
     * Authorisation gate. Falls back to `manage_options` so single-admin
     * installs that haven't re-saved roles since 0.4.0 keep working.
     */
    public static function check( ?string $cap = null ): bool {
        if ( ! function_exists( 'current_user_can' ) ) {
            return false;
        }
        $cap = $cap ?? self::MANAGE_SHIPPING;
        if ( current_user_can( $cap ) ) {
            return true;
        }
        return current_user_can( 'manage_options' );
    }
}
