<?php
/**
 * Disputes capability + role helpers.
 *
 * Decouples chargeback/dispute admin from the WordPress administrator
 * role so a merchant can hand evidence-collection to a finance lead
 * without granting full site-admin powers. Until the capability has
 * been granted explicitly the gate falls back to `manage_options`, so
 * upgrades from 0.1.0 don't lock anyone out of the queue.
 *
 * @package TejCart\Tier2\Disputes
 */

declare(strict_types=1);

namespace TejCart\Tier2\Disputes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Capabilities {
    public const MANAGE_DISPUTES = 'manage_tejcart_disputes';

    /**
     * Idempotently grant MANAGE_DISPUTES to administrators (and to the
     * TejCart core "Shop Manager" role if registered). Called on
     * activation.
     */
    public static function install(): void {
        if ( ! function_exists( 'get_role' ) ) {
            return;
        }

        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( self::MANAGE_DISPUTES ) ) {
            $admin->add_cap( self::MANAGE_DISPUTES );
        }

        $shop_manager = get_role( 'tejcart_shop_manager' );
        if ( $shop_manager && ! $shop_manager->has_cap( self::MANAGE_DISPUTES ) ) {
            $shop_manager->add_cap( self::MANAGE_DISPUTES );
        }
    }

    /**
     * Strip MANAGE_DISPUTES from every role. Called from uninstall.php.
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
            $role->remove_cap( self::MANAGE_DISPUTES );
        }
    }

    /**
     * Authorisation gate. Falls back to `manage_options` so single-admin
     * installs that haven't re-saved roles since 0.2.0 keep working.
     */
    public static function check( ?string $cap = null ): bool {
        if ( ! function_exists( 'current_user_can' ) ) {
            return false;
        }
        $cap = $cap ?? self::MANAGE_DISPUTES;
        if ( current_user_can( $cap ) ) {
            return true;
        }
        return current_user_can( 'manage_options' );
    }
}
