<?php
/**
 * Carrier-driver exception. Used for unrecoverable configuration or
 * protocol errors — transient transport failures should be swallowed by
 * the driver and surface as an empty rate list instead.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Carrier_Exception extends \RuntimeException {}
