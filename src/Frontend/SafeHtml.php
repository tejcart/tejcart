<?php
/**
 * Immutable wrapper for pre-escaped HTML strings.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEC-033 — type-enforced "this string is already safe to echo" contract.
 *
 * Multiple sites in TejCart's render layer build HTML by composing
 * `esc_html()` / `esc_attr()` / `esc_url()` calls and then echo the
 * result with a `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`
 * suppression comment. The escape contract between producer and
 * consumer is informal — a future change to the producer that forgets
 * an escape silently introduces XSS and the linter still passes because
 * the call site is annotated.
 *
 * Wrap pre-escaped HTML in this value object and echo it via
 * `(string) $safe`. Callers that previously returned `string` can now
 * return `SafeHtml`; the type signature itself communicates the
 * contract. Reviewers and static analysers see an unambiguous boundary
 * between "this string came from an escaping helper" and "this string
 * might be tainted."
 *
 * Construction is allowed via:
 *  - {@see SafeHtml::from_escaped()} — the canonical factory; caller
 *    asserts via the method name that the string is already escaped.
 *  - {@see SafeHtml::concat()} — compose multiple SafeHtml values
 *    without re-escaping.
 *
 * Plain text → SafeHtml goes through {@see SafeHtml::from_text()},
 * which runs esc_html() so the resulting wrapper genuinely is safe.
 */
final class SafeHtml {

    /**
     * @var string The pre-escaped HTML payload.
     */
    private string $html;

    private function __construct( string $html ) {
        $this->html = $html;
    }

    /**
     * Wrap a string the caller asserts is already escaped.
     *
     * The method name is the contract: "I have already escaped this."
     * Don't use this on untrusted input.
     */
    public static function from_escaped( string $html ): self {
        return new self( $html );
    }

    /**
     * Escape plain text and wrap the result. Safe to pass any
     * user-supplied / external string.
     */
    public static function from_text( string $text ): self {
        return new self( esc_html( $text ) );
    }

    /**
     * Compose multiple SafeHtml values without re-escaping each one.
     *
     * Use when stitching pre-escaped fragments together, e.g. a label
     * plus a `<span class="badge">` produced by separate helpers.
     */
    public static function concat( self ...$parts ): self {
        $out = '';
        foreach ( $parts as $part ) {
            $out .= $part->html;
        }
        return new self( $out );
    }

    /**
     * Stringify so `echo $safe` works directly.
     */
    public function __toString(): string {
        return $this->html;
    }

    /**
     * Explicit accessor for call sites that prefer not to rely on
     * `__toString()` (e.g. `printf`'s `%s` which already invokes it,
     * but `wp_kses` doesn't).
     */
    public function html(): string {
        return $this->html;
    }
}
