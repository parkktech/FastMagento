# FastMagento — RESUME HERE (fresh-session pickup)

Start here, then read **`docs/ARCHITECTURE.md`** (the canonical how-it-works map — interception
points, file responsibilities, gotchas, dormant code). `README.md` is the user-facing doc.
`git log --oneline` tells the detailed story.

## ⚠️ ACTIVE HANDOFF (2026-07-22, session 2) — READ FIRST

**The dev site is currently pointed at the 500k SCALE DB, not prod.** `app/etc/env.php` `dbname`
was switched `diyprod_db → diyscale_db` (backup at `app/etc/env.php.diyprod-backup`), and
`catalog/search/opensearch_index_prefix` set to `scale` (isolated indexes `scale_products` /
`scale_product_1`; live `magento2_*` untouched). **When benchmarking is done, restore prod:**
`cp app/etc/env.php.diyprod-backup app/etc/env.php && php bin/magento cache:flush`.

**A 500k reindex is running/finishing in the background** (building `scale_products`, ~518k docs).
Verify done: `curl -s localhost:9200/scale_products/_count` (~518771) and
`pgrep -f "indexer:reindex"`.

**MySQL CLI creds:** regenerate a defaults file from env.php (`[client] host/user/password`) to run
`mysql --defaults-extra-file=... diyscale_db`.

### TODO for this fresh session (in order)
1. **Wait for the 500k reindex to finish**, then **compile**: `bash bin/magento-compile-safe`
   (needed for the new attribute-pagination controllers/block/plugin — production mode).
2. **Test the paginated attribute-option manager** (new, committed, UNTESTED). Admin → Stores →
   Attributes → Product → edit each and confirm the page opens instantly + add/edit/delete/search:
   `color` (50k visual swatch), `size` (330 text swatch), `part_type` (1000 select),
   `compatible_platforms` (1000 multiselect). Confirm search-by-name AND by option-id. Files under
   `Model/AttributeOption`, `Controller/Adminhtml/AttributeOption`, `Block/Adminhtml/AttributeOption`,
   `Plugin/Adminhtml/AttributeSaveOptionGuardPlugin`, `view/adminhtml/...`. Admin login needed.
3. **Run the huge-vs-small benchmark** and fill the README placeholders under
   `## 📊 Performance at scale — 500,000 products` (page-load / SQL / DOM tables, with vs without —
   toggle the module via `bin/magento module:disable/enable ParkkTech_FastMagento`). Optionally
   generate a chart SVG at `docs/img/scale-benchmark.svg`.
4. **Record the 3 demo GIFs** → `docs/img/demo-{autocomplete,instant-serp,shop-by}.gif` (placeholders
   already in README). Pipeline: Playwright screenshot sequence → `convert -delay N -loop 0
   frames/*.png out.gif` (ImageMagick `convert` present; ffmpeg is NOT). Source: local 500k store
   (most impressive) or live diyoffroad.com.
5. **Finish remaining README feature hooks** — a few sections still lack the "as-seen-on-TV" hook
   (All product types, Related/sliders, B2B pricing, Read-path resilience).
6. **Push the tested code to the extension master**: the attribute-pagination + README work is on
   `origin` (diy-offroad) but NOT yet on `fastmagento` master. After testing:
   `git subtree split --prefix=app/code/ParkkTech/FastMagento -b fastmagento-sync && git push fastmagento fastmagento-sync:master`.
7. **Then build the approved PLP OS layered navigation** (server-side, SEO-safe) — see below / ARCHITECTURE §10.

### What shipped this session (2026-07-22 pt2)
- **Search optimization layer** (AI keywords, thesaurus, operator, symmetric synonyms, relevance) —
  see [[fastmagento-search-optimization-layer]]. **Subtree master overridden** onto our branch
  (osman archived at `archive/osman-master` + tag `osman-master-pre-override`); PR #2 obsolete.
- **500k scale DB + generator** `docs/tools/scale-catalog.php` — see [[fastmagento-scale-testing]].
- **Paginated attribute-option manager** (admin, committed, pending compile+test above).
- **README** fully rewritten: story/humor, per-feature SEO sections with infomercial hooks, AI-search
  showcase + cross-niche examples, Problem→Solution, demo-GIF + 500k-perf placeholders, badges/icons.
- Fast Checkout default-on; docs/ARCHITECTURE.md is the canonical map.

---

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
