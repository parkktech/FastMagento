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
4. **Category from OpenSearch (Phase 2L)**: search page still fires ~236 product SQL
   (category ~58 + url_rewrite ~112 category-driven). Index category name/request_path/
   tree per store; build the collection `<preference>` + OS aggregations for layered nav.
5. **Layered nav / instant-search UX / write-path sync + delete / resilience
   (OS-down fallback, zero-downtime alias reindex, reconciliation) / admin config /
   harden** — see plan §4. Not started.

## Known interim shortcuts to revisit
- Category listing served natively (broken PLP block override disabled).
- ProductRepository get()/getList() fall back to native (not OS-served yet).
- Product URL built from url_key+suffix (robust = index real request_path per store).
- Reindex drops the index (no zero-downtime alias yet) → transient PDP 500s during reindex.
