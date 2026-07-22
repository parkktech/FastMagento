# FastMagento — AI Search Keywords + Search-Algorithm Controls — BUILD SPEC

> **STATUS: IMPLEMENTED (2026-07).** All three deliverables shipped, plus expert relevance work
> (phrase/all-terms boosting, symmetric synonyms, operator toggle, content-aware AI thesaurus,
> bundled starter thesaurus). See `docs/ARCHITECTURE.md` §7 for the as-built design and
> `docs/tools/search-relevance.php` for the golden-query harness. This document is retained as the
> original design rationale.

Handoff spec for the next session. Goal: make FastMagento search behave more like Algolia /
Sphinx (Mirasvit Search Ultimate). Reference doc the user provided:
**https://mirasvit.com/docs/module-search-ultimate/1.1.7**

Three independent deliverables. Build in this order (1 → 2 → 3); each is shippable alone.

---

## Architecture facts to know FIRST (learned this session — don't re-derive)

- **Two indexes.** InstantSearch relevance + facets run against **Magento's native fulltext
  index** `{catalog/search/opensearch_index_prefix}_product_{storeId}` (see
  `Model/Search/InstantSearch.php::getSearchIndex()` ~line 570 and `search()` line 90). The
  FastMagento index `magento2_products` (`OpenSearchConfig::getIndexName()`) is only for product
  **hydration** (mget of display data) + PDP/PLP/cart serving.
  → **Consequence:** to make a new attribute *searchable*, it must land in the NATIVE index.
  The clean way is a real Magento product attribute with `is_searchable=1`; Magento's own
  fulltext indexer then includes it automatically. Do NOT try to bolt it only onto the
  FastMagento index — InstantSearch won't query it there.
- **Query builder:** `InstantSearch::buildQuery()` (lines ~115-164). Today: a `multi_match`
  `bool_prefix` on the stop-word-stripped query over `RelevanceConfig::getBoostedFields()`, plus
  lower-boosted `best_fields` clauses per thesaurus synonym, wrapped in `bool { should,
  minimum_should_match:1 }` (i.e. **match-ANY**), optionally wrapped in a `function_score` that
  boosts in-stock. Filters are `terms` clauses. This is where the operator toggle + relevance
  changes go.
- **Relevance config:** `Model/Search/RelevanceConfig.php` — `getBoostedFields()` (from admin
  `searchable_attributes` weights), `isTypoToleranceEnabled()`, `isBoostInStockEnabled()`,
  `getFacetAttributes()`. Admin fields live in `etc/adminhtml/system.xml` group `search`
  (searchable_attributes, typo_tolerance, boost_in_stock, custom_ranking_*, synonyms, stopwords,
  facet_attributes). Defaults in `etc/config.xml` `<fastmagento><search>`.
- **AI flow:** `Model/Ai/ThesaurusGenerator.php` (walks catalog terms, calls Anthropic, writes
  the synonyms config), `Model/Ai/AnthropicClient.php` (fixed endpoint, encrypted key via
  `AiConfig::getApiKey()` → `EncryptorInterface::decrypt`), triggered by
  `Controller/Adminhtml/Ai/GenerateThesaurus.php` (POST, ACL `ParkkTech_FastMagento::config`).
- **Indexer:** `Model/Indexer/ProductIndexer.php::prepareDoc()` projects the FastMagento doc;
  `getAttributeValues()` builds the `attributes.{code}` map; `getDynamicAttributeMapping()`
  maps custom attributes as `keyword`. A new attribute the SHELL needs would be added here, but
  for *search* the native fulltext index (above) is what matters.

---

## Deliverable 1 — `fm_search_keywords` attribute + AI population

**What:** a hidden, non-frontend product attribute the AI fills with search keywords/synonyms
per product, indexed as a high-weight searchable field so e.g. a "UTV" product surfaces for
"UTV / side-by-side / SxS" even if the visible copy never says it.

**1a. Create the attribute (data/schema patch).**
- New `Setup/Patch/Data/AddSearchKeywordsAttribute.php` (EAV attribute via
  `Magento\Eav\Setup\EavSetupFactory` or `ProductAttributeRepository`).
- Code `fm_search_keywords`, `type=text`, `input=textarea`, `backend=''`,
  `required=0`, `user_defined=1`, `is_searchable=1`, `search_weight` high (e.g. 8),
  `is_visible=0`, `is_visible_on_front=0`, `is_visible_in_advanced_search=0`,
  `used_in_product_listing=0`, `is_filterable=0`, `is_comparable=0`, `is_html_allowed=0`,
  attribute group unset/"Search". Global scope.
- Because `is_searchable=1`, Magento's native fulltext indexer indexes it into the native
  search index automatically → InstantSearch can weight it (see 1c). No FastMagento-index
  change strictly required for search; optionally also project it into the FastMagento doc if
  any read path wants it.

**1b. Populate it with AI (batched — this is the hard part).**
- Extend the AI layer with a `Model/Ai/SearchKeywordGenerator.php` (mirror ThesaurusGenerator
  structure). For each product (or batch of N), send name + key attributes (make/model/
  part_type/vehicle_type/etc.) + short description to Anthropic and ask for a compact,
  comma-separated keyword/synonym list (buyer terms, platform aliases: UTV↔side-by-side↔SxS,
  etc.). Write the result to `fm_search_keywords` via the product resource
  (`Magento\Catalog\Model\ResourceModel\Product\Action::updateAttributes` — bulk, no full save).
- **Batching is mandatory:** 14,600 products cannot be one API call each on the request path.
  Design: a CLI command (`bin/magento fastmagento:search-keywords:generate [--from --to
  --batch]`) and/or a cron, chunked (e.g. 20-50 products per prompt, ask the model to return a
  JSON map `{sku|id: "kw1, kw2, …"}`), with `max_terms`/token budgeting from `AiConfig`. Make it
  resumable (skip products that already have `fm_search_keywords` unless `--force`) and
  best-effort per batch (log + continue).
- Admin: a "Generate Search Keywords" button next to the thesaurus one (reuse the
  `Block/Adminhtml/System/Config/GenerateThesaurusButton` + a new controller under
  `Controller/Adminhtml/Ai/`), and/or expose the CLI. Gate with `enable_ai` + a new
  `search_keywords_enabled` toggle. Respect the existing encrypted-key + ACL pattern.
- Reindex `fastmagento_product` and the native fulltext after population (or updateAttributes
  triggers the mview; verify).

**1c. Weight it in search.**
- Add `fm_search_keywords` to the admin `searchable_attributes` weights (or force-include it in
  `RelevanceConfig::getBoostedFields()` with a high default weight) so `buildQuery`'s
  `multi_match` fields include `fm_search_keywords^8`.

**Gotcha:** the AI keyword-generation cost/latency is the real risk — keep it a background/CLI
job, never inline. Consider only generating for products missing keywords, and re-running when
products change (hook `catalog_product_save_after` to clear/regenerate lazily, or leave manual).

---

## Deliverable 2 — Search-operator toggle (AND / OR / phrase), Sphinx-style

**What:** admin control over how multiple query terms combine, fixing "Frontend UTV" returning
frontend-only matches.

- New `system.xml` (group `search`) select `search_operator`: `any` (OR — current behavior,
  `minimum_should_match:1`) | `all` (AND — every term must match) | `most` (e.g.
  `minimum_should_match:"75%"`). Default `any` to preserve today's behavior; the user can pick
  `all`/`most`. Add `RelevanceConfig::getSearchOperator()`.
- In `InstantSearch::buildQuery()`: set the primary `multi_match` `operator` = `and` (for
  `all`) or apply `minimum_should_match` on the bool (for `most`). Keep `bool_prefix` for
  as-you-type, but note `bool_prefix`'s last-term-prefix semantics interact with `operator` —
  test; may need `type: cross_fields`/`best_fields` with `operator:and` for the `all` mode.
- Optional: a per-request override (`&op=and`) for testing, allow-listed to the three values.

---

## Deliverable 3 — Relevance overhaul toward Algolia/Sphinx

**What:** ranking quality — "UTV" should rank UTV parts above unrelated "seats". This is the
biggest, most iterative piece; use the Mirasvit doc as the feature checklist.

Candidate work (scope with the user; not all at once):
- **Per-attribute searchability + weights UI** already exists (`searchable_attributes`); make
  sure `name`, `sku`, `fm_search_keywords`, `part_type`/`vehicle_type` labels, category names
  carry sensible default weights (name/sku/keywords high).
- **Exact / phrase boosting:** add a `match_phrase` (or `multi_match type:phrase`) clause with a
  high boost so exact and in-order matches outrank scattered token hits — this is what fixes the
  "seats above UTV" ranking.
- **Field-level tie-breakers / `tie_breaker`** on `multi_match`, and consider `cross_fields` so a
  multi-field product isn't penalized.
- **Category/attribute term matching:** ensure `category_names` and the human labels of
  filterable attributes (`{code}_value`) are searchable so "UTV" (a vehicle_type option label)
  matches products carrying that option even without keywords.
- **Custom ranking signals:** `custom_ranking_attribute`/`direction` already exist; wire them
  into the `function_score` alongside in-stock boost (bestseller/newest/etc.).
- **Typo tolerance** (`fuzziness:AUTO`) already toggled — verify it doesn't drown exact matches
  (usually lower-boost the fuzzy clause).
- **Synonyms/thesaurus** already feed lower-boosted variants; once `fm_search_keywords` exists,
  much of the "UTV↔SxS" need is covered per-product instead of globally.

**Test harness idea:** a small CLI (`docs/tools/search-relevance.php`) that runs a set of golden
queries ("UTV", "Frontend UTV", "front end", brand names) and prints the top-10 with scores, so
relevance changes are measured, not guessed. Build this first in D3 and iterate against it.

---

## Files this touches (checklist)
- `Setup/Patch/Data/AddSearchKeywordsAttribute.php` (new)
- `Model/Ai/SearchKeywordGenerator.php` (new) + `Console/Command/GenerateSearchKeywords.php` (new) + `etc/di.xml` command registration
- `Controller/Adminhtml/Ai/GenerateSearchKeywords.php` (new) + button block + `system.xml` field
- `Model/Search/InstantSearch.php` (buildQuery: operator + phrase boost + keyword field)
- `Model/Search/RelevanceConfig.php` (getSearchOperator, ensure keyword field weighted)
- `etc/adminhtml/system.xml` + `etc/config.xml` (search_operator, search_keywords toggle, keyword weight)
- `Model/Indexer/ProductIndexer.php` (optional: project fm_search_keywords into the FastMagento doc)

## Verify / guardrails
- Confirm `fm_search_keywords` lands in the NATIVE fulltext index (`GET
  {prefix}_product_1/_mapping` shows it) and that `buildQuery` fields include it.
- Keep AI generation OFF the request path (CLI/cron only); best-effort; encrypted key; ACL.
- Re-run the security posture: any new admin controller ACL-gated + POST + form key (mirror
  `GenerateThesaurus`); the keyword CLI is not web-reachable.
- Measure relevance with the golden-query harness before/after every D3 change.
