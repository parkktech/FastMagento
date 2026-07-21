# FastMagento — Autonomous session blockers & decisions

Running the OpenSearch-serving-layer plan autonomously (cheap models, no supervision).
This logs decisions I made without you and questions only YOU can answer. Nothing here
stops the build unless marked **NEEDS YOU**.

## Decisions made autonomously (reverse if you disagree)
- Working on branch `feature/fastmagento-opensearch-layer` (off the osman subtree).
- Site kept in **developer mode** for iterative work (was production).
- Executing phases in plan order 0 → 1 → 2 → 2L → 2S → 3 → 3R → 4 → 5 → 6, testing +
  committing each. Mechanical work delegated to Haiku to keep it cheap.
- **Shrinkage/reuse discipline (hard rule):** reuse Magento core + composer packages
  over new code — native OpenSearch client/adapter (drop the raw `OpenSearch\Client`
  path), core product/fulltext collections, core aggregation framework, native
  attribute + catalog-search config. Use `/srk:audit`/`/srk:shave` to delete dead
  code and `/srk:gate` before writing anything new.

## Progress
- **Phase 0 (stabilize):** di:compile GREEN (nullable params + ctor order fixed);
  conflicting ShellProductPlugin disabled; indexer hardened (index builds 1383 docs).
  Committed. Home page 200.
- **Phase 1 (product object):** PDP still 500 "Product is not loaded" — the OS-hydrated
  product is rejected by `Catalog\Helper\Product::initProduct` (visibility/status or
  getById scope). In-progress via delegated agent. This is the crux of Phase 1.

## DB-query baseline (via docs/tools/query-profile.sh) — the optimization worklist
- **PDP `/605-jeh-001.html`: 16 total queries, 0 product/EAV/catalog.** Fully served
  from OpenSearch — no product SQL, no redundant parallel DB load. ✅ (This is the
  author's "PDP flawless" milestone.) The 16 are framework bootstrap: session ×9,
  store ×3, etc. — normal Magento overhead, not product data.
- **Search `/catalogsearch/result/?q=link`: 314 total, 236 product/EAV/catalog.** The
  product *results* come from OS, but surrounding blocks still hit MySQL. Top offenders:
  - `url_rewrite` ×112  ← biggest: product/category URL lookups → index URLs into OS,
    make getProductUrl() use the indexed URL (Phase 1/2).
  - `catalog_category_entity*` ×58 ← category menu + layered-nav tree → serve category
    data from OS (Phase 2/2L).
  - `eav_attribute`/`catalog_eav_attribute` ×14, `search_query` ×3, misc.
  → This 236 is the concrete Phase 2/2L target: drive it toward 0.

## Milestone reached this session
Phase 0 (stabilize) done + committed: di:compile GREEN, plugin conflict resolved,
indexer runs on base Magento (1383 docs), search-client converged to native resolver.
Phase 1 PDP: renders fully from OpenSearch (0 product SQL). Site fully browsable
(home/PDP/cart/search 200); product images synced + resized. Everything committed.

## Open questions (answer when back — NEEDS YOU)
_(none yet — no decisions have required you)_

## Deferred / risky items I did NOT auto-do
_(none yet)_
