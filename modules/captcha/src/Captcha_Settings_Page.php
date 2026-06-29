<?php
/**
 * Captcha (Bot_Gate) settings admin page.
 *
 * Provides the operator-facing UI for the pluggable bot mitigation gate
 * declared in {@see \TejCart\Captcha\Bot_Gate}. Mounts under
 * Settings → Advanced → Captcha (via the core `tejcart_settings_advanced_sub_nav_items`
 * filter and `tejcart_settings_render_advanced_section_captcha` action seam)
 * and persists the same `tejcart_*` options the verifier reads at request
 * time.
 *
 * @package TejCart\Captcha
 */

declare( strict_types=1 );

namespace TejCart\Captcha;

use TejCart\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin UI for configuring the captcha / bot mitigation provider.
 */
class Captcha_Settings_Page {

    /**
     * Allowed provider keys. Mirrors the allow-list in {@see Bot_Gate::active_provider()}.
     */
    private const PROVIDERS = array( 'none', 'turnstile', 'hcaptcha', 'recaptcha_v3' );

    /**
     * Hook into admin_init for save handling and register the Advanced-tab
     * settings section through the core seam.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'admin_init', array( $this, 'handle_save' ) );

        // Inject "Captcha" into the Settings → Advanced sub-nav (and thereby
        // the section allow-list + search index) and render its body when
        // that section is requested. The section lived hard-coded in core
        // before 1.0.1; it now rides the same filter seam the tax-providers
        // module uses for its Tax sub-section.
        add_filter( 'tejcart_settings_advanced_sub_nav_items', array( $this, 'register_section' ) );
        add_action( 'tejcart_settings_render_advanced_section_captcha', array( $this, 'render_section' ) );
    }

    /**
     * Add the Captcha sub-section to the Advanced tab sub-nav.
     *
     * @param array<string, string> $items Existing section_key => label map.
     * @return array<string, string>
     */
    public function register_section( $items ) {
        if ( ! is_array( $items ) ) {
            $items = array();
        }
        $items['captcha'] = __( 'Captcha', 'tejcart' );
        return $items;
    }

    /**
     * Render the Captcha section body inside the Advanced tab.
     *
     * @return void
     */
    public function render_section(): void {
        $this->render( true );
    }

    /**
     * Handle the POST submission. Verifies capability + nonce, persists the
     * fields via {@see self::persist_from_post()}, then redirects to the
     * canonical Captcha sub-section URL with `updated=1`.
     *
     * @return void
     */
    public function handle_save(): void {
        if ( ! isset( $_POST['tejcart_captcha_action'] ) || 'save' !== $_POST['tejcart_captcha_action'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['tejcart_captcha_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tejcart_captcha_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'tejcart_captcha_save' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above; persist_from_post sanitizes per field.
        $this->persist_from_post( wp_unslash( $_POST ) );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'    => 'tejcart-settings',
                    'tab'     => 'advanced',
                    'section' => 'captcha',
                    'updated' => '1',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Sanitize and persist the captcha options from a POST-shaped array.
     *
     * Kept public so the persistence layer can be tested directly without
     * routing through `handle_save()`'s redirect-and-exit. Callers are
     * responsible for capability and nonce checks.
     *
     * @param array<string,mixed> $post Already-unslashed POST data.
     * @return void
     */
    public function persist_from_post( array $post ): void {
        $provider = isset( $post['tejcart_bot_gate_provider'] )
            ? sanitize_key( (string) $post['tejcart_bot_gate_provider'] )
            : 'none';
        if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
            $provider = 'none';
        }
        update_option( Bot_Gate::OPTION_PROVIDER, $provider );

        // Site keys are public (rendered to every visitor), secrets are
        // server-side only. Separate the two so we only encrypt what
        // actually requires confidentiality at rest.
        $public_fields = array(
            Bot_Gate::OPTION_TURNSTILE_SITEKEY => 'tejcart_turnstile_sitekey',
            Bot_Gate::OPTION_HCAPTCHA_SITEKEY  => 'tejcart_hcaptcha_sitekey',
            Bot_Gate::OPTION_RECAPTCHA_SITEKEY => 'tejcart_recaptcha_sitekey',
        );
        foreach ( $public_fields as $option => $post_key ) {
            update_option(
                $option,
                isset( $post[ $post_key ] ) ? sanitize_text_field( (string) $post[ $post_key ] ) : ''
            );
        }

        // Secret keys are encrypted at rest via Crypto::encrypt_required.
        // Without this, the plaintext bot-protection secret sat in
        // wp_options, surfaced in mysqldump backups / replication
        // streams / support exports — anyone obtaining the dump could
        // mint valid captcha tokens at will, undermining the entire
        // bot-protection layer.
        //
        // encrypt_required throws on a host without openssl/libsodium
        // rather than silently persisting plaintext (the fail-closed
        // posture other secret-bearing settings already use). Empty
        // value clears the option without round-tripping through
        // encrypt.
        $secret_fields = array(
            Bot_Gate::OPTION_TURNSTILE_SECRET => 'tejcart_turnstile_secret',
            Bot_Gate::OPTION_HCAPTCHA_SECRET  => 'tejcart_hcaptcha_secret',
            Bot_Gate::OPTION_RECAPTCHA_SECRET => 'tejcart_recaptcha_secret',
        );
        foreach ( $secret_fields as $option => $post_key ) {
            $raw = isset( $post[ $post_key ] ) ? sanitize_text_field( (string) $post[ $post_key ] ) : '';
            if ( '' === $raw ) {
                update_option( $option, '' );
                continue;
            }
            try {
                update_option( $option, Crypto::encrypt_required( $raw ), false );
            } catch ( \Throwable $e ) {
                // Surface to the operator via the settings-errors API so
                // they know the secret wasn't saved instead of silently
                // believing it was. Keep the previously-stored value.
                add_settings_error(
                    'tejcart_captcha',
                    'tejcart_captcha_encrypt_failed',
                    sprintf(
                        /* translators: %s: option key for the captcha provider secret */
                        __( 'Could not encrypt %s. The host appears to be missing openssl/libsodium. The secret was NOT saved; the previous value is preserved.', 'tejcart' ),
                        $option
                    ),
                    'error'
                );
            }
        }

        $threshold = isset( $post['tejcart_recaptcha_threshold'] ) ? (float) $post['tejcart_recaptcha_threshold'] : 0.5;
        $threshold = max( 0.0, min( 1.0, $threshold ) );
        update_option( Bot_Gate::OPTION_RECAPTCHA_THRESH, $threshold );
    }

    /**
     * Render the captcha settings form.
     *
     * @param bool $embedded When true, skip the outer `<div class="wrap">`
     *                      and page heading for composition inside another
     *                      admin screen (Settings → Advanced → Captcha).
     * @return void
     */
    public function render( bool $embedded = false ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $provider           = (string) get_option( Bot_Gate::OPTION_PROVIDER, 'none' );
        if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
            $provider = 'none';
        }
        $turnstile_sitekey  = (string) get_option( Bot_Gate::OPTION_TURNSTILE_SITEKEY, '' );
        $hcaptcha_sitekey   = (string) get_option( Bot_Gate::OPTION_HCAPTCHA_SITEKEY, '' );
        $recaptcha_sitekey  = (string) get_option( Bot_Gate::OPTION_RECAPTCHA_SITEKEY, '' );

        // Secrets are encrypted at rest. Decrypt for the form re-render
        // so the operator can confirm what they previously entered and
        // edit incrementally without re-pasting the whole value. The
        // password-type input prevents shoulder-surfing during edit.
        // Crypto::decrypt is safe on plaintext (legacy pre-encryption
        // rows are returned unchanged via the prefix marker check).
        $decrypt = static function ( string $option ): string {
            $stored = (string) get_option( $option, '' );
            if ( '' === $stored || ! class_exists( '\\TejCart\\Security\\Crypto' ) ) {
                return $stored;
            }
            return Crypto::decrypt( $stored );
        };
        $turnstile_secret = $decrypt( Bot_Gate::OPTION_TURNSTILE_SECRET );
        $hcaptcha_secret  = $decrypt( Bot_Gate::OPTION_HCAPTCHA_SECRET );
        $recaptcha_secret = $decrypt( Bot_Gate::OPTION_RECAPTCHA_SECRET );
        $recaptcha_threshold = (float) get_option( Bot_Gate::OPTION_RECAPTCHA_THRESH, 0.5 );
        $recaptcha_threshold = max( 0.0, min( 1.0, $recaptcha_threshold ) );

        $providers = array(
            'none'         => __( 'None — disabled', 'tejcart' ),
            'turnstile'    => __( 'Cloudflare Turnstile', 'tejcart' ),
            'hcaptcha'     => __( 'hCaptcha', 'tejcart' ),
            'recaptcha_v3' => __( 'Google reCAPTCHA v3', 'tejcart' ),
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from our own redirect.
        $updated    = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
        $just_saved = '1' === $updated;
        ?>
        <?php if ( ! $embedded ) : ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <h1><?php esc_html_e( 'Captcha / Bot Protection', 'tejcart' ); ?></h1>
                    <p class="tejcart-page-subtitle"><?php esc_html_e( 'Configure a CAPTCHA provider in front of login, checkout, cart and coupon endpoints.', 'tejcart' ); ?></p>
                </div>
            </div>
        <?php endif; ?>

            <?php if ( $just_saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Captcha settings saved.', 'tejcart' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="tejcart-card tejcart-captcha-intro-card">
                <div class="tejcart-card-header">
                    <h3><?php esc_html_e( 'About bot protection', 'tejcart' ); ?></h3>
                </div>
                <div class="tejcart-card-body tejcart-captcha-intro-body">
                    <p><?php esc_html_e( 'TejCart can verify a CAPTCHA token before allowing high-friction actions that card-testing botnets target. The gate is layered on top of the per-IP rate limiter and only fires after the heuristic threshold for each surface is exceeded.', 'tejcart' ); ?></p>
                    <ul class="tejcart-captcha-intro-list">
                        <li><?php esc_html_e( 'Login — after 3 failed attempts on the same IP/username.', 'tejcart' ); ?></li>
                        <li><?php esc_html_e( 'Checkout — every submit.', 'tejcart' ); ?></li>
                        <li><?php esc_html_e( 'Cart add — after 20 rapid add-to-cart hits from the same IP.', 'tejcart' ); ?></li>
                        <li><?php esc_html_e( 'Coupon apply — after 3 invalid codes on the same IP.', 'tejcart' ); ?></li>
                    </ul>
                    <p class="description"><?php esc_html_e( 'Select "None" to disable the gate. Saving an empty secret for the active provider will cause verification to fail closed.', 'tejcart' ); ?></p>
                </div>
            </div>

            <form method="post" action="" class="tejcart-settings-form">
                <?php wp_nonce_field( 'tejcart_captcha_save', 'tejcart_captcha_nonce' ); ?>
                <input type="hidden" name="tejcart_captcha_action" value="save" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="tejcart-bot-gate-provider"><?php esc_html_e( 'Provider', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <select
                                id="tejcart-bot-gate-provider"
                                name="tejcart_bot_gate_provider"
                                class="regular-text"
                                data-tejcart-captcha-provider
                            >
                                <?php foreach ( $providers as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $provider, $key ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Pick the captcha service to verify against. Credentials below must match the selected provider.', 'tejcart' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div
                    data-tejcart-captcha-section="turnstile"
                    <?php echo 'turnstile' === $provider ? '' : 'hidden'; ?>
                >
                <h2 class="tejcart-captcha-provider-heading"><?php esc_html_e( 'Cloudflare Turnstile', 'tejcart' ); ?></h2>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to the Turnstile dashboard. */
                        esc_html__( 'Create a site key/secret pair in %s.', 'tejcart' ),
                        '<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">' . esc_html__( 'the Cloudflare dashboard', 'tejcart' ) . '</a>'
                    );
                    ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="tejcart-turnstile-sitekey"><?php esc_html_e( 'Site key', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="tejcart-turnstile-sitekey"
                                name="tejcart_turnstile_sitekey"
                                value="<?php echo esc_attr( $turnstile_sitekey ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tejcart-turnstile-secret"><?php esc_html_e( 'Secret key', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="tejcart-turnstile-secret"
                                name="tejcart_turnstile_secret"
                                value="<?php echo esc_attr( $turnstile_secret ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                            <p class="description"><?php esc_html_e( 'Stored as-is. Treat the secret like a password.', 'tejcart' ); ?></p>
                        </td>
                    </tr>
                </table>
                </div>

                <div
                    data-tejcart-captcha-section="hcaptcha"
                    <?php echo 'hcaptcha' === $provider ? '' : 'hidden'; ?>
                >
                <h2 class="tejcart-captcha-provider-heading"><?php esc_html_e( 'hCaptcha', 'tejcart' ); ?></h2>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to the hCaptcha dashboard. */
                        esc_html__( 'Sign up and retrieve credentials from %s.', 'tejcart' ),
                        '<a href="https://dashboard.hcaptcha.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'the hCaptcha dashboard', 'tejcart' ) . '</a>'
                    );
                    ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="tejcart-hcaptcha-sitekey"><?php esc_html_e( 'Site key', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="tejcart-hcaptcha-sitekey"
                                name="tejcart_hcaptcha_sitekey"
                                value="<?php echo esc_attr( $hcaptcha_sitekey ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tejcart-hcaptcha-secret"><?php esc_html_e( 'Secret key', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="tejcart-hcaptcha-secret"
                                name="tejcart_hcaptcha_secret"
                                value="<?php echo esc_attr( $hcaptcha_secret ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                        </td>
                    </tr>
                </table>
                </div>

                <div
                    data-tejcart-captcha-section="recaptcha_v3"
                    <?php echo 'recaptcha_v3' === $provider ? '' : 'hidden'; ?>
                >
                <h2 class="tejcart-captcha-provider-heading"><?php esc_html_e( 'Google reCAPTCHA v3', 'tejcart' ); ?></h2>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to the reCAPTCHA admin console. */
                        esc_html__( 'Register your site at %s. Only reCAPTCHA v3 (score-based) is supported.', 'tejcart' ),
                        '<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">' . esc_html__( 'the reCAPTCHA admin console', 'tejcart' ) . '</a>'
                    );
                    ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="tejcart-recaptcha-sitekey"><?php esc_html_e( 'Site key', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="tejcart-recaptcha-sitekey"
                                name="tejcart_recaptcha_sitekey"
                                value="<?php echo esc_attr( $recaptcha_sitekey ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tejcart-recaptcha-secret"><?php esc_html_e( 'Secret key', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="tejcart-recaptcha-secret"
                                name="tejcart_recaptcha_secret"
                                value="<?php echo esc_attr( $recaptcha_secret ); ?>"
                                class="regular-text"
                                autocomplete="off"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tejcart-recaptcha-threshold"><?php esc_html_e( 'Score threshold', 'tejcart' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="number"
                                id="tejcart-recaptcha-threshold"
                                name="tejcart_recaptcha_threshold"
                                value="<?php echo esc_attr( (string) $recaptcha_threshold ); ?>"
                                min="0"
                                max="1"
                                step="0.05"
                                class="small-text"
                            />
                            <p class="description"><?php esc_html_e( 'Requests scoring below this value (0.0 – 1.0) are rejected. Google recommends 0.5 as a starting point. Lower values are more permissive.', 'tejcart' ); ?></p>
                        </td>
                    </tr>
                </table>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Captcha Settings', 'tejcart' ); ?>
                    </button>
                </p>
            </form>
        <?php if ( ! $embedded ) : ?>
        </div>
        <?php endif; ?>
        <?php
    }
}
