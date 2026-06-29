<?php
/**
 * TejCart Main Singleton Class
 *
 * @package TejCart\Core
 */

declare( strict_types=1 );

namespace TejCart\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main TejCart application class.
 *
 * Implements the singleton pattern to ensure only one instance
 * of the application is running at any time.
 */
final class TejCart {
    /**
     * The single instance of the class.
     *
     * F-CORE-013: typed so PHP 8.2 enforces assignment type and PHPStan
     * infers precisely instead of falling back to untyped inference.
     *
     * @var TejCart|null
     */
    private static ?TejCart $instance = null;

    /**
     * DI Container instance.
     *
     * @var Container
     */
    private $container;

    /**
     * Loader instance for hook registration.
     *
     * @var Loader
     */
    private $loader;

    /**
     * Plugin version.
     *
     * @var string
     */
    public $version = TEJCART_VERSION;

    /**
     * Returns the single instance of this class.
     *
     * @return TejCart
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Replace the process-wide singleton. Test-substitution seam (#1242).
     *
     * Pass null to reset to lazy construction on the next `instance()`
     * call. Tests / DI overrides can hand in a fake TejCart whose
     * container has been pre-bound with different singletons (Logger,
     * Cart, Gateways, ...), letting call sites that reach for
     * `tejcart()` see the fakes.
     *
     * @internal Use in tests and DI overrides only.
     * @param TejCart|null $instance Instance to install, or null to clear.
     */
    public static function set_instance( ?TejCart $instance ): void {
        // Audit H-22 (Core F-006): gate behind a testing constant so
        // production code can't replace the singleton. Tests define
        // TEJCART_TESTING in their bootstrap; without it this is a
        // no-op that logs the attempt.
        if ( ! defined( 'TEJCART_TESTING' ) || ! TEJCART_TESTING ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'set_instance() called outside test context — ignored.', 'warning' );
            }
            return;
        }
        self::$instance = $instance;
    }

    /**
     * Private constructor to prevent direct instantiation.
     */
    private function __construct() {
        $this->init();
    }

    /**
     * Prevent cloning of the instance.
     *
     * @throws \RuntimeException Always.
     */
    private function __clone() {
        throw new \RuntimeException( 'Cannot clone a singleton.' );
    }

    /**
     * Prevent unserializing of the instance.
     *
     * @throws \RuntimeException Always.
     */
    public function __wakeup() {
        throw new \RuntimeException( 'Cannot unserialize a singleton.' );
    }

    /**
     * Prevent unserializing via PHP 8.0+ __unserialize().
     *
     * @param array $data Serialized data.
     * @throws \RuntimeException Always.
     */
    public function __unserialize( array $data ): void {
        throw new \RuntimeException( 'Cannot unserialize a singleton.' );
    }

    /**
     * Initialize the application.
     *
     * Sets up the container, loader, fires the init action, and registers hooks.
     */
    private function init() {
        Performance::init();

        $this->container = new Container();
        $this->loader    = new Loader();

        $this->register_bindings();

        /**
         * Fires when TejCart has been initialized.
         *
         * @param TejCart $tejcart The main TejCart instance.
         */
        do_action( 'tejcart_init', $this );

        $this->init_hooks();
    }

    /**
     * Register default container bindings.
     */
    private function register_bindings() {
        $this->container->singleton( 'logger', static function () {
            return \TejCart\Logging\Logger::instance();
        } );

        $this->container->singleton( 'cart', function () {
            return new \TejCart\Cart\Cart();
        } );

        $this->container->singleton( 'checkout', function () {
            return new \TejCart\Checkout\Checkout();
        } );

        $this->container->singleton( 'gateways', function () {
            $registry = new \TejCart\Gateways\Gateway_Registry();
            $registry->init();
            return $registry;
        } );

        $this->container->singleton( 'emails', function () {
            $manager = new \TejCart\Email\Email_Manager();
            $manager->init();
            return $manager;
        } );

        $this->container->singleton( 'api', function () {
            return new \TejCart\API\REST_API();
        } );

        $this->container->singleton( 'admin', function () {
            $admin = new \TejCart\Admin\Admin();
            $admin->init();
            return $admin;
        } );

        $this->container->singleton( 'frontend', function () {
            $frontend = new \TejCart\Frontend\Frontend();
            $frontend->init();
            return $frontend;
        } );
    }

    /**
     * Register all WordPress hooks.
     */
    private function init_hooks() {
        $this->loader->add_action( 'after_setup_theme', $this, 'register_product_image_sizes', 5 );

        $this->loader->add_action( 'rest_api_init', $this, 'register_rest_routes' );

        $this->register_features();

        $this->container->make( 'emails' );

        $this->loader->add_action( 'init', $this, 'init_gateways', 5 );

        if ( is_admin() && ! wp_doing_ajax() ) {
            $this->container->make( 'admin' );
            $this->loader->add_action( 'admin_init', $this, 'admin_init' );
        }

        if ( is_admin() ) {
            ( new \TejCart\Admin\Setup_Wizard() )->init();
        }
        if ( ! is_admin() || wp_doing_ajax() ) {
            $this->container->make( 'frontend' );
            $this->loader->add_action( 'wp', $this, 'frontend_init' );
        }

        // F-M1 / #935: place-order entry now lives in its own class
        // (testable, single source of truth) instead of an inline
        // closure in bootstrap.
        ( new \TejCart\Checkout\Place_Order_Handler() )->register();

        ( new \TejCart\Checkout\Checkout_AJAX() )->register();

        ( new \TejCart\Cart\Cart_Ajax() )->register();

        add_action(
            'wp_ajax_tejcart_toggle_payment_method',
            array( \TejCart\Admin\Payment_Methods_List::class, 'ajax_toggle_gateway' )
        );

        // The product CSV importer registers its admin-ajax callbacks
        // here rather than inside Admin::init() because the heavy admin
        // boot above is gated behind `is_admin() && ! wp_doing_ajax()` —
        // during the actual admin-ajax.php POST that gate is false and
        // Admin::init() never runs, which means the import callbacks
        // would not be registered and WordPress would fall through to
        // its default `die('0')`. Register the AJAX hooks unconditionally
        // in admin context so both real admin pageloads and admin-ajax
        // requests can reach them. The handlers do their own permission
        // and nonce checks, so this does not widen the attack surface.
        if ( is_admin() ) {
            ( new \TejCart\Admin\Product_Import_Export() )->register_ajax_handlers();
            ( new \TejCart\Admin\Product_Form() )->register_ajax_handlers();
            // F-M5 / #939: admin clear-inconsistency endpoint.
            ( new \TejCart\Order\Refund_Inconsistency_Admin() )->register();
        }

        $this->loader->run();
    }

    /**
     * Initialise payment gateways.
     *
     * Runs on the 'init' action so that rest_url() and translations are
     * available (both depend on $wp_rewrite being set up).
     */
    public function init_gateways() {
        $this->container->make( 'gateways' );
    }

    /**
     * Register product image sizes so the gallery can emit a
     * srcset-backed responsive image set.
     *
     * Registered at `after_setup_theme` priority 5 so themes that want
     * to override any entry (via `tejcart_product_image_sizes` filter
     * at priority 10+) can still do so.
     */
    public function register_product_image_sizes(): void {
        /**
         * Filter the registered product image sizes.
         *
         * Each entry: name => [ width, height, crop ].
         *
         * @param array $sizes
         */
        $sizes = (array) apply_filters(
            'tejcart_product_image_sizes',
            array(
                'tejcart-product-card'    => array( 400,  400,  true ),
                'tejcart-product-main'    => array( 800,  800,  true ),
                'tejcart-product-main-2x' => array( 1600, 1600, true ),
                'tejcart-product-thumb'   => array( 120,  120,  true ),
            )
        );

        foreach ( $sizes as $name => $spec ) {
            if ( ! is_array( $spec ) || count( $spec ) < 2 ) {
                continue;
            }
            add_image_size(
                (string) $name,
                (int) $spec[0],
                (int) $spec[1],
                $spec[2] ?? true
            );
        }
    }

    /**
     * Register every always-on feature class as a container singleton
     * and call its init() method.
     *
     * Replaces the previous block of inline `( new Foo() )->init()` calls
     * (~25 of them, each a hard-coded dependency) with a single
     * filterable map. Tier-2 modules and tests can now substitute any
     * feature by binding their own implementation against the same
     * container key BEFORE this method runs, or by hooking
     * `tejcart_feature_classes` to swap a class without touching core.
     *
     * Insertion order in {@see self::default_feature_bindings()} is the
     * registration order — match the original sequence so any hook
     * priorities that relied on registration order continue to work.
     *
     * @return void
     */
    private function register_features(): void {
        $bindings = $this->default_feature_bindings();

        /**
         * Filter the map of feature container keys to class names.
         *
         * Return false (or remove the key) to skip a feature; assign a
         * different class string to override; insert a new key/class to
         * add an always-on feature owned by an extension.
         *
         * @param array<string, class-string|callable> $bindings
         * @param Container                            $container
         */
        $bindings = (array) apply_filters( 'tejcart_feature_classes', $bindings, $this->container );

        // Per-request context gate (perf). Many always-on features register
        // hooks that can only ever fire in one context — e.g. front-end-only
        // presentation hooks (wp_enqueue_scripts / send_headers /
        // template_redirect / front-end shortcodes) never fire on a wp-admin
        // page render. Constructing and init()-ing such a feature on a request
        // where it is inert wastes an autoload + object construction on every
        // page. We skip those, while leaving the container binding registered
        // so any explicit make() still resolves it lazily.
        $contexts      = $this->feature_contexts();
        $is_admin_view = is_admin() && ! wp_doing_ajax();

        foreach ( $bindings as $key => $factory ) {
            if ( false === $factory ) {
                continue;
            }

            if ( ! $this->container->has( $key ) ) {
                if ( is_callable( $factory ) ) {
                    $this->container->singleton( $key, $factory );
                } elseif ( is_string( $factory ) && class_exists( $factory ) ) {
                    $class = $factory;
                    $this->container->singleton( $key, static function () use ( $class ) {
                        return new $class();
                    } );
                } else {
                    continue;
                }
            }

            // Skip the eager construction + init() of a feature that provably
            // cannot act in the current request context. The binding above is
            // still registered, so a later make( $key ) resolves it lazily.
            // Unknown keys — including any added via the tejcart_feature_classes
            // filter — default to 'all', preserving the prior behaviour so a
            // third-party feature is never silently dropped.
            $context = $contexts[ $key ] ?? 'all';
            if ( 'frontend' === $context && $is_admin_view ) {
                continue;
            }
            if ( 'admin' === $context && ! is_admin() ) {
                continue;
            }

            $instance = $this->container->make( $key );
            if ( is_object( $instance ) && method_exists( $instance, 'init' ) ) {
                $instance->init();
            }
        }
    }

    /**
     * Per-feature request-context classification for {@see register_features()}.
     *
     * Maps a feature container key to the context(s) in which its init() does
     * anything useful, so the bootstrap can skip eagerly constructing a
     * feature that cannot act in the current request:
     *
     *   - 'frontend' — registers ONLY front-end hooks (wp_enqueue_scripts,
     *     send_headers, template_redirect, front-end shortcodes). None of
     *     those fire on a wp-admin page render, so the feature is skipped
     *     there (is_admin() && ! wp_doing_ajax()). It still loads on front-end,
     *     REST, cron, and admin-ajax requests — unchanged from before.
     *   - 'admin'    — registers ONLY admin hooks; skipped on non-admin
     *     requests (mirrors the feature's own is_admin() short-circuit, so
     *     behaviour is identical — we just avoid the wasted construction).
     *
     * Any key NOT listed here defaults to 'all' (loaded in every context),
     * preserving the prior behaviour. ONLY features whose init() was verified
     * to register exclusively single-context hooks appear here; features that
     * register context-agnostic filters, order/cron/login events, or
     * init/option callbacks intentionally stay 'all'.
     *
     * @return array<string, string> Feature key => 'frontend'|'admin'.
     */
    private function feature_contexts(): array {
        return array(
            // Front-end presentation only — inert on a wp-admin page render.
            'features.theme_colors'          => 'frontend', // wp_enqueue_scripts
            'features.security_headers'      => 'frontend', // send_headers
            'features.cache_compatibility'   => 'frontend', // template_redirect
            'features.edge_hydrate'          => 'frontend', // shortcode + wp_enqueue_scripts + template_redirect
            'features.recommendations'       => 'frontend', // wp_enqueue_scripts
            'features.order_reorder'         => 'frontend', // template_redirect
            // Admin only — already self-guards with `if ( ! is_admin() ) return;`.
            'features.deactivation_feedback' => 'admin',
        );
    }

    /**
     * Default feature bindings.
     *
     * Each entry maps a stable container key (for substitution / testing)
     * onto either a class name (instantiated via `new $class()`) or a
     * callable factory. Singletons that own a private constructor (the
     * `::instance()` pattern) use the callable form so the container
     * resolves them through the existing accessor.
     *
     * @return array<string, class-string|callable>
     */
    private function default_feature_bindings(): array {
        return array(
            'features.product_taxonomy'    => \TejCart\Product\Product_Taxonomy::class,
            'features.global_attributes'   => \TejCart\Product\Global_Attributes::class,
            'features.low_stock_alert'     => \TejCart\Product\Low_Stock_Alert::class,
            'features.stock_notifications' => \TejCart\Product\Stock_Notifications::class,
            'features.sales_counter'       => \TejCart\Product\Sales_Counter::class,
            'features.product_reviews'     => \TejCart\Product\Product_Reviews::class,
            'features.review_votes'        => \TejCart\Product\Review_Votes::class,
            'features.product_permalinks'  => \TejCart\Frontend\Product_Permalinks::class,
            // Gutenberg blocks must register in every context: the editor
            // (admin) needs them for the inserter, the frontend needs the
            // render_callback. Registering here — rather than inside the
            // frontend service, which only boots on `! is_admin()` — keeps
            // the blocks available in the block editor.
            'features.blocks'              => \TejCart\Frontend\Blocks\Block_Registry::class,
            'features.api_keys'            => \TejCart\API\API_Keys::class,
            'features.action_scheduler'    => static fn () => Action_Scheduler::instance(),
            'features.outgoing_webhooks'   => static fn () => Outgoing_Webhooks::instance(),
            'features.payment_methods'     => static fn () => \TejCart\Customer\Payment_Methods::instance(),
            'features.guest_order_linker'  => \TejCart\Customer\Guest_Order_Linker::class,
            'features.customer_sync'       => \TejCart\Customer\Customer_Sync::class,
            'features.rfm_scorer'          => \TejCart\Customer\RFM_Scorer::class,
            'features.order_reorder'       => \TejCart\Order\Order_Reorder::class,
            'features.order_cart_cleanup'  => \TejCart\Order\Order_Cart_Cleanup::class,
            'features.order_auto_complete' => \TejCart\Order\Order_Auto_Complete::class,
            'features.order_stock'         => \TejCart\Order\Order_Stock::class,
            'features.order_activity_log'  => \TejCart\Order\Order_Activity_Logger::class,
            'features.coupon_rollback'     => \TejCart\Coupon\Order_Coupon_Rollback::class,
            'features.pay_for_order'       => \TejCart\Checkout\Pay_For_Order::class,
            'features.data_exporter'       => \TejCart\Privacy\Data_Exporter::class,
            'features.data_eraser'         => \TejCart\Privacy\Data_Eraser::class,
            'features.policy_content'      => \TejCart\Privacy\Policy_Content::class,
            'features.account_deletion'    => \TejCart\Customer\Account_Deletion::class,
            'features.account_details'     => \TejCart\Customer\Account_Details::class,
            // Audit #93 / 09 F-014 — self-service GDPR Art. 20 export.
            'features.data_export_request' => \TejCart\Customer\Data_Export_Request::class,
            'features.login_rate_limiter'  => \TejCart\Security\Login_Rate_Limiter::class,
            'features.cache_compatibility' => \TejCart\Frontend\Cache_Compatibility::class,
            // PR #7 of the perf roadmap. Default-OFF; merchants opt in
            // via `tejcart_edge_hydrate_enabled` filter or option. When
            // disabled, the only side effect is registering the
            // `[tejcart_cart_icon]` shortcode (which falls back to a
            // server-rendered cart icon) — safe to ship to existing
            // sites without behaviour change.
            'features.edge_hydrate'        => \TejCart\Frontend\Edge_Hydrate::class,
            'features.theme_colors'        => \TejCart\Frontend\Theme_Colors::class,
            'features.geolocation'         => \TejCart\I18n\Geolocation::class,
            'features.lock_sweeper'        => \TejCart\Core\Lock_Sweeper::class,
            'features.partition_roller'    => \TejCart\Core\Partition_Roller::class,
            'features.security_headers'    => \TejCart\Frontend\Security_Headers::class,
            'features.paypal_webhook'      => static fn () => new \TejCart\Gateways\PayPal\PayPal_Webhook(
                \TejCart\Gateways\PayPal\PayPal_Gateway::get_shared_instance()
            ),
            'features.paypal_event_worker' => \TejCart\Gateways\PayPal\PayPal_Event_Worker::class,
            'features.paypal_reconciler'   => \TejCart\Gateways\PayPal\PayPal_Reconciler::class,
            'features.paypal_webhook_health' => \TejCart\Gateways\PayPal\PayPal_Webhook_Health::class,
            'features.payment_debug_logger' => \TejCart\Logging\Payment_Debug_Logger::class,
            'features.payment_telemetry'    => \TejCart\Logging\Payment_Telemetry_REST::class,
            'features.co_occurrence_index'  => \TejCart\Product\Co_Occurrence_Index::class,
            'features.recommendations'     => \TejCart\Product\Recommendations::class,
            'features.daily_summary'       => \TejCart\Reports\Daily_Summary::class,
            // Bot mitigation (CAPTCHA / Turnstile / hCaptcha / reCAPTCHA) is
            // now the opt-in `captcha` module (modules/captcha/) rather than
            // an always-on core feature. Stores that had a provider already
            // configured are auto-enabled on upgrade by
            // Installer::preserve_legacy_modules().
            'features.gift_wrap'           => \TejCart\Cart\Gift_Wrap::class,
            'features.save_for_later'      => \TejCart\Cart\Save_For_Later::class,
            'features.deactivation_feedback' => \TejCart\Admin\Deactivation_Feedback::class,
        );
    }

    /**
     * Initialize admin-specific functionality.
     */
    public function admin_init() {
        /**
         * Fires when TejCart admin is initialized.
         */
        do_action( 'tejcart_admin_init' );
    }

    /**
     * Initialize frontend-specific functionality.
     */
    public function frontend_init() {
        /**
         * Fires when TejCart frontend is initialized.
         */
        do_action( 'tejcart_frontend_init' );
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        $api = $this->container->make( 'api' );
        $api->register_routes();

        /**
         * Fires when TejCart REST API routes should be registered.
         */
        do_action( 'tejcart_register_rest_routes' );
    }

    /**
     * Get the Cart instance.
     *
     * @return \TejCart\Cart\Cart
     */
    public function cart() {
        return $this->container->make( 'cart' );
    }

    /**
     * Get the Checkout instance.
     *
     * @return \TejCart\Checkout\Checkout
     */
    public function checkout() {
        return $this->container->make( 'checkout' );
    }

    /**
     * Get the Gateway Registry instance.
     *
     * @return \TejCart\Gateways\Gateway_Registry
     */
    public function gateways() {
        return $this->container->make( 'gateways' );
    }

    /**
     * Get the Email Manager instance.
     *
     * @return \TejCart\Email\Email_Manager
     */
    public function emails() {
        return $this->container->make( 'emails' );
    }

    /**
     * Get the REST API instance.
     *
     * @return \TejCart\API\REST_API
     */
    public function api() {
        return $this->container->make( 'api' );
    }

    /**
     * Get the Logger registry.
     *
     * Resolve a per-module channel via `tejcart()->logger()->get( 'payment' )`
     * or the global shortcut `tejcart_logger( 'payment' )`.
     *
     * @return \TejCart\Logging\Logger
     */
    public function logger() {
        return $this->container->make( 'logger' );
    }

    /**
     * Get the DI container.
     *
     * @return Container
     */
    public function container() {
        return $this->container;
    }

    /**
     * Get the hook loader.
     *
     * @return Loader
     */
    public function loader() {
        return $this->loader;
    }
}
