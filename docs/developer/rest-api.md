---
title: REST routes
description: The REST routes Pixonaut for WooCommerce registers.
sidebar:
  order: 6
---

The plugin's routes are under the `pixonaut/v1` namespace, at
`/wp-json/pixonaut/v1/` (or `?rest_route=/pixonaut/v1/` on sites without
pretty permalinks).

<!-- BEGIN GENERATED rest-routes: from the routes registered under pixonaut/v1, run pnpm docs:generate -->

| Route | Methods |
|---|---|
| `/pixonaut/v1` | GET |
| `/pixonaut/v1/admin/blank-fields` | GET |
| `/pixonaut/v1/admin/catalogue-products/blank` | POST |
| `/pixonaut/v1/admin/search/blanks` | GET |
| `/pixonaut/v1/admin/search/catalogue-products` | GET |
| `/pixonaut/v1/admin/search/fitting-templates` | GET |
| `/pixonaut/v1/admin/search/placements` | GET |
| `/pixonaut/v1/admin/search/template` | GET |
| `/pixonaut/v1/admin/search/template-groups` | GET |
| `/pixonaut/v1/admin/search/templates` | GET |
| `/pixonaut/v1/cart/design` | POST |
| `/pixonaut/v1/cart/design-link` | POST |
| `/pixonaut/v1/order-items/(?P<item>\d+)/design` | POST |
| `/pixonaut/v1/order-items/(?P<item>\d+)/design-link` | POST |
| `/pixonaut/v1/order-items/(?P<item>\d+)/edit` | GET |
| `/pixonaut/v1/pictures/draw` | POST |
| `/pixonaut/v1/pictures/now` | POST |
| `/pixonaut/v1/products/(?P<id>\d+)/pictures` | GET, POST |
| `/pixonaut/v1/proof/(?P<design>[A-Za-z0-9_-]{1,64})` | GET |
| `/pixonaut/v1/shop-tools/(?P<tool>[^/]+)` | POST |
| `/pixonaut/v1/storefront/unavailable` | POST |
| `/pixonaut/v1/webhook` | POST |

<!-- END GENERATED rest-routes -->

## Shop tools

`POST /pixonaut/v1/shop-tools/{tool}` is how Pixonaut acts on the store for
Pixel in the studio, while the merchant has **Allow Pixel to make changes to
your store** on. It is authenticated with `Authorization: Bearer <secret>`, the
store-level credential the plugin issues and hands over with
`PUT /v1/connect/store-access` (`Connection\StoreAccess`). Only the
credential's SHA-256 is kept, in the option `pixonaut_wc_store_access`. It is
bound to no WordPress user: a call runs as no user, and is no login anywhere
else in WordPress.

The JSON body is the tool's input, as the ability of the same name takes it,
and a call answers `200 {"result": ...}`. Otherwise `{"code", "message"}`:

| Status | `code` | When |
|---|---|---|
| 401 | `credential_invalid` | No credential, or an unknown or revoked one |
| 403 | `store_access_off` | The switch is off |
| 404 | `unknown_tool` | Not one of the tools below |
| 422 | `invalid_input` | The body is not JSON, does not fit the tool's schema, or names what is not there |
| 422 | `invalid_print_size` | `set-variation-print-size` was given a size that cannot be printed; `reason` gives the code, e.g. `too_large` or `wrong_ratio` |
| 502 | `store_error` | The shop itself failed, or Pixonaut could not give what a tool needed from it |

`ShopTools\ShopTools` is the closed list of tools, separate from the abilities
offered to a user:

| Resource | Tools |
|---|---|
| Products | `list-products`, `get-product`, `create-product`, `update-product`, `delete-product` |
| Variations | `list-product-variations`, `get-product-variation`, `create-product-variation`, `update-product-variation`, `delete-product-variation` |
| Attributes | `list-product-attributes`, `get-product-attribute`, `create-product-attribute`, `update-product-attribute`, `delete-product-attribute` |
| Attribute terms | `list-attribute-terms`, `get-attribute-term`, `create-attribute-term`, `update-attribute-term`, `delete-attribute-term` |
| Categories | `list-product-categories`, `get-product-category`, `create-product-category`, `update-product-category`, `delete-product-category` |
| Tags | `list-product-tags`, `get-product-tag`, `create-product-tag`, `update-product-tag`, `delete-product-tag` |
| Linking a template | `link-product` |
| Blank variants of variations | `set-variation-variants` |
| A variation's print size | `set-variation-print-size` |
| Showcase pictures | `set-product-showcase`, `get-product-picture-views` |
| Orders, read only | `list-orders`, `get-order` |

The product and variation tools answer as the store abilities of the same
names ([Abilities](../abilities/)), and `list-products` gives each product as
`get-product` does: each product and variation read carries
`pixonaut_validity`, and each variation `pixonaut_variant`.

Pixonaut asks for changes to the switch with the webhook events
`store_access.requested`, ignored once it was turned off in WordPress, and
`store_access.off`. The `PUT` answering a request echoes its `requestId`; an
opt-in in WordPress sends none, and always wins. A `requestId` that is no longer
current answers `409 store_access_request_stale` and changes nothing: the
plugin drops the credential it issued for that `PUT`, if it is still the one
kept, and does not try again. A request with no `requestId` is logged and
dropped: answering it would be an opt-in.

`StoreAccess` changes its option with a compare-and-swap on the row, so a cut
cannot be overwritten by a job that read the state before it. A change that
cannot be saved throws: no credential is sent, the webhook job runs again, and
turning it off still tells Pixonaut and shows the admin the failure.

PHP must see the `Authorization` header. Apache's PHP module and hosts with
WordPress's `.htaccess` do; Apache with FastCGI under plain permalinks needs
`CGIPassAuth On`.

Pixonaut builds the route's address from the REST root the store gave when it
connected, so it works under plain permalinks too.

## Proof images

`GET /pixonaut/v1/proof/<designId>?v=<proof_id>&w=<width>[&p=<picture>]&sig=<hmac>`
serves a design's proof: without `p`, the flat proof as a JPEG; with
`p=blank`, the design drawn on its blank's photo as a PNG, in the view its
proof was previewed in (else the placement's first), and with
`p=blank:<viewId>` in that view. Every proof the store shows goes through
it; no Pixonaut image URL is stored or printed.

The route is public, since an email is opened without a session. It serves
only URLs the plugin minted: `sig` is an HMAC-SHA256 of the design id, `v`,
`w` and `p` (a flat proof's leaves `p` out), keyed by
`wp_salt( 'pixonaut_proof' )`, so a link with any part changed answers
`403`. Mint one with `ProofUrls::url()`, passing a `Proofs\ProofPicture` for
the design on its blank:

```php
$urls = Pixonaut\WooCommerce\Plugin::boot( $file )->proof_urls();
$flat = $urls->url( $design_id, $proof_id, 300 );
$shown = $urls->url( $design_id, $proof_id, 300, Pixonaut\WooCommerce\Proofs\ProofPicture::on_blank() );
```

`w` is one of `ProofUrls::WIDTHS` (160, 320, 640, 1280); a width asked for
is rounded up to the next one. On the blank it is the picture's long edge.
`v` is the design's `proofId`, which names
bytes that never change, so the answer carries
`Cache-Control: public, max-age=31536000, immutable`.

The plugin keeps each image it fetched in a transient per design, width and
picture (flat, or on the blank in each view) for a week, and serves it from
there without asking Pixonaut again. When Pixonaut cannot draw the design
on its blank while its proof is there (the view or photo is gone, the
design is on no blank, the drawing failed, Pixonaut could not be reached),
the route answers the flat proof instead with
`Cache-Control: public, max-age=3600`. The store remembers that refusal for
the same hour: every request for that picture meanwhile gets the flat proof
without asking Pixonaut, and the picture is asked for again after it. Each
fetch spends the tenant's render budget, so one request at a time fetches a
given image; another asking for it meanwhile waits up to 15 seconds for it
to land, and answers as the fetching request did when the picture was refused. A design's images are dropped when the plugin deletes the design in
Pixonaut, and all of them when the plugin is uninstalled.

| Answer | When |
|---|---|
| `200` `image/jpeg` | The flat proof, from the store's copy or from Pixonaut |
| `200` `image/png` | The design on its blank, from the store's copy or from Pixonaut |
| `403` `pixonaut_proof_forbidden` | The URL was not minted by this store |
| `404` `pixonaut_proof_not_found` | The design is gone, its proof was released, or it now shows another proof (`v` is stale) |
| `502` `pixonaut_proof_unavailable` | Pixonaut could not be reached, refused for another reason or answered something other than the image (logged), or another request's fetch did not land in time |

Refusals carry `Cache-Control: no-store`.
