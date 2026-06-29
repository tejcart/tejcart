/*
 * TejCart Currency Switcher — admin helpers.
 *
 * Wires the four interactions added in the WC-parity refresh:
 *
 *  - Add Currency / delete row / refresh per-row rate (Currencies tab)
 *  - Per-row "settings" modal (fee + format fields stored in hidden inputs)
 *  - Chip toggle highlights on the Display tab
 *  - Live Test Price → Final Price preview on the Pricing tab
 *
 * All work is server-side rendered first; this script is progressive
 * enhancement on top of the standard form. Disabling JS leaves the
 * admin functional (a refresh button just won't refresh inline,
 * deletions can't happen, etc.) — the merchant can still Save the form.
 */
(function () {
    'use strict';

    var data = window.tejcartCswAdminData || {};
    var doc  = document;

    if (!data.ajaxUrl) { return; }

    // ----- Helpers --------------------------------------------------------

    function postAjax(action, params) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('tejcart_csw_nonce', data.nonce || '');
        Object.keys(params || {}).forEach(function (key) {
            body.append(key, params[key]);
        });
        return fetch(data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (r) { return r.json().then(function (json) { return { ok: r.ok, body: json }; }); });
    }

    function nextRowIndex() {
        nextRowIndex.counter = (nextRowIndex.counter || 0) + 1;
        return 'new-' + nextRowIndex.counter;
    }

    function setRowIndex(row, index) {
        if (!row) { return; }
        row.querySelectorAll('[name]').forEach(function (input) {
            input.name = input.name.replace(/currencies\[[^\]]+\]/, 'currencies[' + index + ']');
        });
        var labels = row.querySelectorAll('label[for]');
        labels.forEach(function (lbl) {
            lbl.setAttribute('for', lbl.getAttribute('for').replace(/__INDEX__/g, index));
        });
    }

    // ----- Currencies tab -------------------------------------------------

    function initCurrencies() {
        var tbody = doc.querySelector('[data-csw-rows]');
        var tpl   = doc.querySelector('[data-csw-row-template]');
        if (!tbody || !tpl) { return; }

        // Add Currency
        var addBtn = doc.querySelector('[data-csw-action="add-currency"]');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                var content = tpl.content.firstElementChild
                    ? tpl.content.firstElementChild.cloneNode(true)
                    : null;
                if (!content) { return; }
                setRowIndex(content, nextRowIndex());
                tbody.appendChild(content);
                var sel = content.querySelector('[data-csw-field="code"]');
                if (sel) { sel.focus(); }
            });
        }

        // Delegate per-row actions
        tbody.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-csw-action]');
            if (!btn) { return; }
            var row = btn.closest('[data-csw-row]');
            if (!row) { return; }
            var action = btn.getAttribute('data-csw-action');

            if (action === 'delete-row') {
                event.preventDefault();
                if (!window.confirm(data.i18n && data.i18n.confirmDelete ? data.i18n.confirmDelete : 'Remove this currency?')) {
                    return;
                }
                row.parentNode.removeChild(row);
                return;
            }

            if (action === 'refresh-rate') {
                event.preventDefault();
                refreshRow(row, btn);
                return;
            }

            if (action === 'open-settings') {
                event.preventDefault();
                openSettingsModal(row);
                return;
            }
        });

        // Auto-fetch rate when the code dropdown changes (handle bubbling)
        tbody.addEventListener('change', function (event) {
            var field = event.target.getAttribute('data-csw-field');
            if (field !== 'code') { return; }
            var row = event.target.closest('[data-csw-row]');
            if (!row) { return; }
            var typeSel = row.querySelector('[data-csw-field="rate_type"]');
            if (typeSel) { typeSel.value = 'Auto'; }
            refreshRow(row, event.target);
        });
    }

    function refreshRow(row, triggerEl) {
        var sel  = row.querySelector('[data-csw-field="code"]');
        var rate = row.querySelector('[data-csw-field="rate"]');
        if (!sel || !rate || !sel.value) { return; }

        if (triggerEl && triggerEl.classList) {
            triggerEl.classList.add('is-busy');
            if (triggerEl.tagName === 'BUTTON') { triggerEl.disabled = true; }
        }

        postAjax('tejcart_csw_fetch_rate', { code: sel.value })
            .then(function (resp) {
                if (resp.ok && resp.body && resp.body.success && resp.body.data && typeof resp.body.data.rate === 'number') {
                    rate.value = resp.body.data.rate;
                    row.setAttribute('data-csw-code', sel.value);
                }
            })
            .finally(function () {
                if (triggerEl && triggerEl.classList) {
                    triggerEl.classList.remove('is-busy');
                    if (triggerEl.tagName === 'BUTTON') { triggerEl.disabled = false; }
                }
            });
    }

    // ----- Settings modal -------------------------------------------------

    var modalRow = null;

    function openSettingsModal(row) {
        var dlg = doc.querySelector('[data-csw-modal]');
        if (!dlg) { return; }
        modalRow = row;

        // Populate modal fields from the row's hidden inputs.
        var code = row.querySelector('[data-csw-field="code"]');
        var codeEl = dlg.querySelector('[data-csw-modal-code]');
        if (codeEl && code) { codeEl.textContent = code.value; }

        dlg.querySelectorAll('[data-csw-modal-field]').forEach(function (input) {
            var key = input.getAttribute('data-csw-modal-field');
            var src = row.querySelector('[data-csw-field="' + key + '"]');
            if (src) {
                input.value = src.value;
            }
        });

        if (typeof dlg.showModal === 'function') {
            dlg.showModal();
        } else {
            dlg.setAttribute('open', 'open');
        }
    }

    function closeSettingsModal() {
        var dlg = doc.querySelector('[data-csw-modal]');
        if (!dlg) { return; }
        if (typeof dlg.close === 'function' && dlg.open) {
            dlg.close();
        } else {
            dlg.removeAttribute('open');
        }
        modalRow = null;
    }

    function applySettingsModal() {
        var dlg = doc.querySelector('[data-csw-modal]');
        if (!dlg || !modalRow) { closeSettingsModal(); return; }

        dlg.querySelectorAll('[data-csw-modal-field]').forEach(function (input) {
            var key = input.getAttribute('data-csw-modal-field');
            var dst = modalRow.querySelector('[data-csw-field="' + key + '"]');
            if (dst) { dst.value = input.value; }
        });

        closeSettingsModal();
    }

    function initModal() {
        var dlg = doc.querySelector('[data-csw-modal]');
        if (!dlg) { return; }
        dlg.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-csw-action]');
            if (!btn) { return; }
            var action = btn.getAttribute('data-csw-action');
            if (action === 'close-modal') { event.preventDefault(); closeSettingsModal(); }
            if (action === 'apply-modal') { event.preventDefault(); applySettingsModal(); }
        });
        dlg.addEventListener('cancel', function () { modalRow = null; });
    }

    // ----- Display chip toggles ------------------------------------------

    function initChips() {
        var chips = doc.querySelectorAll('.tejcart-csw-chip input[type="checkbox"]');
        chips.forEach(function (input) {
            input.addEventListener('change', function () {
                var chip = input.closest('.tejcart-csw-chip');
                if (!chip) { return; }
                if (input.checked) {
                    chip.classList.add('is-selected');
                } else {
                    chip.classList.remove('is-selected');
                }
            });
        });
    }

    // ----- Pricing live preview ------------------------------------------

    function priceAdjust(price, rounding, charm) {
        if (!isFinite(price) || price <= 0 || !rounding || rounding <= 0) {
            return price;
        }
        var rounded;
        if (rounding === 99) {
            rounded = (Math.ceil(price / 100) * 100) - 1;
        } else {
            rounded = Math.ceil(price / rounding) * rounding;
        }
        if (charm > 0 && charm < rounding) {
            rounded -= (rounding - charm);
        }
        return rounded;
    }

    function updatePricingRow(row) {
        var enabled  = row.querySelector('[data-csw-pricing-field="enabled"]');
        var rounding = row.querySelector('[data-csw-pricing-field="rounding"]');
        var charm    = row.querySelector('[data-csw-pricing-field="charm"]');
        var test     = row.querySelector('[data-csw-pricing-field="test"]');
        var final_   = row.querySelector('[data-csw-pricing-final]');
        if (!final_) { return; }

        var price = test ? parseFloat(test.value) : NaN;
        if (!isFinite(price)) { final_.textContent = '—'; return; }

        if (!enabled || !enabled.checked || !rounding || !rounding.value) {
            final_.textContent = price.toFixed(2);
            return;
        }
        var r = parseInt(rounding.value, 10);
        var c = charm ? parseFloat(charm.value) : 0;
        var result = priceAdjust(price, r, isFinite(c) ? c : 0);
        final_.textContent = result.toFixed(2);
    }

    function initPricing() {
        var table = doc.querySelector('[data-csw-pricing]');
        if (!table) { return; }
        var rows = table.querySelectorAll('[data-csw-pricing-row]');
        rows.forEach(function (row) {
            updatePricingRow(row);
            row.addEventListener('input', function () { updatePricingRow(row); });
            row.addEventListener('change', function () { updatePricingRow(row); });
        });
    }

    // ----- Boot -----------------------------------------------------------

    if (doc.readyState === 'loading') {
        doc.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    function boot() {
        initCurrencies();
        initModal();
        initChips();
        initPricing();
    }
})();
