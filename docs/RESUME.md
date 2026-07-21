# FastMagento — RESUME HERE (fresh-session pickup)

Read this first, then `docs/OPENSEARCH-SERVING-LAYER-PLAN.md` (roadmap + GSD prompt)
and `docs/BLOCKERS.md` (decisions/queue). `git log --oneline` tells the story.

## Environment (local dev)
- Branch `feature/fastmagento-opensearch-layer` (FastMagento is a git-subtree of
  parkktech/FastMagento @ osman-fast-magento; can subtree-push back).
- Site: `http://www.diyoffroad.loc/` (WSL2 → Windows hosts `127.0.0.1`), **developer
  mode**, prod DB clone `diyprod_db` (MySQL 8), OpenSearch on :9200.
- FastMagento index: `magento2_products` (1383 docs). Rebuild:
  `bin/magento indexer:reindex fastmagento_product`.
- DB query gate: `bash docs/tools/query-profile.sh enable|<path>|disable`.

## Working now (verified 200)
home, PDP (served from OpenSearch — 0 product-data SQL, 30/30 sample), search, cart,
category (native). Product images synced+resized. di:compile GREEN.

## Indexer performance (the EAV bottleneck — scale work, task #13)
Measured: old `ProductIndexer::execute()` fired **442 SQL queries/product** (4422 for
10) via row-at-a-time full-model loads — ~3 full loads/product (redundant
`factory->load()` + a `getById()` reload per store view), plus per-product
`getOptionText()` (417 option queries/10 prod), media-gallery, MSI and a **1690-query
`marketplace_userdata` 3rd-party tax (38% of all queries)**; docs accumulated in one
array and bulk-flushed once at the very end (index empty for the whole run; OOM at
scale); `executeFull()` delete+recreate = downtime.
**Phase A done (shape-preserving, verified vs baseline docs):** single load/product +
per-run option-label cache + streamed FLUSH_SIZE(200) bulk. → **86 q/product (5.2×),
0.068s/product (2.8×)**, identical doc keys (unset selects now `['']` not `[false]`).
**Phase B (todo for 10M):** fully set-based per-chunk extraction (batch maps for
stock/tier/rule/parent/category) to kill the remaining per-product model load, optional
module-owned covering indexes / projection table, alias-swap build. See task backlog.

## Configurable products (swatch stress test — in progress)
- `docs/tools/create-configurable-bras.php [n] [colors] [sizes]` generates configurable
  "bra" products: `color` = visual (hex) swatch, `size` = text swatch (band×cup, e.g.
  34DD..46K), one child simple per color×size with own SKU/price(+$3/cup)/stock/image.
  swatch_input_type lives in `catalog_eav_attribute.additional_data`; swatch values in
  `eav_attribute_option_swatch` (type 1=visual color, 0=textual).
- **DB side verified correct**: HER-KEIRA-001 (id 2005) = configurable, 2 super-attrs
  (color 3 opts, size 4 opts), 12 linked children, indexed into OS doc with
  child_products[] + configurable_options_<id>.
- **Fixed:** `ShellNoEavProduct` constructor TypeError — custom deps ($urlFinder,
  $categoryCollectionFactory, $scopeConfig) were BEFORE `array $data=[]`, breaking
  positional instantiation on the configurable child-hydration path; moved them AFTER
  core's $data/$config/$filterCustomAttribute (nullable + OM fallback).
- **STILL TODO (OS-served read path, never exercised for configurables):**
  configurable PDP renders 200 but layered swatch options + `jsonConfig`/`optionPrices`
  are EMPTY when served from the shell (native path builds them fine). Also
  `ProductRepository::get()` cache path hits null-SKU via the shell. Need: hydrate the
  configurable jsonConfig/swatch/child-price data from the OS doc into ShellNoEavProduct
  (or delegate correctly), fix shell getSku(), then scale fixtures to the real
  ~19-color × ~77-size matrix and batch child-loading in the indexer (Phase B — current
  getChildProducts() does a full ->load() per child = N+1 death for 600-child bras).

## Test bed in place
19 filterable attributes (all input types), 11 attribute sets with distinct
compositions, values on all 1383 products, indexed at product level (OS doc) and
native `catalog_product_index_eav`. Setup scripts: `docs/tools/create-fitment-attrs.php`,
`create-alltype-attrs.php`, `assign-fitment-values.php`, `assign-alltype-values.php`,
`create-attribute-sets.php`, `create-attribute-sets-2.php`.

## NEXT — in priority order
1. ✅ **DONE — Multiselect → native EAV facet index.** Root cause: the test-bed
   attrs were created with `backend_type='varchar'`; Magento's core EAV Source
   indexer only indexes multiselect where `backend_type='text'` (reads
   `catalog_product_entity_text`), so they yielded 0 rows in
   `catalog_product_index_eav`. Fixed: flipped both attrs to `text`, migrated values
   varchar→text (`docs/tools/fix-multiselect-backend-type.sql`), corrected
   `create-alltype-attrs.php`. compatible_platforms=5444 rows/1368 products/6 opts,
   included_formats=5436/1368/5. Both facets now render + filter on category AND
   search layered nav (verified counts category-scoped: 100→30 for Jeep).
2. **Downloadable proper hydration** (95% of catalog): index links/samples, hydrate
   native downloadable blocks, remove the interim block-removal in catalog_product_view.xml.
3. **All product types + test products**: no configurable/grouped/bundle exist —
   create samples (configurable needs a super-attribute like `size`) and support each.
4. **Category from OpenSearch (Phase 2L)** — LARGELY DONE. Category landing page now
   fires **0 category/EAV SQL** (fully served); search page **342 → 118**.
   - ✅ `CategoryIndexer` (`fastmagento_category`) → `magento2_categories` (218 docs:
     tree structure, menu flags, url paths, all_children). mview subs on
     catalog_category_entity*. `CategoryDataProvider` loads the whole tree from OS once
     per request (by-id / children / request_path), native fallback if OS down.
   - ✅ `CategoryUrlFinderPlugin` — batches category url_rewrite N+1 (112→5).
   - ✅ `CategoryAttributeLoadPlugin` — serves category collection attribute values
     (name/url_key/is_active/include_in_menu/is_anchor/all_children) from OS, skipping
     the catalog_category_entity_* UNION loads. Covers mega-menu, top-nav, breadcrumb
     parents, layered-nav children. Render diffs verified IDENTICAL plugin on/off.
   - **DEFERRED (deliberate):** the residual search-page category SQL is ~5 native
     collection *main* queries (filter joins — cheap indexed lookups, cold-cache only for
     the FPC-cached menu) + one single-category `CategoryRepository::get()`/`Category::load`
     from the layered-nav DataProvider. Serving that last model-load from OS needs a
     COMPLETE category doc (all attrs, dynamic) + a Category shell/aroundLoad mirroring the
     product path — high risk to category-page render (display_mode/custom layout) for
     ~1-3 cold-cache queries, since the category page is already fully served. Revisit only
     if a full category-object serve is wanted for other reasons.
5. **Layered nav / instant-search UX / write-path sync + delete / resilience
   (OS-down fallback, zero-downtime alias reindex, reconciliation) / admin config /
   harden** — see plan §4. Not started.

## Configurable read-path — SWATCHES RENDER (item 3, Stage 1-2 DONE)
Configurable PDP (`/keira-banded-underwire-bra-1.html`, id 4369) now renders the full
swatch UI from OpenSearch: jsonConfig (color 15 opts / size 44 opts / 660 optionPrices)
+ jsonSwatchConfig + client-side swatch-renderer, box-tocart, **16 total SQL (0 product/
EAV)**. Fixes (all committed):
- `ShellProductBuilder::hydrateChildFromCustomAttributes()` — child shells map
  custom_attributes (raw color/size option ids), status label→numeric, type_id, top-level
  stock→salable, so getAllowProducts() sees enabled children with option values.
- Composite parent salability derived from children (salable if any child in stock);
  shadowing salable/is_in_stock keys stripped from the OS doc.
- `ShellNoEavProduct::isSalable()` honours the OS 'salable' flag (was reading
  doc['is_in_stock'] = false for composite parents); `Configurable::isSalable()` override
  trusts the flag instead of getLinkedProductCollection() (product SQL / 0 for a shell).
- **Data/ops:** had to rebuild cataloginventory_stock + catalog_product_price (14k
  backlog) so MSI reports children salable (getUsedProducts' MSI after-plugin filters by
  real stock). New configurable/grouped/bundle test products need these indexes current.
- Test tool: `docs/tools/create-downloadable-test.php` (downloadable); configurable test
  bed = HER-* bras (id 4369+).
✅ Out-of-stock child greying CONFIRMED (user-verified on PDP): an OOS child is dropped
from getAllowProducts (659/660) but all 15 colors / 44 sizes still render; the swatch
renderer greys the specific unavailable combo (show_out_of_stock=0 default).

STILL TODO (Stage 3): **configurable add-to-cart via the shell fails** — addProduct returns
"You need to choose options" because `Configurable::getProductByAttributes([93=>86,189=>89])`
returns NULL for the shell (its getUsedProductCollection returns all 660, but the
attribute-id→child match yields nothing). Native super_attribute matching path needs an
override/fix for the OS-hydrated shell (mirror the getUsedProducts registry approach, or
override getProductByAttributes to match against the OS child docs). Until then configurables
can't be ordered. Simple/virtual/downloadable add + order fine. Also: grouped/bundle add,
price/image switching visual check.

## Real-time stock sync — DONE
`Model/OpenSearch/StockSyncer` + observers (sales_order_place_after,
sales_order_creditmemo_save_after) + plugins (SourceItemsSave/DeleteInterface) reproject
affected products + their configurable/grouped/bundle parents into OpenSearch immediately,
so qty / in-stock stay live on orders, returns and inventory-API writes (admin grid,
imports, ERP, bin/magento inventory:*). Verified: inventory-API qty change hits the OS doc
with no manual reindex; a child sync reprojects its parent's child_products stock. The
stock WRITE path itself is native Magento (StockManagement) — the sync just keeps OS current.

## Configurable read-path — original DIAGNOSIS (resolved above)
Configurable PDP (e.g. `/keira-banded-underwire-bra-1.html`, id 4369) renders 200 but shows
**"unavailable"** — no swatches, no jsonConfig/spConfig, no price. The OS doc is COMPLETE:
`configurable_options_4369` (2 super-attrs), `swatch_options` (attr 93/189),
`child_products` (660 children with price/stock/images). The gap is the READ path:
- `ProductIndexer::getChildProducts()` emits each child as {entity_id, sku, price,
  final_price, special_price, is_in_stock, stock_qty, image(s), custom_attributes, store_*}.
  The super-attribute values (`color=86`, `size=89`), `type_id=simple`, `status="Enabled"`
  (LABEL not 1), `visibility` all live INSIDE `custom_attributes`.
- `ShellProductBuilder::buildNoEavProductFromOsDoc()` (used recursively per child) reads
  `status`/`type_id`/`visibility` TOP-LEVEL, stock from `extension_attributes.stock_item`,
  and attrs from an `attributes` key — NONE of which match the child shape. So child shells
  get no type_id, status not enabled, stock not wired → not salable, and their color/size
  option values are never set as product data.
- Result: `Configurable::getUsedProducts()` (reads registry `child_products`) returns shells
  that `getAllowProducts()` filters out as non-salable → block renders nothing → "unavailable".
**To finish:** (a) hydrate child shells correctly — map `custom_attributes` (incl super-attr
option ids), resolve status label→1, wire top-level is_in_stock/stock_qty to salable, set
type_id=simple; (b) verify `Swatches\Block\...\Configurable::getJsonConfig()` builds spConfig
(optionPrices per child, swatch data from `swatch_options`, image switching); (c) fix shell
`getSku()` null-SKU on the ProductRepository cache path; (d) add-to-cart with selected options;
(e) confirm 0 product SQL. Biggest + most intricate of the remaining items.

## Known interim shortcuts to revisit
- Category listing served natively (broken PLP block override disabled).
- ProductRepository get()/getList() fall back to native (not OS-served yet).
- Product URL built from url_key+suffix (robust = index real request_path per store).
- Reindex drops the index (no zero-downtime alias yet) → transient PDP 500s during reindex.
