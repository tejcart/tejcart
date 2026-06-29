/**
 * TejCart AI Content SmartSuite — frontend FAQ accordion controller.
 *
 *  - Smooth height-animated expand / collapse (works around the
 *    native <details> "snap" behaviour).
 *  - Single-open by default — opening one question collapses the rest.
 *  - Deep-link support — visiting `?...#tejcart-faq-12-3` auto-opens
 *    that question, scrolls it into view, and briefly highlights it.
 *  - Click the "Link to this question" chip to copy the absolute URL
 *    of that question to the clipboard (with graceful fallback).
 *
 * Pure vanilla JS, no framework, ~2 KB minified.
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia
        ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
        : false;

    var COPIED_LABEL = 'Copied';

    function $$(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function init() {
        var roots = $$('[data-tejcart-faq]');
        if (!roots.length) {
            return;
        }
        roots.forEach(bindRoot);
        handleInitialHash();
        window.addEventListener('hashchange', handleInitialHash);
    }

    function bindRoot(root) {
        var items = $$('.tejcart-faq__item', root);

        items.forEach(function (item) {
            var btn   = item.querySelector('[data-tejcart-faq-toggle]');
            var panel = item.querySelector('.tejcart-faq__answer');
            if (!btn || !panel) {
                return;
            }

            // End-state on transition end keeps height auto so resizes
            // (window resize, font swap, dynamic content) stay correct.
            panel.addEventListener('transitionend', function (e) {
                if (e.propertyName !== 'height') { return; }
                if (item.classList.contains('is-open')) {
                    panel.style.height = 'auto';
                }
            });

            btn.addEventListener('click', function () {
                toggle(item, !item.classList.contains('is-open'), items);
            });
        });

        // Permalink copy buttons.
        $$('[data-tejcart-faq-permalink]', root).forEach(function (link) {
            link.setAttribute('data-copied-label', COPIED_LABEL);
            link.addEventListener('click', function (e) {
                var url = absoluteUrlFor(link);
                if (!url) { return; }
                e.preventDefault();
                copyToClipboard(url).then(function () {
                    flashCopied(link);
                });
                // Update browser URL without scroll-jumping.
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', link.getAttribute('href'));
                }
            });
        });
    }

    function toggle(item, open, siblings) {
        var panel = item.querySelector('.tejcart-faq__answer');
        var btn   = item.querySelector('[data-tejcart-faq-toggle]');
        if (!panel || !btn) { return; }

        if (open && siblings) {
            siblings.forEach(function (other) {
                if (other !== item && other.classList.contains('is-open')) {
                    toggle(other, false, null);
                }
            });
        }

        if (open) {
            panel.hidden = false;
            item.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');

            if (reduceMotion) {
                panel.style.height = 'auto';
                return;
            }
            var target = panel.scrollHeight;
            panel.style.height = '0px';
            // Force layout, then animate.
            void panel.offsetHeight;
            panel.style.height = target + 'px';
        } else {
            item.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');

            if (reduceMotion) {
                panel.style.height = '0px';
                panel.hidden = true;
                return;
            }
            // From auto -> measured px -> 0 so transition runs.
            var current = panel.getBoundingClientRect().height;
            panel.style.height = current + 'px';
            void panel.offsetHeight;
            panel.style.height = '0px';

            // Hide after the transition for a11y / tab order.
            var onEnd = function (e) {
                if (e.propertyName !== 'height') { return; }
                panel.removeEventListener('transitionend', onEnd);
                if (!item.classList.contains('is-open')) {
                    panel.hidden = true;
                }
            };
            panel.addEventListener('transitionend', onEnd);
        }
    }

    function handleInitialHash() {
        var hash = (window.location.hash || '').replace(/^#/, '');
        if (!hash) { return; }
        var target = document.getElementById(hash);
        if (!target || !target.classList.contains('tejcart-faq__item')) { return; }
        var siblings = $$('.tejcart-faq__item', target.parentNode);
        toggle(target, true, siblings);
        // Slight delay so the height transition can run before scroll.
        setTimeout(function () {
            target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
            target.classList.add('is-flash');
            setTimeout(function () { target.classList.remove('is-flash'); }, 1400);
        }, 50);
    }

    function absoluteUrlFor(link) {
        var href = link.getAttribute('href');
        if (!href || href.charAt(0) !== '#') { return ''; }
        return window.location.origin + window.location.pathname + window.location.search + href;
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).catch(function () { return fallbackCopy(text); });
        }
        return fallbackCopy(text);
    }

    function fallbackCopy(text) {
        return new Promise(function (resolve) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* noop */ }
            document.body.removeChild(ta);
            resolve();
        });
    }

    function flashCopied(link) {
        link.classList.add('is-copied');
        setTimeout(function () { link.classList.remove('is-copied'); }, 1500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
