<?php
/**
 * Reusable card-style flash notice for TejCart admin pages.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders a polished, on-brand post-action confirmation banner for any
 * TejCart admin page. Replaces the stock `<div class="notice notice-*">`
 * markup with a card-style flash that matches the rest of the admin
 * design system: filled tone-coloured icon medallion, bold title plus an
 * optional secondary detail line, a properly styled dismiss X, and a
 * subtle slide-down entry animation that respects `prefers-reduced-motion`.
 *
 * Usage from anywhere in admin-page render code:
 *
 *   \TejCart\Admin\Flash_Notice::render(
 *       'Settings saved.',
 *       'Changes apply on the next page load.',
 *       Flash_Notice::TONE_SUCCESS,
 *   );
 *
 * Or via the global wrapper:
 *
 *   tejcart_admin_flash( 'Settings saved.' );
 *   tejcart_admin_flash( 'Refund failed.', 'Check the gateway log for details.', 'error' );
 *
 * Critical layout CSS is emitted inline alongside the markup the first
 * time `render()` runs on a page, so the banner renders correctly even
 * when a host edge cache, CDN, or asset-optimiser is serving a stale
 * `assets/css/tejcart-admin.css`. The same rules also live in the
 * stylesheet so themes can override.
 *
 * The class is intentionally stateless and final — it is a presentation
 * helper, not a service. Tests should call `reset_print_flags()` between
 * cases that need to assert on the inline CSS/JS being emitted again.
 */
final class Flash_Notice {

    public const TONE_SUCCESS = 'success';
    public const TONE_ERROR   = 'error';
    public const TONE_WARNING = 'warning';
    public const TONE_INFO    = 'info';

    /**
     * Tracks whether the inline critical CSS has already been printed
     * on this page. Multiple notices on the same page share one CSS
     * block to avoid duplicate rules in the DOM.
     */
    private static bool $css_printed = false;

    /**
     * Same idea for the dismiss handler — one script per page covers
     * every notice rendered on it.
     */
    private static bool $js_printed = false;

    /**
     * Render the flash banner. Outputs HTML directly (no return value).
     *
     * @param string $title       Primary message line. Plain text, escaped on output.
     * @param string $detail      Optional secondary line. Plain text, escaped on output.
     * @param string $tone        One of the TONE_* constants. Defaults to success.
     * @param bool   $dismissible Whether to render the dismiss X button. Defaults to true.
     */
    public static function render(
        string $title,
        string $detail = '',
        string $tone = self::TONE_SUCCESS,
        bool $dismissible = true
    ): void {
        $title = trim( $title );
        if ( '' === $title ) {
            return;
        }

        $tone = self::normalise_tone( $tone );

        self::maybe_print_critical_css();

        $classes = sprintf( 'tejcart-admin-flash tejcart-admin-flash--%s', $tone );
        ?>
        <div class="<?php echo esc_attr( $classes ); ?>"
             role="<?php echo esc_attr( self::TONE_ERROR === $tone ? 'alert' : 'status' ); ?>"
             aria-live="<?php echo esc_attr( self::TONE_ERROR === $tone ? 'assertive' : 'polite' ); ?>"
             data-tejcart-flash>
            <span class="tejcart-admin-flash__icon" aria-hidden="true">
                <?php echo tejcart_kses_svg( self::icon_svg( $tone ) ); ?>
            </span>
            <div class="tejcart-admin-flash__body">
                <p class="tejcart-admin-flash__title"><?php echo esc_html( $title ); ?></p>
                <?php if ( '' !== $detail ) : ?>
                    <p class="tejcart-admin-flash__detail"><?php echo esc_html( $detail ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( $dismissible ) : ?>
                <button type="button" class="tejcart-admin-flash__dismiss" data-tejcart-flash-dismiss
                        aria-label="<?php esc_attr_e( 'Dismiss this notice', 'tejcart' ); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                </button>
            <?php endif; ?>
        </div>
        <?php

        if ( $dismissible ) {
            self::maybe_print_dismiss_js();
        }
    }

    /**
     * Reset the printed-flags. Tests only — production code should never
     * call this. Keeps the renderer testable in random-order PHPUnit runs.
     */
    public static function reset_print_flags(): void {
        self::$css_printed = false;
        self::$js_printed  = false;
    }

    private static function normalise_tone( string $tone ): string {
        $tone = strtolower( trim( $tone ) );
        $valid = array( self::TONE_SUCCESS, self::TONE_ERROR, self::TONE_WARNING, self::TONE_INFO );
        return in_array( $tone, $valid, true ) ? $tone : self::TONE_SUCCESS;
    }

    private static function icon_svg( string $tone ): string {
        $paths = array(
            // Filled check (success).
            self::TONE_SUCCESS => '<path d="M20 6 9 17l-5-5"/>',
            // Filled circle with X (error).
            self::TONE_ERROR   => '<circle cx="12" cy="12" r="0.5" stroke="none" fill="currentColor"/><path d="M12 8v5"/><path d="M12 16.5v.01"/>',
            // Triangle with exclamation (warning).
            self::TONE_WARNING => '<path d="M12 9v4"/><path d="M12 16.5v.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>',
            // Info (i in circle).
            self::TONE_INFO    => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        );

        $path  = $paths[ $tone ] ?? $paths[ self::TONE_SUCCESS ];
        $width = self::TONE_SUCCESS === $tone ? '2.6' : '2';

        return sprintf(
            '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" focusable="false">%s</svg>',
            esc_attr( $width ),
            $path
        );
    }

    private static function maybe_print_critical_css(): void {
        if ( self::$css_printed ) {
            return;
        }
        self::$css_printed = true;
        // phpcs:disable Generic.WhiteSpace.ScopeIndent.IncorrectExact
        ?>
<style id="tejcart-admin-flash-inline-css">
.tejcart-admin-flash{display:flex;align-items:flex-start;gap:14px;margin:0 0 22px;padding:14px 16px;background:#fff;border:1px solid #e0e0e0;border-left:4px solid #6b7280;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.05);box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;animation:tejcart-admin-flash-in 220ms cubic-bezier(.16,1,.3,1)}
.tejcart-admin-flash.is-dismissing{opacity:0;transform:translateY(-4px);transition:opacity 140ms ease,transform 140ms ease}
.tejcart-admin-flash--success{border-left-color:#00a32a;background:linear-gradient(180deg,rgba(0,163,42,.04) 0%,rgba(255,255,255,0) 80%),#fff}
.tejcart-admin-flash--error{border-left-color:#d63638;background:linear-gradient(180deg,rgba(214,54,56,.04) 0%,rgba(255,255,255,0) 80%),#fff}
.tejcart-admin-flash--warning{border-left-color:#dba617;background:linear-gradient(180deg,rgba(219,166,23,.05) 0%,rgba(255,255,255,0) 80%),#fff}
.tejcart-admin-flash--info{border-left-color:#2271b1;background:linear-gradient(180deg,rgba(34,113,177,.04) 0%,rgba(255,255,255,0) 80%),#fff}
.tejcart-admin-flash__icon{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;margin-top:1px;border-radius:999px;color:#fff;box-sizing:border-box}
.tejcart-admin-flash--success .tejcart-admin-flash__icon{background:#00a32a;box-shadow:0 0 0 4px rgba(0,163,42,.12)}
.tejcart-admin-flash--error .tejcart-admin-flash__icon{background:#d63638;box-shadow:0 0 0 4px rgba(214,54,56,.12)}
.tejcart-admin-flash--warning .tejcart-admin-flash__icon{background:#dba617;box-shadow:0 0 0 4px rgba(219,166,23,.14)}
.tejcart-admin-flash--info .tejcart-admin-flash__icon{background:#2271b1;box-shadow:0 0 0 4px rgba(34,113,177,.12)}
.tejcart-admin-flash__icon svg{display:block}
.tejcart-admin-flash__body{flex:1 1 auto;min-width:0}
.tejcart-admin-flash__title{margin:0;padding:0;color:#23282d;font-size:14px;font-weight:600;line-height:1.4}
.tejcart-admin-flash__detail{margin:3px 0 0;padding:0;color:#6b7280;font-size:13px;line-height:1.5;font-weight:400}
.tejcart-admin-flash__dismiss{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;margin:-3px -4px 0 4px;padding:0;background:transparent;border:0;border-radius:4px;color:#9ca3af;cursor:pointer;transition:all .15s ease;box-sizing:border-box}
.tejcart-admin-flash__dismiss:hover,.tejcart-admin-flash__dismiss:focus-visible{color:#1e1e1e;background:#f9fafb;outline:0}
.tejcart-admin-flash__dismiss:focus-visible{box-shadow:0 0 0 2px rgba(0,0,0,.12)}
.tejcart-admin-flash__dismiss svg{display:block}
@keyframes tejcart-admin-flash-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
@media (prefers-reduced-motion:reduce){.tejcart-admin-flash,.tejcart-admin-flash.is-dismissing{animation:none;transition:none}}
</style>
        <?php
        // phpcs:enable
    }

    private static function maybe_print_dismiss_js(): void {
        if ( self::$js_printed ) {
            return;
        }
        self::$js_printed = true;
        // Lightweight vanilla-JS handler: fade out + remove the node,
        // and scrub the `?updated=` / `?intent=` / `?message=` query
        // args via History API so a refresh does not resurrect a toast
        // for an action the operator already acknowledged.
        ?>
<script id="tejcart-admin-flash-inline-js">
(function(){if(window.__tejcartFlashBound){return}window.__tejcartFlashBound=true;
function scrubUrl(){if(!window.history||typeof window.history.replaceState!=='function'){return}
try{var url=new URL(window.location.href);['updated','intent','message','settings-updated'].forEach(function(k){url.searchParams.delete(k)});window.history.replaceState({},'',url.toString())}catch(e){}}
document.addEventListener('click',function(e){var btn=e.target&&e.target.closest&&e.target.closest('[data-tejcart-flash-dismiss]');if(!btn){return}
var flash=btn.closest('[data-tejcart-flash]');if(!flash){return}
flash.classList.add('is-dismissing');setTimeout(function(){if(flash.parentNode){flash.parentNode.removeChild(flash)}},160);scrubUrl()})})();
</script>
        <?php
    }
}
