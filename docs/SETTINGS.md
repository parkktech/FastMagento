# FastMagento — Settings Reference

Every FastMagento setting, what it actually does, and how to tune it — with examples. The search &
relevance controls are broken down clause-by-clause so you can dial in the results you want.

**Admin location:** *Stores > Configuration > FastMagento Settings*

Every config path shown below also works from the CLI / automation:

```bash
bin/magento config:set <path> <value>
bin/magento config:set --scope=stores --scope-code=<code> <path> <value>
```

A rich, graphical version of this document ships alongside it as
[`settings-reference.html`](settings-reference.html) (open in a browser) and
[`FastMagento-Settings-Reference.docx`](FastMagento-Settings-Reference.docx) (Word).

> **Safe by default.** Every feature degrades to native Magento on any index miss or OpenSearch
> outage. The defaults are production-ready; most stores only touch the search section.

**Scope legend:** *Store-level* = overridable per website / store view · *Global only* = one value site-wide.

---

## Contents

1. [Indexing](#1-indexing-configuration) — index names
2. [Instant Search & Relevance](#2-instant-search--relevance) — what matches and in what order
3. [AI Assistant](#3-ai-assistant) — auto-build a thesaurus / keyword layer
4. [Fast Checkout](#4-fast-checkout) — serve cart/checkout from OpenSearch
5. [Cache Warmup](#5-cache-warmup)
6. [Extension Efficiency Monitor](#6-extension-efficiency-monitor)
7. [Advanced (config-only)](#7-advanced-config-only)
8. [Cheat-sheet](#cheat-sheet)

---

## 1. Indexing Configuration

`fastmagento/indexing/*` — names the OpenSearch indexes FastMagento reads/writes. Most stores never change these.

> **Real-time vs. scheduled indexing is not set here.** That is controlled by Magento's own
> *System > Index Management* ("Update on Save" / "Update by Schedule"), because FastMagento's
> indexers run off Magento's native mview.

| Setting | Path | Type | Default | Scope |
|---|---|---|---|---|
| **Product Index Suffix** | `fastmagento/indexing/opensearch_index_prefix` | Text | `products` | Store-level |
| **Category Index Suffix** | `fastmagento/indexing/opensearch_category_index_prefix` | Text | `categories` | Store-level |

The full index name is the engine prefix joined to the suffix:

```
<catalog/search/opensearch_index_prefix>_<suffix>   →   e.g. magento2_products
```

Only change these to run parallel indexes (blue/green). **Reindex after changing** so the new index
is populated.

---

## 2. Instant Search & Relevance

`fastmagento/search/*` — decides **which** products a query returns and **in what order**. This is
the section most stores need to understand.

### How one search query flows

```
1. CLEAN        2. EXPAND          3. MATCH                    4. BOOST                5. SORT
lower-case,     add synonym        per candidate: prefix +     phrase & all-terms      by score, then
strip stop  ─▶  variants       ─▶  words + phrase across   ─▶  boosts, then the    ─▶  your custom
words           (equal weight)     weighted fields, with       in-stock nudge          ranking tie-
                                   Operator & Typo rules                               breaker
"the front  →   + "frontend",      name^5 sku^6 …              ×4 phrase               _score →
 end"           "sxs","utv"                                    ×1.6 in-stock           bestseller
```

**Mental model:** stages 1–2 decide *what counts as the query*, stage 3 decides *what matches*, and
stages 4–5 decide *the order*. When results feel wrong, find the responsible stage below.

### Searchable Attributes & Weights

`fastmagento/search/searchable_attributes` · Grid · Default: `name·5, sku·6, short_description·1, description·1` · Store-level

Which product fields are searched, and how strongly each counts. Higher weight = a hit there ranks
higher. One place to control what Magento otherwise buries in each attribute's "Search Weight". Leave
empty to use the defaults. Each weight becomes the field boost (`name^5`) in every match clause.

> Attributes must be in the search index. After changing this list, run
> `indexer:reindex catalogsearch_fulltext`.

### Multi-Term Operator

`fastmagento/search/search_operator` · Select · Default: `any` · Store-level

How multiple typed words combine — **the single biggest lever on "too many vs. too few results".**

| Value | Behavior | Use when |
|---|---|---|
| **Any** *(default)* | OR — one matching word is enough. Broadest, most results. | Small catalogs; you'd rather show more. |
| **Most** | 75% of words must match. Balanced. | 3+ word queries; a good middle ground. |
| **All** | AND — every word must match. Strictest, most precise. | Loosely-related items are leaking in. |

**Worked example — query `Frontend UTV`:**
- **Any** → anything that says *frontend* **or** *UTV* — generic frontend parts leak in.
- **All** → only products matching *frontend* **and** *UTV* — the intended UTV front-end parts.

Terms may be spread across name, keywords *and* description (cross-field) — "All" doesn't force every
word into one field.

### Typo Tolerance

`fastmagento/search/typo_tolerance` · Yes/No · Default: `Yes` · Store-level

Fuzzy matching so misspellings still find results (OpenSearch equivalent of Algolia typo tolerance).
Applied to the as-you-type match with `fuzziness: AUTO` (edit distance scales with word length).

- `"contol arm"` → still finds *control arm*
- `"suspention"` → *suspension*

Turn off only if fuzzy matches pull noise into very short SKUs.

### Exact / Phrase Match Boost

`fastmagento/search/phrase_match_boost` · Number · Default: `4` · Store-level

How hard to rank products where the query appears as a **contiguous phrase** (in name / keywords)
above products where the same words are merely scattered. `0` disables it.

**Worked example — query `rock slider`:** a product named *Rock Slider Kit* (exact phrase) is boosted
**×4** over one whose description just happens to contain "rock" and "slider" in different sentences.

| Value | Effect |
|---|---|
| Higher (6–8) | Exact phrasing wins harder — good when product names are clean. |
| Lower (1–2) | Softer — lets strong scattered-word matches compete. |
| 0 | No phrase preference at all. |

This value also feeds the "all-terms" precision boost (≈75% of it), so raising it tightens phrase
*and* full-coverage ranking together.

### Synonyms / Thesaurus

`fastmagento/search/synonyms` · Textarea · Pre-populated · Store-level

One equivalence group per line, comma-separated. A search for *any* term in a group also matches the
others — scored **identically**, so `frontend` and `front end` return the same results in the same order.

```
sxs, utv
a-arm, a arm, control arm, control-arm
shock, damper, coilover
```

> **Keep terms distinctive.** A group term that is a common catalogue word (e.g. "side" inside "side
> by side") over-broadens every query that expands to it, because synonym equivalents match at full
> strength. Put buyer *phrases* that contain common words into the **AI Search Keywords** layer
> instead — that's why `sxs, utv` lives here but "side by side" does not.

Multi-word terms work: substitution is phrase-level, so `a-arm` ⇄ `control arm` (joining/splitting
words) is fine. No thesaurus yet? Generate one from your catalogue — see [AI Assistant](#3-ai-assistant).

### Stop Words

`fastmagento/search/stopwords` · Textarea · Pre-populated · Store-level

Comma- or space-separated words ignored in queries, so filler doesn't dilute the match.

```
a, an, and, or, the, of, for, with, to, in, on, at, by, is, it, this, that, your, my
```

The raw multi-word phrase is preserved for phrase-matching even when a middle word is a stop word —
so "side by side" stays intact rather than collapsing to "side side".

### Facet Attributes

`fastmagento/search/facet_attributes` · Text · Default: `part_type,color,size,link_style,shock_spacing` · Store-level

Comma-separated attribute codes that become the filter facets on the instant-search results page.
Category is always included automatically.

> Use **filterable, SELECT-type** attributes — their option labels resolve cleanly from the index.
> Multi-select attributes aren't supported here yet (they need an indexed option dictionary).

### Boost In-Stock Products

`fastmagento/search/boost_in_stock` · Yes/No · Default: `Yes` · Store-level

Nudges in-stock products above out-of-stock ones *without* a hard sort, so text relevance stays
primary. Implemented as a ×1.6 score multiplier on in-stock items — a strongly-relevant out-of-stock
product can still outrank a weakly-relevant in-stock one.

### Custom Ranking Attribute & Direction

`fastmagento/search/custom_ranking_attribute` · `…/custom_ranking_direction` · Text + Select · Default: blank · Highest first · Store-level

An optional **numeric** attribute used as a *secondary* ranking signal, applied only after text
relevance (Algolia-style custom ranking). Blank = relevance only.

**Example:** `custom_ranking_attribute = bestseller_score`, `direction = Highest first` → products
that tie on text relevance are broken by the higher `bestseller_score`.

| Direction | Meaning | Good for |
|---|---|---|
| Highest first (desc) | Bigger value ranks higher | bestseller rank, rating, popularity |
| Lowest first (asc) | Smaller value ranks higher | price-low, "position" fields |

Products missing the attribute sort last — they're never dropped.

### AI Search Keywords (layer)

`fastmagento/search/search_keywords_enabled` · Yes/No · Default: `No` · Store-level

Turns on searching a hidden, AI-generated per-product keyword field (`fm_search_keywords`). It lets a
product surface for terms its own copy never uses.

**Example:** a UTV part with no "side-by-side" text still ranks for `side-by-side` / `SxS` because
those buyer terms were generated into its keyword field.

> **Two steps to go live:** (1) populate the field off the request path with
> `bin/magento fastmagento:search-keywords:generate`, then (2) reindex `catalogsearch_fulltext`.
> Enabling the toggle alone does nothing until the field is populated.

| Companion | Path | Notes |
|---|---|---|
| **Weight** | `fastmagento/search/search_keywords_weight` | How strongly keyword hits rank (default `8`; name defaults to 5). Only used when the layer is on. |
| **Source attrs** | `fastmagento/search/keyword_source_attributes` | Attribute codes whose labels give the generator context (e.g. `part_type,make,model`). Blank = reuse Facet Attributes. *(Global only.)* |

---

## 3. AI Assistant

`fastmagento/ai/*` · **Global only** — connect an AI API key to auto-build a search thesaurus and
keyword layer from your own catalogue. Entirely optional; leave the key blank to keep AI features off.

| Setting | Path | Type | Default |
|---|---|---|---|
| **API Key** | `fastmagento/ai/claude_api_key` | Encrypted | blank (off) |
| **Model** | `fastmagento/ai/claude_model` | Text | model id |
| **Max Catalogue Terms** | `fastmagento/ai/max_terms` | Number | `1200` |
| **Generate Thesaurus** | *(button)* | Action | — |

- **API Key** — stored encrypted; used only by the generator tools. Blank disables all AI features.
- **Max Catalogue Terms** — upper bound on attribute values + category names sent to the model, so
  very large catalogues stay bounded and cost-controlled.
- **Generate Thesaurus** — reads your attribute values + category names and builds shopper-facing
  synonym groups, merged into *Search > Synonyms*.

> **Order of operations:** save the API Key first → click Generate → **review** the proposed groups →
> Save the Synonyms field. Nothing changes live search until you save.

---

## 4. Fast Checkout

`fastmagento/cart/*` — serves cart & checkout line items from OpenSearch instead of the native
~217-query product collection. The biggest single win for configurable-heavy carts.

### Enable Fast Checkout (master)

`fastmagento/cart/enable_fast_checkout` · Yes/No · Default: `Yes (ON)` · Store-level

The one switch most stores use. On by default, it enables the whole fast pipeline — OS-served quote
items, optimistic stock, and fast stock sync — so Fast Checkout works fully out of the box.

> **Cannot oversell.** Order placement still re-checks salable quantity by SKU (MSI reservations); a
> truly out-of-stock order is rejected at placement. Any product missing/partial in the index falls
> back to the fully native path automatically.

> After go-live, run a full reindex and place a **test order** to confirm totals. Set to **No** to
> fall back to the fully native cart.

### Advanced toggles (granular)

Default `No` — **implied ON by the master**, and hidden while the master is On. Each can also force
*its* feature on by itself when the master is Off.

| Toggle | Path | What it does |
|---|---|---|
| **Serve Quote Items from OpenSearch** | `fastmagento/cart/os_serve_quote_items` | Hydrate cart/checkout line products from the index instead of the ~217-query collection. Hard native fallback for missing/partial, custom-option, and bundle/grouped carts. |
| **Optimistic Stock** | `fastmagento/cart/optimistic_stock` | Skip the redundant per-load MySQL stock preload; rely on the authoritative placement-time gate. Trade-off: a briefly-stale index may only reject at the final step. *Validate with a real order.* |
| **Fast Stock Sync** | `fastmagento/cart/fast_stock_sync` | On an order/refund/inventory save, patch only the stock fields of affected OS docs instead of reprojecting the whole product. Runs after the response — no shopper latency. Full-reproject fallback if a doc isn't fully present. |

The three default to `0` by design: the master **OR** each flag turns a feature on, so leaving them
at 0 is what lets the master switch cleanly enable *and* disable the whole bundle.

### Configurable Line-Item Name

`fastmagento/cart/configurable_line_name` · Select · Default: `parent` · Store-level

Which name to show for a configurable product on cart / mini-cart / checkout. **Display only** — it
does not change price or the placed-order line data.

- **Parent** *(default)* — the configurable name (Magento default), e.g. "Keira Banded Bra" with
  Color / Size beneath.
- **Child** — the specific purchased variant's own simple name.

---

## 5. Cache Warmup

`fastmagento/cache/popular_searches` · Textarea · Default: blank · Store-level

A comma-separated list of your highest-traffic search terms. The scheduled `fastmagento_cache_warmup`
cron pre-renders these result pages so the first real shopper hits a warm cache instead of a cold render.

```
a-arm, rock slider, light bar, rzr cage, tie rod
```

Keep the list short and genuinely popular — warming rarely-used terms just wastes cache.

---

## 6. Extension Efficiency Monitor

`fastmagento/efficiency/*` · **Global only** — profiles how much database work each third-party
extension adds to the hot paths (indexing, product pages, category grids, search, logged-in customer
data). View it under *FastMagento > Extension Efficiency*.

| Setting | Path | Type | Default |
|---|---|---|---|
| **Run Scan on a Schedule** | `fastmagento/efficiency/cron_enabled` | Yes/No | `No` |
| **Schedule (cron expr)** | `fastmagento/efficiency/cron_expr` | Text | `0 3 * * 0` (Sun 3 AM) |
| **Sample Size** | `fastmagento/efficiency/sample_size` | Number | `50` |

> A scan briefly enables query logging to attribute SQL to modules — so it's opt-in. **Don't overlap
> a full reindex.** Run one on demand from the admin page or with
> `bin/magento fastmagento:efficiency:scan`.

**Sample Size** — products/rows profiled per scenario. Higher = steadier averages but a slower scan.

---

## 7. Advanced (config-only)

No admin screen — set via `app/etc/config.php`, `env.php`, or `bin/magento config:set`. Defaults are
correct for almost everyone.

### Attribute-Option Pagination

`fastmagento/attribute_pagination/enabled` (default `1`) · `…/page_size` (default `50`)

Replaces Magento's native "Manage Options" / swatch grid — which loads and re-saves the *entire*
option set and buckles past a few thousand options — with a paginated, per-row CRUD manager. Leave on
unless you specifically need the native grid; only matters for attributes with very large option sets.

### Attribute-Option Index Suffix

`fastmagento/indexing/opensearch_option_index_prefix` · Default `attribute_options`

Suffix for the attribute-option index (→ `magento2_attribute_options`). Same convention as the
product/category suffixes; rarely changed.

---

## Cheat-sheet

```bash
# Set any value from the CLI (great for CI / multi-store)
bin/magento config:set fastmagento/search/search_operator all
bin/magento config:set --scope=stores --scope-code=default fastmagento/cart/enable_fast_checkout 1

# After changing searchable attributes / AI keywords
bin/magento fastmagento:search-keywords:generate      # optional: build the AI keyword layer
bin/magento indexer:reindex catalogsearch_fulltext fastmagento_product fastmagento_category

# Efficiency monitor on demand
bin/magento fastmagento:efficiency:scan --sample=50

# Flush after config changes
bin/magento cache:flush
```

All config paths live under `fastmagento/*` and are settable in the admin, via `config:set`, or in
`app/etc/config.php` / `env.php`.
