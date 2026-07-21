# FastMagento — OpenSearch Serving Layer for Magento 2

FastMagento makes **OpenSearch the primary serving layer** for the Magento 2 storefront.
Products, the category tree, PDP, search and layered navigation are hydrated from OpenSearch
instead of Magento's EAV/ORM, while **MySQL stays the source of truth**. Reads are
transparent to third-party code (a real `Magento\Catalog\Model\Product` / `Category` object
is hydrated from the OpenSearch document), it runs on **base Magento only**, and it is
**Full-Page-Cache / Varnish safe**.

The goal is to drive product/EAV SQL toward zero on the hot paths.

**🔗 See it in action:** [www.diyoffroad.com](https://www.diyoffroad.com/) — a live storefront
running this serving layer.

**Measured on a production-sized catalog:**

| Page | Before | After |
|---|---|---|
| Cold homepage | ~21,610 SQL | ~1,084 SQL |
| PDP (any type) | hundreds of EAV queries | **0 product/EAV SQL** |
| Category page | ~58+ category/EAV queries | **0 product/EAV SQL** |
| Search results | 342 SQL | 118 SQL |

---

## Benchmarks — with vs without the extension

Measured on this storefront by toggling `ParkkTech_FastMagento` on and off and replaying the
same requests. **Cold render** = Full-Page-Cache disabled — the real product-render path (an
FPC *hit* renders no product blocks, so it hides the cost). Environment: local dev, **~14,600
products**, **active catalog price rules across customer groups**, with Webkul Marketplace and
other third-party modules running on every page.

![Cold-render SQL queries — native Magento vs FastMagento](docs/img/benchmark-queries.svg)

### SQL queries per cold render

| Surface | Product type | Without (native) | With FastMagento | Reduction |
|---|---|---:|---:|---:|
| PDP | Simple | 822 | 540 | −34% |
| PDP | Downloadable | 784 | 512 | −35% |
| PDP | Configurable (660 variants) | 774 | 494 | −36% |
| PLP | Category listing | 452 | 224 | −50% |
| Search | Results page | 436 | **48** | **−89%** |
| Cart / Checkout | Configurable + simple | 524 | 252 | −52% |

### Cold render time (local dev)

| Surface | Without (native) | With FastMagento |
|---|---:|---:|
| PDP · Simple | 0.49 s | 0.47 s |
| PDP · Downloadable | 0.33 s | 0.42 s |
| PDP · Configurable | 0.68 s | 0.75 s |
| PLP · Category | 0.45 s | 0.44 s |
| Search results | 0.73 s | 0.66 s |
| Cart / Checkout | 1.16 s | 1.15 s |

> **How to read this.** The **query-count reduction is the scale-invariant metric**; the
> wall-clock column is close *only because this is local dev*, where MySQL answers each query in
> ~0.1 ms — so dropping a few hundred queries saves tens of milliseconds, swamped by PHP and the
> third-party modules on every page. The queries FastMagento removes are the **product / EAV /
> catalog-rule** reads whose cost grows with catalog size and DB round-trip latency. On a
> production store — millions of EAV rows, a networked/replicated database at 1–5 ms per query —
> the *same* reduction is **seconds** of latency and a large drop in DB load. The design goal is
> that **product/EAV SQL stays flat as the catalog grows**; the native path does not.

### N+1 query patterns eliminated

The patterns that make checkout and large-catalog pages fall over:

- **Configurable price resolution.** Native Magento prices a configurable by iterating *every*
  child (660 here) through the price model; on the serving-layer path this produced one
  `catalogrule_product_price` **and** one `catalog_product_entity_tier_price` query *per child*.
  Child prices (per customer group) are now served from the index → **0 rule/tier SQL** on the
  configurable PDP.
- **Cart / checkout reprojection.** Writing stock for a single configurable child reprojected
  the whole parent inline, calling `getRulePrice()` once per child (~660 queries) on the revenue
  path. The indexed base price is now rule-neutral (no per-child price observer) and reprojection
  is **deferred off the response** and skipped when stock is unchanged → **671 → 8** catalog-rule
  queries on a cart view, and the shopper never waits on the reindex.
- **Search.** Relevance + layered-nav facets served from the index → **436 → 48** SQL.

Catalog price rules are resolved **per customer group** (guest, Wholesale, Retailer, …) across
PDP, PLP, cart and checkout, served from a per-group map in the index rather than a per-child
SQL lookup.

---

## Features

- **Product serving** — PDP, PLP and search hydrate real product objects from OpenSearch
  with no EAV load: price, special/tier/catalog-rule prices, stock, media gallery,
  category names and custom attributes.
- **Category serving layer** — a dedicated `fastmagento_category` indexer
  (`magento2_categories`) powers the mega-menu, top navigation, breadcrumbs and layered
  navigation from OpenSearch, eliminating `catalog_category_entity*` reads. A batched URL
  finder also collapses the per-category `url_rewrite` N+1.
- **All product types**
  - Simple & Virtual — fully served.
  - **Downloadable** — links and samples are indexed and hydrated into the native
    downloadable blocks (title, price, per-link samples), rendered with 0 downloadable SQL.
  - **Configurable** — swatch `jsonConfig`, per-option prices and swatch config are served
    from OpenSearch; the PDP renders the full swatch UI. Out-of-stock option combinations
    still render (greyed), matching Magento's default behaviour.
- **Instant search + autocomplete** — an as-you-type header dropdown (product cards +
  category suggestions) and a search results page whose facets, product grid and pagination
  re-render live from OpenSearch with no page reload (Algolia-style). Endpoints:
  `/fastmagento/search/suggest` and `/fastmagento/search/instant`.
- **Real-time stock sync** — order placement, refunds/returns and MSI inventory-API writes
  reproject the affected products (and their configurable/grouped/bundle parents) into the
  index immediately, so quantity and in-stock status stay live.
- **Read-path resilience** — *warm-on-miss* (a product missing from the index is added on
  first access, self-healing like a cache miss) and automatic native fallback whenever
  OpenSearch is unavailable.
- **Cache/Varnish transparent** — serving happens beneath FPC and the layout cache.

---

## Requirements

- Magento 2.4.x (Open Source / Commerce), base install.
- OpenSearch 1.x/2.x (the engine already configured under
  `Stores → Configuration → Catalog → Catalog Search`).
- PHP matching your Magento version.

---

## Installation

**Composer (recommended)**
```bash
composer require parkktech/fastmagento
bin/magento module:enable ParkkTech_FastMagento
bin/magento setup:upgrade
bin/magento setup:di:compile      # production mode
bin/magento cache:flush
```

**Manual**
```bash
mkdir -p app/code/ParkkTech/FastMagento
cp -R <module-source>/* app/code/ParkkTech/FastMagento/
bin/magento module:enable ParkkTech_FastMagento
bin/magento setup:upgrade
bin/magento cache:flush
```

Then build the indexes:
```bash
bin/magento indexer:reindex fastmagento_product
bin/magento indexer:reindex fastmagento_category
```

---

## Architecture

### Serving layer

A frontend load of a product/category is intercepted and hydrated from OpenSearch into a
lightweight *shell* object that subclasses the real Magento model but never issues an EAV
load. Because it is a genuine `Product`/`Category`, third-party blocks and plugins keep
working unchanged. When the requested document is not in the index (mid-reindex, never
indexed), the read path loads it natively once, projects it into OpenSearch, and serves the
next request from the index.

### Indexers

| Indexer id | Index | Serves |
|---|---|---|
| `fastmagento_product` | `magento2_products` | product docs (PDP / PLP / search / cart) |
| `fastmagento_category` | `magento2_categories` | category tree, menu flags, url paths |

Both track incremental changes through `mview` (subscriptions on the respective EAV tables)
and stream documents to OpenSearch in bulk chunks for flat memory use at catalog scale.

### Real-time stock sync

MSI mutates stock through SKU-keyed tables that a product-entity mview cannot map, so stock
is additionally kept live by:

- `sales_order_place_after` observer — reservation / decrement on order.
- `sales_order_creditmemo_save_after` observer — back-to-stock on return/refund.
- `SourceItemsSaveInterface` / `SourceItemsDeleteInterface` plugins — admin grid, imports,
  ERP integrations and `bin/magento inventory:*`.

Each reprojects only the affected products (and their composite parents) and is best-effort
(logged, never surfaced) so a sync failure cannot break checkout, a refund or a stock save.
The stock *write* remains native Magento (`StockManagement`); the sync only keeps the index
current.

---

## Verifying it works

Confirm pages are served from OpenSearch with minimal SQL using the bundled profiler:
```bash
bash app/code/ParkkTech/FastMagento/docs/tools/query-profile.sh enable
bash app/code/ParkkTech/FastMagento/docs/tools/query-profile.sh /your-product.html
bash app/code/ParkkTech/FastMagento/docs/tools/query-profile.sh disable
```
A PDP/category page should report **0 product/EAV/catalog** queries.

### OpenSearch quick reference
```bash
# Count product docs
curl -s "http://localhost:9200/magento2_products/_count?pretty"

# Count category docs
curl -s "http://localhost:9200/magento2_categories/_count?pretty"

# Fetch a single doc by id
curl -s "http://localhost:9200/magento2_products/_doc/<entity_id>?pretty"

# View the mapping
curl -s "http://localhost:9200/magento2_products/_mapping?pretty"
```
(Index names use the store's `opensearch_index_prefix`; the defaults are shown.)

---

## Supported product types

| Type | Read (PDP/PLP/search) | Add to cart / order |
|---|---|---|
| Simple / Virtual | ✅ served from OpenSearch | ✅ |
| Downloadable | ✅ links + samples served | ✅ |
| Configurable | ✅ swatches + jsonConfig served | ✅ (option→child matched from OpenSearch) |
| Grouped / Bundle | ✅ indexed | ⚠️ not yet fully exercised |

---

## Known limitations / roadmap

- Grouped and bundle read paths are indexed but not yet fully exercised for add-to-cart.
- The serving index projects the **default store view**; per-store serving is tracked
  separately for multi-store setups.

---

## Test tooling

- `docs/tools/create-downloadable-test.php` — creates a downloadable product with multiple
  purchasable links and product-level samples to exercise the full downloadable render path.
- `docs/tools/query-profile.sh` — DB-query profiler used to verify OpenSearch serving.
