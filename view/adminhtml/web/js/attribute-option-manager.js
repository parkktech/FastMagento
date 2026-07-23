/**
 * FastMagento — paginated attribute-option manager (admin).
 *
 * Replaces Magento's native "Manage Options" / swatch grid, which loads and re-saves the ENTIRE
 * option set (unusable past a few thousand options). This loads ONE page at a time and persists
 * each add / edit / delete as a single-row AJAX call, so an attribute with 50k options is fast and
 * every write touches only the row being changed. Works for dropdown, multiselect, and visual /
 * text swatch attributes.
 *
 * Also surfaces, per option, whether it is assigned to any product — with a filter (Any / Yes / No)
 * and bulk delete (checked rows, or EVERY option matching the current filter) so a catalog littered
 * with thousands of unused options can be cleaned out in one pass.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, root) {
        var $root = $(root),
            $head = $root.find('.fm-aom-head'),
            $body = $root.find('.fm-aom-body'),
            $pager = $root.find('.fm-aom-pager'),
            $status = $root.find('.fm-aom-status'),
            $search = $root.find('.fm-aom-search'),
            $assigned = $root.find('.fm-aom-assigned'),
            $delSel = $root.find('.fm-aom-delsel'),
            $delAll = $root.find('.fm-aom-delall'),
            page = 1,
            search = '',
            assigned = '',
            total = 0,
            timer = null;

        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
        function plural(n) { return n === 1 ? '' : 's'; }
        function post(url, data) {
            return $.ajax({url: url, type: 'POST', dataType: 'json', data: $.extend({form_key: config.formKey}, data)});
        }

        function columns() {
            var cols = [{key: 'check', type: 'check'}, {key: 'id', label: 'ID'}, {key: 'assigned', label: 'Assigned'}];
            if (config.isSwatch) { cols.push({key: 'swatch', label: config.swatchType === 'visual' ? 'Swatch' : 'Text'}); }
            config.stores.forEach(function (s) { cols.push({key: 'store_' + s.id, label: s.label}); });
            cols.push({key: 'sort', label: 'Sort'});
            cols.push({key: 'actions', label: ''});
            return cols;
        }

        function renderHead() {
            $head.empty();
            columns().forEach(function (c) {
                if (c.type === 'check') {
                    $head.append('<th class="fm-aom-check-col"><input type="checkbox" class="fm-aom-all" title="Select page"/></th>');
                } else {
                    $head.append('<th>' + esc(c.label) + '</th>');
                }
            });
        }

        function swatchCell(opt) {
            if (config.swatchType === 'visual') {
                var v = (opt.swatch && opt.swatch.value) || '';
                var style = v && v.charAt(0) === '#' ? 'background:' + esc(v) : (v ? 'background-image:url(' + esc(v) + ');background-size:cover' : '');
                return '<span class="fm-aom-swatch" data-swatch style="' + style + '"></span>' +
                    '<input type="text" class="fm-aom-swatch-input admin__control-text" value="' + esc(v) + '" placeholder="#RRGGBB" style="width:90px;margin-left:6px;"/>';
            }
            var tv = (opt.swatch && opt.swatch.value) || '';
            return '<input type="text" class="fm-aom-swatch-input admin__control-text" value="' + esc(tv) + '" placeholder="text swatch" style="width:120px;"/>';
        }

        function assignedCell(opt) {
            if (!opt.option_id) { return '<td class="fm-aom-assigned-cell">—</td>'; }
            return opt.assigned
                ? '<td class="fm-aom-assigned-cell fm-aom-badge-yes">Yes</td>'
                : '<td class="fm-aom-assigned-cell fm-aom-badge-no">No</td>';
        }

        function rowHtml(opt) {
            var tds = [];
            tds.push('<td class="fm-aom-check-col"><input type="checkbox" class="fm-aom-check"' + (opt.option_id ? '' : ' disabled') + '/></td>');
            tds.push('<td class="fm-aom-id">' + (opt.option_id ? esc(opt.option_id) : '—') + '</td>');
            tds.push(assignedCell(opt));
            if (config.isSwatch) { tds.push('<td>' + swatchCell(opt) + '</td>'); }
            config.stores.forEach(function (s, i) {
                var val = opt.labels && opt.labels[i] != null ? opt.labels[i] : '';
                tds.push('<td><input type="text" class="fm-aom-label admin__control-text" data-store="' + s.id + '" value="' + esc(val) + '" style="width:100%"/></td>');
            });
            tds.push('<td><input type="number" class="fm-aom-sort admin__control-text" value="' + (opt.sort_order || 0) + '" style="width:64px"/></td>');
            tds.push('<td style="white-space:nowrap">' +
                '<button type="button" class="fm-aom-save action-secondary" title="Save">✔</button> ' +
                '<button type="button" class="fm-aom-del action-secondary" title="Delete">✕</button></td>');
            return $('<tr class="fm-aom-row">' + tds.join('') + '</tr>').data('optionId', opt.option_id || 0);
        }

        function fetch(after) {
            $status.text('Loading…');
            $.ajax({
                url: config.gridUrl, type: 'GET', dataType: 'json',
                data: {attribute_id: config.attributeId, page: page, page_size: config.pageSize, search: search, assigned: assigned}
            }).done(function (res) {
                if (!res || !res.success) { $status.text((res && res.message) || 'Load failed.'); return; }
                total = res.total;
                $body.empty();
                (res.options || []).forEach(function (opt) { $body.append(rowHtml(opt)); });
                renderPager();
                $root.find('.fm-aom-all').prop('checked', false);
                updateSelection();
                $status.text(total + ' option' + plural(total));
                if (typeof after === 'function') { after(); }
            }).fail(function () { $status.text('Request failed.'); });
        }

        function renderPager() {
            var pages = Math.max(1, Math.ceil(total / config.pageSize));
            $pager.empty();
            $pager.append('<button type="button" class="fm-aom-prev action-secondary"' + (page <= 1 ? ' disabled' : '') + '>‹ Prev</button>');
            $pager.append('<span> Page ' + page + ' / ' + pages + ' </span>');
            $pager.append('<button type="button" class="fm-aom-next action-secondary"' + (page >= pages ? ' disabled' : '') + '>Next ›</button>');
        }

        function collectRow($tr) {
            var labels = {};
            $tr.find('.fm-aom-label').each(function () { labels[$(this).data('store')] = $(this).val(); });
            return {
                attribute_id: config.attributeId,
                option_id: $tr.data('optionId') || 0,
                labels: labels,
                sort_order: $tr.find('.fm-aom-sort').val() || 0,
                swatch_value: config.isSwatch ? ($tr.find('.fm-aom-swatch-input').val() || '') : null
            };
        }

        function checkedIds() {
            var ids = [];
            $body.find('.fm-aom-check:checked').each(function () {
                var id = $(this).closest('tr').data('optionId') || 0;
                if (id) { ids.push(id); }
            });
            return ids;
        }

        function updateSelection() {
            var n = checkedIds().length;
            $delSel.prop('disabled', n === 0).text(n > 0 ? ('Delete Selected (' + n + ')') : 'Delete Selected');
        }

        // ── events ────────────────────────────────────────────────────────────────────────────
        $search.on('input', function () {
            search = $(this).val();
            clearTimeout(timer);
            timer = setTimeout(function () { page = 1; fetch(); }, 300);
        });
        $assigned.on('change', function () { assigned = $(this).val(); page = 1; fetch(); });
        $root.on('click', '.fm-aom-prev', function () { if (page > 1) { page--; fetch(); } });
        $root.on('click', '.fm-aom-next', function () { if (page < Math.ceil(total / config.pageSize)) { page++; fetch(); } });
        $root.on('click', '.fm-aom-add', function () {
            var $tr = rowHtml({option_id: 0, labels: [], sort_order: 0, swatch: null, assigned: false});
            $tr.addClass('fm-aom-row-dirty');
            $body.prepend($tr);
        });
        $root.on('click', '.fm-aom-save', function () {
            var $tr = $(this).closest('tr'), data = collectRow($tr);
            $status.text('Saving…');
            post(config.saveUrl, data).done(function (res) {
                if (res && res.success) {
                    $tr.data('optionId', res.option_id).removeClass('fm-aom-row-dirty');
                    $tr.find('.fm-aom-check').prop('disabled', false);
                    $status.text('Saved option #' + res.option_id);
                } else { $status.text((res && res.message) || 'Save failed.'); }
            }).fail(function () { $status.text('Save request failed.'); });
        });
        $root.on('click', '.fm-aom-del', function () {
            var $tr = $(this).closest('tr'), id = $tr.data('optionId') || 0;
            if (!id) { $tr.remove(); return; }
            if (!window.confirm('Delete this option? Products using it will lose the value.')) { return; }
            $status.text('Deleting…');
            post(config.deleteUrl, {attribute_id: config.attributeId, option_id: id}).done(function (res) {
                if (res && res.success) { $tr.remove(); total--; renderPager(); updateSelection(); $status.text('Deleted.'); }
                else { $status.text((res && res.message) || 'Delete failed.'); }
            }).fail(function () { $status.text('Delete request failed.'); });
        });

        // selection
        $root.on('change', '.fm-aom-all', function () {
            var on = $(this).prop('checked');
            $body.find('.fm-aom-check:not(:disabled)').prop('checked', on).each(function () {
                $(this).closest('tr').toggleClass('fm-aom-checked', on);
            });
            updateSelection();
        });
        $root.on('change', '.fm-aom-check', function () {
            $(this).closest('tr').toggleClass('fm-aom-checked', $(this).prop('checked'));
            updateSelection();
        });

        // bulk delete — checked rows on this page
        $root.on('click', '.fm-aom-delsel', function () {
            if ($(this).prop('disabled')) { return; }
            var ids = checkedIds();
            if (!ids.length) { return; }
            if (!window.confirm('Delete ' + ids.length + ' selected option' + plural(ids.length) + '? Products using them will lose the value.')) { return; }
            $status.text('Deleting ' + ids.length + '…');
            post(config.massDeleteUrl, {attribute_id: config.attributeId, mode: 'selected', option_ids: ids}).done(function (res) {
                if (res && res.success) {
                    page = 1;
                    fetch(function () { $status.text('Deleted ' + res.deleted + ' option' + plural(res.deleted) + '.'); });
                } else { $status.text((res && res.message) || 'Bulk delete failed.'); }
            }).fail(function () { $status.text('Bulk delete request failed.'); });
        });

        // bulk delete — EVERY option matching the current filter (all pages)
        $root.on('click', '.fm-aom-delall', function () {
            $status.text('Counting…');
            $.ajax({
                url: config.gridUrl, type: 'GET', dataType: 'json',
                data: {attribute_id: config.attributeId, page: 1, page_size: 1, search: search, assigned: assigned, counts: 1}
            }).done(function (res) {
                if (!res || !res.success) { $status.text((res && res.message) || 'Count failed.'); return; }
                var n = res.total, inUse = res.assigned_in_match || 0;
                if (!n) { $status.text('Nothing matches the current filter.'); return; }
                var scope = (search === '' && assigned === '') ? 'ALL ' : '';
                var msg = 'Delete ' + scope + n + ' option' + plural(n) + ' matching the current filter?\nThis cannot be undone.';
                if (inUse > 0) {
                    msg += '\n\n⚠ ' + inUse + ' of these ' + (inUse === 1 ? 'is' : 'are') +
                        ' still assigned to products — those products will lose the value.';
                }
                if (!window.confirm(msg)) { return; }
                $status.text('Deleting ' + n + '… this may take a moment.');
                post(config.massDeleteUrl, {attribute_id: config.attributeId, mode: 'all', search: search, assigned: assigned}).done(function (r) {
                    if (r && r.success) {
                        page = 1;
                        fetch(function () { $status.text('Deleted ' + r.deleted + ' option' + plural(r.deleted) + '.'); });
                    } else { $status.text((r && r.message) || 'Bulk delete failed.'); }
                }).fail(function () { $status.text('Bulk delete request failed.'); });
            }).fail(function () { $status.text('Count request failed.'); });
        });

        // live swatch colour preview
        $root.on('input', '.fm-aom-swatch-input', function () {
            var v = $(this).val();
            $(this).closest('td').find('[data-swatch]').attr('style', v && v.charAt(0) === '#' ? 'background:' + v : '');
        });

        renderHead();
        fetch();
    };
});
