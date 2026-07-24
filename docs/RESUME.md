# FastMagento — RESUME HERE (fresh-session pickup)

Start here, then read **`docs/ARCHITECTURE.md`** (the canonical how-it-works map — interception
points, file responsibilities, gotchas, dormant code). `README.md` is the user-facing doc.
`git log --oneline` tells the detailed story.

## ⚠️ ACTIVE HANDOFF (2026-07-23 session 3 — Stripe tax resolved + two store-side N+1 fixes) — READ FIRST

Continued the detect→diagnose→fix→confirm loop off the Efficiency Monitor. Still on the **scale DB**
(`diyscale_db`, `scale` prefix, production mode). Commits are **committed-pending — NOT pushed**.

### Resolved: the Stripe-Tax-disabled live flag
This store uses **native Magento tax** (5 tax rules; US-CA 7.75%/8.25%; `tax/defaults/region=12`). Stripe
Tax has **zero** config and Vertex is off, so `StripeIntegration_Tax` was only ever an unused override →
**left DISABLED**. Proved native tax still calculates with it off: scripted CA-address guest quote →
subtotal 300 → **tax 23.25 (7.75%)** → grand total 328.25. No action needed; do NOT re-enable.

### Shipped this session (all committed-pending)
0. **Jadog_Marketplace Webkul-helper memoization** (commit `56dab972b`) — `Webkul\Marketplace\Helper\Data::getSellerCollectionObj()` re-queries `marketplace_userdata` on every call and is the seam every storefront seller check funnels through (isSeller/getSellerStatus/header blocks). Added a **preference** subclass (`Jadog\Marketplace\Helper\Data`) that memoizes the built collection per (seller, store) and defers to parent — byte-identical, only repeat queries removed. Preference (not plugin) because `isSeller()` calls it via `$this->`. Safe: no caller mutates the returned collection (verified read-only across app/code + vendor). **Verified:** marketplace_userdata findings 3→0; home+PDP still 200. (User directive: marketplace fixes go through Jadog_Marketplace.)
1. **Jadog_Stripe** (new module, commit `620e5e73f`) — kills the checkout subscription-read N+1 that
   fired even with subscriptions OFF. Two around-plugins gated on a memoized
   `payment/stripe_payments_subscriptions/active` check (`Model/SubscriptionsState.php`):
   - `SubscriptionProduct::from{QuoteItem,OrderItem,ProductId}` → return the model untouched (product
     stays null → `isSubscriptionProduct()` false = the exact non-subscription state Stripe produces).
     Kills `Helper\Product::getProduct` ×32 + `Helper\Subscriptions::getSubscriptionOptionDetails` ×16.
   - `SubscriptionOptions\ReadHandler::execute` → return entity un-hydrated (matches native no-row path).
     Kills the extension-attr read ×8.
   Both no-op the moment subscriptions are switched on. **Verified:** all StripeIntegration checkout
   findings gone; checkout totals + native tax still correct. ⚠️ `app/etc/config.php` is gitignored →
   on deploy run `bin/magento module:enable Jadog_Stripe` (same caveat as Jadog_Marketplace).
2. **Jadog_StructuredData breadcrumb fix** (commit `cc28d5cdb`) — `BreadcrumbSchema::bestCategory` /
   `categoryPath` loaded categories one id at a time (`categoryRepository->get()` per id → `bestCategory`
   ×9 catalog_category_entity on PDP). Replaced with a single request-memoized `Category` **collection**
   load (`loadCategories()`) — OS-served via FastMagento's `CategoryAttributeLoadPlugin`, and 1 query
   instead of N on any store. **Verified:** PDP still renders the full Home>…>product BreadcrumbList
   JSON-LD; `bestCategory` gone from findings.

### Diagnosed, intentionally NOT patched (with reasons)
- **Mageplaza Productslider `getProductParentIds` ×11 (home)** — the per-product `getParentIdsByChild`
  calls are **already FastMagento-served** (`etc/frontend/di.xml:64,75-79`, comment literally says
  "product sliders call it"). The ×11 attributed to `sales_bestsellers_aggregated_monthly` is the
  widget's own bestsellers-collection load — inherent, not a fixable N+1.
- **`ProductSchema::productRefs` ×6 (url_rewrite)** — `getProductUrl()` per related product; served from
  `url_path` via the link-collection plugin once the linked products are OS-indexed (a scale-DB artifact,
  fine on prod's fully-indexed 14k).
- **`ProductReviews::getFormHtml` ×4 (rating)** — core Magento review-form block loading ratings, not ours.
- **Webkul `Plugin\App\Action\Context::aroundDispatch` ×3 (catalog_category_entity, PLP)** — Webkul's
  per-dispatch category load on category pages; small, not yet patched.

### Next (unchanged priority): update PR #6 (needs user OK), then optional Monitor date-range/history.
Restore prod when the scale-DB testing is truly done:
`cp app/etc/env.php.diyprod-backup app/etc/env.php && php bin/magento cache:flush` (left on scale DB for now).

## ⚠️ ACTIVE HANDOFF (2026-07-23 session 2 — Extension Efficiency Monitor) — READ FIRST

New admin feature shipped this session: **Extension Efficiency Monitor** (task #11). Code-complete,
`magento-code-reviewer`-passed (all blocking fixes applied), compiled, and live-verified on the 500k
scale DB. **Committed-pending — NOT yet pushed to `fastmagento` master** (ask user first).

**What it is:** admin dashboard under *FastMagento → Extension Efficiency* that profiles how much DB
work each third-party extension adds to the storefront hot paths (PDP / PLP / Search), attributing
every SQL query to the extension that fired it via `db_logger` stacktraces. Segmented core-vs-extension
bars, severity-ranked extension table with the offending `class::method` + tables touched.

**Three triggers** (per user request): admin **"Run scan now"** button (background), **cron**
(`fastmagento/efficiency/cron_expr`, off by default), and **CLI** `bin/magento fastmagento:efficiency:scan`.
Plus a **staleness banner** (≥7 days) and **auto-refresh while scanning** (lock-driven).

**Files:** `Model/Efficiency/{Profiler,DbLogParser,ModuleAttributor,ScenarioRunner,ReportStorage}.php`,
`Console/Command/Efficiency{Scan,Worker}.php`, `Cron/EfficiencyScan.php`,
`Controller/Adminhtml/Efficiency/{Index,Scan}.php`, `Block/Adminhtml/Efficiency/Dashboard.php`,
`view/adminhtml/{layout/fastmagento_efficiency_index.xml,templates/efficiency/dashboard.phtml}`,
+ di/menu/acl/system/config/crontab wiring. Report JSON: `var/fastmagento/efficiency-report.json`.

**How it works / gotchas:**
- The scan flips `db_logger` on via an **atomic** env.php write (temp+rename), runs each scenario in a
  **fresh `bin/magento` worker** (so it bootstraps with logging on — the parent can't self-log), parses
  the log, then restores db_logger and **empties db.log**. A **named lock** serializes scans; a
  leftover-config check prevents logging ever getting stuck on.
- **Measurement caveat (important):** on a live FastMagento store, `getById`/PDP is **OS-served**, so
  native-vs-FastMagento product-load can't be measured (that's the whole point — the cost is gone). The
  monitor therefore measures **extension tax = total queries vs total-minus-third-party** per hot path,
  which IS measurable and honest. On the scale DB the numbers are small (generated products have no
  marketplace/Stripe associations); it still correctly flags **Webkul Marketplace** (its
  `Model\Rewrite\...\Product\Collection::load` adds all 5 queries on a category page = 100% of PLP cost,
  HIGH impact). **PROD would surface more** (real Webkul seller data, Stripe) — worth a demo scan there.
- `search` on the scale index returns 0 results for sampled terms (store search-index routing quirk);
  ops is counted per-search (=1) so it's not misleading. PDP measures 0 queries (fully OS-served).

**Reindex:** the 500k run finished — `518,771` docs, all indexers Ready (product 57m06s, category 0s,
catalogsearch 2m40s). Still on scale DB.

**README 500k benchmark re-verified (task #5, done)** on the full index, module `disable` vs `enable`,
cache-busted cold render, median of clean runs. New numbers replace the partial-index ones (which
badly overstated the WITH query counts). Headline: **home 10,090→80 q (~126×), 2,911→775 ms (3.8×);
search 220→23 q (−90%); config PDP 388→231 q, 1,371→827 ms (1.7×); cart collectTotals 1,210→265 q
(−78%); checkout totals-information 1,250→295 q (−76%)**. Category is ~flat / a hair slower on
wall-clock (light page, PHP-dominated + OS round-trip) though −47% queries. Both README tables + the
"how to read it" note updated.

**Serving bug FIXED this session:** `ShellNoEavProduct::getOptions()` returned `null` (inherited from
the shell's no-op load), so core `product/view/options.phtml`'s `count($block->getOptions())` fataled
(PHP 8 `count(null)`) → HTTP 500 on any product whose OS doc has no `options` field (hit `605-jeh-001`
= entity_id 1). Added a `getOptions()` override returning `[]` on miss (also hardened
`ShellDataProduct::getOptions()`). Verified `605-jeh-001` → 200 with real content. Compiled in.

**Everything above is committed-pending — nothing pushed. Remaining: GIFs (tasks #3/#4), then release
(task #6, needs user OK to push to `fastmagento` master).**

---

## ⚠️ ACTIVE HANDOFF (2026-07-23 — verification + two production fixes)

Re-verified every 2026-07-22 TODO against the live box, then fixed two live-store bugs surfaced
during it. State below is measured, not assumed. **Code changes below are committed-pending — the
subtree has NOT been re-pushed yet** (see "Pending push", bottom of this block).

### 🛠️ Fixed this session (code changes, compiled + verified)
1. **Store-wide fatal on every OS-served product** (`generated/metadata/*.php` still referenced a
   deleted `ParkkTech\FastMagento\Plugin\CategoryProductCollectionPlugin` — not in any di.xml).
   Every `buildNoEavProductFromOsDoc()` instantiates a product collection → hit the dead plugin →
   hard fatal on PDP / category / search-grid. **Fix = recompiled DI** (regenerates the plugin
   lists from current di.xml). Verified: PDP id 1785 → HTTP 200 with real product content. This was
   a *stale-compile* landmine, not a code bug — if PDPs ever fatal with a "Plugin class … doesn't
   exist" report, recompile.
2. **Search grid blanked out during reindex** (`Model/Search/InstantSearch.php`). The native
   fulltext (matching) index and our serving index drift out of sync mid-reindex, so
   `hydrateProducts()` silently dropped matched products missing from the serving index → "183
   results for front, No products found." **Fix = warm-on-miss in `hydrateProducts()`**: missing
   hits are loaded natively once (read-through-indexes them into OpenSearch via the repository
   plugin) then re-fetched (GET-by-id is realtime in OS). Added a `mgetSource()` helper + injected
   `ProductRepositoryInterface`. Verified: `q=front` → 12 products (was 0). Products can no longer
   vanish from search during a reindex; it self-heals. *(Follow-up option: make the
   `fastmagento_product` indexer blue-green/alias-based like the native fulltext one, so the serving
   index is never partial from the read path's view — would remove the per-request native warm.)*

### 🟢 Reindex — now running RELIABLY (was stalled)
The 500k reindex had stalled at 238,200/518,771 (foreground run SIGHUP'd when the session closed).
**Relaunched fully detached** (`setsid`, logged to `var/log/fastmagento-reindex.log`) so it survives
session end — steady ~55 docs/s. This run reindexes **all three**: `fastmagento_product`,
`fastmagento_category`, AND `catalogsearch_fulltext` (the native matching index was only 5,518 docs
→ search only matched a tiny subset). Verify done: `tail var/log/fastmagento-reindex.log` shows
`DONE`, `curl -s localhost:9200/scale_products/_count` ≈ 518771. **The README 500k benchmark numbers
were captured against a partial index — re-verify after this completes.**

### ⚡ Performance investigation (2026-07-23) — indexer profiling + index pack
Profiled the indexer with `general_log` on a controlled 20-product run: **32 queries/product**,
and **every query is already index-hit** (url_rewrite rows=1) — so the reindex is bound by query
VOLUME + PHP hydration, **not** by missing indexes. Breakdown: ~26% **Webkul Marketplace**
(`marketplace_userdata`, fires on every `getById` full-hydration), ~3% **Stripe**, ~47% batchable
core EAV/stock/price loads, ~16% MSI. `productRepository->getById()` fully hydrates each product →
every installed module's product-load plugins fire.
- **DONE — large-catalog index pack shipped in `etc/db_schema.xml`** (auto-applies on
  `setup:upgrade`, removed cleanly on uninstall; whitelist generated). Composite
  `(attribute_id, store_id, value)` on `_varchar/_decimal/_datetime` (Magento already ships it on
  `_int`; `_text` excluded). **Proven: attribute-value filter 571,866-row scan → 1-row seek.**
  Helps storefront/layered-nav/admin on ANY large site — NOT the reindex. Applied+verified on
  `diyscale_db`. (Replaced the earlier `docs/tools/large-catalog-indexes.sql`, now removed.)
- **PENDING (task #10) — batched/lightweight indexer loader.** The real reindex win + the GENERAL
  third-party fix: load a chunk set-based (id IN) and skip full per-product hydration so NO
  third-party product-load hooks fire (helps any store, any 3rd-party module — not Webkul-specific).
  Flag-gated + byte-level OS-doc parity vs current path before enabling. Target 32 → ~5 q/product.

### 🔴 Still open
1. **3 demo GIFs missing → broken `<img>` on README/public master.** README references
   `docs/img/demo-autocomplete.gif`, `demo-instant-serp.gif`, `demo-shop-by.gif`; only
   `demo-attribute-manager.gif` exists (and it's slow — 5 frames, ~10s of pauses → re-record ~10fps).
   User wants a **search + realtime-search** GIF specifically. Record against the full 500k store
   after reindex (Playwright frames → `convert -delay N -loop 0 frames/*.png out.gif`; ffmpeg NOT
   present). Admin GIF: test admin is `newadmin` / `FastMag2026!` at `/admin_3xo245`.
2. **composer.json** — was MISSING; authored `parkktech/module-fast-magento` (valid), pending commit.
3. **Release tag** — only `osman-master-pre-override` exists. User wants `v1.<commit-number>` on the
   finished master after everything lands.

### ⏳ Pending push (do after GIFs + benchmark re-verify)
Commit (composer.json + InstantSearch warm-on-miss + RESUME + new GIFs + benchmark corrections),
then subtree-split → push to `fastmagento` master, then tag `v1.<commit-number>` and push the tag.

### ✅ Verified DONE (2026-07-22 work, re-checked 2026-07-23)
- **Compile + attribute-option manager** — built, extended (assigned-to-product column, filter,
  bulk delete, OS-served option labels) AND bug-fixed (layout alias collision, commit 6a75f2cf6);
  running in production mode. Files present under `Model/AttributeOption`,
  `Controller/Adminhtml/AttributeOption`, `Block/Adminhtml/AttributeOption/Manager.php`,
  `Plugin/Adminhtml/AttributeSaveOptionGuardPlugin.php`.
- **README** — 500k benchmark tables filled (see caveat in gap #1), full feature-hook coverage
  pass, no remaining placeholders. Only broken links are the 3 GIFs above.
- **Subtree push** — `fastmagento/master` root tree hash is **identical** to our extension subtree
  (`6d136d8897…`). Fully in sync through HEAD `8f14878cb` (landed via PR #4).

### Environment state right now
- **DB still on the 500k scale DB, NOT restored to prod.** `app/etc/env.php` `dbname` =
  `diyscale_db` (backup at `app/etc/env.php.diyprod-backup`), `opensearch_index_prefix` = `scale`
  (isolated `scale_*` indexes; live `magento2_*` = 14,604 docs, untouched). Restore when done:
  `cp app/etc/env.php.diyprod-backup app/etc/env.php && php bin/magento cache:flush`.
- Production mode. **MySQL CLI creds:** regenerate a `[client]` defaults file from env.php
  (`host/user/password`) → `mysql --defaults-extra-file=... diyscale_db`.

### Next feature (unchanged — not started, correctly)
**PLP OS layered navigation** (server-side, SEO-safe) — see below / ARCHITECTURE §10.

### What shipped 2026-07-22 (session 2)
- **Search optimization layer** (AI keywords, thesaurus, operator, symmetric synonyms, relevance) —
  see [[fastmagento-search-optimization-layer]]. **Subtree master overridden** onto our branch
  (osman archived at `archive/osman-master` + tag `osman-master-pre-override`); PR #2 obsolete.
- **500k scale DB + generator** `docs/tools/scale-catalog.php` — see [[fastmagento-scale-testing]].
- **Paginated attribute-option manager** (admin) — verified done (see above).
- **README** fully rewritten: story/humor, per-feature SEO sections with infomercial hooks, AI-search
  showcase + cross-niche examples, Problem→Solution, demo-GIF + 500k-perf tables, badges/icons.
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
