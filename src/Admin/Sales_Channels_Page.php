<?php
/**
 * Sales Channels hub page.
 *
 * Consolidates the individual channel module admin pages (Meta, TikTok,
 * Amazon, …) under a single TejCart sidebar entry. Channel modules
 * register themselves via the `tejcart_sales_channel_tabs` filter and
 * provide a render callback for their content.
 *
 * @package TejCart\Admin
 */

declare(strict_types=1);

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Sales_Channels_Page {

    public const MENU_SLUG = 'tejcart-sales-channels';

    private const CHANNEL_SLUGS = array( 'channels-meta', 'channels-tiktok', 'channels-amazon' );

    public static function init(): void {
        if ( ! self::has_enabled_channel() ) {
            return;
        }
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 25 );
        add_filter( 'tejcart_admin_page_hooks', array( __CLASS__, 'register_page_hook' ) );
    }

    private static function has_enabled_channel(): bool {
        $states = (array) get_option( \TejCart\Modules\Module_Manager::OPTION, array() );
        foreach ( self::CHANNEL_SLUGS as $slug ) {
            if ( ! empty( $states[ $slug ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $hooks
     * @return string[]
     */
    public static function register_page_hook( array $hooks ): array {
        $hooks[] = 'tejcart_page_' . self::MENU_SLUG;
        return $hooks;
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tejcart',
            __( 'Sales Channels', 'tejcart' ),
            __( 'Sales Channels', 'tejcart' ),
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render' )
        );
    }

    /**
     * Collect registered channel tabs.
     *
     * Each entry is `[ 'label' => string, 'callback' => callable ]`,
     * keyed by a short slug (e.g. `meta`, `tiktok`, `amazon`).
     *
     * @return array<string, array{label: string, callback: callable}>
     */
    public static function get_tabs(): array {
        $tabs = apply_filters( 'tejcart_sales_channel_tabs', array() );
        return is_array( $tabs ) ? $tabs : array();
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tabs = self::get_tabs();

        if ( empty( $tabs ) ) {
            echo '<div class="wrap tejcart-admin-wrap">';
            Page_Header::list(
                __( 'Sales Channels', 'tejcart' ),
                __( 'Connect your TejCart store to external marketplaces and social commerce platforms.', 'tejcart' )
            );
            echo '<div class="notice notice-info"><p>';
            esc_html_e( 'No sales channel modules are enabled. Visit TejCart → Modules to enable Meta Channels, TikTok Shop, or Amazon.', 'tejcart' );
            echo '</p></div></div>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( (string) $_GET['channel'] ) ) : '';
        if ( ! isset( $tabs[ $channel ] ) ) {
            $channel = (string) array_key_first( $tabs );
        }

        $base = admin_url( 'admin.php?page=' . self::MENU_SLUG );
        ?>
        <div class="wrap tejcart-admin-wrap">
            <?php
            Page_Header::list(
                __( 'Sales Channels', 'tejcart' ),
                __( 'Connect your TejCart store to external marketplaces and social commerce platforms.', 'tejcart' )
            );
            ?>

            <nav class="tejcart-settings-subnav" aria-label="<?php esc_attr_e( 'Sales channels', 'tejcart' ); ?>">
                <?php foreach ( $tabs as $slug => $tab ) :
                    $url        = add_query_arg( 'channel', $slug, $base );
                    $is_current = ( $slug === $channel );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>"
                       class="tejcart-settings-subnav-item<?php echo $is_current ? ' is-active' : ''; ?>"
                        <?php echo $is_current ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html( $tab['label'] ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            if ( isset( $tabs[ $channel ]['callback'] ) && is_callable( $tabs[ $channel ]['callback'] ) ) {
                call_user_func( $tabs[ $channel ]['callback'] );
            }
            ?>
        </div>
        <?php
    }
}
