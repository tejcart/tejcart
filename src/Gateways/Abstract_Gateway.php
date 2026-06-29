<?php
/**
 * Abstract Payment Gateway
 *
 * @package TejCart\Gateways
 */

declare( strict_types=1 );

namespace TejCart\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract gateway class providing base functionality for all payment gateways.
 */
abstract class Abstract_Gateway {
    /**
     * Gateway unique identifier.
     *
     * @var string
     */
    protected string $id = '';

    /**
     * Gateway display title.
     *
     * @var string
     */
    protected string $title = '';

    /**
     * Gateway description shown to customers.
     *
     * @var string
     */
    protected string $description = '';

    /**
     * Whether the gateway is enabled.
     *
     * @var bool
     */
    protected bool $enabled = false;

    /**
     * Features this gateway supports (e.g. 'products', 'refunds').
     *
     * @var array
     */
    protected array $supports = array();

    /**
     * Admin form fields configuration.
     *
     * @var array
     */
    protected array $form_fields = array();

    /**
     * Gateway settings loaded from the database.
     *
     * @var array
     */
    protected array $settings = array();

    /**
     * Tracks whether the lazy form_fields + hydrate_defaults bootstrap has
     * already run for this instance. The bootstrap pulls translated strings
     * via `__()`, so we defer it until something actually reads the form
     * schema (which only happens in admin / REST / CLI contexts that run at
     * or after the `init` action).
     *
     * @var bool
     */
    private bool $form_fields_bootstrapped = false;

    /**
     * Constructor. Loads settings from wp_options.
     *
     * Form-field construction and default hydration are deferred to first
     * access via {@see self::ensure_form_fields_bootstrapped()} — see WP 6.7's
     * `_doing_it_wrong` warning when translations are loaded before the
     * `init` action. The gateway is instantiated from
     * `register_features()` at `plugins_loaded`, well before `init` fires.
     */
    public function __construct() {
        $saved = get_option( 'tejcart_gateway_' . $this->id, array() );

        if ( is_array( $saved ) ) {
            $this->settings = $saved;
        }

        // Sync runtime properties from saved settings only. The form_fields
        // default fallback path is intentionally skipped here — building
        // form_fields runs translation lookups and would trigger the WP 6.7
        // "translation loading triggered too early" warning at plugins_loaded.
        // Subclass-set property defaults remain in place when settings are
        // missing.
        if ( isset( $this->settings['enabled'] ) ) {
            $this->enabled = ( 'yes' === $this->settings['enabled'] );
        }
        if ( isset( $this->settings['title'] ) ) {
            $this->title = (string) $this->settings['title'];
        }
        if ( isset( $this->settings['description'] ) ) {
            $this->description = (string) $this->settings['description'];
        }
    }

    /**
     * Lazily build $form_fields and hydrate any missing defaults into
     * $this->settings, persisting the merged row back to wp_options so
     * external readers see a fully populated schema.
     *
     * Idempotent — subsequent calls short-circuit. Callers that need the
     * form_fields array or the hydrated settings (admin settings screen,
     * REST gateway controller, `wp tejcart` CLI) invoke this via the
     * `get_form_fields()` / `get_settings()` / `get_option()` public
     * accessors. Inside the gateway constructor we deliberately do NOT
     * call this so that `__()` lookups are postponed past the `init`
     * action.
     *
     * @return void
     */
    private function ensure_form_fields_bootstrapped(): void {
        if ( $this->form_fields_bootstrapped ) {
            return;
        }
        $this->form_fields_bootstrapped = true;

        // Skip init_form_fields() when form_fields was already populated
        // by an external setter (notably the test doubles in
        // PaymentGatewaysControllerTest, which inject a custom field map
        // via `set_form_fields()`). A subclass with no form fields will
        // still leave the array empty after init_form_fields(), which is
        // the same end state — so this short-circuit costs nothing.
        if ( empty( $this->form_fields ) ) {
            $this->init_form_fields();
        }
        $this->hydrate_defaults();
    }

    /**
     * Fill in any default values from $form_fields that are missing from
     * $this->settings, and persist the merged result to wp_options so
     * external readers (REST endpoints, exports, migrations) see a
     * complete schema without having to re-implement the fallback lookup.
     *
     * Skips structural field types that don't carry a value.
     *
     * @return void
     */
    private function hydrate_defaults(): void {
        if ( empty( $this->form_fields ) || ! is_array( $this->form_fields ) ) {
            return;
        }

        $non_value_types = array( 'heading', 'note', 'connection', 'readonly' );
        $added           = false;

        foreach ( $this->form_fields as $field_id => $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $type = $field['type'] ?? 'text';
            if ( in_array( $type, $non_value_types, true ) ) {
                continue;
            }
            if ( array_key_exists( $field_id, $this->settings ) ) {
                continue;
            }
            if ( ! array_key_exists( 'default', $field ) ) {
                continue;
            }
            $this->settings[ $field_id ] = $field['default'];
            $added = true;
        }

        if ( $added ) {
            // H-5: gateway settings carry encrypted credentials (PayPal
            // client_secret, webhook_id, …). Don't autoload — keeps the
            // ciphertext out of wp_load_alloptions() so a future DB-read
            // primitive can't trivially exfiltrate the credential bundle
            // for every authenticated request.
            update_option( 'tejcart_gateway_' . $this->id, $this->settings, false );

            // Now that settings carry the hydrated defaults, mirror them
            // onto the runtime properties so callers that grabbed the
            // gateway before the bootstrap ran see the merged title /
            // description / enabled flag.
            if ( isset( $this->settings['enabled'] ) ) {
                $this->enabled = ( 'yes' === $this->settings['enabled'] );
            }
            if ( isset( $this->settings['title'] ) ) {
                $this->title = (string) $this->settings['title'];
            }
            if ( isset( $this->settings['description'] ) ) {
                $this->description = (string) $this->settings['description'];
            }
        }
    }

    /**
     * Process a payment for the given order.
     *
     * @param int $order_id Order ID.
     * @return array Array with keys 'result' ('success'|'failure') and 'redirect' (URL).
     */
    abstract public function process_payment( int $order_id ): array;

    /**
     * Check whether the gateway is available for use.
     *
     * @return bool
     */
    public function is_available(): bool {
        if ( ! $this->enabled ) {
            return false;
        }

        return true;
    }

    /**
     * Whether the gateway should be listed in the admin Settings → Payments page.
     *
     * Gateways whose prerequisites (e.g. PayPal seller onboarding) have not
     * been met return false so they are hidden from the admin list until the
     * required setup is complete. Avoids showing merchants methods that
     * cannot possibly work without credentials.
     *
     * Defaults to true; gateways with onboarding prerequisites override this.
     *
     * @return bool
     */
    public function is_admin_visible(): bool {
        /**
         * Filter whether a gateway is visible in the admin payment methods list.
         *
         * @param bool             $visible Whether the gateway is visible.
         * @param Abstract_Gateway $gateway Gateway instance.
         */
        return (bool) apply_filters( 'tejcart_gateway_admin_visible', true, $this );
    }

    /**
     * Check whether the gateway supports a given feature.
     *
     * @param string $feature Feature name.
     * @return bool
     */
    public function supports( string $feature ): bool {
        $supported = in_array( $feature, $this->supports, true );

        /**
         * Filter whether a gateway supports a specific feature.
         *
         * @param bool            $supported Whether the feature is supported.
         * @param string          $feature   Feature name.
         * @param Abstract_Gateway $gateway  Gateway instance.
         */
        return (bool) apply_filters( 'tejcart_gateway_supports', $supported, $feature, $this );
    }

    /**
     * Currencies this gateway can transact in.
     *
     * An empty array means "no restriction" — the gateway accepts
     * whatever currency the store is configured for. This is the
     * historical behaviour, so offline gateways and any gateway that
     * does not override this method keep accepting every currency.
     * Gateways with a fixed acquirer-side currency list (e.g. PayPal)
     * override this to declare it.
     *
     * @return string[] Upper-case ISO-4217 codes, or empty for "any".
     */
    public function supported_currencies(): array {
        return array();
    }

    /**
     * Whether this gateway can charge in the given currency.
     *
     * @param string $currency ISO-4217 currency code.
     * @return bool
     */
    public function supports_currency( string $currency ): bool {
        $supported = $this->supported_currencies();
        if ( empty( $supported ) ) {
            return true;
        }
        return in_array( strtoupper( $currency ), array_map( 'strtoupper', $supported ), true );
    }

    /**
     * Get the gateway title, filtered.
     *
     * @return string
     */
    public function get_title(): string {
        /**
         * Filter the gateway title.
         *
         * @param string          $title   Gateway title.
         * @param Abstract_Gateway $gateway Gateway instance.
         */
        return apply_filters( 'tejcart_gateway_title', $this->title, $this );
    }

    /**
     * Get the gateway description.
     *
     * @return string
     */
    public function get_description(): string {
        return $this->description;
    }

    /**
     * Output payment form fields on the checkout page.
     * Override in subclasses for custom payment forms.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo wp_kses_post( wpautop( $this->description ) );
        }
    }

    /**
     * Validate submitted payment fields.
     * Override in subclasses for custom validation.
     *
     * @return bool
     */
    public function validate_fields(): bool {
        return true;
    }

    /**
     * Process a refund.
     *
     * @param int        $order_id Order ID.
     * @param float|null $amount   Refund amount, or null for full refund.
     * @param string     $reason   Refund reason.
     * @return bool|WP_Error True on success, false or WP_Error on failure.
     */
    public function process_refund( int $order_id, ?float $amount = null, string $reason = '' ) {
        return false;
    }

    /**
     * Initialise form fields for the gateway settings screen.
     * Override in subclasses.
     */
    public function init_form_fields(): void {
        $this->form_fields = array();
    }

    /**
     * Get the gateway form field definitions.
     *
     * @return array
     */
    public function get_form_fields(): array {
        $this->ensure_form_fields_bootstrapped();
        return $this->form_fields;
    }

    /**
     * Get all settings as an associative array.
     *
     * @return array
     */
    public function get_settings(): array {
        $this->ensure_form_fields_bootstrapped();
        return $this->settings;
    }

    /**
     * Setting keys whose values are sensitive credential material (API
     * secrets, passwords) and must NEVER be exposed over the REST API or any
     * other read surface. Derived from `password`-type form fields plus a
     * conservative name heuristic; gateways with identifiers that don't match
     * the heuristic (or that store secrets under an unusual key) may override.
     *
     * @return list<string>
     */
    public function get_secret_setting_keys(): array {
        $keys = array();
        foreach ( $this->get_form_fields() as $key => $field ) {
            $key  = (string) $key;
            $type = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
            if ( 'password' === $type ) {
                $keys[] = $key;
                continue;
            }
            $lower = strtolower( $key );
            if ( false !== strpos( $lower, 'secret' )
                || false !== strpos( $lower, 'password' )
                || false !== strpos( $lower, 'api_key' )
                || false !== strpos( $lower, 'private_key' ) ) {
                $keys[] = $key;
            }
        }
        return array_values( array_unique( $keys ) );
    }

    /**
     * Get a single setting value.
     *
     * @param string $key     Setting key.
     * @param string $default Default value if not set.
     * @return mixed
     */
    public function get_option( string $key, string $default = '' ) {
        if ( isset( $this->settings[ $key ] ) ) {
            return $this->settings[ $key ];
        }

        // Lazy-bootstrap form_fields only when the setting isn't already
        // saved — keeps the constructor's `get_option('enabled', 'no')`
        // calls from pulling translated default strings before `init`.
        $this->ensure_form_fields_bootstrapped();

        if ( isset( $this->settings[ $key ] ) ) {
            return $this->settings[ $key ];
        }

        if ( isset( $this->form_fields[ $key ]['default'] ) ) {
            return $this->form_fields[ $key ]['default'];
        }

        return $default;
    }

    /**
     * Update a single setting value in memory.
     *
     * @param string $key   Setting key.
     * @param mixed  $value Setting value.
     */
    public function update_option( string $key, $value ): void {
        $this->settings[ $key ] = $value;
    }

    /**
     * Persist all current settings to wp_options.
     *
     * @return bool True if the option was updated, false otherwise.
     */
    public function save_settings(): bool {
        // Audit #27 / 08 #6 — explicit `false` for the autoload flag.
        // Without it the row's autoload flipped to `yes` on every
        // admin save, regressing the no-autoload protection at line
        // ~186 (H-5 security guard against autoloading encrypted
        // gateway credentials with the rest of `alloptions`).
        return update_option( 'tejcart_gateway_' . $this->id, $this->settings, false );
    }

    /**
     * Get the gateway ID.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->id;
    }
}
