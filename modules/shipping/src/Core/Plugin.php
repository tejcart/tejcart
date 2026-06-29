<?php
/**
 * Plugin singleton.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

use TejCart\Shipping_Plugin\Admin\Carrier_State;
use TejCart\Shipping_Plugin\Admin\Order_Shipments_Panel;
use TejCart\Shipping_Plugin\Admin\Settings_Page;
use TejCart\Shipping_Plugin\Carriers\APAC\Australia_Post\Australia_Post_Driver;
use TejCart\Shipping_Plugin\Carriers\APAC\Delhivery\Delhivery_Driver;
use TejCart\Shipping_Plugin\Carriers\APAC\Sendle\Sendle_Driver;
use TejCart\Shipping_Plugin\Carriers\APAC\Shiprocket\Shiprocket_Driver;
use TejCart\Shipping_Plugin\Carriers\Aggregators\EasyPost\EasyPost_Driver;
use TejCart\Shipping_Plugin\Carriers\Aggregators\Shippo\Shippo_Driver;
use TejCart\Shipping_Plugin\Carriers\Europe\DPD\DPD_Driver;
use TejCart\Shipping_Plugin\Carriers\Europe\Evri\Evri_Driver;
use TejCart\Shipping_Plugin\Carriers\Europe\Royal_Mail\Royal_Mail_Driver;
use TejCart\Shipping_Plugin\Carriers\Global\DHL_Express\DHL_Express_Driver;
use TejCart\Shipping_Plugin\Carriers\Global\FedEx\FedEx_Driver;
use TejCart\Shipping_Plugin\Carriers\Global\UPS\UPS_Driver;
use TejCart\Shipping_Plugin\Carriers\NorthAmerica\Canada_Post\Canada_Post_Driver;
use TejCart\Shipping_Plugin\Carriers\NorthAmerica\USPS\USPS_Driver;
use TejCart\Shipping_Plugin\Shipping_Methods\Carrier_Driven_Method;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {
    private static ?Plugin $instance = null;

    private Carrier_Registry $registry;
    private HTTP_Client $http;
    private Rate_Cache $cache;
    private Credentials_Vault $vault;
    private Carrier_State $state;
    private Selected_Quote_Registry $selected_quotes;
    private Shipment_Repository $shipments;
    private Label_Service $label_service;
    private bool $booted = false;

    private function __construct() {}

    public function __clone() {
        throw new \LogicException( 'Cannot clone the TejCart Shipping plugin singleton.' );
    }

    public function __wakeup(): void {
        throw new \LogicException( 'Cannot unserialize the TejCart Shipping plugin singleton.' );
    }

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Boot the plugin against an initialised TejCart core instance.
     *
     * Idempotent — safe to call multiple times; subsequent calls are no-ops.
     *
     * @param mixed $core The TejCart core singleton (typed as mixed to avoid
     *                    a hard class-existence dependency at autoload time).
     */
    public function boot( $core ): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        $this->http            = new HTTP_Client();
        $this->cache           = new Rate_Cache();
        $this->vault           = new Credentials_Vault();
        $this->state           = new Carrier_State();
        $this->registry        = new Carrier_Registry();
        $this->selected_quotes = new Selected_Quote_Registry();

        $this->register_built_in_drivers();

        /**
         * Fires after built-in drivers are registered, before any settings
         * are read. Third-party plugins should register their own
         * carrier drivers on this hook.
         *
         * @param Carrier_Registry $registry
         */
        do_action( 'tejcart_shipping_register_drivers', $this->registry );

        add_filter( 'tejcart_shipping_method_classes', array( $this, 'inject_carrier_methods' ) );
        add_filter( 'tejcart_shipping_method_instance', array( $this, 'configure_carrier_instance' ), 10, 3 );
        add_filter( 'tejcart_shipping_available_methods', array( $this, 'fan_out_carrier_services' ), 10, 6 );
        add_filter( 'tejcart_shipping_zone_method_type_labels', array( $this, 'inject_carrier_method_labels' ) );

        $this->shipments     = new Shipment_Repository( new Schema() );
        $this->label_service = new Label_Service( $this->registry, $this->vault, $this->shipments );

        ( new Webhook_Router() )->register();
        ( new Tracking_Webhook_Handlers( $this->shipments ) )->register( $this->vault );
        ( new Tracking_Poller( $this->label_service ) )->register();
        ( new Order_Integration( $this->selected_quotes ) )->register();

        if ( is_admin() ) {
            ( new Settings_Page( $this->registry, $this->vault, $this->state ) )->register();
            ( new Order_Shipments_Panel( $this->registry, $this->shipments, $this->label_service ) )->register();
        }
    }

    /**
     * Expand each Carrier_Driven_Method that has no explicit service_code
     * into one method per service the carrier returned for the cart.
     *
     * Core's Shipping_Manager treats the array as opaque, so the fan-out
     * is fully transparent — the cart calculator sees N distinct methods
     * (e.g. "FedEx Ground", "FedEx 2Day", "FedEx Overnight") instead of a
     * single "Carrier FedEx" entry collapsed to the cheapest service.
     *
     * @param mixed[]               $methods
     * @param array<string,mixed>   $zone
     * @param string                $country
     * @param string                $state
     * @param mixed                 $cart
     * @param string                $postcode
     * @return mixed[]
     */
    public function fan_out_carrier_services( $methods, $zone, $country, $state, $cart, $postcode ) {
        if ( ! is_array( $methods ) ) {
            return $methods;
        }

        $expanded = array();

        foreach ( $methods as $method ) {
            if ( ! ( $method instanceof Carrier_Driven_Method ) ) {
                $expanded[] = $method;
                continue;
            }

            if ( '' !== $method->get_service_code() ) {
                $expanded[] = $method;
                continue;
            }

            $quotes = $method->quotes_for_cart( $cart );
            if ( array() === $quotes ) {
                continue;
            }

            $driver_id = $method->get_driver_id();
            $driver    = '' === $driver_id ? null : $this->registry->get( $driver_id );
            if ( null === $driver ) {
                $expanded[] = $method;
                continue;
            }

            $quotes = $this->curate_quotes( $quotes, $driver_id );

            foreach ( $quotes as $quote ) {
                $clone = new Carrier_Driven_Method();
                $clone->bind( $driver, $this->vault, $this->cache, $this->selected_quotes, $this->state );
                $clone->set_service(
                    $quote->service_code,
                    $driver->label() . ' — ' . $quote->service_label,
                    'carrier_' . $driver_id . ':' . $quote->service_code
                );
                // Surface the carrier's estimated delivery on the checkout
                // row. `eta_days` is parsed by every driver that returns
                // one; `etd` is the literal date string (Shiprocket).
                $clone->set_eta(
                    $quote->eta_days,
                    isset( $quote->meta['etd'] ) ? (string) $quote->meta['etd'] : ''
                );
                $expanded[] = $clone;
            }
        }

        return $expanded;
    }

    /**
     * Curate a carrier's raw quote list before it fans out into one
     * checkout row per service.
     *
     * Aggregators like Shiprocket happily return a dozen+ couriers for a
     * single shipment, in no meaningful order. Dropping all of them into
     * the picker buries the merchant's own flat/pickup/free rows and
     * paralyses the buyer with choice. Gold-standard checkouts surface a
     * small, price-sorted set instead — so we sort the carrier's quotes
     * cheapest-first and keep at most N (default 3). The downstream
     * Shipping_Manager sort then interleaves these with the built-in
     * methods by price across the whole list.
     *
     * @param Rate_Quote[] $quotes    The carrier's quotes for this cart.
     * @param string       $driver_id Carrier id (e.g. `shiprocket`).
     * @return Rate_Quote[]
     */
    private function curate_quotes( array $quotes, string $driver_id ): array {
        usort(
            $quotes,
            static fn ( Rate_Quote $a, Rate_Quote $b ): int => $a->cost_cents <=> $b->cost_cents
        );

        /**
         * Filter the maximum number of services shown per carrier at checkout.
         *
         * Applied after the carrier's quotes are sorted cheapest-first, so
         * the cap keeps the lowest-cost services. Return 0 (or a negative
         * number) to show every service the carrier returned.
         *
         * @param int    $max       Maximum services to display. Default 3.
         * @param string $driver_id Carrier id (e.g. `shiprocket`).
         */
        $max = (int) apply_filters( 'tejcart_shipping_max_rates_per_carrier', 3, $driver_id );

        if ( $max > 0 && count( $quotes ) > $max ) {
            $quotes = array_slice( $quotes, 0, $max );
        }

        return $quotes;
    }

    public function registry(): Carrier_Registry {
        return $this->registry;
    }

    public function http(): HTTP_Client {
        return $this->http;
    }

    public function cache(): Rate_Cache {
        return $this->cache;
    }

    public function vault(): Credentials_Vault {
        return $this->vault;
    }

    public function state(): Carrier_State {
        return $this->state;
    }

    public function selected_quotes(): Selected_Quote_Registry {
        return $this->selected_quotes;
    }

    public function shipments(): Shipment_Repository {
        return $this->shipments;
    }

    public function label_service(): Label_Service {
        return $this->label_service;
    }

    /**
     * Append one synthetic shipping-method class per registered driver to
     * the core method-class map so merchants can drop carriers into zones.
     *
     * @param array<string,string> $map
     * @return array<string,string>
     */
    public function inject_carrier_methods( array $map ): array {
        foreach ( $this->registry->all() as $driver ) {
            $map[ 'carrier_' . $driver->id() ] = Carrier_Driven_Method::class;
        }
        return $map;
    }

    /**
     * Provide friendly labels for the carrier method ids surfaced in the
     * core shipping-zone admin dropdown, replacing the auto-derived
     * `Shiprocket` (from `carrier_shiprocket`) with each driver's
     * canonical `label()` (`Shiprocket`, `EasyPost`, `DHL Express`, …).
     *
     * Only carriers a merchant has toggled ON in `Settings → Shipping
     * → Carriers` are surfaced — disabled carriers are explicitly
     * removed from the labels map so the dropdown stays focused on
     * what's actually quotable. The synthetic `tejcart_shipping_method_classes`
     * map still registers every driver (so existing zone rows that
     * reference a now-disabled carrier remain valid until the merchant
     * re-saves), but the editor's selector hides them.
     *
     * @param mixed $labels Existing labels map (id => label string).
     * @return array<string,string>
     */
    public function inject_carrier_method_labels( $labels ): array {
        $out = is_array( $labels ) ? $labels : array();
        foreach ( $this->registry->all() as $driver ) {
            $key = 'carrier_' . $driver->id();
            if ( $this->state->is_enabled( $driver->id() ) ) {
                $out[ $key ] = $driver->label();
            } else {
                unset( $out[ $key ] );
            }
        }
        return $out;
    }

    /**
     * Bind the registry/vault into a Carrier_Driven_Method right after the
     * core Shipping_Manager instantiates it (the core class has no DI hook,
     * so we wire dependencies via the `tejcart_shipping_method_instance`
     * filter instead).
     *
     * @param mixed  $instance
     * @param string $id
     * @param array  $config
     * @return mixed
     */
    public function configure_carrier_instance( $instance, string $id, array $config ) {
        if ( ! $instance instanceof Carrier_Driven_Method ) {
            return $instance;
        }

        if ( 0 !== strpos( $id, 'carrier_' ) ) {
            return $instance;
        }

        $driver_id = substr( $id, strlen( 'carrier_' ) );
        $driver    = $this->registry->get( $driver_id );

        if ( null === $driver ) {
            return $instance;
        }

        $instance->bind( $driver, $this->vault, $this->cache, $this->selected_quotes, $this->state );
        return $instance;
    }

    private function register_built_in_drivers(): void {
        $oauth = new OAuth_Token_Cache( $this->http );

        $this->registry->register( new EasyPost_Driver( $this->http ) );
        $this->registry->register( new Shippo_Driver( $this->http ) );
        $this->registry->register( new USPS_Driver( $this->http, $oauth ) );
        $this->registry->register( new UPS_Driver( $this->http, $oauth ) );
        $this->registry->register( new FedEx_Driver( $this->http, $oauth ) );
        $this->registry->register( new DHL_Express_Driver( $this->http ) );
        $this->registry->register( new Canada_Post_Driver( $this->http ) );
        $this->registry->register( new Royal_Mail_Driver( $this->http ) );
        $this->registry->register( new Evri_Driver( $this->http ) );
        $this->registry->register( new DPD_Driver( $this->http ) );
        $this->registry->register( new Australia_Post_Driver( $this->http ) );
        $this->registry->register( new Sendle_Driver( $this->http ) );
        $this->registry->register( new Shiprocket_Driver( $this->http ) );
        $this->registry->register( new Delhivery_Driver( $this->http ) );
    }
}
