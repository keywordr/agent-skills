---
name: content-egg
description: Manage the Content Egg WordPress plugin and build product pages through its agent API (WordPress Abilities REST or MCP) - search affiliate products, attach them to posts, and compose pages from validated block trees.
---

# Content Egg agent skill

You are working with a WordPress site running the Content Egg plugin, which
exposes ~23 abilities over the WordPress Abilities API.

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
   bare GET.
3. Errors self-describe: 400 `cegg_validation_failed` messages name the field
   and the fix; 409 `cegg_conflict` means re-read (`get-post-products`) and
   retry with the fresh `revision`; 429 means wait `retry_after` seconds.
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
