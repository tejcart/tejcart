/**
 * TejCart Variation Swatches — product-edit swatch editor.
 *
 * Adds, to each attribute card in the product Variations tab, a "Swatch
 * style" select plus a per-value colour picker. Common colour names are
 * pre-filled automatically (zero configuration); merchants can override
 * any value or switch an attribute to plain text buttons. The result is
 * serialised into a hidden field saved as product meta.
 */
(function () {
    'use strict';

    var cfg   = window.tejcartSwatchEditor || {};
    var saved = cfg.config || {};
    var names = cfg.names || {};
    var i18n  = cfg.i18n || {};

    /**
     * Mirror WordPress's sanitize_key() so attribute keys match server-side.
     *
     * @param {string} s
     * @return {string}
     */
    function sanitizeKey(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9_\-]/g, '');
    }

    /**
     * Expand a 3-digit hex to 6 digits (input[type=color] needs 6).
     *
     * @param {string} hex  Hex digits without leading '#'.
     * @return {string}
     */
    function expandHex(hex) {
        if (hex.length === 3) {
            return hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
        }
        return hex;
    }

    /**
     * Resolve a value to a hex colour (named colour or raw hex), or '' .
     *
     * @param {string} val
     * @return {string}
     */
    function autoColor(val) {
        var v = String(val || '').toLowerCase().trim();
        if (!v) {
            return '';
        }
        var hex = v.replace(/^#/, '');
        if (/^[0-9a-f]{3}$/.test(hex) || /^[0-9a-f]{6}$/.test(hex)) {
            return '#' + expandHex(hex);
        }
        var key = v.replace(/[\s_\-]+/g, '');
        return names[key] || '';
    }

    var list = document.getElementById('tejcart-variation-attrs');
    if (!list) {
        return;
    }
    var form = list.closest('form');

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'tejcart_swatch_config';
    (form || list).appendChild(hidden);

    /**
     * Current attribute values (chip text) for a row.
     *
     * @param {HTMLElement} row
     * @return {string[]}
     */
    function rowValues(row) {
        var out = [];
        row.querySelectorAll('[data-chip-list] .tejcart-chip').forEach(function (c) {
            var v = c.getAttribute('data-chip-val');
            if (v !== null && v !== '') {
                out.push(v);
            }
        });
        return out;
    }

    /**
     * Sanitised attribute key for a row (from its name input).
     *
     * @param {HTMLElement} row
     * @return {string}
     */
    function rowKey(row) {
        var i = row.querySelector('input[name^="variation_attr_name"]');
        return sanitizeKey(i ? i.value : '');
    }

    /**
     * Create (once) and return the swatch panel for a row.
     *
     * @param {HTMLElement} row
     * @return {HTMLElement}
     */
    function ensurePanel(row) {
        var panel = row.querySelector('[data-tejcart-swatch-panel]');
        if (panel) {
            return panel;
        }

        panel = document.createElement('div');
        panel.className = 'tejcart-swatch-editor';
        panel.setAttribute('data-tejcart-swatch-panel', '');

        var head = document.createElement('div');
        head.className = 'tejcart-swatch-editor-head';

        var label = document.createElement('span');
        label.className = 'tejcart-swatch-editor-label';
        label.textContent = i18n.heading || 'Swatch style';

        var sel = document.createElement('select');
        sel.className = 'tejcart-swatch-editor-mode';
        sel.setAttribute('data-swatch-mode', '');
        [
            ['auto', i18n.auto || 'Auto'],
            ['color', i18n.color || 'Colour'],
            ['label', i18n.label || 'Buttons']
        ].forEach(function (o) {
            var op = document.createElement('option');
            op.value = o[0];
            op.textContent = o[1];
            sel.appendChild(op);
        });

        head.appendChild(label);
        head.appendChild(sel);

        var vals = document.createElement('div');
        vals.className = 'tejcart-swatch-editor-values';
        vals.setAttribute('data-swatch-values', '');

        panel.appendChild(head);
        panel.appendChild(vals);

        var chipEditor = row.querySelector('[data-tejcart-chip-editor]');
        var field = chipEditor ? chipEditor.closest('.tejcart-field') : null;
        if (field && field.parentNode) {
            field.parentNode.insertBefore(panel, field.nextSibling);
        } else {
            row.appendChild(panel);
        }

        sel.addEventListener('change', function () {
            renderValues(row);
            collect();
        });

        return panel;
    }

    /**
     * Rebuild the per-value colour pickers for a row.
     *
     * @param {HTMLElement} row
     */
    function renderValues(row) {
        var panel  = ensurePanel(row);
        var sel    = panel.querySelector('[data-swatch-mode]');
        var valsBox = panel.querySelector('[data-swatch-values]');
        var key    = rowKey(row);
        var values = rowValues(row);

        // Seed the mode select from saved meta once.
        if (!sel._init) {
            sel._init = true;
            if (saved[key] && saved[key].mode) {
                sel.value = saved[key].mode;
            }
        }

        if (!key || values.length === 0) {
            panel.style.display = 'none';
            return;
        }
        panel.style.display = '';

        var mode = sel.value || 'auto';
        if (mode === 'label') {
            valsBox.style.display = 'none';
            valsBox.innerHTML = '';
            return;
        }
        valsBox.style.display = '';

        // Preserve picks already in the DOM (e.g. across a chip change).
        var current = {};
        var dirty = {};
        valsBox.querySelectorAll('[data-swatch-color]').forEach(function (i) {
            current[i.getAttribute('data-value')] = i.value;
            if (i.getAttribute('data-dirty') === '1') {
                dirty[i.getAttribute('data-value')] = '1';
            }
        });

        valsBox.innerHTML = '';

        values.forEach(function (val) {
            var savedColor = (saved[key] && saved[key].colors && saved[key].colors[val]) || '';
            var auto = autoColor(val);
            var color = current[val] || savedColor || auto || '#cccccc';

            var item = document.createElement('label');
            item.className = 'tejcart-swatch-editor-value';

            var input = document.createElement('input');
            input.type = 'color';
            input.value = color;
            input.setAttribute('data-swatch-color', '');
            input.setAttribute('data-value', val);
            input.title = i18n.pickColor || 'Pick colour';
            if (dirty[val] || savedColor) {
                input.setAttribute('data-dirty', '1');
            }

            var text = document.createElement('span');
            text.className = 'tejcart-swatch-editor-value-text';
            text.textContent = val;

            input.addEventListener('input', function () {
                input.setAttribute('data-dirty', '1');
                collect();
            });

            item.appendChild(input);
            item.appendChild(text);
            valsBox.appendChild(item);
        });
    }

    /**
     * Serialise every row's swatch config into the hidden field.
     */
    function collect() {
        var out = {};
        list.querySelectorAll('.tejcart-variation-attr-row').forEach(function (row) {
            var key = rowKey(row);
            if (!key) {
                return;
            }
            var panel = row.querySelector('[data-tejcart-swatch-panel]');
            if (!panel) {
                return;
            }
            var sel = panel.querySelector('[data-swatch-mode]');
            var mode = sel ? sel.value : 'auto';

            if (mode === 'label') {
                out[key] = { mode: 'label', colors: {} };
                return;
            }

            var colors = {};
            panel.querySelectorAll('[data-swatch-color]').forEach(function (i) {
                var v = i.getAttribute('data-value');
                if (v === null) {
                    return;
                }
                if (mode === 'color') {
                    colors[v] = i.value;
                } else if (i.getAttribute('data-dirty') === '1') {
                    // Auto mode: persist only explicit overrides; the
                    // storefront auto-detects everything else.
                    colors[v] = i.value;
                }
            });

            if (mode === 'color' || Object.keys(colors).length) {
                out[key] = { mode: mode, colors: colors };
            }
        });
        hidden.value = JSON.stringify(out);
    }

    /**
     * Wire up a single attribute row.
     *
     * @param {HTMLElement} row
     */
    function setupRow(row) {
        if (row._swatchInit) {
            return;
        }
        row._swatchInit = true;

        ensurePanel(row);
        renderValues(row);

        var chipList = row.querySelector('[data-chip-list]');
        if (chipList) {
            new MutationObserver(function () {
                renderValues(row);
                collect();
            }).observe(chipList, { childList: true, subtree: true });
        }

        var nameInput = row.querySelector('input[name^="variation_attr_name"]');
        if (nameInput) {
            nameInput.addEventListener('input', function () {
                renderValues(row);
                collect();
            });
        }
    }

    function setupAll() {
        list.querySelectorAll('.tejcart-variation-attr-row').forEach(setupRow);
        collect();
    }

    setupAll();

    // New attribute rows are added dynamically by the core admin script.
    new MutationObserver(function () {
        setupAll();
    }).observe(list, { childList: true });

    if (form) {
        form.addEventListener('submit', collect);
    }
})();
