/**
 * FastMagento — paginated attribute-option manager (admin).
 *
 * Replaces Magento's native "Manage Options" / swatch grid, which loads and re-saves the ENTIRE
 * option set (unusable past a few thousand options). This loads ONE page at a time and persists
 * each add / edit / delete as a single-row AJAX call, so an attribute with 50k options is fast and
 * every write touches only the row being changed. Works for dropdown, multiselect, and visual /
 * text swatch attributes.
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
            page = 1,
            search = '',
            total = 0,
            timer = null;

        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
        function post(url, data) {
            return $.ajax({url: url, type: 'POST', dataType: 'json', data: $.extend({form_key: config.formKey}, data)});
        }

        function columns() {
            var cols = [{key: 'id', label: 'ID'}];
            if (config.isSwatch) { cols.push({key: 'swatch', label: config.swatchType === 'visual' ? 'Swatch' : 'Text'}); }
            config.stores.forEach(function (s) { cols.push({key: 'store_' + s.id, label: s.label}); });
            cols.push({key: 'sort', label: 'Sort'});
            cols.push({key: 'actions', label: ''});
            return cols;
        }

        function renderHead() {
            $head.empty();
            columns().forEach(function (c) { $head.append('<th>' + esc(c.label) + '</th>'); });
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

        function rowHtml(opt) {
            var tds = [];
            tds.push('<td class="fm-aom-id">' + (opt.option_id ? esc(opt.option_id) : '—') + '</td>');
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

        function fetch() {
            $status.text('Loading…');
            $.ajax({
                url: config.gridUrl, type: 'GET', dataType: 'json',
                data: {attribute_id: config.attributeId, page: page, page_size: config.pageSize, search: search}
            }).done(function (res) {
                if (!res || !res.success) { $status.text((res && res.message) || 'Load failed.'); return; }
                total = res.total;
                $body.empty();
                (res.options || []).forEach(function (opt) { $body.append(rowHtml(opt)); });
                renderPager();
                $status.text(total + ' option' + (total === 1 ? '' : 's'));
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

        // ── events ────────────────────────────────────────────────────────────────────────────
        $search.on('input', function () {
            search = $(this).val();
            clearTimeout(timer);
            timer = setTimeout(function () { page = 1; fetch(); }, 300);
        });
        $root.on('click', '.fm-aom-prev', function () { if (page > 1) { page--; fetch(); } });
        $root.on('click', '.fm-aom-next', function () { if (page < Math.ceil(total / config.pageSize)) { page++; fetch(); } });
        $root.on('click', '.fm-aom-add', function () {
            var $tr = rowHtml({option_id: 0, labels: [], sort_order: 0, swatch: null});
            $tr.addClass('fm-aom-row-dirty');
            $body.prepend($tr);
        });
        $root.on('click', '.fm-aom-save', function () {
            var $tr = $(this).closest('tr'), data = collectRow($tr);
            $status.text('Saving…');
            post(config.saveUrl, data).done(function (res) {
                if (res && res.success) {
                    $tr.data('optionId', res.option_id).removeClass('fm-aom-row-dirty');
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
                if (res && res.success) { $tr.remove(); total--; renderPager(); $status.text('Deleted.'); }
                else { $status.text((res && res.message) || 'Delete failed.'); }
            }).fail(function () { $status.text('Delete request failed.'); });
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
