<?php
/**
 * TejCart Tier-2 Feature Bootstrap.
 *
 * Loaded from tejcart.php. Defers all initialization until the
 * `tejcart_init` action so it integrates with the existing plugin
 * lifecycle without forcing any architectural changes.
 *
 * @package TejCart\Tier2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

register_activation_hook( TEJCART_PLUGIN_FILE, array( '\\TejCart\\Tier2\\Schema', 'install' ) );

add_action( 'tejcart_init', array( '\\TejCart\\Tier2\\Tier2', 'boot' ), 20 );
