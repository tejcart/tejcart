/**
 * TejCart Variation Swatches — frontend JavaScript.
 *
 * Handles swatch click events, syncs the hidden <select> element so
 * TejCart's built-in variation matching fires, and optionally updates
 * the product image on hover.
 */
(function () {
    'use strict';

    /** @type {{ changeImageOnHover: boolean, swatchStyle: string, showTooltip: boolean }} */
    var config = window.tejcartSwatchesConfig || {};

    /**
     * Initialise all swatch wrappers on the page.
     */
    function init() {
        var wrappers = document.querySelectorAll('.tejcart-swatches-wrapper');
        wrappers.forEach(function (wrapper) {
            bindSwatchEvents(wrapper);
        });

        // Listen for TejCart's "× Clear" button to reset all swatches.
        document.querySelectorAll('[data-tejcart-variation-clear]').forEach(function (btn) {
            if (btn._tejcartSwatchClearBound) { return; }
            btn._tejcartSwatchClearBound = true;
            btn.addEventListener('click', function () {
                var container = btn.closest('[data-tejcart-variations]');
                if (!container) return;
                var allSwatches = container.querySelectorAll('.tejcart-swatch');
                deselectAll(allSwatches);
            });
        });
    }

    /**
     * Bind click (and optional hover) events to swatches inside a wrapper.
     *
     * @param {HTMLElement} wrapper  The .tejcart-swatches-wrapper element.
     */
    function bindSwatchEvents(wrapper) {
        // Idempotency guard — init() may run again after AJAX content
        // loads (e.g. the quick-view modal), so never double-bind a wrapper.
        if (wrapper._tejcartSwatchBound) {
            return;
        }

        var attribute = wrapper.getAttribute('data-attribute') || '';
        var select    = wrapper.querySelector('select');
        var swatches  = wrapper.querySelectorAll('.tejcart-swatch:not(.tejcart-swatch--compact)');

        if (!select || swatches.length === 0) {
            return;
        }

        wrapper._tejcartSwatchBound = true;

        swatches.forEach(function (swatch) {
            // Click — select this swatch.
            swatch.addEventListener('click', function (e) {
                e.preventDefault();

                if (swatch.classList.contains('tejcart-swatch--disabled')) {
                    return;
                }

                var value = swatch.getAttribute('data-value') || '';

                // Toggle: clicking the already-selected swatch deselects.
                if (swatch.classList.contains('tejcart-swatch--selected')) {
                    deselectAll(swatches);
                    setSelectValue(select, '');
                    fireVariationEvent(wrapper, attribute, '');
                    return;
                }

                deselectAll(swatches);
                applySelected(swatch);
                setSelectValue(select, value);
                fireVariationEvent(wrapper, attribute, value);
            });

            // Keyboard — Space / Enter trigger click.
            swatch.addEventListener('keydown', function (e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    swatch.click();
                }
            });

            // Optional: change product image on hover.
            if (config.changeImageOnHover) {
                swatch.addEventListener('mouseenter', function () {
                    if (swatch.classList.contains('tejcart-swatch--disabled')) {
                        return;
                    }
                    var value = swatch.getAttribute('data-value') || '';
                    fireImageHoverEvent(wrapper, attribute, value);
                });

                swatch.addEventListener('mouseleave', function () {
                    fireImageHoverEvent(wrapper, attribute, '');
                });
            }
        });

        // Sync swatches if the hidden select changes externally (e.g.
        // via a "clear" link or another script).
        select.addEventListener('change', function () {
            var val = select.value;
            deselectAll(swatches);
            if (val) {
                swatches.forEach(function (s) {
                    if (s.getAttribute('data-value') === val) {
                        applySelected(s);
                    }
                });
            }
        });
    }

    /**
     * Deselect all swatches in a NodeList.
     *
     * @param {NodeList} swatches
     */
    function deselectAll(swatches) {
        swatches.forEach(function (s) {
            s.classList.remove('tejcart-swatch--selected');
            s.setAttribute('aria-checked', 'false');
        });
    }

    /**
     * Mark a swatch as selected — CSS handles the visual via
     * [aria-checked="true"] selector with !important.
     *
     * @param {HTMLElement} swatch
     */
    function applySelected(swatch) {
        swatch.classList.add('tejcart-swatch--selected');
        swatch.setAttribute('aria-checked', 'true');
    }

    /**
     * Set the value of the hidden <select> and trigger its change event
     * so TejCart's variation matching picks up the new selection.
     *
     * @param {HTMLSelectElement} select
     * @param {string}           value
     */
    function setSelectValue(select, value) {
        select.value = value;

        // Dispatch a native change event.
        var event;
        if (typeof Event === 'function') {
            event = new Event('change', { bubbles: true });
        } else {
            // IE11 fallback (unlikely for TejCart's target, but safe).
            event = document.createEvent('Event');
            event.initEvent('change', true, true);
        }
        select.dispatchEvent(event);
    }

    /**
     * Fire a custom `tejcart_variation_selected` event on the wrapper
     * so other scripts (quick-view, analytics, etc.) can react.
     *
     * @param {HTMLElement} wrapper
     * @param {string}      attribute
     * @param {string}      value
     */
    function fireVariationEvent(wrapper, attribute, value) {
        var detail = {
            attribute: attribute,
            value: value,
            wrapper: wrapper
        };

        var event;
        if (typeof CustomEvent === 'function') {
            event = new CustomEvent('tejcart_variation_selected', {
                bubbles: true,
                detail: detail
            });
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent('tejcart_variation_selected', true, true, detail);
        }
        wrapper.dispatchEvent(event);

        // Also fire on document for global listeners.
        document.dispatchEvent(event);
    }

    /**
     * Fire a custom `tejcart_swatch_image_hover` event so the product
     * gallery can swap the displayed image.
     *
     * @param {HTMLElement} wrapper
     * @param {string}      attribute
     * @param {string}      value  Empty string means "revert to default".
     */
    function fireImageHoverEvent(wrapper, attribute, value) {
        var detail = {
            attribute: attribute,
            value: value,
            wrapper: wrapper
        };

        var event;
        if (typeof CustomEvent === 'function') {
            event = new CustomEvent('tejcart_swatch_image_hover', {
                bubbles: true,
                detail: detail
            });
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent('tejcart_swatch_image_hover', true, true, detail);
        }
        wrapper.dispatchEvent(event);
        document.dispatchEvent(event);
    }

    // Boot on DOMContentLoaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-initialise after AJAX content loads (e.g. quick-view modals).
    document.addEventListener('tejcart_content_loaded', init);
})();
