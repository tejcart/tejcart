<?php
/**
 * Deactivation feedback modal.
 *
 * Shows a feedback modal on the Plugins screen when an administrator
 * deactivates TejCart, asking why. The selected reason plus optional free
 * text and non-personal environment information are forwarded to a
 * site-configured remote endpoint (Airtable's REST API by default).
 * Deactivation is never blocked — even when the remote call fails or no
 * endpoint is configured.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the deactivation feedback modal and handles its AJAX submission.
 */
class Deactivation_Feedback {

    /**
     * Nonce action protecting the AJAX submission.
     *
     * @var string
     */
    private const NONCE = 'tejcart_deactivation';

    /**
     * AJAX action name.
     *
     * @var string
     */
    private const ACTION = 'tejcart_send_deactivation';

    /**
     * Register WordPress hooks. Called from the feature-bindings map.
     *
     * @return void
     */
    public function init(): void {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_footer', array( $this, 'render_modal' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_request' ) );
    }

    /**
     * Print the modal markup in the footer of the Plugins screen only.
     *
     * @return void
     */
    public function render_modal(): void {
        global $pagenow;
        if ( 'plugins.php' !== $pagenow ) {
            return;
        }

        $deactivate_url = wp_nonce_url(
            'plugins.php?action=deactivate&amp;plugin=' . rawurlencode( TEJCART_PLUGIN_BASENAME ),
            'deactivate-plugin_' . TEJCART_PLUGIN_BASENAME
        );

        $reasons = $this->get_reasons();
        ?>
        <div class="tejcart-deactivation-Modal" aria-hidden="true">
            <div class="tejcart-deactivation-Modal-header">
                <div>
                    <button type="button" class="tejcart-deactivation-Modal-return"><span class="dashicons dashicons-arrow-left-alt2"></span><span class="screen-reader-text"><?php esc_html_e( 'Return', 'tejcart' ); ?></span></button>
                    <h2><?php esc_html_e( 'We’re sorry to see you go! 💔', 'tejcart' ); ?></h2>
                </div>
                <button type="button" class="tejcart-deactivation-Modal-close"><span class="dashicons dashicons-no-alt"></span><span class="screen-reader-text"><?php esc_html_e( 'Close', 'tejcart' ); ?></span></button>
            </div>
            <div class="tejcart-deactivation-Modal-content">
                <div class="tejcart-deactivation-Modal-question tejcart-deactivation-isOpen">
                    <p><?php esc_html_e( 'Can you please tell us why you’re deactivating TejCart? Your feedback helps us make it better.', 'tejcart' ); ?></p>
                    <ul>
                        <?php foreach ( $reasons as $reason ) : ?>
                            <li>
                                <input type="radio" name="tejcart-reason" id="<?php echo esc_attr( $reason['id'] ); ?>" value="<?php echo esc_attr( $reason['value'] ); ?>">
                                <label for="<?php echo esc_attr( $reason['id'] ); ?>"><?php echo esc_html( $reason['label'] ); ?></label>
                                <?php if ( '' !== $reason['placeholder'] ) : ?>
                                    <div class="tejcart-deactivation-Modal-fieldHidden">
                                        <textarea placeholder="<?php echo esc_attr( $reason['placeholder'] ); ?>"></textarea>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <p class="tejcart-deactivation-Modal-privacy">
                    <?php esc_html_e( 'Your privacy is important to us. No personal data is collected with this form — just your valuable feedback and basic system information (such as WordPress and plugin versions) to help us improve TejCart.', 'tejcart' ); ?>
                </p>
            </div>
            <div class="tejcart-deactivation-Modal-footer">
                <a href="https://wordpress.org/support/plugin/tejcart" class="button" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Support', 'tejcart' ); ?></a>
                <div>
                    <a href="<?php echo esc_url( $deactivate_url ); ?>" class="button button-primary tejcart-deactivation-isDisabled" id="tejcart-send-deactivation"><?php esc_html_e( 'Send & Deactivate', 'tejcart' ); ?></a>
                </div>
                <a id="tejcart-deactivation-no-reason" href="<?php echo esc_url( $deactivate_url ); ?>"><?php esc_html_e( 'I’d rather not say', 'tejcart' ); ?></a>
            </div>
        </div>
        <div class="tejcart-deactivation-Modal-overlay"></div>
        <?php
    }

    /**
     * Enqueue the modal stylesheet and script on the Plugins screen only.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'plugins.php' !== $hook ) {
            return;
        }

        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';

        wp_enqueue_style(
            'tejcart-deactivation-feedback',
            tejcart_asset_url( 'assets/css/tejcart-deactivation-feedback.css' ),
            array(),
            $version
        );

        wp_enqueue_script(
            'tejcart-deactivation-feedback',
            tejcart_asset_url( 'assets/js/tejcart-deactivation-feedback.js' ),
            array( 'jquery' ),
            $version,
            true
        );

        wp_localize_script(
            'tejcart-deactivation-feedback',
            'tejcartFeedback',
            array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'action'     => self::ACTION,
                'nonce'      => wp_create_nonce( self::NONCE ),
                'pluginFile' => TEJCART_PLUGIN_BASENAME,
                'slug'       => dirname( TEJCART_PLUGIN_BASENAME ),
                'i18n'       => array(
                    'selectReason' => __( 'Please select a reason before deactivating.', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * AJAX handler.
     *
     * Validates the request, forwards the feedback to the configured
     * endpoint, and always responds with success so the deactivation can
     * proceed.
     *
     * @return void
     */
    public function handle_request(): void {
        $method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
            : '';
        if ( 'POST' !== $method ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request method.', 'tejcart' ) ), 405 );
        }

        check_ajax_referer( self::NONCE, 'nonce' );

        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'tejcart' ) ), 403 );
        }

        $reason         = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
        $reason_details = isset( $_POST['reason_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason_details'] ) ) : '';

        $this->send_feedback( $reason, $reason_details );

        wp_send_json_success( array( 'message' => __( 'Feedback received.', 'tejcart' ) ) );
    }

    /**
     * The selectable deactivation reasons.
     *
     * @return array<int,array<string,string>>
     */
    private function get_reasons(): array {
        return array(
            array(
                'id'          => 'tejcart-reason-temporary',
                'value'       => 'Temporary Deactivation',
                'label'       => __( 'Temporary deactivation (troubleshooting)', 'tejcart' ),
                'placeholder' => '',
            ),
            array(
                'id'          => 'tejcart-reason-broken',
                'value'       => 'Compatibility Issue',
                'label'       => __( 'Compatibility issue', 'tejcart' ),
                'placeholder' => __( 'Please describe what part of the layout or functionality was affected.', 'tejcart' ),
            ),
            array(
                'id'          => 'tejcart-reason-complicated',
                'value'       => 'Difficult To Set Up',
                'label'       => __( 'Difficult to set up', 'tejcart' ),
                'placeholder' => __( 'What part of the setup was confusing or unclear?', 'tejcart' ),
            ),
            array(
                'id'          => 'tejcart-reason-missing',
                'value'       => 'Missing Features',
                'label'       => __( 'Missing features', 'tejcart' ),
                'placeholder' => __( 'Which features were you looking for?', 'tejcart' ),
            ),
            array(
                'id'          => 'tejcart-reason-other',
                'value'       => 'Other',
                'label'       => __( 'Other', 'tejcart' ),
                'placeholder' => __( 'Please share why you’re deactivating TejCart so we can make improvements.', 'tejcart' ),
            ),
        );
    }

    /**
     * Default TejCart feedback collector endpoint.
     *
     * A first-party tejcart.com endpoint that stores the feedback in the
     * TejCart MySQL database. No authentication is required to reach it.
     *
     * @var string
     */
    private const DEFAULT_ENDPOINT = 'https://tejcart.com/wp-content/feedback/deactivation-feedback.php';

    /**
     * Forward feedback to the TejCart feedback collector.
     *
     * Posts a flat JSON payload to a first-party tejcart.com endpoint that
     * writes it into the TejCart MySQL database. The endpoint defaults to
     * {@see self::DEFAULT_ENDPOINT} and can be overridden per-site:
     *
     *     define( 'TEJCART_FEEDBACK_ENDPOINT', 'https://tejcart.com/...' );
     *
     * The endpoint may also be supplied through the
     * `tejcart_deactivation_feedback_endpoint` filter. When the endpoint is
     * filtered to an empty string the request is skipped silently and
     * deactivation still proceeds.
     *
     * @param string $reason         Selected reason label.
     * @param string $reason_details Optional free-text details.
     * @return void
     */
    private function send_feedback( string $reason, string $reason_details ): void {
        $endpoint = defined( 'TEJCART_FEEDBACK_ENDPOINT' ) ? (string) TEJCART_FEEDBACK_ENDPOINT : self::DEFAULT_ENDPOINT;

        /**
         * Filter the deactivation-feedback endpoint URL.
         *
         * @param string $endpoint Endpoint URL. Empty disables the request.
         */
        $endpoint = (string) apply_filters( 'tejcart_deactivation_feedback_endpoint', $endpoint );

        // No endpoint — skip quietly so deactivation is never blocked.
        if ( '' === $endpoint ) {
            return;
        }

        $theme = wp_get_theme();

        $payload = array(
            'reason'         => $reason,
            'reason_details' => $reason_details,
            'plugin'         => 'TejCart',
            'php_version'    => phpversion(),
            'wp_version'     => get_bloginfo( 'version' ),
            'locale'         => get_locale(),
            'theme'          => $theme->get( 'Name' ),
            'theme_version'  => $theme->get( 'Version' ),
            'multisite'      => is_multisite() ? 'Yes' : 'No',
            'plugin_version' => defined( 'TEJCART_VERSION' ) ? (string) TEJCART_VERSION : '',
            'site_url'       => home_url(),
            'date'           => current_time( 'mysql' ),
        );

        $body = wp_json_encode( $payload );

        $args = array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $body,
            'method'  => 'POST',
            'timeout' => 10,
        );

        // Fire-and-forget: the result never blocks the deactivation experience.
        $response = wp_remote_post( $endpoint, $args );

        // Temporary diagnostic logging of the request/response so the
        // collector round-trip can be inspected in the TejCart logs
        // (uploads/tejcart-logs/). The bearer token is intentionally
        // never logged.
        if ( is_wp_error( $response ) ) {
            tejcart_log(
                'Deactivation feedback request failed.',
                'error',
                array(
                    'channel'  => 'deactivation_feedback',
                    'endpoint' => $endpoint,
                    'request'  => $body,
                    'error'    => $response->get_error_message(),
                )
            );
        } else {
            tejcart_log(
                'Deactivation feedback request sent.',
                'debug',
                array(
                    'channel'       => 'deactivation_feedback',
                    'endpoint'      => $endpoint,
                    'request'       => $body,
                    'response_code' => wp_remote_retrieve_response_code( $response ),
                    'response_body' => wp_remote_retrieve_body( $response ),
                )
            );
        }
    }
}
