<?php
/**
 * Canada Post direct driver.
 *
 * Uses the Canada Post Sell Online "Get Rates" API. The endpoint
 * speaks XML (Content-Type vnd.cpc.ship.rate-v4+xml) and authentication
 * is HTTP Basic with the merchant's API username/password issued by
 * Canada Post Developer Program.
 *
 * API reference: https://www.canadapost-postescanada.ca/info/mc/business/productsservices/developers/services/rating/getrates/default.jsf
 *
 * @package TejCart\Shipping_Plugin\Carriers\NorthAmerica\Canada_Post
 */

namespace TejCart\Shipping_Plugin\Carriers\NorthAmerica\Canada_Post;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Canada_Post_Driver extends Abstract_Carrier_Driver {
    public const RATES_URL_LIVE = 'https://soa-gw.canadapost.ca/rs/ship/price';
    public const RATES_URL_TEST = 'https://ct.soa-gw.canadapost.ca/rs/ship/price';
    public const SHIP_URL_LIVE  = 'https://soa-gw.canadapost.ca/rs/{customer}/ncshipment';
    public const SHIP_URL_TEST  = 'https://ct.soa-gw.canadapost.ca/rs/{customer}/ncshipment';
    public const TRACK_URL_LIVE = 'https://soa-gw.canadapost.ca/vis/track/pin/';
    public const TRACK_URL_TEST = 'https://ct.soa-gw.canadapost.ca/vis/track/pin/';

    public function id(): string {
        return 'canada_post';
    }

    public function label(): string {
        return 'Canada Post';
    }

    public function region(): string {
        return 'north_america';
    }

    public function credential_fields(): array {
        return array(
            'api_username' => array( 'type' => 'text',     'title' => __( 'API username', 'tejcart' ), 'secret' => false ),
            'api_password' => array( 'type' => 'password', 'title' => __( 'API password', 'tejcart' ), 'secret' => true ),
            'customer_number' => array(
                'type'        => 'text',
                'title'       => __( 'Customer number', 'tejcart' ),
                'description' => __( 'Canada Post customer number (mailed-by).', 'tejcart' ),
                'secret'      => false,
            ),
            'environment' => array(
                'type'    => 'select',
                'title'   => __( 'Environment', 'tejcart' ),
                'options' => array( 'live' => 'Live (production)', 'test' => 'Development' ),
                'default' => 'test',
                'secret'  => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $username = trim( (string) ( $credentials['api_username'] ?? '' ) );
        $password = trim( (string) ( $credentials['api_password'] ?? '' ) );
        if ( '' === $username || '' === $password ) {
            throw new Carrier_Exception( 'Canada Post: missing api_username or api_password credential.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = $is_test ? self::RATES_URL_TEST : self::RATES_URL_LIVE;

        try {
            $response = $this->http->request( 'POST', $url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
                    'Content-Type'  => 'application/vnd.cpc.ship.rate-v4+xml',
                    'Accept'        => 'application/vnd.cpc.ship.rate-v4+xml',
                ),
                'body' => $this->build_rates_xml( $request, $credentials ),
            ) );
        } catch ( Carrier_Exception $e ) {
            return array();
        }

        if ( $response['status'] >= 400 ) {
            return array();
        }

        return $this->parse_rates_response( $response['body'] );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function build_rates_xml( Rate_Request $request, array $credentials ): string {
        $package         = $request->packages[0];
        $customer_number = (string) ( $credentials['customer_number'] ?? '' );

        $weight_kg  = round( $package->weight_grams / 1000.0, 3 );
        $length_cm  = round( $package->length_mm / 10.0, 1 );
        $width_cm   = round( $package->depth_mm / 10.0, 1 );
        $height_cm  = round( $package->height_mm / 10.0, 1 );

        $origin_pc      = $this->normalise_postcode( $request->origin['postcode'] ?? '' );
        $destination_pc = $this->normalise_postcode( $request->destination['postcode'] ?? '' );
        $destination_cc = strtoupper( $request->destination['country'] ?? '' );

        $destination_xml = 'CA' === $destination_cc
            ? '<domestic><postal-code>' . $this->xml_escape( $destination_pc ) . '</postal-code></domestic>'
            : ( 'US' === $destination_cc
                ? '<united-states><zip-code>' . $this->xml_escape( $destination_pc ) . '</zip-code></united-states>'
                : '<international><country-code>' . $this->xml_escape( $destination_cc ) . '</country-code></international>'
            );

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<mailing-scenario xmlns="http://www.canadapost.ca/ws/ship/rate-v4">'
                . '<customer-number>' . $this->xml_escape( $customer_number ) . '</customer-number>'
                . '<parcel-characteristics>'
                    . '<weight>' . $weight_kg . '</weight>'
                    . '<dimensions>'
                        . '<length>' . $length_cm . '</length>'
                        . '<width>' . $width_cm . '</width>'
                        . '<height>' . $height_cm . '</height>'
                    . '</dimensions>'
                . '</parcel-characteristics>'
                . '<origin-postal-code>' . $this->xml_escape( $origin_pc ) . '</origin-postal-code>'
                . '<destination>' . $destination_xml . '</destination>'
            . '</mailing-scenario>';
    }

    /**
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body ): array {
        if ( '' === trim( $body ) ) {
            return array();
        }

        $previous = libxml_use_internal_errors( true );
        $xml      = simplexml_load_string( $body );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( false === $xml ) {
            return array();
        }

        $namespaces = $xml->getDocNamespaces( true );
        $default    = $namespaces[''] ?? '';
        if ( '' !== $default ) {
            $xml->registerXPathNamespace( 'cp', $default );
            $services = $xml->xpath( '//cp:price-quote' ) ?: array();
        } else {
            $services = $xml->xpath( '//price-quote' ) ?: array();
        }

        $quotes = array();
        foreach ( $services as $node ) {
            $code  = (string) ( $node->{'service-code'} ?? '' );
            $name  = (string) ( $node->{'service-name'} ?? '' );
            $price = (string) ( $node->{'price-details'}->{'due'} ?? '' );

            if ( '' === $code || '' === $price ) {
                continue;
            }

            // CAD is 2-decimal; route through Currency::to_minor_units()
            // for codebase consistency and banker's rounding.
            $cents = \TejCart\Money\Currency::to_minor_units( $price, 'CAD' );
            $eta   = null;
            if ( isset( $node->{'service-standard'}->{'expected-transit-time'} ) ) {
                $eta = (int) $node->{'service-standard'}->{'expected-transit-time'};
            }

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $code,
                service_label: '' !== $name ? $name : ( 'Canada Post ' . $code ),
                cost_cents:    $cents,
                currency:      'CAD',
                eta_days:      $eta,
                rate_id:       null,
                meta:          array()
            );
        }

        return $quotes;
    }

    private function normalise_postcode( string $postcode ): string {
        return strtoupper( preg_replace( '/\s+/', '', $postcode ) ?? '' );
    }

    private function xml_escape( string $value ): string {
        return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Buy a label via the Non-Contract Shipment API. The rate_id is the
     * Canada Post service code (e.g. "DOM.RP" for Regular Parcel).
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $username = trim( (string) ( $credentials['api_username'] ?? '' ) );
        $password = trim( (string) ( $credentials['api_password'] ?? '' ) );
        $customer = trim( (string) ( $credentials['customer_number'] ?? '' ) );
        if ( '' === $username || '' === $password || '' === $customer ) {
            throw new Carrier_Exception( 'Canada Post: missing api_username, api_password, or customer_number credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'Canada Post: rate_id (service code) required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = str_replace( '{customer}', rawurlencode( $customer ), $is_test ? self::SHIP_URL_TEST : self::SHIP_URL_LIVE );

        $response = $this->http->request( 'POST', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
                'Accept'        => 'application/vnd.cpc.ncshipment-v4+xml',
                'Content-Type'  => 'application/vnd.cpc.ncshipment-v4+xml',
                'Accept-language' => 'en-CA',
            ),
            'body' => $this->build_ship_xml( $rate_id, $credentials ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Canada Post: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function build_ship_xml( string $service, array $credentials ): string {
        $weight_kg = (float) ( $credentials['__weight_kg'] ?? 1 );
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<non-contract-shipment xmlns="http://www.canadapost.ca/ws/ncshipment-v4">'
            . '<delivery-spec>'
            . '<service-code>' . $this->xml_escape( $service ) . '</service-code>'
            . '<sender>'
            . '<name>' . $this->xml_escape( (string) ( $credentials['__from_name'] ?? 'Shipper' ) ) . '</name>'
            . '<contact-phone>0000000000</contact-phone>'
            . '<address-details>'
            . '<address-line-1>' . $this->xml_escape( (string) ( $credentials['__from_line1'] ?? '' ) ) . '</address-line-1>'
            . '<city>' . $this->xml_escape( (string) ( $credentials['__from_city'] ?? '' ) ) . '</city>'
            . '<prov-state>' . $this->xml_escape( (string) ( $credentials['__from_state'] ?? '' ) ) . '</prov-state>'
            . '<postal-zip-code>' . $this->xml_escape( $this->normalise_postcode( (string) ( $credentials['__from_zip'] ?? '' ) ) ) . '</postal-zip-code>'
            . '</address-details>'
            . '</sender>'
            . '<destination>'
            . '<name>' . $this->xml_escape( (string) ( $credentials['__to_name'] ?? 'Recipient' ) ) . '</name>'
            . '<address-details>'
            . '<address-line-1>' . $this->xml_escape( (string) ( $credentials['__to_line1'] ?? '' ) ) . '</address-line-1>'
            . '<city>' . $this->xml_escape( (string) ( $credentials['__to_city'] ?? '' ) ) . '</city>'
            . '<prov-state>' . $this->xml_escape( (string) ( $credentials['__to_state'] ?? '' ) ) . '</prov-state>'
            . '<country-code>' . $this->xml_escape( (string) ( $credentials['__to_country'] ?? 'CA' ) ) . '</country-code>'
            . '<postal-zip-code>' . $this->xml_escape( $this->normalise_postcode( (string) ( $credentials['__to_zip'] ?? '' ) ) ) . '</postal-zip-code>'
            . '</address-details>'
            . '</destination>'
            . '<parcel-characteristics><weight>' . $weight_kg . '</weight></parcel-characteristics>'
            . '<preferences><show-packing-instructions>false</show-packing-instructions></preferences>'
            . '</delivery-spec>'
            . '</non-contract-shipment>';
    }

    public function parse_label_response( string $body ): Label {
        $body = $this->strip_namespaces( $body );
        $xml  = @simplexml_load_string( $body );
        if ( false === $xml ) {
            throw new Carrier_Exception( 'Canada Post: malformed ship response.' );
        }

        $tracking = (string) ( $xml->{'tracking-pin'} ?? '' );
        $links    = $xml->{'links'} ?? null;
        $label_url = '';
        if ( null !== $links && isset( $links->link ) ) {
            foreach ( $links->link as $link ) {
                $rel = (string) $link['rel'];
                if ( 'label' === $rel ) {
                    $label_url = (string) $link['href'];
                    break;
                }
            }
        }

        if ( '' === $tracking || '' === $label_url ) {
            throw new Carrier_Exception( 'Canada Post: response missing tracking-pin or label link.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: 'PDF',
            cost_cents: 0,
            currency: 'CAD',
            meta: array( 'shipment_id' => (string) ( $xml->{'shipment-id'} ?? '' ) )
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $username = trim( (string) ( $credentials['api_username'] ?? '' ) );
        $password = trim( (string) ( $credentials['api_password'] ?? '' ) );
        if ( '' === $username || '' === $password ) {
            throw new Carrier_Exception( 'Canada Post: missing credentials.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'Canada Post: tracking_number required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = ( $is_test ? self::TRACK_URL_TEST : self::TRACK_URL_LIVE ) . rawurlencode( $tracking_number ) . '/detail';

        $response = $this->http->request( 'GET', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
                'Accept'        => 'application/vnd.cpc.track-v2+xml',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Canada Post: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $body = $this->strip_namespaces( $body );
        $xml  = @simplexml_load_string( $body );
        if ( false === $xml ) {
            throw new Carrier_Exception( 'Canada Post: malformed tracking response.' );
        }

        $events = array();
        $latest = '';
        if ( isset( $xml->{'significant-events'}->occurrence ) ) {
            foreach ( $xml->{'significant-events'}->occurrence as $occ ) {
                $code  = (string) $occ->{'event-identifier'};
                $desc  = (string) $occ->{'event-description'};
                $date  = (string) $occ->{'event-date'};
                $time  = (string) $occ->{'event-time'};
                $events[] = array(
                    'timestamp'   => (int) strtotime( $date . ' ' . $time ),
                    'status'      => $this->map_status( $code, $desc ),
                    'description' => $desc,
                    'location'    => trim( (string) $occ->{'event-site'} . ', ' . (string) $occ->{'event-province'}, ', ' ),
                );
                if ( '' === $latest ) {
                    $latest = $code . '|' . $desc;
                }
            }
        }

        [ $code, $desc ] = explode( '|', $latest, 2 ) + array( '', '' );
        return new Tracking(
            carrier_id: $this->id(),
            tracking_number: $tracking_number,
            status: $this->map_status( (string) $code, (string) $desc ),
            events: $events
        );
    }

    private function strip_namespaces( string $xml ): string {
        return (string) preg_replace( '/(<\/?)[a-zA-Z0-9]+:/', '$1', $xml );
    }

    private function map_status( string $code, string $desc ): string {
        $u = strtoupper( $desc . ' ' . $code );
        return match ( true ) {
            str_contains( $u, 'DELIVERED' )                                            => Tracking::STATUS_DELIVERED,
            str_contains( $u, 'OUT FOR DELIVERY' )                                     => Tracking::STATUS_OUT_FOR_DELIVERY,
            str_contains( $u, 'IN TRANSIT' ),
            str_contains( $u, 'PROCESSED' ),
            str_contains( $u, 'ITEM IN' )                                              => Tracking::STATUS_IN_TRANSIT,
            str_contains( $u, 'ELECTRONIC' ),
            str_contains( $u, 'DATA RECEIVED' ),
            str_contains( $u, 'INFORMATION' )                                          => Tracking::STATUS_PRE_TRANSIT,
            str_contains( $u, 'RETURN' )                                               => Tracking::STATUS_RETURNED,
            str_contains( $u, 'EXCEPTION' ), str_contains( $u, 'UNDELIVERED' )         => Tracking::STATUS_EXCEPTION,
            default                                                                    => Tracking::STATUS_UNKNOWN,
        };
    }
}
