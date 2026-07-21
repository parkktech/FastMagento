# Search → Variant Swatch Pre-Selection (design)

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
