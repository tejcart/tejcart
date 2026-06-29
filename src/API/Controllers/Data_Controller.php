<?php
/**
 * Data REST API Controller — countries, currencies, continents.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\API\REST_Rate_Limit;
use TejCart\Tax\Tax_Manager;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for /tejcart/v1/data/{countries|currencies|continents}.
 *
 * Provides static reference data so headless storefronts can populate
 * country / state / currency dropdowns without bundling their own
 * datasets.
 *
 * Read endpoints are public (no auth) but per-IP rate-limited so an
 * unauthenticated client cannot use them as an amplification vector.
 */
class Data_Controller extends WP_REST_Controller {
    use REST_Rate_Limit;
    /**
     * Route namespace.
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     */
    protected $rest_base = 'data';

    /**
     * Register routes.
     */
    public function register_routes(): void {
        $namespace = $this->namespace;
        $base      = $this->rest_base;

        $public = function ( $request ) {
            return $this->enforce_rest_rate_limit( $request, 'data', 60 );
        };

        register_rest_route( $namespace, '/' . $base, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_index' ),
                'permission_callback' => $public,
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/countries', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_countries' ),
                'permission_callback' => $public,
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/countries/(?P<code>[A-Za-z]{2})', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_country' ),
                'permission_callback' => $public,
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/currencies', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_currencies' ),
                'permission_callback' => $public,
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/currencies/current', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_current_currency' ),
                'permission_callback' => $public,
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/currencies/(?P<code>[A-Za-z]{3})', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_currency' ),
                'permission_callback' => $public,
            ),
        ) );

        register_rest_route( $namespace, '/' . $base . '/continents', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_continents' ),
                'permission_callback' => $public,
            ),
        ) );
    }

    public function get_index( WP_REST_Request $request ) {
        return rest_ensure_response( array(
            array( 'slug' => 'countries',  'description' => __( 'Countries with optional states.', 'tejcart' ) ),
            array( 'slug' => 'currencies', 'description' => __( 'Supported currencies.', 'tejcart' ) ),
            array( 'slug' => 'continents', 'description' => __( 'Continents with member countries.', 'tejcart' ) ),
        ) );
    }

    public function get_countries( WP_REST_Request $request ) {
        $items = array();
        foreach ( Tax_Manager::get_countries() as $code => $name ) {
            $items[] = $this->format_country( $code, $name );
        }
        return rest_ensure_response( $items );
    }

    public function get_country( WP_REST_Request $request ) {
        $code      = strtoupper( (string) $request['code'] );
        $countries = Tax_Manager::get_countries();
        if ( ! isset( $countries[ $code ] ) ) {
            return new WP_Error( 'tejcart_rest_country_not_found', __( 'Country not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return rest_ensure_response( $this->format_country( $code, $countries[ $code ] ) );
    }

    public function get_currencies( WP_REST_Request $request ) {
        $items = array();
        foreach ( $this->currency_dataset() as $code => $row ) {
            $items[] = array(
                'code'   => $code,
                'name'   => $row['name'],
                'symbol' => $row['symbol'],
            );
        }
        return rest_ensure_response( $items );
    }

    public function get_currency( WP_REST_Request $request ) {
        $code     = strtoupper( (string) $request['code'] );
        $dataset  = $this->currency_dataset();
        if ( ! isset( $dataset[ $code ] ) ) {
            return new WP_Error( 'tejcart_rest_currency_not_found', __( 'Currency not found.', 'tejcart' ), array( 'status' => 404 ) );
        }
        return rest_ensure_response( array(
            'code'   => $code,
            'name'   => $dataset[ $code ]['name'],
            'symbol' => $dataset[ $code ]['symbol'],
        ) );
    }

    public function get_current_currency( WP_REST_Request $request ) {
        $code    = function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : (string) get_option( 'tejcart_currency', 'USD' );
        $dataset = $this->currency_dataset();
        $row     = $dataset[ $code ] ?? array( 'name' => $code, 'symbol' => $code );
        return rest_ensure_response( array(
            'code'   => $code,
            'name'   => $row['name'],
            'symbol' => $row['symbol'],
        ) );
    }

    public function get_continents( WP_REST_Request $request ) {
        $countries = Tax_Manager::get_countries();
        $continents = $this->continent_map();

        $by_continent = array();
        foreach ( $countries as $code => $name ) {
            $continent = $continents[ $code ] ?? 'OT';
            if ( ! isset( $by_continent[ $continent ] ) ) {
                $by_continent[ $continent ] = array();
            }
            $by_continent[ $continent ][] = $this->format_country( $code, $name );
        }

        $continent_names = array(
            'AF' => 'Africa',
            'AN' => 'Antarctica',
            'AS' => 'Asia',
            'EU' => 'Europe',
            'NA' => 'North America',
            'OC' => 'Oceania',
            'SA' => 'South America',
            'OT' => 'Other',
        );

        $items = array();
        foreach ( $continent_names as $code => $name ) {
            if ( empty( $by_continent[ $code ] ) ) {
                continue;
            }
            $items[] = array(
                'code'      => $code,
                'name'      => $name,
                'countries' => $by_continent[ $code ],
            );
        }

        return rest_ensure_response( $items );
    }

    /**
     * Format a country, with states populated where known.
     */
    protected function format_country( string $code, string $name ): array {
        $states = array();
        foreach ( Tax_Manager::get_states( $code ) as $state_code => $state_name ) {
            $states[] = array(
                'code' => $state_code,
                'name' => $state_name,
            );
        }
        return array(
            'code'   => $code,
            'name'   => $name,
            'states' => $states,
        );
    }

    /**
     * Currency code => {name, symbol}. Delegates to the canonical catalogue in
     * {@see \TejCart\Money\Currencies::get_currencies()} so the dropdown, the
     * `tejcart_get_currency_symbol()` formatter, and this REST endpoint stay
     * in sync.
     */
    protected function currency_dataset(): array {
        return \TejCart\Money\Currencies::get_currencies();
    }

    /**
     * Country code => continent code map for the supported country list.
     */
    protected function continent_map(): array {
        return array(
            'AF' => 'AS', 'AR' => 'SA', 'AU' => 'OC', 'AT' => 'EU', 'BE' => 'EU',
            'BR' => 'SA', 'CA' => 'NA', 'CL' => 'SA', 'CN' => 'AS', 'CO' => 'SA',
            'CZ' => 'EU', 'DK' => 'EU', 'EG' => 'AF', 'FI' => 'EU', 'FR' => 'EU',
            'DE' => 'EU', 'GR' => 'EU', 'HK' => 'AS', 'HU' => 'EU', 'IN' => 'AS',
            'ID' => 'AS', 'IE' => 'EU', 'IL' => 'AS', 'IT' => 'EU', 'JP' => 'AS',
            'KR' => 'AS', 'MY' => 'AS', 'MX' => 'NA', 'NL' => 'EU', 'NZ' => 'OC',
            'NG' => 'AF', 'NO' => 'EU', 'PK' => 'AS', 'PE' => 'SA', 'PH' => 'AS',
            'PL' => 'EU', 'PT' => 'EU', 'RO' => 'EU', 'RU' => 'EU', 'SA' => 'AS',
            'SG' => 'AS', 'ZA' => 'AF', 'ES' => 'EU', 'SE' => 'EU', 'CH' => 'EU',
            'TW' => 'AS', 'TH' => 'AS', 'TR' => 'AS', 'GB' => 'EU', 'US' => 'NA',
        );
    }
}
