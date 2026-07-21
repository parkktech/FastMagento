/**
 * FastMagento autocomplete — an as-you-type search dropdown backed by the OpenSearch
 * /fastmagento/search/suggest endpoint. Renders product cards (image, name, price) and
 * matching category suggestions with keyboard navigation, in the Luma / RequireJS idiom.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $input = $(element),
            minChars = config.minChars || 2,
            delay = config.delay || 200,
            suggestUrl = config.suggestUrl,
            resultsUrl = config.resultsUrl,
            timer = null,
            xhr = null,
            $panel = $('<div class="fm-autocomplete" role="listbox" aria-label="Search suggestions"></div>').hide(),
            activeIndex = -1;

        $input.attr('autocomplete', 'off').closest('.control, .block-content, .minisearch').first().css('position', 'relative').append($panel);
        if (!$panel.parent().length) {
            $input.parent().css('position', 'relative').append($panel);
        }

        function escapeHtml(str) {
            return $('<div>').text(str == null ? '' : str).html();
        }

        function hide() {
            $panel.hide().empty();
            activeIndex = -1;
        }

        function render(data) {
            var products = data.products || [],
                categories = data.categories || [],
                html = '';

            if (!products.length && !categories.length) {
                $panel.html('<div class="fm-ac-empty">No results for <strong>' + escapeHtml(data.query) + '</strong></div>').show();
                return;
            }

            if (categories.length) {
                html += '<div class="fm-ac-section"><div class="fm-ac-heading">Categories</div>';
                categories.forEach(function (c) {
                    html += '<a class="fm-ac-cat" role="option" href="' + escapeHtml(c.url) + '">' +
                        '<span class="fm-ac-cat-name">' + escapeHtml(c.name) + '</span>' +
                        '<span class="fm-ac-cat-count">' + c.count + '</span></a>';
                });
                html += '</div>';
            }

            if (products.length) {
                html += '<div class="fm-ac-section"><div class="fm-ac-heading">Products</div>';
                products.forEach(function (p) {
                    var price = p.regular_price_formatted
                        ? '<span class="fm-ac-price-old">' + escapeHtml(p.regular_price_formatted) + '</span> <span class="fm-ac-price fm-ac-price-special">' + escapeHtml(p.price_formatted) + '</span>'
                        : '<span class="fm-ac-price">' + escapeHtml(p.price_formatted) + '</span>';
                    html += '<a class="fm-ac-product" role="option" href="' + escapeHtml(p.url) + '">' +
                        '<span class="fm-ac-thumb">' + (p.image ? '<img src="' + escapeHtml(p.image) + '" alt="' + escapeHtml(p.name) + '" loading="lazy"/>' : '') + '</span>' +
                        '<span class="fm-ac-info"><span class="fm-ac-name">' + escapeHtml(p.name) + '</span>' + price +
                        (p.in_stock ? '' : '<span class="fm-ac-oos">Out of stock</span>') + '</span></a>';
                });
                html += '</div>';
            }

            if (data.total > products.length) {
                html += '<a class="fm-ac-all" href="' + escapeHtml(resultsUrl) + '?q=' + encodeURIComponent(data.query) + '">' +
                    'View all ' + data.total + ' results</a>';
            }

            $panel.html(html).show();
            activeIndex = -1;
        }

        function fetch(q) {
            if (xhr) {
                xhr.abort();
            }
            xhr = $.ajax({url: suggestUrl, data: {q: q}, dataType: 'json'})
                .done(function (data) {
                    if ($input.val().trim() === q) {
                        render(data);
                    }
                });
        }

        function onInput() {
            var q = $input.val().trim();
            clearTimeout(timer);
            if (q.length < minChars) {
                hide();
                return;
            }
            timer = setTimeout(function () {
                fetch(q);
            }, delay);
        }

        function items() {
            return $panel.find('[role="option"]');
        }

        function move(dir) {
            var $items = items();
            if (!$items.length) {
                return;
            }
            activeIndex = (activeIndex + dir + $items.length) % $items.length;
            $items.removeClass('fm-ac-active').eq(activeIndex).addClass('fm-ac-active');
        }

        $input.on('input', onInput);
        $input.on('keydown', function (e) {
            if (!$panel.is(':visible')) {
                return;
            }
            if (e.keyCode === 40) {            // down
                e.preventDefault();
                move(1);
            } else if (e.keyCode === 38) {     // up
                e.preventDefault();
                move(-1);
            } else if (e.keyCode === 13) {     // enter on an active item
                if (activeIndex > -1) {
                    e.preventDefault();
                    window.location.href = items().eq(activeIndex).attr('href');
                }
            } else if (e.keyCode === 27) {     // escape
                hide();
            }
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest($panel).length && e.target !== $input[0]) {
                hide();
            }
        });
        $input.on('focus', function () {
            if ($input.val().trim().length >= minChars && $panel.children().length) {
                $panel.show();
            }
        });
    };
});
