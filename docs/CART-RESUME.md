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

### BUILD SPEC (scoped 2026-07-21 — two deep-trace agents; ready to implement)
**Interception:** preference on `Magento\Quote\Model\ResourceModel\Quote\Item\Collection`
overriding **`_assignProducts()`** only (the ~217-SQL native `Product\Collection` build at
vendor line ~257). The other product-load site, `removeItemsWithAbsentProducts()` (~line 393),
is a cheap id-only `getAllIds()` prune — leave it.

**Design (minimal divergence + hard fallback):**
1. Guard: not frontend area OR flag off → `parent::_assignProducts()`.
2. Batch-fetch all `$this->_productIds` from OS in ONE mget (`OpenSearchPdpFetcher::fetchByIds`).
3. **Any id missing/partial → `return parent::_assignProducts()`** (hard native fallback for the
   WHOLE collection — never half-serve a mixed cart). This path is 100% native = safe.
4. Full hit: build a real `Product\Collection`, populate with OS-hydrated shells via `addItem()`
   WITHOUT calling `load()` (no SQL); then run core's exact body — dispatch BOTH events
   (`prepare_catalog_product_collection_prices`, `sales_quote_item_collection_products_after_load`
   so `AddStockItemsObserver`/catalog-rule/3rd-party observers still fire) + the identical
   per-item loop (`setProduct`, `checkData`).

**Gotcha (found during scoping):** core `_assignProducts` uses PRIVATE members —
`recollectQuote`, `config` (Quote\Model\Config), and private helpers `getOptionProductIds()` /
`isValidProduct()`. A subclass can't reach them, so the OS branch must inject `Quote\Model\Config`
itself, manage a local recollect flag, and copy those two small helpers inline. The fallback
branch (`parent::_assignProducts()`) is unaffected — parent uses its own privates.

**Flag-gate it** (e.g. `fastmagento/cart/os_serve_quote_items`, default 0) so every commit is
inert until explicitly enabled — the safe way to land checkout code; enable in dev to measure.

**Shell coverage vs requirements (from the trace) — mostly GREEN (shell is already cart-proven
via getById + PDP add-to-cart):** getId/sku/name/type/status/visibility ✅, `getFinalPrice($qty)`
✅ (per-group catalog-rule pricing built), `getTaxClassId()` ✅ (doc tax_class_id), `getExtension
Attributes()->getStockItem()` ✅, `getTypeInstance()` (checkProductBuyState/getOrderOptions) ✅,
`isVisibleInCatalog()` ✅, configurable child wiring ✅ (Task 2). **Verify/close during build:**
(a) custom options hydration (`getOptions/getOptionById`) for products that actually have them;
(b) downloadable links in cart (95% of the REAL sellable catalog — was ~27 of the cart SQL);
(c) `weight` — only 19/14,604 products have it, low-priority but index for correctness.

**Highest-risk getters to diff vs native (silent wrongness):** `getFinalPrice($qty)` (subtotal),
`getTaxClassId()` (tax read LIVE off product, ignores item copy), `getStatus()` must be ENABLED
(blank = item silently deleted → cart empties), `isVisibleInCatalog()` (else dropped from subtotal
unless super-mode), `getExtensionAttributes()` must be a real object (AddStockItemsObserver +
setProduct call it unconditionally → null fatals the quote load).

**Reassuring:** the REAL stock decrement is shell-independent — MSI's
`AppendReservationsAfterOrderPlacementPlugin` re-derives SKU from `order_item.product_id` via
`GetSkusByProductIdsInterface` and reserves by SKU. A shell can't corrupt the decrement; the only
stock risk is fooling pre-placement `QuantityValidator` via wrong getId()/getStore()/getStatus().

**Staged build (each measured COLD w/ FPC off + db_logger; commit atomically):**
1. Baseline cold cart SQL (simple+downloadable+configurable cart) — measure by driving the browser
   cart (session-bound; curl can't) with db_logger on, count new `## ` log lines.
2. Preference skeleton + flag + hard-fallback passthrough — cart/checkout render IDENTICAL to native.
3. OS-serve the load; measure SQL drop; **diff totals/tax/subtotal byte-for-byte vs native (flag off
   vs on)** on guest AND wholesale group.
4. Close custom-options + downloadable hydration gaps found in step 3.
5. HAND OFF: owner places a real supervised order (guest + wholesale) — tax, totals, options,
   order-item conversion, stock decrement, NO overselling — before enabling the flag for real.

### BUILT + MEASURED (2026-07-21)
Preference `ParkkTech\FastMagento\Model\ResourceModel\Quote\Item\Collection` (frontend-scoped
in `etc/frontend/di.xml`) overriding only `_assignProducts()`; flag
`fastmagento/cart/os_serve_quote_items` (default 0, admin toggle under FastMagento > Cart /
Checkout Optimization). Hard native fallback for: flag off, non-frontend area, custom-option
cart (`option_ids`), bundle/grouped, downloadable without indexed links, any id missing/partial,
or any Throwable.

**Critical fix during build:** `getItemById()` on the in-memory shell collection calls
`getItems()` → `load()` on an unloaded collection → a FULL-CATALOG DB read (via the Webkul
Marketplace `Product\Collection` rewrite) that both defeats the purpose AND collides with the
pre-added shells ("Item with the same ID already exists"). Fix: flag the collection loaded with
a bound closure to the protected `_setIsLoaded(true)` BEFORE `addItem()`, so accessors serve
the shells with zero SQL. Without this the OS branch always threw and silently fell back.

**Measured (CLI harness, db_logger on, per-cart item-collection load SQL; flag off→on):**
- downloadable (guest): **102→66 (−35%)**
- downloadable+virtual (guest): **105→72 (−31%)**
- configurable+simple (wholesale grp 2): 225→198 (−12%)
- configurable+downloadable+simple (guest): 237→201 (−15%)

Totals/subtotal/subtotalWithDiscount/tax/grand **byte-for-byte identical** flag off vs on on
ALL four (incl. wholesale +15% group rule and 10% all-group rule).

**Two findings from the diff:**
1. **Configurable carts save less** — a single query loads ALL ~660 child SKUs
   (`catalog_product_entity WHERE sku IN (…660…)`) during `checkData()`'s configurable
   salability/stock resolution. It fires in BOTH native and OS paths (checkData is identical),
   so it's not introduced here — it's the Phase-B batch-child-loading cost. Serving child stock
   from OS to kill it is a separate follow-up.
2. **Stale-index divergence caught (then fixed by reindex, not code):** downloadable id 1
   (605-JEH-001) diverged +6.50 (OS final 65.00 vs native 58.50) because its doc had
   `catalog_rule_price=[]` / no `catalog_rule_prices` map while the live rule gives grp-0 58.50.
   The serving code faithfully served the stale doc. `ProductIndexer::execute([1])` reprojected
   it (`catalog_rule_prices=[58.5,53.5,49.73,58.5]`) and totals then matched. **Lesson: OS-cart
   correctness depends on a fresh price/rule projection — a full reindex + reconciliation is a
   prerequisite before enabling the flag in prod.** The MSI stock decrement is still shell-
   independent (re-derived by SKU), so a stale doc mis-prices but cannot oversell.

### WALL-CLOCK follow-up (2026-07-21) — query count ≠ latency
Re-measured in **milliseconds** (not query count), warm median of 7, per operation
(item-collection load + collectTotals). Query count was MISLEADING:

| cart | native | OS-served | note |
|------|--------|-----------|------|
| downloadable (guest) | 20 ms | **19 ms** | marginally faster |
| downloadable+virtual | 29 ms | **26 ms** | marginally faster |
| configurable+dl+simple (guest) | 141 ms | 138 ms | parity (now excluded) |
| configurable+simple (wholesale) | 134 ms | 132 ms | parity (now excluded) |

**Key findings:**
1. **OS-serving configurables was a ~2x LATENCY REGRESSION** (fewer queries, ~2x wall-clock):
   ShellProductBuilder recursively builds the parent's ~660 child Product shells, which costs
   far more CPU than the SQL saved. `getUsedProducts()` IS called on the cart parent, so the
   children can't be skipped (skipping → uncached native N+1 = 2,700 ms). → **Configurables/
   bundle/grouped now fall back to native** (isDocServable + a cheap pre-mget `getData
   ('product_type')` skip that avoids even the OS round-trip).
2. **The 660-child loop the owner flagged is Magento MSI, not our layer.** During checkData(),
   `InventoryConfigurableProduct\...\UpdateLegacyStockStatusForConfigurableProduct::beforeSave`
   → `StockStatusManagement::isAnyProductInStock([all 660 children])` recomputes the parent's
   legacy stock status by scanning every child. Confirmed present in the NATIVE path too (not
   our regression). It's a WRITE path (result saved) and only ~2 queries / a few ms — NOT the
   dominant cost — so patching it (via the OS-known parent salability, à la
   CompositeParentStockStatusPlugin) is a deferred, higher-risk item, not done.
3. **The real configurable-cart cost is collectTotals (~110 ms, pure CPU** — 48 tiny queries),
   inherent Magento totals machinery, largely product-type-independent to our layer.
4. **Perf gotcha:** never call `$item->getProductType()` in the pre-mget guard — it lazy-loads
   getProduct() and builds the configurable shell (+90 ms). Use `getData('product_type')`.

Net: simple/virtual/downloadable carts (the real 95%-downloadable catalog) are OS-served and
marginally-to-meaningfully faster (query cut is larger on prod infra with DB network latency);
composite carts stay native with zero regression. Every commit still inert (flag default 0).

### WEBAPI / GRAPHQL checkout extension (2026-07-21)
The frontend cart page was only part of the story — **checkout re-loads the quote-item
collection several times per session via REST** (`totals-information`, `estimate-shipping-
methods`, on every address/shipping edit) in the **webapi_rest** area, which was still native.
Extended OS-serving to the customer-facing checkout areas:
- Guard widened `isFrontendArea()` → **`isServableArea()`** = frontend / webapi_rest / graphql.
  adminhtml (admin order-create) and cron stay native.
- Preference moved from `etc/frontend/di.xml` → **global `etc/di.xml`** (area gated in-class).

**Group-pricing bug found + fixed (was silent overcharge in webapi):** the shell resolved its
catalog-rule group from the **customer session**, which webapi/graphql checkout DON'T populate
(no storefront session) → every logged-in customer priced as guest (group 0). PROVEN: a
Wholesale (grp 2) shopper on product 1 got getFinalPrice **58.50 instead of 49.73**.
Fix: `ShellNoEavProduct::getCurrentCustomerGroupId()` now prefers the group that
`Quote\Item::setProduct()` stamps onto the product (`hasData('customer_group_id')`),
area-independent and authoritative; falls back to the session only for non-quote contexts
(PDP). Frontend unchanged (session group == quote group for a logged-in shopper).

**Validated byte-for-byte** frontend-native (production reference) vs webapi-OS (no session) on
REAL customer carts across groups: grp-1 89884 670.00=670.00, 85548 77.80=77.80, 90424 0=0
(correct — grp-1 rule price is 0 for that product); grp-2 product-1 item price 49.73=49.73.
Guest + frontend unaffected. **webapi item-load SQL: 96 → 63 (−34%)** — now on the checkout
REST path, ×4-6 loads/checkout (bigger on prod infra with DB network latency).
QuoteItemPlugin left frontend-only: the shell's group-correct getFinalPrice makes totals right
in webapi without it (validated).

### REMAINING GAPS after re-profiling (2026-07-21)
Fair WARM (Redis primed = prod-representative) real data-query counts, downloadable cart:
native **29 → OS 19 (-34%)**; dl+virtual 35 → 24 (-31%). Schema introspection (SHOW/DESCRIBE)
warms to 0, so it doesn't inflate the steady state.

FIXED here: **sku→id N+1** — MSI's PreloadCache called `OpenSearchStockRegistry::getStockItem
BySku` once per cart line, each firing a fresh `catalog_product_entity WHERE sku=?`. Added a
per-request sku→id cache (registry is a singleton) → collapses to one lookup per distinct sku.

Of the OS-served ~19, the breakdown of what's LEFT (1-item cart):
- **~10 = the MSI live-stock block** (AddStockItemsObserver bulk + PreloadCache + native
  getStockItem/status/reservation). **INTENTIONAL** — kept native so a stale index can't
  oversell. **This is the single biggest remaining lever (~50%).** Serving it from the indexed
  `extension_attributes.stock_item` (StockSyncer keeps it live) is the next big speedup, but
  it's the deferred correctness/risk call: pre-placement QuantityValidator would read OS stock;
  the real decrement stays SKU-derived reservations at placement (still can't oversell), worst
  case is a rare graceful rejection at the final step. OWNER DECISION — not done here.
- ~2 catalog_product_entity: 1 = core `removeItemsWithAbsentProducts()->getAllIds()` existence
  prune (private in core, spec chose to leave); ~1 misc.
- ~1 catalogrule_product_price, ~1 downloadable_link, and necessary quote_item / quote_item_
  option / tax_class / customer_group reads.

Net: the cheap safe wins are captured; the remaining meaningful headroom is the MSI stock block,
gated on the owner's no-oversell risk call.

### OPTIMISTIC STOCK prototype (2026-07-21) — flag `fastmagento/cart/optimistic_stock` (default 0)
Proven the KEY safety fact: order placement is the sole authoritative stock gate —
`InventorySales\Model\AppendReservations::reserve()` calls `CheckItemsQuantity::execute()`
(line 101) BEFORE committing reservations; it re-checks salable qty BY SKU against live MSI and
THROWS if short. A shell can't fool it → **placement can never oversell regardless of any
pre-placement check.** So the per-load stock validation is UX, not correctness.
Optimistic mode: when OS-serving, skip dispatching `sales_quote_item_collection_products_
after_load` (AddStockItemsObserver + InventoryCatalog\PreloadCache). Shells keep their
StockSyncer-live OS stock_item for cart UX; placement's CheckItemsQuantity stays the gate.
- **Measured (warm, full request load+collectTotals):** downloadable 15→11 (-27%); simple
  2-item 95→88 (-8%). Totals + tax BYTE-FOR-BYTE identical (guest/grp-1; simple w/ stock+tax
  151.93=151.93); shell stock_item populated (qty 184/45), no null fatals.
- **Finding:** naive "skip the preload" alone is modest — the bulk preload is efficient and the
  reads are largely still needed downstream (QuantityValidator at collectTotals). A 2-item
  SIMPLE cart is ~95 full-request queries (vs 15 downloadable): the weight is tax-calculation +
  MSI salable-qty, which optimistic stock only partly touches. Fully collapsing it needs OS to
  serve stock at EVERY read point (QuantityValidator/MSI salable) — a bigger, riskier project.
- Behavioural note: with optimistic on, a limited-stock over-qty line is validated at PLACEMENT
  (graceful rejection) rather than earlier. Default off; opt-in; validate in the supervised
  order test (esp. a deliberately over-qty simple line) before enabling.

Still owner-gated (step 5): real supervised order (guest + wholesale) — order-item conversion,
stock decrement, no overselling — before enabling for real. GraphQL uses the same group-stamp
mechanism but wasn't exercised here (Luma store → webapi_rest is the live path). Harnesses:
`scratchpad/quote-measure.php` (SQL + per-item getters + totals), `quote-profile.php`
(per-table / per-class SQL breakdown). Test quotes: 91291 guest 3-type, 91290 wholesale.

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
