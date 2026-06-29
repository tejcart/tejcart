/**
 * Frequently Bought Together — checkbox + add-to-cart interactions.
 */
(function () {
    'use strict';

    var container = document.querySelector('[data-tejcart-fbt]');
    if (!container) {
        return;
    }

    var config     = window.TejCartRecommendations || {};
    var ajaxUrl    = config.ajaxUrl || '';
    var nonce      = config.nonce || '';
    var i18n       = config.i18n || {};
    var totalEl    = container.querySelector('[data-tejcart-fbt-total]');
    var addBtn     = container.querySelector('[data-tejcart-fbt-add]');
    var basePrice  = parseFloat(totalEl ? totalEl.getAttribute('data-base-price') : '0') || 0;

    function getCheckedItems() {
        var items = container.querySelectorAll('[data-tejcart-fbt-item]');
        var checked = [];
        for (var idx = 0; idx < items.length; idx++) {
            var cb = items[idx].querySelector('[data-tejcart-fbt-check]');
            if (cb && cb.checked) {
                checked.push({
                    el: items[idx],
                    productId: items[idx].getAttribute('data-product-id'),
                    price: parseFloat(items[idx].getAttribute('data-price')) || 0
                });
            }
        }
        return checked;
    }

    function updateTotal() {
        var checked = getCheckedItems();
        var total = basePrice;
        for (var c = 0; c < checked.length; c++) {
            total += checked[c].price;
        }

        if (totalEl) {
            totalEl.textContent = formatPrice(total);
        }

        if (addBtn) {
            addBtn.disabled = checked.length === 0;
        }
    }

    function formatPrice(amount) {
        var formatted = amount.toFixed(2);
        var currency = config.currency || 'USD';
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: currency
            }).format(amount);
        } catch (e) {
            return currency + ' ' + formatted;
        }
    }

    function addToCart(productId) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            resolve(data);
                        } else {
                            reject(new Error(data.data || 'Add to cart failed'));
                        }
                    } catch (e) {
                        reject(e);
                    }
                } else {
                    reject(new Error('HTTP ' + xhr.status));
                }
            };
            xhr.onerror = function () {
                reject(new Error('Network error'));
            };
            xhr.send(
                'action=tejcart_add_to_cart&product_id=' +
                encodeURIComponent(productId) +
                '&quantity=1&nonce=' +
                encodeURIComponent(nonce)
            );
        });
    }

    function handleAddAll(e) {
        e.preventDefault();

        if (addBtn.disabled) {
            return;
        }

        var checked = getCheckedItems();
        if (checked.length === 0) {
            return;
        }

        var currentProductId = container.getAttribute('data-current-product-id');
        var allIds = [currentProductId];
        for (var i = 0; i < checked.length; i++) {
            allIds.push(checked[i].productId);
        }

        addBtn.disabled = true;
        var originalLabel = addBtn.textContent;
        addBtn.textContent = i18n.adding || 'Adding...';

        var chain = Promise.resolve();
        for (var j = 0; j < allIds.length; j++) {
            (function (id) {
                chain = chain.then(function () {
                    return addToCart(id);
                });
            })(allIds[j]);
        }

        chain
            .then(function () {
                addBtn.textContent = i18n.added || 'Added to cart!';
                document.body.dispatchEvent(
                    new CustomEvent('tejcart-cart-updated', { bubbles: true })
                );
                setTimeout(function () {
                    addBtn.textContent = originalLabel;
                    addBtn.disabled = false;
                }, 2000);
            })
            .catch(function () {
                addBtn.textContent = originalLabel;
                addBtn.disabled = false;
            });
    }

    // Bind checkbox change events.
    var checkboxes = container.querySelectorAll('[data-tejcart-fbt-check]');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].addEventListener('change', updateTotal);
    }

    // Bind add-to-cart button.
    if (addBtn) {
        addBtn.addEventListener('click', handleAddAll);
    }
})();
