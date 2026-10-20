---
name: content-egg
description: Use when the user wants to manage the Content Egg WordPress plugin or build affiliate/product pages (reviews, roundups, comparisons), including price-comparison pages that match the same product across shops and WooCommerce products, on a site running it - searching products, images, videos or coupons, attaching them to posts, and composing validated block layouts through its agent API (WordPress Abilities REST or MCP).
---

# Content Egg agent skill

You are working with a WordPress site running the Content Egg plugin, which
exposes an agent API over the WordPress Abilities API. The site's own guide is
the single source of instructions; this skill only connects you and says which
part of it to load.

## Connect

Ask the user for: site URL, WordPress username, application password (HTTP
Basic, on every route).

- All-POST, preferred from plain HTTP: `POST {site}/wp-json/content-egg/v1/abilities/{name}/run`
  with body `{"input": {...}}` for EVERY ability, reads included.
- Canonical: discovery `GET {site}/wp-json/wp-abilities/v1/abilities`; run at
  `.../wp-abilities/v1/abilities/{name}/run` - reads are GET with
  `input[key]=value`, writes are POST (a read POSTed here returns 405).
- `{name}` is `content-egg/<ability>`: keep the slash literal, never `%2F`.
- MCP, when the site runs the MCP Adapter plugin: `{site}/wp-json/content-egg/mcp`.

## Ground rules

1. Errors self-describe: 400 `cegg_validation_failed` names the fix; 409
   `cegg_conflict` means re-read and retry with the fresh `revision`; 409
   `cegg_feed_pending` means poll `get-feed-status`; 429 means wait
   `retry_after` seconds; 502 `cegg_search_failed` means the module's API
   failed (bad key, quota) - relay the message, don't retry blindly.
2. `remove-products`, `insert-blocks`, `set-featured-image` and
   `set-post-status` are destructive/outward-facing - confirm with the user
   before calling them, especially publishing, which is hard to undo.
3. Never write back a masked secret value (`••••...`).
4. For a live display use the block in the block editor, or
   `[content-egg-block template=X]` for the classic editor / WooCommerce /
   any non-Gutenberg post type. Never generate bare `[content-egg]` shortcodes.
5. Abilities require a WordPress application password. If the transport has no
   credentials, **stop and ask the user** - never bypass auth by reading
   `wp-config.php`/the database, minting your own application password, or
   calling plugin code directly. A 401/403 means stop and ask.

## Load the guide

1. Call `content-egg/get-status` (it reports `guide_version`). If it reports
   `guide_topics`, continue. If not (an older build), call
   `content-egg/get-guide` with no input - it returns the full guide - and skip
   the topic steps.
2. Call `content-egg/get-guide` with no input: the core guide plus the topic
   index.
3. Call `content-egg/get-guide` with `{"topic": "<name>"}` for the task at hand
   (several if the task spans them) before you act; `"all"` returns
   everything. A loaded topic stays valid while `guide_version` is unchanged.
   The same text is public at
   `GET {site}/wp-json/content-egg/v1/agent-guide?topic=<name>`.

| Topic | Load when |
|---|---|
| `page-building` | Before writing, building or editing any post or page: Egg Blocks vs live blocks, the block palette, node shapes, create-post and insert-blocks. |
| `products-and-modules` | Finding and attaching products: auditing modules, search-products, add-products-to-post, the manual Offer module, editing attached products. |
| `price-comparison` | Comparing one product across shops: finding it in several modules, deciding which offers are the same product, keeping comparison pages current, and linking variant pages (128 GB, 256 GB...) as a family with a variant switcher. |
| `woocommerce` | Working with WooCommerce products: product pages, the price sync setup and the recommended comparison product. |
| `media-and-coupons` | Adding images, videos or coupons to a post. |
| `feeds-and-shops` | Adding a product source: a new affiliate shop (Affiliate Egg) or a product feed. |

## Network failures

If requests fail at the network level - DNS failure, connection refused, a
sandbox policy error - rather than with an HTTP 401/403, your environment may
restrict outbound domains. Ask the user to allow the site's domain (in Claude:
Settings -> Capabilities -> allowed domains), then retry. Don't mistake this
for an auth problem.
