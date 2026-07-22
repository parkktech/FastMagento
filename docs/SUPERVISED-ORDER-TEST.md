# FastMagento — Supervised Order Test (cart/checkout OS-serving)

Run this before enabling the OS quote-item flags on a real store. It validates that the
OpenSearch-served cart/checkout produces **byte-for-byte correct totals** and that stock
**cannot oversell**. Nothing here is safe to skip — this is the checkout money path.

Two flags (both **default off**, `Stores → Config → FastMagento → Cart / Checkout Optimization`):
- `fastmagento/cart/os_serve_quote_items` — serve cart/checkout line products from OpenSearch.
- `fastmagento/cart/optimistic_stock` — skip redundant pre-placement stock checks (needs the
  first flag on). Placement's MSI `CheckItemsQuantity` stays the authoritative gate.

Helper (READ-ONLY, never places/saves an order):
`php app/code/ParkkTech/FastMagento/docs/tools/cart-verify.php <flags|totals|stock>`

---

## Step 0 — Fresh reindex (HARD PREREQUISITE)

OS pricing is only as fresh as the index. A stale index silently mis-prices (verified: catalog-
rule prices absent from old docs → guest/group price served as plain base price).

```
bin/magento indexer:reset fastmagento_product
bin/magento indexer:reindex fastmagento_product        # ~15 min for ~14.6k products
```

Spot-check a product that has a catalog rule (expect per-group `catalog_rule_prices` present):
`php app/code/ParkkTech/FastMagento/docs/tools/cart-verify.php totals <quoteId>` and confirm the
`final=` per line matches the storefront.

> Until zero-downtime alias reindex exists, a reindex briefly drops the index (transient PDP
> 500s). Reindex in a maintenance window or off-peak.

---

## Step 1 — Totals parity: native vs OS (automated, no order placed)

For each scenario below, run `cart-verify.php totals <quoteId>` **with the flags OFF**, record
the TOTALS line, then **with the flags ON**, and confirm they are **identical to the paisa/cent**.
Any difference is a stop-ship bug (usually a stale doc — re-run Step 0 for that product).

Do this for BOTH areas — pass the area as the 3rd arg (`frontend` and `webapi_rest`):

| # | Scenario | Cart contents | Customer |
|---|----------|---------------|----------|
| 1 | Guest, digital | 1 downloadable | not logged in |
| 2 | Guest, physical + tax | 1–2 simple (taxable) | not logged in |
| 3 | Group pricing | product with a catalog rule | **logged-in Wholesale (group 2)** |
| 4 | Group pricing | same product | **logged-in Retailer/other group** |
| 5 | Mixed | simple + downloadable + virtual | guest and logged-in |
| 6 | Configurable (fallback) | 1 configurable + its child | guest — must render/price identically (served native) |

Gate: **every TOTALS line matches off vs on, in both frontend and webapi_rest, for every group.**
(webapi_rest is the real checkout REST path — do not skip it.)

---

## Step 2 — Live order placement (owner, real checkout)

The CLI cannot place an authenticated, paid order — do these by hand in a browser, flags **ON**.
Place a **real (or sandbox-payment) order** for each and verify:

For each of: (a) guest downloadable, (b) guest simple+tax, (c) **logged-in Wholesale** simple:

- [ ] Cart subtotal/tax/shipping/grand match what Step 1 predicted for that customer/group.
- [ ] Order places successfully; order confirmation shows correct line prices and grand total.
- [ ] `sales_order` / `sales_order_item` rows: unit price, row total, tax, qty all correct.
- [ ] Custom options / downloadable links (if any) carried onto the order correctly.
- [ ] Group price honoured on the PLACED order for the logged-in Wholesale customer.

---

## Step 3 — No-oversell (the critical gate) — required with `optimistic_stock` ON

With optimistic stock on, a low/out-of-stock line is no longer capped in the cart — it must be
**rejected at placement**. Prove it:

1. Pick a **simple** product; note its SKU. Snapshot salable qty:
   `php .../cart-verify.php stock <SKU>`
2. Set its salable qty **low** (e.g. 1) in admin or `bin/magento inventory:source-item ...`.
3. In the browser, add **more than available** (e.g. qty 5) and go to checkout.
   - [ ] With optimistic ON: the cart may *allow* the over-qty line (expected) …
   - [ ] … but **order placement is REJECTED** with an out-of-stock/insufficient-qty error.
   - [ ] No order row is created; **salable qty never goes negative** (re-run the `stock` snapshot).
4. Set salable qty to exactly the requested qty and place again:
   - [ ] Order succeeds; post-order `stock <SKU>` shows the qty **decremented by exactly the
     ordered amount** (not double-decremented, not unchanged).

Gate: **placement rejects the impossible order and stock never goes negative.** This is what
makes optimistic stock safe — placement (`AppendReservations::reserve → CheckItemsQuantity`) is
the authoritative gate; a stale index can at worst cause a graceful rejection, never an oversell.

---

## Step 4 — Concurrency sanity (optional but recommended)

Two browsers, same last-unit item, both to checkout, both place near-simultaneously:
- [ ] Exactly **one** order succeeds; the other is rejected. Stock ends at 0, never negative.

---

## Rollback

Instant and total — set both flags back to **No** (or):
```
bin/magento config:set fastmagento/cart/optimistic_stock 0
bin/magento config:set fastmagento/cart/os_serve_quote_items 0
bin/magento cache:flush
```
The preference/plugins become inert immediately; cart/checkout revert to 100% native Magento.

## Recommended enable order (after all gates pass)
1. `os_serve_quote_items` on, `optimistic_stock` off — measure, watch orders for a day.
2. Then add `optimistic_stock` on — re-run Step 3, watch again.
Enable per-website/store scope first if you can, before default scope.
