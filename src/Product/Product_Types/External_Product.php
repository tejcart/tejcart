<?php
/**
 * External/Affiliate Product type.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * An external or affiliate product that links to an external website.
 *
 * External products are not purchasable through the store and do not
 * require shipping. They display a button linking to the external URL.
 */
class External_Product extends Abstract_Product {
    /**
     * External product URL. `null` means the meta lookup hasn't run yet;
     * `''` means it ran and the row is genuinely empty. Distinguishing
     * the two avoids re-querying meta on every call when the product
     * has no external URL configured.
     *
     * @var string|null
     */
    protected $product_url = null;

    /**
     * Custom button text. Same null/empty semantics as $product_url.
     *
     * @var string|null
     */
    protected $button_text = null;

    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'external';
    }

    /**
     * Get the external product URL from meta `_product_url`.
     *
     * @return string
     */
    public function get_product_url() {
        if ( null === $this->product_url ) {
            $url = $this->get_id() ? $this->get_meta( '_product_url' ) : '';

            $this->product_url = $url ? self::sanitize_external_url( (string) $url ) : '';
        }

        return $this->product_url;
    }

    /**
     * Persist the external product URL, rejecting non-http(s) schemes.
     *
     * `esc_url_raw` alone is insufficient because it accepts schemes like
     * `javascript:` and `data:` that are unsafe for a public-facing button.
     *
     * @param string $url Raw URL input.
     * @return bool True when the URL is stored (or cleared when empty).
     */
    public function set_product_url( string $url ): bool {
        $clean = self::sanitize_external_url( $url );
        $this->product_url = $clean;

        return (bool) $this->update_meta( '_product_url', $clean );
    }

    /**
     * Persist the button text for the external product.
     *
     * @param string $text Button text.
     * @return bool
     */
    public function set_button_text( string $text ): bool {
        $clean = sanitize_text_field( $text );
        $this->button_text = '' === $clean ? __( 'Buy product', 'tejcart' ) : $clean;

        return (bool) $this->update_meta( '_button_text', $clean );
    }

    /**
     * Allow-list URL schemes. Returns an empty string for anything that
     * isn't an absolute http(s) URL.
     *
     * @param string $url Raw input.
     * @return string
     */
    protected static function sanitize_external_url( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }

        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return '';
        }

        $scheme = strtolower( $parsed['scheme'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return '';
        }

        return esc_url_raw( $url, array( 'http', 'https' ) );
    }

    /**
     * Get the custom button text from meta `_button_text`.
     *
     * @return string
     */
    public function get_button_text() {
        if ( null === $this->button_text ) {
            $text = $this->get_id() ? $this->get_meta( '_button_text' ) : '';

            $this->button_text = $text
                ? sanitize_text_field( $text )
                : __( 'Buy product', 'tejcart' );
        }

        return $this->button_text;
    }

    /**
     * External products are not purchasable through this store.
     *
     * @return bool
     */
    public function is_purchasable() {
        return false;
    }

    /**
     * External products do not need shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        return false;
    }
}
