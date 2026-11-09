---
title: Abilities
description: The abilities the plugin registers on the WordPress Abilities API, for the MCP Adapter, and WooCommerce's MCP.
sidebar:
  order: 11
---

The plugin registers its abilities on the WordPress Abilities API (core
since 6.9) on `wp_abilities_api_init`, in the category `pixonaut`. Each has
an input and an output JSON schema, is shown in the REST API
(`/wp-json/wp-abilities/v1/abilities`) and is marked public for MCP
(`meta.mcp.public`), so with the
[MCP Adapter](https://github.com/WordPress/mcp-adapter) active an MCP client
lists and runs them like WooCommerce's own. An ability runs as the user the
client signs in as, and checks that user's capability itself.

| Ability | Does | Capability |
|---|---|---|
| `pixonaut-for-woocommerce/list-templates` | Lists the workspace's live templates, by `search`, `page` and `per_page`, with their `total`, each with the id a product is linked with, its aspect ratio and the print sizes the store's products sell it in | `edit_products` |
| `pixonaut-for-woocommerce/list-products` | Lists simple and variable products, in id order, with their Pixonaut setup and each enabled variation's; by `product_id`, `template_id`, `linked_only`, `page`, `per_page` and `variations_page` | `edit_products` |
| `pixonaut-for-woocommerce/link-product` | Links a simple or variable product to one template, optionally on a blank's placement | `edit_product` on that product |
| `pixonaut-for-woocommerce/unlink-product` | Turns personalisation off for a product, keeping its link | `edit_product` on that product |
| `pixonaut-for-woocommerce/set-variation-variants` | Sets which variant of its blank each listed variation of a variable product is printed as, by the variant's options | `edit_product` on that product |
| `pixonaut-for-woocommerce/set-product-setup` | Sets up a simple or variable product, or a variation, as its Pixonaut tab does: blank, sides, settings, views, printshop and blank variant | `edit_product` on that product (and on a variation's product) |
| `pixonaut-for-woocommerce/set-product-showcase` | Sets which of its template's showcase sets a product's images are drawn with | `edit_product` on that product |
| `pixonaut-for-woocommerce/get-product-picture-views` | What a product's or variation's images are drawn on, and the views and photos of its side's placement | `edit_product` on that product (and on a variation's product) |
| `pixonaut-for-woocommerce/set-product-picture-view` | Sets the view and photo one image of a product or variation is drawn on, or flat | `edit_product` on that product (and on a variation's product) |
| `pixonaut-for-woocommerce/move-product-template` | Moves a product and its variations' own setups to another template version, keeping their sides | `edit_product` on that product |
| `pixonaut-for-woocommerce/use-product-picture` | Puts the Pixonaut picture back in place of the store manager's own image | `edit_product` on that product (and on a variation's product) |
| `pixonaut-for-woocommerce/reset-standing-layout` | Undoes the layout adjusted by hand for one option of a standing side | `edit_product` on that product (and on a variation's product) |
| `pixonaut-for-woocommerce/list-personalised-orders` | Lists orders with personalised lines, newest first, with each line's proofs and production status, and their `total`; by `order_id` (with each line's actions), `state`, `per_page` and `before` | `manage_woocommerce` |
| `pixonaut-for-woocommerce/act-on-order-line` | Runs an action the order screen offers for a line: `retry`, `reorder`, `settle_submitted`, `settle_failed`, `cancel`, `rehearse_<status>` | `manage_woocommerce` |
| `pixonaut-for-woocommerce/change-delivery-address` | Changes an order's shipping address and sends it to each line's printshop order | `manage_woocommerce` |
| `pixonaut-for-woocommerce/regenerate-product-pictures` | Draws the Pixonaut pictures of up to 50 products again, as Regenerate in background does | `edit_product` on every product |
| `pixonaut-for-woocommerce/recheck-products` | Clears the cached catalogue and re-checks every linked product, as Recheck products does | `manage_woocommerce` |
| `pixonaut-for-woocommerce/get-plugin-settings` | Every setting on the Pixonaut settings page, by its key | `manage_woocommerce` |
| `pixonaut-for-woocommerce/update-plugin-settings` | Changes the settings given, as saving the settings page does | `manage_woocommerce` |
| `pixonaut-for-woocommerce/get-connection-status` | Whether the store is connected, to which workspace and environment, whether the connection is paused, its origins, the last webhook test and the workspace's brands and settings | `manage_woocommerce` |
| `pixonaut-for-woocommerce/choose-brand` | Chooses the workspace brand the store's pages are drawn in | `manage_woocommerce` |
| `pixonaut-for-woocommerce/allow-www` | Allows the site's `www.` counterpart on the publishable key, or takes it away | `manage_woocommerce` |
| `pixonaut-for-woocommerce/ping-webhook` | Sends the store's webhook endpoint a test request | `manage_woocommerce` |
| `pixonaut-for-woocommerce/forget-copied-connection` | Forgets the connection a copied site was copied with (`site_changed`) | `manage_woocommerce` |

The registration is `Pixonaut\WooCommerce\Abilities\Abilities`; each ability
is a class beside it.

## Store management

In a second category, `pixonaut-store`, the plugin registers abilities that
manage the store's own things, beyond WooCommerce's product and order
abilities (`Abilities\Store`). Each runs through the resource's REST route
(`rest_do_request()`) as the signed-in user, after checking the capability
WordPress or WooCommerce checks for that kind of thing; the route then
checks the item itself. A list answers `{items, total, pages}` and takes the
route's query parameters as `query`; a create takes `fields`, an update the
item's `id` and only the `fields` to change, a delete the `id` and `force`.
A nested resource also takes its parent's id (`product_id`, `attribute_id`,
`zone_id`, `group_id`). A shipping zone's id, as `id` or `zone_id`, may be
`0`: WooCommerce's zone for the locations no other zone covers. Answers leave
out the routes' `_links`.

| Resource | Route | Operations | Capability |
|---|---|---|---|
| `product` | `wc/v3/products` | get, update | `read_private_products`, `edit_products` |
| `product-variation` | `wc/v3/products/{product_id}/variations` | list, get, create, update, delete | `read_private_products`, `publish_products`, `edit_products`, `delete_products` |
| `product-category`, `product-tag`, `product-brand` | `wc/v3/products/categories`, `tags`, `brands` | list, get, create, update, delete | `manage_product_terms`; update `edit_product_terms`; delete `delete_product_terms` |
| `product-attribute` | `wc/v3/products/attributes` | list, get, create, update, delete | `manage_product_terms` |
| `attribute-term` | `wc/v3/products/attributes/{attribute_id}/terms` | list, get, create, update, delete | as categories |
| `page` | `wp/v2/pages` | list, get, create, update, delete | `edit_pages`; delete `delete_pages` |
| `post` | `wp/v2/posts` | list, get, create, update, delete | `edit_posts`; delete `delete_posts` |
| `menu`, `menu-item` | `wp/v2/menus`, `wp/v2/menu-items` | list, get, create, update, delete | `edit_theme_options` |
| `media` | `wp/v2/media` | list, get, create, update, delete | `upload_files` |
| `coupon` | `wc/v3/coupons` | list, get, create, update, delete | `read_private_shop_coupons`, `publish_shop_coupons`, `edit_shop_coupons`, `delete_shop_coupons` |
| `shipping-zone` | `wc/v3/shipping/zones` | list, get, create, update, delete | `manage_woocommerce` |
| `shipping-zone-location` | `wc/v3/shipping/zones/{zone_id}/locations` | list, update (replaces the list with `items`) | `manage_woocommerce` |
| `shipping-zone-method` | `wc/v3/shipping/zones/{zone_id}/methods` | list, get, create, update, delete | `manage_woocommerce` |
| `order` | `wc/v3/orders` | get, create, update, delete | `read_private_shop_orders`, `publish_shop_orders`, `edit_shop_orders`, `delete_shop_orders` |
| `settings-group` | `wc/v3/settings` | list, get (the group's settings) | `manage_woocommerce` |
| `store-setting` | `wc/v3/settings/{group_id}` | get, update | `manage_woocommerce` |

An ability is named `<operation>-<resource>`, a list and a replacing update
by the plural (`list-product-categories`, `update-shipping-zone-locations`,
`delete-coupon`). Reads are `readonly`; creates are not `destructive`,
except a coupon's and an order's; updates and deletes are. A media file is created from `fields.source_url`, which the store
downloads with `wp_safe_remote_get()` (no address on its own network), up to
the upload size limit. `pixonaut-for-woocommerce/describe-store-resource`
gives a resource's writable fields, from its route's own schema (an
`OPTIONS` request), so a create uses the fields this store takes. A create's
and an update's description name it by the tool the MCP Adapter serves it
as, `pixonaut-for-woocommerce-describe-store-resource`
(`Abilities::tool()`).

Each declares an output schema: a list's `{items, total, pages}`, or the
route's answer. `list-product-variations` and `get-product-variation` add
`pixonaut_variant` to each variation, the variant of its blank it is printed
as, read from what is stored without asking Pixonaut: `ref`, `label`,
`problem` and `picked` as `list-products` gives a variation's `variant`
(`ref` null and `problem` `unmapped` when it sells none), or null when the
variation is not personalised on a blank. Dates in an answer are RFC 3339 with their offset: a field
ending in `_gmt` in UTC, any other in the site's timezone, where the route
answers them without one.

`update-product` is how a product's `attributes` (global and custom, with
`visible`, `variation` and `options`), `default_attributes` and `type` change,
which WooCommerce's own `woocommerce/product-update` does not take. A
variation's `attributes` naming one its product lacks is dropped by the route,
and a variation whose attribute its product no longer has offers any value, so
do not take a variable product off an attribute this way: use
`move-product-attribute`.

## Moving products to a global attribute

`move-product-attribute` moves products from a custom attribute (`from`, by
name or slug) to a global one (`to`, e.g. `pa_size`), whose terms exist first:
a value matches a term by name or slug, or through `term_map`. For each
product it writes, in one database transaction: the attribute, keeping its
position, visibility and use for variations; the default choice; each
variation's value (an "Any" variation stays one); and the form arrangements
(`formGroups`) naming the attribute. A customer sees the product before or
after, never part-way, and if a write fails, or reads back other than written,
the product stays as it was. Saving the product and then its variations cannot
give that. The transaction locks the product and works the move out again as
the product stands then, so a second call or an edit since the first look is not
overwritten. Variations in the trash move too, and stay in the trash. Each
variation keeps its id, price, image and `_pixonaut_config`. Pixonaut is not asked and no picture
is drawn: a picture's source names no attribute, and the re-check that follows
a save is skipped because the move refuses a variant it would change (below).
The move queues no picture job and no re-check, also not for a picture or
variant already due for another reason: those wait for the next change. The
sync of the product's price and stock with its variations, which WooCommerce
leaves for the end of the request, runs inside the transaction.

`product_ids` names up to 50 products, and `per_page` is at most 50; without it, the call walks the store
by `page` and `per_page` and answers `more`, listing only products with one
attribute or the other. Each product answers its `status`: `moved`,
`would_move` for a `dry_run`, `already_moved` (so a call can run again),
`not_on_product`, or `failed` with a `reason` and `message`, leaving that
product as it was while the others go ahead:

| `reason` | Meaning |
|---|---|
| `target_on_product` | The product has both attributes |
| `too_many_variations` | The product has more than 500 variations, more than one transaction writes |
| `ambiguous_values` | Two values have the same slug (`A B` and `A-B`), so a variation's value could mean either; rename one |
| `missing_terms` | A value has no term; create it or name it in `term_map` |
| `duplicate_terms` | Two values would become one term |
| `unknown_value` | A variation holds a value the attribute does not offer |
| `blank_variant` | A blank's variant is matched by the attribute's words, and the move would change them, which would stop it selling; pick the variant first |
| `not_saved` | A write did not land, or the product changed so it no longer has the attribute; all were undone |
| `save_failed` | A write failed unexpectedly, and all were undone; the error is in the log (WooCommerce > Status > Logs) |
| `not_allowed`, `not_a_product` | The user may not edit it, or it is not a simple or variable product |

A `dry_run` writes nothing, and with a move answers `before`: a record of each
product's prior state, to keep. It holds the attributes, default choice and
variations' values, the raw `_pixonaut_config` of each product or variation whose
arrangement the move renames (`setup_documents`, by id), and the earlier note of
moves (`moved_attributes`).

There is no undo ability, and nothing reads `before` back. Going back means
`update-product`, `update-product-variation`, and `set-product-setup` with the
sides' `form_groups`.

The product keeps a note of the move (`_pixonaut_moved_attributes`), so what
still names the old attribute goes on selecting the same value:

- A product page link with `attribute_<old>=` redirects (301) to the same link
  with `attribute_<global>=<term slug>`.
- WooCommerce removes a cart line when its hash of the variation's attributes
  changes. That hash is kept in the old form, each variation's value spelled as
  it was stored, so carts, saved ones too, keep
  their lines, and each line's size is rewritten to the new attribute when it
  is read.
- An order line on an "Any" variation finds its value under the old name for
  the link that corrects it.

An add-to-cart form opened before the move and submitted after it names the old
field: WooCommerce asks the customer to choose the size again.

## Listing templates

`list-templates` gives each template's `background_see_through` (`true` when
its background shows through, as matters on a product without a blank; `null`
when Pixonaut does not know), its `ratio` (`width` to `height`, as Pixonaut
gives it) and `print_sizes`: the sizes, in millimetres and with their
labels, that the store sells the template in: from the configuration that
applies to each published product and enabled variation linked to it, when
that configuration personalises with the template. A link kept
after unlinking, or a variation's own configuration while it follows its
product's, does not count. A new product on a
template, or on another of the same ratio, can take a size the store already
uses rather than asking for one.

A page holds up to `per_page` templates (20 by default, at most 100);
`total` counts them all, as Pixonaut counts them (null when it did not say),
and `more` says whether a later page has more.

## Listing products

`list-products` gives what WooCommerce's own product abilities leave out:
how each product is set up for Pixonaut, read from what the store keeps
(`_pixonaut_config`, `_pixonaut_variant`, `_pixonaut_picture`); Pixonaut is
not asked. Per product: its `price`, `sku`, `image` URL and
`permalink`; whether it is `linked` to a template (a link kept
after unlinking counts, with `personalised` false), whether Pixonaut found
a problem (`valid`, `invalid_reason`), whether customers can buy it
(`purchasable`), the `blank_id` and the blank `variant` it sells, its
`layout`, `form_layout`, `option_groups`, `group_numbers` and `approval_mode`
overrides, its
`canvas_ratio`, the designer's
`views` and `initial_view`, its `printshop_id`, its `sides`, the
`showcase_images` its pictures are drawn with, and its `picture`:
`drawn`, `pending`, `failed`, `own_image` (the store manager's image is
kept), `none` (nothing to draw it from) or `off` (pictures are not product
images, or it is not personalised). A side is given as `set-product-setup`
takes it (`key`, `label`, `mode`, `template_id`, `template_pinned`, `placement_id`,
`print_sizes` in millimetres, `start_size`, `form_groups`, `canvas_ratio`), with its
`template_name` and `placement_name`, and, for a standing side on a blank
whose print areas change with an option, its `standing_options`: the
design each option is printed from and whether its layout was adjusted.
A size Pixonaut has not checked yet is given as entered; `placement_default`
marks the placement's default size. A variable product gives
its enabled variations 50 at a time, in the order customers see them, each
with `own_setup` and the setup that applies to it: its own, or its
product's; `variations_total` counts them, `more_variations` says whether
a later `variations_page` has more, and its own `variant` is null, as each
variation sells its own. A stored variant of another blank, or one matched
on attributes the product no longer has, is given as none matched
(`problem` `unmapped`).

`template_id` takes a template's id with or without its version; products
linked to any version of it match, on their own configuration or a
variation's. `template_id` and `linked_only` list only linked products. A
page holds up to `per_page` products (20 by default, at most 100);
`total` counts them all and `more` says whether a later page has more.

To make a product like an existing one, an agent reads the existing one here
and links the new one with its template, size and blank.

## Linking and unlinking

`link-product` takes `product_id`, `template_id` (as `list-templates` gives
it, e.g. `mini-tee@2`), `blank_id` and `placement_id` for a product printed
on a blank, and a print size in `width_mm` and `height_mm`. It saves the
product as the product screen saves one with a single side, in that size or,
without one, the placement's default size. A product without a blank has no
placement to take a size from: it needs `width_mm` and `height_mm`, which
Pixonaut checks against the template's aspect ratio. Pixonaut checks the link,
the `pixonaut_wc_catalogue_product_config` filter runs, the blank variant is
resolved, and a product that fails a check is saved but cannot be bought.
The answer gives the setup saved, as `list-products` gives it (the
template's name as Pixonaut gives it, the blank `variant`, the `picture`),
with `valid` and `invalid_reason`. `purchasable` says
whether customers can buy the product now, and `variant_issue` which of its
variations sell no variant of the blank, whose `variant` `set-product-setup`
sets. The link replaces the
product's sides; its layout, form layout, approval mode and views are kept.
A product with several sides, print-size menus or standing designs is set
up with `set-product-setup`.

`unlink-product` turns personalisation off, as unticking it on the product
screen does. The template and blank are kept, so linking again restores them.

## Setting a product up

`set-product-setup` saves what the Pixonaut tab of a simple or variable
product, or a variation's Pixonaut panel, saves, through the same code
(`ProductPanel::configure()`, `VariationPanel::apply()`), so Pixonaut checks
it, the filters run and the blank variant is resolved as on the screen. A
field left out keeps what the product or variation has, so a client reads
the setup with `list-products` and sends what changes:

- `personalised`; left out, it is turned on when `sides` are given.
- `blank_id`, empty for a product without a blank.
- `sides`, replacing the ones it has, each with `template_id`, and
  optionally `key`, `label`, `placement_id`, `mode` (`personalised` or
  `standing`), `print_sizes` (`width_mm`, `height_mm`, `label`, or
  `placement_default`), `start_size` (by its place in `print_sizes`) and
  `form_groups` (the arrangement `list-products` gives) and `canvas_ratio`
  (below, empty for the product's choice). A
  `template_id` is a version (`mini-tee@2`) or a template's id alone
  (`mini-tee`), for its live version, which Pixonaut's list of live
  templates gives. Either way the side follows the template's latest
  published version from then on, unless `template_pinned` is true: the
  side then stays on that version.
- `layout`, `form_layout` and `approval_mode`, empty for the store's.
- `option_groups` (`on_top`, `first`, `last`) and `group_numbers` (`on`, `off`),
  empty for the store's; a product's only, and refused on a variation.
- `canvas_ratio`, the shape customers design on when a print size's
  proportions are not the template's: `template` keeps the template's
  shape at every size, `print_size` changes shape with each size, and
  empty follows the template's own setting. A side's own comes first.
- `views` and `initial_view`, the designer's views of the blank.
- `printshop_id`, empty for the workspace's default.
- `variant`, the blank variant a simple product or a variation sells
  (`v_…`), empty to match it by options.

A variation given any setup field gets a setup of its own, starting from its
product's; `own_setup` false makes it follow its product again, keeping its
own unused. Its `variant` can be set either way; left out, a variant picked on a blank
it is no longer on is matched by options again.

`set-variation-variants` sets the variant of several variations of one
variable product at once, by the variant's options rather than its ref:
`variations`, 1 to 100 entries of `variation_id` and `options`, the variant's
option values by axis as Pixonaut writes them or as the variation's Pixonaut
panel shows them in the site's language, every axis of the variant and no
other, case and spacing aside (`{}` for a blank without options). Each is the
pick the variation's panel saves, on the blank the variation is sold on (its
own setup's, or else its product's), and the product's variant notice is
noted again. Every variation is checked before any is saved: one that is not
the product's, is not personalised on a blank, names no variant, several, or
a retired one, or is listed twice refuses the whole call, naming it, and so
does a blank whose variants Pixonaut could not give. The answer gives
`purchasable` and `variant_issue` as `link-product` does, and `variations`,
every variation of the product with its `variant` as `pixonaut_variant`
gives it.

`move-product-template` moves every side on `template_id` (any version), on
the product and on each variation with a setup of its own, when it is
personalised (a link kept while personalisation is off is left as it is), to
`to_template_id`, a version, which the sides then stay on, or a template's
id for its live version, after which they follow its latest version, or
another template. Everything else the side has is kept, and each setup is
saved and checked again; when one cannot be worked out, nothing is saved.

`set-product-showcase` sets the showcase sets of the product's template its
images are drawn with (`images`: `id` and `kind`, `mockup` on the blank's
photo or `preview` as the flat design; only `preview` without a blank),
up to 10, replacing the ones it has. It refuses a set the template does not
offer and lists the ones it does; the answer gives them in
`available_showcases`. The pictures are drawn again once the choice is saved.
A set still drawn on the photo keeps the view and photo chosen for it.

`get-product-picture-views` reads what a product's or variation's images are
drawn on: `picture_view`, the image drawn while no set is chosen (on a
variation, its own choice; null for none), and `showcase_images`, each set
chosen, each with `kind`, `view`, `photo` and `gone` (the blank no longer
has the view or photo, so the image is drawn as without a choice); and
`views`, the views of the first side's placement with their photos (`id`,
`thumbnail_url`, `variant_refs`), scene photos included, or null when
Pixonaut could not be asked. `set-product-picture-view` sets one of them:
`showcase` names a set chosen on a product (left out, the image without a
set), `view` and `photo` one of those listed (a photo's view is found when
left out; neither is the photo the product page starts on), or `kind`
`preview` the flat design. On a variation it sets the variation's own view
and photo, neither making it follow its product again; a variation is not
drawn flat. It refuses a view or photo the blank does not have, naming
those it has, and answers as `get-product-picture-views`. The pictures are
drawn again once the choice is saved.

`use-product-picture` puts the drawn Pixonaut picture back as the image of
a product or variation whose own image the store manager set in place of
its picture (`picture` `own_image`), as Use the Pixonaut picture on the
product screen does, or removes the image until one is drawn; it refuses any
other, whose image the picture takes once drawn. `reset-standing-layout` undoes a hand-adjusted layout of one
`standing_options` entry of a standing side, as Reset beside it does.

Each write answers with the setup saved, as `link-product` does, and
`own_setup` for a variation.

## Personalised orders

`list-personalised-orders` reads each line as the store keeps it, which
Pixonaut's events keep in step ([Webhooks](../webhooks/)). A line gives its
`state` and its label as the order screen shows it, why it waits when it
does, the Pixonaut order and that order's last reported
`production_status`, where its last change of delivery address stands
(`delivery`), and per side the design, the proof picture's URL on the store
(signed, as the order screen's), and whether the customer approved it.

A page holds up to `per_page` orders; `total` counts every order that
matches. The next page is asked for with the answer's `next_before`; `more`
is false on the last, and only the last page is short. A line's state is
the one the order screen shows, so `needs_attention` also lists a line whose
stored document this version cannot read.

With `order_id`, the answer holds that order alone, and each line gives the
`actions` it offers now, read as the order screen reads them: Pixonaut is
asked for the line's printshop order when the actions depend on it. Without
`order_id`, Pixonaut is not asked and `actions` is null.

### Acting on a line

`act-on-order-line` takes `order_id`, `item_id` and one of those `actions`,
and runs it through the order screen's own code (`LineActions`), with the
same order notes and line bookkeeping: `retry` places the printshop order
again (Pixonaut's retry of a failed order, or the store's own next attempt
at a line that never had one), `reorder` orders it although its design has
a live order the line did not place, `settle_submitted` and `settle_failed`
say whether the printshop has an order whose outcome is unknown (`reason`,
what the printshop said, is required; `printshop_ref` is the printshop's
reference), `cancel` asks the printshop to stop it, and `rehearse_queued`,
`rehearse_printed`, `rehearse_shipped` and `rehearse_failed` move a sandbox
order on. What the line offers is read again first, so an action Pixonaut
no longer allows is not sent. It answers `result` (`done`, or
`retry_still_pending` when the new order is not on its way yet) and the line
as it stands now; anything else is an error whose code is
`pixonaut_wc_line_<result>` (`not_available`, `reason_missing`,
`status_not_allowed`, `reference_taken`, `line_changed`, `unreachable`,
`not_scheduled`), or Pixonaut's own code for a refusal. An `item_id` that
is not a personalised line of that order is `pixonaut_wc_line_not_found`.

### Changing the delivery address

`change-delivery-address` takes `order_id` and the shipping `address`
fields to change, and saves the order as its screen does: each line with a
printshop order gets the new address ([How orders flow](../../merchant/how-orders-flow/#shipping-address)),
and a change Pixonaut never answered, or that the store could not send, is
sent again; with no fields, only that. `sending` lists the lines whose
change is scheduled (a line whose printshop order has the address already,
or is being told it, is not listed); each line's `delivery` says what came
of it once Pixonaut answered. An order none of whose lines has a printshop
order that can take a new address is refused
(`pixonaut_wc_delivery_not_sendable`) and left unchanged. When the address
was saved but a line's change could not be scheduled, the error is
`pixonaut_wc_delivery_not_scheduled`, with `saved` true and the lines'
`item_ids`; saving the order on its screen, or running the ability again,
sends it.

## Errors

When Pixonaut could not do what an ability asked, the error's code is
`pixonaut_` and Pixonaut's code (or the store's own: `not_connected`,
`transport_failed`, `unexpected_response`), its message names the cause
(the store's key no longer accepted, the workspace suspended, too many
requests, Pixonaut unreachable or failing) and ends with the code, and its
data holds `code`, the HTTP status Pixonaut answered with as
`pixonaut_status` (0 when no answer came), whether asking again may work
(`retryable`) and, for too many requests, `retry_after` in seconds.

Every ability error's data has a `status`, the HTTP status the ability's
REST run route (`/wp-abilities/v1/abilities/<name>/run`) answers with:

| Status | Errors |
|---|---|
| 400 | `pixonaut_wc_line_reason_missing` |
| 404 | `pixonaut_wc_line_not_found` |
| 409 | `pixonaut_wc_line_not_available`, `status_not_allowed`, `reference_taken`, `line_changed`; `pixonaut_wc_delivery_not_sendable` |
| 500 | `pixonaut_wc_line_not_scheduled`, `pixonaut_wc_delivery_not_scheduled` |
| 502 | Pixonaut refused or answered with a failure that asking again will not mend |
| 503 | Pixonaut unreachable, the store not connected, or a failure that asking again may mend (`retryable`) |
| 504 | `pixonaut_wc_line_unreachable` |

A number in the input may also come as a string of digits, as the REST API
passes the input of a run sent as `GET` or `DELETE` (the read abilities, and
the write ones, which are marked destructive and idempotent).

## Connection

`get-connection-status` reads the stored connection: the workspace's name
and id, `live` or `sandbox`, when it was connected, and why it is paused
(`unauthorized` or `site_changed`), if it is. It also gives the origins the
publishable key takes, the site's `www.` counterpart and whether it is
among them, the last webhook test (`last_ping`), and, while the connection
works, the workspace's settings as the connection card shows them
(`workspace_settings`: `brands`, `brand_id`, `chosen_brand_id`, …), read
from `GET /v1/connect`. It never gives a key.

`choose-brand` (`brand_id`, null for the workspace's default), `allow-www`
(`allow`, true by default) and `ping-webhook` act as the connection card's
buttons do, and refuse while the store is not connected or the connection
is paused. `forget-copied-connection` runs only while the connection is
paused for `site_changed`: it forgets the copied keys without revoking them,
so the site it was copied from stays connected. Each answers with the
connection as `get-connection-status` reads it. Connecting and connecting
again go through the studio in a browser, and have no ability.

## Settings

`get-plugin-settings` gives every setting on the Pixonaut settings page,
by the key `pixonaut_wc_settings` stores it under ([Settings](../settings/)),
as the page reads it; its output schema gives each one's type, allowed
values and description. The developer tools' addresses are left out.
`update-plugin-settings` takes any of them and changes only those, in one
write of the option, so what follows a change (the product pictures drawn
again, or the images put back) runs once. A value a setting does not take
is refused, rather than stored as the default; a custom accent colour needs
`designer_accent_colour`, as on the page.

## Pictures and the catalogue

`regenerate-product-pictures` takes `product_ids` and draws each one's
pictures again through `RegeneratePictures::redraw()`, which the product
screen's Regenerate in background button and the products list's bulk action use. It refuses the
call, drawing nothing, when one of them shows no Pixonaut picture. Each
product's `state` is `drawing`, `drawn`, `kept` (the store manager's own
image), `failed` or `error`.

`recheck-products` clears the cached catalogue answers, then queues the
re-check of every linked product, as Recheck products in the developer
tools does, so a template published or a blank changed in Pixonaut reaches
the products. It refuses while the store is not connected or the connection
is paused.
