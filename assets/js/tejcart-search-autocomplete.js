(function () {
    'use strict';

    var config = window.tejcartSearch || {};
    var DEBOUNCE_MS = config.debounce || 300;
    var MIN_CHARS   = config.minChars || 2;
    var REST_URL    = config.restUrl || '';
    var NO_RESULTS  = config.noResults || 'No products found.';
    var VIEW_ALL    = config.viewAll || 'View all results';
    var LIMIT       = config.limit || 8;
    var SHOP_URL    = config.shopUrl || '';

    if (!REST_URL) {
        return;
    }

    var inputs = document.querySelectorAll('[data-tejcart-autocomplete="true"]');
    if (!inputs.length) {
        return;
    }

    var instanceCounter = 0;

    function debounce(fn, ms) {
        var timer;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    function createDropdown(input) {
        var uid  = 'tejcart-ac-' + (++instanceCounter);
        var wrap = document.createElement('div');
        wrap.className = 'tejcart-autocomplete-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var dropdown = document.createElement('div');
        dropdown.className = 'tejcart-autocomplete-dropdown';
        dropdown.id = uid + '-listbox';
        dropdown.setAttribute('role', 'listbox');
        dropdown.setAttribute('aria-label', 'Search suggestions');
        wrap.appendChild(dropdown);

        input.setAttribute('aria-controls', dropdown.id);

        var liveRegion = document.createElement('div');
        liveRegion.className = 'tejcart-sr-only';
        liveRegion.setAttribute('role', 'status');
        liveRegion.setAttribute('aria-live', 'polite');
        liveRegion.setAttribute('aria-atomic', 'true');
        wrap.appendChild(liveRegion);

        return { dropdown: dropdown, liveRegion: liveRegion, uid: uid };
    }

    function buildItem(result, uid, index) {
        var a = document.createElement('a');
        a.className = 'tejcart-autocomplete-item';
        a.href = result.url || '#';
        a.id = uid + '-opt-' + index;
        a.setAttribute('role', 'option');

        if (result.image) {
            var img = document.createElement('img');
            img.className = 'tejcart-autocomplete-item__image';
            img.src = result.image;
            img.alt = result.name;
            img.loading = 'lazy';
            img.width = 40;
            img.height = 40;
            a.appendChild(img);
        } else {
            var ph = document.createElement('span');
            ph.className = 'tejcart-autocomplete-item__image--placeholder';
            a.appendChild(ph);
        }

        var details = document.createElement('span');
        details.className = 'tejcart-autocomplete-item__details';

        var name = document.createElement('span');
        name.className = 'tejcart-autocomplete-item__name';
        name.textContent = result.name;
        details.appendChild(name);

        if (result.price_html) {
            var price = document.createElement('span');
            price.className = 'tejcart-autocomplete-item__price';
            price.innerHTML = result.price_html;
            details.appendChild(price);
        }

        a.appendChild(details);
        return a;
    }

    function buildViewAllLink(query) {
        var base = SHOP_URL || window.location.pathname;
        var a = document.createElement('a');
        a.className = 'tejcart-autocomplete-view-all';
        a.href = base + (base.indexOf('?') > -1 ? '&' : '?') + 'tejcart_s=' + encodeURIComponent(query);
        a.textContent = VIEW_ALL + ' →';
        return a;
    }

    function fetchSuggestions(query, signal, callback) {
        var url = REST_URL + '?q=' + encodeURIComponent(query) + '&limit=' + LIMIT;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        if (config.nonce) {
            xhr.setRequestHeader('X-WP-Nonce', config.nonce);
        }

        signal.xhr = xhr;

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (signal.aborted) return;
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    callback(null, data.results || []);
                } catch (e) {
                    callback(e, []);
                }
            } else {
                callback(new Error('HTTP ' + xhr.status), []);
            }
        };
        xhr.send();
    }

    function initInput(input) {
        var parts       = createDropdown(input);
        var dropdown    = parts.dropdown;
        var liveRegion  = parts.liveRegion;
        var uid         = parts.uid;
        var activeIndex = -1;
        var currentSignal = null;

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        function open() {
            dropdown.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
        }

        function close() {
            dropdown.classList.remove('is-open');
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        }

        function setActive(index) {
            var items = dropdown.querySelectorAll('.tejcart-autocomplete-item');
            items.forEach(function (el) { el.classList.remove('is-active'); });
            if (index >= 0 && index < items.length) {
                items[index].classList.add('is-active');
                items[index].scrollIntoView({ block: 'nearest' });
                input.setAttribute('aria-activedescendant', items[index].id);
                activeIndex = index;
            } else {
                input.removeAttribute('aria-activedescendant');
                activeIndex = -1;
            }
        }

        function abortPending() {
            if (currentSignal && currentSignal.xhr) {
                currentSignal.aborted = true;
                currentSignal.xhr.abort();
            }
            currentSignal = null;
        }

        var doSearch = debounce(function () {
            var q = input.value.trim();
            if (q.length < MIN_CHARS) {
                abortPending();
                close();
                liveRegion.textContent = '';
                return;
            }

            abortPending();
            var signal = { aborted: false, xhr: null };
            currentSignal = signal;

            dropdown.innerHTML = '<div class="tejcart-autocomplete-loading"></div>';
            open();

            fetchSuggestions(q, signal, function (err, results) {
                if (signal.aborted) return;

                dropdown.innerHTML = '';

                if (err || !results.length) {
                    var noRes = document.createElement('div');
                    noRes.className = 'tejcart-autocomplete-no-results';
                    noRes.textContent = NO_RESULTS;
                    dropdown.appendChild(noRes);
                    liveRegion.textContent = NO_RESULTS;
                    open();
                    return;
                }

                results.forEach(function (r, i) {
                    dropdown.appendChild(buildItem(r, uid, i));
                });

                if (results.length >= LIMIT) {
                    dropdown.appendChild(buildViewAllLink(q));
                }

                liveRegion.textContent = results.length + ' suggestions available';
                activeIndex = -1;
                open();
            });
        }, DEBOUNCE_MS);

        input.addEventListener('input', doSearch);

        input.addEventListener('keydown', function (e) {
            var items = dropdown.querySelectorAll('.tejcart-autocomplete-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(activeIndex < items.length - 1 ? activeIndex + 1 : 0);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(activeIndex > 0 ? activeIndex - 1 : items.length - 1);
            } else if (e.key === 'Enter' && activeIndex >= 0) {
                e.preventDefault();
                var href = items[activeIndex].getAttribute('href');
                if (href && href !== '#') {
                    window.location.href = href;
                }
            } else if (e.key === 'Escape') {
                close();
                input.focus();
            }
        });

        dropdown.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });

        document.addEventListener('click', function (e) {
            if (!dropdown.parentNode.contains(e.target)) {
                close();
            }
        });

        var inputRect = null;
        window.addEventListener('scroll', function () {
            if (!dropdown.classList.contains('is-open')) return;
            var rect = input.getBoundingClientRect();
            if (!inputRect) {
                inputRect = rect;
                return;
            }
            if (rect.bottom < 0 || rect.top > window.innerHeight) {
                close();
            }
            inputRect = rect;
        }, { passive: true });

        input.addEventListener('focus', function () {
            if (dropdown.children.length > 0 && input.value.trim().length >= MIN_CHARS) {
                open();
            }
        });

        input.addEventListener('blur', function (e) {
            setTimeout(function () {
                if (!dropdown.parentNode.contains(document.activeElement)) {
                    close();
                }
            }, 150);
        });
    }

    inputs.forEach(initInput);
})();
