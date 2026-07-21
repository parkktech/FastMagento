# FastMagento — Cart / Checkout OS-serving — RESUME HERE

Handoff for resuming the cart/checkout OpenSearch optimization. Read these first, in order:
- `app/code/ParkkTech/FastMagento/docs/RESUME.md`
- Project memory `fastmagento-opensearch-work.md` (recent-work section covers the cart profiling + gaps)
- `git log --oneline -10` (branch `feature/fastmagento-opensearch-layer`, pushed to origin)

## Environment
- Local dev `http://www.diyoffroad.loc/` (WSL2 → 127.0.0.1, send `Host: www.diyoffroad.loc`),
  developer mode, OpenSearch :9200, index `magento2_products`.
- Wholesale test customer `wholesale@diytest.loc` / `Wholesale123!` (group 2).
- 3 catalog price rules active: 10% all groups, +15% Wholesale-only, +$5 General-only.
- Profiler: `bash app/code/ParkkTech/FastMagento/docs/tools/query-profile.sh enable|<path>|disable`.
- **Always measure COLD** — `php bin/magento cache:disable full_page` — never FPC-warm (an FPC
  hit renders no product blocks and hides the cost). Re-enable FPC + disable db_logger when done.

## Where things stand
Cart/checkout is **NOT OS-served**. Steady-state cart (1 configurable + simple + downloadable)
≈ **364 SQL**; ~217 of them all stem from ONE native load,
`Quote\Item\Collection::_assignProducts()`:
- native product/EAV ≈ 119
- MSI / inventory stock ≈ 71
- downloadable links ≈ 27

The three "gaps" (native EAV, stock-from-OS, downloadable-from-OS) are really **one project**:
serve the quote-item collection from OpenSearch.

## Primary task — OS-serve the quote-item collection (revenue path, SUPERVISED)
Intercept the quote-item product load so cart/checkout hydrate products from OpenSearch shells
instead of the native `Product\Collection`. Closes all three gaps at once. Groundwork already
in the index:
- full stock-item config at `extension_attributes.stock_item` (min/max/qty/backorders/etc.)
- downloadable links/samples indexed
- per-group `catalog_rule_prices` map indexed

Build with a **hard native fallback** (missing/partial doc → native load). **Verify with a real
supervised test order** (owner places it): tax, totals, custom options, stock decrement, order
placement — and confirm **no overselling** (stock accuracy at checkout) — before trusting it.
Do NOT half-build this; it is checkout.

## Secondary task — 2nd-configurable add-to-cart bug
Adding a second configurable in one session (e.g. Matilda `5030`, child `4370` = color 86 /
size 89) resolves the child correctly — `getProductByAttributes` matches after the per-parent
registry fix (`child_products_<id>`, commit that made the registry per-parent) — but the item
**never persists to the quote** (no error, no `quote_item` row). Root-cause the downstream
failure. Single-configurable add works fine (4369 → 43.61 Wholesale).

## Notes / gotchas
- Commit atomically; measure cold before/after; keep the README benchmark table current.
- `catalogrule_product_price` stays flat (~175k rows) regardless of rule count (always-active
  rules pre-collapse into the stacked price) — rules are NOT the checkout read bottleneck; the
  native quote-item load is. 1000-rule stress only slows the catalog-rule REINDEX (admin/cron).
- Built-in FPC in this env does not vary catalog pages by customer group (affects native pages
  too); Varnish handles it in prod. Uncacheable cart/checkout are always group-correct.
- Fixtures: `docs/tools/create-catalog-rules.php`, `docs/tools/stress-catalog-rules.php`.
