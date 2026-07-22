# FastMagento — Architecture & How It Works (developer reference)

The canonical "how it works" map for the module, verified against the code. Read this before
changing serving behaviour. User-facing docs live in `README.md`; this is the developer-level
detail (interception points, file responsibilities, gotchas, dormant code).

Design contract: **OpenSearch is the primary serving layer; MySQL stays the source of truth.**
Storefront reads resolve from an OpenSearch document instead of EAV, via a real
`Magento\Catalog\Model\Product`/`Category` subclass, so third-party blocks/plugins/SEO keep
working. Everything degrades to native EAV on a miss or an OpenSearch outage. Runs on base
Magento; FPC/Varnish safe.

---

## 1. The two serving indexes

| Indexer id | Index (`OpenSearchConfig`) | Class |
|---|---|---|
| `fastmagento_product` | `magento2_products` (`getIndexName`) | `Model/Indexer/ProductIndexer.php` |
| `fastmagento_category` | `magento2_categories` (`getCategoryIndexName`) | `Model/Indexer/CategoryIndexer.php` |

Both project the **default store view** into one global doc per entity, track incremental change
through **mview** (`etc/mview.xml`, `etc/indexer.xml`), and stream to OpenSearch in **NDJSON bulk
chunks of `FLUSH_SIZE = 200`** for flat memory at scale.

### Product doc (`ProductIndexer::prepareDoc`)
- **Custom/dynamic attributes** — `getAttributeValues()` iterates `$product->getAttributes()`,
  skips ~60 hardcoded native attrs (`getDefaultMagentoAttributes()`), writes the rest under a
  nested `attributes` object. Select/multiselect option ids are resolved to **labels** via
  `resolveOptionLabel()` using a per-run `optionLabelCache` (one `getAllOptions()` per attribute,
  not a `getOptionText()` DB hit per option per product). Mapping = `keyword` per attribute
  (`getDynamicAttributeMapping()`).
- **Prices** — `price` (regular), `special_price` (float), rule-neutral `final_price`
  (`getBaseFinalPrice()` — deliberately does NOT call `getFinalPrice()`, which would fire
  `catalog_product_get_final_price` → the per-child rule N+1 and bake the *current shopper's* group
  rule into a shared doc), `tier_prices`, and the **per-customer-group catalog-rule price map**
  `catalog_rule_prices` (`{group_id => price}`) from `getRulePriceMapByGroup()` (ONE query on
  `catalogrule_product_price`), plus a legacy scalar `catalog_rule_price` (group 0).
- **Stock** — `is_in_stock`, `stock_data.qty`, full `extension_attributes.stock_item`.
- **Composite children** (`getChildProducts()`) — configurable/grouped/bundle members loaded in
  ONE set-based collection, with **batched** stock (`getStockMap()`, one
  `cataloginventory_stock_item` query), per-group rule prices, and tier prices
  (`getChildTierPrices()`). Each child carries its own price/final/special/stock/rule map/tier +
  `custom_attributes` (raw super-attribute option ids).
- **Configurable jsonConfig** — `configurable_options_<id>` (full super-attribute data incl.
  `product_attribute`) + `swatch_options` (`getSwatchOptions()`, one joined query →
  `[attr_id => [option_id => {type,value,label}]]`).
- **Category linkage** — `category_ids`, `category_names`, `extension_attributes.category_links`.
- **URL** — canonical `request_path` (`getProductRequestPath()`, one `url_rewrite` query) +
  `url_key`/`url_path`.
- **Other** — `parent_ids` (for simples), downloadable links/samples, `website_ids`, `store_id(s)`.
- Runtime `_cache_instance_*` product-type caches are stripped before indexing.

### Category doc (`CategoryIndexer::buildDoc`)
Whole tree read in ONE collection load with attributes pre-selected (`name, is_active,
include_in_menu, is_anchor, display_mode, url_key, url_path, all_children`) + ONE `url_rewrite`
query (`loadRequestPaths()`). Doc carries tree structure (`parent_id, path, path_ids, level,
position, children_count`), menu flags, url fields, `request_path`.

---

## 2. Read path — interception map

Wired in `etc/frontend/di.xml` (frontend) and `etc/di.xml` (global, so `webapi_rest`/`graphql`
checkout is covered; adminhtml + cron stay native). Each entry lists the native SQL it removes.

| Interceptor | Target (core class::method) | Serves from OS | Native SQL removed |
|---|---|---|---|
| `Plugin/FrontendProductPlugin` `aroundLoad` | `Catalog\Model\Product::load` | PDP/cart single product → `ShellNoEavProduct` | full per-product EAV multi-table load |
| `Plugin/ProductRepositoryPlugin` `aroundGetById` | `ProductRepositoryInterface::getById` | repository/REST/GraphQL getById | EAV product load (also drives warm-on-miss) |
| `Plugin/FrontendProductCollectionPlugin` `aroundLoad` | `Product\CollectionFactory` | PLP/search collection item hydration → shells | `catalog_product_entity_*` EAV for list items |
| `Plugin/LinkProductCollectionPlugin` `aroundLoad` | `Product\Link\Product\Collection` | related/up-sell/cross-sell | per-item EAV + per-card `url_rewrite` N+1 |
| `Plugin/CategoryAttributeLoadPlugin` `around_loadAttributes` | `Category\Collection` | menu/nav/breadcrumb/layered-nav category attrs | `catalog_category_entity_{varchar,int,text}` UNION → 0 |
| `Plugin/CategoryUrlFinderPlugin` `aroundFindOneByData` | `UrlRewrite\Model\UrlFinderInterface` | category URLs (batched) | per-category `url_rewrite` N+1 (~108 → 1/store) |
| `Model/Configurable/ConfigurableProductType` (pref) + `Plugin/GroupedParentIdPlugin` | `getParentIdsByChild` | child→parent ids from indexed `parent_ids` | `catalog_product_super_link`/`_link` parent N+1 |
| `Pricing/Price/TierPrice` / `CatalogRulePrice` / `SpecialPrice` (prefs) | price models `getValue` | tier/rule/special from doc | `catalog_product_entity_tier_price`, `catalogrule_product_price` |
| `Plugin/LowestPriceOptionsProviderPlugin` `aroundGetProducts` | `ConfigurableProduct\Pricing\Price\LowestPriceOptionsProvider` | configurable "from" price from child shells | native used-product collection + per-child rule/tier N+1 (~660) |
| `Model/Product/Type/Configurable` (pref) | configurable type model | options/used-products/salability from registry shells | used-product collection loads |
| `Model/OpenSearchStockRegistry` (pref) | `StockRegistryInterface` | stock item/status from shell | `cataloginventory_stock_item` per-product; MSI sku preload |
| `Plugin/Inventory/CompositeParentStockStatusPlugin` `afterGetStockStatus` | `StockRegistryInterface` (sortOrder 100, after MSI) | composite parent salable if any OS child in stock | fixes stale-mview parent OOS (2nd-configurable add) |
| `Model/ResourceModel/Quote/Item/Collection` (pref, GLOBAL) | `Quote\Item\Collection::_assignProducts` | Fast Checkout quote-item hydration | native ~217-query cart collection |

Also disabled (native plugins turned off, `etc/frontend/di.xml`): core `used_products_cache` on
Configurable (would rebuild children as native products, reintroducing the ~660 N+1) and core
`catalogInventoryAfterLoad` on Product.

---

## 3. The shell read model

- **`Model/ShellProduct/ShellNoEavProduct.php`** — extends `Catalog\Model\Product` (custom deps
  appended nullable AFTER core's `$data`/`$config` to preserve positional instantiation).
  `load()`/`afterLoad()`/`_afterLoad()` are no-ops. `getData($key)` returns `doc[$key]` first
  (skipping `_cache_instance_*`) then `parent::getData()`; `getAttributeText()` serves indexed
  labels. Price/stock overrides: `getPrice` (regular, never rule), `getFinalPrice` (applies
  per-group rule), `getSpecialPrice`, `getTierPrices`, `getStockData`, `isSalable` (honours indexed
  `salable`), `getCategoryIds/Names`, `getChildProducts`, `getMediaGalleryImages`,
  `getProductUrl` (from `url_path`, no DB).
  - **Per-group price**: `getCurrentCustomerGroupId()` prefers the quote-stamped
    `customer_group_id` (correct for webapi/graphql checkout with no session) then session;
    `getCatalogRulePriceForGroup()` reads the `catalog_rule_prices` map (absent group = no rule,
    **no** group-0 fallback — a Retailer never inherits a guest discount).
- **`Helper/ShellProductBuilder.php`** `buildNoEavProductFromOsDoc()` — the main hydrator: rebuilds
  stock-item/category/configurable extension attributes; rebuilds the configurable attribute
  collection from `configurable_options_<id>` and `markLoaded()` (skips the DB collection load);
  seeds `tier_price` in native shape (`mapTierPricesToNative()`) so core `getStoredTierPrices()`
  short-circuits; recursively builds **child shells** into `child_products` + `child_products_<id>`
  registries (cart mode `hydrateChildren=false` skips the ~660 siblings); hydrates downloadable
  Link/Sample models; derives composite salability from children.
- **`Helper/OpenSearchPdpFetcher.php`** — `fetchPdpById()` (single `get`) / `fetchByIds()` (single
  `mget`); null/`[]` on miss (logged).
- **`Model/OpenSearch/CategoryDataProvider.php`** — pulls the whole tree in ONE `match_all` search
  on first access, `byId`/`childrenByParent` maps; `isAvailable()==false` → native fallback.
- **`Model/OpenSearch/ParentIdResolver.php`** — child→parent from indexed `parent_ids`, per-request
  cache. (`ShellProduct.php`/`ShellDataProduct.php` are legacy variants — the live path uses
  **ShellNoEavProduct** only.)

---

## 4. Write path — keeping the index live

- **Indexers** (mview) reproject changed entities.
- **`Model/OpenSearch/StockSyncer.php`** — MSI mutates stock through SKU-keyed tables a
  product-entity mview can't map, so `Observer/ReindexOnOrderPlace` (`sales_order_place_after`),
  `Observer/ReindexOnCreditmemo` (`sales_order_creditmemo_save_after`),
  `Observer/RemoveOnProductDelete` (`catalog_product_delete_after`), and
  `Plugin/Inventory/SourceItemsSyncPlugin` (`SourceItemsSave/DeleteInterface`) funnel affected
  SKUs/ids here. It dedupes, `filterStockChanged()` drops no-op saves, adds parents (direct
  `catalog_product_super_link`/`catalog_product_relation` lookup), and defers work to
  `register_shutdown_function` **after `fastcgi_finish_request()`** — the shopper never waits.
  - **Fast stock sync** (`OpenSearchConfig::isFastStockSyncEnabled`, implied on by Fast Checkout):
    `patchStockDocs()` does ONE `mget` + ONE `getLiveStock()` query and patches ONLY the stock
    fields (`is_in_stock`, `stock_data.qty`, `extension_attributes.stock_item`, each
    `child_products[].{is_in_stock,stock_qty}`); any miss/partial/failure → full reproject via
    `productIndexer->executeList()`. Never leaves the index stale.
- **`Model/OpenSearch/CatalogRuleSyncer.php`** — `Plugin/CatalogRule/RulePriceSyncPlugin` (GLOBAL,
  on `CatalogRule\Model\Indexer\IndexBuilder`) funnels `reindexById/ByIds/Full` here; it
  mget→patches ONLY the `catalog_rule_prices` fields (top-level + `child_products[]`), byte-identical
  query to the indexer. So a rule save / nightly apply-all keeps the OS-served cart correct with no
  manual reindex.
- **Warm-on-miss** — `FrontendProductPlugin`/`ProductRepositoryPlugin`: on a doc miss, load
  natively once, `ProductIndexer::indexProductObject()` (projects the already-loaded object, no
  reload), re-fetch, serve from OS. Next request is a pure OS read.

---

## 5. Pricing (per-group, N+1-free)

The indexer writes per-group `catalog_rule_prices` maps (parent + every child, batched). The
frontend price-model preferences (`TierPrice`/`CatalogRulePrice`/`SpecialPrice`) and
`LowestPriceOptionsProviderPlugin` serve from those maps/child shells instead of SQL, so a
660-variant configurable PDP/PLP/cart renders with **0 price SQL** instead of ~660
`catalogrule_product_price` + `catalog_product_entity_tier_price` queries. Cart pricing:
`Observer/ApplyCatalogRulePrice` (`sales_quote_collect_totals_before`), `Plugin/Quote/QuoteItemPlugin`
(`afterGetProduct`), `Plugin/Checkout/AddProductPlugin` (`beforeAddProduct`) all set the custom
price from the indexed per-group value, resolving the selected child for configurables.
`SpecialPrice` guards a shell's `special_price <= 0` → `false` (a 0 would win FinalPrice `min()` and
render $0.00).

---

## 6. Cart / Checkout / Fast Checkout

`Model/ResourceModel/Quote/Item/Collection.php` (GLOBAL preference) overrides ONLY
`_assignProducts()` — the native ~217-query `Product\Collection` build (product/EAV ≈119 + MSI
stock ≈71 + downloadable ≈27). Flow: gate on `isOsServeEnabled()` + servable area; pre-fetch
bail-outs off raw `quote_item` rows (bundle/grouped → native; configurable → native unless
optimistic; custom options → native); ONE `mget`; **hard fallback** — any id missing or not
`isDocServable()` sends the WHOLE collection to `parent::_assignProducts()` (byte-for-byte native).
Builds a `Product\Collection` of shells, marks it loaded via bound closure (so `getItemById` runs
no SQL), wires each configurable parent to only the in-cart child, dispatches
`prepare_catalog_product_collection_prices` (catalog-rule + 3rd-party observers still fire), runs
core's exact per-item `setProduct`/`checkData` loop.

**Flags** (`etc/config.xml` `<fastmagento><cart>`): `enable_fast_checkout` (master, **default 1/ON**)
implies all three advanced flags (each default 0, each can force-on when the master is off — the OR
is why they MUST default 0, else a master-off would leak past):
- `os_serve_quote_items` → `isOsServeEnabled()`.
- `optimistic_stock` → `isOptimisticStockEnabled()` (skips the redundant per-load MSI stock preload;
  backed by `Plugin/Inventory/QuantityValidatorSkipPlugin` + `OptimisticObserverSkipPlugin` which
  suspend the ~18-query/product per-qty-set MSI revalidation).
- `fast_stock_sync` → `isFastStockSyncEnabled()` (§4).

**Cannot oversell**: there is NO override of the placement gate. MSI's `CheckItemsQuantity`
(`AppendReservations::reserve`) remains authoritative and re-derives salable qty by SKU at
placement, throwing if short. Worst case of a stale index = a rare graceful rejection at the final
step; `ReindexOnOrderPlace` then reprojects immediately.

---

## 7. Search (relevance + realtime frontend + AI)

Two indexes are involved: relevance + facets run against **Magento's native fulltext index**
(`<prefix>_product_<storeId>`); display fields hydrate from FastMagento's `magento2_products` via
one `mget`. `Model/Search/InstantSearch.php` is the query service.

- **Realtime frontend** (the only no-reload surfaces): the header autocomplete dropdown
  (`web/js/autocomplete.js` → `Controller/Search/Suggest`) and the **live search results page**
  (`web/js/instant-search.js` → `Controller/Search/Instant`) — grid, pagination AND
  layered-navigation facets re-render in place from OpenSearch aggregations; URL synced via
  `history.replaceState`. `layout/catalogsearch_result_index.xml` removes native leftnav +
  `search.result` and injects the live app. Facet option labels come from the index
  (`bucketLabel()` trusts only unambiguous single-value hits). **Note: the category/PLP page grid is
  NOT this — it renders natively today (see §9 roadmap).**
- **Relevance** (`buildQuery`): candidate set = cleaned query + synonym variants scored
  **identically** (so `frontend` ≡ `front end`), each with bool_prefix (as-you-type) + best_fields +
  phrase boost + all-terms cross_fields; operator any/most/all; typo tolerance; in-stock/custom
  ranking. `RelevanceConfig` reads the admin knobs.
- **AI keyword layer** — `fm_search_keywords` (hidden, searchable, `is_searchable=1`, weight 8;
  `Setup/Patch/Data/AddSearchKeywordsAttribute`), populated off-request by
  `Model/Ai/SearchKeywordGenerator` via CLI `fastmagento:search-keywords:generate` (batched,
  resumable). Folded into the boosted fields when `search_keywords_enabled`.
- **Thesaurus** — `Model/Search/SynonymImporter` (single owner of `fastmagento/search/synonyms`),
  `Setup/Patch/Data/ImportStarterThesaurus` (bundled `etc/thesaurus/starter-synonyms.json` imported
  on install, merge-safe), `Model/Ai/ThesaurusGenerator` (scrapes attribute labels + categories +
  product names, discovers compound/grammatical variants, guard-railed against common-word groups).
- **Golden-query harness** — `docs/tools/search-relevance.php` + `golden-queries.json` runs golden
  queries through the real `buildQuery` (via `InstantSearch::debugSearch`) and prints scored
  pass/fail. See `docs/SEARCH-KEYWORDS-SPEC.md` (implemented) for the original spec.

---

## 8. Admin: paginated attribute options

Fixes Magento's whole-collection "Manage Options" grid (crashes at thousands of options). Files:
- `Model/AttributeOption/OptionRepository.php` — direct-SQL reader/writer: `getPage()` (one bounded
  SELECT + label search), `save()` / `delete()` touch only one option's rows
  (`eav_attribute_option` incl. the 3rd-party `group_id`, per-store `eav_attribute_option_value`,
  `eav_attribute_option_swatch`). Handles select / multiselect / visual+text swatch.
- `Controller/Adminhtml/AttributeOption/{Grid,Save,Delete}` — AJAX endpoints (`fastmagento/attributeOption/*`,
  ACL `Magento_Catalog::attributes_attributes`).
- `Block/Adminhtml/AttributeOption/Manager` + `view/adminhtml/templates/attribute-option/manager.phtml`
  + `view/adminhtml/web/js/attribute-option-manager.js` — the paginated grid UI (renders only for
  enabled option-bearing attributes; swatch column only for swatch types).
- `view/adminhtml/layout/catalog_product_attribute_edit.xml` — removes the native `main.advanced` /
  `main.swatches_visual` / `main.swatches_text` blocks (so their whole-collection load never runs)
  and injects the manager.
- `Plugin/Adminhtml/AttributeSaveOptionGuardPlugin` (`etc/adminhtml/di.xml`) — strips the monolithic
  option payload from the attribute Save request when pagination is on, so options are only ever
  managed per-row via AJAX (belt-and-suspenders; the UI posts no option fields anyway).
- Config: `fastmagento/attribute_pagination/enabled` (default 1), `page_size` (default 50).
- Known limitation: SYSTEM attributes whose options come from a PHP source model (not
  `eav_attribute_option`) show an empty grid (rare — not the target use case).

## 9. Config reference (paths)

`fastmagento/indexing/*` (index prefixes, realtime/cron indexing); `fastmagento/search/*`
(searchable_attributes, typo_tolerance, boost_in_stock, custom_ranking_*, search_operator,
phrase_match_boost, synonyms, stopwords, facet_attributes, search_keywords_enabled,
search_keywords_weight, keyword_source_attributes); `fastmagento/ai/*` (encrypted claude_api_key,
claude_model, max_terms); `fastmagento/cart/*` (enable_fast_checkout + advanced flags,
configurable_line_name). Admin UI: `etc/adminhtml/system.xml`, defaults `etc/config.xml`. CLI
registered in `etc/di.xml`. Crons: `etc/crontab.xml` (reindex, cache warmup).

---

## 10. Dormant code (do NOT mistake for live) & roadmap

**Not wired / disabled** — ignore when reasoning about live behaviour:
- `Plugin/ShellProductPlugin` (`disabled="true"`, superseded by `FrontendProductPlugin`).
- `Plugin/CacheWarmupPlugin`, `Observer/UpdateIndex`, `Observer/ProductSave` — no wiring.
- `Model/Search/ProductSearch` + `Block/Product/ListProduct` + `view/frontend/templates/{category,product}/list.phtml` — the **OS category/PLP listing path**: disabled (`catalog_category_view.xml` is a `<body/>` no-op) because it injects a raw `ClientInterface` (no DI preference → "Cannot instantiate interface"), the block/template contract is incomplete, and it targets an outdated ES7 index prefix.
- `Controller/Product/View` + `view/frontend/templates/product/view.phtml` — the PDP controller
  preference is commented out; product loading is intercepted by `FrontendProductPlugin` instead.

**Roadmap:**
- **PLP / category grid from OpenSearch (server-side, SEO-safe) — NEXT.** Today the category page
  renders natively; per-item product *data* is already OS-served (via the collection plugin), but
  category→product **membership**, sort/pagination, and **layered-nav filtering** are still native
  MySQL. The approved approach is to serve the category product collection (membership + facets)
  from OpenSearch **server-side**, preserving Magento's native render / canonical URLs /
  crawlability, NOT by cloning the client-side search app (category pages are SEO landing pages).
- Grouped/bundle add-to-cart hardening; per-store serving (docs project default store view);
  multi-select facet labels (needs an indexed option dictionary); zero-downtime alias reindex.

---

## 11. Ops quick reference

Reindex: `bin/magento indexer:reindex fastmagento_product fastmagento_category`
(+ `catalogsearch_fulltext` after AI keyword runs). Production mode needs a DI compile for new
classes — use `bin/magento-compile-safe` (segfault-safe settings; see
`../../../../MAGENTO_DI_COMPILATION_FIX.md`). Verify serving: `docs/tools/query-profile.sh`.
Measure relevance: `docs/tools/search-relevance.php`.
