<?php
/**
 * Bank Transfer (BACS) payment gateway.
 *
 * @package TejCart\Gateways\Offline
 */

declare( strict_types=1 );

namespace TejCart\Gateways\Offline;

use TejCart\Gateways\Abstract_Gateway;
use TejCart\Security\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lets the customer pay by direct bank transfer.
 *
 * Order is held until the shop manager confirms funds were received.
 * Bank account details are configured under
 * Settings → Payments → Bank Transfer and shown on the thank-you page
 * and inside the order confirmation email.
 */
class Bank_Transfer_Gateway extends Abstract_Gateway {
    /**
     * Fields that hold merchant banking details and must be encrypted
     * at rest. See #1209 — a DB-read primitive against `wp_options`
     * should not be able to harvest plaintext IBAN / BIC / account
     * number / sort code.
     */
    private const ENCRYPTED_FIELDS = array(
        'account_number',
        'sort_code',
        'iban',
        'bic',
    );

    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'bank_transfer';
        $this->title       = __( 'Direct Bank Transfer', 'tejcart' );
        $this->description = __( 'Make your payment directly into our bank account. Use your order ID as the payment reference. Your order will be processed once payment has cleared.', 'tejcart' );
        $this->supports    = array( 'products' );

        parent::__construct();

        add_action( 'tejcart_thankyou_' . $this->id, array( $this, 'render_bank_details' ), 10, 1 );

        // #1209: transparently encrypt sensitive fields on save and
        // decrypt on read. Filter signatures match Abstract_Gateway's
        // settings persistence so the gateway settings page is
        // unchanged from the admin's perspective.
        add_filter( 'tejcart_gateway_save_option_' . $this->id, array( $this, 'encrypt_on_save' ), 10, 2 );
    }

    /**
     * Encrypt sensitive fields just before they're persisted to
     * `wp_options`. Idempotent — re-encrypting an already-encrypted
     * value is detected via Crypto::is_encrypted and short-circuited.
     *
     * @param mixed  $value Incoming option value.
     * @param string $key   Field key.
     * @return mixed The (possibly encrypted) value.
     */
    public function encrypt_on_save( $value, string $key ): mixed {
        if ( ! in_array( $key, self::ENCRYPTED_FIELDS, true ) ) {
            return $value;
        }
        $plain = (string) $value;
        if ( '' === $plain ) {
            return $value;
        }
        if ( class_exists( '\\TejCart\\Security\\Crypto' ) && Crypto::is_encrypted( $plain ) ) {
            return $value;
        }
        if ( class_exists( '\\TejCart\\Security\\Crypto' ) ) {
            return Crypto::encrypt_required( $plain );
        }
        return $value;
    }

    /**
     * Override get_option to decrypt sensitive fields on read.
     * Lazy-migrates plaintext values written before #1209 — the first
     * read after upgrade detects plaintext, returns it as-is, and the
     * next admin save re-persists it encrypted.
     */
    public function get_option( string $key, string $default = '' ) {
        $raw = parent::get_option( $key, $default );
        if ( ! in_array( $key, self::ENCRYPTED_FIELDS, true ) ) {
            return $raw;
        }
        $raw = (string) $raw;
        if ( '' === $raw ) {
            return $raw;
        }
        if ( class_exists( '\\TejCart\\Security\\Crypto' ) && Crypto::is_encrypted( $raw ) ) {
            return Crypto::decrypt( $raw );
        }
        return $raw;
    }

    /**
     * Define admin settings fields.
     */
    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'         => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable Direct Bank Transfer as a payment method.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'           => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Direct Bank Transfer', 'tejcart' ),
            ),
            'description'     => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Make your payment directly into our bank account. Use your order ID as the payment reference. Your order will be processed once payment has cleared.', 'tejcart' ),
            ),
            'account_heading' => array(
                'type'        => 'heading',
                'title'       => __( 'Bank Account Details', 'tejcart' ),
                'description' => __( 'These details are shown to customers on the thank-you page and in the order confirmation email. Leave a field blank to hide it.', 'tejcart' ),
            ),
            'account_name'    => array(
                'type'    => 'text',
                'title'   => __( 'Account Name', 'tejcart' ),
                'default' => '',
            ),
            'account_number'  => array(
                'type'    => 'text',
                'title'   => __( 'Account Number', 'tejcart' ),
                'default' => '',
            ),
            'bank_name'       => array(
                'type'    => 'text',
                'title'   => __( 'Bank Name', 'tejcart' ),
                'default' => '',
            ),
            'sort_code'       => array(
                'type'    => 'text',
                'title'   => __( 'Sort Code', 'tejcart' ),
                'default' => '',
            ),
            'iban'            => array(
                'type'    => 'text',
                'title'   => __( 'IBAN', 'tejcart' ),
                'default' => '',
            ),
            'bic'             => array(
                'type'    => 'text',
                'title'   => __( 'BIC / SWIFT', 'tejcart' ),
                'default' => '',
            ),
        );
    }

    /**
     * Process payment - mark the order on-hold pending the wire transfer.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( int $order_id ): array {
        $order = new \TejCart\Order\Order( $order_id );

        if ( ! $order->get_id() ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Order not found.', 'tejcart' ),
            );
        }

        // Order_Cart_Cleanup listens on tejcart_order_status_on-hold
        // and empties the cart centrally. Inline empty_cart() removed.
        $order->update_status( 'on-hold', __( 'Awaiting bank transfer payment.', 'tejcart' ) );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Render bank account details (used on thank-you page and emails).
     *
     * @param mixed $order Order instance or ID.
     */
    public function render_bank_details( $order = null ): void {
        $details = array(
            'account_name'   => $this->get_option( 'account_name', '' ),
            'account_number' => $this->get_option( 'account_number', '' ),
            'bank_name'      => $this->get_option( 'bank_name', '' ),
            'sort_code'      => $this->get_option( 'sort_code', '' ),
            'iban'           => $this->get_option( 'iban', '' ),
            'bic'            => $this->get_option( 'bic', '' ),
        );

        $details = array_filter( $details );
        if ( empty( $details ) ) {
            return;
        }

        echo '<h3>' . esc_html__( 'Our Bank Details', 'tejcart' ) . '</h3>';
        echo '<table class="tejcart-bank-details">';
        $labels = array(
            'account_name'   => __( 'Account Name', 'tejcart' ),
            'account_number' => __( 'Account Number', 'tejcart' ),
            'bank_name'      => __( 'Bank Name', 'tejcart' ),
            'sort_code'      => __( 'Sort Code', 'tejcart' ),
            'iban'           => __( 'IBAN', 'tejcart' ),
            'bic'            => __( 'BIC / SWIFT', 'tejcart' ),
        );
        foreach ( $details as $key => $value ) {
            echo '<tr><th>' . esc_html( $labels[ $key ] ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
        }
        echo '</table>';
    }

    /**
     * Build the thank-you URL for an order.
     *
     * @param \TejCart\Order\Order $order Order instance.
     * @return string
     */
    private function get_return_url( $order ): string {
        $page_id = (int) get_option( 'tejcart_thankyou_page_id', 0 );
        $url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        return add_query_arg(
            array(
                'order_id'  => $order->get_id(),
                'order_key' => method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '',
            ),
            $url
        );
    }
}
