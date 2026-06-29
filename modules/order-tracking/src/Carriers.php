<?php
/**
 * Carrier registry.
 *
 * Maps carrier slug → label + tracking-URL template. The template uses
 * `{tracking}` as the substitution token. The list is filterable so
 * sibling plugins (or carrier-rate addons) can extend it without touching
 * this class.
 *
 * Per-request memoisation: `apply_filters` runs once per page even with
 * 50 carriers registered.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Carriers {
    /** @var array<string, array{label:string,url:string}>|null */
    private static ?array $cache = null;

    /** @var array<string, string>|null */
    private static ?array $alias_cache = null;

    /**
     * Built-in carrier table. Order matches the most-common-first heuristic
     * used by the admin "Carrier" select.
     *
     * @return array<string, array{label:string,url:string}>
     */
    private static function built_ins(): array {
        return array(
            'usps'        => array( 'label' => 'USPS',          'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking}' ),
            'ups'         => array( 'label' => 'UPS',           'url' => 'https://www.ups.com/track?tracknum={tracking}' ),
            'fedex'       => array( 'label' => 'FedEx',         'url' => 'https://www.fedex.com/fedextrack/?trknbr={tracking}' ),
            'dhl'         => array( 'label' => 'DHL',           'url' => 'https://www.dhl.com/en/express/tracking.html?AWB={tracking}' ),
            'dhl_ecommerce' => array( 'label' => 'DHL eCommerce', 'url' => 'https://webtrack.dhlglobalmail.com/?trackingnumber={tracking}' ),
            'royal_mail' => array( 'label' => 'Royal Mail',     'url' => 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking}' ),
            'canada_post' => array( 'label' => 'Canada Post',    'url' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/details/{tracking}' ),
            'australia_post' => array( 'label' => 'Australia Post', 'url' => 'https://auspost.com.au/mypost/track/#/details/{tracking}' ),
            'aramex'      => array( 'label' => 'Aramex',        'url' => 'https://www.aramex.com/track/results?ShipmentNumber={tracking}' ),
            'sf_express'  => array( 'label' => 'SF Express',    'url' => 'https://htm.sf-express.com/us/en/dynamic_function/waybill/#search/bill-number/{tracking}' ),
            'china_post'  => array( 'label' => 'China Post',    'url' => 'http://track-chinapost.com/result_china.php?nums={tracking}' ),
            'other'       => array( 'label' => 'Other',         'url' => '' ),
        );
    }

    /**
     * Return the filtered carrier registry.
     *
     * @return array<string, array{label:string,url:string}>
     */
    public static function all(): array {
        if ( null !== self::$cache ) {
            return self::$cache;
        }

        /**
         * Filter the carrier registry.
         *
         * @param array<string, array{label:string,url:string}> $carriers
         */
        $filtered = (array) apply_filters( 'tejcart_order_tracking_carriers', self::built_ins() );

        $clean = array();
        foreach ( $filtered as $slug => $entry ) {
            $slug = sanitize_key( (string) $slug );
            if ( '' === $slug || ! is_array( $entry ) ) {
                continue;
            }
            $clean[ $slug ] = array(
                'label' => isset( $entry['label'] ) ? (string) $entry['label'] : strtoupper( $slug ),
                'url'   => isset( $entry['url'] )   ? (string) $entry['url']   : '',
            );
        }

        self::$cache = $clean;
        return self::$cache;
    }

    /**
     * Reset the per-request cache. Used by tests.
     */
    public static function flush_cache(): void {
        self::$cache       = null;
        self::$alias_cache = null;
    }

    public static function exists( string $slug ): bool {
        return isset( self::all()[ $slug ] );
    }

    /**
     * Default carrier-slug aliases.
     *
     * Different parts of the system (shipping drivers, tracking providers,
     * external integrations) sometimes spell the same physical carrier
     * differently — e.g. the bundled shipping driver uses `dhl_express`
     * while order-tracking and EasyPost both expect `dhl`. The alias map
     * collapses these spellings to a single canonical slug before storage
     * and lookups so a webhook arriving for one variant still resolves
     * against a shipment row stored under the other.
     *
     * Filterable so sites with bespoke shipping integrations can teach
     * the system new aliases without forking core.
     *
     * @return array<string, string>
     */
    private static function aliases(): array {
        if ( null !== self::$alias_cache ) {
            return self::$alias_cache;
        }
        $defaults = array(
            'dhl_express'  => 'dhl',
            'dhl-express'  => 'dhl',
            'dhlexpress'   => 'dhl',
            'dhl_ecom'     => 'dhl_ecommerce',
            'dhle'         => 'dhl_ecommerce',
            'fedex_ground' => 'fedex',
            'ups_ground'   => 'ups',
            'usps_priority' => 'usps',
        );
        /**
         * Filter the carrier-slug alias table.
         *
         * Map of `incoming_slug => canonical_slug`. Used by
         * {@see Carriers::normalize_slug()} to fold shipping-side carrier
         * spellings, provider-supplied codes, and admin free-text into a
         * single canonical slug per physical carrier.
         *
         * @param array<string, string> $aliases
         */
        $filtered = (array) apply_filters( 'tejcart_order_tracking_carrier_aliases', $defaults );
        $clean    = array();
        foreach ( $filtered as $from => $to ) {
            $from = sanitize_key( (string) $from );
            $to   = sanitize_key( (string) $to );
            if ( '' === $from || '' === $to || $from === $to ) {
                continue;
            }
            $clean[ $from ] = $to;
        }
        self::$alias_cache = $clean;
        return self::$alias_cache;
    }

    /**
     * Collapse a carrier slug to its canonical form.
     *
     * Returns the input untouched (after `sanitize_key`) when it is
     * either already canonical or unrecognised — callers can still feed
     * the result to {@see exists()} to decide whether to accept it.
     */
    public static function normalize_slug( string $slug ): string {
        $slug = sanitize_key( $slug );
        if ( '' === $slug ) {
            return $slug;
        }
        $aliases = self::aliases();
        return $aliases[ $slug ] ?? $slug;
    }

    public static function label( string $slug ): string {
        $carriers = self::all();
        return $carriers[ $slug ]['label'] ?? strtoupper( $slug );
    }

    /**
     * Build the public tracking URL for a (carrier, tracking_number) pair.
     *
     * Returns '' for carriers without a URL template (e.g. "other") so
     * callers can suppress the link.
     */
    public static function build_url( string $carrier, string $tracking_number ): string {
        $carriers = self::all();
        if ( '' === $tracking_number || empty( $carriers[ $carrier ]['url'] ) ) {
            return '';
        }
        return str_replace( '{tracking}', rawurlencode( $tracking_number ), $carriers[ $carrier ]['url'] );
    }
}
