<?php
/**
 * Shipping zone and method manager.
 *
 * @package TejCart\Shipping
 */

declare( strict_types=1 );

namespace TejCart\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages shipping zones and their associated methods.
 *
 * Zones are stored as a JSON array in the tejcart_shipping_zones option.
 * Each zone: {id, name, countries[], methods[]}
 */
class Shipping_Manager {
    /**
     * Loaded shipping zones.
     *
     * @var array
     */
    private $zones = array();

    /**
     * Constructor - loads zones from the database.
     */
    public function __construct() {
        $stored      = get_option( 'tejcart_shipping_zones', '[]' );
        $decoded     = json_decode( $stored, true );
        $this->zones = is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Persist the current zones array back to the database.
     *
     * Autoload=no: multi-country stores with per-postcode zones can
     * reach hundreds of zone entries — keeping this out of `alloptions`
     * eliminates the per-request memory + decode cost on pages that
     * don't touch the cart. The Shipping_Manager constructor still
     * decodes once per request and reuses the result.
     *
     * @return bool
     */
    private function save_zones() {
        return update_option( 'tejcart_shipping_zones', wp_json_encode( $this->zones ), false );
    }

    /**
     * Return all configured shipping zones.
     *
     * @return array
     */
    public function get_zones() {
        return $this->zones;
    }

    /**
     * Retrieve a single shipping zone by its ID.
     *
     * @param int $id Zone ID.
     * @return array|null Zone data or null when not found.
     */
    public function get_zone( $id ) {
        foreach ( $this->zones as $zone ) {
            if ( isset( $zone['id'] ) && (int) $zone['id'] === (int) $id ) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Add a new shipping zone.
     *
     * @param array $data {name, countries[], methods[]}.
     * @return int The new zone ID.
     */
    public function add_zone( $data ) {
        $id = 1;
        foreach ( $this->zones as $zone ) {
            if ( isset( $zone['id'] ) && (int) $zone['id'] >= $id ) {
                $id = (int) $zone['id'] + 1;
            }
        }

        $new_zone = array(
            'id'        => $id,
            'name'      => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
            'countries' => isset( $data['countries'] ) && is_array( $data['countries'] )
                ? array_map( 'sanitize_text_field', $data['countries'] )
                : array(),
            'postcodes' => isset( $data['postcodes'] ) && is_array( $data['postcodes'] )
                ? array_values( array_filter( array_map( array( $this, 'normalize_postcode_rule' ), $data['postcodes'] ) ) )
                : array(),
            'methods'   => isset( $data['methods'] ) && is_array( $data['methods'] )
                ? $data['methods']
                : array(),
        );

        $this->zones[] = $new_zone;
        $this->save_zones();

        return $id;
    }

    /**
     * Update an existing shipping zone.
     *
     * @param int   $id   Zone ID.
     * @param array $data Fields to update.
     * @return bool True on success.
     */
    public function update_zone( $id, $data ) {
        foreach ( $this->zones as $index => $zone ) {
            if ( isset( $zone['id'] ) && (int) $zone['id'] === (int) $id ) {
                if ( isset( $data['name'] ) ) {
                    $this->zones[ $index ]['name'] = sanitize_text_field( $data['name'] );
                }
                if ( isset( $data['countries'] ) && is_array( $data['countries'] ) ) {
                    $this->zones[ $index ]['countries'] = array_map( 'sanitize_text_field', $data['countries'] );
                }
                if ( isset( $data['postcodes'] ) && is_array( $data['postcodes'] ) ) {
                    $this->zones[ $index ]['postcodes'] = array_values( array_filter(
                        array_map( array( $this, 'normalize_postcode_rule' ), $data['postcodes'] )
                    ) );
                }
                if ( isset( $data['methods'] ) && is_array( $data['methods'] ) ) {
                    $this->zones[ $index ]['methods'] = $data['methods'];
                }
                $this->save_zones();
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a shipping zone by ID.
     *
     * @param int $id Zone ID.
     * @return bool True on success.
     */
    public function delete_zone( $id ) {
        foreach ( $this->zones as $index => $zone ) {
            if ( isset( $zone['id'] ) && (int) $zone['id'] === (int) $id ) {
                array_splice( $this->zones, $index, 1 );
                $this->save_zones();
                return true;
            }
        }

        return false;
    }

    /**
     * Find the shipping zone that matches a given address.
     *
     * Matching precedence (first match wins), narrow-to-broad, and within
     * each tier the postcode rule (when one is defined on the zone) must
     * pass:
     *   1. country:state + postcode rule satisfied
     *   2. country-only + postcode rule satisfied
     *
     * A zone whose postcode rule *fails* the customer's postcode is not
     * considered a match at any tier — the admin explicitly scoped it
     * out, and silently promoting the customer to a broader sibling zone
     * (or silently demoting them to a narrower-but-postcode-mismatched
     * zone) would both violate the admin's configuration.
     *
     * Postcode rules support exact match (`90210`), wildcards (`902*`),
     * and inclusive ranges (`90210...90299`).
     *
     * @param string $country  Country code.
     * @param string $state    Optional state code.
     * @param string $postcode Optional postcode / ZIP.
     * @return array|null Matching zone or null.
     */
    public function get_zone_for_address( $country, $state = '', $postcode = '' ) {
        $country_match = null;

        $postcode = strtoupper( preg_replace( '/\s+/', '', (string) $postcode ) );

        foreach ( $this->zones as $zone ) {
            if ( ! isset( $zone['countries'] ) || ! is_array( $zone['countries'] ) ) {
                continue;
            }

            $has_postcode_rules = ! empty( $zone['postcodes'] ) && is_array( $zone['postcodes'] );
            $postcode_ok        = ! $has_postcode_rules || $this->postcode_matches( $postcode, (array) $zone['postcodes'] );

            if ( ! $postcode_ok ) {
                continue;
            }

            foreach ( $zone['countries'] as $entry ) {
                if ( ! empty( $state ) && $entry === $country . ':' . $state ) {
                    return $zone;
                }

                if ( $entry === $country && null === $country_match ) {
                    $country_match = $zone;
                }
            }
        }

        return $country_match;
    }

    /**
     * Get available shipping methods for a given address.
     *
     * @param string    $country Country code.
     * @param string    $state   Optional state code.
     * @param mixed     $cart    Cart instance (passed to method availability checks).
     * @return \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method[] Available method instances.
     */
    public function get_available_methods( $country, $state = '', $cart = null, $postcode = '' ) {
        $zone = $this->get_zone_for_address( $country, $state, $postcode );

        if ( null === $zone || empty( $zone['methods'] ) ) {
            return array();
        }

        $available = array();

        foreach ( $zone['methods'] as $method_config ) {
            $instance = $this->create_method_instance( $method_config );

            if ( null !== $instance && $instance->is_available( $cart ) ) {
                $available[] = $instance;
            }
        }

        /**
         * Filter the array of available shipping method instances for a zone.
         *
         * Sibling plugins use this hook to fan one method instance out into
         * many — e.g. the TejCart Shipping addon turns a single
         * `carrier_fedex` zone-method into one method per FedEx service
         * the carrier API returned (Ground, 2Day, Overnight, ...).
         *
         * Implementations MUST return an array of Abstract_Shipping_Method
         * instances; entries that aren't will be discarded.
         *
         * @param \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method[] $available Already-filtered method instances.
         * @param array                                                          $zone      Matched zone config.
         * @param string                                                         $country   Destination country.
         * @param string                                                         $state     Destination state.
         * @param mixed                                                          $cart      Cart instance (may be null).
         * @param string                                                         $postcode  Destination postcode.
         */
        $filtered = apply_filters(
            'tejcart_shipping_available_methods',
            $available,
            $zone,
            (string) $country,
            (string) $state,
            $cart,
            (string) $postcode
        );

        if ( ! is_array( $filtered ) ) {
            return $this->sort_methods_by_cost( $available, $cart );
        }

        $clean = array();
        foreach ( $filtered as $entry ) {
            if ( $entry instanceof \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method ) {
                $clean[] = $entry;
            }
        }
        return $this->sort_methods_by_cost( $clean, $cart );
    }

    /**
     * Order available methods cheapest-first (the checkout convention).
     *
     * Both the picker template and the cart calculator treat the *first*
     * entry as the default selection, so a price-ascending sort here is
     * what makes "Free / cheapest is pre-selected" true everywhere at
     * once — including the fanned-out carrier services (Shiprocket, FedEx,
     * …) that arrive interleaved with the built-in flat/pickup/free rows.
     *
     * The sort is stable: methods that cost the same keep the merchant's
     * configured zone order. Each method's cost is computed once (not on
     * every comparator call) so carrier rate lookups — already memoised by
     * the addon's Rate_Cache — aren't re-hit during the sort. A method
     * whose cost isn't numeric sinks to the bottom rather than corrupting
     * the order.
     *
     * @param \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method[] $methods Method instances.
     * @param mixed                                                          $cart    Cart instance (may be null).
     * @return \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method[]
     */
    private function sort_methods_by_cost( array $methods, $cart ) {
        /**
         * Filter whether available shipping methods are sorted cheapest-first.
         *
         * Return false to preserve the raw zone-config / carrier-API order
         * (e.g. a merchant who hand-curates the order of their methods).
         *
         * @param bool   $enabled Whether to sort. Default true.
         * @param mixed  $cart    Cart instance (may be null).
         */
        if ( ! apply_filters( 'tejcart_shipping_sort_methods', true, $cart ) ) {
            return $methods;
        }

        if ( count( $methods ) < 2 ) {
            return $methods;
        }

        $decorated = array();
        foreach ( $methods as $index => $method ) {
            $cost = method_exists( $method, 'calculate' ) ? $method->calculate( $cart ) : 0;
            $cost = is_numeric( $cost ) ? (float) $cost : INF;
            $decorated[] = array(
                'cost'   => $cost,
                'index'  => $index,
                'method' => $method,
            );
        }

        usort(
            $decorated,
            static function ( $a, $b ) {
                if ( $a['cost'] === $b['cost'] ) {
                    return $a['index'] <=> $b['index'];
                }
                return $a['cost'] <=> $b['cost'];
            }
        );

        return array_column( $decorated, 'method' );
    }

    /**
     * Calculate shipping cost for a specific method, scoped to the
     * address-matching zone when one is provided.
     *
     * IMPORTANT — callers should pass the customer's destination
     * (`$country`, `$state`, `$postcode`) so the lookup is restricted to
     * the zone that serves that address. Without a destination this
     * method falls back to a cross-zone scan that can return the price
     * of a method from an unrelated zone — retained only for backwards
     * compatibility with integrations built against earlier releases.
     *
     * Prefer `get_available_methods()` in new code: it returns fully
     * instantiated method objects already filtered by availability and
     * by zone, which is the form the cart calculator consumes.
     *
     * @param string $method_id Method identifier (e.g. "flat_rate", "free_shipping").
     * @param mixed  $cart      Cart instance.
     * @param string $country   Optional destination country code.
     * @param string $state     Optional destination state code.
     * @param string $postcode  Optional destination postcode.
     * @return float Shipping cost.
     */
    public function calculate_shipping( $method_id, $cart, $country = '', $state = '', $postcode = '' ) {
        if ( '' !== (string) $country ) {
            $zone = $this->get_zone_for_address( $country, $state, $postcode );
            if ( null !== $zone && ! empty( $zone['methods'] ) ) {
                foreach ( $zone['methods'] as $method_config ) {
                    $config_id = isset( $method_config['id'] ) ? $method_config['id'] : '';
                    if ( $config_id === $method_id ) {
                        $instance = $this->create_method_instance( $method_config );
                        if ( null !== $instance ) {
                            return $instance->calculate( $cart );
                        }
                    }
                }
            }
            return 0.0;
        }

        foreach ( $this->zones as $zone ) {
            if ( empty( $zone['methods'] ) ) {
                continue;
            }

            foreach ( $zone['methods'] as $method_config ) {
                $config_id = isset( $method_config['id'] ) ? $method_config['id'] : '';

                if ( $config_id === $method_id ) {
                    $instance = $this->create_method_instance( $method_config );

                    if ( null !== $instance ) {
                        return $instance->calculate( $cart );
                    }
                }
            }
        }

        return 0.0;
    }

    /**
     * Normalise a single postcode rule for storage.
     *
     * Strips whitespace, upper-cases, and passes through otherwise so
     * wildcard (`902*`) and range (`90210...90299`) forms are preserved.
     *
     * @param string $rule Raw postcode rule.
     * @return string Normalised rule, or empty string if invalid.
     */
    private function normalize_postcode_rule( $rule ): string {
        $rule = strtoupper( preg_replace( '/\s+/', '', (string) $rule ) );
        if ( '' === $rule ) {
            return '';
        }
        if ( ! preg_match( '/^[A-Z0-9\*\-\.]+$/', $rule ) ) {
            return '';
        }
        return $rule;
    }

    /**
     * Check whether a postcode satisfies any rule in the list.
     *
     * Supported rule forms:
     *  - exact:     "90210"
     *  - wildcard:  "902*"
     *  - range:     "90210...90299"
     *
     * @param string $postcode Customer postcode (already normalised).
     * @param array  $rules    Stored rule strings.
     * @return bool
     */
    private function postcode_matches( string $postcode, array $rules ): bool {
        if ( '' === $postcode ) {
            return false;
        }

        foreach ( $rules as $rule ) {
            $rule = strtoupper( preg_replace( '/\s+/', '', (string) $rule ) );
            if ( '' === $rule ) {
                continue;
            }

            if ( false !== strpos( $rule, '...' ) ) {
                [ $low, $high ] = array_pad( explode( '...', $rule, 2 ), 2, '' );
                if ( '' !== $low && '' !== $high ) {
                    // C-M4: only do an integer range comparison when BOTH
                    // bounds AND the postcode are purely numeric. The old
                    // code stripped letters before comparing, which
                    // collapsed alphanumeric ranges (UK "SW1A...SW1Z" →
                    // "1...1", CA "K1A...K9Z" → "1...9") into a wrong
                    // numeric test. For alphanumeric ranges fall back to a
                    // lexicographic comparison on the normalised values,
                    // which is correct for fixed-width alphanumeric codes.
                    if ( ctype_digit( $low ) && ctype_digit( $high ) && ctype_digit( $postcode ) ) {
                        if ( (int) $postcode >= (int) $low && (int) $postcode <= (int) $high ) {
                            return true;
                        }
                    } elseif ( strcmp( $postcode, $low ) >= 0 && strcmp( $postcode, $high ) <= 0 ) {
                        return true;
                    }
                }
                continue;
            }

            if ( false !== strpos( $rule, '*' ) ) {
                if ( fnmatch( $rule, $postcode ) ) {
                    return true;
                }
                continue;
            }

            if ( $rule === $postcode ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a shipping method instance from a config array.
     *
     * @param array $config Method configuration.
     * @return \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method|null
     */
    private function create_method_instance( $config ) {
        $id = isset( $config['id'] ) ? $config['id'] : '';

        $map = array(
            'flat_rate'     => '\\TejCart\\Shipping\\Shipping_Methods\\Flat_Rate',
            'free_shipping' => '\\TejCart\\Shipping\\Shipping_Methods\\Free_Shipping',
            'local_pickup'  => '\\TejCart\\Shipping\\Shipping_Methods\\Local_Pickup',
            'weight_based'  => '\\TejCart\\Shipping\\Shipping_Methods\\Weight_Based',
        );

        /**
         * Filters the shipping method class map.
         *
         * @param array $map Method ID => fully qualified class name.
         */
        $map = apply_filters( 'tejcart_shipping_method_classes', $map );

        if ( ! isset( $map[ $id ] ) || ! class_exists( $map[ $id ] ) ) {
            return null;
        }

        $class    = $map[ $id ];
        $settings = isset( $config['settings'] ) ? $config['settings'] : array();

        $instance = new $class();
        $instance->set_settings( $settings );

        /**
         * Filter the instantiated shipping method instance.
         *
         * Third-party plugins can wrap or substitute the instance — for
         * example, a FedEx live-rate plugin may inject a shared HTTP client
         * or replace the default class with an authenticated subclass.
         *
         * @param \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method $instance Method instance.
         * @param string                                                       $id       Method ID.
         * @param array                                                        $config   Raw method config from the zone.
         */
        $instance = apply_filters( 'tejcart_shipping_method_instance', $instance, $id, $config );

        /**
         * Fires after a shipping method has been instantiated and configured.
         *
         * @param \TejCart\Shipping\Shipping_Methods\Abstract_Shipping_Method $instance Method instance.
         * @param string                                                       $id       Method ID.
         * @param array                                                        $config   Raw method config from the zone.
         */
        do_action( 'tejcart_shipping_method_registered', $instance, $id, $config );

        return $instance;
    }
}
