# FastMagento — OpenSearch Serving-Layer Plan

> Scoped roadmap to finish `ParkkTech_FastMagento`: make OpenSearch the sole
> read/serving layer for products, categories, search and layered navigation,
> while MySQL stays the source of truth and is kept in sync on every write.
> Produced from a full code discovery pass (read-path, write-path, product-object)
> against the current `osman-fast-magento` state.

## 1. Vision & core design tenets

1. **OpenSearch is the serving layer.** PDP, PLP/category, search, and layered
   navigation are served entirely from OpenSearch — zero MySQL/EAV product loads
   on those paths. **Measured, not assumed:** every page/method/class is profiled
   with the DB query logger (`docs/tools/query-profile.sh`, which logs each query
   with the class that fired it) and driven toward zero product-data SQL. No
   redundant/parallel DB load may run alongside an OpenSearch fetch for the same
   data — if OS provides it, the native EAV/collection load must be suppressed, not
   run-and-discarded.
2. **MySQL stays the source of truth.** The DB keeps all object-oriented storage.
   Every write (admin, REST/GraphQL, import, mass action, order stock decrement)
   keeps OpenSearch in sync — including deletes and qty/price changes.
3. **Total transparency to third parties.** Any code that reads or extends
   `\Magento\Catalog\Model\Product` (core blocks, themes, extensions, GraphQL,
   plugins/preferences) must keep working *unaware* the data came from OpenSearch.
   → The endgame object is the **real `Product`**, hydrated from OpenSearch, not a
   stand-in DTO. The real class means the real interceptor fires every plugin, and
   `getData()/getCustomAttribute()/getPriceInfo()/getMediaGalleryImages()` all work.
4. **Least code, lowest layer, largest coverage.** Override at the most basic
   Magento choke points (resource-model load/save/delete) so one override covers
   many surfaces via unmodified core blocks — instead of per-block/per-controller
   rewrites. Delete the code that a lower override makes unnecessary.
5. **Base Magento only.** No third-party module may be *required*. The module must
   run on a clean Magento 2.4.x + OpenSearch install.
6. **Cache & Varnish safe.** Everything must work behind Magento FPC **and Varnish**.
   OpenSearch is queried on cache-miss/page-build, never on a cache hit. Cacheable
   blocks must declare correct cache tags/keys; anything genuinely per-request
   (e.g. live layered-nav counts under active filters) is served via a **cacheable
   AJAX endpoint or an ESI/hole-punch**, so the surrounding page stays a Varnish
   cache hit. Filtered PLP URLs must remain cacheable and correctly keyed on the
   active filter set.
7. **Multi-store / multi-website native.** Indexing, reads, pricing, facets,
   autocomplete, and config are store-view / website scoped from day one (this
   install runs two websites). Index **per store view** (or store-scoped doc ids) —
   never collapse multiple stores onto one document.
8. **Never degrade below native.** If OpenSearch is down or stale, the storefront
   falls back gracefully to the native MySQL path (or a controlled degrade). An OS
   incident must never take the store down harder than base Magento would be.

> **Highest-risk item: layered navigation.** It is the single largest DB bottleneck
> in Magento and the hardest surface to move to OpenSearch. It gets its own detailed
> design (§3.1) and is planned as its own phase.

## 2. Current-state assessment (discovery findings)

The module currently contains **three competing, mostly-unwired strategies** and a
lot of dead code. What is actually live vs. broken:

### Works today
- **Single-product reads** via `Plugin\FrontendProductPlugin::aroundLoad` (on
  `Product::load`) and `Plugin\ProductRepositoryPlugin::aroundGetById`, both calling
  `Helper\OpenSearchPdpFetcher` → hydrate `Model\ShellProduct\ShellNoEavProduct`
  (which **extends the real `Product`** — correct for tenet #3). Covers PDP, and
  cart/checkout product hydration.
- **Search results page** via `Block\Search\Results` querying OpenSearch directly
  (isolated, hand-rolled, not integrated with Magento's search/aggregation framework).
- The custom `fastmagento_product` indexer + `mview` subscriptions capture
  create/update changelog entries for product/stock/price/category tables.

### Broken / dead / missing (the work)
- **`di:compile` fails** — implicit-nullable params (`Type $x = null`) in 7 files /
  15+ params (PHP 8.4). Blocks production builds. (Runs only in developer mode now.)
- **Two conflicting `aroundLoad` plugins** on `Product` at the same `sortOrder=10`
  (`ShellProductPlugin` vs `FrontendProductPlugin`) — non-deterministic; one can
  bypass the OpenSearch fetch and return an empty product.
- **Fatal in repository reads** — `ProductRepositoryPlugin::aroundGet/aroundGetList`
  call `ShellProductBuilder::convertToShellProduct()`, **which does not exist** →
  any `get($sku)`/`getList()` on the frontend throws.
- **Undefined `$osDoc`** in `ShellProductBuilder::buildNoEavProductFromOsDoc()`
  (should be `$doc`) → catalog-rule price path silently null.
- **PLP / category grid** — core `category.products.list` is removed and replaced by
  a custom block, but the block/template method names mismatch (`getProductCollection`
  vs `getProductList`) and the search service lacks `searchByCategory()` → grid renders
  empty / would fatal.
- **Layered navigation, toolbar, sorting, pagination** — untouched; still MySQL. The
  one collection plugin (`FrontendProductCollectionPlugin`) targets
  `CollectionFactory::aroundLoad` (no such method) → never fires.
- **AJAX controllers** for PLP/filter/search/pdp exist but the JS is disabled
  (`requirejs-config.js` commented out) → unreachable.
- **No write choke point / no delete propagation** — no plugin on
  `ProductRepository`/resource-model `save()/delete()`. Deletes leave **stale docs
  in OpenSearch forever** (biggest correctness gap). Sync depends on an admin
  indexer-mode setting the module neither sets nor enforces; the save-time observers
  (`Observer\ProductSave`, `Observer\UpdateIndex`) are **disabled/unregistered**.
- **Broken cron** — `crontab.xml` schedules a non-existent `Cron\ExistingCronJob`;
  the real `Cron\Reindex` is never scheduled; admin toggles
  (`enable_realtime_indexing`, `enable_cron_indexing`) are never read.
- **Indexer not resilient** — full `execute()` had no per-product try/catch and blew
  up on the first attribute whose `source_model` class is missing (the
  `Amasty\SeoToolKit\...\Robots` crash). *(Hardened in this branch — see §6.)*
- **Product data gaps in the doc/shell** — media gallery indexing is commented out;
  `custom_attributes` never populated (so `getCustomAttribute()` returns nothing);
  **downloadable** products unhandled (this store sells downloadable!); configurable
  swatches, related/upsell/cross-sell, and reviews not captured; catalog-rule price
  hard-coded to `website_id=1/customer_group_id=0`; `getResource()` still returns the
  DB resource (a third party calling `getResource()->load()` bypasses OpenSearch).
- **Three OpenSearch access paths + three index names** (`magento_products`,
  `<prefix>_pdp`, `<prefix>_products`) — must converge to one.
- **Multi-store is broken.** The indexer loops store views but writes every store's
  doc with `_id = product->getId()` into **one** index → docs collide and only the
  **last store view survives**. Store-scoped name/price/status/visibility/website
  assignment are wrong for all other stores (two websites live here). Needs
  index-per-store-view (or store-scoped `_id`) + store-filtered reads.
- **Stock model too shallow (no MSI).** Only `is_in_stock`/`qty`; ignores Magento 2.4
  Multi-Source Inventory salable-qty and reservations → wrong availability.
- **Pricing scope incomplete.** Price/catalog rules aren't resolved per customer-group
  / website / currency (catalog rule hard-coded to website 1, group 0).
- **`getProductUrl()` still hits MySQL** (`url_rewrite` lookup per call) — index URLs.
- **No OpenSearch-down fallback** — if OS is unavailable the storefront has no product
  data (MySQL is bypassed); no graceful degrade.
- **Full reindex causes downtime** — indexer does `deleteIndex()` then `createIndex()`,
  leaving an empty index mid-rebuild. Needs alias-swap (zero-downtime).
- **GraphQL/headless coverage unverified** — resolvers use repository/collections;
  confirm they receive OS data.

## 3. Target architecture (the resolved design)

Two choke points, both at the resource-model layer, plus one canonical object and
one index:

| Concern | Override point (lowest layer) | Covers |
|---|---|---|
| **Single product read** | `aroundLoad` on `Product` **or** a load override on `Magento\Catalog\Model\ResourceModel\Product` | PDP, cart, checkout, any `getById` |
| **Collection reads** | `<preference>` for `Magento\Catalog\Model\ResourceModel\Product\Collection` and `Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection` (frontend), overriding `_loadEntities()` + `getFacetedData()`/aggregations | PLP grid, **layered nav**, toolbar sort/pagination, search — all via unmodified core blocks |
| **All writes** | plugin on `Magento\Catalog\Model\ResourceModel\Product::save()/delete()` (or `EntityManager`) | admin, REST/GraphQL, import, mass action, order decrement — incl. **delete** |
| **The object** | one class: `ShellNoEavProduct extends \Magento\Catalog\Model\Product`, fully hydrated from the OS doc | third-party transparency (tenet #3) |
| **The index** | one index **per store view** (alias-swapped for zero-downtime) + one client resolver (drop the divergent raw-client path) | consistency, multi-store, safe reindex |
| **Store/website/group scope** | resolve store view, website, customer group, currency at index + read time; store-scoped index/`_id` + per-context price fields | correct multi-store data & pricing |
| **Resilience** | circuit-breaker around OS reads → fall back to native MySQL/EAV; alias-based reindex; drift reconciliation job | OS incident never harder-downs the store |

This deletes most of the current surface area: the per-block/per-controller/AJAX
approach, the two extra shell classes, and the disabled observers all become
unnecessary once the two resource-model choke points exist. (Least code, tenet #4.)

### 3.1 Layered navigation — detailed design (highest risk)

Layered nav is where Magento's MySQL model hurts most: for every category/search
page it computes, per filterable attribute, the set of option values *and their
result counts* scoped to the current category **and** the currently-active filters —
a fan-out of `catalog_product_index_eav*` joins that dominates DB load. Moving this
to OpenSearch is the biggest win and the hardest correctness problem. It needs an
explicit **attribute layer** design, not an afterthought.

**A. The attribute layer (source of truth in the DB, projected into OS).**
- A filterable-attribute registry driven by Magento's own attribute config
  (`is_filterable`, `is_filterable_in_search`, `used_in_product_listing`,
  `frontend_input`, `backend_type`, `attribute_group`, position/sort). This may
  require **adding/curating attributes, option values, and attribute groups** in the
  DB so the facet set is rich and correct — that curation is part of the work.
- Each filterable attribute is classified: **select/multiselect** (term facets on
  option_id + label + swatch), **price** (range/histogram facet, must honor catalog
  price rules + customer group + currency), **decimal/range** (e.g. weight),
  **boolean**, **category** (the category tree as a facet).

**B. Index mapping (retrievable, fast, accurate).**
- Project each filterable attribute into the product doc as a **`keyword`**-typed
  field carrying `{option_id, label, position, swatch}` (no `text`/analysis on facet
  fields — exact-match + aggregatable). Multiselect → array of keywords.
- Store both **option_id** (for the filter query / URL) and **label + swatch**
  (for rendering) so the sidebar renders with zero MySQL.
- Index price per **customer group + website + currency**, with catalog-rule price
  already applied, so price facets are accurate per context.
- Category membership as a keyword/`category_ids` field for category-scoped facets.
- Denormalize enough attribute *metadata* (label, frontend type, position, group,
  sort) either into a small companion index/doc or the app config, so building the
  sidebar needs no EAV lookups.

**C. Serving facets (the hard part) — correctness of counts.**
- Build a single OpenSearch request per page: a **filtered query** for the result
  set + **aggregations** for each facet. Use the standard layered-nav rule: a facet's
  own counts are computed with all *other* active filters applied but **not** its own
  (so multi-select within one attribute stays additive) — implement via
  `post_filter` + per-aggregation `filter` sub-aggs, or a filtered-agg per facet.
- Feed these back through Magento's own layered-nav contract by overriding at the
  collection layer (`Fulltext\Collection::getFacetedData()` /
  `Magento\Framework\Search\Response\Aggregation`) so the **stock** `catalog.leftnav`
  blocks and filter classes render unchanged (tenet #3/#4) — no bespoke sidebar.
- Accuracy checks vs. the MySQL baseline: counts, availability of "no results"
  filters, price ranges, and swatch rendering must match before cutover.

**D. Cache & Varnish (tenet #6).**
- A filtered PLP URL (`?color=red&size=xl`) is a distinct, **cacheable** page: the
  OpenSearch query runs on cache-miss/build only; on a Varnish hit nothing queries
  OS or MySQL. Ensure the FPC/Varnish cache key includes the active filter params
  and the sidebar block carries correct cache tags (`cat_p`, attribute/option tags)
  so admin edits invalidate the right pages.
- If any live/AJAX faceting is added (e.g. instant filter counts without full reload),
  it must be a **separately cacheable** endpoint or an ESI include so it never busts
  the surrounding Varnish object — consistent with how this site already hole-punches
  blocks. Avoid per-request uncached OS calls on otherwise-cacheable pages.

**E. Invalidation.** Attribute/option/price/stock/category changes must re-project the
affected products into the OS facet fields (Phase 3 write hook) and invalidate the
matching FPC/Varnish tags — so facets stay accurate without a full reindex.

### 3.2 Multi-store / multi-website & scoping

- **Index per store view** (mirror core: `<prefix>_product_<storeId>_v<n>`) — or one
  index with a store-scoped `_id` (`{storeId}_{productId}`) + a `store_id` filter on
  every read. Index-per-store is preferred (matches native; clean cache/store switch).
- Index **store-scoped values** (name/description/status/visibility per store view)
  and **website/group/currency-scoped price** (regular/special/tier + catalog rule,
  resolved per website + customer group + currency), plus `website_ids`.
- Every read path (PDP fetch, collection, autocomplete, facets) resolves the current
  store view and queries that store's index/scope.
- All admin config (§3.3) is website/store scoped so each site can differ.

### 3.3 Admin configuration design

Principle: **reuse native config as the source of truth; add a thin FastMagento layer
only for what native config can't express.** All sections website/store scoped.

**Reused native config (do not duplicate):**
- Attribute filterability/searchability/position/weight — `Stores → Attributes →
  Product` (`is_filterable`, `is_filterable_in_search`, `used_in_product_listing`,
  `is_searchable`, `search_weight`, position). Indexing/faceting is driven by these.
- Catalog Search config — `Stores → Config → Catalog → Catalog Search` (min/max query
  length, **synonyms**, autocomplete limit). Tie into these.

**FastMagento layered-nav facet settings** (per-attribute UI-component grid, seeded
from native `is_filterable`; not flat fields):
- Render type (checkbox / swatch / single-select / price slider / range); show counts;
  max options before "Show more"; option sort (count / name / position); default
  collapsed; hide zero-count; hide single-option; multi- vs single-select;
  primary-vs-secondary + facet order; per-context (category vs search) override.
- Price facet: interval mode (auto / fixed step / manual ranges / histogram).

**FastMagento search-autocomplete settings** (the "what shows in autocomplete"
flexibility):
- Global: enable, min chars, debounce ms, max results, cache TTL.
- Result groups (toggle + order + max per group): Products, Categories, CMS pages,
  Search-term/popular suggestions, Brands/attribute values.
- Product result-card fields (each toggleable): thumbnail, name, price
  (group/currency-aware), SKU, short description, rating stars, stock badge,
  add-to-cart button.
- Matched fields + weights (reuse native `is_searchable`/`search_weight`, override
  optional); fuzziness/typo tolerance; synonyms on/off; boost in-stock; boost by
  popularity/sales; min score.
- No-results behavior + suggested terms; optional query→URL redirect rules; optional
  recent/trending searches.

**Indexing/ops settings** (fix the currently-dead ones): make
`enable_realtime_indexing` / `enable_cron_indexing` actually control behavior (or
remove); index prefix; OpenSearch host/auth/TLS; fallback toggle; reconciliation
schedule.

## 4. Phased roadmap

**Phase 0 — Stabilize & consolidate (unblock the build; delete dead code)**
- Fix all implicit-nullable params → explicit `?Type`; get `setup:di:compile` green.
- Resolve the duplicate `aroundLoad`: keep `FrontendProductPlugin`, remove `ShellProductPlugin`.
- Fix `ProductRepositoryPlugin` (`convertToShellProduct` → real builder method) and the `$osDoc`→`$doc` bug.
- Harden the indexer (source-model guard + per-product try/catch — done in this branch) and remove any 3rd-party assumptions (base-Magento only).
- Collapse 3 shell classes → 1 (`ShellNoEavProduct`); delete unused collections/observers/AJAX/JS/broken cron. Run `/srk:audit` on the module and shave.
- Converge to one OpenSearch client path + one index name.

**Phase 1 — Product object complete (PDP-perfect, transparent to 3rd parties)**
- Index + hydrate the full product: media gallery, populated `custom_attributes`
  (so `getCustomAttribute()` works), tier/special/catalog-rule price (per
  website/customer-group/currency), **MSI salable-qty/reservations** stock, indexed
  URL (drop the `url_rewrite` per-call lookup).
- **Multi-store**: index per store view + store-scoped reads (see §3.2).
- **Index the URL, don't look it up.** Store the real `url_rewrite.request_path`
  (per store view) in the doc so `getProductUrl()` returns it verbatim — zero
  `url_rewrite` SQL AND correct for custom rewrites (building from `url_key`+suffix
  alone breaks custom URLs). Baseline showed `url_rewrite` = 112 queries on a search
  page; same lesson applies to category URLs (§2 Phase 2L: index category
  request_path/name/tree per store so the menu/breadcrumbs/leftnav render from OS).
- General rule proven by profiling: **whatever a page renders must be in the index**
  (store-scoped) — then its DB queries go to zero.
- Make `getResource()` safe so no code path silently falls back to MySQL.
- Compatibility test suite: core + representative 3rd-party calls to standard
  `Product` methods return OS-sourced data unaware.

**Phase 2 — Read path at the lowest layer (PLP / category / search result sets)**
- Wire the resource-model collection `<preference>`s so category grid, catalog
  search, toolbar sort/pagination all pull result sets from OpenSearch via
  unmodified core blocks; remove the broken custom blocks/controllers/JS. Confirm
  pages stay FPC/Varnish-cacheable. Verify via query logger: zero product SQL.

**Phase 2L — Layered navigation (highest risk; own phase — see §3.1)**
- Build the **attribute layer**: curate filterable attributes/options/groups in the
  DB; project them into the OS doc as keyword facet fields (option_id + label +
  swatch + position), price per customer-group/website/currency with catalog rules
  applied, category facet.
- Serve facets + accurate counts via OpenSearch aggregations fed back through
  `Fulltext\Collection::getFacetedData()`/`Aggregation` so **stock `catalog.leftnav`**
  renders unchanged. Match MySQL baseline counts/ranges/swatches before cutover.
- Cache/Varnish: filtered PLP URLs cacheable + correctly keyed/tagged; any live
  faceting via a separately-cacheable AJAX/ESI endpoint. Invalidate on attr/option/
  price/stock/category change.

**Phase 2S — Instant search & instant-filter UX (all from OpenSearch)**
- **Rich type-ahead autocomplete**: a debounced JS widget on the search box hitting a
  dedicated OpenSearch-backed suggest endpoint that returns **product cards** (image,
  name, price, and key details) plus category/popular-term suggestions as the user
  types — no MySQL. Responses **cacheable** (short-TTL, keyed on query prefix) so
  Varnish/FPC absorb repeat prefixes.
- **Live full-grid re-render on category & search pages**: typing, applying/removing
  a facet, or sorting updates the **entire product-grid + layered-nav DOM in real
  time** (no full reload) by fetching results **and** refreshed facet counts from one
  OpenSearch aggregation request (the §3.1/§2L contract). The endpoint returns
  rendered HTML or JSON that the JS swaps into the grid/sidebar containers.
- **Cache/Varnish + SEO (tenet #6):** the first page load is a normal, Varnish-cached,
  crawlable HTML page (server-rendered from OpenSearch). Interactive updates go
  through a **separately-cacheable AJAX endpoint** (keyed on category/query + active
  filters + sort + page) so they never bust the surrounding Varnish object, and each
  filter state maps to a **canonical, deep-linkable, cacheable URL** (pushState) so
  bots and no-JS clients get the same result via a normal full-reload request.
- One faceting implementation shared by category and search pages; only the base
  query differs (category filter vs. fulltext).
- **Search quality**: explicit index mapping + analyzers (edge-ngram for autocomplete,
  keyword + normalizer for facets, language analyzer for text); synonyms (reuse native
  config), stopwords, fuzziness/typo-tolerance, field boosting, "did you mean". Store-scoped.

**Phase 3 — Write path / sync at the lowest layer (always in sync)**
- Resource-model `save()/delete()` plugin (or EntityManager) → upsert/**delete**
  the OS doc for every write source; qty (order decrement) + price/catalog-rule sync.
- Keep mview as a self-healing secondary; fix crontab (drop `ExistingCronJob`,
  schedule `Reindex`); make admin toggles functional or delete them.

**Phase 4 — Product types (ALL must work)**
Every type must index its type-specific data and hydrate a working shell product +
PDP + add-to-cart. Per type:
- **Simple** — baseline (works).
- **Virtual** — like simple, no shipping (43 in catalog).
- **Downloadable** — index/hydrate links + samples so the native downloadable blocks
  render (remove the interim block-removal); handle links_purchased_separately (1/0).
  Primary type here (1,317 products).
- **Configurable** — index children + super-attributes + **swatches** (option
  swatch_image/values); hydrate so options/gallery/price switching work.
- **Grouped** — index associated products + qty; hydrate the grouped table/add-to-cart.
- **Bundle** — index options/selections + dynamic price; hydrate bundle options.
- **Test-product creation (required):** this catalog has NO configurable / grouped /
  bundle products — create sample products of each missing type (fixtures or a setup
  script) so every type path is actually exercised and regression-tested. Add each
  type to the PDP-coverage survey + the query-profile gate (0 product SQL per type).

**Phase 5 — Harden, verify, release**
- Query-logger proof of zero MySQL on PDP/PLP/search; cache warmup; monitoring;
  acceptance tests; `composer.json` for `parkktech/fastmagento`; docs. Ship the
  PDP-speed milestone first (per the author's rollout: PDP → category → search),
  then category, then search.

## 5. GSD kickoff prompt

Paste into `/gsd:new-milestone` (or `/gsd:plan-phase` per phase):

```
Milestone: FastMagento — OpenSearch serving layer (production-ready)

Goal: Make OpenSearch the sole read/serving layer for products, category/PLP,
search and layered navigation in Magento 2.4.x, with MySQL as the source of truth
kept in sync on every write. Third-party/core code that reads or extends
\Magento\Catalog\Model\Product must keep working, unaware the data is from
OpenSearch. Base Magento only — no third-party module may be required. Everything
must work behind Magento FPC AND Varnish, and be fully multi-store / multi-website
scoped. If OpenSearch is down/stale, fall back to native MySQL — never harder-down
than base Magento.

Design constraints (must hold):
- The storefront product object is the REAL \Magento\Catalog\Model\Product
  (ShellNoEavProduct extends it), hydrated from OpenSearch — never a DTO stand-in.
- Override at the lowest layer for widest coverage with least code:
  * reads: preference on Product\Collection + CatalogSearch Fulltext\Collection
    (frontend) overriding _loadEntities()/getFacetedData()/aggregations; aroundLoad/
    resource load for single products.
  * writes: plugin on Catalog\Model\ResourceModel\Product::save()/delete()
    (or EntityManager) so admin, REST/GraphQL, import, mass action, and order
    stock decrement all sync — including deletes.
- Layered navigation is the highest-risk surface (biggest DB bottleneck): needs an
  explicit attribute-layer index (facet fields = option_id+label+swatch+position,
  price per customer-group/website/currency w/ catalog rules, category facet) and
  accurate aggregation counts fed back through the stock catalog.leftnav. May
  require curating attributes/options/groups in the DB. See §3.1.
- Cache/Varnish: OpenSearch queried only on cache-miss/build; filtered PLP URLs
  cacheable + correctly keyed/tagged; live faceting + search autocomplete served via
  separately-cacheable AJAX/ESI endpoints so they never bust the surrounding object.
- Multi-store: index per store view (or store-scoped _id) + store-filtered reads;
  price per website/customer-group/currency; MSI salable-qty stock; indexed URLs.
- Resilience: circuit-breaker fallback to native on OS down; zero-downtime reindex
  via alias swap; drift reconciliation; validate price/stock vs DB at checkout.
- Search quality: explicit mapping/analyzers (edge-ngram autocomplete, keyword
  facets), synonyms (native), fuzziness, boosting, "did you mean".
- Admin config (§3.3): reuse native attribute + catalog-search config; add a
  per-attribute facet grid + autocomplete result/field settings; website/store scoped.
- Delete superseded code (dead shell classes, per-block AJAX approach, disabled
  observers, broken cron) as lower overrides replace them.

Phases: (0) stabilize+consolidate+green di:compile; (1) complete product object
(multi-store, MSI stock, per-scope price, indexed URL) + 3rd-party-compat tests;
(2) read path (PLP/category/search result sets) via collection preference;
(2L) layered navigation attribute-layer + accurate facets; (2S) instant search UX —
rich autocomplete + live full-grid/facet re-render + search quality; (3) write/delete
sync via resource-model plugin; (3R) resilience — OS-down fallback, zero-downtime
alias reindex, drift reconciliation, checkout integrity; (4) product types incl.
downloadable; (5) admin configuration (facet grid + autocomplete settings, reuse
native config); (6) harden+verify(zero product SQL, Varnish-safe)+observability+
security+benchmarks+package+release.

Test site: local diyoffroad (prod DB clone, OpenSearch on :9200, developer mode;
fastmagento_product index already builds 1383 docs on base Magento). Verify with DB
query logger — target zero product SQL on PDP/PLP/search, and confirm pages stay
Varnish/FPC cache hits.
Full current-state findings and target design: app/code/ParkkTech/FastMagento/docs/OPENSEARCH-SERVING-LAYER-PLAN.md
```

## 6. Already applied on this branch (`feature/fastmagento-opensearch-layer`)
- **Removed the hard Amasty/3rd-party requirement in the indexer** — `getSource()`
  is now resolved through a `safeGetSource()` guard (missing/broken source models
  are skipped), and `execute()` wraps each product in try/catch so one bad product
  can't abort a full reindex. Works on base Magento regardless of leftover attribute
  source_models. (`Model/Indexer/ProductIndexer.php`)
