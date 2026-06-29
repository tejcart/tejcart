<?php
/**
 * TejCart Storefront Search module bootstrap.
 *
 * Loaded by {@see \TejCart\Modules\Module_Manager} when the `search`
 * module toggle is enabled. Provides FULLTEXT-indexed, weighted,
 * fuzzy product search with a REST autocomplete API and a storefront
 * dropdown widget.
 *
 * @package TejCart\Search
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TEJCART_SEARCH_FILE' ) ) {
    define( 'TEJCART_SEARCH_FILE',    __FILE__ );
    define( 'TEJCART_SEARCH_DIR',     plugin_dir_path( __FILE__ ) );
    define( 'TEJCART_SEARCH_VERSION', '1.0.0' );
}

if ( ! defined( 'TEJCART_SEARCH_DB_VERSION_OPTION' ) ) {
    define( 'TEJCART_SEARCH_DB_VERSION_OPTION', 'tejcart_search_db_version' );
    define( 'TEJCART_SEARCH_DB_VERSION',        '1.0.0' );
}

spl_autoload_register( static function ( string $class ): void {
    $prefix = 'TejCart\\Tier2\\Search\\';
    if ( 0 !== strpos( $class, $prefix ) ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $path     = TEJCART_SEARCH_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
    if ( is_readable( $path ) ) {
        require_once $path;
    }
} );

add_action( 'tejcart_init', static function (): void {
    if ( ! class_exists( '\\TejCart\\Tier2\\Search\\Search_Bootstrap' ) ) {
        return;
    }
    \TejCart\Tier2\Search\Search_Bootstrap::init();
}, 20 );

// F-CORE-011: register the module's own table on the multisite drop list so
// a deleted sub-site has its tejcart_search_index table cleaned up. The
// wpmu_drop_tables list in tejcart.php is explicit (no prefix wildcard), so
// modules must self-append here or their tables leak on sub-site deletion.
// Registered at include time (not inside tejcart_init) so the filter is
// present during wp_delete_site even when tejcart_init has not fired.
add_filter( 'tejcart_drop_tables', static function ( array $tables ): array {
    if ( class_exists( '\\TejCart\\Tier2\\Search\\Search_Index' ) ) {
        $tables[] = \TejCart\Tier2\Search\Search_Index::TABLE;
    } else {
        $tables[] = 'tejcart_search_index';
    }
    return $tables;
} );

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
    add_action( 'cli_init', static function (): void {
        if ( ! class_exists( '\\TejCart\\Tier2\\Search\\CLI_Command' ) ) {
            return;
        }
        \WP_CLI::add_command( 'tejcart search', '\\TejCart\\Tier2\\Search\\CLI_Command' );
    } );
}
