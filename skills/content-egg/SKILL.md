---
name: content-egg
description: Use when the user wants to manage the Content Egg WordPress plugin or build affiliate/product pages (reviews, roundups, comparisons) on a site running it - searching products, images, videos or coupons, attaching them to posts, and composing validated block layouts through its agent API (WordPress Abilities REST or MCP).
---

# Content Egg agent skill

You are working with a WordPress site running the Content Egg plugin, which
exposes an agent API over the WordPress Abilities API. `content-egg/get-status`
reports the live ability count and the guide/OpenAPI URLs for that specific
site - trust it over anything hardcoded here.

## Connect

Ask the user for: site URL, WordPress username, application password.

- Discovery: `GET {site}/wp-json/wp-abilities/v1/abilities` (HTTP Basic auth)
- Execute read abilities: `GET .../wp-abilities/v1/abilities/{name}/run?input[key]=value`
- Execute write abilities: `POST .../wp-abilities/v1/abilities/{name}/run` with body `{"input": {...}}`
- OpenAPI: `GET {site}/wp-json/content-egg/v1/openapi`
- Site-specific guide: `GET {site}/wp-json/content-egg/v1/agent-guide` - READ IT FIRST;
  it carries the canonical block-choice rules and workflows.
- MCP alternative (when the site runs the MCP Adapter): `{site}/wp-json/content-egg/mcp`

## Ground rules

1. Call `content-egg/get-status` first; it reports the build, module count and
   the guide/OpenAPI URLs.
2. Read abilities are GET; write abilities are POST. Empty input is fine on
   bare GET. Exceptions: `validate-blocks` and `preview-blocks` are POST even
   though they have no side effects - their block tree is too nested for query
   params. When unsure of an ability's method, read it from discovery/OpenAPI.
3. Errors self-describe: 400 `cegg_validation_failed` messages name the field
   and the fix; 409 `cegg_conflict` means re-read (`get-post-products`) and
   retry with the fresh `revision`; 409 `cegg_feed_pending` means a feed is
   still importing (poll `get-feed-status`); 429 means wait `retry_after`
   seconds; 502 `cegg_search_failed` means the module's own API failed
   (bad/missing key, quota) - relay the message, don't retry blindly. The site
   guide lists the full set.
4. `remove-products` and `insert-blocks` are destructive - confirm with the
   user before calling them.
5. Never write back a masked secret value (`••••...`).
6. Never generate `[content-egg]` module shortcodes for product display.

## Choosing blocks (summary - the site guide has the full rule)

- Authoring editorial content (reviews, roundups, comparisons where YOU write
  titles/verdicts/copy): use `eggb/*` Egg Blocks. Content is a snapshot you
  own; price/link/stock hydrate live.
- Embedding a live product display (no per-product authoring): use ONE
  `content-egg/products` block. Template ids are unprefixed (`offers_grid`,
  never `data_offers_grid`).
- Prose between blocks: `core/markdown` nodes.

## The page-build loop

1. `content-egg/list-blocks` - catalog + decision rule (fetch one type with
   `input[type]=eggb/faq` for its full attribute schema).
2. `content-egg/search-products` with `fields: "full"` on an active module
   (see `content-egg/list-modules`).
3. Compose the block tree. Typical roundup: intro -> toc -> quick-picks
   (items bind products) -> product-card + pros-cons per product ->
   comparison-table -> faq -> conclusion/verdict.
4. `content-egg/validate-blocks` - iterate until `valid: true`; errors carry
   JSON-pointer paths.
5. `content-egg/create-post` - one call: `{title, status: "draft", blocks,
   products: {ModuleId: [items...]}}`. product_refs resolve against the
   payload by module_id + unique_id.
6. `content-egg/preview-blocks` or open `edit_url`; publish only when the
   user asks (needs publish_posts).

## Finding images, videos and coupons

Beyond products, Content Egg has image, video and coupon modules (see the
`type` in `content-egg/list-modules`). Search them with
`content-egg/search-images`, `search-videos`, `search-coupons` (one active
module of that type). These are **retrieval only**: use the returned URLs or
codes in your copy, or insert the module's `[content-egg module="<id>"]`
shortcode to have Content Egg render them. Images, videos and coupons are
shortcode-based - never Egg Blocks or the products block.

## Custom / manual products (Offer module)

For a product with no feed or API behind it (a hand-picked deal, an unsupported
merchant), use the built-in "Offer" module: `add-products-to-post` with
`module_id: "Offer"` and items you construct yourself - each needs
`unique_id`, `title` and `url` (or `orig_url`), plus optional
`price`/`currencyCode`/`description`/`img`. Offer auto-activates on first use
(no `activate-module` call), and being a product module it renders through the
products block and Egg Blocks like any other. Find it in `list-modules` (it may
be listed as inactive). Affiliate links are applied automatically from the Offer
module's per-domain deeplink rules; the response's `monetization` block reports
how many links were monetized and lists any `domains_without_deeplink` - relay
those so the user can add a deeplink rule in the Offer settings.

## Editing existing posts

`content-egg/get-post-blocks` -> modify the tree (opaque nodes round-trip
verbatim - leave them alone) -> `content-egg/validate-blocks` with `post_id`
-> `content-egg/insert-blocks` (`append`/`prepend`/`at_index`/`replace_all`).

## Worked example: minimal roundup

POST create-post input:

    {
      "title": "Best USB Microphones 2026",
      "status": "draft",
      "blocks": [
        {"type": "eggb/intro", "attrs": {"title": "Best USB Microphones", "body": "We compared the top options."}},
        {"type": "eggb/quick-picks", "attrs": {"items": [
          {"product_ref": {"module_id": "Amazon", "unique_id": "B0EXAMPLE1"}, "label": "Best overall"}
        ]}},
        {"type": "core/markdown", "markdown": "## How we chose\n\nHands-on testing plus spec analysis."},
        {"type": "eggb/faq", "attrs": {"items": [{"question": "Do I need an interface?", "answer": "No - USB mics connect directly."}]}}
      ],
      "products": {"Amazon": [ /* items from search-products fields=full */ ]}
    }

## Module management (admin-capability users)

`activate-module` / `deactivate-module`, `update-module-settings` (partial
patch; get keys from `get-module-settings`), `create-feed-module` (async -
poll `get-feed-status`), `update-settings` for global options.

## Beyond Content Egg (core WordPress)

These abilities cover Content Egg only. The same site + application-password
auth also reach core WordPress abilities (currently minimal) and the full
WordPress REST API (`{site}/wp-json/wp/v2/`) - use it for the rest of the
article. `create-post` returns the post_id that drives them:

- Featured image: upload to `/wp/v2/media`, then set `featured_media` on the
  post (`POST /wp/v2/posts/{id}`).
- Categories/tags: `/wp/v2/categories`, `/wp/v2/tags`, assigned via the post's
  `categories`/`tags` arrays.
- Publish status: `status` (`draft`/`pending`/`publish`) on the post -
  publishing needs `publish_posts`.
- Find content: `GET /wp/v2/search?search=…` or `/wp/v2/posts?search=…`.
