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

## Open questions (answer when back — NEEDS YOU)
_(none yet)_

## Deferred / risky items I did NOT auto-do
_(none yet)_
