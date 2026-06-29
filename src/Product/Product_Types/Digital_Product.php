<?php
/**
 * Digital Product class.
 *
 * @package TejCart\Product\Product_Types
 */

declare( strict_types=1 );

namespace TejCart\Product\Product_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a digital/downloadable product.
 */
class Digital_Product extends Abstract_Product {
    /**
     * Get the product type.
     *
     * @return string
     */
    public function get_type() {
        return 'digital';
    }

    /**
     * Digital products are virtual.
     *
     * @return bool
     */
    public function is_virtual() {
        return true;
    }

    /**
     * Digital products are downloadable.
     *
     * @return bool
     */
    public function is_downloadable() {
        return true;
    }

    /**
     * Digital products do not need shipping.
     *
     * @return bool
     */
    public function needs_shipping() {
        return false;
    }

    /**
     * Get download files from product meta.
     *
     * Returns an array of download file entries, each containing
     * at minimum a 'name' and 'file' URL.
     *
     * @return array
     */
    public function get_download_files() {
        $files = $this->get_meta( '_download_files' );

        if ( is_string( $files ) ) {
            $decoded = json_decode( $files, true );
            $files   = is_array( $decoded ) ? $decoded : array();
        }

        if ( ! is_array( $files ) ) {
            return array();
        }

        return $files;
    }

    /**
     * Build signed download URLs for this product against a specific order.
     *
     * Returns an array of entries: { name, url, remaining }. Remaining is
     * an integer or the string 'unlimited'. Requires the order to have a
     * status that authorises downloads (processing / completed); callers
     * should still gate on order status before sending the URLs.
     *
     * @param int $order_id Order the links are bound to.
     * @return array[]
     */
    public function get_signed_download_urls( int $order_id ): array {
        if ( $order_id <= 0 ) {
            return array();
        }

        $files   = $this->get_download_files();
        $manager = new \TejCart\Download\Download_Manager();
        $result  = array();

        foreach ( array_values( $files ) as $index => $file ) {
            if ( ! is_array( $file ) ) {
                continue;
            }

            $result[] = array(
                'name'      => isset( $file['name'] ) ? (string) $file['name'] : '',
                'url'       => $manager->generate_download_url( $order_id, (int) $this->get_id(), $index ),
                'remaining' => $manager->get_remaining_downloads( $order_id, (int) $this->get_id(), $index ),
            );
        }

        return $result;
    }
}
