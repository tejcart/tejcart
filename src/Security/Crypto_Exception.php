<?php
/**
 * Exception thrown when {@see Crypto::encrypt()} cannot produce
 * ciphertext at runtime (random_bytes failure, openssl_encrypt false).
 *
 * @package TejCart\Security
 */

declare( strict_types=1 );

namespace TejCart\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Marker exception. Callers that handle sensitive material catch this
 * specifically so they can refuse to persist the value (rather than
 * fall back to plaintext) and surface a hard error to the operator.
 */
class Crypto_Exception extends \RuntimeException {
}
