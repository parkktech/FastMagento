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

### Built for large, attribute-heavy, complex-configurable catalogs

This extension was designed and hardened against a catalog where native Magento hurts the most —
lots of products, lots of attributes, and configurables with **hundreds** of variants. Everything
in this README is measured on that reference catalog:

| Dimension | This catalog |
|---|---:|
| Total products | **14,604** |
| — simple / downloadable / virtual / configurable | 13,223 / 1,318 / 43 / 20 |
| Product attributes | **101** |
| Attribute sets | **11** |
| Configurables with **> 250** variants | **all 20** |
| **Largest single configurable** | **660 variants** (2 axes) |

Native Magento prices, hydrates and stock-checks a configurable by iterating **every** child, so
cost scales with variant count — a **660-variant** product is a worst case that makes cold PDP,
cart and checkout crawl. The whole point of the serving layer is that these operations become
**index reads that stay flat as variant count and attribute count grow**. If your catalog is
large, EAV-heavy, or full of big configurables, this is exactly the workload it targets; a small,
simple catalog will see the query reductions but feel less wall-clock benefit (see the note under
Benchmarks).

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
| Cart / Checkout † | 1.16 s | 1.15 s |

> † This row is the base serving layer with **Fast Checkout off**. Cart/checkout wall-clock is
> dominated by configurable line-item hydration, which the opt-in **Fast Checkout** feature
> removes — up to **8×** on configurable carts (see the **Fast Checkout** table below).

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

### Fast Checkout — configurable-cart render time

The cart/checkout **HTML render** cost is dominated by hydrating each configurable line item
(parent + used-products + per-source MSI salability), so native checkout time grows with the
number of configurable line items. **Fast Checkout** (`Enable Fast Checkout`, off by default)
serves those line products from the index and flattens it. Warm render, this storefront:

| Cart | Native | Fast Checkout | 
|---|---:|---:|
| 1 simple | 0.93 s | 0.41 s |
| 1 configurable | 1.08 s | 0.42 s |
| **10 configurable variants** | **3.34 s** | **0.41 s** |

Native adds **~230 ms of render per configurable line**; Fast Checkout is **flat** regardless of
configurable count (≈0.4 s), an **8× cut** on a 10-configurable cart. Order placement still
re-checks salable quantity by SKU (MSI reservations), so it cannot oversell; any product
missing/partial in the index falls back to the native path automatically.

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
  category suggestions) and a search results page whose **attribute facets**, product grid
  and pagination re-render live from OpenSearch with no page reload (Algolia-style). Facets
  (category + configured attributes, e.g. Part Type / Color / Link Style) and their option
  **labels are resolved entirely from the index** — no DB/EAV lookup on the request path.
  Configure via `FastMagento > Search > Facet Attributes`. Endpoints:
  `/fastmagento/search/suggest` and `/fastmagento/search/instant`.
- **Fast Checkout** (opt-in, off by default) — serves cart/checkout line products, including
  configurable variants, from the index instead of the native ~217-query product collection,
  and skips the redundant per-load stock revalidation. Removes the multi-second cost of
  configurable line items at checkout (see Benchmarks). One admin toggle,
  `FastMagento > Fast Checkout > Enable Fast Checkout`; order placement still gates stock by
  SKU so it cannot oversell, and anything not fully in the index falls back to native.
- **Real-time stock sync** — order placement, refunds/returns and MSI inventory-API writes
  reproject the affected products (and their configurable/grouped/bundle parents) into the
  index immediately, so quantity and in-stock status stay live. An optional **fast stock
  sync** patches only the stock fields of the affected docs (instead of a full reprojection)
  for much lower cost on large configurables.
- **Always-ready index** — catalog-rule recalculations (rule save / nightly apply-all) patch
  the affected docs' per-group rule prices into the index automatically, so the OS-served
  cart is correct without a manual reindex.
- **Read-path resilience** — *warm-on-miss* (a product missing from the index is added on
  first access, self-healing like a cache miss) and automatic native fallback whenever
  OpenSearch is unavailable.
- **Cache/Varnish transparent** — serving happens beneath FPC and the layout cache.

---

## AI-powered search relevance

Instant search is tuned to behave like Algolia/Sphinx on a real catalogue, and it self-optimises
from your own content. The goal: **install the extension, run the AI mapping tool, and get the
best possible search with almost no hand-tuning.**

### Relevance engine

The query builder (`Model/Search/InstantSearch::buildQuery`) ranks results with:

- **Exact / phrase boosting** — a product whose name/keywords contain the query as a contiguous
  phrase outranks docs where the words are merely scattered, so "skid plate" ranks the actual
  skid plates above everything that just contains "plate".
- **All-terms precision boost** — products matching *every* term (across name + keywords +
  description) rank above single-term hits, without ever costing recall.
- **Multi-term operator** — `FastMagento > Search > Multi-Term Operator`: **Any** (OR, broadest),
  **Most** (75%), or **All** (AND, most precise).
- **Symmetric synonyms** — a query and its synonyms are scored as true equivalents, so
  `frontend` and `front end` (or `sxs` and `utv`) return **identical** results in the same order.
  Phrase-level expansion means single-word ⇄ multi-word variants work (`frontend ⇄ front end`,
  `a-arm ⇄ control arm`), which token-level swapping cannot do.
- **Typo tolerance**, **in-stock boost**, and a **custom-ranking** tie-breaker (all admin toggles).

### Two synonym homes — used automatically

- **Global synonym thesaurus** (`Search > Synonyms`) — for *distinctive* terms. Ships with a
  **bundled starter database** (`etc/thesaurus/starter-synonyms.json`, imported on install,
  merge-safe) covering compound/grammatical variants (front end, back end, rear end, t-shirt),
  colours, sizes, materials and misspellings.
- **Per-product AI keyword layer** (`fm_search_keywords`) — a hidden, high-weight searchable
  attribute the AI fills per product with buyer terms and aliases (UTV ↔ side-by-side ↔ SxS,
  fitment/brand nicknames), so a product surfaces for terms its visible copy never mentions.
  Buyer phrases built from common words (e.g. "side by side") live here, where they match
  precisely, rather than as global synonyms that would over-broaden.

### AI tools (Claude) — scrape your content, optimise search

Add a Claude API key under `FastMagento > AI Assistant`, then:

- **Generate Thesaurus** (admin button) — scrapes your attribute labels, category names **and a
  sample of product names**, and discovers the grammatical/compound variants your copy actually
  uses (e.g. `rear end ↔ back end ↔ backend`, `u-bolt ↔ ubolt`, `rock racer ↔ rockracer`),
  merging them into `Search > Synonyms`. It is guard-railed against building groups around common
  words.
- **Generate Search Keywords** (CLI) — populates `fm_search_keywords` for the catalogue, off the
  request path, in resumable best-effort batches:

  ```bash
  bin/magento fastmagento:search-keywords:generate [--from --to --batch --limit --force --dry-run]
  bin/magento indexer:reindex catalogsearch_fulltext   # make the new keywords searchable
  ```

  Enable via `FastMagento > Search > AI Search Keywords`. Use a fast model (e.g. Haiku/Sonnet)
  under `AI Assistant > Model` for large catalogues.

### Measuring relevance

`docs/tools/search-relevance.php` runs a set of golden queries through the **real** query builder
and prints the ranked top-N with scores plus pass/fail checks, so ranking changes are measured,
not guessed:

```bash
php app/code/ParkkTech/FastMagento/docs/tools/search-relevance.php            # golden-queries.json
php app/code/ParkkTech/FastMagento/docs/tools/search-relevance.php "front end" "sxs"
```

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

## Configuration reference

All settings live under **Stores → Configuration → FastMagento**. Every value is store-view
scoped unless noted; sensible defaults ship in `etc/config.xml` so the extension is usable
immediately after install.

### Indexing

| Setting | Default | What it does |
|---|---|---|
| Enable Real-time Indexing | On | Update OpenSearch docs synchronously on catalog save (mview). |
| Enable Cron Indexing | On | Let the `mview` cron flush pending changes on a schedule. |
| Product Index Prefix | `products` | Index name prefix for the product serving index. |
| Category Index Prefix | `categories` | Index name prefix for the category serving index. |

### Instant Search & Relevance

| Setting | Default | What it does |
|---|---|---|
| Searchable Attributes & Weights | name, sku, descriptions | The fields searched and their relative weights (higher = ranks stronger). One grid instead of per-attribute Search Weight. |
| Typo Tolerance | On | Fuzzy matching (`fuzziness: AUTO`) so misspellings still match. |
| Boost In-Stock Products | On | Rank in-stock above out-of-stock as a tie-breaker. |
| Custom Ranking Attribute / Direction | — | Optional numeric secondary sort after text relevance (e.g. bestseller, rating). |
| **Multi-Term Operator** | Any (OR) | How multiple words combine: **Any** (broadest), **Most** (75%), **All** (AND, most precise). |
| **Exact / Phrase Match Boost** | 4 | How hard a contiguous-phrase match outranks scattered-word hits. 0 disables. |
| Synonyms / Thesaurus | starter DB | Equivalence groups (one per line). Ships with a bundled starter database; extend by hand or with the AI tool. |
| Stop Words | common English | Words ignored in queries. |
| Facet Attributes | — | Comma-separated attribute codes that build the search-results facets (single-select). |
| **AI Search Keywords** | Off | Search the per-product AI keyword layer (`fm_search_keywords`). Enable after populating it (below). |
| **AI Keyword Weight** | 8 | Search weight of the AI keyword field when enabled. |
| **AI Keyword Source Attributes** | facet attrs | Attribute codes whose labels give the AI product context when generating keywords. |

### AI Assistant (Claude)

| Setting | Default | What it does |
|---|---|---|
| Claude API Key | — | Stored **encrypted**. Enables the AI thesaurus + keyword tools. Leave blank to disable AI. |
| Model | `claude-opus-4-8` | Claude model id. **Use a fast model (Haiku/Sonnet) for large keyword runs.** |
| Max Catalogue Terms | 1200 | Upper bound on vocabulary sent to the model (keeps prompts bounded on big catalogs). |
| Generate Thesaurus | button | Scrapes your attribute labels, categories and product names → discovers synonym/compound groups → merges into Synonyms. |

### Fast Checkout

| Setting | Default | What it does |
|---|---|---|
| **Enable Fast Checkout** | On | Master toggle: serve cart/checkout line products from the index + optimistic stock. Cannot oversell (SKU-gated at placement); falls back to native for anything not fully indexed. |
| OS-Serve Quote Items *(advanced)* | Off | Serve the quote-item collection from OpenSearch by itself. |
| Optimistic Stock *(advanced)* | Off | Skip the redundant per-load MSI preload; rely on the placement-time gate. |
| Fast Stock Sync *(advanced)* | Off | Patch only stock fields of affected docs instead of a full reprojection. |
| Configurable Line Name | parent | Show the configurable (parent) or purchased child name on cart/checkout lines. |

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

## Operations & maintenance

### Reindexing

```bash
bin/magento indexer:reindex fastmagento_product      # product serving index
bin/magento indexer:reindex fastmagento_category     # category serving index
bin/magento indexer:reindex catalogsearch_fulltext   # native search index (after AI keyword runs)
```

Day to day, real-time + cron indexing keep both indexes current; a full reindex is only needed
after a bulk import or a mapping change. Reindexing streams documents in bulk chunks, so memory
stays flat regardless of catalog size.

### AI search-mapping tools

The intended setup flow — *install → add a Claude key → run the mapping tools → best search*:

1. **Thesaurus** (admin): `FastMagento > AI Assistant > Generate Thesaurus`. Scrapes your own
   content (attribute labels, categories, product names) and merges discovered synonym/compound
   groups into `Search > Synonyms`. Re-runnable; merge-safe.
2. **Per-product keywords** (CLI): populate `fm_search_keywords`, then reindex fulltext.

   ```bash
   # dry-run a sample first (no API calls, no writes)
   bin/magento fastmagento:search-keywords:generate --dry-run --limit=25

   # generate for the whole catalogue (resumable; re-run continues where it left off)
   bin/magento fastmagento:search-keywords:generate --batch=25

   # scope / control
   #   --from N --to N     entity_id range
   #   --limit N           cap this run
   #   --force             regenerate products that already have keywords
   bin/magento indexer:reindex catalogsearch_fulltext
   ```

   Then enable `Search > AI Search Keywords`. For a 14k+ catalog, set a fast model under
   `AI Assistant > Model` (Haiku/Sonnet) — keyword extraction does not need the largest model.

### Measuring search relevance

```bash
# run the golden queries (docs/tools/golden-queries.json) through the real query builder
php app/code/ParkkTech/FastMagento/docs/tools/search-relevance.php

# ad-hoc: compare specific queries with scores
php app/code/ParkkTech/FastMagento/docs/tools/search-relevance.php "front end" "sxs" "skid plate"
```

Prints the ranked top-N with `_score` and pass/fail checks, so any relevance/synonym change is
measured before and after — not guessed.

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
- **Search facets** cover **single-select** attributes today; multi-select attributes
  (e.g. Compatible Platforms) are deferred — the native fulltext index sorts a doc's option-id
  and option-label arrays independently, so an id can't be mapped to its label from OpenSearch
  alone. A per-attribute option dictionary in the index would close this.
- **Fast Checkout** is opt-in and should be validated with a real order (guest + a logged-in
  group, plus a deliberately over-qty line) before enabling in production; it also assumes a
  fresh price/rule projection (the always-ready rule sync keeps it current after the first
  reindex).

---

## Test tooling

- `docs/tools/search-relevance.php` + `docs/tools/golden-queries.json` — relevance harness: runs
  golden queries through the real query builder and prints ranked hits with scores + pass/fail.
- `docs/tools/create-downloadable-test.php` — creates a downloadable product with multiple
  purchasable links and product-level samples to exercise the full downloadable render path.
- `docs/tools/query-profile.sh` — DB-query profiler used to verify OpenSearch serving.
