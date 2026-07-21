# QA: where product info comes from — OpenSearch vs MySQL (2026-07-21)

End-to-end audit of every product-display surface: is product info (name, price, image,
stock, attributes, options, URL) served from OpenSearch (the OS shell product) or loaded
natively from MySQL/EAV? Cross-checked two ways — **empirically** with the DB query
profiler on **cold (FPC-disabled) renders**, and by **code analysis** of the read path.

> ⚠️ The prior "PDP = 16 queries, 0 product SQL" milestone was measured **FPC-warm** (a
> full-page-cache HIT, which renders no product blocks). This audit disabled FPC to measure
> the **real render path**. In production FPC still masks cacheable pages (PDP/PLP) after the
> first render — but cache-miss renders and all **uncacheable** surfaces (cart, mini-cart,
> checkout, add-to-cart) pay the native cost every time.

## Verdict

**Only search and the single-product PDP *object* are OS-served. Everything that loads
products in BULK via a collection — PLP, related/up-sell/cross-sell, and cart/checkout line
items — bypasses the OS path and loads natively from MySQL/EAV.**

Root cause: OS serving works **only** through the per-id load choke point
(`FrontendProductPlugin::aroundLoad` on `Catalog\Model\Product`, and
`ProductRepositoryPlugin::aroundGetById`). Collection loads never call those per item, and
the collection interceptor that was meant to cover them —
`Plugin/FrontendProductCollectionPlugin` — is **inert**: it's attached to a
`CollectionFactory` but hooks `aroundLoad`, and a factory only exposes `create()`, so it
never fires (and its body returns a single shell, wrong shape for a collection).

## Measured (cold render, FPC off, config/EAV cache warm)

| Surface | Total SQL | Product source | Notable native tables |
|---|---:|---|---|
| Search results / instant | ~54 | **OpenSearch** ✅ | none (only url_rewrite ×2) |
| PDP simple | ~554 | OS object; secondary leaks | `catalog_product_entity` ×28, `eav_attribute_option_value` ×26 (attr labels), `catalog_product_link` ×5 (related/upsell), url_rewrite, stock |
| PDP configurable | ~3,136 | OS object; **pricing N+1** | **`catalog_product_entity_tier_price` ×661 + `catalogrule_product_price` ×660** (one pair per child) |
| PLP / category listing | ~256 | **Native MySQL/EAV** ❌ | `catalog_product_entity` ×30, `catalogrule_product_price` ×24, `catalog_product_index_price` |
| Cart page | ~1,780 | **Native MySQL/EAV** ❌ | full EAV load of line-item product (`catalog_product_entity_{varchar,int,decimal,datetime}`), option labels |
| Checkout | ~3,062 | **Native MySQL/EAV** ❌ | `catalog_product_entity` ×79, `catalog_product_option_{title,price}` ×10, option labels ×30 |

## Findings (by priority)

### P1 — Cart / mini-cart / checkout deliver product info from MySQL, not OS
Core `Quote\Item\Collection::_assignProducts()` bulk-loads quote-item products via the native
`Product\Collection` (full EAV). The module's `QuoteItemPlugin::afterGetProduct` and the
`ApplyCatalogRulePrice` observer only patch **price** onto those items — name, image, URL,
options remain the native load. So the answer to "does cart/checkout use OS for product info
delivery" is **no** today. Fixing needs real collection interception (target the
`Collection` `load`/`_afterLoad`, or a correct `create()` around-plugin returning an
OS-backed collection).

### P1 — Related / Up-sell / Cross-sell are 100% native (no interception exists)
Grep of the whole module for related/upsell/crosssell → **nothing**. These blocks (on the PDP
and cart) build native link collections. No OS path exists at all.

### P1 — PLP / category listing is native (deliberately deferred, but inert plugin)
`catalog_category_view.xml` is emptied with a "Phase 2" comment; the native
`ListProduct` + native collection render everything. `FrontendProductCollectionPlugin` is a
dead no-op (see root cause). `Block/Product/ListProduct.php` calls a non-existent
`ProductSearch::searchByCategory()` and is wired to no layout.

### P2 — Configurable PDP child-pricing N+1 (~2,600 SQL on the 660-child bra)
The indexer's `getChildProducts()` emits child price/final/special but **not**
`catalog_rule_price` or `tier_prices`. So each child shell's `CatalogRulePrice::getValue()` /
`TierPrice` falls through to `parent::getValue()` → one `catalogrule_product_price` +
`catalog_product_entity_tier_price` query **per child**. Fix: index rule price + tier prices
into each `child_products[]` entry (mirror the parent), so the shell serves them.

### P2 — PDP secondary leaks even when the main object is OS
- **Attribute-label resolution:** `eav_attribute_option_value` ×26 — select/multiselect
  attribute *labels* resolved from DB for the "More Information"/attribute displays, though
  the labels are already in the OS doc (`attributes`/`swatch_options`).
- **Product URL:** falls to the `url_rewrite` DB lookup when the OS doc lacks
  `url_path`/`url_key` (`ShellNoEavProduct::getUrlRewrite()`).
- **Stock for non-current-product ids:** `OpenSearchStockRegistry` only serves OS when the id
  == `current_product`; otherwise native stock provider.

### P3 — Repository `get($sku)` and `getList()` are native passthroughs
`ProductRepositoryPlugin::aroundGet` / `aroundGetList` fall through to native
(`TODO: Optimize with OpenSearch`). Any SKU- or list-based access is native.

## Still remaining overall (beyond the above)
From RESUME.md NEXT + this audit, not yet done:
1. **Collection-based OS serving** (the umbrella fix for P1: PLP, related/upsell/crosssell,
   quote items).
2. Configurable child rule/tier price indexing (P2 N+1).
3. PDP attribute-label + URL + stock secondary leaks (P2).
4. Repository getList/get-by-SKU (P3).
5. Downloadable proper hydration (remove interim `catalog_product_view.xml` block-removal).
6. Grouped/bundle add-to-cart support.
7. Resilience: zero-downtime alias reindex, reconciliation job, OS-down fallback hardening.
8. Full admin config panel (search/AI groups done; broader coverage pending).

## Fix attempt log — P1 collection-serving has a hard prerequisite

Attempted the safest P1 slice first: serve Related/Up-sell/Cross-sell (the
`Product\Link\Product\Collection`) from OS shells via a scoped `around load` plugin
(`getAllIds` → mget → build shells, all-or-nothing). **Reverted** — it REGRESSED the PDP from
~554 to ~5,906 SQL: `url_rewrite` ×2,700 + `catalog_url_rewrite_product_category` ×2,697. The
OS shells carry no indexed URL, so each grid product's `getProductUrl()` fell into the dynamic
URL-rewrite storage (per product, per category context) — where the native `addUrlRewrite()`
batches it. The main-product PDP shell doesn't hit this (its URL is resolved once), but a grid
of shells multiplies it.

**Conclusion — corrected dependency order.** Collection-serving (related/upsell/crosssell,
PLP, and cart/checkout quote items) is NOT safe until the enabling prerequisites land first:
1. **Index `url_rewrite.request_path` per store into the product doc** and serve product URLs
   from it (no DB). This both fixes the PDP url_rewrite leak (P2) AND unblocks collection URLs.
2. **Index child rule/tier prices into `child_products[]`** and serve children as shells (fixes
   the configurable pricing N+1 and stops per-child native price SQL).
3. PLP additionally needs sort/pagination/layered-nav served from OS (the instant-search
   approach), not just item hydration.
Only after 1–3 should the collection `around load` interception be re-introduced. Doing it
before is why the original author deferred it.

### Update — `request_path` indexed; collection-serving has a SECOND blocker

**Landed (prerequisite #1):** the indexer now writes the canonical `request_path` per store into
each product doc (`ProductIndexer::getProductRequestPath()`), and `ShellProductBuilder` sets it on
the shell (`setData('request_path', ...)`). Requires a **full reindex** to populate all docs
(only the test set is populated so far). This is correct groundwork but delivers no standalone
SQL win yet — because:

**Second blocker found (re-attempt reverted):** re-introducing the link-collection plugin with
`request_path`-carrying shells produced the SAME url_rewrite cascade (~2,700 `url_rewrite` +
~2,680 `catalog_url_rewrite_product_category`) **and** a 500. Root cause: Magento's product-**list**
blocks generate **category-aware** product URLs per grid item via the URL finder
(`catalog_url_rewrite_product_category`), which ignores the shell's canonical `request_path`. The
native list batches these via `Collection::addUrlRewrite($categoryId)` (one query, sets a
`url_data_object` on each item). So serving link/list collections from OS shells also needs that
batch URL-rewrite load replicated onto the shells (set `url_data_object`, not just `request_path`),
and the 500 indicates the link collection expects more of its items (link attributes) than a bare
shell provides. **Net: collection-serving is a multi-layer feature (shell URLs → list batch URLs →
link/quote item shape), not an incremental patch.** Recommend a focused session, or building the
list batch-URL layer next before retrying.

## RESUME.md corrections found during this audit
- **Configurable add-to-cart is NOT broken** — `Model/Product/Type/Configurable::getProductByAttributes()`
  is implemented and matches OS `child_products` (RESUME "Stage 3 TODO" is stale).
- `AddProductPlugin::getSelectedChildProduct()` matches children by `attribute_<id>` but OS
  child shells key option values by attribute *code* — dead matching logic (harmless; core's
  OS override resolves the child, so add-to-cart still works).
