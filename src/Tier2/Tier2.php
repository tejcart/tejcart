<?php
/**
 * TejCart Tier-2 Loader.
 *
 * @package TejCart\Tier2
 */

declare( strict_types=1 );

namespace TejCart\Tier2;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Top-level loader. Wires up each Tier-2 feature module.
 *
 * Each feature module self-registers its own hooks; this class only
 * orchestrates discovery so individual modules can be enabled, disabled
 * or replaced via the `tejcart_tier2_modules` filter without touching
 * any existing TejCart core class.
 */
class Tier2 {
    /**
     * Whether boot() has already run.
     *
     * @var bool
     */
    private static $booted = false;

    /**
     * Boot all Tier-2 modules.
     */
    public static function boot() {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        // Audit M-31 (Core F-009): gate Schema::install() behind a
        // version cursor so dbDelta doesn't run on every request.
        // Only re-runs when the plugin version changes (activation,
        // update). dbDelta on 5 tables costs ~10-30ms per request.
        $schema_ver_key = 'tejcart_tier2_schema_version';
        // Use the stable, un-overridable schema version (NOT a raw
        // TEJCART_VERSION) so a wp-config override of the constant can't pin
        // this dbDelta into a per-request loop. See Installer::schema_version().
        $current_ver    = \TejCart\Core\Installer::schema_version();
        if ( get_option( $schema_ver_key, '' ) !== $current_ver ) {
            Schema::install();
            update_option( $schema_ver_key, $current_ver, true );
        }

        $modules = array(
            'advanced_coupons' => Coupons\Advanced_Coupons::class,
            'address_book'     => Address_Book\Address_Book::class,
            'email_templates'  => Emails\Template_System::class,
            'abandoned_cart'   => Abandoned_Cart\Abandoned_Cart::class,

            'mini_cart'        => Cart_Ajax\Mini_Cart::class,
        );

        /**
         * Filter the list of Tier-2 modules to load.
         *
         * @param array<string, class-string> $modules Map of module key => class name.
         */
        $modules = apply_filters( 'tejcart_tier2_modules', $modules );

        // Audit #99 / 01 #9 — product/category coupon restrictions are
        // the only Tier-2 surface that affects core data integrity
        // (a coupon configured with `include_products` would silently
        // apply to ANY cart if a merchant disables the module). Force
        // the validator registration even if the merchant filtered it
        // out of `tejcart_tier2_modules`. The BOGO path remains opt-in.
        if ( ! isset( $modules['advanced_coupons'] )
            && class_exists( Coupons\Advanced_Coupons::class )
            && method_exists( Coupons\Advanced_Coupons::class, 'register_restriction_validator' ) ) {
            Coupons\Advanced_Coupons::register_restriction_validator();
        }

        foreach ( $modules as $key => $class ) {
            if ( is_string( $class ) && class_exists( $class ) && method_exists( $class, 'init' ) ) {
                call_user_func( array( $class, 'init' ) );
            }
        }

        do_action( 'tejcart_tier2_loaded' );
    }
}
