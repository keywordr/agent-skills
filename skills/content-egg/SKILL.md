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
- All-POST alternative: `POST {site}/wp-json/content-egg/v1/abilities/{name}/run`
  with body `{"input": {...}}` for EVERY ability, reads included - same
  abilities, same permission checks and logging. Prefer it when you drive HTTP
  yourself: nothing to serialize into `input[...]` query params, and it is
  unaffected by edge/WAF rules that block parameterized GETs. (Posting a
  read-only ability to the wp-abilities route returns 405 instead.)
- OpenAPI: `GET {site}/wp-json/content-egg/v1/openapi`
- Site-specific guide: `GET {site}/wp-json/content-egg/v1/agent-guide` - READ IT FIRST;
  it carries the canonical block-choice rules and workflows.
- MCP alternative (when the site runs the MCP Adapter): `{site}/wp-json/content-egg/mcp`

If requests fail at the network level - DNS failure, connection refused, a
sandbox policy error - rather than with an HTTP 401/403, your environment may
restrict outbound domains. Ask the user to allow the site's domain (in Claude:
Settings -> Capabilities -> allowed domains), then retry. Don't mistake this
for an auth problem.

## Ground rules

1. Call `content-egg/get-status` first; it reports the build, module count and
   the guide/OpenAPI URLs.
2. On the `wp-abilities` route, read abilities are GET and write abilities are
   POST. Empty input is fine on bare GET. Exceptions: `validate-blocks` and
   `preview-blocks` are POST even though they have no side effects - their
   block tree is too nested for query params. When unsure of an ability's
   method, read it from discovery/OpenAPI. None of this applies on the
   `content-egg/v1` all-POST route, where every ability is a POST.
3. Errors self-describe: 400 `cegg_validation_failed` messages name the field
   and the fix; 409 `cegg_conflict` means re-read (`get-post-products`) and
   retry with the fresh `revision`; 409 `cegg_feed_pending` means a feed is
   still importing (poll `get-feed-status`); 429 means wait `retry_after`
   seconds; 502 `cegg_search_failed` means the module's own API failed
   (bad/missing key, quota) - relay the message, don't retry blindly. The site
   guide lists the full set.
4. `remove-products`, `insert-blocks`, `set-featured-image` and
   `set-post-status` are destructive/outward-facing - confirm with the user
   before calling them (especially publishing, which is hard to undo).
5. Never write back a masked secret value (`••••...`).
6. For a live display use the block in the block editor, or
   `[content-egg-block template="X"]` for the classic editor / WooCommerce /
   any non-Gutenberg post type. Never generate bare `[content-egg]` shortcodes.
7. Abilities require a WordPress application password (HTTP Basic); the server
   enforces it. If the transport has no credentials, **stop and ask the user** —
   never bypass auth by reading `wp-config.php`/the database, minting your own
   application password, or calling plugin code directly. A 401/403 means stop
   and ask.

## Choosing blocks (summary - the site guide has the full rule)

- Authoring editorial content (reviews, roundups, comparisons where YOU write
  titles/verdicts/copy): use `eggb/*` Egg Blocks. Content is a snapshot you
  own; price/link/stock hydrate live.
- Embedding a live display (no per-item authoring): use the matching live block
  — `content-egg/products`, `content-egg/coupons`, `content-egg/images` or
  `content-egg/videos`. Each renders module data attached to the post; template
  ids are unprefixed (`offers_grid`, never `data_offers_grid`). Coupons/images/
  videos have no Egg Blocks equivalent.
- Prose between blocks: `core/markdown` nodes.

## The page-build loop

1. `content-egg/list-blocks` - catalog + decision rule (fetch one type with
   `input[type]=eggb/faq` for its full attribute schema).
2. `content-egg/search-products` with `fields: "full"` on an active module
   (see `content-egg/list-modules`). To compare across networks in one call,
   `content-egg/search-all-products` takes explicit `module_ids` (up to 6) and
   returns results grouped per module (one broken module reports a per-module
   error, not a whole-call failure); results are grouped, not merged.
3. Compose the block tree. Typical roundup: intro -> toc -> quick-picks
   (items bind products) -> product-card + pros-cons per product ->
   comparison-table -> faq -> conclusion/verdict.
4. `content-egg/validate-blocks` - iterate until `valid: true`; errors carry
   JSON-pointer paths.
5. `content-egg/create-post` - one call: `{title, status: "draft", blocks,
   products: {ModuleId: [items...]}}`. product_refs resolve against the
   payload by module_id + unique_id. Pass search-products items unchanged; to
   tidy a source title or add a badge, attach a per-item `overrides` object
   (title, subtitle, description, badge, badge_color, promo) - applied on top
   and kept across price refreshes. Heavy rewriting -> Egg Blocks instead.
6. `content-egg/preview-blocks` or open `edit_url`; publish only when the
   user asks (needs publish_posts).
7. Finish the post: `content-egg/find-posts` (locate a post_id by search,
   status or `has_module`) -> `content-egg/set-featured-image` (an
   `attachment_id`, or an `image_url` e.g. from `search-images`) ->
   `content-egg/set-post-status` (draft/pending/publish/future; schedule with
   status `future` + a future `date_gmt`/`date`). Confirm before publishing.

## Attaching products to an existing post

To add products to a post that already exists - not a brand-new one built via
`create-post`'s combined `blocks` + `products` payload - search first:
`content-egg/search-products` on an active module (see `list-modules`); the
lean default result is enough, no `fields: "full"` needed. When there are
results, the response carries a top-level `search_token`. Attach with
`content-egg/add-products-to-post`, passing that `search_token` plus `items`
as the chosen `unique_id` strings (or `{unique_id, overrides}` per item -
`overrides` tidies a title or adds a badge: title, subtitle, description,
short_description, badge, badge_color, promo). The server attaches its own
stored copy of each result - you never echo item JSON back. The token
expires in ~30 minutes; an expired-token error means re-run the search and
attach again. If the search response has no `search_token`, the site runs an
older Content Egg - fall back to `fields: "full"` and pass items unchanged.
(`create-post`'s `products` payload still takes full items - only the add-*
abilities accept id refs.)

## Finding images, videos and coupons

Beyond products, Content Egg has image, video and coupon modules (see the
`type` in `content-egg/list-modules`). The flow mirrors products: search with
`content-egg/search-images` / `search-videos` / `search-coupons` (one active
module of that type; the lean default result is enough, no `fields: "full"`
needed) -> when there are results the response carries a top-level
`search_token` -> attach with `content-egg/add-images-to-post` /
`add-videos-to-post` / `add-coupons-to-post`, passing that `search_token`
plus `items` as the chosen `unique_id` strings - the server attaches its own
stored copy of each result, you never echo item JSON back (same ~30-minute
expiry and older-CE fallback as products, above) -> render with the matching
live block (`content-egg/images` / `videos` / `coupons`), or
`[content-egg-block]` in a classic-editor post. The block only shows what's
attached — insert-without-attach renders nothing. Each block has its own
template set (`list-blocks` -> its `attributes.template`). You can also use
the returned URLs/codes inline (e.g. a core embed) without attaching.

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

`connect-shop` creates a new affiliate shop module from a domain (needs the
Affiliate Egg plugin): `connect-shop` (`domain`, e.g. "walmart.com") ->
`search-products` (new `module_id`) -> `add-products-to-post` - attach by
`search_token` as described above under "Attaching products to an existing
post". Known shops are searchable at once; a custom domain needs Affiliate
Egg 11.0+ and a `search_url` containing `%KEYWORD%`. The response's
`searchable` flag says whether keyword search will work.

## Beyond Content Egg (core WordPress)

Featured image, publish/schedule and finding posts are first-class abilities
(`find-posts`, `set-featured-image`, `set-post-status`) - use those; they run
through the same channel as every other ability (including OpenAPI/connector
agents). For anything else in core WordPress (categories/tags, excerpt, slug…),
the full WordPress REST API (`{site}/wp-json/wp/v2/`) is reachable when an
authenticated REST transport is available - a direct HTTP client (Claude, Claude
Code, your own script) or an MCP/connector that exposes WordPress core - under
the same application password, no separate auth. (An agent limited to the Content Egg
OpenAPI schema must add those endpoints to its connector first.) E.g. assign
categories/tags via `/wp/v2/categories` + `/wp/v2/tags` and the post's
`categories`/`tags` arrays.
