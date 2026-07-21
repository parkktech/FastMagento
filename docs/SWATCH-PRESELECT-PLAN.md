# Search → Variant Swatch Pre-Selection (design)

> **STATUS: IMPLEMENTED** (query-time approach). See "## Implemented" at the bottom for the
> final design and how it differs from the plan below. No reindex was required and the PDP
> needed no new JS — the native swatch renderer already pre-selects from URL params.


Goal: when a search matches a specific CHILD of a configurable (e.g. "Red 34C bra",
"Red Large Bell Moto 5 helmet"), the result should open the configurable PDP with the
matching swatches + size already selected (correct image/price shown), because a simple
product inside the configurable matched the query.

## Why it's a multi-part build
1. **Children aren't in the native search index.** Magento's fulltext index only holds
   search-visible products (parents). To match a child by its option values (color=Red,
   size=34C) we must make child option data searchable.
2. **Result must carry the selection.** The parent result needs a deep link with the
   child's super-attribute option ids.
3. **PDP must pre-select.** The swatch renderer must read those params and select them.

## Plan
### A. Index child option data on the PARENT doc (indexer)
`ProductIndexer::prepareDoc` already indexes `child_products[]` with each child's
`custom_attributes` (color/size option ids) into `magento2_products`. Add to the doc a
flattened, searchable `variant_search` string per child (name + option LABELS, e.g.
"Red 34C") and a `variants[]` array of {child_id, options:{attrId:optionId}, label,
image, price}. (magento2_products is _source-only today; either map these fields, or run
the match against the parent's indexed super-attribute option labels.)

### B. Detect the matching variant at query time (InstantSearch)
When a hit is configurable, test the query tokens against that hit's `variants[]`
option labels. If a variant's labels are all present in the query (e.g. tokens {red, 34c}
⊆ variant "Red 34C"), attach `selected_options` (attrId => optionId) + the variant image
to the formatted product. Falls back to the parent when nothing matches.

### C. Deep link (formatProduct)
When `selected_options` is set, build the product URL with them as query params:
`/keira-...html?color=86&size=89` (attribute code => option id) and use the variant image
in the result card.

### D. PDP pre-selection (new small JS)
`view/frontend/web/js/swatch-preselect.js` — on the configurable PDP, read the option
query params and, after the swatch renderer inits, trigger clicks on the matching
`[data-option-id]` swatches (color then size). Wire via a mixin on
`Magento_Swatches/js/swatch-renderer` or a `text/x-magento-init` on
`[data-role=swatch-options]`. This updates image/price/jsonConfig via the native renderer.

## Notes
- Option-id ↔ label mapping: pull from `swatch_options` (already indexed) or the attribute
  source; needed to match query words to option ids and to build code=>id params.
- Keep it best-effort: no matched variant → normal parent result (today's behaviour).
- Test products: HER-* configurable bras (color/size), plus create a "Bell Moto" style
  helmet (color/size) for the helmet example.

## Implemented (what actually shipped)

Done entirely in `Model/Search/InstantSearch.php` (the OpenSearch instant-search service that
powers autocomplete + the live results grid). **No indexer change and no reindex** — the match
is derived at query time from data already in each configurable's `_source`; **no PDP JS** — the
native `Magento_Swatches/js/swatch-renderer` already pre-selects from `?code=optId` URL params.

**Deviation from plan A/D:** the plan proposed indexing a `variants[]`/`variant_search` field
(A) and a new `swatch-preselect.js` (D). Both proved unnecessary:
- **A → query-time derivation.** `InstantSearch::deriveVariants()` flattens the parent doc's
  existing `child_products[]` (each child's `custom_attributes[<code>]` = raw super-attr option
  id) + `swatch_options[<attrId>][<optId>].label` + `configurable_options_<id>` (code ↔ id) into
  `[{id,in_stock,image,options:{code=>optId},labels:{code=>label}}]`. It reads a precomputed
  `source['variants']` if a future indexer ever adds one, so it's forward-compatible without the
  doc bloat/reindex today. (The parent configurable is already returned by the native fulltext
  index on a name/token match, so children never needed to become independently searchable.)
- **D → native renderer.** `swatch-renderer.js` `_init` calls
  `_EmulateSelected($.parseQuery())`; `$.parseQuery()` reads `window.location.search` and
  `_EmulateSelected` clicks `[data-attribute-code="<code>"] [data-option-id="<optId>"]`. So a link
  keyed by attribute **code** → option **id** pre-selects with zero custom code.

**B — detection (`matchSelectedOptions`).** Per super-attribute, an option is pinned only when
exactly ONE of its labels is fully present in the query (every label token is a query word).
Ambiguous/unmatched attributes stay open, so "red bra" pins colour alone and "red 34c bra" pins
both. The representative child (matches all pinned options, in-stock preferred) supplies the card
image.

**Synonyms / near-colours (`applySynonyms`).** Query tokens are widened by the admin thesaurus
(`fastmagento/search/synonyms` — the same groups that drive fulltext relevance) BEFORE label
matching, so a shopper's word can pin a differently-named swatch: with a `merlot, burgundy, wine`
group, "burgundy" selects the **Merlot** swatch (verified: `?color=104`); `lavender, purple,
violet` maps "lavender" → Purple. Curating a group is the lever for "close colour" matches. It
stays safe because the "exactly one" rule means a synonym that widens onto TWO real option labels
just leaves the attribute unpinned rather than guessing (so "brown" across Espresso+Cocoa no-ops).
config.xml ships colour-family defaults (merlot/burgundy, navy, blush/rose, ivory/cream,
gray/charcoal, lavender/purple). A future upgrade could add nearest-hex matching for VISUAL colour
swatches (each option's `swatch_options[...].value` is a hex) to auto-handle uncurated colour words
— deferred; the curated thesaurus is deterministic and admin-controllable.

**C — deep link (`productUrl`).** Appends `http_build_query($selectedOptions)` →
`…/keira-…html?color=86&size=90`. `formatProduct` also swaps in the matched child's image and
returns `selected_options` on the product payload (available to the JS grid/autocomplete).

**Verified (local, doc id 4369 "Keira Banded Underwire Bra"):**
- Endpoint `/fastmagento/search/instant?q=…`: "keira black 34ddd" → `{color:86,size:90}` +
  `?color=86&size=90`; "keira black" → `{color:86}`; "keira 34ddd" → `{size:90}`; "keira" → none;
  "keira purple 34ddd" → `{size:90}` (unknown colour word ignored). Simple products → none.
- Browser (native PDP served from OpenSearch): `?color=86&size=90` opens with Black + 34DDD
  selected, price shown, add-to-cart enabled. An out-of-stock combo (`…&size=89`, Black/34DD)
  correctly leaves the greyed size unselected — you can't pre-select an unavailable variant.

**Known unrelated data quirk:** child 3709 (Black/34DD) is `is_in_stock:true` in the OS doc but
renders greyed on the PDP — a stock-state mismatch between the doc and the block's computed
jsonConfig (the stock-reindex staleness already tracked in RESUME.md), not a pre-selection bug.

**Not covered (follow-ups):** the classic server-rendered search results page (this ships on the
instant/autocomplete path only); a "Bell Moto" helmet test product; multi-word colour labels rely
on all words appearing in the query (by design — avoids false positives).
