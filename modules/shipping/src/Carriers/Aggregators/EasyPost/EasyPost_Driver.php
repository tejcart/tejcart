<?php
/**
 * EasyPost aggregator driver.
 *
 * EasyPost is a multi-carrier rate aggregator — a single integration
 * unlocks ~100 carriers (USPS, UPS, FedEx, DHL, Canada Post, plus
 * regional carriers worldwide). This driver is intentionally the
 * reference implementation that the rest of the carrier folder is
 * cloned from.
 *
 * API reference: https://www.easypost.com/docs/api
 *
 * @package TejCart\Shipping_Plugin\Carriers\Aggregators\EasyPost
 */

namespace TejCart\Shipping_Plugin\Carriers\Aggregators\EasyPost;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Address_Validation_Result;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Package;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyPost_Driver extends Abstract_Carrier_Driver {
    public const ENDPOINT       = 'https://api.easypost.com/v2/shipments';
    public const TRACKERS_API   = 'https://api.easypost.com/v2/trackers';

    public function id(): string {
        return 'easypost';
    }

    public function label(): string {
        return 'EasyPost';
    }

    public function region(): string {
        return 'aggregator';
    }

    public function credential_fields(): array {
        return array(
            'api_key' => array(
                'type'        => 'password',
                'title'       => __( 'API key', 'tejcart' ),
                'description' => __( 'Production or test EasyPost API key (begins with "EZAK" or "EZTK").', 'tejcart' ),
                'secret'      => true,
            ),
            'environment' => array(
                'type'        => 'select',
                'title'       => __( 'Environment', 'tejcart' ),
                'description' => __( 'EasyPost uses one endpoint; the API key prefix selects mode (EZAK_ live, EZTK_ test). This field is informational.', 'tejcart' ),
                'options' => array(
                    'live' => __( 'Live', 'tejcart' ),
                    'test' => __( 'Test (sandbox)', 'tejcart' ),
                ),
                'default' => 'test',
                'secret'  => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $api_key = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $api_key ) {
            throw new Carrier_Exception( 'EasyPost: missing api_key credential.' );
        }

        // EasyPost's /shipments endpoint quotes a single parcel; carts that
        // pack into multiple boxes get one quote per box and we sum the
        // resulting rates by service code. Skipping the fan-out (the
        // pre-fix behaviour of using packages[0] only) under-quotes every
        // multi-box cart.
        /** @var array<string, Rate_Quote> $by_service */
        $by_service = array();
        foreach ( $request->packages as $idx => $package ) {
            try {
                $response = $this->http->request( 'POST', self::ENDPOINT, array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                    ),
                    'body' => wp_json_encode( $this->build_shipment_payload_for_package( $request, $package ) ),
                ) );
            } catch ( Carrier_Exception $e ) {
                return array();
            }

            if ( $response['status'] >= 400 ) {
                return array();
            }

            $quotes = $this->parse_rates_response( $response['body'] );
            if ( 0 === $idx ) {
                foreach ( $quotes as $quote ) {
                    $by_service[ $quote->service_code ] = $quote;
                }
                continue;
            }

            $by_service = $this->merge_quotes_by_service( $by_service, $quotes );
        }

        return array_values( $by_service );
    }

    /**
     * Build the EasyPost /shipments POST body from a Rate_Request.
     *
     * Extracted as a public method so the contract test can assert the
     * payload mapping without making a real HTTP call. Single-package
     * carts continue to use the same payload shape they always did.
     *
     * @return array<string,mixed>
     */
    public function build_shipment_payload( Rate_Request $request ): array {
        return $this->build_shipment_payload_for_package( $request, $request->packages[0] );
    }

    /**
     * Build the EasyPost /shipments POST body for a single package within
     * a (possibly) multi-package rate request.
     *
     * @return array<string,mixed>
     */
    private function build_shipment_payload_for_package( Rate_Request $request, Package $package ): array {
        return array(
            'shipment' => array(
                'to_address' => array(
                    'country' => $request->destination['country'] ?? '',
                    'state'   => $request->destination['state'] ?? '',
                    'city'    => $request->destination['city'] ?? '',
                    'zip'     => $request->destination['postcode'] ?? '',
                    'street1' => $request->destination['line1'] ?? '',
                ),
                'from_address' => array(
                    'country' => $request->origin['country'] ?? '',
                    'state'   => $request->origin['state'] ?? '',
                    'city'    => $request->origin['city'] ?? '',
                    'zip'     => $request->origin['postcode'] ?? '',
                    'street1' => $request->origin['line1'] ?? '',
                ),
                'parcel' => array(
                    'weight' => $this->grams_to_oz( $package->weight_grams ),
                    'length' => $this->mm_to_in( $package->length_mm ),
                    'width'  => $this->mm_to_in( $package->depth_mm ),
                    'height' => $this->mm_to_in( $package->height_mm ),
                ),
            ),
        );
    }

    /**
     * Sum incoming quotes into the running per-service totals, intersecting
     * by service code so we only return options that every package can
     * actually ship by.
     *
     * @param array<string, Rate_Quote> $running
     * @param Rate_Quote[]              $incoming
     * @return array<string, Rate_Quote>
     */
    private function merge_quotes_by_service( array $running, array $incoming ): array {
        $next = array();
        foreach ( $incoming as $quote ) {
            if ( ! isset( $running[ $quote->service_code ] ) ) {
                continue;
            }
            $prev                         = $running[ $quote->service_code ];
            $next[ $quote->service_code ] = new Rate_Quote(
                carrier_id:    $prev->carrier_id,
                service_code:  $prev->service_code,
                service_label: $prev->service_label,
                cost_cents:    $prev->cost_cents + $quote->cost_cents,
                currency:      $prev->currency,
                eta_days:      max( $prev->eta_days, $quote->eta_days ),
                rate_id:       $prev->rate_id,
                meta:          $prev->meta
            );
        }
        return $next;
    }

    /**
     * Parse the EasyPost shipment response into Rate_Quote objects.
     *
     * Public so the contract test harness can exercise the mapping
     * against recorded fixtures.
     *
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body ): array {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $rates = $decoded['rates'] ?? array();
        if ( ! is_array( $rates ) ) {
            return array();
        }

        $shipment_id = (string) ( $decoded['id'] ?? '' );

        $quotes = array();
        foreach ( $rates as $rate ) {
            if ( ! is_array( $rate ) ) {
                continue;
            }

            $service  = (string) ( $rate['service'] ?? '' );
            $carrier  = (string) ( $rate['carrier'] ?? '' );
            $rate_str = (string) ( $rate['rate'] ?? '' );
            if ( '' === $service || '' === $rate_str ) {
                continue;
            }

            // EasyPost rate responses include a `currency` field per
            // rate. Use the currency-aware multiplier so JPY (×1) and
            // KWD/BHD/OMR (×1000) are converted correctly — a hardcoded
            // ×100 would silently misclassify amounts on non-2-decimal
            // currencies.
            $rate_currency = strtoupper( (string) ( $rate['currency'] ?? 'USD' ) );
            $cents         = \TejCart\Money\Currency::to_minor_units( $rate_str, $rate_currency );

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $service,
                service_label: $carrier . ' ' . $service,
                cost_cents:    $cents,
                currency:      $rate_currency,
                eta_days:      isset( $rate['delivery_days'] ) ? (int) $rate['delivery_days'] : null,
                rate_id:       isset( $rate['id'] ) ? (string) $rate['id'] : null,
                meta:          array(
                    'carrier_account_id' => (string) ( $rate['carrier_account_id'] ?? '' ),
                    'list_rate'          => (string) ( $rate['list_rate'] ?? '' ),
                    'shipment_id'        => $shipment_id,
                ),
            );
        }

        return $quotes;
    }

    /**
     * Buy a label by redeeming a previously quoted EasyPost rate.
     *
     * Two-step EasyPost flow: re-fetch the shipment by id (the rate id
     * format is `rate_xxx` and the shipment id is required to buy), then
     * POST to /shipments/{shipment_id}/buy. The shipment id is carried
     * on the Rate_Quote meta as `shipment_id`. Idempotency-Key header
     * is set so a duplicate retry doesn't double-charge.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $api_key = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $api_key ) {
            throw new Carrier_Exception( 'EasyPost: missing api_key credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'EasyPost: rate_id is required.' );
        }

        $shipment_id = (string) ( $credentials['__shipment_id'] ?? '' );
        if ( '' === $shipment_id ) {
            throw new Carrier_Exception( 'EasyPost: shipment_id missing — pass via credentials __shipment_id.' );
        }

        $idem = (string) ( $credentials['__idempotency_key'] ?? '' );

        $response = $this->http->request(
            'POST',
            'https://api.easypost.com/v2/shipments/' . rawurlencode( $shipment_id ) . '/buy',
            array(
                'driver_id' => $this->id(),
                'headers'   => array_filter( array(
                    'Authorization'   => 'Basic ' . base64_encode( $api_key . ':' ),
                    'Content-Type'    => 'application/json',
                    'Accept'          => 'application/json',
                    'Idempotency-Key' => '' === $idem ? null : $idem,
                ) ),
                'body' => wp_json_encode( array( 'rate' => array( 'id' => $rate_id ) ) ),
            )
        );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'EasyPost: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'EasyPost: malformed buy response.' );
        }

        $label_url = (string) (
            $decoded['postage_label']['label_url']
            ?? $decoded['label_url']
            ?? ''
        );
        $tracking  = (string) ( $decoded['tracking_code'] ?? '' );
        $rate_str  = (string) ( $decoded['selected_rate']['rate'] ?? $decoded['rate'] ?? '0' );
        $currency  = strtoupper( (string) ( $decoded['selected_rate']['currency'] ?? $decoded['currency'] ?? 'USD' ) );
        $format    = strtoupper( (string) ( $decoded['postage_label']['label_file_type'] ?? 'PDF' ) );
        if ( str_contains( $format, '/' ) ) {
            $parts  = explode( '/', $format );
            $format = strtoupper( end( $parts ) );
        }

        if ( '' === $label_url || '' === $tracking ) {
            throw new Carrier_Exception( 'EasyPost: response missing label_url or tracking_code.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: $format,
            cost_cents: \TejCart\Money\Currency::to_minor_units( $rate_str, $currency ),
            currency: $currency,
            meta: array(
                'shipment_id' => (string) ( $decoded['id'] ?? '' ),
            )
        );
    }

    /**
     * Look up a tracker by tracking number. EasyPost stores trackers as
     * first-class resources; we POST a `create-or-find` to the trackers
     * endpoint with `carrier=AUTO_DETECT` so the caller doesn't need to
     * know which sub-carrier the label was bought from.
     *
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $api_key = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $api_key ) {
            throw new Carrier_Exception( 'EasyPost: missing api_key credential.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'EasyPost: tracking_number required.' );
        }

        $response = $this->http->request( 'POST', self::TRACKERS_API, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'tracker' => array(
                    'tracking_code' => $tracking_number,
                    'carrier'       => $credentials['__carrier'] ?? 'AUTO_DETECT',
                ),
            ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'EasyPost: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    /**
     * Validate via EasyPost's /addresses?verify endpoint.
     *
     * @param array<string,string> $address
     * @param array<string,string> $credentials
     */
    public function validate_address( array $address, array $credentials ): Address_Validation_Result {
        $api_key = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $api_key ) {
            throw new Carrier_Exception( 'EasyPost: missing api_key credential.' );
        }

        $payload = array(
            'address' => array(
                'verify' => array( 'delivery' ),
                'street1' => $address['line1'] ?? '',
                'city'    => $address['city'] ?? '',
                'state'   => $address['state'] ?? '',
                'zip'     => $address['postcode'] ?? '',
                'country' => $address['country'] ?? '',
            ),
        );

        $response = $this->http->request( 'POST', 'https://api.easypost.com/v2/addresses', array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'EasyPost: validate_address failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_address_response( $response['body'] );
    }

    public function parse_address_response( string $body ): Address_Validation_Result {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'EasyPost: malformed address response.' );
        }

        $verifications = $decoded['verifications']['delivery'] ?? array();
        $deliverable   = (bool) ( $verifications['success'] ?? false );

        $messages = array();
        if ( isset( $verifications['errors'] ) && is_array( $verifications['errors'] ) ) {
            foreach ( $verifications['errors'] as $err ) {
                if ( is_array( $err ) && isset( $err['message'] ) ) {
                    $messages[] = (string) $err['message'];
                }
            }
        }

        return new Address_Validation_Result(
            is_deliverable: $deliverable,
            is_residential: (bool) ( $decoded['residential'] ?? false ),
            corrected: array(
                'line1'    => (string) ( $decoded['street1'] ?? '' ),
                'city'     => (string) ( $decoded['city'] ?? '' ),
                'state'    => (string) ( $decoded['state'] ?? '' ),
                'postcode' => (string) ( $decoded['zip'] ?? '' ),
                'country'  => (string) ( $decoded['country'] ?? '' ),
            ),
            messages: $messages
        );
    }

    /**
     * Refund a purchased shipment via /shipments/{id}/refund.
     *
     * @param array<string,string> $credentials
     */
    public function void_label( string $shipment_token, array $credentials ): void {
        $api_key = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $api_key ) {
            throw new Carrier_Exception( 'EasyPost: missing api_key credential.' );
        }
        if ( '' === $shipment_token ) {
            throw new Carrier_Exception( 'EasyPost: shipment_token required.' );
        }

        $response = $this->http->request(
            'POST',
            'https://api.easypost.com/v2/shipments/' . rawurlencode( $shipment_token ) . '/refund',
            array(
                'driver_id' => $this->id(),
                'headers'   => array(
                    'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => '{}',
            )
        );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'EasyPost: void_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'EasyPost: malformed track response.' );
        }

        $status = $this->map_tracking_status( (string) ( $decoded['status'] ?? '' ) );
        $events = array();

        $details = $decoded['tracking_details'] ?? array();
        if ( is_array( $details ) ) {
            foreach ( $details as $detail ) {
                if ( ! is_array( $detail ) ) {
                    continue;
                }
                $location_parts = array_filter( array(
                    (string) ( $detail['tracking_location']['city'] ?? '' ),
                    (string) ( $detail['tracking_location']['state'] ?? '' ),
                    (string) ( $detail['tracking_location']['country'] ?? '' ),
                ) );
                $events[] = array(
                    'timestamp'   => isset( $detail['datetime'] ) ? (int) strtotime( (string) $detail['datetime'] ) : 0,
                    'status'      => $this->map_tracking_status( (string) ( $detail['status'] ?? '' ) ),
                    'description' => (string) ( $detail['message'] ?? '' ),
                    'location'    => implode( ', ', $location_parts ),
                );
            }
        }

        $eta = isset( $decoded['est_delivery_date'] ) && '' !== $decoded['est_delivery_date']
            ? (int) strtotime( (string) $decoded['est_delivery_date'] )
            : null;
        if ( 0 === $eta ) {
            $eta = null;
        }

        return new Tracking(
            carrier_id: $this->id(),
            tracking_number: $tracking_number,
            status: $status,
            events: $events,
            estimated_delivery: $eta
        );
    }

    private function map_tracking_status( string $status ): string {
        return match ( strtolower( $status ) ) {
            'pre_transit'      => Tracking::STATUS_PRE_TRANSIT,
            'in_transit'       => Tracking::STATUS_IN_TRANSIT,
            'out_for_delivery' => Tracking::STATUS_OUT_FOR_DELIVERY,
            'delivered'        => Tracking::STATUS_DELIVERED,
            'return_to_sender',
            'returned'         => Tracking::STATUS_RETURNED,
            'failure',
            'error',
            'exception'        => Tracking::STATUS_EXCEPTION,
            default            => Tracking::STATUS_UNKNOWN,
        };
    }

    private function grams_to_oz( int $grams ): float {
        return round( $grams / 28.3495, 2 );
    }

    private function mm_to_in( int $mm ): float {
        return round( $mm / 25.4, 2 );
    }
}
