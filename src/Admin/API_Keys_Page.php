<?php
/**
 * REST API keys admin page.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\API\API_Keys;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin UI for creating and revoking REST API consumer key/secret pairs.
 */
class API_Keys_Page {
    /**
     * Hook into admin_init for action handling.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
    }

    /**
     * Handle create / revoke actions.
     *
     * @return void
     */
    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['tejcart_api_key_action'] ) && 'create' === $_POST['tejcart_api_key_action'] ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'tejcart_create_api_key' ) ) {
                return;
            }

            $description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
            $permissions = isset( $_POST['permissions'] ) ? sanitize_text_field( wp_unslash( $_POST['permissions'] ) ) : 'read';
            // Always bind the key to the acting administrator. There is no UI
            // field for user_id, and honouring a request-supplied one only let
            // an admin mis-attribute REST credentials to another user (audit-
            // trail confusion), so it is ignored.
            $user_id     = get_current_user_id();

            $created = API_Keys::create( $user_id, $description, API_Keys::normalize_permissions( $permissions ) );

            set_transient(
                'tejcart_api_key_new',
                $created,
                60
            );

            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=api-keys&created=1' ) );
            exit;
        }

        if ( isset( $_GET['action'], $_GET['key_id'], $_GET['_wpnonce'] ) && 'revoke' === $_GET['action'] ) {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tejcart_revoke_api_key_' . (int) $_GET['key_id'] ) ) {
                return;
            }
            API_Keys::revoke( (int) $_GET['key_id'] );
            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=api-keys&revoked=1' ) );
            exit;
        }
    }

    /**
     * Render the page.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page header for composition inside another
     *                      admin screen (Settings → Advanced → API Keys).
     * @return void
     */
    public function render( bool $embedded = false ): void {
        $keys      = API_Keys::list_keys();
        $just_made = get_transient( 'tejcart_api_key_new' );
        if ( is_array( $just_made ) ) {
            delete_transient( 'tejcart_api_key_new' );
        }
        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'REST API Keys', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Generate consumer key/secret pairs for REST API access.', 'tejcart' ); ?></p>
                </div>
            </div>
        <?php endif; ?>

            <?php if ( is_array( $just_made ) ) : ?>
                <div class="notice notice-success">
                    <p>
                        <strong><?php esc_html_e( 'Key created — copy the secret below. It will not be shown again.', 'tejcart' ); ?></strong>
                    </p>
                    <table class="widefat">
                        <tr><th><?php esc_html_e( 'Consumer key', 'tejcart' ); ?></th><td><code><?php echo esc_html( $just_made['consumer_key'] ); ?></code></td></tr>
                        <tr><th><?php esc_html_e( 'Consumer secret', 'tejcart' ); ?></th><td><code><?php echo esc_html( $just_made['consumer_secret'] ); ?></code></td></tr>
                    </table>
                </div>
            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            elseif ( ! empty( $_GET['revoked'] ) ) :
                \TejCart\Admin\Flash_Notice::render(
                    __( 'API key revoked.', 'tejcart' ),
                    '',
                    \TejCart\Admin\Flash_Notice::TONE_SUCCESS
                );
            endif;
            ?>

            <div class="tejcart-card" style="margin-bottom: 24px;">
                <div class="tejcart-card-header">
                    <h3><?php esc_html_e( 'Create a new key', 'tejcart' ); ?></h3>
                </div>
                <form method="post" style="padding: 16px;">
                    <?php wp_nonce_field( 'tejcart_create_api_key' ); ?>
                    <input type="hidden" name="tejcart_api_key_action" value="create" />
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="tejcart-api-description"><?php esc_html_e( 'Description', 'tejcart' ); ?></label></th>
                            <td><input type="text" id="tejcart-api-description" name="description" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Mobile app integration', 'tejcart' ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="tejcart-api-permissions"><?php esc_html_e( 'Permissions', 'tejcart' ); ?></label></th>
                            <td>
                                <select id="tejcart-api-permissions" name="permissions">
                                    <option value="read"><?php esc_html_e( 'Read only', 'tejcart' ); ?></option>
                                    <option value="write"><?php esc_html_e( 'Write only', 'tejcart' ); ?></option>
                                    <option value="read_write" selected><?php esc_html_e( 'Read / Write', 'tejcart' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Generate API key', 'tejcart' ); ?></button></p>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Description', 'tejcart' ); ?></th>
                        <th><?php esc_html_e( 'User', 'tejcart' ); ?></th>
                        <th><?php esc_html_e( 'Permissions', 'tejcart' ); ?></th>
                        <th><?php esc_html_e( 'Consumer key ending in', 'tejcart' ); ?></th>
                        <th><?php esc_html_e( 'Last access', 'tejcart' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $keys ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No API keys yet.', 'tejcart' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $keys as $key ) :
                        $user = get_userdata( (int) $key['user_id'] );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $key['description'] ?: __( '(no description)', 'tejcart' ) ); ?></td>
                            <td><?php echo esc_html( $user ? $user->user_login : sprintf( '#%d', (int) $key['user_id'] ) ); ?></td>
                            <td><?php echo esc_html( $key['permissions'] ); ?></td>
                            <td><code>…<?php echo esc_html( $key['truncated_key'] ); ?></code></td>
                            <td><?php echo esc_html( $key['last_access'] ?: __( 'Never', 'tejcart' ) ); ?></td>
                            <td>
                                <a class="button-link delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=api-keys&action=revoke&key_id=' . (int) $key['id'] ), 'tejcart_revoke_api_key_' . (int) $key['id'] ) ); ?>"
                                   onclick="return confirm('<?php esc_attr_e( 'Revoke this API key? Applications using it will be locked out.', 'tejcart' ); ?>');">
                                    <?php esc_html_e( 'Revoke', 'tejcart' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }
}
