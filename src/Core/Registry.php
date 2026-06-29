<?php
/**
 * TejCart Global Registry (deprecated).
 *
 * Historically intended as a static parallel registry for gateways,
 * product types, and email classes. Superseded by the typed registries
 * (`Gateways\Gateway_Registry`, `Product\Product_Factory`, `Email\Email_Manager`)
 * and the `tejcart_payment_gateways` / `tejcart_email_classes` filters.
 *
 * Kept as a thin deprecation shim so any third-party caller is steered
 * to the canonical registry rather than silently writing into a dead
 * store. Remove in a future major.
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deprecated registry. Use the typed sibling registries instead.
 *
 * F-CORE-016: planned removal in 2.0.0. All callers should migrate to the
 * appropriate typed registry before that release:
 *   - Gateways  → `tejcart_payment_gateways` filter / Gateway_Registry
 *   - Products  → Product_Factory
 *   - Emails    → `tejcart_email_classes` filter / Email_Manager
 *
 * PHPStan level 5 will flag any new call to these methods via the
 * @deprecated docblock on each method below. A grep for `Registry::`
 * (excluding this file and its test) should return zero results before
 * the 2.0.0 branch cut.
 *
 * @deprecated since 1.0.0 — will be removed in 2.0.0.
 */
class Registry {

    /**
     * @deprecated Use the `tejcart_payment_gateways` filter and {@see \TejCart\Gateways\Gateway_Registry::init()}.
     *
     * @param string $id      Unused.
     * @param mixed  $gateway Unused.
     * @return void
     */
    public static function register_gateway( $id, $gateway ) {
        _deprecated_function(
            __METHOD__,
            '1.0.0',
            'tejcart_payment_gateways filter / \\TejCart\\Gateways\\Gateway_Registry'
        );
    }

    /**
     * @deprecated Use {@see \TejCart\Gateways\Gateway_Registry::get_gateways()}.
     *
     * @return array<string, mixed> Always empty.
     */
    public static function get_gateways() {
        _deprecated_function(
            __METHOD__,
            '1.0.0',
            '\\TejCart\\Gateways\\Gateway_Registry::get_gateways()'
        );
        return array();
    }

    /**
     * @deprecated Use {@see \TejCart\Product\Product_Factory}.
     *
     * @param string $type_id Unused.
     * @param mixed  $handler Unused.
     * @return void
     */
    public static function register_product_type( $type_id, $handler ) {
        _deprecated_function(
            __METHOD__,
            '1.0.0',
            '\\TejCart\\Product\\Product_Factory'
        );
    }

    /**
     * @deprecated Use {@see \TejCart\Product\Product_Factory}.
     *
     * @return array<string, mixed> Always empty.
     */
    public static function get_product_types() {
        _deprecated_function(
            __METHOD__,
            '1.0.0',
            '\\TejCart\\Product\\Product_Factory'
        );
        return array();
    }

    /**
     * @deprecated Use the `tejcart_email_classes` filter and {@see \TejCart\Email\Email_Manager}.
     *
     * @param string $email_id Unused.
     * @param mixed  $class    Unused.
     * @return void
     */
    public static function register_email( $email_id, $class ) {
        _deprecated_function(
            __METHOD__,
            '1.0.0',
            'tejcart_email_classes filter / \\TejCart\\Email\\Email_Manager'
        );
    }

    /**
     * @deprecated Use {@see \TejCart\Email\Email_Manager}.
     *
     * @return array<string, mixed> Always empty.
     */
    public static function get_emails() {
        _deprecated_function(
            __METHOD__,
            '1.0.0',
            '\\TejCart\\Email\\Email_Manager'
        );
        return array();
    }

    /**
     * No-op reset helper retained for backwards source compatibility.
     *
     * @return void
     */
    public static function reset() {
    }
}
