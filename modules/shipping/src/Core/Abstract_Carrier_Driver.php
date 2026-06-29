<?php
/**
 * Base class for all carrier drivers.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Abstract_Carrier_Driver {
    protected HTTP_Client $http;

    public function __construct( HTTP_Client $http ) {
        $this->http = $http;
    }

    /**
     * Stable machine-readable identifier (e.g. "easypost", "fedex").
     * MUST match `^[a-z][a-z0-9_]*$`.
     */
    abstract public function id(): string;

    /**
     * Human-readable label for the admin UI ("EasyPost", "FedEx").
     */
    abstract public function label(): string;

    /**
     * Region grouping for the admin UI.
     * One of: "aggregator", "global", "north_america", "europe", "apac",
     * "latam", "mea", "last_mile", "lockers", "freight".
     */
    abstract public function region(): string;

    /**
     * Credential field schema. Mirrors Abstract_Shipping_Method::$form_fields.
     *
     * Each entry: ['type' => 'text|password|select|checkbox', 'title' => '...',
     *              'default' => '...', 'options' => [...], 'secret' => bool].
     *
     * Fields with 'secret' => true are stored encrypted via Credentials_Vault.
     *
     * @return array<string,array<string,mixed>>
     */
    abstract public function credential_fields(): array;

    /**
     * Fetch live rate quotes for the request.
     *
     * Implementations MUST NOT throw on transport failures — return an
     * empty array (or throw Carrier_Exception for genuinely unrecoverable
     * configuration errors). Empty result = no rates available, which the
     * checkout treats as "this carrier doesn't service this destination".
     *
     * @param Rate_Request          $request
     * @param array<string,string>  $credentials Decrypted credential map.
     * @return Rate_Quote[]
     * @throws Carrier_Exception When credentials are structurally invalid.
     */
    abstract public function rates( Rate_Request $request, array $credentials ): array;

    /**
     * Buy a label for a previously quoted shipment. Optional — drivers
     * that don't support label purchase should leave the default which
     * throws a clear exception.
     *
     * @throws Carrier_Exception
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        throw new Carrier_Exception( sprintf( 'Driver "%s" does not support label purchase.', esc_html( $this->id() ) ) );
    }

    /**
     * Fetch tracking information for a shipment.
     *
     * @throws Carrier_Exception
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        throw new Carrier_Exception( sprintf( 'Driver "%s" does not support tracking lookup.', esc_html( $this->id() ) ) );
    }

    /**
     * Validate / normalise a shipping address against the carrier's
     * verification endpoint. Drivers that can't validate addresses
     * should leave the default; `Label_Service` callers can catch the
     * exception and fall back to the merchant-provided address.
     *
     * @param array<string,string> $address     Same shape as Rate_Request::$destination.
     * @param array<string,string> $credentials
     * @throws Carrier_Exception
     */
    public function validate_address( array $address, array $credentials ): Address_Validation_Result {
        throw new Carrier_Exception( sprintf( 'Driver "%s" does not support address validation.', esc_html( $this->id() ) ) );
    }

    /**
     * Generate an end-of-day manifest / SCAN form for one or more
     * previously purchased shipments. Required by USPS bulk drop-off
     * and by UPS scheduled pickup. Drivers without a manifesting
     * endpoint should leave the default which throws.
     *
     * @param string[]             $shipment_tokens Shipment / tracking tokens to manifest.
     * @param array<string,string> $credentials
     * @return Manifest
     * @throws Carrier_Exception
     */
    public function manifest( array $shipment_tokens, array $credentials ): Manifest {
        throw new Carrier_Exception( sprintf( 'Driver "%s" does not support manifesting.', esc_html( $this->id() ) ) );
    }

    /**
     * Void a previously purchased label so the carrier refunds the postage.
     *
     * Drivers that don't expose a void/refund endpoint should leave the
     * default which throws — `Label_Service::void()` catches and surfaces
     * the limitation cleanly.
     *
     * @param array<string,string> $credentials
     * @throws Carrier_Exception
     */
    public function void_label( string $shipment_token, array $credentials ): void {
        throw new Carrier_Exception( sprintf( 'Driver "%s" does not support label voiding.', esc_html( $this->id() ) ) );
    }

    /**
     * Whether the driver implements a real `test_connection()` probe.
     *
     * The admin UI uses this to decide whether to render the "Test
     * connection" button at all — drivers that fall through to the
     * abstract default below have no useful probe to run, so showing the
     * button only to surface a "not available yet" message is noisy.
     *
     * Implemented via reflection so individual drivers don't have to
     * declare a redundant boolean — overriding `test_connection()` is
     * the single source of truth.
     */
    public function supports_test_connection(): bool {
        try {
            $declaring = ( new \ReflectionMethod( $this, 'test_connection' ) )->getDeclaringClass()->getName();
        } catch ( \ReflectionException $e ) {
            return false;
        }
        return self::class !== $declaring;
    }

    /**
     * Probe the carrier's API with the supplied credentials and return a
     * structured result for the "Test connection" affordance in the admin.
     *
     * The default implementation is intentionally pessimistic — it reports
     * "not supported" so drivers opt in by overriding. The contract is
     * deliberately tolerant of all transport failures: an exception MUST
     * NOT bubble out, since the result is rendered straight into the
     * settings UI. Implementations should return an array shaped:
     *
     *   [
     *     'ok'      => bool,
     *     'message' => string,  // human-readable, already translatable
     *   ]
     *
     * @param array<string,string> $credentials Decrypted credential map.
     * @return array{ok:bool,message:string}
     */
    public function test_connection( array $credentials ): array {
        return array(
            'ok'      => false,
            'message' => sprintf(
                /* translators: %s: carrier label. */
                __( 'Connection tests aren’t available for %s yet — save credentials and place a test order to verify.', 'tejcart' ),
                $this->label()
            ),
        );
    }
}
