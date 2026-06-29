<?php
/**
 * Module Manager — loads optional first-party modules from `modules/`
 * based on a per-module on/off toggle.
 *
 * The five modules below used to ship as separate wordpress.org siblings
 * (`tejcart-analytics`, `tejcart-disputes`, `tejcart-order-tracking`,
 * `tejcart-returns`, `tejcart-shipping`). They are now bundled with core
 * and gated behind admin toggles stored in the `tejcart_modules_enabled`
 * option so merchants only pay the boot cost for features they want.
 * Every module ships OFF by default — merchants opt in from
 * `TejCart → Modules`.
 *
 * @package TejCart\Modules
 */

declare(strict_types=1);

namespace TejCart\Modules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bootstraps optional modules behind merchant-controlled toggles.
 *
 * Defaults: every module is OFF and opt-in — merchants enable only what
 * their store needs via the `TejCart → Modules` admin screen.
 */
final class Module_Manager {
    /**
     * Option key holding the slug => bool map of enabled modules.
     */
    public const OPTION = 'tejcart_modules_enabled';

    private const FLUSH_RULES_TRANSIENT = 'tejcart_flush_rewrite_rules';

    /**
     * Singleton instance.
     */
    private static ?Module_Manager $instance = null;

    /**
     * Module registry keyed by slug.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $registry = array();

    /**
     * Whether load_enabled() has already required module files.
     */
    private bool $loaded = false;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the process-wide singleton. Test-substitution seam (#1242).
     *
     * Pass null to reset to lazy construction on the next `instance()`
     * call. Tests / DI overrides can hand in a fake to exercise call
     * sites that resolve through `Module_Manager::instance()`.
     *
     * @internal Use in tests and DI overrides only.
     */
    public static function set_instance( ?self $instance ): void {
        if ( ! defined( 'TEJCART_TESTING' ) || ! TEJCART_TESTING ) { return; }
        self::$instance = $instance;
    }

    private function __construct() {
        $this->registry = self::default_registry();
        add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 9999 );
    }

    /**
     * Flush rewrite rules once after a module toggle so modules that
     * register rewrite rules (e.g. product-filters) have them persisted.
     *
     * Runs at `init` priority 9999 — after every module's own
     * `add_rewrite_rule()` calls (priority 10) have executed.
     */
    public function maybe_flush_rewrite_rules(): void {
        if ( get_transient( self::FLUSH_RULES_TRANSIENT ) ) {
            delete_transient( self::FLUSH_RULES_TRANSIENT );
            flush_rewrite_rules( false );
        }
    }

    /**
     * The hard-coded module catalogue.
     *
     * Each entry:
     *   - name:        Human label for the admin UI.
     *   - description: One-line benefit-led summary for the admin UI.
     *   - file:        Absolute path to the module's `module.php` bootstrap.
     *   - default:     Default toggle state if the merchant has not chosen yet.
     *   - install:     Optional callable run when the toggle flips ON for
     *                  the first time. Lets a module create its DB tables
     *                  / capabilities on enable instead of on plugin activate.
     *   - disable:     Optional callable run when the toggle flips OFF.
     *                  Used by modules that schedule background jobs or
     *                  hold non-DB state (cron events, Action Scheduler
     *                  hooks) — without this hook those would survive
     *                  the toggle and silently misfire later.
     *   - view:        Optional relative admin URL (passed through
     *                  `admin_url()` at render time) for the module's
     *                  primary admin screen. Surfaced in the Modules page
     *                  as a "Configure" link beside the toggle so
     *                  merchants can jump straight to the module they
     *                  just turned on.
     *   - icon:        Dashicons class for the card icon (e.g.
     *                  `dashicons-chart-bar`). Defaults to a generic
     *                  puzzle-piece glyph if omitted.
     *   - category:    Category slug used to group cards on the admin
     *                  screen — one of the keys returned by
     *                  {@see default_categories()}. Defaults to `other`.
     *   - recommended: When true, the card shows a "Recommended" badge
     *                  to nudge merchants on a fresh install.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function default_registry(): array {
        $base = defined( 'TEJCART_PLUGIN_DIR' ) ? TEJCART_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';

        return array(
            'product-filters' => array(
                'name'        => __( 'Product Filters', 'tejcart' ),
                'description' => __( 'Faceted navigation for the shop page — filter by category, brand, price, rating, stock and attributes with AJAX.', 'tejcart' ),
                'file'        => $base . 'modules/product-filters/module.php',
                'default'     => false,
                'install'     => null,
                'view'        => 'admin.php?page=tejcart-settings&tab=products',
                'icon'        => 'dashicons-filter',
                'category'    => 'customer',
                'recommended' => true,
            ),
            'variation-swatches' => array(
                'name'        => __( 'Variation Swatches', 'tejcart' ),
                'description' => __( 'Replace dropdown selects with visual color, image and label swatches on product pages and shop archives.', 'tejcart' ),
                'file'        => $base . 'modules/variation-swatches/module.php',
                'default'     => false,
                'install'     => null,
                'icon'        => 'dashicons-art',
                'category'    => 'customer',
                'recommended' => true,
            ),
            'analytics'      => array(
                'name'        => __( 'Tracking & Pixels', 'tejcart' ),
                'description' => __( 'Track conversions in GA4 and Meta without browser-side tags — server-side, survives ad-blockers.', 'tejcart' ),
                'file'        => $base . 'modules/analytics/module.php',
                'default'     => false,
                'install'     => null,
                'view'        => 'admin.php?page=tejcart-analytics',
                'icon'        => 'dashicons-chart-bar',
                'category'    => 'marketing',
                'recommended' => true,
            ),
            'disputes'       => array(
                'name'        => __( 'Disputes', 'tejcart' ),
                'description' => __( 'All chargebacks in one queue, with evidence-due reminders so you never miss a response window.', 'tejcart' ),
                'file'        => $base . 'modules/disputes/module.php',
                'default'     => false,
                'install'     => 'tejcart_disputes_install',
                'disable'     => 'tejcart_disputes_disable',
                'view'        => 'admin.php?page=tejcart-disputes',
                'icon'        => 'dashicons-shield-alt',
                'category'    => 'operations',
            ),
            'order-tracking' => array(
                'name'        => __( 'Order Tracking', 'tejcart' ),
                'description' => __( 'Add tracking numbers to orders and let customers check shipment status without contacting support.', 'tejcart' ),
                'file'        => $base . 'modules/order-tracking/module.php',
                'default'     => false,
                // F-MODL-006: Switch from the bare Schema_Migrator static-method array to
                // the dedicated tejcart_order_tracking_install() function defined in
                // module.php. The named function also calls Capability::install() so the
                // scoped cap is granted on first toggle-ON (previously only the schema was
                // created; capabilities were never registered for this module).
                'install'     => 'tejcart_order_tracking_install',
                // F-MODL-006: Add the disable callback so the retention cron and polling
                // job are drained when the toggle flips OFF. Without this, both jobs
                // continue firing against an un-booted module.
                'disable'     => 'tejcart_order_tracking_disable',
                'view'        => 'admin.php?page=tejcart-settings&tab=order-tracking',
                'icon'        => 'dashicons-location-alt',
                'category'    => 'operations',
                'recommended' => true,
            ),
            'returns'        => array(
                'name'        => __( 'Returns / RMA', 'tejcart' ),
                'description' => __( 'Customer-facing return requests with admin approval queue and one-click refund to original or store credit.', 'tejcart' ),
                'file'        => $base . 'modules/returns/module.php',
                'default'     => false,
                'install'     => 'tejcart_returns_install',
                'disable'     => 'tejcart_returns_disable',
                'view'        => 'admin.php?page=tejcart-returns',
                'icon'        => 'dashicons-image-rotate',
                'category'    => 'operations',
            ),
            'shipping'       => array(
                'name'        => __( 'Shipping', 'tejcart' ),
                'description' => __( 'Live carrier rates from FedEx, UPS, USPS, DHL and more — print labels and push tracking to customers.', 'tejcart' ),
                'file'        => $base . 'modules/shipping/module.php',
                'default'     => false,
                'install'     => 'tejcart_shipping_module_install',
                'disable'     => 'tejcart_shipping_module_disable',
                'view'        => 'admin.php?page=tejcart-settings&tab=shipping&section=carriers',
                'icon'        => 'dashicons-products',
                'category'    => 'tax-shipping',
                'recommended' => true,
            ),
            'tax-providers'  => array(
                'name'        => __( 'Tax Providers', 'tejcart' ),
                'description' => __( 'Accurate sales tax, VAT and GST via Stripe Tax, TaxJar or Avalara — no manual rate maintenance.', 'tejcart' ),
                'file'        => $base . 'modules/tax-providers/module.php',
                'default'     => false,
                'install'     => null,
                'view'        => 'admin.php?page=tejcart-settings&tab=tax&section=providers',
                'icon'        => 'dashicons-money-alt',
                'category'    => 'tax-shipping',
            ),
            'address-autocomplete' => array(
                'name'        => __( 'Address Autocomplete', 'tejcart' ),
                'description' => __( 'Let shoppers pick their address from a dropdown that fills city, state and postcode in one tap — a proven checkout conversion lever.', 'tejcart' ),
                'file'        => $base . 'modules/address-autocomplete/module.php',
                'default'     => false,
                'install'     => null,
                'view'        => 'admin.php?page=tejcart-settings&tab=checkout',
                'icon'        => 'dashicons-location',
                'category'    => 'customer',
                'recommended' => true,
            ),
            'currency-switcher' => array(
                'name'        => __( 'Currency Switcher', 'tejcart' ),
                'description' => __( 'Show prices in customers\' local currency with hourly FX, geolocation, and per-currency gateway rules.', 'tejcart' ),
                'file'        => $base . 'modules/currency-switcher/module.php',
                'default'     => false,
                'install'     => 'tejcart_currency_switcher_install',
                'disable'     => 'tejcart_currency_switcher_disable',
                'view'        => 'admin.php?page=tejcart-settings&tab=currency',
                'icon'        => 'dashicons-tag',
                'category'    => 'customer',
            ),
            'ai-content-smartsuite' => array(
                'name'        => __( 'AI Content SmartSuite', 'tejcart' ),
                'description' => __( 'Generate product titles, descriptions, tags and FAQs in seconds with OpenAI — fully editable prompts, 35+ languages, and one-click apply.', 'tejcart' ),
                'file'        => $base . 'modules/ai-content-smartsuite/module.php',
                'default'     => false,
                'install'     => 'tejcart_ai_content_smartsuite_install',
                'disable'     => 'tejcart_ai_content_smartsuite_disable',
                'view'        => 'admin.php?page=tejcart-ai-content',
                'icon'        => 'dashicons-edit-large',
                'category'    => 'marketing',
            ),
            'gift-cards'      => array(
                'name'        => __( 'Gift Cards & Store Credit', 'tejcart' ),
                'description' => __( 'Sell branded gift cards customers can email to friends, plus store credit you can issue from any order or return.', 'tejcart' ),
                'file'        => $base . 'modules/gift-cards/module.php',
                'default'     => false,
                'install'     => 'tejcart_gift_cards_install',
                'disable'     => 'tejcart_gift_cards_disable',
                'view'        => 'admin.php?page=tejcart-gift-cards',
                'icon'        => 'dashicons-tickets-alt',
                'category'    => 'customer',
            ),
            'loyalty'         => array(
                'name'        => __( 'Loyalty & Rewards', 'tejcart' ),
                'description' => __( 'Points-per-dollar loyalty program with checkout redemption, per-product earn rates, and configurable expiry.', 'tejcart' ),
                'file'        => $base . 'modules/loyalty/module.php',
                'default'     => false,
                'install'     => 'tejcart_loyalty_install',
                'disable'     => 'tejcart_loyalty_disable',
                'view'        => 'admin.php?page=tejcart-loyalty',
                'icon'        => 'dashicons-star-filled',
                'category'    => 'customer',
            ),
            'b2b'             => array(
                'name'        => __( 'B2B Company Accounts', 'tejcart' ),
                'description' => __( 'Company profiles with shared addresses, VAT IDs, tax-exempt checkout, member roles and consolidated order history.', 'tejcart' ),
                'file'        => $base . 'modules/b2b/module.php',
                'default'     => false,
                'install'     => 'tejcart_b2b_install',
                'disable'     => 'tejcart_b2b_disable',
                'view'        => 'admin.php?page=tejcart-b2b',
                'icon'        => 'dashicons-building',
                'category'    => 'operations',
            ),
            'graphql'         => array(
                'name'        => __( 'GraphQL API', 'tejcart' ),
                'description' => __( 'First-party GraphQL endpoint for headless storefronts — products, orders, cart, customers, and introspection.', 'tejcart' ),
                'file'        => $base . 'modules/graphql/module.php',
                'default'     => false,
                'install'     => null,
                'view'        => 'admin.php?page=tejcart-settings&tab=api',
                'icon'        => 'dashicons-rest-api',
                'category'    => 'other',
            ),
            'search'          => array(
                'name'        => __( 'Storefront Search', 'tejcart' ),
                'description' => __( 'Fuzzy, weighted product search with autocomplete — finds "Blue Jeans" when shoppers type "bleu jeans".', 'tejcart' ),
                'file'        => $base . 'modules/search/module.php',
                'default'     => false,
                'install'     => array( '\\TejCart\\Tier2\\Search\\Search_Index', 'install' ),
                'view'        => 'admin.php?page=tejcart-settings&tab=search',
                'icon'        => 'dashicons-search',
                'category'    => 'customer',
                'recommended' => true,
            ),
            'referrals'       => array(
                'name'        => __( 'Referral Program', 'tejcart' ),
                'description' => __( 'Let customers share a referral link and earn rewards when friends buy — a built-in alternative to $47+/mo referral SaaS.', 'tejcart' ),
                'file'        => $base . 'modules/referrals/module.php',
                'default'     => false,
                'install'     => 'tejcart_referrals_install',
                'disable'     => 'tejcart_referrals_disable',
                'view'        => 'admin.php?page=tejcart-referrals',
                'icon'        => 'dashicons-groups',
                'category'    => 'marketing',
            ),
            'channels-meta'   => array(
                'name'        => __( 'Meta Channels', 'tejcart' ),
                'description' => __( 'Sync products to Facebook Shop and Instagram Shopping — catalog upload, real-time inventory, server-side Conversions API.', 'tejcart' ),
                'file'        => $base . 'modules/channels-meta/module.php',
                'default'     => false,
                'install'     => 'tejcart_channels_meta_install',
                'disable'     => 'tejcart_channels_meta_disable',
                'view'        => 'admin.php?page=tejcart-sales-channels&channel=meta',
                'icon'        => 'dashicons-share',
                'category'    => 'channels',
            ),
            'channels-tiktok' => array(
                'name'        => __( 'TikTok Shop', 'tejcart' ),
                'description' => __( 'Sell on TikTok — sync products, import orders, push tracking, and measure conversions with server-side Pixel.', 'tejcart' ),
                'file'        => $base . 'modules/channels-tiktok/module.php',
                'default'     => false,
                'install'     => array( '\\TejCart\\Channels\\TikTok\\Schema', 'install' ),
                'disable'     => array( '\\TejCart\\Channels\\TikTok\\Schema', 'on_disable' ),
                'view'        => 'admin.php?page=tejcart-sales-channels&channel=tiktok',
                'icon'        => 'dashicons-share',
                'category'    => 'channels',
            ),
            'channels-amazon' => array(
                'name'        => __( 'Amazon', 'tejcart' ),
                'description' => __( 'List products on Amazon via SP-API — ASIN mapping, FBA inventory, price sync, and category mapping.', 'tejcart' ),
                'file'        => $base . 'modules/channels-amazon/module.php',
                'default'     => false,
                'install'     => array( '\\TejCart\\Channels\\Amazon\\Schema', 'install' ),
                'view'        => 'admin.php?page=tejcart-sales-channels&channel=amazon',
                'icon'        => 'dashicons-cart',
                'category'    => 'channels',
            ),
            'analytics-advanced' => array(
                'name'        => __( 'Store Insights', 'tejcart' ),
                'description' => __( 'Cohort retention tables, customer lifetime value by channel, segment dashboards and trend charts — no third-party subscription.', 'tejcart' ),
                'file'        => $base . 'modules/analytics-advanced/module.php',
                'default'     => false,
                'install'     => 'tejcart_analytics_advanced_install',
                'disable'     => 'tejcart_analytics_advanced_disable',
                'view'        => 'admin.php?page=tejcart-analytics&provider=advanced',
                'icon'        => 'dashicons-chart-area',
                'category'    => 'marketing',
            ),
            'experiments'     => array(
                'name'        => __( 'A/B Testing', 'tejcart' ),
                'description' => __( 'Split-test checkout, product pages and CTAs with deterministic bucketing and auto-declared winners at 95% confidence.', 'tejcart' ),
                'file'        => $base . 'modules/experiments/module.php',
                'default'     => false,
                'install'     => 'tejcart_experiments_install',
                'disable'     => 'tejcart_experiments_disable',
                'view'        => 'admin.php?page=tejcart-experiments',
                'icon'        => 'dashicons-randomize',
                'category'    => 'marketing',
            ),
            'multi-step-checkout' => array(
                'name'        => __( 'Multi-Step Checkout', 'tejcart' ),
                'description' => __( 'Split checkout into steps — billing, shipping, payment and review — with a progress bar and per-step validation.', 'tejcart' ),
                'file'        => $base . 'modules/multi-step-checkout/module.php',
                'default'     => false,
                'install'     => null,
                'view'        => 'admin.php?page=tejcart-settings&tab=checkout',
                'icon'        => 'dashicons-editor-ol',
                'category'    => 'customer',
                'recommended' => true,
            ),
            'captcha'         => array(
                'name'        => __( 'Captcha / Bot Protection', 'tejcart' ),
                'description' => __( 'Cloudflare Turnstile, hCaptcha or Google reCAPTCHA v3 in front of login, checkout, cart and coupon endpoints — stops card-testing botnets that ride past per-IP rate limits.', 'tejcart' ),
                'file'        => $base . 'modules/captcha/module.php',
                'default'     => false,
                'install'     => null,
                'view'        => 'admin.php?page=tejcart-settings&tab=advanced&section=captcha',
                'icon'        => 'dashicons-shield',
                'category'    => 'operations',
                'recommended' => true,
            ),
        );
    }

    /**
     * Category labels keyed by category slug, used to group module cards
     * on the admin screen. Order here is the display order; modules in
     * categories not listed here fall under "Other" at the bottom.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function default_categories(): array {
        return array(
            'marketing'    => array(
                'label'       => __( 'Marketing & Analytics', 'tejcart' ),
                'description' => __( 'Reach customers, measure what is working, and let AI take the first draft.', 'tejcart' ),
            ),
            'operations'   => array(
                'label'       => __( 'Operations', 'tejcart' ),
                'description' => __( 'Day-to-day order, return and dispute workflows.', 'tejcart' ),
            ),
            'tax-shipping' => array(
                'label'       => __( 'Tax & Shipping', 'tejcart' ),
                'description' => __( 'Live carrier rates and accurate tax in every jurisdiction.', 'tejcart' ),
            ),
            'customer'     => array(
                'label'       => __( 'Customer Experience', 'tejcart' ),
                'description' => __( 'Checkout and storefront enhancements your shoppers interact with directly.', 'tejcart' ),
            ),
            'channels'     => array(
                'label'       => __( 'Sales Channels', 'tejcart' ),
                'description' => __( 'Sell on Facebook, Instagram and other marketplaces without managing separate inventories.', 'tejcart' ),
            ),
            'other'        => array(
                'label'       => __( 'Other', 'tejcart' ),
                'description' => '',
            ),
        );
    }

    /**
     * Read the merchant's toggle map, merged onto registry defaults.
     *
     * Only returns entries whose `module.php` bootstrap is present on
     * disk — if a merchant has deleted a module folder, that slug is
     * filtered out so callers see the same shape as if it had never
     * been registered. The stored option is intentionally left intact
     * so restoring the folder reinstates the previous toggle state.
     *
     * @return array<string, bool>
     */
    public function get_states(): array {
        return $this->compute_states( $this->get_registry() );
    }

    /**
     * Whether a module is currently enabled AND its files exist on disk.
     *
     * Returns false for modules whose folder has been deleted so callers
     * that gate features on this method don't try to talk to dead code.
     */
    public function is_enabled( string $slug ): bool {
        $states = $this->get_states();
        return ! empty( $states[ $slug ] );
    }

    /**
     * The module registry (read-only), filtered to only the modules
     * whose `module.php` bootstrap is currently readable. A deleted
     * module folder transparently disappears from the registry, the
     * admin UI, and `is_enabled()` checks — without any data loss
     * (the stored toggle option is left untouched).
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_registry(): array {
        $available = array();
        foreach ( $this->registry as $slug => $entry ) {
            if ( self::module_file_exists( $entry ) ) {
                $available[ $slug ] = $entry;
            }
        }
        return $available;
    }

    /**
     * Full registry including modules whose files are missing on disk.
     * Used for lifecycle bookkeeping (option persistence, install/disable
     * transitions) so deleting a folder never drops the merchant's intent
     * from the stored toggle map.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_full_registry(): array {
        return $this->registry;
    }

    /**
     * Replace the registry — primarily for testing.
     *
     * @param array<string, array<string, mixed>> $registry
     */
    public function set_registry( array $registry ): void {
        $this->registry = $registry;
        $this->loaded   = false;
    }

    /**
     * Require the bootstrap PHP file for every enabled module.
     *
     * Idempotent — guarded by an internal flag so a second call inside
     * the same request is a no-op. Iterates the full registry so a
     * module whose folder was deleted is simply skipped (the readable
     * guard is the safety net) rather than crashing the request.
     */
    public function load_enabled(): void {
        if ( $this->loaded ) {
            return;
        }
        $this->loaded = true;

        foreach ( $this->full_states() as $slug => $enabled ) {
            if ( ! $enabled ) {
                continue;
            }
            $entry = $this->registry[ $slug ] ?? null;
            if ( ! is_array( $entry ) ) {
                continue;
            }
            self::safe_require_module( $slug, $entry );
        }
    }

    /**
     * Persist a new toggle map and run install callbacks for modules
     * that have just been turned on.
     *
     * @param array<string, bool> $new_states Slug => enabled flag.
     */
    public function update_states( array $new_states ): void {
        // Lifecycle bookkeeping has to see the full registry — never the
        // file-existence-filtered view — so deleting a folder cannot drop
        // the merchant's intent from the option payload.
        $previous = $this->full_states();

        $merged = array();
        foreach ( $this->registry as $slug => $_entry ) {
            $merged[ $slug ] = array_key_exists( $slug, $new_states )
                ? (bool) $new_states[ $slug ]
                : (bool) $previous[ $slug ];
        }

        update_option( self::OPTION, $merged, false );

        // Newly-enabled modules: require the bootstrap so the install
        // callback (which often needs the module's own classes) can run,
        // then invoke the registered installer.
        foreach ( $merged as $slug => $enabled ) {
            if ( ! $enabled ) {
                continue;
            }
            if ( ! empty( $previous[ $slug ] ) ) {
                continue;
            }
            $entry = $this->registry[ $slug ] ?? array();
            if ( ! self::safe_require_module( $slug, $entry ) ) {
                continue;
            }
            self::safe_invoke_callback( $slug, 'install', $entry['install'] ?? null );
        }

        // Newly-disabled modules: require the bootstrap (so the
        // disable callback's classes are autoloadable) then run the
        // registered disable hook. Without this, modules with their
        // own cron / Action Scheduler jobs leak orphan tasks that fire
        // against an un-booted module.
        foreach ( $previous as $slug => $was_enabled ) {
            if ( ! $was_enabled ) {
                continue;
            }
            if ( ! empty( $merged[ $slug ] ) ) {
                continue;
            }
            $entry = $this->registry[ $slug ] ?? array();
            if ( ! self::safe_require_module( $slug, $entry ) ) {
                continue;
            }
            self::safe_invoke_callback( $slug, 'disable', $entry['disable'] ?? null );
        }

        if ( $merged !== $previous ) {
            set_transient( self::FLUSH_RULES_TRANSIENT, '1', HOUR_IN_SECONDS );
        }
    }

    /**
     * Run install for every module whose toggle is currently ON. Used
     * on plugin activation so any module that the merchant has flipped
     * ON before reactivating gets its tables created without a separate
     * "enable" click. With every module defaulting OFF, this is a no-op
     * on a fresh install and only runs on reactivation against a
     * previously-configured site.
     */
    public function install_enabled_modules(): void {
        foreach ( $this->full_states() as $slug => $enabled ) {
            if ( ! $enabled ) {
                continue;
            }
            $entry = $this->registry[ $slug ] ?? array();
            if ( ! self::safe_require_module( $slug, $entry ) ) {
                continue;
            }
            self::safe_invoke_callback( $slug, 'install', $entry['install'] ?? null );
        }
    }

    /**
     * State map computed against the full (unfiltered) registry. Used
     * internally by lifecycle methods so a deleted module folder cannot
     * silently flip the merchant's stored toggle.
     *
     * @return array<string, bool>
     */
    private function full_states(): array {
        return $this->compute_states( $this->registry );
    }

    /**
     * Compute slug => enabled map for the given registry view.
     *
     * @param array<string, array<string, mixed>> $registry
     * @return array<string, bool>
     */
    private function compute_states( array $registry ): array {
        $stored = get_option( self::OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $states = array();
        foreach ( $registry as $slug => $entry ) {
            $states[ $slug ] = array_key_exists( $slug, $stored )
                ? (bool) $stored[ $slug ]
                : (bool) ( $entry['default'] ?? false );
        }
        return $states;
    }

    /**
     * Whether the module's `module.php` bootstrap is on disk and
     * readable right now. The check is run on every read — there is no
     * caching — so an admin restoring a deleted folder takes effect on
     * the very next request without needing a re-save or reactivation.
     *
     * @param array<string, mixed> $entry
     */
    private static function module_file_exists( array $entry ): bool {
        $file = isset( $entry['file'] ) ? (string) $entry['file'] : '';
        return '' !== $file && is_readable( $file );
    }

    /**
     * Require the module's bootstrap defensively. A clean deletion of
     * the module folder is already handled by {@see module_file_exists()},
     * but a *partial* corruption — module.php on disk, but a class file
     * it eagerly references missing (e.g. a half-uploaded module via
     * FTP, a `rm` that hit `src/` but not the bootstrap) — would
     * otherwise surface as a fatal on every request and lock the
     * merchant out of wp-admin where they could disable the broken
     * module. Catch `\Throwable` so a parse error / class-not-found
     * inside the bootstrap is logged and skipped instead of taking
     * the site down.
     *
     * Returns true iff the bootstrap was both present and loaded
     * without throwing; callers should use the return value to gate
     * any follow-up callback invocation (install / disable).
     *
     * @param string                $slug  Module slug, for diagnostics.
     * @param array<string, mixed>  $entry Module registry entry.
     */
    private static function safe_require_module( string $slug, array $entry ): bool {
        if ( ! self::module_file_exists( $entry ) ) {
            return false;
        }
        $file = (string) $entry['file'];
        try {
            require_once $file;
            return true;
        } catch ( \Throwable $e ) {
            self::log_module_failure( $slug, 'bootstrap', $file, $e );
            return false;
        }
    }

    /**
     * Invoke an install or disable callback without letting an
     * exception (or fatal-as-`Error`) escape into the request. The
     * worst that can happen for a partially-broken module is now
     * "the toggle changed but its tables weren't migrated" — never
     * a hard fatal.
     *
     * @param string                $slug     Module slug, for diagnostics.
     * @param string                $stage    'install' or 'disable'.
     * @param callable|string|array|null $callback Registered callback.
     */
    private static function safe_invoke_callback( string $slug, string $stage, $callback ): void {
        if ( null === $callback ) {
            return;
        }
        if ( ! is_callable( $callback ) ) {
            return;
        }
        try {
            call_user_func( $callback );
        } catch ( \Throwable $e ) {
            self::log_module_failure( $slug, $stage, '', $e );
        }
    }

    /**
     * Log a module lifecycle failure when WP_DEBUG is on. Silent in
     * production so a broken third-party-distributed module zip
     * cannot spam the log on every request.
     *
     * Routes through tejcart_log() so the redactor / rotator pipeline
     * applies; direct error_log() would land in php_error.log which on
     * shared hosts may not be isolated per-site (SEC-023).
     */
    private static function log_module_failure( string $slug, string $stage, string $file, \Throwable $e ): void {
        // Audit M-28 (Core F-013): always log install failures
        // (not just under WP_DEBUG) so a broken module's error is
        // visible in tejcart-logs even in production. The WP_DEBUG
        // gate made install errors invisible on production sites.
        if ( ! function_exists( 'tejcart_log' ) ) {
            return;
        }
        tejcart_log(
            sprintf(
                '[Module_Manager] Module "%s" %s failed%s: %s (%s:%d)',
                $slug,
                $stage,
                '' !== $file ? ' loading ' . $file : '',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ),
            'error'
        );
    }
}
