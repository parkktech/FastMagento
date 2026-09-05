/**
 * FastMagento storefront — autocomplete + instant search.
 *
 * THEME-AGNOSTIC BY DESIGN. This file has NO dependencies: no jQuery, no RequireJS, no Alpine,
 * no Knockout. It is a plain <script defer> that boots itself off JSON config embedded in the
 * page, so the identical build runs on:
 *
 *   - default Magento (Luma / Blank)  — RequireJS is present but unused
 *   - Swissup Breeze                  — its jQuery shim is present but unused
 *   - Hyvä                            — which ships NEITHER jQuery NOR RequireJS
 *
 * It previously bootstrapped through `<script type="text/x-magento-init">`, which only executes
 * if Magento's RequireJS `mage/apply/main` is on the page. On Hyvä that tag is inert, so the
 * markup rendered and nothing ever read it — autocomplete never appeared and the search results
 * grid stayed empty. Self-bootstrapping removes the dependency instead of adding a second
 * theme-specific implementation to keep in sync.
 */
(function () {
    'use strict';

    /* ── tiny DOM/util helpers (replacing the jQuery this used to need) ──────────────────── */

    function readConfig(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent || el.innerText || '{}');
        } catch (e) {
            return null;
        }
    }

    var decoder = null;

    /**
     * Catalogue data legitimately contains HTML entities — Magento's own sample data ships
     * "Lumaflex&trade;". Escaping that directly renders the literal text "&trade;", so decode
     * once first. Safe: decoding happens in a detached textarea (no parsing of tags, no script
     * execution), and the result is escaped immediately afterwards, so real markup in the data
     * still ends up inert.
     */
    function decodeEntities(str) {
        if (str.indexOf('&') === -1) {
            return str;
        }
        if (!decoder) {
            decoder = document.createElement('textarea');
        }
        decoder.innerHTML = str;
        return decoder.value;
    }

    function escapeHtml(str) {
        // Escapes for BOTH text and quoted-attribute contexts — values below are concatenated
        // into href="..."/src="..." so quotes must be encoded too.
        return decodeEntities(String(str === null || str === undefined ? '' : str))
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function param(name) {
        var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    /**
     * Applied filters carried by the page URL, in the shape this script itself writes
     * (`filter[color]=58,59`, see syncUrl/filterHref) and the one the instant endpoint reads
     * (`filter[color][]=58`). Without this a reload, a shared link, or a middle-clicked option
     * silently dropped every filter while the URL still claimed them.
     */
    function filtersFromLocation() {
        var filters = {};
        (window.location.search.replace(/^\?/, '').split('&')).forEach(function (pair) {
            var eq = pair.indexOf('='),
                key,
                value,
                match;
            try {
                key = decodeURIComponent((eq > -1 ? pair.slice(0, eq) : pair).replace(/\+/g, ' '));
                value = eq > -1 ? decodeURIComponent(pair.slice(eq + 1).replace(/\+/g, ' ')) : '';
            } catch (err) {
                // A malformed escape in someone's URL must not stop the results page from booting.
                return;
            }
            match = key.match(/^filter\[([^\]]+)\](\[\])?$/);
            if (!match) {
                return;
            }
            filters[match[1]] = (filters[match[1]] || []).concat(
                (match[2] ? [value] : value.split(',')).filter(Boolean)
            );
        });
        return filters;
    }

    function buildQuery(obj, prefix) {
        var parts = [];
        Object.keys(obj).forEach(function (key) {
            var value = obj[key],
                k = prefix ? prefix + '[' + key + ']' : key;
            if (value === null || value === undefined) {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(function (v) {
                    parts.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(v));
                });
            } else if (typeof value === 'object') {
                var nested = buildQuery(value, k);
                if (nested) {
                    parts.push(nested);
                }
            } else {
                parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(value));
            }
        });
        return parts.join('&');
    }

    /**
     * Locate the site search input across themes. Luma, Breeze and Hyvä all use id="search",
     * but Hyvä renders a second input inside its slide-out search panel and custom themes rename
     * things, so fall back to the form field name the Magento search route actually reads.
     */
    function findSearchInputs(selector) {
        var found = [];
        (selector ? [selector] : []).concat([
            '#search',
            'input[name="q"][type="search"]',
            'input[name="q"]'
        ]).forEach(function (sel) {
            Array.prototype.forEach.call(document.querySelectorAll(sel), function (el) {
                if (el.tagName === 'INPUT' && found.indexOf(el) === -1) {
                    found.push(el);
                }
            });
        });
        return found;
    }

    /* ── autocomplete ───────────────────────────────────────────────────────────────────── */

    function initAutocomplete(config, input) {
        var minChars = config.minChars || 2,
            delay = config.delay || 200,
            timer = null,
            controller = null,
            activeIndex = -1,
            panel = document.createElement('div');

        panel.className = 'fm-autocomplete';
        panel.setAttribute('role', 'listbox');
        panel.setAttribute('aria-label', 'Search suggestions');
        panel.hidden = true;

        input.setAttribute('autocomplete', 'off');

        // Anchor the panel to the closest positioned-able wrapper. Themes differ wildly here, so
        // walk a short list and fall back to the input's own parent.
        var host = input.closest('.control, .block-content, .minisearch, .field.search, form') || input.parentNode;
        if (getComputedStyle(host).position === 'static') {
            host.style.position = 'relative';
        }
        host.appendChild(panel);

        function hide() {
            panel.hidden = true;
            panel.innerHTML = '';
            activeIndex = -1;
        }

        function show() {
            panel.hidden = false;
        }

        function options() {
            return panel.querySelectorAll('[role="option"]');
        }

        function render(data) {
            var products = data.products || [],
                categories = data.categories || [],
                html = '';

            if (!products.length && !categories.length) {
                panel.innerHTML = '<div class="fm-ac-empty">No results for <strong>'
                    + escapeHtml(data.query) + '</strong></div>';
                show();
                return;
            }

            if (categories.length) {
                html += '<div class="fm-ac-section"><div class="fm-ac-heading">Categories</div>';
                categories.forEach(function (c) {
                    html += '<a class="fm-ac-cat" role="option" href="' + escapeHtml(c.url) + '">'
                        + '<span class="fm-ac-cat-name">' + escapeHtml(c.name) + '</span>'
                        + '<span class="fm-ac-cat-count">' + escapeHtml(c.count) + '</span></a>';
                });
                html += '</div>';
            }

            if (products.length) {
                html += '<div class="fm-ac-section"><div class="fm-ac-heading">Products</div>';
                products.forEach(function (p) {
                    var price = p.regular_price_formatted
                        ? '<span class="fm-ac-price-old">' + escapeHtml(p.regular_price_formatted)
                            + '</span> <span class="fm-ac-price fm-ac-price-special">'
                            + escapeHtml(p.price_formatted) + '</span>'
                        : '<span class="fm-ac-price">' + escapeHtml(p.price_formatted) + '</span>';
                    html += '<a class="fm-ac-product" role="option" href="' + escapeHtml(p.url) + '">'
                        + '<span class="fm-ac-thumb">'
                        + (p.image ? '<img src="' + escapeHtml(p.image) + '" alt="' + escapeHtml(p.name)
                            + '" loading="lazy"/>' : '')
                        + '</span><span class="fm-ac-info"><span class="fm-ac-name">'
                        + escapeHtml(p.name) + '</span>' + price
                        + (p.in_stock ? '' : '<span class="fm-ac-oos">Out of stock</span>')
                        + '</span></a>';
                });
                html += '</div>';
            }

            if (data.total > products.length) {
                html += '<a class="fm-ac-all" href="' + escapeHtml(config.resultsUrl) + '?q='
                    + encodeURIComponent(data.query) + '">View all ' + escapeHtml(data.total)
                    + ' results</a>';
            }

            panel.innerHTML = html;
            show();
            activeIndex = -1;
        }

        function fetchSuggestions(q) {
            if (controller) {
                controller.abort();
            }
            controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var url = config.suggestUrl + (config.suggestUrl.indexOf('?') > -1 ? '&' : '?')
                + 'q=' + encodeURIComponent(q);

            fetch(url, {
                signal: controller ? controller.signal : undefined,
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    // Ignore a response that lost the race with newer typing.
                    if (data && input.value.trim() === q) {
                        render(data);
                    }
                })
                .catch(function () { /* aborted or offline — leave the panel as-is */ });
        }

        function move(dir) {
            var items = options();
            if (!items.length) {
                return;
            }
            if (activeIndex > -1 && items[activeIndex]) {
                items[activeIndex].classList.remove('fm-ac-active');
            }
            activeIndex = (activeIndex + dir + items.length) % items.length;
            items[activeIndex].classList.add('fm-ac-active');
            items[activeIndex].scrollIntoView({block: 'nearest'});
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < minChars) {
                hide();
                return;
            }
            timer = setTimeout(function () { fetchSuggestions(q); }, delay);
        });

        input.addEventListener('keydown', function (e) {
            if (panel.hidden) {
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                move(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                move(-1);
            } else if (e.key === 'Enter') {
                var items = options();
                if (activeIndex > -1 && items[activeIndex]) {
                    e.preventDefault();
                    window.location.href = items[activeIndex].getAttribute('href');
                }
            } else if (e.key === 'Escape') {
                hide();
            }
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= minChars && panel.children.length) {
                show();
            }
        });

        document.addEventListener('click', function (e) {
            if (e.target !== input && !panel.contains(e.target)) {
                hide();
            }
        });
    }

    /* ── instant search results page ────────────────────────────────────────────────────── */

    function initInstantSearch(config, root) {
        var pageSize = config.pageSize || 12,
            controller = null,
            timer = null,
            facetHost = null,
            facetProtos = null,
            facetOpen = {},
            // The active-filter card starts expanded: it only exists once something is applied,
            // and a shopper who just picked an option should see what they picked. After that it
            // is theirs — collapse it and it stays collapsed through every later update.
            stateOpen = true,
            state = {
                q: config.initialQuery || param('q') || '',
                page: parseInt(param('p'), 10) || 1,
                filters: filtersFromLocation()
            };

        function requestUrl() {
            var data = {q: state.q, p: state.page, page_size: pageSize, filter: {}};
            Object.keys(state.filters).forEach(function (code) {
                if (state.filters[code] && state.filters[code].length) {
                    data.filter[code] = state.filters[code];
                }
            });
            return config.instantUrl + (config.instantUrl.indexOf('?') > -1 ? '&' : '?') + buildQuery(data);
        }

        function syncUrl() {
            var params = ['q=' + encodeURIComponent(state.q)];
            if (state.page > 1) {
                params.push('p=' + state.page);
            }
            Object.keys(state.filters).forEach(function (code) {
                if (state.filters[code] && state.filters[code].length) {
                    params.push('filter[' + code + ']=' + encodeURIComponent(state.filters[code].join(',')));
                }
            });
            window.history.replaceState({}, '', window.location.pathname + '?' + params.join('&'));
        }

        function renderFacets(facets) {
            if (!facets || !facets.length) {
                return '';
            }
            var html = '<div class="fm-facets">';
            facets.forEach(function (facet) {
                html += '<div class="fm-facet"><div class="fm-facet-title">'
                    + escapeHtml(facet.label) + '</div><ul>';
                (facet.options || []).forEach(function (opt) {
                    var selected = (state.filters[facet.attribute] || []).indexOf(String(opt.value)) > -1;
                    html += '<li><label class="fm-facet-opt' + (selected ? ' selected' : '') + '">'
                        + '<input type="checkbox" data-facet="' + escapeHtml(facet.attribute)
                        + '" value="' + escapeHtml(opt.value) + '"' + (selected ? ' checked' : '') + '/> '
                        + '<span class="fm-facet-label">' + escapeHtml(opt.label) + '</span>'
                        + '<span class="fm-facet-count">' + escapeHtml(opt.count) + '</span></label></li>';
                });
                html += '</ul></div>';
            });
            return html + '</div>';
        }

        function renderProducts(products) {
            if (!products.length) {
                return '<div class="fm-no-results">No products found for <strong>'
                    + escapeHtml(state.q) + '</strong>.</div>';
            }
            var html = '<ol class="products list items product-items fm-grid">';
            products.forEach(function (p) {
                var price = p.regular_price_formatted
                    ? '<span class="old-price"><span class="price">'
                        + escapeHtml(p.regular_price_formatted) + '</span></span> '
                        + '<span class="special-price"><span class="price">'
                        + escapeHtml(p.price_formatted) + '</span></span>'
                    : '<span class="price">' + escapeHtml(p.price_formatted) + '</span>';
                html += '<li class="item product product-item"><div class="product-item-info">'
                    + '<a class="product-item-photo" href="' + escapeHtml(p.url) + '">'
                    + (p.image ? '<img class="product-image-photo" src="' + escapeHtml(p.image)
                        + '" alt="' + escapeHtml(p.name) + '" loading="lazy"/>' : '')
                    + '</a><div class="product-item-details">'
                    + '<strong class="product-item-name"><a class="product-item-link" href="'
                    + escapeHtml(p.url) + '">' + escapeHtml(p.name) + '</a></strong>'
                    + '<div class="price-box">' + price + '</div>'
                    + (p.in_stock ? '' : '<div class="stock unavailable">Out of stock</div>')
                    + '</div></div></li>';
            });
            return html + '</ol>';
        }

        function renderPagination(data) {
            if (data.pages <= 1) {
                return '';
            }
            var html = '<div class="fm-pagination">',
                start = Math.max(1, data.page - 2),
                end = Math.min(data.pages, start + 4),
                i;
            if (data.page > 1) {
                html += '<button type="button" class="fm-page" data-page="' + (data.page - 1) + '">‹ Prev</button>';
            }
            for (i = start; i <= end; i++) {
                html += '<button type="button" class="fm-page' + (i === data.page ? ' current' : '')
                    + '" data-page="' + i + '">' + i + '</button>';
            }
            if (data.page < data.pages) {
                html += '<button type="button" class="fm-page" data-page="' + (data.page + 1) + '">Next ›</button>';
            }
            return html + '</div>';
        }

        /*
           FACETS IN THE THEME'S OWN LAYERED NAVIGATION
           --------------------------------------------
           The takeover leaves the native layered-nav block in place purely for its MARKUP: the
           theme renders its wrapper, heading, collapsible groups and mobile toggle exactly as it
           does on a category page. We then clone one of those groups as a prototype and refill
           the list from the OpenSearch aggregation, which is richer than what native layered nav
           puts on a search page.

           Nothing here hardcodes a class name from any theme, so a Hyva / Luma / Breeze storefront
           each gets its own styling with no stylesheet of ours involved. Clicks are intercepted
           rather than followed, so filtering stays client-side and as-you-type search, pagination
           and the FastMagento facet settings all keep working. The href is still a real URL, so a
           middle-click or a crawler gets a working link.
        */

        // First element matching any of these selectors, tried IN ORDER. Not one comma-separated
        // querySelector: that returns the first match in DOCUMENT order regardless of which
        // selector hit, which picked an unrelated `.block-content` in the page header.
        function firstOf(selectors) {
            for (var i = 0; i < selectors.length; i++) {
                var el = document.querySelector(selectors[i]);
                if (el) {
                    return el;
                }
            }
            return null;
        }

        // The container the theme drops its filter groups into. Resolved from a rendered group,
        // so it is the right element whatever the theme calls it; then the layered-nav content
        // element by id/class; then the sidebar itself. Null on a 1-column layout or a theme with
        // none of these, and render() falls back to the self-contained rail.
        function resolveFacetHost() {
            var group = document.querySelector('.filter-option, .filter-options-item'),
                host = (group && group.parentElement)
                    || firstOf(['#filters-content', '.filter-content', '.sidebar-main', '.sidebar-additional']);
            // Never hijack a container that holds the results themselves.
            return host && !host.contains(root) ? host : null;
        }

        // One rendered filter group + one option row, kept before the first refill overwrites
        // them. Prefer a group whose options are a plain list — a swatch group's row carries
        // markup specific to that attribute and does not generalise.
        function captureProtos(host) {
            var groups = host.querySelectorAll('.filter-option, .filter-options-item'),
                i,
                list;
            for (i = 0; i < groups.length; i++) {
                list = groups[i].querySelector('.items li, .item');
                if (list) {
                    return {group: groups[i].cloneNode(true), option: list.cloneNode(true)};
                }
            }
            return null;
        }

        // Text of the deepest element holding the group's title, without disturbing the
        // screen-reader suffix ("... filter") templates put beside it.
        function setGroupTitle(group, label) {
            var el = group.querySelector('h2, h3, .filter-options-title'),
                target;
            if (!el) {
                return;
            }
            target = el.querySelector('h2, h3') || el;
            // The first non-empty text node, wherever the theme nests it (a bare heading, or a
            // <span> inside it), so a screen-reader-only sibling survives. Inserting a new node
            // instead left the prototype's own label beside ours ("Size Departments").
            if (setFirstText(target, label)) {
                return;
            }
            target.insertBefore(document.createTextNode(label), target.firstChild);
        }

        function setFirstText(node, text) {
            var n;
            for (n = node.firstChild; n; n = n.nextSibling) {
                if (n.nodeType === 3 && n.nodeValue.trim()) {
                    n.nodeValue = text;
                    return true;
                }
                if (n.nodeType === 1 && !(n.classList && n.classList.contains('sr-only'))
                    && setFirstText(n, text)) {
                    return true;
                }
            }
            return false;
        }

        /*
           "Currently filtering by" comes from the server already rendered through the theme's own
           layer/state.phtml (see Controller\Search\Instant::renderState), so the chips, remove
           buttons and "Clear All" are the theme's, not ours. It goes above the filter groups,
           which is where the theme's own layered-nav template puts getChildHtml('state').
        */
        function hydrateThemeState(html) {
            var holder = facetHost.querySelector('[data-fm-state]'),
                previous = holder ? holder.querySelector('details') : null,
                first,
                details;

            // Re-read the shopper's choice before the markup that carries it is replaced.
            if (previous) {
                stateOpen = previous.open;
            }

            if (!html) {
                if (holder) {
                    holder.parentNode.removeChild(holder);
                }
                return;
            }
            if (!holder) {
                holder = document.createElement('div');
                holder.setAttribute('data-fm-state', '');
                first = facetHost.querySelector('[data-fm-facet-group], .filter-option, .filter-options-item');
                facetHost.insertBefore(holder, first || facetHost.firstChild);
            }

            // The server re-renders this card on every response, so unlike the filter groups there
            // is no node to keep — the open state has to be carried across by hand, or the card
            // snaps shut on each keystroke and each filter change.
            holder.innerHTML = html;
            details = holder.querySelector('details');
            if (details) {
                details.open = stateOpen;
            }
        }

        // Every link the state block renders — each remove button and "Clear All" — is a real
        // results URL for the set it leads to. Rather than teach the JS each one's meaning, adopt
        // that URL's query as the new state: one handler covers removing a single option, removing
        // the last one, and clearing everything, and the links still work with JS off.
        function onStateClick(e) {
            var link = e.target.closest ? e.target.closest('[data-fm-state] a') : null,
                params,
                next;
            if (!link) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            params = new URLSearchParams((link.getAttribute('href') || '').split('?')[1] || '');
            next = {};
            params.forEach(function (value, key) {
                var match = key.match(/^filter\[(.+)\]$/);
                if (match) {
                    next[match[1]] = value.split(',').filter(Boolean);
                }
            });
            state.q = params.get('q') || '';
            state.filters = next;
            state.page = 1;
            load();
        }

        // <details> is what the Tailwind-based themes use; `aria-expanded` covers a theme that
        // drives its groups from a button instead.
        function isGroupOpen(group) {
            if (group.tagName === 'DETAILS') {
                return group.open;
            }
            var toggle = group.querySelector('[aria-expanded]');
            return toggle ? toggle.getAttribute('aria-expanded') === 'true' : false;
        }

        function setGroupOpen(group, open) {
            if (group.tagName === 'DETAILS') {
                group.open = open;
                return;
            }
            var toggle = group.querySelector('[aria-expanded]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        }

        function stripDataAttributes(el) {
            var names = [],
                i;
            for (i = 0; i < el.attributes.length; i++) {
                if (el.attributes[i].name.indexOf('data-') === 0) {
                    names.push(el.attributes[i].name);
                }
            }
            names.forEach(function (name) {
                el.removeAttribute(name);
            });
        }

        function buildOption(proto, facet, opt, selected) {
            var li = proto.cloneNode(true),
                link = li.querySelector('a') || li,
                count = li.querySelector('.count'),
                labelEl;

            // The label lives in the first span that is neither the count (nor inside it: Luma nests
            // a "items" span in there) nor screen-reader-only. Luma renders the label as a bare text
            // node beside the count, so with no such span the first text node of the link is it.
            labelEl = Array.prototype.filter.call(link.querySelectorAll('span'), function (s) {
                return !s.classList.contains('count') && !s.classList.contains('sr-only')
                    && !(s.closest && s.closest('.count'));
            })[0] || null;

            if (labelEl) {
                labelEl.textContent = opt.label;
            } else if (!setFirstText(link, opt.label)) {
                link.textContent = opt.label;
            }
            if (count) {
                // Follow the prototype's own format: a theme that prints "(39)" gets the same,
                // one that prints "39" and draws the brackets in CSS must not get "((39))".
                count.textContent = /^\s*\(/.test(count.textContent || '')
                    ? '(' + opt.count + ')'
                    : String(opt.count);
            }
            if (link.tagName === 'A') {
                link.setAttribute('href', filterHref(facet.attribute, opt.value));
                link.setAttribute('rel', 'nofollow');
            }
            // The prototype carries the label + count of whatever option the theme happened to
            // render, phrased in the storefront locale. Rewriting that sentence here would mean
            // inventing a translation, so drop it: the link's own text ("XS (39)") is then its
            // accessible name, which is accurate in every locale.
            link.removeAttribute('aria-label');
            // The prototype's data-* attributes describe the option the theme rendered (its
            // request var, value, ajax URL) and are what the theme's own layered-navigation
            // script keys off. On a clone they would point every option at that one filter.
            stripDataAttributes(link);
            if (link !== li) {
                stripDataAttributes(li);
            }
            li.setAttribute('data-fm-facet', facet.attribute);
            li.setAttribute('data-fm-value', opt.value);

            // An applied option stays in the list (clicking it again clears the filter), so it
            // needs a visible state. Native layered nav has no such state — it moves applied
            // filters to the "Now Shopping By" block instead — so there is no theme class that
            // means "this option is on". aria-current is the standards answer and is what a
            // screen reader announces; the utility class beside it is one the theme already
            // compiles, so the emphasis comes from the theme's stylesheet rather than ours. On a
            // theme that does not ship that utility the class is simply inert and the option is
            // still announced correctly.
            if (selected) {
                link.setAttribute('aria-current', 'page');
                if (link.classList) {
                    link.classList.add('aria-[current=page]:font-medium');
                }
            }
            return li;
        }

        // Refill the theme's filter groups, leaving everything else it rendered (heading, state
        // block, skip link, mobile toggle) untouched.
        //
        // A group already on the page is REUSED rather than re-cloned. Re-cloning reset every
        // <details> to closed on each filter click, so the accordion a shopper had opened snapped
        // shut under them the moment they picked an option — the group node carries that
        // open/expanded state, so keeping the node keeps the state. It also keeps focus and any
        // behaviour the theme bound to that element.
        function hydrateThemeFacets(facets) {
            var previous = {},
                seen = {},
                existing = facetHost.querySelectorAll('[data-fm-facet-group]'),
                orphans,
                i;

            for (i = 0; i < existing.length; i++) {
                previous[existing[i].getAttribute('data-fm-facet-group')] = existing[i];
                // Remember it even for a group that is about to disappear: narrowing the query can
                // drop an attribute from the aggregation and a later keystroke bring it back, and
                // that returning group is a fresh clone which would otherwise come back collapsed.
                facetOpen[existing[i].getAttribute('data-fm-facet-group')] = isGroupOpen(existing[i]);
            }

            (facets || []).forEach(function (facet) {
                var group = previous[facet.attribute] || facetProtos.group.cloneNode(true),
                    list = group.querySelector('.items') || group.querySelector('ol, ul'),
                    chosen = state.filters[facet.attribute] || [];
                if (!list) {
                    return;
                }
                setGroupTitle(group, facet.label);
                list.innerHTML = '';
                (facet.options || []).forEach(function (opt) {
                    list.appendChild(buildOption(
                        facetProtos.option,
                        facet,
                        opt,
                        chosen.indexOf(String(opt.value)) > -1
                    ));
                });
                group.setAttribute('data-fm-facet-group', facet.attribute);
                if (!previous[facet.attribute] && facetOpen[facet.attribute]) {
                    setGroupOpen(group, true);
                }
                seen[facet.attribute] = true;
                // appendChild on a node already here just re-orders it, keeping its state.
                facetHost.appendChild(group);
            });

            // Groups the new aggregation no longer returns.
            orphans = facetHost.querySelectorAll('[data-fm-facet-group], .filter-option, .filter-options-item');
            for (i = 0; i < orphans.length; i++) {
                if (!seen[orphans[i].getAttribute('data-fm-facet-group')]) {
                    orphans[i].parentNode.removeChild(orphans[i]);
                }
            }
        }

        // A real, shareable URL for one option on top of the current selection.
        function filterHref(code, value) {
            var params = ['q=' + encodeURIComponent(state.q)],
                merged = {};
            Object.keys(state.filters).forEach(function (k) {
                merged[k] = (state.filters[k] || []).slice();
            });
            merged[code] = merged[code] || [];
            if (merged[code].indexOf(String(value)) === -1) {
                merged[code].push(String(value));
            }
            Object.keys(merged).forEach(function (k) {
                if (merged[k].length) {
                    params.push('filter[' + k + ']=' + encodeURIComponent(merged[k].join(',')));
                }
            });
            return window.location.pathname + '?' + params.join('&');
        }

        function render(data) {
            var header = '<div class="fm-results-header"><span class="fm-count">'
                    + escapeHtml(data.total) + ' result' + (data.total === 1 ? '' : 's')
                    + (data.query ? ' for “' + escapeHtml(data.query) + '”' : '')
                    + '</span></div>',
                body = renderProducts(data.products || []) + renderPagination(data),
                facets = renderFacets(data.facets);

            if (facetHost && facetProtos) {
                hydrateThemeState(data.stateHtml);
                hydrateThemeFacets(data.facets);
                root.innerHTML = header + body;
                return;
            }

            if (facetHost) {
                facetHost.innerHTML = facets;
                root.innerHTML = header + body;
                return;
            }

            root.innerHTML = header + '<div class="fm-results-body"><aside class="fm-sidebar">'
                + facets + '</aside><div class="fm-results-main">' + body + '</div></div>';
        }

        function load() {
            if (controller) {
                controller.abort();
            }
            controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            root.classList.add('fm-loading');
            fetch(requestUrl(), {
                signal: controller ? controller.signal : undefined,
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data) {
                        render(data);
                        syncUrl();
                    }
                })
                .catch(function () { /* aborted or offline */ })
                .then(function () { root.classList.remove('fm-loading'); });
        }

        // Delegated so the handlers survive every re-render. Bound to BOTH hosts because the
        // facets may live outside `root` now (in the theme's sidebar).
        function onFacetChange(e) {
            var el = e.target;
            if (!el.matches || !el.matches('input[type="checkbox"][data-facet]')) {
                return;
            }
            var code = el.getAttribute('data-facet'),
                value = String(el.value),
                list = state.filters[code] || [],
                idx = list.indexOf(value);
            if (idx > -1) {
                list.splice(idx, 1);
            } else {
                list.push(value);
            }
            state.filters[code] = list;
            state.page = 1;
            load();
        }

        facetHost = resolveFacetHost();
        if (facetHost) {
            // Grab the theme's markup BEFORE the first refill replaces it.
            facetProtos = captureProtos(facetHost);
            facetHost.addEventListener('change', onFacetChange);
            // Capture phase, and the handled click stops there: a theme's ajax layered
            // navigation binds its own click handler to the very links we clone (Breeze mounts
            // it after our first render, so the clones carry it) and would otherwise fetch the
            // native, takeover-emptied results page and replace the grid and sidebar with it.
            // The instant grid owns filtering on this page; our listener on the host runs
            // before any listener on the link and ends the event once it has acted.
            facetHost.addEventListener('click', function (e) {
                onStateClick(e);
                onFacetClick(e);
            }, true);
            if (!facetProtos) {
                // Self-contained rail: it needs our stylesheet, the theme's markup does not.
                facetHost.classList.add('fm-facet-host');
            }
        }

        root.addEventListener('change', onFacetChange);

        // Theme-rendered options are links, so filtering stays a client-side toggle instead of a
        // page load. The href remains valid for middle-click / no-JS.
        function onFacetClick(e) {
            var row = e.target.closest ? e.target.closest('[data-fm-facet]') : null,
                code,
                value,
                list,
                idx;
            if (!row || e.defaultPrevented) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            code = row.getAttribute('data-fm-facet');
            value = String(row.getAttribute('data-fm-value'));
            list = state.filters[code] || [];
            idx = list.indexOf(value);
            if (idx > -1) {
                list.splice(idx, 1);
            } else {
                list.push(value);
            }
            state.filters[code] = list;
            state.page = 1;
            load();
        }

        root.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.fm-page') : null;
            if (!btn) {
                return;
            }
            state.page = parseInt(btn.getAttribute('data-page'), 10);
            load();
            root.scrollIntoView({behavior: 'smooth', block: 'start'});
        });

        // As-you-type from whichever header search box the theme rendered.
        findSearchInputs(config.searchInputSelector).forEach(function (input) {
            input.addEventListener('input', function () {
                var q = input.value.trim();
                clearTimeout(timer);
                timer = setTimeout(function () {
                    if (q !== state.q) {
                        state.q = q;
                        state.page = 1;
                        state.filters = {};
                        load();
                    }
                }, 300);
            });
            if (input.form) {
                input.form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    state.q = input.value.trim();
                    state.page = 1;
                    load();
                });
            }
        });

        load();
    }

    /* ── boot ───────────────────────────────────────────────────────────────────────────── */

    function boot() {
        var instantConfig = readConfig('fm-instant-config'),
            instantRoot = document.getElementById('fm-instant-results');

        // On the results page the header box drives the live grid, so the dropdown would fight
        // it — same rule the old layout expressed by removing the autocomplete block there.
        if (instantConfig && instantRoot) {
            initInstantSearch(instantConfig, instantRoot);
            return;
        }

        var acConfig = readConfig('fm-autocomplete-config');
        if (acConfig && acConfig.suggestUrl) {
            findSearchInputs(acConfig.searchInputSelector).forEach(function (input) {
                if (!input.dataset.fmAutocomplete) {
                    input.dataset.fmAutocomplete = '1';
                    initAutocomplete(acConfig, input);
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
