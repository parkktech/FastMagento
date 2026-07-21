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

## Secondary task — 2nd-configurable add-to-cart bug — DONE (commit 2c6dc57b6)
The handoff description ("silent, no error, no quote_item row, only the 2nd configurable")
was inaccurate on every point. Real symptom: add-to-cart failed with **"This product is out
of stock."** — reproducible even with an EMPTY cart, so not about being second. It was
intrinsic to specific products: Matilda `5030` failed, Keira `4369` worked, purely because
Matilda's configurable **parent** `cataloginventory_stock_status` index row was stale at `0`
while all children were in stock (Keira's happened to be fresh `1`).
- Root cause: a composite parent holds no stock of its own (salability = "any child in
  stock"), maintained by the native/MSI stock-status mview which can lag. StockSyncer keeps
  only the OS doc live, so the native parent row goes stale. `QuantityValidator` reads the
  parent's `getStockStatus()` during add-to-cart and rejects on that coarse gate. MSI's
  `AdaptGetStockStatusPlugin::afterGetStockStatus` recomputes it from MSI (which reflects the
  stale legacy row here), so it's the authority actually read.
- Fix: `Plugin/Inventory/CompositeParentStockStatusPlugin` — `afterGetStockStatus` on
  `StockRegistryInterface` (frontend, sortOrder 100 → runs after MSI). For a composite parent
  whose OS children are in the `child_products_<id>` registry, forces parent status in-stock
  if any child is in stock (mirrors the PDP `Configurable::isSalable()` OS-trust). Registry-
  only (no OS round-trip); simples/unresolved ids keep native status; only the coarse parent
  gate is lifted (the purchased child is still validated natively) → cannot oversell.
- Verified in-browser with the native index deliberately re-staled to 0: add succeeds +
  persists, two configurables coexist in one cart, simple-with-native-OOS stays blocked.
- Left as separate latent issues (not this fix): undefined `$registryKey` in
  `Configurable::getUsedProducts()` native-fallback (throws on a 2nd null-key register);
  `AddProductPlugin::getSelectedChildProduct` matches `attribute_<id>` instead of the attribute
  code (only mis-applies the child catalog-rule price, not persistence).

## Notes / gotchas
- Commit atomically; measure cold before/after; keep the README benchmark table current.
- `catalogrule_product_price` stays flat (~175k rows) regardless of rule count (always-active
  rules pre-collapse into the stacked price) — rules are NOT the checkout read bottleneck; the
  native quote-item load is. 1000-rule stress only slows the catalog-rule REINDEX (admin/cron).
- Built-in FPC in this env does not vary catalog pages by customer group (affects native pages
  too); Varnish handles it in prod. Uncacheable cart/checkout are always group-correct.
- Fixtures: `docs/tools/create-catalog-rules.php`, `docs/tools/stress-catalog-rules.php`.
