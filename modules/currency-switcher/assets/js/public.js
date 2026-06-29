/*
 * TejCart Currency Switcher — public-side helpers.
 *
 * Two responsibilities:
 *   1. Render the "~secondary" dual-price line next to base-currency
 *      amounts when checkout is in Mode B. Server-side rendering does
 *      this for cart/checkout markup; this script only enhances block
 *      checkout React subtrees that hydrate after page load.
 *   2. Drive the custom flag dropdown UI: toggle open/close, keyboard
 *      navigation, click-outside-to-close, and submit the underlying
 *      form whenever the visitor picks a new currency.
 *
 * No build step — this file is shipped as-is (and minified into
 * `public.min.js` by `bin/minify-assets.mjs`).
 */
(function () {
    'use strict';

    /* -------------------------------------------------------------- */
    /* 1) Dual-price helper                                           */
    /* -------------------------------------------------------------- */

    var data = window.tejcartCswPublicData || {};

    function formatNumber(amount) {
        var fixed = (Math.round(amount * Math.pow(10, data.displayDecimals)) / Math.pow(10, data.displayDecimals)).toFixed(data.displayDecimals);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, data.displayThousandSep);
        return parts.join(data.displayDecimalSep);
    }

    function formatWithSymbol(amount) {
        var num = formatNumber(amount);
        switch (data.displayCurrencyPos) {
            case 'right':       return num + data.displaySymbol;
            case 'left_space':  return data.displaySymbol + ' ' + num;
            case 'right_space': return num + ' ' + data.displaySymbol;
            default:            return data.displaySymbol + num;
        }
    }

    if (data && data.checkoutDualMode) {
        window.tejcartCswFormatSecondary = function (baseAmount) {
            if (typeof baseAmount !== 'number' || !isFinite(baseAmount)) {
                return '';
            }
            return '~' + formatWithSymbol(baseAmount * data.rate);
        };
    }

    /* -------------------------------------------------------------- */
    /* 2) Custom dropdown                                             */
    /* -------------------------------------------------------------- */

    var MOBILE_MEDIA = '(max-width: 768px)';

    function isMobileViewport() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia(MOBILE_MEDIA).matches;
    }

    // Track how many sheets are currently open so the body scroll lock
    // class only flips off when the last one closes.
    var openSheetCount = 0;
    function lockBodyScroll() {
        if (++openSheetCount === 1) {
            document.body.classList.add('tejcart-csw-sheet-open');
        }
    }
    function unlockBodyScroll() {
        if (openSheetCount > 0 && --openSheetCount === 0) {
            document.body.classList.remove('tejcart-csw-sheet-open');
        }
    }

    function initDropdown(root) {
        var trigger = root.querySelector('.tejcart-csw-dropdown__trigger');
        var list    = root.querySelector('.tejcart-csw-dropdown__list');
        var select  = root.querySelector('.tejcart-csw-dropdown__select');
        if (!trigger || !list || !select) {
            return;
        }

        var sheetLocked = false;

        var options = Array.prototype.slice.call(list.querySelectorAll('.tejcart-csw-dropdown__option'));
        if (options.length === 0) {
            return;
        }

        var focusIndex = options.findIndex(function (el) { return el.classList.contains('is-active'); });
        if (focusIndex < 0) { focusIndex = 0; }

        function open() {
            list.hidden = false;
            root.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            setFocus(focusIndex);
            if (isMobileViewport() && !sheetLocked) {
                sheetLocked = true;
                lockBodyScroll();
            }
        }

        function close() {
            list.hidden = true;
            root.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            if (sheetLocked) {
                sheetLocked = false;
                unlockBodyScroll();
            }
        }

        function setFocus(idx) {
            if (idx < 0) { idx = options.length - 1; }
            if (idx >= options.length) { idx = 0; }
            options.forEach(function (el, i) {
                el.classList.toggle('is-focus', i === idx);
            });
            focusIndex = idx;
            options[idx].scrollIntoView({ block: 'nearest' });
        }

        function pick(option) {
            var currency = option.getAttribute('data-currency');
            if (!currency) { return; }
            if (root.classList.contains('is-loading')) {
                return;
            }
            select.value = currency;
            // Mark the chosen option active so the loading state highlights
            // the right row, then enter the loading state so visitors see
            // immediate feedback while the form POST + page reload happens.
            options.forEach(function (el) { el.classList.remove('is-active'); });
            option.classList.add('is-active');
            options.forEach(function (el) { el.setAttribute('aria-selected', el === option ? 'true' : 'false'); });
            root.classList.add('is-loading');
            trigger.setAttribute('aria-busy', 'true');
            trigger.disabled = true;
            // Native change events drive form analytics + theme listeners.
            select.dispatchEvent(new Event('change', { bubbles: true }));
            // Submit the form so the cookie is set + page reloads.
            if (typeof root.requestSubmit === 'function') {
                root.requestSubmit();
            } else {
                root.submit();
            }
        }

        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            if (list.hidden) { open(); } else { close(); }
        });

        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });

        list.addEventListener('keydown', function (e) {
            switch (e.key) {
                case 'ArrowDown': e.preventDefault(); setFocus(focusIndex + 1); break;
                case 'ArrowUp':   e.preventDefault(); setFocus(focusIndex - 1); break;
                case 'Home':      e.preventDefault(); setFocus(0); break;
                case 'End':       e.preventDefault(); setFocus(options.length - 1); break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    pick(options[focusIndex]);
                    break;
                case 'Escape':
                    e.preventDefault();
                    close();
                    trigger.focus();
                    break;
            }
        });

        options.forEach(function (opt, idx) {
            opt.addEventListener('click', function (e) {
                e.preventDefault();
                focusIndex = idx;
                pick(opt);
            });
            opt.addEventListener('mouseenter', function () {
                setFocus(idx);
            });
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) {
                close();
            }
        });

        // Keep the list focusable so ArrowDown/Up reach it after open().
        list.setAttribute('tabindex', '-1');
    }

    /* -------------------------------------------------------------- */
    /* 3) Sidebar -> FAB pill + bottom sheet (mobile only)            */
    /* -------------------------------------------------------------- */

    function initSidebar(root) {
        var fab   = root.querySelector('.tejcart-csw-sidebar__fab');
        var close = root.querySelector('.tejcart-csw-sidebar__sheet-close');
        if (!fab) {
            return;
        }

        var sheetLocked = false;

        function openSheet() {
            // CSS only activates the sheet layout on mobile viewports;
            // on desktop the FAB is hidden and the list is permanently
            // visible, so toggling is-open is a no-op there.
            root.classList.add('is-open');
            fab.setAttribute('aria-expanded', 'true');
            if (isMobileViewport() && !sheetLocked) {
                sheetLocked = true;
                lockBodyScroll();
            }
        }

        function closeSheet() {
            root.classList.remove('is-open');
            fab.setAttribute('aria-expanded', 'false');
            if (sheetLocked) {
                sheetLocked = false;
                unlockBodyScroll();
            }
        }

        fab.addEventListener('click', function (e) {
            e.preventDefault();
            if (root.classList.contains('is-open')) {
                closeSheet();
            } else {
                openSheet();
            }
        });

        if (close) {
            close.addEventListener('click', function (e) {
                e.preventDefault();
                closeSheet();
            });
        }

        // Click on the dimmed backdrop (anywhere outside the sheet) closes.
        document.addEventListener('click', function (e) {
            if (!root.classList.contains('is-open')) {
                return;
            }
            if (root.contains(e.target)) {
                return;
            }
            closeSheet();
        });

        // ESC dismisses the sheet.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && root.classList.contains('is-open')) {
                closeSheet();
                fab.focus();
            }
        });

        // If the viewport resizes from mobile -> desktop while the sheet
        // is open, drop the open state so we don't strand a stuck overlay.
        if (typeof window.matchMedia === 'function') {
            var mq = window.matchMedia(MOBILE_MEDIA);
            var onChange = function () {
                if (!mq.matches && root.classList.contains('is-open')) {
                    closeSheet();
                }
            };
            if (typeof mq.addEventListener === 'function') {
                mq.addEventListener('change', onChange);
            } else if (typeof mq.addListener === 'function') {
                mq.addListener(onChange);
            }
        }
    }

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var dropdowns = document.querySelectorAll('[data-tejcart-csw-dropdown]');
        Array.prototype.forEach.call(dropdowns, initDropdown);
        var sidebars = document.querySelectorAll('[data-tejcart-csw-sidebar]');
        Array.prototype.forEach.call(sidebars, initSidebar);
    });

    // Reset the loading state if the page is restored from the
    // back-forward cache (Safari / Firefox keep the DOM around and
    // re-render it on history navigation — the previous loading state
    // would otherwise look stuck).
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) { return; }
        var roots = document.querySelectorAll('[data-tejcart-csw-dropdown].is-loading');
        Array.prototype.forEach.call(roots, function (root) {
            root.classList.remove('is-loading');
            var trigger = root.querySelector('.tejcart-csw-dropdown__trigger');
            if (trigger) {
                trigger.removeAttribute('aria-busy');
                trigger.disabled = false;
            }
        });
    });
})();
