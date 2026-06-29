<?php
/**
 * Merchant theme color overrides.
 *
 * Three knobs on the Design settings tab (primary, accent, sale) cascade
 * through the token layer so the storefront blends with the merchant's
 * WordPress theme instead of locking every site to TejCart's default navy.
 *
 * Design invariants:
 *   - Merchants pick the *brand accents*; neutral surfaces, text, borders,
 *     and semantic success/warning/error stay on the Polaris defaults so
 *     the shell never loses readability or meaning.
 *   - Hover / active / ring / soft-background variants are derived from
 *     the picked hex — merchants set one color, get the full state machine.
 *   - Foreground (button label) color is auto-computed against the picked
 *     background so a merchant cannot ship unreadable text by accident.
 *   - An empty / unset value falls through to the existing token default;
 *     nothing is forced on merchants who never visit the Design tab.
 *
 * @package TejCart\Frontend
 */

declare( strict_types=1 );

namespace TejCart\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders an inline <style> block that overrides TejCart's brand-accent
 * design tokens with values saved on the Design settings tab.
 */
class Theme_Colors {
    /**
     * Option keys that hold the merchant-picked hex values.
     *
     * Each maps to a logical role, not to a single CSS variable — one
     * stored hex drives several derived tokens (hover, active, soft, ring).
     */
    private const OPTION_KEYS = array(
        'primary' => 'tejcart_theme_color_primary',
        'accent'  => 'tejcart_theme_color_accent',
        'sale'    => 'tejcart_theme_color_sale',
    );

    /**
     * Wire the inline-CSS printer onto the frontend style pipeline.
     *
     * `wp_add_inline_style` attaches the <style> tag to `tejcart-public`,
     * which guarantees it prints *after* the stylesheet that declares the
     * defaults. CSS variable cascade resolves last-wins at computed-value
     * time, so the overrides win without !important.
     */
    public function init(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_overrides' ), 20 );
    }

    /**
     * Attach the override stylesheet to the main public bundle.
     *
     * No-op when the merchant has not saved any colors yet — we skip the
     * inline-style emit entirely so there is zero byte cost on stock sites.
     */
    public function enqueue_overrides(): void {
        if ( ! wp_style_is( 'tejcart-public', 'enqueued' ) && ! wp_style_is( 'tejcart-public', 'registered' ) ) {
            return;
        }

        $css = $this->build_css();
        if ( '' === $css ) {
            return;
        }

        // F-FE-003: tejcart-checkout.css bridges its --nc-* aliases to --tejcart-* tokens,
        // but that bridge resolves at parse time from the default values, not from any
        // merchant override. Attaching the override block to tejcart-checkout as well
        // ensures the --tejcart-accent (and friends) override is in scope when the
        // --nc-cta-bg: var(--tejcart-accent) aliases resolve on the checkout page.
        wp_add_inline_style( 'tejcart-public', $css );
        if ( wp_style_is( 'tejcart-checkout', 'enqueued' ) || wp_style_is( 'tejcart-checkout', 'registered' ) ) {
            wp_add_inline_style( 'tejcart-checkout', $css );
        }
    }

    /**
     * Build the :root { --tejcart-*: ...; } override block.
     *
     * @return string CSS text, or empty string when no overrides are set.
     */
    public function build_css(): string {
        $colors = $this->get_colors();

        $declarations = array();

        if ( ! empty( $colors['primary'] ) ) {
            $primary    = $colors['primary'];
            $foreground = self::readable_text_for( $primary );
            $hover      = self::shift_luminance( $primary, -0.06 );
            $active     = self::shift_luminance( $primary, -0.12 );
            $soft       = self::shift_luminance( $primary, 0.58, true );
            $ring       = self::hex_to_rgba( $primary, 0.20 );

            $declarations[] = '--tejcart-accent: ' . $primary . ';';
            $declarations[] = '--tejcart-accent-hover: ' . $hover . ';';
            $declarations[] = '--tejcart-accent-active: ' . $active . ';';
            $declarations[] = '--tejcart-accent-foreground: ' . $foreground . ';';
            $declarations[] = '--tejcart-accent-soft: ' . $soft . ';';
            $declarations[] = '--tejcart-accent-ring: ' . $ring . ';';

            $declarations[] = '--tejcart-color-accent: ' . $primary . ';';
            $declarations[] = '--tejcart-primary: ' . $primary . ';';
        }

        if ( ! empty( $colors['accent'] ) ) {
            $accent = $colors['accent'];

            $declarations[] = '--tejcart-text-link: ' . $accent . ';';
            $declarations[] = '--tejcart-text-link-hover: ' . self::shift_luminance( $accent, -0.12 ) . ';';
        }

        if ( ! empty( $colors['sale'] ) ) {
            $sale = $colors['sale'];
            $declarations[] = '--tejcart-sale-accent: ' . $sale . ';';
        }

        if ( empty( $declarations ) ) {
            return '';
        }

        return ":root{\n    " . implode( "\n    ", $declarations ) . "\n}\n";
    }

    /**
     * Return the currently stored merchant colors.
     *
     * Each entry is either a `#rrggbb` string or an empty string when the
     * merchant has not picked a value. `sanitize_hex_color` is a WP core
     * helper that rejects anything that isn't a well-formed 3/6-digit hex.
     *
     * @return array{primary:string,accent:string,sale:string}
     */
    public function get_colors(): array {
        $out = array();
        foreach ( self::OPTION_KEYS as $role => $option_name ) {
            $raw = (string) get_option( $option_name, '' );
            $out[ $role ] = sanitize_hex_color( $raw ) ?: '';
        }
        return $out;
    }

    /**
     * Pick readable foreground (black or white) for a given background.
     *
     * Uses the relative-luminance formula from WCAG 2.x. A threshold of
     * 0.5 is an intentional, conservative midpoint — it matches what
     * Material and Polaris use for "on-color" selection and keeps the
     * rule simple (no surprise three-way branching).
     *
     * @param string $hex Background color in #rrggbb form.
     * @return string     Either `#202223` (Polaris ink) or `#ffffff`.
     */
    public static function readable_text_for( string $hex ): string {
        $lum = self::relative_luminance( $hex );
        return $lum > 0.5 ? '#202223' : '#ffffff';
    }

    /**
     * Shift a hex color toward black (negative amount) or white (positive).
     *
     * When `$toward_white_on_light` is true and the base color is already
     * light, the shift is biased toward a very-light tint instead — this
     * is what we want for the "soft" background variant (button hover tint,
     * chip fill) where we always want a whisper-tint regardless of base.
     *
     * @param string $hex                    Base color in #rrggbb form.
     * @param float  $amount                 -1.0 .. 1.0.
     * @param bool   $toward_white_on_light  When true, derive a soft tint.
     * @return string                         Shifted color in #rrggbb.
     */
    public static function shift_luminance( string $hex, float $amount, bool $toward_white_on_light = false ): string {
        $rgb = self::hex_to_rgb( $hex );
        if ( null === $rgb ) {
            return $hex;
        }

        if ( $toward_white_on_light ) {
            $r = (int) round( $rgb[0] + ( 255 - $rgb[0] ) * $amount );
            $g = (int) round( $rgb[1] + ( 255 - $rgb[1] ) * $amount );
            $b = (int) round( $rgb[2] + ( 255 - $rgb[2] ) * $amount );
        } else {
            $delta = (int) round( 255 * $amount );
            $r = max( 0, min( 255, $rgb[0] + $delta ) );
            $g = max( 0, min( 255, $rgb[1] + $delta ) );
            $b = max( 0, min( 255, $rgb[2] + $delta ) );
        }

        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /**
     * Convert `#rrggbb` to `rgba(r, g, b, a)` for focus rings and overlays.
     *
     * @param string $hex   `#rrggbb`.
     * @param float  $alpha 0.0 .. 1.0.
     * @return string        CSS `rgba(...)` expression.
     */
    public static function hex_to_rgba( string $hex, float $alpha ): string {
        $rgb = self::hex_to_rgb( $hex );
        if ( null === $rgb ) {
            return $hex;
        }
        return sprintf( 'rgba(%d, %d, %d, %.2f)', $rgb[0], $rgb[1], $rgb[2], $alpha );
    }

    /**
     * Compute WCAG 2.x relative luminance (0..1) of a hex color.
     *
     * Kept public so the admin live-preview JS can mirror the same
     * formula for its contrast badge — a cross-language helper would be
     * nice but duplicating ~5 lines is cheaper than a build step.
     *
     * @param string $hex `#rrggbb`.
     * @return float      0.0 (black) .. 1.0 (white).
     */
    public static function relative_luminance( string $hex ): float {
        $rgb = self::hex_to_rgb( $hex );
        if ( null === $rgb ) {
            return 0.0;
        }
        $channel = static function ( $c ) {
            $c = $c / 255;
            return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        };
        return 0.2126 * $channel( $rgb[0] ) + 0.7152 * $channel( $rgb[1] ) + 0.0722 * $channel( $rgb[2] );
    }

    /**
     * WCAG contrast ratio between two hex colors. 1..21.
     *
     * @param string $a `#rrggbb`.
     * @param string $b `#rrggbb`.
     * @return float     Ratio — 4.5 is AA for body, 3.0 is AA for large text.
     */
    public static function contrast_ratio( string $a, string $b ): float {
        $la = self::relative_luminance( $a );
        $lb = self::relative_luminance( $b );
        $light = max( $la, $lb );
        $dark  = min( $la, $lb );
        return ( $light + 0.05 ) / ( $dark + 0.05 );
    }

    /**
     * Parse `#rgb` or `#rrggbb` to a `[r, g, b]` int triple.
     *
     * Returns null when the input isn't a valid short/long hex — the
     * callers treat null as "pass the original value through".
     *
     * @param string $hex
     * @return array{0:int,1:int,2:int}|null
     */
    private static function hex_to_rgb( string $hex ): ?array {
        $hex = ltrim( trim( $hex ), '#' );
        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
            return null;
        }
        return array(
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        );
    }
}
