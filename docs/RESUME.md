# FastMagento — RESUME HERE (fresh-session pickup)

Start here, then read **`docs/ARCHITECTURE.md`** (canonical how-it-works: interception points, file
responsibilities, gotchas, dormant code). `README.md` is the user-facing doc. `git log --oneline`
tells the detailed story.

---

## Environment (unchanged)

- **Branch** `feature/fastmagento-opensearch-layer`. Remotes: `origin` = diy-offroad repo,
  `fastmagento` = the module repo (git@github.com:parkktech/FastMagento.git).
- **On the 500k scale DB** — `app/etc/env.php` `dbname=diyscale_db`, `opensearch_index_prefix=scale`,
  **production mode**. Restore prod when scale testing is done:
  `cp app/etc/env.php.diyprod-backup app/etc/env.php && php bin/magento cache:flush`.
- Storefront `http://www.diyoffroad.loc/`. Admin `newadmin` / `FastMag2026!` at `/admin_3xo245`.
- Compile: `bash bin/magento-compile-safe` (PHP 8.3 segfault-safe). `opcache.validate_timestamps` is
  **on** → edits to existing classes go live without a compile; new classes / DI / constructor changes
  need a compile.
- MySQL CLI: regenerate a `[client]` defaults file from `app/etc/env.php` (host/user/password).
- **Breeze is INSTALLED + ON** (`design/breeze/enabled=1`) layered on the Hos/diy Luma theme.
- **StripeIntegration_Tax is DISABLED** — this store uses native Magento tax (verified: CA 7.75%,
  order tax correct). Leave it disabled. ⚠️ **Stripe upgraded v14→v20** during the Breeze install —
  **smoke-test a real Stripe payment on HTTPS before prod** (Stripe.js won't init over local HTTP).

## Routing rule (IMPORTANT — see memory [[branch-routing-rule]])

**FastMagento module code** (`app/code/ParkkTech/FastMagento/`) → the `fastmagento` repo via subtree
split → **PR #6** (`feature/opensearch-serving-layer`). Ask before pushing to `fastmagento` master.
**Everything else** — `Jadog_*` modules, the Hos/diy theme, `composer.json/lock`, root docs → the
**DIY branch** (`origin`). Never let Jadog/store code leak into the module subtree.
To sync PR #6: `git subtree split --prefix=app/code/ParkkTech/FastMagento -b fastmagento-sync` then
`git push fastmagento fastmagento-sync:feature/opensearch-serving-layer` (fast-forward).

---

## What shipped in the Breeze arc (all committed + pushed to `origin`; module commits synced into PR #6)

**Store-side (DIY branch):**
- **Breeze installed** — `swissup/breeze` (v2.31, free/public from Packagist+github) + `swissup/module-marketplace`,
  enabled on Hos/diy. `composer.json/lock` committed (carries the Stripe v20 bump). Recipe + caveats in
  repo-root **`BREEZE_SETUP.md`**. See memory [[breeze-compatibility]].
- **Jadog_Marketplace** — memoized Webkul `Helper\Data::getSellerCollectionObj` (marketplace_userdata N+1 → 0),
  on top of the earlier SellerIdAttribute source-model fix.
- **Jadog_Stripe** (module) — skips subscription resolution + option reads when subscriptions are OFF
  (checkout `getProduct` ×32 / `getSubscriptionOptionDetails` ×16 / ReadHandler ×8 → gone). Proven required.
- **Jadog_StructuredData** — batched breadcrumb category loads (per-id → 1 collection).
- **Hos/diy theme** — lazy-load carousels site-wide (`breeze.lazyImages` xpaths) + point `breeze.criticalImages`
  at the real hero LCP (`app/design/frontend/Hos/diy/Swissup_Breeze/layout/breeze_default.xml`).
- ⚠️ deploy note: `app/etc/config.php` is gitignored → on deploy run
  `bin/magento module:enable Jadog_Marketplace Jadog_Stripe`.

**FastMagento module (→ PR #6):**
- **Breeze compatibility** — `view/frontend/layout/breeze_default.xml` registers the module for Better
  Compatibility (reuses existing web/js + requirejs-config; inert under Luma). README documents it.
- **Swatch media 500 fix** — `ShellNoEavProduct::getMediaGalleryEntries()` coalesces null→[] (was 500ing
  `swatches/ajax/media` on every swatch select, Luma too).
- **Search page** — merge compare + wishlist into the single layered-nav rail (`instant-search.js` relocates
  them into `.fm-sidebar`, survives re-render; CSS hides the emptied native column).
- **Admin settings UX** — hide the implied "Advanced" Fast Checkout toggles while the master is on; drop the
  vestigial (never-read) indexing toggles.
- **Efficiency Monitor — logged-in profiling** — auto-provisions a profiler customer, logs it in over HTTP
  (form-key → cookie jar), re-runs PDP/PLP/home + `/customer/section/load` through that session; findings
  tagged guest|logged_in.
- **Efficiency Monitor — clear/restore** — per-row "Clear" dismisses a fixed hotspot (persisted in
  `var/fastmagento/efficiency-dismissed.json`, stable-hash keyed, survives re-scans); "Restore N cleared"
  brings them back; guest/logged-in badges on each row.

## Verified this arc

- **Breeze end-to-end works**: home / search / autocomplete / instant SERP + live facets / PDP + swatches +
  media / add-to-cart / cart / checkout. Placed guest order **#100006123** (checkmo, $58.19, CA tax $4.19).
  Zero console errors across the flow. **Luma fallback** works (`design/breeze/enabled=0`).
- **FPC caching**: home/PDP/category warm **~0.23s** vs cold ~1.67s (≈7×). Cart ~0.67s (uncached — cart/checkout
  are never FPC-cached by design). The Monitor cache-busts (`fmcb`) to measure the **cold** render, so FPC
  hits aren't counted — FastMagento's numbers reflect the cache-MISS + personalized + cart/checkout paths.

---

## OPEN — needs the user / next session

1. **Breeze paid-tier upgrade (real TurboLinks)** — BLOCKED. Free core 2.31 has no turbo (`$.breeze.visit`
   is a stub that full-reloads). Licensed repo is `ci.swissuplabs.com`; the keys return **401** — the license
   needs the **`www.diyoffroad.loc` domain activated** in the Swissup account
   (`swissuplabs.com/license/customer/activation/`), then
   `bin/magento marketplace:auth:key:add swissuplabs`. User action.
2. **Stripe v20 HTTPS payment smoke-test** before this reaches prod.
3. **PR #6** (parkktech/FastMagento#6) is in sync through the latest module commit. Packagist publish = user action.
4. **Restore prod DB** when scale testing is done (command above).

## Next perf-fix candidates (from the latest logged-in scan, ranked) — DiyOffroad side

1. ✅ **DONE — Webkul `Cart::getSectionData` ×4 (quote_address)** turned out to be a **Monitor false positive**,
   not a real N+1. Verified with stacktrace db-logging: the queries are pure **core Magento** (Webkul only
   overrides `getRecentItems`; `getSectionData` is inherited). The "×4 + writes" only appears under the Monitor's
   **headless curl session**, which re-bootstraps the checkout quote (`Checkout\Model\Session::getQuote` →
   collectTotals+save → quote_address INSERTs) on every hit. A **real logged-in browser** does **1 legit
   address read, 0 writes, 0 churn** (proven via Playwright). Fixes shipped:
   - **Monitor (→ PR #6)**: (a) `ModuleAttributor` now blames the class that *declares* a method, not a
     subclass that inherits it (kills the whole "3rd-party rewrite owns core's method" false-positive class —
     also fixed a stray `getIdentities` mislabelled Webkul→Mageplaza); (b) `PageProfiler` drops queries fired
     inside the checkout-session quote bootstrap (`isCheckoutSessionBootstrap`). Re-scan: the customer_data /
     quote_address row is gone; real findings (Webkul `Context::aroundDispatch`, Mageplaza, Jadog SD) intact.
   - **DIY branch**: small `Jadog\Marketplace\CustomerData\Cart` preference — `isGuestCheckoutAllowed()` reuses
     the already-memoized quote instead of re-asking the session (correct/harmless; marginal on healthy sessions).
2. ✅ **DONE — Webkul `Context::aroundDispatch` ×3 (catalog_category_entity)** was **also a Monitor
   false positive** (same family). The plugin only stamps `customer_id` into the http context and returns
   `$proceed($request)` — it loads no categories. Verified by trace: 15/22 `catalog_category_entity` queries
   on a category page had `aroundDispatch` as their **only** non-core frame = core menu/breadcrumb/layered-nav
   loads it merely *wraps*. Fix (Monitor → PR #6): `ModuleAttributor::WRAPPER_METHODS` treats request-lifecycle
   wrapper plugins (`around/before/afterDispatch`, `around/before/afterLaunch`) as transparent for
   attribution — a query whose only 3rd-party frame is such a wrapper is core work, attributed to nobody.
   Genuine callers appear deeper and still win (mega-menu `loadTree`, Jadog `BreadcrumbSchema` unaffected).
   Re-scan: the aroundDispatch row is gone; Mageplaza + Jadog SD findings intact.
3. **Mageplaza Productslider loops** (`toHtml` ×12 downloadable_link, `getProductParentIds` ×11,
   `getProductCollection` ×5, `getIdentities` ×5) — vendor-internal bestseller-slider render. Only fixable by
   plugin-ing/replacing Mageplaza; the lazy-load already mitigates the front-end cost.
4. **Jadog StructuredData `productRefs` ×6 (url_rewrite) / `getFormHtml` ×4 (rating)** — scale-DB artifacts
   (served from `url_path` on prod's indexed catalog) / core review form. Not worth patching.

Workflow: run the Monitor scan → read the hotspot row → build the Jadog_* fix → re-scan to watch the loop
drop → Clear the row once fixed.

---

## Hard-won lessons (apply these)

- Magento plugins do **not** intercept internal `$this->` calls → use a **preference** (subclass) for those.
- Instance-property memoization fails on re-instantiated EAV source models; it **works** on singleton helpers
  (that's why the Webkul `Helper\Data` fix is a preference, and the SellerIdAttribute one short-circuits).
- Admin routes carry a `/key/<secret>/` URL param named **`key`** — never name a form field `key` in an admin
  controller (it gets shadowed by the 64-char secret). Use e.g. `fm_key`.
- Breeze **Better Compatibility** reuses the module's existing `web/js` + `requirejs-config.js` and runs them
  with real jQuery; the `breeze_default` layout handle is **inert under native Luma**, so it can't break Luma.
- Breeze free core 2.31 has **no turbo** and **no per-image lazy** by default — lazy-loading is via the
  `breeze.lazyImages` block xpaths; critical/LCP preload via `breeze.criticalImages` xpaths.
- Production static changes: clear `var/view_preprocessed/*` to force re-minification, and bump
  `pub/static/deployed_version.txt` **without a trailing newline** (a stray `\n` lands inside a body
  `data-mage-init` JSON and breaks Breeze's `JSON.parse`).
- The Efficiency Monitor cache-busts (`fmcb`) to measure the **cold** render on purpose; FPC hits run no PHP,
  so nothing is (or should be) attributed on a hit.

## Related docs / memory

- `docs/ARCHITECTURE.md` — how it works (canonical). `README.md` — user-facing.
- Repo-root `BREEZE_SETUP.md` — Breeze install recipe + Stripe-upgrade caveat + verification checklist.
- Memory: [[branch-routing-rule]], [[breeze-compatibility]], [[fastmagento-opensearch-work]],
  [[fastmagento-scale-testing]], [[fastmagento-search-optimization-layer]].
