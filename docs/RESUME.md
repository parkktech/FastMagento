# FastMagento — RESUME HERE (fresh-session pickup)

Start here, then read **`docs/ARCHITECTURE.md`** (the canonical how-it-works map — interception
points, file responsibilities, gotchas, dormant code). `README.md` is the user-facing doc.
`git log --oneline` tells the detailed story.

## Current state (what's done)

The OpenSearch serving layer is built and live for **PDP, cart/checkout, search, related/up-sell,
and category *data* (menu/nav/breadcrumbs/URLs)** — all served from `magento2_products` /
`magento2_categories` with product/EAV SQL driven toward zero on the hot paths. MySQL stays the
source of truth; everything degrades to native EAV on a miss or an OpenSearch outage.

- **Product serving** — `ShellNoEavProduct` (no-op `load()`, getters from `_source`) at every
  read choke point (`Product::load`, repository, collections, price models, stock registry). §2–3
  of ARCHITECTURE.md.
- **Pricing** — per-customer-group catalog-rule price maps in the doc; the ~660-query configurable
  rule/tier N+1 eliminated; live via `CatalogRuleSyncer`. §5.
- **Fast Checkout** — OS-served quote-item collection; **on by default** (master
  `enable_fast_checkout` implies OS-serve + optimistic stock + fast stock sync). Cannot oversell
  (SKU-gated at placement). §6.
- **Real-time stock sync** — order/refund/MSI writes reproject off-response via `StockSyncer`. §4.
- **Category serving** — whole tree in one search; menu/nav/breadcrumb/layered-nav category
  attributes + URLs from OS (0 category EAV reads, url_rewrite N+1 collapsed). §2 read-path table.
- **Search** — realtime autocomplete + live search results page (grid + pagination + layered-nav
  facets, no reload); relevance overhaul (phrase/all-terms/symmetric synonyms/operator); AI keyword
  layer (`fm_search_keywords` + CLI); bundled starter thesaurus + content-aware AI thesaurus tool. §7.
- **All product types** — simple/virtual/downloadable/configurable served (swatches + jsonConfig +
  add-to-cart from OS); grouped/bundle indexed, add-to-cart not fully exercised.

`README.md` is complete (benchmarks, capacity, config reference, architecture + Mermaid diagram).
The module is synced to `parkktech/FastMagento` **master** (subtree — see below).

## Environment (local dev)
- Branch `feature/fastmagento-opensearch-layer`. Site `http://www.diyoffroad.loc/`, **production
  mode**, prod DB clone (MySQL 8), OpenSearch :9200. Full product index = **14,604 docs**.
- Reindex: `bin/magento indexer:reindex fastmagento_product fastmagento_category`
  (+ `catalogsearch_fulltext` after AI keyword runs).
- New DI (e.g. a new class/command) in production mode needs a compile — use
  `bin/magento-compile-safe` (PHP 8.3 segfault-safe settings; see `MAGENTO_DI_COMPILATION_FIX.md`).
  `opcache.validate_timestamps` is on, so PHP edits to existing classes go live without a compile.
- Verify serving: `bash docs/tools/query-profile.sh enable|<path>|disable` (PDP/category = 0
  product/EAV/catalog SQL). Measure relevance: `php docs/tools/search-relevance.php`.

## Subtree sync (important)
FastMagento is a git subtree at `app/code/ParkkTech/FastMagento/`. Remotes: `fastmagento` =
git@github.com:parkktech/FastMagento.git, `origin` = diy-offroad. To publish module changes:
```
git subtree split --prefix=app/code/ParkkTech/FastMagento -b fastmagento-sync
git push fastmagento fastmagento-sync:master        # fast-forward (master == our line now)
```
`master` was force-overridden onto our branch (osman's original is preserved at
`archive/osman-master` + tag `osman-master-pre-override`). Also push the feature branch to `origin`
to back up. `gh pr edit` hits a projectCards GraphQL error here — use `gh api -X PATCH` for PR edits.

## NEXT — approved
**PLP / category grid from OpenSearch — server-side, SEO-safe** (deferred "Phase 2"). Today the
category page renders natively: per-item product data is already OS-served, but category→product
**membership**, sort/pagination and **layered-nav filtering** are still native MySQL. Approach:
serve the category product collection (membership + facets) from OpenSearch **server-side**, keeping
Magento's native render / canonical URLs / crawlability — NOT by cloning the client-side search app
(category pages are SEO landing pages). The old dormant path (`Model/Search/ProductSearch`,
`Block/Product/ListProduct`) is broken/incomplete — do not revive it as-is (see ARCHITECTURE.md §9).

Other open items: grouped/bundle add-to-cart hardening, per-store serving (docs project the default
store view), multi-select facet labels (needs an indexed option dictionary), zero-downtime alias
reindex.

## Related docs
- `docs/ARCHITECTURE.md` — how it works (canonical).
- `docs/OPENSEARCH-SERVING-LAYER-PLAN.md` — original roadmap (historical; status in ARCHITECTURE §9).
- `docs/CART-RESUME.md` — Fast Checkout deep dive + supervised-order test notes.
- `docs/SEARCH-KEYWORDS-SPEC.md` — search relevance/keywords spec (implemented).
- `docs/SWATCH-PRESELECT-PLAN.md`, `docs/QA-OS-COVERAGE.md`, `docs/BLOCKERS.md` — feature/QA notes.
