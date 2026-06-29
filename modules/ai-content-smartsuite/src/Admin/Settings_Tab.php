<?php
/**
 * AI Content settings — rendered as a tab inside the unified TejCart
 * Settings page so the UI inherits core's sidebar / card / form-table
 * design tokens 1:1. Mirrors the integration pattern used by the
 * `currency-switcher` module.
 *
 *  - `tejcart_settings_tabs`              — registers the "AI Content" tab
 *  - `tejcart_settings_tab_groups`        — slots it into the "Commerce" group
 *  - `tejcart_settings_subnav_items_<tab>` — declares the two sub-sections
 *  - `tejcart_settings_render_tab_<tab>`  — renders the body
 *
 * @package TejCart\AI_Content_Smartsuite\Admin
 */

declare(strict_types=1);

namespace TejCart\AI_Content_Smartsuite\Admin;

use TejCart\AI_Content_Smartsuite\AI\Provider_Registry;
use TejCart\AI_Content_Smartsuite\Capabilities;
use TejCart\AI_Content_Smartsuite\Default_Prompts;
use TejCart\AI_Content_Smartsuite\Languages;
use TejCart\AI_Content_Smartsuite\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Settings_Tab {
    public const TAB_ID         = 'ai-content';
    public const NONCE_ACTION   = 'tejcart_ai_content_settings';
    public const NONCE_FIELD    = 'tejcart_ai_content_nonce';
    public const SUBMIT_FIELD   = 'tejcart_ai_content_submit';
    public const SECTION_FIELD  = 'tejcart_ai_content_section';

    public const SECTION_API     = 'api';
    public const SECTION_PROMPTS = 'prompts';

    public static function register(): void {
        $instance = new self();
        add_action( 'admin_init',                                       array( $instance, 'maybe_save' ) );
        add_filter( 'tejcart_settings_tabs',                            array( $instance, 'register_tab' ) );
        add_filter( 'tejcart_settings_tab_groups',                      array( $instance, 'register_tab_group' ), 10, 2 );
        add_filter( 'tejcart_settings_subnav_items_' . self::TAB_ID,    array( $instance, 'register_subnav_items' ) );
        add_action( 'tejcart_settings_render_tab_' . self::TAB_ID,      array( $instance, 'render_tab' ), 10, 2 );
    }

    public static function settings_url( string $section = '' ): string {
        $url = admin_url( 'admin.php?page=tejcart-settings&tab=' . self::TAB_ID );
        if ( '' !== $section ) {
            $url = add_query_arg( 'section', $section, $url );
        }
        return $url;
    }

    /**
     * @param array<string,array<string,mixed>> $tabs
     */
    public function register_tab( array $tabs ): array {
        $tabs[ self::TAB_ID ] = array(
            'id'       => self::TAB_ID,
            'label'    => __( 'AI Content', 'tejcart' ),
            'icon'     => 'dashicons-lightbulb',
            'desc'     => __( 'AI-generated product titles, descriptions, tags, and FAQs with OpenAI.', 'tejcart' ),
            'sections' => array(),
        );
        return $tabs;
    }

    /**
     * @param array<string,array{label:string,tabs:string[]}> $groups
     * @param array<string,mixed>                              $tabs
     */
    public function register_tab_group( array $groups, array $tabs ): array {
        if ( isset( $groups['commerce'] ) && is_array( $groups['commerce']['tabs'] ?? null ) ) {
            if ( ! in_array( self::TAB_ID, $groups['commerce']['tabs'], true ) ) {
                $groups['commerce']['tabs'][] = self::TAB_ID;
            }
        }
        return $groups;
    }

    /**
     * @return array<string,string>
     */
    public function register_subnav_items(): array {
        return array(
            self::SECTION_API     => __( 'API Settings', 'tejcart' ),
            self::SECTION_PROMPTS => __( 'Prompt Templates', 'tejcart' ),
        );
    }

    public function render_tab( string $section = '', string $tab = '' ): void {
        if ( ! Capabilities::current_user_can_manage() ) {
            esc_html_e( 'You do not have permission to access this page.', 'tejcart' );
            return;
        }
        settings_errors( self::NONCE_ACTION );

        if ( self::SECTION_PROMPTS === $section ) {
            $this->render_prompts_section();
        } else {
            $this->render_api_section();
        }
    }

    public function maybe_save(): void {
        if ( empty( $_POST[ self::SUBMIT_FIELD ] ) && empty( $_POST['reset_prompts'] ) ) {
            return;
        }
        if ( ! Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'Forbidden.', 'tejcart' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        $section = isset( $_POST[ self::SECTION_FIELD ] )
            ? sanitize_key( wp_unslash( (string) $_POST[ self::SECTION_FIELD ] ) )
            : '';

        if ( self::SECTION_API === $section ) {
            $result = Settings::save_api( wp_unslash( (array) $_POST ) );
        } elseif ( self::SECTION_PROMPTS === $section ) {
            if ( ! empty( $_POST['reset_prompts'] ) ) {
                Settings::reset_prompts();
                $result = array( 'ok' => true, 'message' => __( 'Prompt templates reset to defaults.', 'tejcart' ) );
            } else {
                $result = Settings::save_prompts( wp_unslash( (array) $_POST ) );
            }
        } else {
            return;
        }

        add_settings_error(
            self::NONCE_ACTION,
            self::NONCE_ACTION . '_save',
            (string) ( $result['message'] ?? '' ),
            $result['ok'] ? 'success' : 'error'
        );
        set_transient(
            'settings_errors',
            get_settings_errors(),
            30
        );

        wp_safe_redirect( self::settings_url( $section ) . '&settings-updated=true' );
        exit;
    }

    // ------------------------------------------------------------------
    // Section renderers — each emits a `.tejcart-card` wrapping a form.
    // ------------------------------------------------------------------

    private function open_section_form( string $section, string $title, string $icon, string $description = '' ): void {
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h3>
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
                    <?php echo esc_html( $title ); ?>
                </h3>
            </div>
            <form method="post" action="" class="tejcart-card-body">
                <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
                <input type="hidden" name="<?php echo esc_attr( self::SECTION_FIELD ); ?>" value="<?php echo esc_attr( $section ); ?>" />
                <?php if ( '' !== $description ) : ?>
                    <p class="description" style="margin-top:0;"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
        <?php
    }

    private function close_section_form( string $submit_label = '' ): void {
        $submit_label = '' !== $submit_label ? $submit_label : __( 'Save changes', 'tejcart' );
        ?>
                <p class="submit">
                    <button type="submit" name="<?php echo esc_attr( self::SUBMIT_FIELD ); ?>" value="1" class="button button-primary">
                        <?php echo esc_html( $submit_label ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_api_section(): void {
        $settings  = Settings::get();
        $has_key   = Settings::has_api_key();
        $providers = Provider_Registry::all();
        $model_labels = array(
            'gpt-4o-mini'    => __( 'gpt-4o-mini (Fast, Affordable)', 'tejcart' ),
            'gpt-4o'         => __( 'gpt-4o (Balanced)', 'tejcart' ),
            'gpt-4.1-mini'   => __( 'gpt-4.1-mini (Latest, Fast)', 'tejcart' ),
            'gpt-4.1-nano'   => __( 'gpt-4.1-nano (Lightweight)', 'tejcart' ),
            'gpt-4.1'        => __( 'gpt-4.1 (Best Quality)', 'tejcart' ),
            'gpt-4-turbo'    => __( 'gpt-4-turbo (Legacy)', 'tejcart' ),
            'gpt-3.5-turbo'  => __( 'gpt-3.5-turbo (Legacy)', 'tejcart' ),
        );
        $models = array();
        foreach ( Settings::allowed_models() as $slug ) {
            $models[ $slug ] = $model_labels[ $slug ] ?? $slug;
        }

        $this->open_section_form(
            self::SECTION_API,
            __( 'API Settings', 'tejcart' ),
            'dashicons-admin-network',
            __( 'Connect your OpenAI account. The API key is encrypted at rest.', 'tejcart' )
        );
        ?>
        <div class="notice notice-info inline" style="margin:0 0 16px;">
            <p>
                <strong><?php esc_html_e( 'Data sent to OpenAI:', 'tejcart' ); ?></strong>
                <?php esc_html_e( 'Product name, description, short description, tags, categories, and attributes. No customer data, pricing, or personally identifiable information is transmitted.', 'tejcart' ); ?>
                <a href="https://openai.com/enterprise-privacy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'OpenAI data usage policy', 'tejcart' ); ?> ↗</a>
            </p>
        </div>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="tejcart-ai-provider"><?php esc_html_e( 'Provider', 'tejcart' ); ?></label></th>
                    <td>
                        <select id="tejcart-ai-provider" name="provider" class="regular-text">
                            <?php foreach ( $providers as $slug => $meta ) :
                                $disabled = 'active' !== $meta['status'];
                                $selected = ( $settings['provider'] ?? '' ) === $slug;
                                ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"
                                    <?php echo $disabled ? 'disabled' : ''; ?>
                                    <?php selected( $selected ); ?>>
                                    <?php echo esc_html( $meta['label'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tejcart-ai-model"><?php esc_html_e( 'Model', 'tejcart' ); ?></label></th>
                    <td>
                        <select id="tejcart-ai-model" name="model" class="regular-text">
                            <?php foreach ( $models as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( ( $settings['model'] ?? '' ) === $value ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tejcart-ai-temperature"><?php esc_html_e( 'Temperature', 'tejcart' ); ?></label></th>
                    <td>
                        <input type="number" id="tejcart-ai-temperature" name="temperature"
                               value="<?php echo esc_attr( (string) ( $settings['temperature'] ?? 0.7 ) ); ?>"
                               min="0" max="2" step="0.1" class="small-text" />
                        <p class="description">
                            <?php esc_html_e( 'Controls output randomness (0 = deterministic, 2 = most creative). Default: 0.7. Lower values produce more consistent copy; higher values produce more varied, creative output.', 'tejcart' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tejcart-ai-api-key"><?php esc_html_e( 'API Key', 'tejcart' ); ?></label></th>
                    <td>
                        <input type="password"
                               id="tejcart-ai-api-key"
                               name="api_key"
                               class="regular-text"
                               autocomplete="new-password"
                               spellcheck="false"
                               placeholder="<?php echo $has_key ? esc_attr( '••••••••' ) : esc_attr( 'sk-...' ); ?>" />
                        <button type="button" class="button" id="tejcart-ai-validate-key">
                            <?php esc_html_e( 'Validate API Key', 'tejcart' ); ?>
                        </button>
                        <span id="tejcart-ai-validate-result" class="tejcart-ai-inline-status" aria-live="polite"></span>
                        <?php if ( $has_key ) : ?>
                            <p class="description">
                                <label>
                                    <input type="checkbox" name="clear_api_key" value="1" />
                                    <?php esc_html_e( 'Clear the stored API key', 'tejcart' ); ?>
                                </label>
                            </p>
                        <?php endif; ?>
                        <p class="description">
                            <?php esc_html_e( 'Stored encrypted at rest. Leave blank to keep the existing key.', 'tejcart' ); ?>
                        </p>
                    </td>
                </tr>
                <?php
                $raw_settings = get_option( Settings::OPTION_KEY, array() );
                if ( ! is_array( $raw_settings ) ) { $raw_settings = array(); }
                $daily_budget = (int) ( $raw_settings['daily_token_budget'] ?? 0 );
                $hourly_limit = (int) ( $raw_settings['hourly_request_limit'] ?? 0 );
                ?>
                <tr>
                    <th scope="row"><label for="tejcart-ai-daily-budget"><?php esc_html_e( 'Daily token budget', 'tejcart' ); ?></label></th>
                    <td>
                        <input type="number" id="tejcart-ai-daily-budget" name="daily_token_budget"
                               value="<?php echo esc_attr( (string) $daily_budget ); ?>" min="0" step="1000" class="small-text" />
                        <p class="description">
                            <?php esc_html_e( 'Maximum total tokens per day across all users. 0 = unlimited. Recommended: 500000 for moderate use.', 'tejcart' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tejcart-ai-hourly-limit"><?php esc_html_e( 'Hourly request limit', 'tejcart' ); ?></label></th>
                    <td>
                        <input type="number" id="tejcart-ai-hourly-limit" name="hourly_request_limit"
                               value="<?php echo esc_attr( (string) $hourly_limit ); ?>" min="0" step="1" class="small-text" />
                        <p class="description">
                            <?php esc_html_e( 'Maximum AI requests per user per hour. 0 = unlimited. Recommended: 100.', 'tejcart' ); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
        $this->close_section_form( __( 'Save API Settings', 'tejcart' ) );

        // Localise a tiny inline script so the Validate button works inside
        // core's Settings page (no separate JS file needed — keeps payload
        // small and avoids depending on the Content Generator bundle).
        $this->print_validate_script();

        // Quick link to the Content Generator page.
        ?>
        <div class="tejcart-card">
            <div class="tejcart-card-header">
                <h3>
                    <span class="dashicons dashicons-edit-page" aria-hidden="true"></span>
                    <?php esc_html_e( 'Content Generator', 'tejcart' ); ?>
                </h3>
            </div>
            <div class="tejcart-card-body">
                <p><?php esc_html_e( 'Generate, review, and apply AI-written copy for any product in your catalogue.', 'tejcart' ); ?></p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-ai-content' ) ); ?>">
                    <?php esc_html_e( 'Open Content Generator', 'tejcart' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    private function render_prompts_section(): void {
        $settings = Settings::get();

        $prompt_labels = array(
            'name_prompt'        => __( 'Product Name Prompt', 'tejcart' ),
            'short_desc_prompt'  => __( 'Short Description Prompt', 'tejcart' ),
            'description_prompt' => __( 'Description Prompt', 'tejcart' ),
            'tags_prompt'        => __( 'Tags Prompt', 'tejcart' ),
            'faqs_prompt'        => __( 'FAQs Prompt', 'tejcart' ),
        );

        $current_lang = (string) ( $settings['language'] ?? Languages::default_locale() );

        $this->open_section_form(
            self::SECTION_PROMPTS,
            __( 'Prompt Templates', 'tejcart' ),
            'dashicons-edit',
            __( 'Customise the prompts sent to OpenAI. Use {placeholders} to inject product fields.', 'tejcart' )
        );
        ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="tejcart-ai-language"><?php esc_html_e( 'Response Language', 'tejcart' ); ?></label></th>
                    <td>
                        <select id="tejcart-ai-language" name="language" class="regular-text">
                            <?php foreach ( Languages::all() as $code => $label ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_lang === $code ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'The AI will respond in the selected language.', 'tejcart' ); ?></p>
                    </td>
                </tr>
                <?php foreach ( $prompt_labels as $key => $label ) :
                    $current_val = (string) ( $settings['prompts'][ $key ] ?? Default_Prompts::get( $key ) );
                    ?>
                    <tr>
                        <th scope="row"><label for="tejcart-ai-prompt-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                        <td>
                            <textarea id="tejcart-ai-prompt-<?php echo esc_attr( $key ); ?>"
                                      name="prompts[<?php echo esc_attr( $key ); ?>]"
                                      rows="7"
                                      class="large-text code"><?php echo esc_textarea( $current_val ); ?></textarea>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Supported placeholders', 'tejcart' ); ?></th>
                    <td><code><?php echo esc_html( '{product_name}, {product_description}, {product_short_desc}, {product_tags}, {product_category}, {product_attributes}' ); ?></code></td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" name="<?php echo esc_attr( self::SUBMIT_FIELD ); ?>" value="1" class="button button-primary">
                <?php esc_html_e( 'Save Prompts', 'tejcart' ); ?>
            </button>
            <button type="submit" name="reset_prompts" value="1" class="button"
                    onclick="return confirm('<?php echo esc_js( __( 'Reset all prompt templates to defaults?', 'tejcart' ) ); ?>');">
                <?php esc_html_e( 'Reset to Default', 'tejcart' ); ?>
            </button>
        </p>
            </form>
        </div>
        <?php
    }

    /**
     * Inline JS so the Validate button works without a separate bundle —
     * the core Settings page doesn't enqueue the Content Generator's
     * admin.js, so we ship the minimal nonce-aware fetch inline here.
     */
    private function print_validate_script(): void {
        $nonce = wp_create_nonce( Capabilities::NONCE_AJAX );
        $i18n  = array(
            'validating' => __( 'Validating…', 'tejcart' ),
            'valid'      => __( 'Valid', 'tejcart' ),
            'invalid'    => __( 'Invalid', 'tejcart' ),
        );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
        <script>
        (function(){
            var btn = document.getElementById('tejcart-ai-validate-key');
            if (!btn) { return; }
            btn.addEventListener('click', function(){
                var input = document.getElementById('tejcart-ai-api-key');
                var out = document.getElementById('tejcart-ai-validate-result');
                var key = (input.value || '').trim();
                out.className = 'tejcart-ai-inline-status is-info';
                out.textContent = <?php echo wp_json_encode( $i18n['validating'] ); ?>;
                var body = new URLSearchParams();
                body.append('action', 'tejcart_ai_content_validate_api_key');
                body.append('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
                body.append('api_key', key);
                fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function(r){ return r.json(); }).then(function(resp){
                    if (resp && resp.success) {
                        out.className = 'tejcart-ai-inline-status is-ok';
                        out.textContent = (resp.data && resp.data.message) || <?php echo wp_json_encode( $i18n['valid'] ); ?>;
                    } else {
                        out.className = 'tejcart-ai-inline-status is-error';
                        out.textContent = (resp && resp.data && resp.data.message) || <?php echo wp_json_encode( $i18n['invalid'] ); ?>;
                    }
                }).catch(function(){
                    out.className = 'tejcart-ai-inline-status is-error';
                    out.textContent = <?php echo wp_json_encode( $i18n['invalid'] ); ?>;
                });
            });
        })();
        </script>
        <style>
            .tejcart-ai-inline-status { display:inline-block; margin-left:8px; font-weight:600; }
            .tejcart-ai-inline-status.is-ok    { color: var(--nc-success, #00a32a); }
            .tejcart-ai-inline-status.is-error { color: var(--nc-error,   #d63638); }
            .tejcart-ai-inline-status.is-info  { color: var(--nc-text,    #1e1e1e); }
        </style>
        <?php
    }
}
