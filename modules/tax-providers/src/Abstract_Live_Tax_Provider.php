<?php
/**
 * Shared base for live, third-party tax providers.
 *
 * @package TejCart\Tax_Providers
 */

namespace TejCart\Tax_Providers;

use TejCart\Security\Crypto;
use TejCart\Security\Crypto_Exception;
use TejCart\Tax\Abstract_Tax_Provider;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Common scaffolding for {@see \TejCart\Tax\Abstract_Tax_Provider}
 * implementations that call a remote rate API.
 *
 * Concrete subclasses implement {@see Abstract_Live_Tax_Provider::request_tax()},
 * which receives the resolved cart context and is expected to return a tax
 * amount in cart currency or null on failure. The base class handles:
 *
 *   - Credential storage (encrypted at rest via {@see Crypto}).
 *   - Per-request memoisation, so the same cart computed multiple times in a
 *     single page load (cart total → checkout review → AJAX update) only hits
 *     the upstream once.
 *   - Short-lived transient caching keyed by a stable hash of the cart and
 *     destination address, so independent requests for the same shopper
 *     don't burn API quota.
 *   - **Page-context gating** ({@see Page_Context}) — by default we only call
 *     the upstream on the checkout page. The Cart page can be opted in via
 *     the `calculation_pages` setting. This is the single biggest lever
 *     against runaway billing on high-volume stores.
 *   - **Address completeness gate** — destinations missing the country code
 *     (or, in nexus-strict countries like US/CA, missing both state and
 *     postcode) are rejected pre-flight so a half-typed address can't fire
 *     a billable round-trip that the upstream would just reject anyway.
 *   - **Daily call cap and circuit breaker** ({@see Tax_Provider_Usage_Tracker})
 *     — caps protect against runaway test-mode billing; the breaker stops
 *     us from synchronously hammering an unhealthy upstream during an
 *     incident and instead falls cleanly through to the manual rate table.
 *   - **Latency tracking and last-error capture** for the admin card and
 *     audit log.
 *   - Soft-fail logging via {@see tejcart_log()}: the calculator falls back
 *     to {@see \TejCart\Tax\Tax_Manager} whenever the upstream is sick.
 *
 * Subclasses should override:
 *   - {@see static::CREDENTIAL_KEYS} to declare the option fields they read.
 *   - {@see Abstract_Live_Tax_Provider::request_tax()} for the API call.
 *   - {@see Abstract_Live_Tax_Provider::is_configured()} when the default
 *     "all credential keys non-empty" check is too coarse.
 *   - {@see Abstract_Live_Tax_Provider::is_test_mode()} when the credentials
 *     can identify a sandbox/test environment (the Stripe driver detects the
 *     `*_test_*` key prefix, for example).
 */
abstract class Abstract_Live_Tax_Provider extends Abstract_Tax_Provider {
    /**
     * WordPress option key holding this provider's credentials and toggles.
     *
     * Stored shape: associative array of { key → value }. Sensitive entries
     * (anything matching {@see static::SECRET_KEYS}) are encrypted at rest.
     *
     * @var string
     */
    protected string $option_key = '';

    /**
     * Credential field IDs surfaced in the admin UI. Override per provider.
     *
     * Each entry: { 'id' => string, 'label' => string, 'type' => 'text'|'password',
     * 'description' => string, 'required' => bool }.
     *
     * @var array<int, array<string, mixed>>
     */
    public const CREDENTIAL_KEYS = array();

    /**
     * IDs from {@see static::CREDENTIAL_KEYS} that are sensitive and must be
     * encrypted at rest with {@see Crypto::encrypt()}.
     *
     * @var string[]
     */
    public const SECRET_KEYS = array();

    /**
     * Setting fields shared across every live provider.
     *
     * These render after a provider's credential fields and control gating
     * behaviour that is identical regardless of upstream — page context,
     * daily call cap, circuit-breaker tuning, address strictness.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function shared_setting_keys(): array {
        return array(
            array(
                'id'          => '__shared_safety_heading',
                'label'       => 'Safety controls',
                'type'        => 'heading',
                'description' => 'Limit when this provider is consulted to keep API spend predictable on high-volume stores.',
                'required'    => false,
            ),
            array(
                'id'          => 'calculation_pages',
                'label'       => 'Calculate tax on',
                'type'        => 'select',
                'options'     => array(
                    'checkout_only'     => 'Checkout only (recommended)',
                    'cart_and_checkout' => 'Cart and checkout',
                    'cart_only'         => 'Cart only',
                ),
                'description' => 'Default leaves the cart page using the cached / manual rate table and only consults the live provider during checkout. Saves roughly half of all calls on a typical store.',
                'required'    => false,
            ),
            array(
                'id'          => 'daily_cap',
                'label'       => 'Daily call cap',
                'type'        => 'number',
                'description' => 'Hard stop after this many upstream calls in a UTC day. 0 disables the cap. Recommended for staging / pre-launch sites to avoid surprise bills.',
                'required'    => false,
            ),
            array(
                'id'          => 'strict_address',
                'label'       => 'Require complete address',
                'type'        => 'checkbox',
                'description' => 'When checked (default), skip the upstream call until the customer has supplied country plus state or postcode. Stops half-typed addresses from being billed for a useless round-trip.',
                'required'    => false,
            ),
        );
    }

    /**
     * Render-time merge of provider-specific credential keys with the shared
     * safety controls. Used by the admin renderer.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function setting_fields(): array {
        return array_merge( static::CREDENTIAL_KEYS, static::shared_setting_keys() );
    }

    /**
     * Default values for shared settings, applied on first read so the gate
     * logic always has well-typed values without the merchant pre-saving.
     *
     * @return array<string, mixed>
     */
    protected static function shared_setting_defaults(): array {
        return array(
            'calculation_pages' => 'checkout_only',
            'daily_cap'         => 0,
            'strict_address'    => 'yes',
        );
    }

    /**
     * Default cache TTL (seconds). Live tax responses are stable for a few
     * minutes — the rate at a given address rarely changes mid-checkout —
     * so a short TTL keeps repeated cart calculations cheap without
     * holding stale data through a real rate change.
     *
     * @var int
     */
    protected const CACHE_TTL = 300;

    /**
     * Per-request memo of API responses keyed by the same hash used for the
     * transient cache. Cleared when {@see Abstract_Live_Tax_Provider::reset_runtime_cache()} is called.
     *
     * @var array<string, float|null>
     */
    protected array $request_memo = array();

    /**
     * Read the merged settings array (decrypted secrets included).
     *
     * @return array<string, mixed>
     */
    public function get_settings(): array {
        $stored = get_option( $this->option_key, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        foreach ( static::SECRET_KEYS as $secret_key ) {
            if ( isset( $stored[ $secret_key ] ) && is_string( $stored[ $secret_key ] ) && '' !== $stored[ $secret_key ] ) {
                $ciphertext           = $stored[ $secret_key ];
                $stored[ $secret_key ] = Crypto::decrypt( $ciphertext );
                if ( '' === $stored[ $secret_key ] ) {
                    // Crypto::decrypt() returns an empty string on tag/ciphertext
                    // failure (e.g. salt rotation, corrupted DB row). Without a
                    // log line operators see "tax provider stopped working" with
                    // no trail. Keep this at warning so it surfaces in routine
                    // log review without paging.
                    $this->log_decryption_failure( $secret_key );
                }
            }
        }

        // Apply shared defaults so the gates have well-typed values even on
        // first read of a freshly-installed site.
        return array_merge( static::shared_setting_defaults(), $stored );
    }

    /**
     * Persist merged settings, encrypting secrets.
     *
     * Live tax-provider API keys (Avalara license key, TaxJar API token,
     * Stripe Tax secret) are PCI-soft-control sensitive: a DB leak that
     * exposes them as plaintext is reusable against the upstream service.
     * M-2 (see review): use Crypto::encrypt_required() so a host without
     * the openssl extension fails closed and the merchant sees a hard
     * configuration error instead of silently storing plaintext.
     *
     * Already-encrypted values (re-saving the form without retyping the
     * secret) pass through Crypto::is_encrypted() unchanged so we never
     * double-encrypt.
     *
     * @param array<string, mixed> $settings Plain settings to store.
     * @return bool True on success, false when a secret could not be
     *              safely encrypted (caller surfaces an admin notice).
     */
    public function save_settings( array $settings ): bool {
        foreach ( static::SECRET_KEYS as $secret_key ) {
            if ( isset( $settings[ $secret_key ] ) && is_string( $settings[ $secret_key ] ) && '' !== $settings[ $secret_key ] ) {
                if ( Crypto::is_encrypted( $settings[ $secret_key ] ) ) {
                    continue;
                }
                try {
                    $settings[ $secret_key ] = Crypto::encrypt_required( $settings[ $secret_key ] );
                } catch ( Crypto_Exception $e ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf(
                                'Tax provider %s: refusing to persist secret "%s" as plaintext (%s).',
                                static::class,
                                $secret_key,
                                $e->getMessage()
                            ),
                            'error'
                        );
                    }
                    return false;
                }
            }
        }
        return (bool) update_option( $this->option_key, $settings );
    }

    /**
     * Log a decryption failure once per provider+key per day to avoid
     * flooding the log on every cart calc, AND stash an admin-side
     * notice so the merchant actually sees that the credential has
     * stopped working (logs alone are not surfaced in wp-admin).
     */
    private function log_decryption_failure( string $secret_key ): void {
        $marker = 'tejcart_tax_decrypt_fail_' . $this->get_id() . '_' . $secret_key . '_' . gmdate( 'Ymd' );
        if ( false !== get_transient( $marker ) ) {
            return;
        }
        set_transient( $marker, 1, DAY_IN_SECONDS );

        $message = sprintf(
            'Tax provider %s: decryption of "%s" returned empty — credential may be corrupted or the encryption salt rotated. Re-enter the credential in Settings → Tax → Providers.',
            $this->get_id() ?: static::class,
            $secret_key
        );

        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log( $message, 'warning' );
        }

        // Persist an admin notice with TTL matching the dedup window so
        // the merchant sees a banner in wp-admin until they re-enter the
        // credential (or until the daily dedup expires and we re-check).
        $notices = (array) get_option( self::DECRYPT_NOTICE_OPTION, array() );
        $notices[ $this->get_id() . '|' . $secret_key ] = array(
            'message' => $message,
            'time'    => time(),
        );
        update_option( self::DECRYPT_NOTICE_OPTION, $notices, false );
    }

    /**
     * Option key for the cross-page admin notice store. Notices are
     * cleared by {@see render_decrypt_admin_notices} after they fire
     * once on the Tax → Providers settings screen so the merchant
     * sees the warning at most once per resolution.
     */
    private const DECRYPT_NOTICE_OPTION = 'tejcart_tax_provider_decrypt_notices';

    /**
     * Hookable on `admin_notices` — emits a warning banner per
     * outstanding decryption failure, then clears the option.
     */
    public static function render_decrypt_admin_notices(): void {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $notices = (array) get_option( self::DECRYPT_NOTICE_OPTION, array() );
        if ( empty( $notices ) ) {
            return;
        }
        foreach ( $notices as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['message'] ) ) {
                continue;
            }
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html( (string) $entry['message'] )
            );
        }
        delete_option( self::DECRYPT_NOTICE_OPTION );
    }

    /**
     * Read a single setting value (decrypted for secrets).
     *
     * @param string $key     Setting ID.
     * @param mixed  $default Default value when unset.
     * @return mixed
     */
    public function get_setting( string $key, $default = '' ) {
        $settings = $this->get_settings();
        return $settings[ $key ] ?? $default;
    }

    /**
     * Default availability check: provider is enabled and every required
     * credential is non-empty. Subclasses can override for custom rules
     * (e.g. a sandbox mode that needs only a subset of keys).
     */
    public function is_available(): bool {
        if ( 'yes' !== $this->get_setting( 'enabled', 'no' ) ) {
            return false;
        }
        return $this->is_configured();
    }

    /**
     * Whether all required credentials are populated.
     */
    public function is_configured(): bool {
        $settings = $this->get_settings();
        foreach ( static::CREDENTIAL_KEYS as $field ) {
            if ( empty( $field['required'] ) ) {
                continue;
            }
            $value = $settings[ $field['id'] ] ?? '';
            if ( '' === (string) $value ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compute tax for this request.
     *
     * Coordinates per-request memoisation, transient cache, the actual API
     * call (delegated to subclasses), the safety gates (page context,
     * address completeness, daily cap, circuit breaker), and soft-fail
     * logging. Returns null when the upstream cannot answer so the caller
     * falls back to the built-in rate table.
     *
     * @param float $taxable_amount Subtotal minus discounts, in cart currency.
     * @param array $context        See {@see Abstract_Tax_Provider::calculate()} for shape.
     * @return float|null
     */
    public function calculate( float $taxable_amount, array $context ): ?float {
        $this->debug( 'calculate() entered', array(
            'taxable_amount' => $taxable_amount,
            'country'        => (string) ( $context['country'] ?? '' ),
            'state'          => (string) ( $context['state'] ?? '' ),
            'postcode'       => (string) ( $context['postcode'] ?? '' ),
            'page'           => (string) ( $context['page'] ?? '' ),
        ) );

        if ( ! $this->is_available() ) {
            $this->debug( 'gate:is_available=false → returning null (provider disabled or missing required credentials)', array(
                'enabled'      => (string) $this->get_setting( 'enabled', 'no' ),
                'is_configured' => $this->is_configured(),
            ) );
            return null;
        }

        if ( $taxable_amount <= 0.0 ) {
            $this->debug( 'gate:taxable_amount<=0 → returning 0.0 (cart subtotal minus discounts is non-positive)' );
            return 0.0;
        }

        $country = isset( $context['country'] ) ? (string) $context['country'] : '';
        if ( '' === $country ) {
            $this->debug( 'gate:country empty → returning null (no destination country in cart context)' );
            return null;
        }

        /**
         * Filter whether this provider should be consulted for the given
         * cart context. Return false to short-circuit before any cache or
         * upstream call. Useful for B2B carts that always use a fixed VAT
         * number, geo-fencing, or experimental rollout gates.
         *
         * @param bool   $allow      Default true.
         * @param array  $context    Cart context.
         * @param string $provider_id Provider identifier (e.g. 'stripe_tax').
         */
        if ( ! (bool) apply_filters( 'tejcart_tax_provider_should_calculate', true, $context, $this->get_id() ) ) {
            $this->debug( 'gate:tejcart_tax_provider_should_calculate filter returned false → returning null' );
            return null;
        }

        // Page-context gate: by default we only call the upstream during
        // checkout. The biggest single defence against runaway billing.
        $page    = isset( $context['page'] ) && is_string( $context['page'] ) && '' !== $context['page']
            ? (string) $context['page']
            : Page_Context::detect();
        $setting = (string) $this->get_setting( 'calculation_pages', 'checkout_only' );
        if ( ! Page_Context::is_allowed( $page, $setting ) ) {
            $this->debug( 'gate:page-context not allowed → returning null', array(
                'detected_page' => $page,
                'setting'       => $setting,
            ) );
            return null;
        }

        // Address completeness gate: half-typed addresses produce useless
        // upstream calls that still get billed.
        if ( ! $this->address_is_complete( $context ) ) {
            $this->debug( 'gate:address_is_complete=false → returning null', array(
                'country'        => $country,
                'state'          => (string) ( $context['state'] ?? '' ),
                'postcode'       => (string) ( $context['postcode'] ?? '' ),
                'strict_address' => (string) $this->get_setting( 'strict_address', 'yes' ),
            ) );
            return null;
        }

        $cache_key = $this->cache_key( $taxable_amount, $context );

        if ( array_key_exists( $cache_key, $this->request_memo ) ) {
            $memo = $this->request_memo[ $cache_key ];
            $this->debug( 'cache:request-memo hit → returning memoised value', array( 'tax' => $memo ) );
            return $memo;
        }

        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            $value = is_numeric( $cached ) ? (float) $cached : null;
            $this->request_memo[ $cache_key ] = $value;
            $this->debug( 'cache:transient hit → returning cached value', array( 'tax' => $value ) );
            return $value;
        }

        // Cap and breaker check (after caches — cached responses aren't
        // billable). On `cap`, we fall through to manual rates and continue;
        // on `breaker`, same — but each emits a single warning per day so
        // SREs can alert on it.
        $tracker  = $this->usage_tracker();
        $cap      = $this->daily_cap();
        $b_thresh = $this->breaker_threshold();
        $b_cool   = $this->breaker_cooldown();
        $gate     = $tracker->should_allow_call( $this->get_id(), $cap, $b_thresh, $b_cool );
        if ( ! $gate['allowed'] ) {
            $this->debug( 'gate:cap-or-breaker tripped → returning null', array(
                'reason'    => (string) ( $gate['reason'] ?? '' ),
                'daily_cap' => $cap,
            ) );
            $this->log_throttle( $gate['reason'] );
            $this->request_memo[ $cache_key ] = null;
            self::signal_provider_unhealthy( $this->get_id(), (string) ( $gate['reason'] ?? 'cap_or_breaker' ) );
            return null;
        }

        // Increment the day counter before the call so concurrent workers
        // observe each other and a burst of misconfigured carts can't blow
        // through the cap by 10x. The breaker still relies on post-call
        // failure tracking.
        $tracker->increment_day_count( $this->get_id() );

        $started_at_us = (int) ( microtime( true ) * 1_000_000 );

        $this->debug( 'request_tax() about to fire upstream call', array(
            'taxable_amount' => $taxable_amount,
            'shipping_total' => (float) ( $context['shipping_total'] ?? 0.0 ),
        ) );

        try {
            $tax = $this->request_tax( $taxable_amount, $context );
        } catch ( \Throwable $e ) {
            $message = $e->getMessage();
            $tracker->record_failure( $this->get_id(), $message, $b_thresh, $b_cool );
            $this->debug( 'request_tax() threw — recording breaker failure and returning null', array(
                'exception' => get_class( $e ),
                'message'   => $message,
            ) );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf(
                        '%s tax provider error: %s',
                        $this->get_id() ?: static::class,
                        $message
                    ),
                    'warning'
                );
            }
            $this->request_memo[ $cache_key ] = null;
            self::signal_provider_unhealthy( $this->get_id(), 'request_threw:' . get_class( $e ) );
            return null;
        }

        $latency_ms = (int) round( ( ( microtime( true ) * 1_000_000 ) - $started_at_us ) / 1000 );

        if ( null !== $tax ) {
            $tax = max( 0.0, (float) $tax );
            set_transient( $cache_key, $tax, $this->cache_ttl() );
            $tracker->record_success( $this->get_id(), $latency_ms );
            $this->debug( 'request_tax() succeeded', array(
                'tax'        => $tax,
                'latency_ms' => $latency_ms,
            ) );
        } else {
            // request_tax() returned null without throwing — the subclass
            // already logged the upstream detail. Treat as a failure for
            // breaker purposes so a sustained 4xx storm still trips it.
            $tracker->record_failure( $this->get_id(), 'upstream returned null', $b_thresh, $b_cool );
            $this->debug( 'request_tax() returned null → falling back to manual rates', array(
                'latency_ms' => $latency_ms,
            ) );
        }

        $this->request_memo[ $cache_key ] = $tax;
        return $tax;
    }

    /**
     * Wrap `wp_remote_post()` with gated debug request/response logging.
     *
     * The existing {@see debug()} channel is always-on and routes to a
     * provider-specific tax log so a misbehaving live-rate calculator
     * is never silent. This helper is the *additional* per-HTTP-call
     * trace gated by the global `tejcart_log_level` (Settings → Advanced):
     * when the level is set to `debug`, each provider request emits a
     * `[tax:<id>] http_request` line before the call and a
     * `[tax:<id>] http_response` (or `http_transport_error`) line after,
     * via {@see tejcart_log()}. Bodies are intentionally NOT included —
     * destination address PII is already redacted in {@see debug()}, and
     * the HTTP trace is just for "did the request happen, how long did it
     * take, what status" investigations.
     *
     * @param array<string, mixed> $args `wp_remote_post()` arguments.
     * @return array|\WP_Error             Raw `wp_remote_post()` return.
     */
    protected function remote_post( string $url, array $args, string $context = '' ) {
        $debug    = $this->http_debug_logging_enabled();
        $started  = microtime( true );

        if ( $debug ) {
            tejcart_log(
                sprintf( '[tax:%s] http_request', $this->get_id() ?: static::class ),
                'debug',
                array(
                    'source'  => 'tax_' . ( $this->get_id() ?: 'live' ) . '_http',
                    'method'  => 'POST',
                    'url'     => $url,
                    'context' => $context,
                )
            );
        }

        $response = wp_remote_post( $url, $args );

        if ( $debug ) {
            $duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            if ( is_wp_error( $response ) ) {
                tejcart_log(
                    sprintf( '[tax:%s] http_transport_error', $this->get_id() ?: static::class ),
                    'debug',
                    array(
                        'source'      => 'tax_' . ( $this->get_id() ?: 'live' ) . '_http',
                        'url'         => $url,
                        'context'     => $context,
                        'duration_ms' => $duration_ms,
                        'error'       => $response->get_error_message(),
                    )
                );
            } else {
                tejcart_log(
                    sprintf( '[tax:%s] http_response', $this->get_id() ?: static::class ),
                    'debug',
                    array(
                        'source'      => 'tax_' . ( $this->get_id() ?: 'live' ) . '_http',
                        'url'         => $url,
                        'context'     => $context,
                        'status'      => (int) wp_remote_retrieve_response_code( $response ),
                        'duration_ms' => $duration_ms,
                    )
                );
            }
        }

        return $response;
    }

    private function http_debug_logging_enabled(): bool {
        return function_exists( 'tejcart_log' )
            && function_exists( 'tejcart_log_level_passes' )
            && tejcart_log_level_passes( 'debug' );
    }

    /**
     * Emit a debug-level log line tagged with this provider's id.
     *
     * Routes to a dedicated `tax_<provider_id>` channel so multiple
     * providers (TaxJar, Stripe Tax, Avalara) write to separate files.
     * The file lives under `{uploads}/tejcart-logs/tax_<id>-<date>-<hash>.log`.
     *
     * Always-on — `tejcart_tax_log()` bypasses `tejcart_log_level` so a
     * silently-failing tax provider is never invisible to operators.
     *
     * **PII redaction**: the destination address is needed to debug tax
     * decisions ("why did this US-CA cart get 0 tax?") but the street line,
     * the full postcode, and the city are not — and the log files end up
     * on disk and inside support bundles. The keys listed in
     * {@see static::PII_FIELDS} are masked through {@see redact_for_log()}
     * before the context is handed off to the log writer. Whole-payload
     * arrays (Avalara `shipTo`, TaxJar `to_*`, Stripe `customer_details`)
     * are scrubbed recursively.
     *
     * @param string               $event   Short human-readable description of the decision.
     * @param array<string, mixed> $context Additional structured fields appended as JSON.
     */
    protected function debug( string $event, array $context = array() ): void {
        if ( ! function_exists( 'tejcart_tax_log' ) ) {
            return;
        }

        $provider_id = $this->get_id() ?: static::class;

        tejcart_tax_log(
            'tax_' . $provider_id,
            sprintf( '[%s] %s', $provider_id, $event ),
            $this->redact_for_log( $context )
        );
    }

    /**
     * Tokens that flag a field name as PII. Matched as a case-insensitive
     * substring against the normalised key, so both flat keys ("postcode",
     * "to_zip") and Stripe's bracketed form keys
     * ("customer_details[address][postal_code]") are caught by the same
     * rule. Country and state codes are intentionally NOT in this list —
     * those are the dimensions operators need to debug tax decisions and
     * are not personally identifying on their own.
     *
     * @var array<int, string>
     */
    protected const PII_KEY_TOKENS = array(
        'postcode', 'postal_code', 'postalcode', 'zip',
        'city',
        'line1', 'line2', 'streetlines', 'street', 'address1', 'address_line',
        'email', 'phone',
    );

    /**
     * Keys whose value is itself a postcode (kept as a 3-char ZIP3-style
     * prefix instead of a fully length-redacted marker).
     */
    private const POSTCODE_KEY_TOKENS = array( 'postcode', 'postal_code', 'postalcode', 'zip' );

    /**
     * Recursively mask PII fields inside a logging context.
     *
     * Decisions:
     *   - Keys containing a postcode token: truncate to 3 leading chars
     *     ("94103" → "941***", "M5V 1A1" → "M5V***"). Enough to support
     *     a "why was tax wrong for ZIP 941xx" investigation without
     *     storing the full address on disk.
     *   - Other PII tokens (city, street, email, phone): collapse to a
     *     length-tagged marker.
     *   - Top-level `body` / `payload` strings that look like JSON are
     *     decoded so nested PII is also masked.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function redact_for_log( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                if ( is_string( $k ) && $this->key_is_pii( $k ) ) {
                    $out[ $k ] = $this->mask_pii_value( $k, $v );
                    continue;
                }
                $out[ $k ] = $this->redact_for_log( $v );
            }
            return $out;
        }

        if ( is_string( $value ) && strlen( $value ) > 1 && ( '{' === $value[0] || '[' === $value[0] ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                return wp_json_encode( $this->redact_for_log( $decoded ) );
            }
        }

        return $value;
    }

    private function key_is_pii( string $key ): bool {
        $needle = strtolower( $key );
        foreach ( static::PII_KEY_TOKENS as $token ) {
            if ( str_contains( $needle, $token ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mask a single PII value.
     */
    private function mask_pii_value( string $field, $raw ): string {
        $str = is_scalar( $raw ) ? (string) $raw : '';
        if ( '' === $str ) {
            return '';
        }
        $lower = strtolower( $field );
        foreach ( self::POSTCODE_KEY_TOKENS as $token ) {
            if ( str_contains( $lower, $token ) ) {
                $compact = preg_replace( '/\s+/', '', $str );
                return strtoupper( substr( (string) $compact, 0, 3 ) ) . '***';
            }
        }
        return '[redacted len=' . strlen( $str ) . ']';
    }

    /**
     * Concrete API call. Return null when the provider cannot answer
     * authoritatively (network error, missing nexus, currency mismatch);
     * the caller will fall through to {@see \TejCart\Tax\Tax_Manager}.
     *
     * @param float $taxable_amount Subtotal minus discounts.
     * @param array $context        Cart context (country, state, postcode, …).
     * @return float|null
     */
    abstract protected function request_tax( float $taxable_amount, array $context ): ?float;

    /**
     * Build the cache key for a given cart computation.
     *
     * The key is per-provider and per-rounded-amount so two providers in the
     * same store don't collide, and so the cache automatically refreshes
     * when anything material in the cart changes.
     *
     * @param float $taxable_amount
     * @param array $context
     * @return string
     */
    protected function cache_key( float $taxable_amount, array $context ): string {
        $material = array(
            'p'  => $this->get_id(),
            'a'  => round( $taxable_amount, 4 ),
            // Currency must be part of the key: the taxable amount is in cart
            // currency, so without this a multi-currency store would serve a
            // cached "100 USD" tax to a "100 EUR" cart (same numeric amount,
            // same destination) inside the TTL and collect the wrong tax.
            'cu' => strtoupper( $this->resolve_cache_currency() ),
            'co' => isset( $context['country'] ) ? strtoupper( (string) $context['country'] ) : '',
            'st' => isset( $context['state'] ) ? strtoupper( (string) $context['state'] ) : '',
            'pc' => isset( $context['postcode'] ) ? strtoupper( preg_replace( '/\s+/', '', (string) $context['postcode'] ) ) : '',
            'sh' => isset( $context['shipping_total'] ) ? round( (float) $context['shipping_total'], 4 ) : 0.0,
            'ic' => ! empty( $context['prices_include_tax'] ),
        );
        return 'tejcart_tax_' . substr( hash( 'sha1', wp_json_encode( $material ) ), 0, 24 );
    }

    /**
     * Resolve the active cart currency for cache-key scoping. Mirrors the
     * `resolve_currency()` helpers in the concrete drivers but lives here so
     * the cache key is currency-aware for every provider (including
     * third-party subclasses) without each one re-implementing it.
     */
    protected function resolve_cache_currency(): string {
        if ( function_exists( 'tejcart_get_currency' ) ) {
            $code = (string) tejcart_get_currency();
            if ( '' !== $code ) {
                return $code;
            }
        }
        return (string) get_option( 'tejcart_currency', 'USD' );
    }

    /**
     * TTL for the transient cache, filterable per provider.
     */
    protected function cache_ttl(): int {
        $ttl = (int) apply_filters( 'tejcart_tax_provider_cache_ttl', static::CACHE_TTL, $this->get_id() );
        return max( 0, $ttl );
    }

    /**
     * Reset the in-process memo. Test-only helper.
     */
    public function reset_runtime_cache(): void {
        $this->request_memo = array();
    }

    /**
     * Build the destination address payload subclasses commonly need.
     *
     * @param array $context Cart context array.
     * @return array{country: string, state: string, postcode: string, city: string, line1: string}
     */
    protected function destination_address( array $context ): array {
        return array(
            'country'  => isset( $context['country'] ) ? strtoupper( (string) $context['country'] ) : '',
            'state'    => isset( $context['state'] ) ? strtoupper( (string) $context['state'] ) : '',
            'postcode' => isset( $context['postcode'] ) ? (string) $context['postcode'] : '',
            'city'     => isset( $context['city'] ) ? (string) $context['city'] : '',
            'line1'    => isset( $context['line1'] ) ? (string) $context['line1'] : '',
        );
    }

    /**
     * Sanitise an HTTP timeout the way every driver in this module wants:
     * never below 5 s, never above 30 s. Keeps a single misconfiguration
     * from blocking checkout for a full minute.
     */
    protected function http_timeout(): int {
        $timeout = (int) apply_filters( 'tejcart_tax_provider_http_timeout', 15, $this->get_id() );
        return min( 30, max( 5, $timeout ) );
    }

    /**
     * Whether this provider's credentials look like a sandbox / test key.
     *
     * Subclasses override when the upstream exposes a clear test-mode
     * signal in the credentials. Default conservative answer is `false`
     * (treat as live) so we never accidentally suppress billing safety
     * for a real production key.
     */
    public function is_test_mode(): bool {
        return false;
    }

    /**
     * Address completeness pre-flight. Country is always required; for
     * countries with sub-national tax nexus we additionally require state or
     * postcode so a half-typed address can't burn a billed call that the
     * upstream would just reject.
     */
    protected function address_is_complete( array $context ): bool {
        $country = strtoupper( (string) ( $context['country'] ?? '' ) );
        if ( '' === $country ) {
            return false;
        }

        if ( 'yes' !== (string) $this->get_setting( 'strict_address', 'yes' ) ) {
            return true;
        }

        /**
         * Filter the list of countries where state or postcode is mandatory
         * before a billable upstream call may be made.
         *
         * @param array<int,string> $countries Two-letter ISO codes, uppercase.
         * @param string            $provider_id
         */
        $strict_countries = (array) apply_filters(
            'tejcart_tax_provider_strict_address_countries',
            array( 'US', 'CA', 'AU', 'GB', 'IN', 'BR', 'MX' ),
            $this->get_id()
        );

        if ( in_array( $country, $strict_countries, true ) ) {
            $state    = trim( (string) ( $context['state'] ?? '' ) );
            $postcode = trim( (string) ( $context['postcode'] ?? '' ) );
            if ( '' === $state && '' === $postcode ) {
                return false;
            }
        }

        /**
         * Final say: integrations can flip the answer either way (e.g. a B2B
         * checkout with a verified VAT ID may legitimately skip postcode).
         *
         * @param bool   $complete    Default decision.
         * @param array  $context     Cart context.
         * @param string $provider_id Provider identifier.
         */
        return (bool) apply_filters( 'tejcart_tax_provider_address_complete', true, $context, $this->get_id() );
    }

    /**
     * Daily call cap (filterable). 0 disables the cap entirely.
     */
    protected function daily_cap(): int {
        $cap = (int) $this->get_setting( 'daily_cap', 0 );
        $cap = (int) apply_filters( 'tejcart_tax_provider_daily_cap', $cap, $this->get_id() );
        return max( 0, $cap );
    }

    /**
     * Consecutive-failure threshold before the breaker opens (filterable).
     * 0 disables the breaker.
     */
    protected function breaker_threshold(): int {
        $threshold = (int) apply_filters(
            'tejcart_tax_provider_breaker_threshold',
            Tax_Provider_Usage_Tracker::BREAKER_THRESHOLD,
            $this->get_id()
        );
        return max( 0, $threshold );
    }

    /**
     * Seconds the breaker stays open before half-opening for a probe call.
     */
    protected function breaker_cooldown(): int {
        $cooldown = (int) apply_filters(
            'tejcart_tax_provider_breaker_cooldown',
            Tax_Provider_Usage_Tracker::BREAKER_COOLDOWN,
            $this->get_id()
        );
        return max( 1, $cooldown );
    }

    /**
     * Usage tracker singleton accessor — overridable in tests.
     */
    protected function usage_tracker(): Tax_Provider_Usage_Tracker {
        return Tax_Provider_Usage_Tracker::instance();
    }

    /**
     * Log a throttle event at most once per provider per day so high-volume
     * stores don't drown their log files when the cap is hit.
     */
    private function log_throttle( string $reason ): void {
        if ( ! function_exists( 'tejcart_log' ) ) {
            return;
        }

        $marker_key = 'tejcart_tax_throttle_' . $this->get_id() . '_' . $reason . '_' . gmdate( 'Ymd' );
        if ( false !== get_transient( $marker_key ) ) {
            return;
        }
        set_transient( $marker_key, 1, DAY_IN_SECONDS );

        $message = 'cap' === $reason
            ? sprintf( '%s daily cap reached — falling through to manual rate table.', $this->get_id() )
            : sprintf( '%s circuit breaker open — falling through to manual rate table.', $this->get_id() );

        tejcart_log( $message, 'warning' );
    }

    /**
     * Decode a JSON response body, returning null on parse error or HTTP
     * non-2xx status. Logs the upstream response when available.
     *
     * @param mixed  $response wp_remote_* response.
     * @param string $context  Short description for the log line.
     * @return array<string, mixed>|null
     */
    protected function decode_json_response( $response, string $context ): ?array {
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            $this->debug( 'http:transport error', array(
                'endpoint' => $context,
                'error'    => $error_msg,
            ) );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( '%s %s: %s', $this->get_id(), $context, $error_msg ),
                    'warning'
                );
            }
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            $this->debug( 'http:non-2xx response', array(
                'endpoint' => $context,
                'status'   => $code,
                'body'     => substr( $body, 0, 500 ),
            ) );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( '%s %s: HTTP %d %s', $this->get_id(), $context, $code, substr( $body, 0, 200 ) ),
                    'warning'
                );
            }
            return null;
        }

        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            $this->debug( 'http:body not JSON object — cannot extract tax', array(
                'endpoint' => $context,
                'status'   => $code,
                'body'     => substr( $body, 0, 500 ),
            ) );
            return null;
        }

        $this->debug( 'http:2xx response decoded', array(
            'endpoint' => $context,
            'status'   => $code,
            'body'     => substr( $body, 0, 500 ),
        ) );
        return $decoded;
    }

    /**
     * Request-scoped record of provider IDs that returned null because
     * they were unhealthy during this request. Checkout-validate
     * listeners can read this to refuse the order when
     * `tejcart_tax_provider_strict_failover` is on rather than
     * silently fall through to manual rates.
     *
     * @var array<string,string>
     */
    private static array $unhealthy_signals = array();

    /**
     * Record that this provider's request couldn't be served by the
     * upstream. Backed by a request-scoped static for low cost. Also
     * fires the `tejcart_tax_provider_unavailable` action so
     * extensions / SRE alerting can hook in.
     */
    protected static function signal_provider_unhealthy( string $provider_id, string $reason ): void {
        if ( '' === $provider_id ) {
            $provider_id = 'unknown';
        }
        self::$unhealthy_signals[ $provider_id ] = $reason;

        /**
         * Fires when a live tax provider couldn't compute a rate for
         * the active request. Listeners can route to ops alerting or
         * a custom checkout-block flow.
         *
         * @param string $provider_id e.g. 'taxjar', 'avalara', 'stripe_tax'.
         * @param string $reason      Short reason code: `cap`, `breaker`, `request_threw:Exception`.
         */
        if ( function_exists( 'do_action' ) ) {
            do_action( 'tejcart_tax_provider_unavailable', $provider_id, $reason );
        }
    }

    /**
     * Has any active live-tax provider signalled unavailability in the
     * current request? Used by the module's checkout-validate listener
     * to refuse the order when strict mode is opted-in.
     *
     * @return array<string,string> Map of provider_id => reason.
     */
    public static function unhealthy_signals_this_request(): array {
        return self::$unhealthy_signals;
    }

    /**
     * Is the merchant's site opted into strict failover? When true,
     * the checkout-validate listener should refuse to proceed if any
     * provider signalled unhealthy. Default is OFF for backwards
     * compatibility with stores that prefer silent fall-through to
     * manual rates over a checkout-blocked banner during upstream
     * incidents.
     */
    public static function strict_failover_enabled(): bool {
        return (bool) apply_filters(
            'tejcart_tax_provider_strict_failover',
            'yes' === (string) get_option( 'tejcart_tax_provider_strict_failover', 'no' )
        );
    }
}
