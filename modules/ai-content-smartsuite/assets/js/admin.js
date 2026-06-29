/**
 * TejCart AI Content SmartSuite — admin UI controller.
 *
 * Talks to the wp_ajax_tejcart_ai_content_* endpoints with the localised
 * nonce. Pure vanilla JS — no jQuery dependency.
 */
(function () {
    'use strict';

    if (typeof window.TejCartAIContent !== 'object') {
        return;
    }
    var NS = window.TejCartAIContent;

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
    var esc = function (s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };
    var format = function (tmpl, a, b) {
        return String(tmpl).replace('%1$s', a).replace('%2$s', b).replace('%s', a).replace('%s', b);
    };

    /* --------------------------- AJAX helper --------------------------- */

    function ajax(action, body) {
        var data = new URLSearchParams();
        data.append('action', 'tejcart_ai_content_' + action);
        data.append('_wpnonce', NS.nonce);
        if (body) {
            Object.keys(body).forEach(function (k) {
                var v = body[k];
                if (v == null) return;
                if (Array.isArray(v)) {
                    v.forEach(function (item) { data.append(k + '[]', item); });
                } else if (typeof v === 'object') {
                    data.append(k, JSON.stringify(v));
                } else {
                    data.append(k, v);
                }
            });
        }
        return fetch(NS.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: data.toString(),
        }).then(function (r) { return r.json().catch(function () { return { success: false, data: { message: NS.i18n.error } }; }); });
    }

    /* ---------------------------- Generator ---------------------------- */

    var state = {
        field: null,
        page: 1,
        perPage: 25,
        total: 0,
        rows: [],
        bulkPoll: null,
    };

    function bootGenerator() {
        var table = $('.tejcart-ai-table');
        if (!table) { return; }
        state.field = table.getAttribute('data-field') || 'name';
        state.perPage = parseInt($('#tejcart-ai-filter-perpage').value, 10) || 25;

        $('#tejcart-ai-filter-apply').addEventListener('click', function () { state.page = 1; loadProducts(); });
        $('#tejcart-ai-filter-reset').addEventListener('click', resetFilters);
        $('#tejcart-ai-filter-perpage').addEventListener('change', function () {
            state.perPage = parseInt(this.value, 10) || 25;
            state.page = 1; loadProducts();
        });
        $('#tejcart-ai-filter-search').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); state.page = 1; loadProducts(); }
        });
        $('#tejcart-ai-select-all').addEventListener('change', function (e) {
            $$('.tejcart-ai-row-check').forEach(function (cb) { cb.checked = e.target.checked; });
        });
        $('#tejcart-ai-bulk-generate').addEventListener('click', bulkGenerate);
        $('#tejcart-ai-bulk-apply').addEventListener('click', bulkApply);

        bindModal();
        loadProducts();
    }

    function resetFilters() {
        $('#tejcart-ai-filter-category').value = '';
        $('#tejcart-ai-filter-type').value = '';
        $('#tejcart-ai-filter-stock').value = '';
        $('#tejcart-ai-filter-search').value = '';
        $('#tejcart-ai-filter-perpage').value = '25';
        state.perPage = 25; state.page = 1;
        loadProducts();
    }

    function currentFilters() {
        return {
            field: state.field,
            category: $('#tejcart-ai-filter-category').value,
            product_type: $('#tejcart-ai-filter-type').value,
            stock_status: $('#tejcart-ai-filter-stock').value,
            search: $('#tejcart-ai-filter-search').value,
            per_page: state.perPage,
            page: state.page,
        };
    }

    function loadProducts() {
        var spinner = $('#tejcart-ai-filter-spinner');
        if (spinner) { spinner.classList.add('is-active'); }
        ajax('fetch_products', currentFilters()).then(function (resp) {
            if (spinner) { spinner.classList.remove('is-active'); }
            if (!resp || !resp.success) {
                renderEmpty((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            state.rows = resp.data.rows || [];
            state.total = resp.data.total || 0;
            renderRows();
            renderPagination();
        });
    }

    function renderEmpty(msg) {
        var tbody = $('#tejcart-ai-rows');
        tbody.innerHTML = '<tr class="tejcart-ai-empty"><td colspan="6" class="tejcart-ai-empty-cell">' + esc(msg) + '</td></tr>';
    }

    function renderRows() {
        var tbody = $('#tejcart-ai-rows');
        tbody.innerHTML = '';
        if (!state.rows.length) {
            renderEmpty(NS.i18n.noContent);
            return;
        }
        var tpl = $('#tejcart-ai-row-template');
        state.rows.forEach(function (row) {
            var node = tpl.content.firstElementChild.cloneNode(true);
            node.setAttribute('data-product-id', row.id);
            var img = $('.tejcart-ai-row-image', node);
            if (row.image) {
                img.src = row.image;
                img.alt = row.name || '';
            } else {
                img.style.visibility = 'hidden';
            }
            $('.tejcart-ai-row-title', node).textContent = row.name || ('#' + row.id);
            $('.tejcart-ai-row-id', node).textContent = '#' + row.id + ' · ' + (row.type || '');
            $('.tejcart-ai-existing', node).innerHTML = formatExisting(row.existing);
            $('.tejcart-ai-generated', node).innerHTML = formatGenerated(row.generated);
            $('.tejcart-ai-btn-generate', node).addEventListener('click', function () { onGenerate(row.id, node); });
            $('.tejcart-ai-btn-edit', node).addEventListener('click', function () { onEdit(row.id, node); });
            $('.tejcart-ai-btn-apply', node).addEventListener('click', function () { onApply(row.id, node); });
            var revertBtn = $('.tejcart-ai-btn-revert', node);
            if (revertBtn) {
                if (row.has_snapshot) { revertBtn.style.display = ''; }
                revertBtn.addEventListener('click', function () { onRevert(row.id, node); });
            }
            tbody.appendChild(node);
        });
    }

    function formatExisting(val) {
        if (val == null || val === '' || (Array.isArray(val) && val.length === 0)) {
            return '<em>—</em>';
        }
        if (state.field === 'faqs') { return renderFaqList(val); }
        return esc(val).replace(/\n/g, '<br>');
    }

    function formatGenerated(val) {
        if (val == null || val === '' || (Array.isArray(val) && val.length === 0)) {
            return '<em>' + esc(NS.i18n.noContent) + '</em>';
        }
        if (state.field === 'faqs') { return renderFaqList(val); }
        return esc(val).replace(/\n/g, '<br>');
    }

    function renderFaqList(val) {
        var items = [];
        if (Array.isArray(val)) {
            items = val;
        } else if (typeof val === 'string') {
            try { items = JSON.parse(val); } catch (e) { items = []; }
        }
        if (!Array.isArray(items) || !items.length) {
            return '<em>' + esc(NS.i18n.noContent) + '</em>';
        }
        return items.map(function (it) {
            return '<div class="tejcart-ai-faq-summary">' +
                '<div class="tejcart-ai-faq-summary__q">' + esc(it.question || '') + '</div>' +
                '<div class="tejcart-ai-faq-summary__a">' + esc(it.answer || '') + '</div>' +
                '</div>';
        }).join('');
    }

    function renderPagination() {
        var totalPages = Math.max(1, Math.ceil(state.total / state.perPage));
        var label = state.total === 1 ? NS.i18n.item : NS.i18n.items;
        var html = '<span>' + state.total + ' ' + label + '</span>' +
            '<button class="button" data-page="prev"' + (state.page <= 1 ? ' disabled' : '') + ' aria-label="Previous">‹</button>' +
            '<span class="paging-input">' + state.page + ' / ' + totalPages + '</span>' +
            '<button class="button" data-page="next"' + (state.page >= totalPages ? ' disabled' : '') + ' aria-label="Next">›</button>';
        ['#tejcart-ai-pagination-top', '#tejcart-ai-pagination-bottom'].forEach(function (sel) {
            var el = $(sel);
            if (!el) { return; }
            el.innerHTML = html;
            $$('button[data-page]', el).forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var dir = btn.getAttribute('data-page');
                    if (dir === 'prev' && state.page > 1) { state.page--; loadProducts(); }
                    if (dir === 'next' && state.page < totalPages) { state.page++; loadProducts(); }
                });
            });
        });
    }

    function rowSpin(node, on) {
        var sp = $('.tejcart-ai-row-spinner', node);
        if (!sp) { return; }
        sp.classList.toggle('is-active', !!on);
    }

    /* ----------------------- Per-row actions ----------------------- */

    function onGenerate(productId, node) {
        rowSpin(node, true);
        ajax('generate_content', { product_id: productId, field: state.field }).then(function (resp) {
            rowSpin(node, false);
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            updateRowGenerated(productId, resp.data.value);
        });
    }

    function onApply(productId, node) {
        if (!confirm(NS.i18n.confirmApply)) { return; }
        rowSpin(node, true);
        ajax('apply_content', { product_id: productId, field: state.field }).then(function (resp) {
            rowSpin(node, false);
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            loadProducts();
        });
    }

    function onRevert(productId, node) {
        if (!confirm(NS.i18n.confirmRevert || 'Revert to the previous value?')) { return; }
        rowSpin(node, true);
        ajax('revert_content', { product_id: productId, field: state.field }).then(function (resp) {
            rowSpin(node, false);
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            loadProducts();
        });
    }

    function updateRowGenerated(productId, val) {
        var node = $('tr.tejcart-ai-row[data-product-id="' + productId + '"]');
        if (!node) { return; }
        $('.tejcart-ai-generated', node).innerHTML = formatGenerated(val);
        var found = state.rows.find(function (r) { return String(r.id) === String(productId); });
        if (found) { found.generated = val; }
    }

    /* ------------------------- Edit modal ------------------------- */

    var modal = {
        productId: null,
        value: null,
    };

    function bindModal() {
        var root = $('#tejcart-ai-edit-modal');
        $$('[data-tejcart-ai-modal-close]', root).forEach(function (el) {
            el.addEventListener('click', closeModal);
        });
        $('[data-tejcart-ai-modal-action="save"]', root).addEventListener('click', saveModal);
        $('[data-tejcart-ai-modal-action="regenerate"]', root).addEventListener('click', regenerateModal);

        var faqEditor = $('[data-tejcart-ai-faq-editor]', root);
        $('[data-tejcart-ai-faq-add]', faqEditor).addEventListener('click', function () { addFaqRow({ question: '', answer: '' }); });
        bindFaqDrag(faqEditor);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !root.hidden) { closeModal(); }
        });
    }

    function onEdit(productId) {
        var row = state.rows.find(function (r) { return String(r.id) === String(productId); });
        if (!row) { return; }
        modal.productId = productId;
        modal.value = row.generated;

        var root = $('#tejcart-ai-edit-modal');
        $('#tejcart-ai-edit-modal-title').textContent = NS.fields[state.field] + ' · ' + (row.name || '#' + row.id);
        var body = $('[data-tejcart-ai-edit-body]', root);
        var faqEditor = $('[data-tejcart-ai-faq-editor]', root);
        $('[data-tejcart-ai-extra]', root).value = '';

        body.innerHTML = '';
        if (state.field === 'faqs') {
            faqEditor.hidden = false;
            body.hidden = true;
            renderFaqEditor(row.generated);
        } else {
            faqEditor.hidden = true;
            body.hidden = false;
            var ta = document.createElement('textarea');
            ta.value = typeof row.generated === 'string' ? row.generated : '';
            ta.rows = 12;
            body.appendChild(ta);
        }

        root.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        $('#tejcart-ai-edit-modal').hidden = true;
        document.body.style.overflow = '';
        modal.productId = null; modal.value = null;
    }

    function currentEditorValue() {
        var root = $('#tejcart-ai-edit-modal');
        if (state.field === 'faqs') {
            var items = [];
            $$('.tejcart-ai-faq-row', root).forEach(function (r) {
                items.push({
                    question: $('[data-tejcart-ai-faq-q]', r).value.trim(),
                    answer: $('[data-tejcart-ai-faq-a]', r).value.trim(),
                });
            });
            return items.filter(function (x) { return x.question && x.answer; });
        }
        var ta = $('[data-tejcart-ai-edit-body] textarea', root);
        return ta ? ta.value : '';
    }

    function saveModal() {
        var sp = $('[data-tejcart-ai-modal-spinner]'); if (sp) { sp.classList.add('is-active'); }
        ajax('save_content', {
            product_id: modal.productId,
            field: state.field,
            value: currentEditorValue(),
        }).then(function (resp) {
            if (sp) { sp.classList.remove('is-active'); }
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            updateRowGenerated(modal.productId, resp.data.value);
            closeModal();
        });
    }

    function regenerateModal() {
        var sp = $('[data-tejcart-ai-modal-spinner]'); if (sp) { sp.classList.add('is-active'); }
        var extra = $('[data-tejcart-ai-extra]').value;
        ajax('regenerate_content', {
            product_id: modal.productId,
            field: state.field,
            extra: extra,
        }).then(function (resp) {
            if (sp) { sp.classList.remove('is-active'); }
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            var val = resp.data.value;
            if (state.field === 'faqs') {
                renderFaqEditor(val);
            } else {
                $('[data-tejcart-ai-edit-body] textarea').value = typeof val === 'string' ? val : '';
            }
            updateRowGenerated(modal.productId, val);
        });
    }

    function renderFaqEditor(val) {
        var list = $('[data-tejcart-ai-faq-list]');
        list.innerHTML = '';
        var items = [];
        if (Array.isArray(val)) { items = val; }
        else if (typeof val === 'string') { try { items = JSON.parse(val); } catch (e) { items = []; } }
        if (!Array.isArray(items)) { items = []; }
        if (items.length === 0) { items.push({ question: '', answer: '' }); }
        items.forEach(addFaqRow);
    }

    function addFaqRow(item) {
        var tpl = $('#tejcart-ai-faq-row-template');
        var node = tpl.content.firstElementChild.cloneNode(true);
        node.setAttribute('draggable', 'true');
        $('[data-tejcart-ai-faq-q]', node).value = (item && item.question) || '';
        $('[data-tejcart-ai-faq-a]', node).value = (item && item.answer) || '';
        $('[data-tejcart-ai-faq-delete]', node).addEventListener('click', function () { node.remove(); });
        $('[data-tejcart-ai-faq-list]').appendChild(node);
    }

    function bindFaqDrag(root) {
        var list = $('[data-tejcart-ai-faq-list]', root);
        var dragging = null;
        list.addEventListener('dragstart', function (e) {
            var row = e.target.closest('.tejcart-ai-faq-row');
            if (!row) { return; }
            dragging = row;
            row.style.opacity = '0.5';
        });
        list.addEventListener('dragend', function () {
            if (dragging) { dragging.style.opacity = ''; dragging = null; }
        });
        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            var target = e.target.closest('.tejcart-ai-faq-row');
            if (!target || !dragging || target === dragging) { return; }
            var rect = target.getBoundingClientRect();
            var after = (e.clientY - rect.top) / rect.height > 0.5;
            target.parentNode.insertBefore(dragging, after ? target.nextSibling : target);
        });
    }

    /* ---------------------------- Bulk ---------------------------- */

    function selectedIds() {
        return $$('.tejcart-ai-row-check:checked').map(function (cb) {
            var row = cb.closest('tr.tejcart-ai-row');
            return row ? row.getAttribute('data-product-id') : null;
        }).filter(Boolean);
    }

    function bulkGenerate() {
        var ids = selectedIds();
        if (!ids.length) { alert(NS.i18n.noSelection); return; }
        if (!confirm(NS.i18n.confirmGen)) { return; }
        toggleBulkSpinner(true);
        ajax('bulk_generate', { product_ids: ids, field: state.field }).then(function (resp) {
            if (!resp || !resp.success) {
                toggleBulkSpinner(false);
                alert((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            pollBulk(resp.data.batch_id);
        });
    }

    function bulkApply() {
        var ids = selectedIds();
        if (!ids.length) { alert(NS.i18n.noSelection); return; }
        if (!confirm(NS.i18n.confirmApply)) { return; }
        toggleBulkSpinner(true);
        ajax('apply_selected_products', { product_ids: ids, field: state.field }).then(function (resp) {
            toggleBulkSpinner(false);
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || NS.i18n.error);
                return;
            }
            var d = resp.data || {};
            $('#tejcart-ai-bulk-status').textContent = format(NS.i18n.doneN, d.applied || 0, d.failed || 0);
            loadProducts();
        });
    }

    function pollBulk(batchId) {
        clearInterval(state.bulkPoll);
        state.bulkPoll = setInterval(function () {
            ajax('check_generation_status', { batch_id: batchId }).then(function (resp) {
                if (!resp || !resp.success) { return; }
                var p = resp.data || {};
                $('#tejcart-ai-bulk-status').textContent = format(NS.i18n.workingOn, (p.completed || 0) + (p.failed || 0), p.total || 0);
                if ((p.queued || 0) === 0) {
                    clearInterval(state.bulkPoll); state.bulkPoll = null;
                    toggleBulkSpinner(false);
                    $('#tejcart-ai-bulk-status').textContent = format(NS.i18n.doneN, p.completed || 0, p.failed || 0);
                    loadProducts();
                }
            });
        }, 2500);
    }

    function toggleBulkSpinner(on) {
        var sp = $('#tejcart-ai-bulk-spinner');
        if (sp) { sp.classList.toggle('is-active', !!on); }
    }

    /* ------------------------------ Boot ------------------------------ */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootGenerator);
    } else {
        bootGenerator();
    }
})();
