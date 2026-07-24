/**
 * FastMagento instant search — re-renders the whole search results page (facets + product
 * grid + pagination) in place from the OpenSearch /fastmagento/search/instant endpoint as
 * the user types or toggles filters, without a full page reload (Algolia-style). Luma /
 * RequireJS / jQuery.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element),
            instantUrl = config.instantUrl,
            pageSize = config.pageSize || 12,
            $searchInput = $('#search'),
            state = {
                q: config.initialQuery || getParam('q') || '',
                page: parseInt(getParam('p'), 10) || 1,
                filters: {}
            },
            timer = null,
            xhr = null,
            // Native compare + wishlist blocks, relocated once out of the second (sidebar.additional)
            // left column into the layered-nav sidebar so the results page has ONE left rail. Held by
            // reference so they survive each $root.html() re-render (which detaches but never destroys
            // them — customer-data bindings stay intact). See relocateSidebarBlocks().
            $extraBlocks = null;

        function getParam(name) {
            var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
            return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
        }

        // Escapes for BOTH text and double/single-quoted attribute contexts (values are
        // concatenated into attributes like src="..." / value="..."), so quotes must be encoded
        // too — a plain text-node escape leaves " and ' through and allows attribute breakout.
        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function requestData() {
            var data = {q: state.q, p: state.page, page_size: pageSize, filter: {}};
            $.each(state.filters, function (code, values) {
                if (values && values.length) {
                    data.filter[code] = values;
                }
            });
            return data;
        }

        function syncUrl() {
            var params = ['q=' + encodeURIComponent(state.q)];
            if (state.page > 1) {
                params.push('p=' + state.page);
            }
            $.each(state.filters, function (code, values) {
                if (values && values.length) {
                    params.push('filter[' + code + ']=' + encodeURIComponent(values.join(',')));
                }
            });
            window.history.replaceState({}, '', window.location.pathname + '?' + params.join('&'));
        }

        function fetch() {
            if (xhr) {
                xhr.abort();
            }
            $root.addClass('fm-loading');
            xhr = $.ajax({url: instantUrl, data: requestData(), dataType: 'json', traditional: false})
                .done(function (data) {
                    render(data);
                    syncUrl();
                })
                .always(function () {
                    $root.removeClass('fm-loading');
                });
        }

        function renderFacets(facets) {
            if (!facets || !facets.length) {
                return '';
            }
            var html = '<div class="fm-facets">';
            facets.forEach(function (facet) {
                html += '<div class="fm-facet"><div class="fm-facet-title">' + escapeHtml(facet.label) + '</div><ul>';
                facet.options.forEach(function (opt) {
                    var selected = (state.filters[facet.attribute] || []).indexOf(String(opt.value)) > -1;
                    html += '<li><label class="fm-facet-opt' + (selected ? ' selected' : '') + '">' +
                        '<input type="checkbox" data-facet="' + escapeHtml(facet.attribute) + '" value="' + escapeHtml(opt.value) + '"' + (selected ? ' checked' : '') + '/> ' +
                        '<span class="fm-facet-label">' + escapeHtml(opt.label) + '</span>' +
                        '<span class="fm-facet-count">' + opt.count + '</span></label></li>';
                });
                html += '</ul></div>';
            });
            return html + '</div>';
        }

        function renderProducts(products) {
            if (!products.length) {
                return '<div class="fm-no-results">No products found for <strong>' + escapeHtml(state.q) + '</strong>.</div>';
            }
            var html = '<ol class="products list items product-items fm-grid">';
            products.forEach(function (p) {
                var price = p.regular_price_formatted
                    ? '<span class="old-price"><span class="price">' + escapeHtml(p.regular_price_formatted) + '</span></span> <span class="special-price"><span class="price">' + escapeHtml(p.price_formatted) + '</span></span>'
                    : '<span class="price">' + escapeHtml(p.price_formatted) + '</span>';
                html += '<li class="item product product-item">' +
                    '<div class="product-item-info">' +
                    '<a class="product-item-photo" href="' + escapeHtml(p.url) + '">' +
                    (p.image ? '<img class="product-image-photo" src="' + escapeHtml(p.image) + '" alt="' + escapeHtml(p.name) + '" loading="lazy"/>' : '') +
                    '</a><div class="product-item-details">' +
                    '<strong class="product-item-name"><a class="product-item-link" href="' + escapeHtml(p.url) + '">' + escapeHtml(p.name) + '</a></strong>' +
                    '<div class="price-box">' + price + '</div>' +
                    (p.in_stock ? '' : '<div class="stock unavailable">Out of stock</div>') +
                    '</div></div></li>';
            });
            return html + '</ol>';
        }

        function renderPagination(data) {
            if (data.pages <= 1) {
                return '';
            }
            var html = '<div class="fm-pagination">', i,
                start = Math.max(1, data.page - 2),
                end = Math.min(data.pages, start + 4);
            if (data.page > 1) {
                html += '<button class="fm-page" data-page="' + (data.page - 1) + '">‹ Prev</button>';
            }
            for (i = start; i <= end; i++) {
                html += '<button class="fm-page' + (i === data.page ? ' current' : '') + '" data-page="' + i + '">' + i + '</button>';
            }
            if (data.page < data.pages) {
                html += '<button class="fm-page" data-page="' + (data.page + 1) + '">Next ›</button>';
            }
            return html + '</div>';
        }

        // Pull the native compare + wishlist blocks out of sidebar.additional (the second left
        // column) exactly once, keeping references. If those are the only blocks there, hide the now
        // empty native column so the page collapses to a single left rail.
        function grabExtraBlocks() {
            if ($extraBlocks !== null) {
                return;
            }
            var $additional = $('.sidebar-additional'),
                $blocks = $additional.find('.block-compare, .block-wishlist');
            $extraBlocks = $blocks.length ? $blocks : $();
            if (
                $additional.length &&
                $additional.find('.block').length &&
                $additional.find('.block').not('.block-compare').not('.block-wishlist').length === 0
            ) {
                $additional.addClass('fm-relocated');
            }
        }

        // Append the relocated compare/wishlist blocks below the layered-nav facets. Called after
        // every render because $root.html() rebuilds .fm-sidebar (and detaches the blocks); the JS
        // reference keeps them alive so we just move the same nodes back in — no clone, no re-fetch.
        function relocateSidebarBlocks() {
            if ($extraBlocks && $extraBlocks.length) {
                $root.find('.fm-sidebar').append($extraBlocks);
            }
        }

        function render(data) {
            grabExtraBlocks();
            var html = '<div class="fm-results-header"><span class="fm-count">' + data.total +
                ' result' + (data.total === 1 ? '' : 's') + (data.query ? ' for &ldquo;' + escapeHtml(data.query) + '&rdquo;' : '') + '</span></div>' +
                '<div class="fm-results-body">' +
                '<aside class="fm-sidebar">' + renderFacets(data.facets) + '</aside>' +
                '<div class="fm-results-main">' + renderProducts(data.products) + renderPagination(data) + '</div>' +
                '</div>';
            $root.html(html);
            relocateSidebarBlocks();
        }

        // Facet toggle
        $root.on('change', 'input[type="checkbox"][data-facet]', function () {
            var code = $(this).data('facet'),
                value = String($(this).val()),
                list = state.filters[code] || [],
                idx = list.indexOf(value);
            if (idx > -1) {
                list.splice(idx, 1);
            } else {
                list.push(value);
            }
            state.filters[code] = list;
            state.page = 1;
            fetch();
        });

        // Pagination
        $root.on('click', '.fm-page', function () {
            state.page = parseInt($(this).data('page'), 10);
            fetch();
            $('html, body').animate({scrollTop: $root.offset().top - 80}, 200);
        });

        // As-you-type from the header search box, on the results page
        if ($searchInput.length) {
            $searchInput.on('input', function () {
                var q = $searchInput.val().trim();
                clearTimeout(timer);
                timer = setTimeout(function () {
                    if (q !== state.q) {
                        state.q = q;
                        state.page = 1;
                        state.filters = {};
                        fetch();
                    }
                }, 300);
            });
            // Prevent the native form submit reloading the page while instant search is active
            $searchInput.closest('form').on('submit', function (e) {
                e.preventDefault();
                state.q = $searchInput.val().trim();
                state.page = 1;
                fetch();
            });
        }

        fetch();
    };
});
