<?php
/**
 * First-run setup wizard.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Five-step onboarding flow (store → tax → shipping → emails & pages → launch)
 * rendered as a single-page wizard with a horizontal stepper.
 *
 * Each step persists independently via admin-ajax so users can resume from
 * where they left off, and every step except the final Launch screen is
 * skippable. Skipped step IDs are stored so the dashboard can surface
 * follow-up nudges.
 *
 * Payments deliberately do NOT live in the wizard. PayPal — the platform's
 * primary gateway — onboards via the Partner Referrals "Connect with PayPal"
 * flow on the gateway settings page; collecting client_id / client_secret
 * manually here would be a step backwards. The Launch screen surfaces a
 * prominent CTA that deep-links into that flow.
 */
class Setup_Wizard {
    /**
     * Legacy flag retained so existing activation logic in Installer keeps
     * working. Set to 'yes' once the wizard has been completed or dismissed.
     */
    public const COMPLETED_OPTION = 'tejcart_setup_completed';

    /**
     * New flag signalling the multi-step wizard has been walked to the end.
     */
    public const WIZARD_COMPLETED_OPTION = 'tejcart_wizard_completed';

    /**
     * Array option listing step IDs the user skipped.
     */
    public const SKIPPED_STEPS_OPTION = 'tejcart_wizard_skipped_steps';

    /**
     * Last-saved step ID so the wizard can resume where the user left off.
     */
    public const CURRENT_STEP_OPTION = 'tejcart_wizard_current_step';

    /**
     * Ordered list of step IDs used by render() and the JS controller.
     */
    private const STEP_IDS = array( 'store', 'tax', 'shipping', 'emails_pages', 'ready' );

    /**
     * Admin page hook suffix captured once the submenu is registered so
     * enqueue_assets() can scope its assets to this screen only.
     */
    private string $page_hook = '';

    /**
     * @return void
     */
    public function init(): void {
        add_action( 'admin_menu', array( $this, 'register_page' ) );
        add_action( 'admin_head', array( $this, 'hide_submenu' ) );
        add_action( 'admin_init', array( $this, 'maybe_redirect_on_activation' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_tejcart_setup_wizard_save', array( $this, 'handle_ajax_save' ) );
    }

    /**
     * Add the wizard as a submenu so it has a canonical URL.
     *
     * The entry is stripped from the sidebar later on `admin_head` (see
     * hide_submenu()). Calling remove_submenu_page() during `admin_menu` would
     * run before WordPress's user_can_access_admin_page() capability check and
     * trigger "Sorry, you are not allowed to access this page." on direct-URL
     * access — including the post-activation redirect.
     *
     * @return void
     */
    public function register_page(): void {
        $this->page_hook = (string) add_submenu_page(
            'tejcart',
            __( 'Setup', 'tejcart' ),
            __( 'Setup', 'tejcart' ),
            'manage_options',
            'tejcart-setup',
            array( $this, 'render' )
        );
    }

    /**
     * Hide the wizard from the sidebar while preserving direct-URL access.
     *
     * @return void
     */
    public function hide_submenu(): void {
        remove_submenu_page( 'tejcart', 'tejcart-setup' );
    }

    /**
     * Redirect to the wizard on first activation.
     *
     * @return void
     */
    public function maybe_redirect_on_activation(): void {
        if ( 'yes' === get_option( self::COMPLETED_OPTION, 'no' ) ) {
            return;
        }

        if ( ! get_transient( 'tejcart_redirect_to_setup' ) ) {
            return;
        }

        delete_transient( 'tejcart_redirect_to_setup' );

        if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-setup' ) );
        exit;
    }

    /**
     * Enqueue wizard assets on the wizard screen only.
     *
     * @param string $hook Current admin hook suffix.
     * @return void
     */
    public function enqueue_assets( string $hook ): void {
        if ( '' === $this->page_hook || $hook !== $this->page_hook ) {
            return;
        }

        $version = defined( 'TEJCART_VERSION' ) ? TEJCART_VERSION : '1.0.0';

        wp_enqueue_style(
            'tejcart-setup-wizard',
            tejcart_asset_url( 'assets/css/tejcart-setup-wizard.css' ),
            array(),
            $version
        );

        wp_enqueue_script(
            'tejcart-setup-wizard',
            tejcart_asset_url( 'assets/js/tejcart-setup-wizard.js' ),
            array(),
            $version,
            true
        );

        $states_by_country = array();
        foreach ( array_keys( \TejCart\Tax\Tax_Manager::get_countries() ) as $cc ) {
            $country_states = \TejCart\Tax\Tax_Manager::get_states( $cc );
            if ( ! empty( $country_states ) ) {
                $states_by_country[ $cc ] = $country_states;
            }
        }

        $currency_decimals = array();
        if ( class_exists( '\\TejCart\\Money\\Currency' ) && class_exists( '\\TejCart\\Money\\Currencies' ) ) {
            foreach ( array_keys( \TejCart\Money\Currencies::get_currencies() ) as $code ) {
                $currency_decimals[ (string) $code ] = (int) \TejCart\Money\Currency::decimals( (string) $code );
            }
        }

        wp_localize_script(
            'tejcart-setup-wizard',
            'tejcartSetupWizard',
            array(
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'tejcart_setup_wizard' ),
                'action'           => 'tejcart_setup_wizard_save',
                'steps'            => self::STEP_IDS,
                'currentStep'      => $this->get_resume_step(),
                'skippedSteps'     => (array) get_option( self::SKIPPED_STEPS_OPTION, array() ),
                'dashboardUrl'     => admin_url( 'admin.php?page=tejcart' ),
                'states'           => $states_by_country,
                'currencyDecimals' => $currency_decimals,
                'i18n'             => array(
                    'saveError' => __( 'Could not save this step. Please try again.', 'tejcart' ),
                    'saving'    => __( 'Saving…', 'tejcart' ),
                ),
            )
        );
    }

    /**
     * Pick the step to open on load. Honours ?step= query if valid,
     * otherwise falls back to the last saved step, otherwise 'store'.
     *
     * Legacy `payments` saved as the resume step (from earlier wizard
     * versions) is rewritten to `shipping` so merchants don't land on a
     * step that no longer exists.
     */
    private function get_resume_step(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $requested = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
        if ( in_array( $requested, self::STEP_IDS, true ) ) {
            return $requested;
        }

        $saved = (string) get_option( self::CURRENT_STEP_OPTION, '' );
        if ( 'payments' === $saved ) {
            return 'shipping';
        }
        if ( in_array( $saved, self::STEP_IDS, true ) ) {
            return $saved;
        }

        return 'store';
    }

    /**
     * AJAX endpoint: save a single step and report the next step ID back.
     *
     * Expects POST: _ajax_nonce, step, skipped (0|1), fields[...].
     *
     * @return void
     */
    public function handle_ajax_save(): void {
        if ( ! \TejCart\Core\Capabilities::check( \TejCart\Core\Capabilities::MANAGE_STORE ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tejcart' ) ), 403 );
        }

        check_ajax_referer( 'tejcart_setup_wizard', 'nonce' );

        $step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
        if ( ! in_array( $step, self::STEP_IDS, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown step.', 'tejcart' ) ), 400 );
        }

        $skipped = ! empty( $_POST['skipped'] );
        // Audit #60 / 04 M-8 — defensive map_deep sanitisation at
        // the dispatcher so a future `save_step_*` method that
        // forgets to sanitise still gets a safe payload. Per-step
        // methods are still allowed to apply stricter typed
        // sanitisation (e.g. sanitize_email on email fields).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $fields  = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
        $fields  = map_deep( $fields, 'sanitize_text_field' );

        if ( $skipped ) {
            $this->mark_skipped( $step );
        } else {
            $this->remove_skipped( $step );
            $method = 'save_step_' . $step;
            if ( method_exists( $this, $method ) ) {
                $this->{$method}( $fields );
            }
        }

        $index     = array_search( $step, self::STEP_IDS, true );
        $next_step = ( false !== $index && $index < count( self::STEP_IDS ) - 1 )
            ? self::STEP_IDS[ $index + 1 ]
            : '';

        // Audit 08 #17 — wizard-state options are read only on
        // admin_init; no need to autoload them on every front-end pageload.
        update_option( self::CURRENT_STEP_OPTION, $next_step !== '' ? $next_step : $step, false );

        if ( 'ready' === $step ) {
            update_option( self::WIZARD_COMPLETED_OPTION, 'yes', false );
            update_option( self::COMPLETED_OPTION, 'yes', false );
        }

        wp_send_json_success( array(
            'step'         => $step,
            'nextStep'     => $next_step,
            'skippedSteps' => (array) get_option( self::SKIPPED_STEPS_OPTION, array() ),
            'completed'    => 'ready' === $step,
        ) );
    }

    /**
     * Append a step ID to the skipped list (unique, re-saved).
     */
    private function mark_skipped( string $step ): void {
        $list = (array) get_option( self::SKIPPED_STEPS_OPTION, array() );
        if ( ! in_array( $step, $list, true ) ) {
            $list[] = $step;
            update_option( self::SKIPPED_STEPS_OPTION, array_values( $list ), false );
        }
    }

    /**
     * Remove a step ID from the skipped list when the user comes back and
     * actually submits it.
     */
    private function remove_skipped( string $step ): void {
        $list    = (array) get_option( self::SKIPPED_STEPS_OPTION, array() );
        $updated = array_values( array_filter( $list, static fn( $s ) => $s !== $step ) );
        if ( $updated !== $list ) {
            update_option( self::SKIPPED_STEPS_OPTION, $updated, false );
        }
    }

    /**
     * Step 1: Store identity, address, currency, formatting, units, timezone.
     *
     * @param array<string,mixed> $fields Raw POSTed fields.
     */
    private function save_step_store( array $fields ): void {
        // Audit 08 #17 — split into autoload-yes (read on every cart /
        // tax calculation) and autoload-no (admin- or email-only) so
        // the wizard doesn't leak admin-only payload into alloptions.
        $autoloaded = array(
            'store_country'  => 'tejcart_store_country',
            'store_state'    => 'tejcart_store_state',
            'store_city'     => 'tejcart_store_city',
            'store_postcode' => 'tejcart_store_postcode',
            'store_address'  => 'tejcart_store_address',
            'timezone'       => 'tejcart_timezone_string',
        );
        $no_autoload = array(
            'store_name'      => 'tejcart_store_name',
            'store_address_2' => 'tejcart_store_address_2',
            'weight_unit'     => 'tejcart_weight_unit',
            'dimension_unit'  => 'tejcart_dimension_unit',
        );

        foreach ( $autoloaded as $field => $option ) {
            if ( ! array_key_exists( $field, $fields ) ) {
                continue;
            }
            update_option( $option, sanitize_text_field( (string) $fields[ $field ] ) );
        }
        foreach ( $no_autoload as $field => $option ) {
            if ( ! array_key_exists( $field, $fields ) ) {
                continue;
            }
            update_option( $option, sanitize_text_field( (string) $fields[ $field ] ), false );
        }

        if ( array_key_exists( 'store_email', $fields ) ) {
            $email = sanitize_email( (string) $fields['store_email'] );
            if ( '' !== $email ) {
                // Email-only option — admin and outgoing-mail surfaces only.
                update_option( 'tejcart_store_email', $email, false );
            }
        }

        $currency = isset( $fields['currency'] ) ? strtoupper( sanitize_text_field( (string) $fields['currency'] ) ) : '';
        if ( '' !== $currency && 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ) {
            update_option( 'tejcart_currency', $currency );
        }

        $positions = array( 'left', 'right', 'left_space', 'right_space' );
        if ( array_key_exists( 'currency_position', $fields ) ) {
            $pos = sanitize_key( (string) $fields['currency_position'] );
            if ( in_array( $pos, $positions, true ) ) {
                update_option( 'tejcart_currency_position', $pos );
            }
        }

        if ( array_key_exists( 'thousand_separator', $fields ) ) {
            $sep = (string) $fields['thousand_separator'];
            // Allow at most a single character (space, comma, period, apostrophe, etc.).
            $sep = mb_substr( $sep, 0, 1 );
            update_option( 'tejcart_thousand_separator', $sep );
        }

        if ( array_key_exists( 'decimal_separator', $fields ) ) {
            $sep = (string) $fields['decimal_separator'];
            $sep = mb_substr( $sep, 0, 1 );
            if ( '' === $sep ) {
                $sep = '.';
            }
            update_option( 'tejcart_decimal_separator', $sep );
        }

        if ( array_key_exists( 'num_decimals', $fields ) ) {
            $decimals = (int) $fields['num_decimals'];
            if ( $decimals < 0 ) {
                $decimals = 0;
            } elseif ( $decimals > 4 ) {
                $decimals = 4;
            }
            update_option( 'tejcart_num_decimals', $decimals );
        }
    }

    /**
     * Step 2: Enable taxes + basis + price entry + display toggles.
     *
     * @param array<string,mixed> $fields Raw POSTed fields.
     */
    private function save_step_tax( array $fields ): void {
        update_option(
            'tejcart_enable_tax',
            ! empty( $fields['enable_tax'] ) ? 'yes' : 'no'
        );

        $bases = array( 'shipping_address', 'billing_address', 'store_address' );
        $basis = isset( $fields['tax_based_on'] ) ? sanitize_key( (string) $fields['tax_based_on'] ) : 'billing_address';
        if ( ! in_array( $basis, $bases, true ) ) {
            $basis = 'billing_address';
        }
        update_option( 'tejcart_tax_based_on', $basis );

        update_option(
            'tejcart_prices_include_tax',
            ! empty( $fields['prices_include_tax'] ) ? 'yes' : 'no'
        );

        $display_opts = array( 'inclusive', 'exclusive' );
        foreach ( array( 'tax_display_shop', 'tax_display_cart' ) as $key ) {
            $value = isset( $fields[ $key ] ) ? sanitize_key( (string) $fields[ $key ] ) : 'exclusive';
            if ( ! in_array( $value, $display_opts, true ) ) {
                $value = 'exclusive';
            }
            update_option( 'tejcart_' . $key, $value );
        }

        $rounding = array( 'subtotal', 'line' );
        $round_at = isset( $fields['tax_round_at_subtotal'] ) ? sanitize_key( (string) $fields['tax_round_at_subtotal'] ) : 'subtotal';
        if ( ! in_array( $round_at, $rounding, true ) ) {
            $round_at = 'subtotal';
        }
        update_option( 'tejcart_tax_round_at_subtotal', 'subtotal' === $round_at ? 'yes' : 'no' );
    }

    /**
     * Step 3: Enable shipping + create a default zone with one method if none exists.
     *
     * @param array<string,mixed> $fields Raw POSTed fields.
     */
    private function save_step_shipping( array $fields ): void {
        $enabled = ! empty( $fields['enable_shipping'] );
        update_option( 'tejcart_enable_shipping', $enabled ? 'yes' : 'no' );

        if ( ! $enabled ) {
            return;
        }

        if ( ! class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
            return;
        }

        $manager = new \TejCart\Shipping\Shipping_Manager();
        if ( ! empty( $manager->get_zones() ) ) {
            return;
        }

        $country = (string) get_option( 'tejcart_store_country', 'US' );

        $method_id = isset( $fields['method'] ) ? sanitize_key( (string) $fields['method'] ) : 'flat_rate';
        $allowed   = array( 'flat_rate', 'free_shipping', 'local_pickup' );
        if ( ! in_array( $method_id, $allowed, true ) ) {
            $method_id = 'flat_rate';
        }

        // Cost only applies to flat_rate; free_shipping and local_pickup
        // ignore it. Always normalise to a non-negative float so the zone
        // record stays predictable on first save.
        $cost   = isset( $fields['cost'] ) ? max( 0.0, (float) $fields['cost'] ) : 0.0;
        $titles = array(
            'flat_rate'     => __( 'Flat rate', 'tejcart' ),
            'free_shipping' => __( 'Free shipping', 'tejcart' ),
            'local_pickup'  => __( 'Local pickup', 'tejcart' ),
        );

        $manager->add_zone( array(
            'name'      => sprintf(
                /* translators: %s: store country code */
                __( '%s — domestic', 'tejcart' ),
                $country
            ),
            'countries' => array( $country ),
            'methods'   => array(
                array(
                    'id'       => $method_id,
                    'title'    => $titles[ $method_id ],
                    'settings' => array( 'cost' => $cost ),
                ),
            ),
        ) );
    }

    /**
     * Step 4: Email sender / support / footer settings + create-or-assign core pages.
     *
     * @param array<string,mixed> $fields Raw POSTed fields.
     */
    private function save_step_emails_pages( array $fields ): void {
        // Audit 08 #17 — every value here is consumed by the outgoing
        // mail path / footer template only, never on a hot cart/order
        // render path. No reason to autoload.
        if ( array_key_exists( 'from_name', $fields ) ) {
            update_option( 'tejcart_from_name', sanitize_text_field( (string) $fields['from_name'] ), false );
        }
        if ( array_key_exists( 'from_email', $fields ) ) {
            $email = sanitize_email( (string) $fields['from_email'] );
            if ( '' !== $email ) {
                update_option( 'tejcart_from_email', $email, false );
            }
        }
        if ( array_key_exists( 'support_email', $fields ) ) {
            $email = sanitize_email( (string) $fields['support_email'] );
            // Empty is allowed — clears the option so the storefront falls
            // back to from_email / admin_email at render time.
            update_option( 'tejcart_support_email', $email, false );
        }
        if ( array_key_exists( 'footer_text', $fields ) ) {
            update_option( 'tejcart_footer_text', wp_kses_post( (string) $fields['footer_text'] ), false );
        }

        $this->install_pages_if_missing();
    }

    /**
     * Step 5: "Launch" step has no fields. Completion flags are flipped in
     * handle_ajax_save() once this method returns.
     *
     * @param array<string,mixed> $fields Unused.
     */
    private function save_step_ready( array $fields ): void {
        unset( $fields );
    }

    /**
     * Ensure the canonical storefront pages exist and have their option
     * pointers set. Pages that already exist and are published are left
     * alone; missing or trashed pages are recreated.
     *
     * Mirrors Installer::install_pages() so users who never finished the
     * wizard but reach Emails & Pages still land in a sane state. Includes
     * Terms & Conditions so the checkout consent link has a target.
     */
    private function install_pages_if_missing(): void {
        $pages = array(
            array(
                'title'   => __( 'Shop', 'tejcart' ),
                'slug'    => 'shop',
                'content' => '<!-- wp:shortcode -->[tejcart_products]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_shop_page_id',
            ),
            array(
                'title'   => __( 'Cart', 'tejcart' ),
                'slug'    => 'cart',
                'content' => '<!-- wp:shortcode -->[tejcart_cart]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_cart_page_id',
            ),
            array(
                'title'   => __( 'Checkout', 'tejcart' ),
                'slug'    => 'checkout',
                'content' => '<!-- wp:shortcode -->[tejcart_checkout]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_checkout_page_id',
            ),
            array(
                'title'   => __( 'Thank You', 'tejcart' ),
                'slug'    => 'thank-you',
                'content' => '<!-- wp:shortcode -->[tejcart_thankyou]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_thankyou_page_id',
            ),
            array(
                'title'   => __( 'My Account', 'tejcart' ),
                'slug'    => 'my-account',
                'content' => '<!-- wp:shortcode -->[tejcart_account]<!-- /wp:shortcode -->',
                'option'  => 'tejcart_myaccount_page_id',
            ),
            array(
                'title'   => __( 'Terms and Conditions', 'tejcart' ),
                'slug'    => 'terms-and-conditions',
                'content' => '<!-- wp:paragraph --><p>' . esc_html__( 'Please add your store\'s terms and conditions here. This page is linked to from checkout.', 'tejcart' ) . '</p><!-- /wp:paragraph -->',
                'option'  => 'tejcart_terms_page_id',
            ),
        );

        foreach ( $pages as $page ) {
            $existing_id = (int) get_option( $page['option'], 0 );
            if ( $existing_id > 0 ) {
                $existing_post = get_post( $existing_id );
                if ( $existing_post instanceof \WP_Post
                    && 'page' === $existing_post->post_type
                    && 'publish' === $existing_post->post_status ) {
                    continue;
                }
            }

            $by_slug = get_page_by_path( $page['slug'], OBJECT, 'page' );
            if ( $by_slug instanceof \WP_Post && 'publish' === $by_slug->post_status ) {
                update_option( $page['option'], (int) $by_slug->ID );
                continue;
            }

            $new_id = wp_insert_post( array(
                'post_title'     => $page['title'],
                'post_content'   => $page['content'],
                'post_status'    => 'publish',
                'post_type'      => 'page',
                'post_name'      => $page['slug'],
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ), true );

            if ( ! is_wp_error( $new_id ) && $new_id > 0 ) {
                update_option( $page['option'], (int) $new_id );
            }
        }
    }

    /**
     * Per-step display metadata used by the stepper and card headers.
     *
     * @return array<string,array{title:string,description:string,icon:string}>
     */
    private function step_meta(): array {
        return array(
            'store'        => array(
                'title'       => __( 'Store', 'tejcart' ),
                'description' => __( 'Tell us about your business, where you ship from, and how prices should look.', 'tejcart' ),
                'icon'        => 'store',
            ),
            'tax'          => array(
                'title'       => __( 'Tax', 'tejcart' ),
                'description' => __( 'Decide whether to charge tax, how to calculate it, and how to display it.', 'tejcart' ),
                'icon'        => 'tax',
            ),
            'shipping'     => array(
                'title'       => __( 'Shipping', 'tejcart' ),
                'description' => __( 'Turn shipping on and set a default zone with at least one method.', 'tejcart' ),
                'icon'        => 'shipping',
            ),
            'emails_pages' => array(
                'title'       => __( 'Emails & Pages', 'tejcart' ),
                'description' => __( 'Personalise outgoing emails and create the storefront pages your store needs.', 'tejcart' ),
                'icon'        => 'mail',
            ),
            'ready'        => array(
                'title'       => __( 'Launch', 'tejcart' ),
                'description' => __( 'Review your setup, connect a payment method, and start selling.', 'tejcart' ),
                'icon'        => 'check',
            ),
        );
    }

    /**
     * Emit an inline SVG icon by name. Single-colour strokes so they inherit
     * the stepper/card text colour via currentColor.
     *
     * @param string $name Icon identifier.
     */
    private function render_icon( string $name ): void {
        $paths = array(
            'store'    => '<path d="M3 9l2-5h14l2 5"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
            'tax'      => '<path d="M9 14l6-6"/><circle cx="9.5" cy="8.5" r="1.5"/><circle cx="14.5" cy="13.5" r="1.5"/><rect x="4" y="4" width="16" height="16" rx="2"/>',
            'shipping' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
            'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
            'check'    => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9"/>',
            'paypal'   => '<path d="M7 6h7a4 4 0 010 8h-4l-1 6H6L7 6z"/><path d="M10 10h4a3 3 0 010 6h-2l-1 4"/>',
            'rocket'   => '<path d="M5 19c0-3 2-7 7-12 3 0 5 2 5 5-5 5-9 7-12 7z"/><path d="M9 14l1 1"/><path d="M14 7l3 3"/>',
            'rates'    => '<path d="M4 20V8"/><path d="M10 20V4"/><path d="M16 20v-8"/><path d="M22 20H2"/>',
            'plug'     => '<path d="M9 7v4"/><path d="M15 7v4"/><rect x="7" y="11" width="10" height="6" rx="1"/><path d="M12 17v4"/>',
            'box'      => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/><path d="M12 11v10"/>',
        );

        $body = $paths[ $name ] ?? '';
        echo tejcart_kses_svg( '<svg class="tejcart-wizard-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>' );
    }

    /**
     * Render the wizard shell: header, horizontal stepper, and every step card
     * as a hidden section. JS handles showing one at a time, posting to
     * admin-ajax, and advancing through the flow.
     */
    public function render(): void {
        $meta    = $this->step_meta();
        $skipped = (array) get_option( self::SKIPPED_STEPS_OPTION, array() );
        $active  = $this->get_resume_step();
        ?>
        <div class="tejcart-wizard" data-active-step="<?php echo esc_attr( $active ); ?>">
            <header class="tejcart-wizard__header">
                <h1 class="tejcart-wizard__title"><?php esc_html_e( 'TejCart setup', 'tejcart' ); ?></h1>
                <p class="tejcart-wizard__subtitle"><?php esc_html_e( 'A few quick steps to get your store ready to sell. You can come back to any step from the dashboard.', 'tejcart' ); ?></p>
            </header>

            <ol class="tejcart-wizard__stepper" role="list">
                <?php $n = 0; foreach ( self::STEP_IDS as $step_id ) : $n++; ?>
                    <li class="tejcart-wizard__step" data-step="<?php echo esc_attr( $step_id ); ?>">
                        <span class="tejcart-wizard__step-marker" aria-hidden="true">
                            <span class="tejcart-wizard__step-number"><?php echo esc_html( (string) $n ); ?></span>
                            <svg class="tejcart-wizard__step-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                        </span>
                        <span class="tejcart-wizard__step-label"><?php echo esc_html( $meta[ $step_id ]['title'] ); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php foreach ( self::STEP_IDS as $step_id ) :
                $is_last     = 'ready' === $step_id;
                $method      = 'render_step_' . $step_id;
                $was_skipped = in_array( $step_id, $skipped, true );
                ?>
                <section class="tejcart-wizard__card" data-step="<?php echo esc_attr( $step_id ); ?>" hidden>
                    <header class="tejcart-wizard__card-header">
                        <span class="tejcart-wizard__card-icon" aria-hidden="true"><?php $this->render_icon( $meta[ $step_id ]['icon'] ); ?></span>
                        <div class="tejcart-wizard__card-heading">
                            <h2 class="tejcart-wizard__card-title"><?php echo esc_html( $meta[ $step_id ]['title'] ); ?></h2>
                            <p class="tejcart-wizard__card-desc"><?php echo esc_html( $meta[ $step_id ]['description'] ); ?></p>
                        </div>
                        <?php if ( $was_skipped ) : ?>
                            <span class="tejcart-wizard__card-pill" title="<?php esc_attr_e( 'You skipped this step — you can come back to it any time.', 'tejcart' ); ?>"><?php esc_html_e( 'Skipped', 'tejcart' ); ?></span>
                        <?php endif; ?>
                    </header>

                    <form class="tejcart-wizard__form" data-step="<?php echo esc_attr( $step_id ); ?>">
                        <div class="tejcart-wizard__fields">
                            <?php
                            if ( method_exists( $this, $method ) ) {
                                $this->{$method}();
                            }
                            ?>
                        </div>

                        <footer class="tejcart-wizard__actions">
                            <?php if ( 'store' !== $step_id ) : ?>
                                <button type="button" class="button tejcart-wizard__back"><?php esc_html_e( 'Back', 'tejcart' ); ?></button>
                            <?php endif; ?>
                            <div class="tejcart-wizard__actions-spacer"></div>
                            <?php if ( ! $is_last ) : ?>
                                <button type="button" class="button-link tejcart-wizard__skip"><?php esc_html_e( 'Skip for now', 'tejcart' ); ?></button>
                                <button type="submit" class="button button-primary tejcart-wizard__next"><?php esc_html_e( 'Save & continue', 'tejcart' ); ?></button>
                            <?php else : ?>
                                <button type="submit" class="button button-primary tejcart-wizard__finish"><?php esc_html_e( 'Visit dashboard', 'tejcart' ); ?></button>
                            <?php endif; ?>
                        </footer>
                    </form>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Step 1 body: store identity + contact + address + currency + formatting +
     * units + timezone. Grouped into "Business", "Address", "Localization" so
     * the field count never feels overwhelming.
     */
    private function render_step_store(): void {
        $name        = (string) get_option( 'tejcart_store_name', get_bloginfo( 'name' ) );
        $email       = (string) get_option( 'tejcart_store_email', get_bloginfo( 'admin_email' ) );
        $country     = (string) get_option( 'tejcart_store_country', 'US' );
        $state       = (string) get_option( 'tejcart_store_state', '' );
        $city        = (string) get_option( 'tejcart_store_city', '' );
        $postcode    = (string) get_option( 'tejcart_store_postcode', '' );
        $address     = (string) get_option( 'tejcart_store_address', '' );
        $address_2   = (string) get_option( 'tejcart_store_address_2', '' );
        $currency    = (string) get_option( 'tejcart_currency', 'USD' );
        $position    = (string) get_option( 'tejcart_currency_position', 'left' );
        $thou_sep    = (string) get_option( 'tejcart_thousand_separator', ',' );
        $dec_sep     = (string) get_option( 'tejcart_decimal_separator', '.' );
        $num_dec     = (int) get_option( 'tejcart_num_decimals', 2 );
        $weight      = (string) get_option( 'tejcart_weight_unit', 'kg' );
        $dimension   = (string) get_option( 'tejcart_dimension_unit', 'cm' );
        $timezone    = (string) get_option( 'tejcart_timezone_string', (string) get_option( 'timezone_string', '' ) );

        $countries = \TejCart\Tax\Tax_Manager::get_countries();
        asort( $countries );

        $currencies = $this->get_currency_options();

        $positions = array(
            'left'        => __( 'Left ($99.99)', 'tejcart' ),
            'right'       => __( 'Right (99.99$)', 'tejcart' ),
            'left_space'  => __( 'Left with space ($ 99.99)', 'tejcart' ),
            'right_space' => __( 'Right with space (99.99 $)', 'tejcart' ),
        );
        ?>

        <h3 class="tejcart-wizard__section-title"><?php esc_html_e( 'Business', 'tejcart' ); ?></h3>
        <div class="tejcart-wizard__grid">
            <label class="tejcart-wizard__field tejcart-wizard__field--full">
                <span><?php esc_html_e( 'Business name', 'tejcart' ); ?></span>
                <input type="text" name="store_name" value="<?php echo esc_attr( $name ); ?>" required />
                <small class="tejcart-wizard__help"><?php esc_html_e( 'Shown in invoices, emails, and the storefront header.', 'tejcart' ); ?></small>
            </label>
            <label class="tejcart-wizard__field tejcart-wizard__field--full">
                <span><?php esc_html_e( 'Store contact email', 'tejcart' ); ?></span>
                <input type="email" name="store_email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email" />
                <small class="tejcart-wizard__help"><?php esc_html_e( 'Used for order notifications and shown to customers in the "Contact us" footer.', 'tejcart' ); ?></small>
            </label>
        </div>

        <h3 class="tejcart-wizard__section-title"><?php esc_html_e( 'Address', 'tejcart' ); ?></h3>
        <div class="tejcart-wizard__grid">
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Country', 'tejcart' ); ?></span>
                <select name="store_country" class="tejcart-wizard__country" autocomplete="country">
                    <?php foreach ( $countries as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $country, $code ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'State / Region', 'tejcart' ); ?></span>
                <?php $country_states = \TejCart\Tax\Tax_Manager::get_states( $country ); ?>
                <?php if ( ! empty( $country_states ) ) : ?>
                    <select name="store_state" class="tejcart-wizard__state">
                        <?php foreach ( $country_states as $s_code => $s_label ) : ?>
                            <option value="<?php echo esc_attr( $s_code ); ?>" <?php selected( $state, $s_code ); ?>><?php echo esc_html( $s_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="text" name="store_state" class="tejcart-wizard__state" value="<?php echo esc_attr( $state ); ?>" />
                <?php endif; ?>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'City', 'tejcart' ); ?></span>
                <input type="text" name="store_city" value="<?php echo esc_attr( $city ); ?>" autocomplete="address-level2" />
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Postcode / ZIP', 'tejcart' ); ?></span>
                <input type="text" name="store_postcode" value="<?php echo esc_attr( $postcode ); ?>" autocomplete="postal-code" />
            </label>
        </div>

        <label class="tejcart-wizard__field tejcart-wizard__field--full">
            <span><?php esc_html_e( 'Street address', 'tejcart' ); ?></span>
            <input type="text" name="store_address" value="<?php echo esc_attr( $address ); ?>" autocomplete="address-line1" />
        </label>
        <label class="tejcart-wizard__field tejcart-wizard__field--full">
            <span><?php esc_html_e( 'Apartment, suite, etc. (optional)', 'tejcart' ); ?></span>
            <input type="text" name="store_address_2" value="<?php echo esc_attr( $address_2 ); ?>" autocomplete="address-line2" />
        </label>

        <h3 class="tejcart-wizard__section-title"><?php esc_html_e( 'Localization', 'tejcart' ); ?></h3>
        <div class="tejcart-wizard__grid">
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Currency', 'tejcart' ); ?></span>
                <select name="currency" class="tejcart-wizard__currency">
                    <?php foreach ( $currencies as $code => $label ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $currency, $code ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="tejcart-wizard__help"><?php esc_html_e( 'The currency every product price, order, and invoice is stored in.', 'tejcart' ); ?></small>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Currency position', 'tejcart' ); ?></span>
                <select name="currency_position">
                    <?php foreach ( $positions as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $position, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Thousand separator', 'tejcart' ); ?></span>
                <input type="text" name="thousand_separator" value="<?php echo esc_attr( $thou_sep ); ?>" maxlength="1" />
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Decimal separator', 'tejcart' ); ?></span>
                <input type="text" name="decimal_separator" value="<?php echo esc_attr( $dec_sep ); ?>" maxlength="1" />
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Number of decimals', 'tejcart' ); ?></span>
                <input type="number" name="num_decimals" value="<?php echo esc_attr( (string) $num_dec ); ?>" min="0" max="4" step="1" class="tejcart-wizard__decimals" />
                <small class="tejcart-wizard__help"><?php esc_html_e( 'Auto-adjusts to your currency (e.g. 0 for JPY, 3 for KWD).', 'tejcart' ); ?></small>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Weight unit', 'tejcart' ); ?></span>
                <select name="weight_unit">
                    <?php foreach ( array( 'kg', 'g', 'lbs', 'oz' ) as $u ) : ?>
                        <option value="<?php echo esc_attr( $u ); ?>" <?php selected( $weight, $u ); ?>><?php echo esc_html( $u ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Dimension unit', 'tejcart' ); ?></span>
                <select name="dimension_unit">
                    <?php foreach ( array( 'cm', 'm', 'mm', 'in', 'yd' ) as $u ) : ?>
                        <option value="<?php echo esc_attr( $u ); ?>" <?php selected( $dimension, $u ); ?>><?php echo esc_html( $u ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Timezone', 'tejcart' ); ?></span>
                <select name="timezone">
                    <?php
                    // wp_timezone_choice() returns the standard WordPress
                    // <optgroup>-grouped timezone picker (UTC offsets +
                    // region/city names), so the wizard exposes the same
                    // list the core Settings → General timezone field uses.
                    echo wp_timezone_choice( '' !== $timezone ? $timezone : 'UTC', null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_timezone_choice() returns pre-escaped <option> markup
                    ?>
                </select>
            </label>
        </div>
        <?php
    }

    /**
     * Build the "code => 'Name (symbol)'" pairs for the currency dropdown.
     * Falls back to a short hard-coded list when the {@see \TejCart\Money\Currencies}
     * catalogue isn't available (e.g. in narrow unit-test fixtures).
     *
     * @return array<string,string>
     */
    private function get_currency_options(): array {
        if ( class_exists( '\\TejCart\\Money\\Currencies' ) ) {
            $dataset = \TejCart\Money\Currencies::get_currencies();
            if ( is_array( $dataset ) && ! empty( $dataset ) ) {
                $options = array();
                foreach ( $dataset as $code => $row ) {
                    $name   = isset( $row['name'] ) ? (string) $row['name'] : (string) $code;
                    $symbol = isset( $row['symbol'] ) ? (string) $row['symbol'] : '';
                    $options[ (string) $code ] = '' !== $symbol
                        ? sprintf( '%s (%s)', $name, $symbol )
                        : $name;
                }
                natcasesort( $options );
                return $options;
            }
        }

        $fallback = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'INR' );
        return array_combine( $fallback, $fallback );
    }

    /**
     * Step 2 body: enable taxes, tax basis, price entry, display options.
     */
    private function render_step_tax(): void {
        $enabled      = 'yes' === get_option( 'tejcart_enable_tax', 'no' );
        $basis        = (string) get_option( 'tejcart_tax_based_on', 'billing_address' );
        $incl         = 'yes' === get_option( 'tejcart_prices_include_tax', 'no' );
        $display_shop = (string) get_option( 'tejcart_tax_display_shop', 'exclusive' );
        $display_cart = (string) get_option( 'tejcart_tax_display_cart', 'exclusive' );
        // Audit #6 / 03 #2 — fallback aligned with UI checkbox default
        // and Installer seed ('no').
        $round_at_sub = 'yes' === get_option( 'tejcart_tax_round_at_subtotal', 'no' );

        $bases = array(
            'shipping_address' => __( 'Customer shipping address', 'tejcart' ),
            'billing_address'  => __( 'Customer billing address', 'tejcart' ),
            'store_address'    => __( 'Store base address', 'tejcart' ),
        );
        $display_opts = array(
            'exclusive' => __( 'Excluding tax', 'tejcart' ),
            'inclusive' => __( 'Including tax', 'tejcart' ),
        );
        ?>
        <label class="tejcart-wizard__toggle tejcart-wizard__toggle--switch tejcart-wizard__lead-toggle" data-controls="tax-settings">
            <input type="checkbox" name="enable_tax" value="1" <?php checked( $enabled ); ?> />
            <span class="tejcart-wizard__switch" aria-hidden="true"></span>
            <span class="tejcart-wizard__toggle-text">
                <span class="tejcart-wizard__toggle-title"><?php esc_html_e( 'Enable taxes', 'tejcart' ); ?></span>
                <span class="tejcart-wizard__toggle-desc"><?php esc_html_e( 'Calculate and display tax during checkout. You can still configure rates later if you turn this on.', 'tejcart' ); ?></span>
            </span>
        </label>

        <div class="tejcart-wizard__conditional" data-conditional="tax-settings" <?php echo $enabled ? '' : 'hidden'; ?>>
            <label class="tejcart-wizard__field tejcart-wizard__field--full">
                <span><?php esc_html_e( 'Calculate tax based on', 'tejcart' ); ?></span>
                <select name="tax_based_on">
                    <?php foreach ( $bases as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $basis, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="tejcart-wizard__toggle">
                <input type="checkbox" name="prices_include_tax" value="1" <?php checked( $incl ); ?> />
                <span><?php esc_html_e( 'Prices I enter already include tax', 'tejcart' ); ?></span>
            </label>

            <div class="tejcart-wizard__grid">
                <label class="tejcart-wizard__field">
                    <span><?php esc_html_e( 'Display prices in shop', 'tejcart' ); ?></span>
                    <select name="tax_display_shop">
                        <?php foreach ( $display_opts as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $display_shop, $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tejcart-wizard__field">
                    <span><?php esc_html_e( 'Display prices in cart & checkout', 'tejcart' ); ?></span>
                    <select name="tax_display_cart">
                        <?php foreach ( $display_opts as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $display_cart, $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="tejcart-wizard__field tejcart-wizard__field--full">
                    <span><?php esc_html_e( 'Tax rounding', 'tejcart' ); ?></span>
                    <select name="tax_round_at_subtotal">
                        <option value="subtotal" <?php selected( $round_at_sub, true ); ?>><?php esc_html_e( 'Round at subtotal (recommended)', 'tejcart' ); ?></option>
                        <option value="line" <?php selected( $round_at_sub, false ); ?>><?php esc_html_e( 'Round per line item', 'tejcart' ); ?></option>
                    </select>
                    <small class="tejcart-wizard__help"><?php esc_html_e( 'Subtotal rounding minimises rounding error on multi-item carts; line rounding matches some legacy ERPs.', 'tejcart' ); ?></small>
                </label>
            </div>

            <p class="tejcart-wizard__hint">
                <?php
                printf(
                    /* translators: %s: opening link tag */
                    esc_html__( 'You can fine-tune rates per zone after the wizard from %1$sSettings → Tax → Rates%2$s.', 'tejcart' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates' ) ) . '" target="_blank" rel="noopener">',
                    '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Step 3 body: enable shipping toggle, method picker, conditional cost,
     * deep-link to the full Shipping Zones editor.
     */
    private function render_step_shipping(): void {
        $enabled   = 'yes' === get_option( 'tejcart_enable_shipping', 'no' );
        $country   = (string) get_option( 'tejcart_store_country', 'US' );
        $has_zones = false;
        if ( class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
            $manager   = new \TejCart\Shipping\Shipping_Manager();
            $has_zones = ! empty( $manager->get_zones() );
        }
        ?>
        <label class="tejcart-wizard__toggle tejcart-wizard__toggle--switch tejcart-wizard__lead-toggle" data-controls="shipping-settings">
            <input type="checkbox" name="enable_shipping" value="1" <?php checked( $enabled ); ?> />
            <span class="tejcart-wizard__switch" aria-hidden="true"></span>
            <span class="tejcart-wizard__toggle-text">
                <span class="tejcart-wizard__toggle-title"><?php esc_html_e( 'Enable shipping', 'tejcart' ); ?></span>
                <span class="tejcart-wizard__toggle-desc"><?php esc_html_e( 'Show shipping options at checkout. Leave off if you only sell digital, virtual, or pickup-only products.', 'tejcart' ); ?></span>
            </span>
        </label>

        <div class="tejcart-wizard__conditional" data-conditional="shipping-settings" <?php echo $enabled ? '' : 'hidden'; ?>>
            <?php if ( $has_zones ) : ?>
                <p class="tejcart-wizard__hint"><?php esc_html_e( 'You already have shipping zones configured. Saving this step will not overwrite them.', 'tejcart' ); ?></p>
            <?php else : ?>
                <p class="tejcart-wizard__hint">
                    <?php
                    printf(
                        /* translators: %s: ISO country code */
                        esc_html__( 'A default zone covering %s will be created with the method you pick below.', 'tejcart' ),
                        esc_html( $country )
                    );
                    ?>
                </p>
            <?php endif; ?>

            <div class="tejcart-wizard__grid">
                <label class="tejcart-wizard__field">
                    <span><?php esc_html_e( 'Shipping method', 'tejcart' ); ?></span>
                    <select name="method" class="tejcart-wizard__shipping-method">
                        <option value="flat_rate"><?php esc_html_e( 'Flat rate', 'tejcart' ); ?></option>
                        <option value="free_shipping"><?php esc_html_e( 'Free shipping', 'tejcart' ); ?></option>
                        <option value="local_pickup"><?php esc_html_e( 'Local pickup', 'tejcart' ); ?></option>
                    </select>
                </label>
                <label class="tejcart-wizard__field tejcart-wizard__shipping-cost" data-cost-field="1">
                    <span><?php esc_html_e( 'Cost per order', 'tejcart' ); ?></span>
                    <input type="number" name="cost" value="5.00" step="0.01" min="0" />
                    <small class="tejcart-wizard__help"><?php esc_html_e( 'Charged on top of the order. Per-class rates and weight-based methods can be configured later.', 'tejcart' ); ?></small>
                </label>
            </div>

            <p class="tejcart-wizard__hint">
                <?php
                printf(
                    /* translators: %s: opening link tag */
                    esc_html__( 'For multi-zone, weight-based, or carrier rates, configure %1$sShipping Zones%2$s after the wizard.', 'tejcart' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=tejcart-settings&tab=shipping' ) ) . '" target="_blank" rel="noopener">',
                    '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Step 4 body: email sender fields + support email + page creation summary.
     */
    private function render_step_emails_pages(): void {
        $from_name     = (string) get_option( 'tejcart_from_name', get_bloginfo( 'name' ) );
        $from_email    = (string) get_option( 'tejcart_from_email', get_bloginfo( 'admin_email' ) );
        $support_email = (string) get_option( 'tejcart_support_email', '' );
        $footer_text   = (string) get_option( 'tejcart_footer_text', get_bloginfo( 'name' ) );

        $pages = array(
            'shop'       => array( 'option' => 'tejcart_shop_page_id',      'label' => __( 'Shop', 'tejcart' ) ),
            'cart'       => array( 'option' => 'tejcart_cart_page_id',      'label' => __( 'Cart', 'tejcart' ) ),
            'checkout'   => array( 'option' => 'tejcart_checkout_page_id',  'label' => __( 'Checkout', 'tejcart' ) ),
            'thankyou'   => array( 'option' => 'tejcart_thankyou_page_id',  'label' => __( 'Thank you', 'tejcart' ) ),
            'my_account' => array( 'option' => 'tejcart_myaccount_page_id', 'label' => __( 'My Account', 'tejcart' ) ),
            'terms'      => array( 'option' => 'tejcart_terms_page_id',     'label' => __( 'Terms & Conditions', 'tejcart' ) ),
        );

        $privacy_page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
        ?>
        <h3 class="tejcart-wizard__section-title"><?php esc_html_e( 'Email sender', 'tejcart' ); ?></h3>
        <div class="tejcart-wizard__grid">
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Sender name', 'tejcart' ); ?></span>
                <input type="text" name="from_name" value="<?php echo esc_attr( $from_name ); ?>" />
                <small class="tejcart-wizard__help"><?php esc_html_e( 'Appears as the "From" name on every order email.', 'tejcart' ); ?></small>
            </label>
            <label class="tejcart-wizard__field">
                <span><?php esc_html_e( 'Sender email', 'tejcart' ); ?></span>
                <input type="email" name="from_email" value="<?php echo esc_attr( $from_email ); ?>" autocomplete="email" />
                <small class="tejcart-wizard__help"><?php esc_html_e( 'Use a mailbox on your domain for the best deliverability.', 'tejcart' ); ?></small>
            </label>
            <label class="tejcart-wizard__field tejcart-wizard__field--full">
                <span><?php esc_html_e( 'Customer support email (optional)', 'tejcart' ); ?></span>
                <input type="email" name="support_email" value="<?php echo esc_attr( $support_email ); ?>" autocomplete="email" />
                <small class="tejcart-wizard__help"><?php esc_html_e( 'Shown in order emails and the My Account page when customers need help. Defaults to the sender email when left blank.', 'tejcart' ); ?></small>
            </label>
        </div>

        <label class="tejcart-wizard__field tejcart-wizard__field--full">
            <span><?php esc_html_e( 'Email footer text', 'tejcart' ); ?></span>
            <textarea name="footer_text" rows="2"><?php echo esc_textarea( $footer_text ); ?></textarea>
            <small class="tejcart-wizard__help"><?php esc_html_e( 'Add a tagline, support address, or unsubscribe message. Basic HTML is allowed.', 'tejcart' ); ?></small>
        </label>

        <div class="tejcart-wizard__pages">
            <h3 class="tejcart-wizard__section-title"><?php esc_html_e( 'Storefront pages', 'tejcart' ); ?></h3>
            <p class="tejcart-wizard__hint"><?php esc_html_e( 'Existing pages are detected and reused. Missing ones are created and assigned automatically.', 'tejcart' ); ?></p>
            <ul class="tejcart-wizard__page-list">
                <?php foreach ( $pages as $slug => $info ) :
                    $page_id = (int) get_option( $info['option'], 0 );
                    $exists  = $page_id > 0 && get_post_status( $page_id ) === 'publish';
                    ?>
                    <li class="tejcart-wizard__page-item">
                        <span class="tejcart-wizard__page-name"><?php echo esc_html( $info['label'] ); ?></span>
                        <?php if ( $exists ) : ?>
                            <span class="tejcart-wizard__page-state is-ok"><?php esc_html_e( 'Detected', 'tejcart' ); ?></span>
                        <?php else : ?>
                            <span class="tejcart-wizard__page-state is-new"><?php esc_html_e( 'Will be created', 'tejcart' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <li class="tejcart-wizard__page-item">
                    <span class="tejcart-wizard__page-name"><?php esc_html_e( 'Privacy Policy', 'tejcart' ); ?></span>
                    <?php if ( $privacy_page_id > 0 ) : ?>
                        <span class="tejcart-wizard__page-state is-ok"><?php esc_html_e( 'Detected', 'tejcart' ); ?></span>
                    <?php else : ?>
                        <span class="tejcart-wizard__page-state is-warn" title="<?php esc_attr_e( 'Set via Settings → Privacy in WordPress.', 'tejcart' ); ?>"><?php esc_html_e( 'Set in WP Privacy', 'tejcart' ); ?></span>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
        <?php
    }

    /**
     * Step 5 body: summary of saved values + prominent next-step CTAs, with
     * PayPal Connect leading because it's the gateway most stores will use.
     */
    private function render_step_ready(): void {
        $name      = (string) get_option( 'tejcart_store_name', get_bloginfo( 'name' ) );
        $country   = (string) get_option( 'tejcart_store_country', 'US' );
        $currency  = (string) get_option( 'tejcart_currency', 'USD' );
        $tax_on    = 'yes' === get_option( 'tejcart_enable_tax', 'no' );
        $ship_on   = 'yes' === get_option( 'tejcart_enable_shipping', 'no' );
        $skipped   = (array) get_option( self::SKIPPED_STEPS_OPTION, array() );

        $paypal_url    = admin_url( 'admin.php?page=tejcart-settings&tab=payments&section=paypal' );
        $payments_url  = admin_url( 'admin.php?page=tejcart-settings&tab=payments' );
        $tax_url       = admin_url( 'admin.php?page=tejcart-settings&tab=tax&section=rates' );
        $modules_url   = admin_url( 'admin.php?page=tejcart-modules' );
        $products_url  = admin_url( 'admin.php?page=tejcart-products' );
        ?>
        <div class="tejcart-wizard__summary">
            <h3 class="tejcart-wizard__section-title"><?php esc_html_e( 'Here\'s what we saved', 'tejcart' ); ?></h3>
            <dl class="tejcart-wizard__summary-list">
                <div class="tejcart-wizard__summary-row">
                    <dt><?php esc_html_e( 'Business', 'tejcart' ); ?></dt>
                    <dd><?php echo esc_html( '' !== $name ? $name : __( '—', 'tejcart' ) ); ?></dd>
                </div>
                <div class="tejcart-wizard__summary-row">
                    <dt><?php esc_html_e( 'Country', 'tejcart' ); ?></dt>
                    <dd><?php echo esc_html( $country ); ?></dd>
                </div>
                <div class="tejcart-wizard__summary-row">
                    <dt><?php esc_html_e( 'Currency', 'tejcart' ); ?></dt>
                    <dd><?php echo esc_html( $currency ); ?></dd>
                </div>
                <div class="tejcart-wizard__summary-row">
                    <dt><?php esc_html_e( 'Taxes', 'tejcart' ); ?></dt>
                    <dd><?php echo $tax_on ? esc_html__( 'Enabled', 'tejcart' ) : esc_html__( 'Disabled', 'tejcart' ); ?></dd>
                </div>
                <div class="tejcart-wizard__summary-row">
                    <dt><?php esc_html_e( 'Shipping', 'tejcart' ); ?></dt>
                    <dd><?php echo $ship_on ? esc_html__( 'Enabled', 'tejcart' ) : esc_html__( 'Disabled', 'tejcart' ); ?></dd>
                </div>
            </dl>

            <?php if ( ! empty( $skipped ) ) : ?>
                <p class="tejcart-wizard__hint"><?php
                    printf(
                        /* translators: %s: comma-separated list of skipped step labels */
                        esc_html__( 'You skipped: %s. You can finish them later from the dashboard.', 'tejcart' ),
                        esc_html( implode( ', ', array_map( fn( $s ) => $this->step_meta()[ $s ]['title'] ?? $s, $skipped ) ) )
                    );
                ?></p>
            <?php endif; ?>
        </div>

        <h3 class="tejcart-wizard__section-title tejcart-wizard__section-title--cta"><?php esc_html_e( 'Next steps', 'tejcart' ); ?></h3>
        <p class="tejcart-wizard__cta-intro"><?php esc_html_e( 'Connect a payment method to start accepting orders. PayPal is one click — no API keys to copy.', 'tejcart' ); ?></p>

        <div class="tejcart-wizard__cta-grid">
            <a class="tejcart-wizard__cta tejcart-wizard__cta--primary" href="<?php echo esc_url( $paypal_url ); ?>">
                <span class="tejcart-wizard__cta-icon" aria-hidden="true"><?php $this->render_icon( 'paypal' ); ?></span>
                <span class="tejcart-wizard__cta-body">
                    <span class="tejcart-wizard__cta-title"><?php esc_html_e( 'Connect with PayPal', 'tejcart' ); ?></span>
                    <span class="tejcart-wizard__cta-desc"><?php esc_html_e( 'Accept PayPal, cards, Apple Pay, Google Pay, and Pay Later through a single onboarding link.', 'tejcart' ); ?></span>
                </span>
            </a>
            <a class="tejcart-wizard__cta" href="<?php echo esc_url( $payments_url ); ?>">
                <span class="tejcart-wizard__cta-icon" aria-hidden="true"><?php $this->render_icon( 'plug' ); ?></span>
                <span class="tejcart-wizard__cta-body">
                    <span class="tejcart-wizard__cta-title"><?php esc_html_e( 'Offline payment methods', 'tejcart' ); ?></span>
                    <span class="tejcart-wizard__cta-desc"><?php esc_html_e( 'Enable cash on delivery, bank transfer, or check payments for in-person and B2B orders.', 'tejcart' ); ?></span>
                </span>
            </a>
            <a class="tejcart-wizard__cta" href="<?php echo esc_url( $tax_url ); ?>">
                <span class="tejcart-wizard__cta-icon" aria-hidden="true"><?php $this->render_icon( 'rates' ); ?></span>
                <span class="tejcart-wizard__cta-body">
                    <span class="tejcart-wizard__cta-title"><?php esc_html_e( 'Configure tax rates', 'tejcart' ); ?></span>
                    <span class="tejcart-wizard__cta-desc"><?php esc_html_e( 'Add the regional rates that apply to your zones, or enable a live tax provider from Modules.', 'tejcart' ); ?></span>
                </span>
            </a>
            <a class="tejcart-wizard__cta" href="<?php echo esc_url( $products_url ); ?>">
                <span class="tejcart-wizard__cta-icon" aria-hidden="true"><?php $this->render_icon( 'box' ); ?></span>
                <span class="tejcart-wizard__cta-body">
                    <span class="tejcart-wizard__cta-title"><?php esc_html_e( 'Add your first product', 'tejcart' ); ?></span>
                    <span class="tejcart-wizard__cta-desc"><?php esc_html_e( 'Create a physical, digital, virtual, variable, or bundle product to start selling.', 'tejcart' ); ?></span>
                </span>
            </a>
            <a class="tejcart-wizard__cta" href="<?php echo esc_url( $modules_url ); ?>">
                <span class="tejcart-wizard__cta-icon" aria-hidden="true"><?php $this->render_icon( 'rocket' ); ?></span>
                <span class="tejcart-wizard__cta-body">
                    <span class="tejcart-wizard__cta-title"><?php esc_html_e( 'Explore modules', 'tejcart' ); ?></span>
                    <span class="tejcart-wizard__cta-desc"><?php esc_html_e( 'Turn on analytics, returns, live carrier rates, gift cards, or multi-currency without installing extra plugins.', 'tejcart' ); ?></span>
                </span>
            </a>
        </div>
        <?php
    }
}
