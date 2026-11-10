---
title: Product page
description: The inline designer's markup, template overrides, and its JavaScript hooks and events, and the Personalise button's round trip to the hosted designer.
sidebar:
  order: 8
---

On a product that personalises inline (see the merchant guide's
[Product page layouts](../../merchant/layouts/)), the plugin prints two templates and loads one script and one
stylesheet. A product on the Personalise button layout gets the button
described at the end of this page. Nothing is printed or loaded for any other
product.

## Markup

| Template | Printed | Holds |
| --- | --- | --- |
| `storefront/preview.php` | In place of the gallery: the classic `woocommerce_show_product_images`, or the output of the `woocommerce/product-image-gallery` or `woocommerce/product-gallery` block (product from the block's `postId` context) | `.pixonaut-preview` with a `.pixonaut-preview__frame` per side holding `#pixonaut-preview-<side>`, a `.pixonaut-spinner[role=status]` over it (label `designer_loading`), the mark target `#pixonaut-mark-<side>` under it and, on a blank, the views strip `#pixonaut-views-<side>`; then `p.pixonaut-preview__note` (label `preview_note`). See [Server-rendered layout](#server-rendered-layout) |
| `storefront/form.php` | At `woocommerce_before_add_to_cart_button`, inside the add-to-cart form | `.pixonaut-form` with `#pixonaut-form-<side>` per side, the mount options in `data-pixonaut-mounts`, the blank variant in `data-pixonaut-variant` (see [Views and variants](#views-and-variants)) or, on a variable product, `data-pixonaut-variations` (see [Variations](#variations)), a `.pixonaut-form__placeholder` (`storefront/form-placeholder.php`) in each `#pixonaut-form-<side>` and the server's reads in `script.pixonaut-reads` (see [Server-rendered layout](#server-rendered-layout)), and a hidden `pixonaut_designs[<side>]` field per side; in the checkbox approval mode, `.pixonaut-approval` with the `pixonaut_approve` box (`.pixonaut-approval__box`) in its `label` a `.pixonaut-approval__proof` button per side (`data-pixonaut-side`), the label's linked word (a label that links none has them after it instead, under a block theme with its button class, as the side tabs) and an empty, hidden `p.pixonaut-approval__error[role=alert]` (see [Checkbox approval](#checkbox-approval)); with more than one side, the side tabs (see [Sides](#sides)); the theme presets in `data-pixonaut-look` (see [Theme look](#theme-look)) and, on a page printed at its opening variation's price, the product's own price in `data-pixonaut-product-price` and the attribute values the printed price is for in `data-pixonaut-opening-choice` (see [Price](#price)) |

Override either by copying it to `yourtheme/woocommerce/pixonaut/storefront/`.
Keep the ids and the fields: the script mounts each side's preview into
`#pixonaut-preview-<side>` and its form into `#pixonaut-form-<side>`, and
writes each side's proof token into `pixonaut_designs[<side>]`, the field
add to cart reads. A theme with no gallery to replace gets the preview above
the form, and the views above the form too.

### Server-rendered layout

The page is printed at the layout the designer ends up with, so it does not
move when the designer starts. For each side the server reads what the SDK
opens with (`GET /v1/templates/{id}`, and with a blank
`GET /v1/templates/{id}/constraints`, `GET /v1/blanks/{id}/mockups` and
`GET /v1/blanks/{id}/variants`), with the integration key, cached for five
minutes like the product screen's catalogue reads. The template is read in
the mount's `locale` (`?lang=`), else in the workspace's default language,
as the designer would show it. The blank's reads name its options and views
in the designer's language (`?lang=`: the mount's `locale`, else the
template answer's `workspaceLanguage`, else the workspace's default
language as the store last read it). From them it prints:

- the preview at the aspect ratio of the photo the side opens on (each
  photo's `width` and `height`, for the product's variant and starting view;
  the template's ratio on the Design view or without a blank, the design's
  `printSize` on a page that edits a design, a square while none is known), set as `--pixonaut-preview-ratio` and
  `--pixonaut-preview-aspect` on `.pixonaut-preview__frame`;
- the views strip, under a `.pixonaut-views__count` heading counting the
  views, a `.pixonaut-views__view` per view of the side's placement the
  product is shown in, and the Design view, each a square 64px across on a
  row up to 520px wide and 84px on a wider one, as the SDK draws them; the
  row scrolls sideways once it is full. Only photos the
  SDK can draw count, those with a `mount`: a view whose photos are still
  uploading, or have no print area marked, is left out of the strip and of
  the starting ratio;
- the form as the SDK draws it, from `storefront/form-placeholder.php`, a
  side's arrangement (`formGroups`) applied: its groups renamed and in its
  order, as `ArrangedSteps` works them out; the
  print size picker (with more than one size, unless the side mounts with
  `showPickers: { size: false }`: see
  [Views and variants](#views-and-variants)), the steps, and each field as
  a `.pixonaut-placeholder__field` with the control the SDK picks for it in
  `data-pixonaut-type`, drawn as the SDK draws it, in its colours and at its
  size: `image` (the upload button, or the picture tiles with the upload
  tile after them, "No picture" first when the picture may be left out),
  `crop` (the zoom slider, Move picture and Reset), `tiles` (a font's, a
  fit's or a shape's choices, in `data-pixonaut-tiles`, "None" first for a
  shape the author allows none of), `select` (a menu holding every offered
  value with the default selected, or a stand-in for the SDK's drawn list of
  more than eight fonts or shapes),
  `gradient` (a colour control per colour along it), `color` and `palette`
  (round swatches, the chosen entry's name beside the label, and Custom…
  with the customer's own colour where the palette offers any colour),
  `number` (a slider at the default), `text` and `text-lines` (a box
  holding the default text, with the character count and its limit), and
  `scale` and `move` for a layer's size slider and the hint to drag it,
  which the SDK adds. The words the SDK writes in its own format (a
  slider's value and ends, but for a crop's zoom, and a CMYK colour's hex)
  are left out until it does; the rest are in the designer's language (the
  mount's `locale`, else the template's workspace's default language),
  whatever the page's. The form in steps prints the stepper on its first
  step, Back and Next, with the short counter ("1 of 2") and no step names
  where the stepper is narrower than 320px, as the SDK draws it; one that
  opens on the product's options (see
  [Options and the cart row in the form](#options-and-the-cart-row-in-the-form))
  prints that step, titled Options, and its print size picker alone.
  The side's accent colour, when the store sets one, is in
  `--pixonaut-accent` on `#pixonaut-form-<side>`.

A theme can hold the preview at a fixed height by setting
`--pixonaut-preview-height` on `.pixonaut-preview` (or any ancestor): the
SDK then draws the preview fitted and centred inside it, and switching
view, variant, side or photo never changes its size. Without it the
preview takes the photo's aspect ratio at the full width, at most as wide
as keeps its picture in the window: from 768px wide, `.pixonaut-preview__frame`
is held to the width that makes the picture as tall as the window below
`--pixonaut-preview-top` (where the script measures the preview starts, at
load, on resize and whenever the page's box changes, such as a web font
above it loading), less 24px and at least 320px, and centred; under
768px, to the width that makes it 80vh tall, so a 5:7 design spans a phone's
width, and only a taller one narrows. Under 768px the preview stays at the top
of the screen (`position: sticky`) only while the whole `.pixonaut-preview`
takes at most 45% of the screen's height, which the script measures and marks
with `data-pixonaut-pinned`, against what an open keyboard leaves of the screen
(the visual viewport) too; a taller one scrolls away with the page, so the
form stays reachable with the keyboard open. The views strip under it may
run below the fold. Both take the shape the SDK drew once it has, which the
script sets as `--pixonaut-preview-drawn-aspect` on the frame and follows as
a view or variant of another shape is shown (not under a theme's
`--pixonaut-preview-height`, where the SDK fits every photo in one box). A side on no blank
(`data-pixonaut-shape="template"` on its `.pixonaut-preview__frame`) has no
photos and keeps its template's aspect ratio either way: under a fixed height
it is as wide as that height takes at the template's ratio, at most the full
width, and centred. A variation that moves the side onto no blank marks the
frame so, at its starting print size's ratio, and one that moves it back onto
a blank gives it the shape the page printed. Where the workspace shows
"Powered by Pixonaut", the SDK draws it into `#pixonaut-mark-<side>`
(`.pixonaut-preview__mark`, the designer's `markTarget`), under the
preview's element and outside any border or shadow a theme gives it:
unless the template read's `poweredBy` is `false`, the frame is marked
`data-pixonaut-mark` and the mark target keeps the mark's 26px
(`--pixonaut-preview-mark`) from the first paint. A theme's copy of the
template from before 0.5.0 has no mark target: the script makes one under
the side's `.pixonaut-preview__stage` when it mounts. Every height above counts
the mark in, so the picture is that much shorter. Once the preview is
drawn, the script marks or unmarks the frame by whether the SDK drew the
mark, where the page could not read the template and guessed. The views strip
keeps `--pixonaut-views-gap` (12px by default) above it, the SDK's own
custom property, which the printed strip follows too.

The answers go into the form as `script.pixonaut-reads` (JSON by side key,
each answer as Pixonaut sent it), stamped with when the page was made in
`data-pixonaut-read-at`. Where the SDK's global has `createDesigner`, the
script mounts each side from those reads with `createDesigner({
previewTarget, formTarget, viewsTarget, template, placement, blank, values,
ui })` (with `markTarget` too), which makes none of those reads again. SDK 0.7.0's script exposes
every export on its global; on 0.6.0's, which has no `createDesigner`, the
split `mount()` reads them in the browser. An SDK before 0.6.0 would ignore the form and views
targets of `createDesigner` rather than refuse them, so it is never used
there. Either way, each of the three targets holds a placeholder (the
preview's is an empty `.pixonaut-preview__placeholder`), so the SDK draws
into hidden layers and replaces them once it can: the form as soon as it is
built from the template, ahead of the preview (SDK 0.16.0 is the first to do
so), the preview and the views strip when the preview shows its photo and
design. An empty preview target would be
drawn into directly, its photo and credit moving as they load. A failed
mount leaves the placeholders in place.

Reads more than 30 minutes old, as a full-page cache can serve, are left to the browser too: the photo links in them expire. When a
read fails, the side is printed without them, and product pages make no
reads for a minute, so an outage does not hold every page up.

The spinner shows until the side's design is drawn, when the script marks
its `.pixonaut-preview__frame` `is-drawn` (once the SDK has replaced the
preview's placeholder, after the mount resolves), or its mount failed (or the SDK
did not load, which marks every side). A drawn side's preview drops the
printed ratio, so what the SDK draws sizes it from then on. While an add
to cart is under way, its button carries `.pixonaut-busy`, `aria-busy` and
`aria-disabled`, and is disabled once the form is sent on.

### Sides

A product with more than one side gets side tabs at the top of the form: a
`.pixonaut-tabs` element with `role="tablist"` (named by the `sides` label)
and a `.pixonaut-tabs__tab` button per side, `#pixonaut-tab-<side>`, named
after the side and carrying `data-pixonaut-side`. Each `#pixonaut-form-<side>`
is its tab's `tabpanel`. Every part of a side carries `data-pixonaut-side`:
its form, its preview and, in the checkbox approval mode, its proof button.
The script shows the selected side's parts and sets `hidden` on the others';
the templates print the first side shown and the rest hidden. The tabs
follow the WAI-ARIA tabs pattern: the arrow keys, Home and End move between
them and select the tab they reach, and only the selected tab is in the tab
order. Under a block theme the tabs carry its button class
(`wc_wp_theme_get_element_class_name( 'button' )`, `wp-element-button`), so
they and the proof buttons are drawn as the theme's buttons rather than left
to the browser.

Every side mounts its own designer, and add to cart approves each one. When
a side cannot be approved, nothing is sent, the form shows `design_failed`
and that side's tab is selected; a side approved meanwhile keeps its
approval until its design changes. The line in the cart holds one design
per side, and the printshop order carries one print per side, the first
side's leading.

A design refused for its fields shows `design_fields_to_change` instead,
with `%s` replaced by the labels of the fields to change, each once, joined
for the side's locale ("Photo, Name and Class"). The SDK refuses it before
sending anything when a field that must be changed still holds its default,
or a required field is empty, and Pixonaut refuses it with 422
`constraint_violation` for what the SDK could not tell; either way `approve()`
rejects with a `PixonautApiError` carrying `violations`, and the SDK marks
each field in the form. The fields are named by `getFieldTexts()`, as the
form heads them; a refusal naming no field the form heads shows
`design_failed`.

A page shows one inline designer. The first personalised product whose
preview or form is printed claims the page; the script looks up each form
target inside that form and each preview target inside the
`.pixonaut-preview` of the same `data-pixonaut-product`. A form for another
personalised product on the same page (a second Single Product block, a
theme printing another form) shows `personalisation_unavailable` with no
targets, and a further gallery, of any product, is left as it is.

The preview is replaced only while the product can be personalised. When it
cannot (no connection, a paused one, a product flagged invalid), the gallery
stays and the form shows the `personalisation_unavailable` label.

The SDK loads when the designer comes within 200 px of the viewport, or on
the first pointer, focus or key event in it, from
`<CDN>/sdk/<version>/pixonaut.iife.js` with `integrity`, where `<CDN>` is
`https://cdn.pixonaut.app` unless the CDN base is set
([developer tools](../developer-tools/#pixonaut-addresses)),
`crossorigin="anonymous"` and no query string. See
[Content Security Policy](../csp/).

## Product pictures

While the `product_pictures` setting is on (the default), a personalised
product's or variation's image is its Pixonaut picture: a media-library
attachment written to the product's own `_thumbnail_id`, so anything that
reads the image shows it, `get_post_thumbnail_id()`, the featured-image
block, `wp_get_attachment_image`, the Store API and feeds alike. It is drawn
in the `pixonaut_wc_catalogue_picture` Action Scheduler job, from
`GET /t/{id}/mockup.png` (the first side's template on the first view of its
placement the product is shown in, with the variation's blank variant) or
`GET /t/{id}/preview.png` for a product with no blank. Both take the store's
publishable key, sent with the site's origin. The product keeps the image it
has until the job ran, and while Pixonaut cannot draw it: one
`pixonaut_wc_catalogue_picture_retry` job per product queues it again after
an hour. One job draws a product at a time; a product that changed while it
drew is drawn again after it, and the picture drawn for what it was is not
set.

A product can show its template's showcase sets instead of its defaults:
`_pixonaut_showcase_images` on the product (JSON, a list of `{id, name,
kind, view?, photo?}`, `kind` `mockup` or `preview`), written by the Pixonaut tab's
**Product images** field from the sets `GET /v1/templates/{id}` lists as
`showcases`. Each set is one picture, drawn with `showcase=<id>`: the first
is the product's image, the others lead its gallery
(`_product_image_gallery`), before the store manager's own gallery images,
which are kept; a variation's image is drawn with the product's first set. A
`404 showcase_not_found` draws that picture without `showcase`, and the set
is recorded as gone in `_pixonaut_picture` for the field to say so. The
designer never receives a showcase set.

What a picture is drawn on can be chosen: a set's `view` and `photo`, and
`_pixonaut_picture_view` (JSON, `{kind, view?, photo?}`) on the product for
the picture drawn without a set and on a variation for its own, written by
the **Product images** field, a variation's **Variation image drawn on** and
`set-product-picture-view`. The ids are a view and a photo of the first
side's placement as `GET /v1/blanks/{id}/mockups` lists them. A chosen photo
is drawn with `mockupId` (no `viewId` or `variant`); a chosen view alone
with `viewId` and the variant sold. A variation without its own choice
follows its product's: the photo when its `variantRefs` are empty or name
the variation's variant, else the same view with its variant. A view or
photo the blank no longer lists draws the picture as without a choice.

A product is queued when its configuration (`_pixonaut_config`), its variant,
its image, its showcase sets or the view and photo chosen change, whether through the meta API or a direct write that
clears the post's cache, when its picture is deleted from the media library
(a configured variable product's variations, which share its configuration,
are checked in a `pixonaut_wc_catalogue_pictures_variations` job), and,
through the `pixonaut_wc_catalogue_pictures_queue` job paging through
every configured product, when the setting changes and after any
`blank.updated` or `blank.restored` event, since a photo or print area can
change under the same ids.

The first picture replaces whatever image the product had, which is
remembered in `_pixonaut_previous_image` (an attachment id, `0` for none).
After that a picture replaces only an empty image or an earlier picture, so
an image the store manager sets is kept, and no picture is drawn while it
is. When the product is no longer personalised, loses its configuration, or
the setting is turned off, the remembered image is put back, unless the
store manager set another one since.

The attachment is recorded in the product's `_pixonaut_picture` meta, and
carries `_pixonaut_picture_of` with the product's id; only such an
attachment is deleted, when the product is no longer personalised, is
deleted, or gets a new picture, or when the setting is turned off.
Uninstalling the plugin puts each product's remembered image back before it
deletes the pictures.

| Template | Printed | Holds |
| --- | --- | --- |
| `admin/product-picture.php` | In a personalised product's **Product image** box, and among a personalised variation's options, while the setting is on | `p.pixonaut-picture.pixonaut-picture--<state>` (`picture`, `own` or `waiting`); beside an own image, `a.pixonaut-use-picture` with `data-picture` (the drawn picture's id, `0` for none) and `data-picture-url` |

## Cart

A personalised cart line's first side's design, drawn on its blank,
replaces the product image (`woocommerce_cart_item_thumbnail`, and
`woocommerce_store_api_cart_item_images` for the Cart block, Mini-Cart and
Checkout block, one image per side). It is drawn in the view the **Picture
shows** setting (`line_picture_view`) names: the one the customer previewed
the design in (`previewed`), or the first of the side's placement's views the
product is shown in and its variant has a photo in (`first`). A product on no
blank shows the flat proof. The line's details
(`woocommerce_get_item_data`) list its [field values](#field-values) and no
row per side. The picture is drawn at `woocommerce_thumbnail`'s
width, which `pixonaut_wc_cart_proof_width` filters; every URL is the
plugin's [proof route](../rest-api/#proof-images).

An order line shows the same picture: under its name on the order received
page and in My Account (`woocommerce_order_item_meta_start`, in the classic
templates and the Order Confirmation block), and in place of the product
image in the order emails (`woocommerce_order_item_thumbnail`), the
fulfilment emails' items tables included. An HTML email whose order lists a
personalised line shows images (`woocommerce_email_order_items_args`, or
`woocommerce_email_fulfillment_items_args` in a fulfilment email), so every
line in it has its image.

While a line waits for the customer's approval after payment
([Proof by email after payment](../../merchant/approval-modes/#proof-by-email-after-payment)),
the order pages also link, under its picture, each proof page still waiting:
Pixonaut's hosted page for the design, the address `POST /v1/share-links`
answered, on the workspace's custom domain when it has one. No order page or
email links the proof's image itself.

| Template | Printed | Holds |
| --- | --- | --- |
| `cart/proof-thumbnail.php` | As the line's image in the classic cart and mini-cart | `img.pixonaut-proof-thumbnail` with a `srcset` over every served width |
| `order/line-picture.php` | Under an order line's name on the order pages, and as its image in order emails | `img.pixonaut-line-picture` with a `srcset` over every served width |
| `order/proof-links.php` | Under the line's picture on the order pages, while the line waits for the customer | a `p.pixonaut-proof-links` per waiting proof page, its link's text the `review_proof` label |

A line added before the plugin kept proof ids keeps the product image, and
so does a line whose design's personal data was erased; an order line then
shows no picture on the order pages.

### Field values

The line's details hold one row per field of each side's design, with the
field's label and the value the customer chose, so they can check it before
paying and find it again in the order. When the add-to-cart form is
submitted, the script reads each approved side's form
(`getFormSchema()` and `getValue()`) and posts the values beside its proof
token as `pixonaut_fields[<side key>]`, a JSON list of
`{label, type, value}`. The line keeps them, and the order line copies them
(`fields` on the side, in the line's state). An update from the cart or from
a proof page posts the side's values again, in `fields`, and they replace the
old ones.

| Field type | Shown as |
| --- | --- |
| `text`, `number` | The value, in full, escaped, its line breaks kept |
| `select` | The chosen option's label, else its value |
| `color` | A spot colour's name, else `#rrggbb`, else its CMYK percentages (`C0 M100 Y100 K0`) |
| `image` | `field_image` ("Image"); never the picture's address |

A crop or an opacity places the design and is not listed, nor is a field
left empty. A field whose template gives it no label is named by its type:
`field_text`, `field_choice`, `field_number`, `field_colour` or
`field_image` ([label keys](../label-keys/)).

In the cart, the checkout and the Mini-Cart a row is
`array{key: string, value: string, display: string}`: `key` is the field's
label, `value` its text, `display` escaped HTML, in
`woocommerce_get_item_data`, filterable with `pixonaut_wc_cart_item_display`.
A side whose design's personal data was erased lists one row instead, by the
side's name, saying the personalisation was removed.

Under an order's item (My Account, the order received page, order emails and
the admin), each is a formatted meta entry at index
`pixonaut_field_<side>_<n>` (`<n>` its place among the side's rows, from 0),
with the label as `key` and `display_key` (escaped) and the value as `value`
and `display_value` (escaped). They come before the line's state (on the
admin order screen only), tracking and removal rows, filterable with
`pixonaut_wc_fulfilment_line_display`. The admin order screen hides the line's
own meta (`_pixonaut_line`, `_pixonaut_order_id`, `_pixonaut_design_id`)
through `woocommerce_hidden_order_itemmeta`. Themes
style them as any WooCommerce item detail.

A line added before field values were kept, or through the
[Personalise button](#personalise-button)'s hosted designer, lists none; a
correction made in the hosted designer drops the side's values, which may no
longer be the design's.
Neither does a side whose design's personal data was erased: its values are
dropped with it, from the order line at once and from a cart as WooCommerce
next loads it. A change made in Pixonaut to a design not yet approved drops
its values too, as they describe the revision the customer approved.

## Editing from the cart

A personalised line of a product on the inline layout can be edited on its
product page ([Editing a design from the cart](../../merchant/cart-editing/));
one on the Personalise button layout on Pixonaut's hosted correction page
([Corrections on the hosted page](#corrections-on-the-hosted-page)).
Its product link (`woocommerce_cart_item_permalink`, which the Cart block and
Mini-Cart honour too) gets `?pixonaut_edit=<cart item key>`. An add from the
product page's form lands there for the line it added
(`woocommerce_add_to_cart_redirect`), unless the store redirects to the cart
after adding.

On that page, when the key names a line of the product in the customer's
cart:

- The server mints a correction link per side the line has (on a
  variation's line, named as in the variation's configuration)
  (`POST /v1/designs/{id}/correction-links`, valid 2 hours) and mounts it as
  `{correctionLink, design, apiBase, locale}` (and `layout` when the product
  sets a form layout), with no publishable key or template. It reads the
  design (`GET /v1/designs/{id}`) and, while the design is still what the
  side sells (its template version, blank, placement and a size on the
  menu), adds the product page's `blankId`, `placementId`, `placements`,
  `views`, `initialView` and `variants` (the line's variant), so the SDK
  draws it on the blank's photos with the same view strip and reads the
  blank, its placement's constraints and colour tables through the link;
  the container names the line's variant as on the product page. The page
  prints the strip of the blank's views as the product page does, from the
  blank's views read on the server (`ServerReads::correction_views()`), which
  are not passed on to the SDK. No
  `printSizes`: the SDK keeps a correction in the box frozen with the design,
  at the size it was bought in, and shows no size menu. A design no longer
  sold, or one Pixonaut does not answer for, opens on the design alone. So
  does a side whose mount the SDK refuses with a `TypeError` (the design was
  made on another blank or placement since the page read it): the script
  mounts it again without the product's options. The PHP filter
  `pixonaut_wc_storefront_edit_mount_options` changes them; the JavaScript
  filter `pixonaut.designer.mountOptions` runs as on any page.
- `.pixonaut-form` carries `data-pixonaut-edit` (`key`, `updateUrl`,
  `linkUrl`, `nonce`), and the add-to-cart button reads `update_design`
  (`woocommerce_product_single_add_to_cart_text`). The script disables the
  form's `attribute_*` and `quantity` fields.
- Submitting approves each side and posts its proof token to the plugin's
  `POST /pixonaut/v1/cart/design` instead of sending the form; nothing is
  added to the cart and `pixonaut.cart.beforeSubmit` does not run. The route
  saves it with `PATCH /v1/designs/{id} {proofToken}`, stores the design's
  new revision and proof on the line with the consent the update gave (the
  line's approval mode: `add_to_cart`, `checkbox` with the box ticked again,
  none for `proof_email`), fires `pixonaut_wc_cart_design_updated`, and
  answers the cart's address as `redirectUrl`, which the script then opens.

| Route refusal (`code`) | Status | The script |
| --- | --- | --- |
| `proof_expired` | 410 | approves the side again and sends the new token, once |
| `revision_changed` | 409 | shows the message (`design_changed_elsewhere`) |
| `not_editable`, `edit_closed` | 409 | removes the designer and shows the message (`design_not_editable`, `edit_closed`) |
| `approval_required`, `line_gone`, `personalisation_unavailable`, `design_not_saved` | 400, 404, 503, 502 | shows the message |

An answer without a message (a proxy's error page) shows `design_failed`.
When `approve()` itself rejects with `revision_changed` or `not_editable`
(Pixonaut refused the correction proof), on submit or from the proof button,
the script reacts as to the route's refusal and shows the label.

When `approve()` rejects with status `401` (the correction link lapsed), the
script posts the side to `POST /pixonaut/v1/cart/design-link`, which mints a
new link and answers the side's mount options, remounts the side on them
with the customer's changes (`initialValues` from the old designer's
`getState()`, read once its `settled()` resolved, so an upload under way
is kept) and approves again. A remount that fails leaves the side to
the next submit, which mounts it again with the same changes. Both routes act only on the caller's own cart, by the
line's key, and take the REST nonce so a signed-in customer's request is
made as them.

## Changes asked for on a proof

A customer who asks for changes on the proof page of a line approved by
proof by email ([Approval modes](../../merchant/approval-modes/#asking-for-changes))
is sent to the page's `returnUrl`, `GET /pixonaut/v1/order-items/<item>/edit`,
with the `state` the store signed for the order, the line, the design and
its revision (`Approval\ProofState`). The route answers `302` to the line's
product page with `?pixonaut_order_edit=<state>` when the product
personalises inline, to Pixonaut's hosted correction page when it is on the
Personalise button layout ([Personalise button](#corrections-on-the-hosted-page)),
to the order received page when it no longer personalises, and to the home
page when the state is not the store's (`Approval\OrderEdit`). The *Edit
your design* email links to the same route.

On the product page the state is checked again (`Approval\OrderEditLine`):

| The line | The page |
| --- | --- |
| `awaiting_customer`, the side not approved, its revision the state's | mounts the side on a correction link, as when editing from the cart |
| holds another revision of the side | shows `proof_changed` in place of the designer |
| approved, no longer waiting for the customer, being cancelled, or its order `failed` | shows `edit_closed` in place of the designer |

While the design is open:

- Only the side whose proof the customer asked to change is mounted, as
  `{correctionLink, design, apiBase, locale}` through
  `pixonaut_wc_storefront_edit_mount_options`.
- The approval UI is the product's approval mode now: in the checkbox mode
  the box must be ticked again. The button reads `approve_changes`, and the
  script disables the `attribute_*` and `quantity` fields.
- `.pixonaut-form` carries `data-pixonaut-edit` with the state as `key`, and
  the line's routes: `POST /pixonaut/v1/order-items/<item>/design` and
  `POST /pixonaut/v1/order-items/<item>/design-link`. They answer and are
  refused as the cart's (`redirectUrl` is the order received page), plus
  `proof_changed` (409) and `edit_closed` (409).
- Saving sends `PATCH /v1/designs/{id} {proofToken}`, stores the new
  revision and proof on the line, then approves it with
  `POST /v1/designs/{id}/approve {consent: {kind: "edit", at, ref}, expectedRevision}`
  (`ref` is the line's external ref). The side is recorded as approved with
  the `edit` consent; once every side of the line is, the line moves to
  `approved`, an order note says so, and its fulfilment job is queued. The
  same state then opens `edit_closed`.
- The line never steps back to an older revision, and the side is approved
  only while it still holds the revision Pixonaut approved: a newer one
  stored meanwhile (a change made in the studio) answers `revision_changed`
  and leaves the line waiting. An order failed, or a line being cancelled,
  meanwhile is noted and answers `edit_closed`.
- Until the change is approved, the side keeps the revision it started from
  (side ref `editBase`), so a state naming that revision still opens the
  page: a customer whose approval failed after the change was saved reloads
  and saves again.
- A variation's page is opened with the attribute values the line was
  bought with, an "Any …" attribute included.

## Mount options

The server builds each side's options, which the PHP filter
`pixonaut_wc_storefront_mount_options` changes ([Hooks](../hooks/)):
`publishableKey`, `apiBase`, `templateId`, `blankId`, `placementId`,
`placements` and `views` (with a blank), `initialView` (with a blank, when
the product names an **Opens on** view), `printSizes` (with a size menu),
`selectedPrintSizeIndex` (the size marked **Starts selected**, else 0), `locale` (from the **Designer
language** setting, absent for the workspace's default; see
[Designer language](#designer-language)) and `layout` (`page` or `steps`, from the product's
**Form layout** or the setting; absent when the template chooses),
`drawAtTemplateRatio` (`true` or `false` from the side's **Canvas shape**,
else the product's; absent when the template's own setting decides),
`preload` (the **Load other colours and views** setting),
`showPrintAreaFrame` (the **Show the print area's frame** setting) and
`accent` (with **Designer accent colour** set to a custom colour, that
colour as `#rrggbb`; set to none, the accent colour of the store's brand in
Pixonaut when it has one). The brand's accent is `branding.accentColor` of
`GET /v1/connect`, which Pixonaut answers for the brand the store is drawn in
(its own, else the workspace's default), kept in an option by
`Connection\WorkspaceSettings` with `branding.brandId`: the Connection tab
keeps what it reads, connecting and choosing a brand keep what
`PATCH /v1/connect` answers, and a product page showing one read more than a day ago queues the job
reading it again, so no product page waits for Pixonaut. An answer is kept
only while the connection it was read for is still the stored one. The script adds `previewTarget` and
`formTarget`, and runs them through the JavaScript filter below before
mounting. Before the filter it also sets `showVariantPicker` (see
[Views and variants](#views-and-variants)) unless the options name it,
and, with the accent set to the theme's primary colour and no `accent` in
the options, `accent` from the theme: its `--wp--preset--color--accent`
(see [Theme look](#theme-look)), else the background of the add-to-cart
form's submit button, or, on a page without one, of a button carrying the
theme's button classes (`button alt wp-element-button`). A theme whose
accent is not a solid colour and whose buttons have no solid background
gives none, and the designer keeps its own colours. It then wraps `onChange`, calling one the filter set, to notice a
design that changed since its proof was made.

## Designer language

`DesignerLanguage` holds the languages Pixonaut's `locale` takes, by code
with each one's own name: `en`, `sv`, `de`, `fr`, `es`, `nl`, `da`, `nb`,
`fi`. It is the one list in the plugin, and the unit suite checks it against
the `locale` enum of `POST /v1/share-links` and
`POST /v1/templates/{id}/designer-links` and against the
`x-extensible-enum` of the answers naming a language (the two links'
`locale`, `defaultLanguage` of `GET` and `PATCH /v1/connect`) in the pinned
spec, so a re-pin (`pnpm contracts:pin`, [Contract](../contract/)) that adds
a language fails until it is added there. The answers are extensible:
`WorkspaceSettings` reads a code it does not know as none.

`DesignerLanguage::resolve( $settings, $locale )` answers the language sent
as the SDK's `locale`, a designer link's and a proof page's: the chosen
language; null for `workspace` (**Pixonaut workspace default**), when
nothing is sent and Pixonaut uses the workspace's default; for `site`
(**Follow the site**, the default) the language of `$locale` without its
region (`de_CH` is `de`, `nb_NO` is `nb`), or null when Pixonaut lacks it.
The storefront passes `determine_locale()`, so a multilingual plugin's
per-visitor locale applies; a proof page passes the line's `locale` ref.
The stored values `site`, `en` and `sv` of earlier versions keep their
meaning. `WorkspaceSettings::default_language()` is the workspace's
`defaultLanguage` as last read with its other settings, which the setting's
**Pixonaut workspace default** option names.

Pixonaut builds each link's `url` with `?lang=<locale>` when the link names
one and answers the link's `locale` (null when it names none, and the page
follows the workspace's default); the plugin never appends `?lang=` itself.
An unknown `locale` is refused `400 invalid_input` with a `reason`, which
the plugin, sending only `DesignerLanguage::languages()`, never meets.

Designer links, proof pages and the inline designer follow the language in
all nine. The SDK (0.7.0 and later) matches `locale` by its language and,
with none or one it lacks, shows the workspace's default, which it reads as
`workspaceLanguage` from `GET /v1/templates/{id}`.

## Theme look

A block theme that defines these presets (custom properties on every page)
gives the designer its look; a theme without one keeps the plugin's own
value for it, so a classic theme looks as it always has.

| Property | Used for | Without it |
| --- | --- | --- |
| `--wp--preset--color--accent` | The selected side tab, the focus ring of the tabs and the approval box, the ticked approval box, the step headings and their number badges (see [Step numbers](#step-numbers)), and the SDK's `accent` (with the accent set to the theme's primary colour) | The tab is underlined in the text colour; the steps are headed `1. <label>` in the SDK's colour; the SDK's `accent` comes from the theme's buttons |
| `--wp--preset--color--border-strong` | The outline of the side tabs and of the approval box | The theme's own buttons and checkbox |
| `--wp--preset--color--error` | `.pixonaut-form__error`, `.pixonaut-approval__error` | `#b32d2e` |
| `--wp--preset--font-family--body` | The text of `.pixonaut-form` | The theme's own font |
| `--wp--custom--radius--small` | The corners of the side tabs and (halved) of the approval box | The theme's own buttons; `0.5rem` halved for the box |
| `--wp--preset--color--base`, `--wp--preset--color--contrast`, `--wp--preset--color--accent-contrast` | The tabs' background and text, the tick and the step number | `transparent`, the inherited text colour, white |

The error colour and the selected tab's underline use the property with its
fallback (`var(--wp--preset--color--error, #b32d2e)`). The rest are drawn
only for a theme that defines them: `.pixonaut-form`'s `data-pixonaut-look`
lists them (`accent`, `border`, `error`, `bodyFont`, `headingFont`,
`radius`, space-separated). The server prints the presets the theme lists
in its global settings (`theme.json`: the `accent`, `border-strong` and
`error` colours, the `body` and `heading` font families, `custom.radius.small`),
so the page draws them from its first paint; before mounting, the script
writes the list again from the presets it finds on the page, which also
counts a preset a theme sets in its own stylesheet. The stylesheet draws the
tabs, the approval box and the printed form's step headings by that list
(`.pixonaut-form[data-pixonaut-look~="border"] .pixonaut-tabs__tab`). The
approval box takes the theme's checkbox look with both `border` and
`accent`, and `accent-color` with `accent` alone.

Of these, the SDK's mount options take only `accent`: it has no option for
fonts, radius, or border and error colours, which it draws in its own look.
`--wp--preset--font-family--heading` is read for when it does.

### Step numbers

On a form laid out as a page, the SDK heads each step `1. <label>` in its
own colour. For a theme that defines `--wp--preset--color--accent`, the
script redraws each of those headings, every time the SDK draws its form,
with the number apart:

```html
<h3 class="pixonaut-step">
	<span class="pixonaut-step__number">1</span>
	<span class="pixonaut-step__label">Upload a picture</span>
</h3>
```

The heading is drawn in the accent, in `--wp--preset--font-family--heading`
at `--wp--preset--font-size--large`, and the number in a round badge,
`--wp--preset--color--accent-contrast` (else white) on the accent, at
`--wp--preset--font-size--medium`; a font or size preset the theme lacks
leaves the SDK's own. The
printed form (`.pixonaut-form__placeholder`) draws its headings the same
way. The SDK keeps the page's stylesheets off what it draws, so these
styles are inline; a theme restyles them by custom properties on
`.pixonaut-form` or above it, such as a badge in its highlight colour:

| Property | Sets | Default |
| --- | --- | --- |
| `--pixonaut-step-color` | The heading's text | `--wp--preset--color--accent` |
| `--pixonaut-step-number-background` | The badge | `--wp--preset--color--accent` |
| `--pixonaut-step-number-color` | The number | `--wp--preset--color--accent-contrast`, else `#fff` |

A form laid out as steps, whose heading has no number, is left as the SDK
draws it.

## Views and variants

Each side is a design of its own, so its designer sells one placement:
`placements` is `[{ "placementId": "<the side's placement>" }]`, printed
with the mount's `templateId`. `views` lists the ids of the blank's views
the product is shown in, as ticked on the product screen
([Views](../../merchant/linking-products/#views)), with `design` for the
SDK's Design view, the artwork alone after the photographed views; empty
shows every view and the Design view, and a list without `design` leaves it
out. `initialView` may be `design` too. `initialView` is the view the product starts on; every side mounts with
it, and the SDK opens a side on it only when it is a view of that side's
placement, else on the placement's first view with a photo. With a blank and no `mockups`, the SDK reads the blank's views, their
photos and its variants itself, with the publishable key
(`GET /v1/blanks/{id}/mockups` and `GET /v1/blanks/{id}/variants`), unless
it starts from the server's reads (see
[Server-rendered layout](#server-rendered-layout)), and draws its own
switcher between the views of the side's placement into
`#pixonaut-views-<side>`, under the preview.

The designer shows the product in the variant it is sold as, the one its
order names ([Variant](../../merchant/linking-products/#variant);
stored as `_pixonaut_variant`, see [Stored data](../#stored-data)): a side
mounts with it as `initialVariant`, and the script calls `setVariant(ref)`
on a side that shows another (a switch of variation, or a mount whose
`initialVariant` the SDK could not show).
On a product without variations the ref is the form's
`data-pixonaut-variant` (absent when the product has none, as a
template-only product); on a variable product it is the chosen variation's
`variant` (see below), and nothing is shown before one is chosen. A side
that already shows the ref is left alone. When `setVariant()` rejects with
the SDK's `VariantUnavailableError` (an unknown or retired variant, or one
no allowed view has a photo of), the variation, or the product, shows
`personalisation_unavailable` and add to cart sends nothing; nothing is
reported to the store, which learns of a retired variant from
`blank.updated`. Any other rejection is tried again by the next switch, and
by add to cart and opening a proof, which send nothing while it fails.

With a blank, `variants` lists the refs of the blank's variants the product
sells: the product's own, or on a variable product those of every variation
personalised inline on the side's blank (a variation without a variant, or
with a retired one, adds none), so a switch between variations on one blank
keeps the designer mounted. The SDK's colour and garment-size picker
(`data-testid="pixonaut-variant-picker"`) offers only those, and
`setVariant()` refuses another. The script mounts with
`showVariantPicker: false`, which hides the picker and keeps the print-size
menu, unless `variants` lists more than one on a product without
variations: a product sold in one variant, and a page editing a design
already bought, have nothing to pick, and a variable product's variation
fields offer every variant it sells, so the picker would offer each choice
twice. A mount filter that sets `showVariantPicker: true` on a variable
product gets the picker and the variation fields kept in step: a colour or size the customer picks there
puts the add-to-cart form's attribute fields on the personalised variation
sold as that variant, the one that keeps most of the form's other choices
(an "any" attribute keeps its value, and the next one is tried when the
form cannot hold it), and announces each change with `input` and `change`
events, so WooCommerce selects it; the classic form offers only the options
the other choices allow, so when one is missing the script clears those
choices first. The designer then follows the form as for any choice there;
the `variant` events of its own `setVariant()` calls are not taken as
picks. A choice of variation in the form
moves the picker in turn. When no variation the form can hold is sold as the
picked variant (a mount filter widened `variants`, or the form lacks the
value), the product shows `personalisation_unavailable` and add to cart
sends nothing until the customer picks again in either. A picked variant
drops the side's proof, as a switch does.

On a variable product whose every personalised variation with a side fixes
one print size, the server mounts that side with `showPickers: { size: false }`
(unless the mount filter set `showPickers`): the variation fields choose the
size, which the script switches with `setPrintSize()`, so the SDK's own
print-size menu would offer it twice; the printed form leaves its picker
out too. A side any variation offers several sizes on, or a simple product
with a menu, keeps it.

## Options and the cart row in the form

On a product with one side, the script draws the add-to-cart form's own
parts inside the designer's form with the SDK's `optionsStep` and
`lastStepContent` mount options:

- `optionsStep.content` is the form's variation fields (the classic form's
  `table.variations`, the Add to Cart with Options block's variation
  selector); the SDK draws them after its print size picker in a first
  step, titled in the designer's language, or first on a page.
- `lastStepContent` is `.pixonaut-cart-row`, an element the script adds
  right after `.pixonaut-form` and moves into it, in order: the checkbox
  approval's `.pixonaut-approval`, the classic form's `.single_variation`
  (the chosen variation's price and stock), then every element after
  `.pixonaut-form` in its parent but the variation fields, hidden inputs,
  scripts and templates: the quantity, **Add to cart**, and whatever a theme
  or another plugin prints beside them. The SDK shows it on the last step
  only, under **Back**, or at the end of a page.

The elements are moved, not copied, so the listeners on them keep working,
and they stay inside the add-to-cart form, so WooCommerce's handlers
delegated from the form still see them. While `.pixonaut-cart-row` is back
in the form it is `display: contents`, laid out as its parts were. The
script destroys the designer, which puts every part back, whenever it hides
the designer: a variation bought without a design, or one that cannot be
personalised. The next variation that can be personalised mounts it again
on the customer's values. With several sides the parts stay where the theme
put them. A mount filter can remove either option.

The parts stand where the designer draws them from the first paint. The
printed form (`.pixonaut-form__placeholder`) holds a
`.pixonaut-placeholder__slot` for each part, named in `data-pixonaut-slot`:
`options` for the option fields, after the print size picker and on a form in
steps on its first step; `cart` for the cart row, at the end, hidden on a form
in steps until the step it belongs to; and `attribute_<slug>` for an attribute
the side's groups place (see [Arranged groups](#arranged-groups)), hidden when
it sits in a later step of a form in steps. What the
printed form only draws is in `.pixonaut-placeholder__part`, `inert` and
hidden from assistive technology; a part in a slot is the real one, and works,
as does a printed input (see [Printed inputs](#printed-inputs)).

WooCommerce prints those parts around the printed form, in the block form
after it, so only a script can move them, and the storefront script is
deferred. `Storefront::print_parts_script()` therefore prints a small script
inline at `woocommerce_after_add_to_cart_form` (the build of
`shop-parts-inline.ts`), which moves them as the page is read. The storefront
script does the same when that one did not run, such as under a policy against
inline scripts. A browser that paints the page between the form and that script
shows the parts where the theme put them for that one paint. A theme's copy of
the template without slots leaves the parts in place until the designer draws
them.

### Printed inputs

The printed form is the form the customer uses. Each control the SDK can take
over is a real input, marked `data-pixonaut-field="<layerId>:<prop>"` with the
field's `layerId` and `prop` from `formSchema`, and holding the field's default:

| Field | Printed input |
|---|---|
| A text | `<textarea>`, or `<input type="text">` when limited to one line |
| A menu | `<select>` with every offered value, the default selected |
| A number | `<input type="range">` with the field's bounds and step |
| A free colour | `<input type="color">` |

They have no `name`, so they post nothing of their own. When the SDK mounts it
moves each one into its form and takes what it holds as the customer's value,
so text typed before it loads is kept and reaches the first preview. What a
marked input does not stand for it draws itself: a picture, a crop, a gradient,
a palette, a scale slider, and any field a theme's copy of the template leaves
without an input. So does a font, a fit or a shape: taken over as a `<select>`,
it would lose the tiles (or, past eight choices, the list) that show each
choice's face. A marked input naming no field of the template is removed. When
the form was printed for another template than the one mounting (a variation
chosen before the designer loaded), or the server's reads are too old to use,
the script first removes the marker from each input the customer has not
changed, so the SDK draws that field on its current default. Until
the SDK loads, `followPrintedInputs()` in the inline script has a slider and a
colour show what they hold, a text count its characters, and Enter in a text
leave the add-to-cart form unsent.

The label of every field is the template read's `label`, printed as given: the
author's, or the form's own word for what the field sets, in the language of
the read.

The SDK then replaces the printed form, and the parts move into its form at
the same place. Whenever the script gives the parts back, or the designer
cannot load, they go back where the add-to-cart form had them, as does a part
the final mount options do not lend (a mount filter removed `optionsStep` or
`lastStepContent`), before the SDK mounts.

### Arranged groups

A side whose **Groups** setting was changed mounts with `formGroups`, the
SDK's arrangement of the form's groups. The server prints it with each
placed variation attribute as `{ "attribute": "<slug>" }`, and, when the
product varies by an attribute the arrangement places nowhere, a first group
`wc-options` titled *Options* holding those. Groups of the product's own
have ids starting `wc-`. When it prints the page, the server fits the
arrangement to the template it read for the side: the SDK passes over an
untitled group the template lacks, its host items too, so such a group is
left out and its attributes join `wc-options`; without a read of the
template, the side mounts without `formGroups`. A variation's side carries
its own `formGroups` (absent for none), fitted the same way when it opens
the template read, and a switch to a variation with another arrangement
mounts the side again. The script swaps each attribute item for a host
item, `{ host: "attribute_<slug>", element }`: the Add to Cart with Options
block's attribute element, or the classic form's row moved into a
`table.variations.pixonaut-attribute` of its own, put before the form's
`table.variations`, where the SDK gives it back on `destroy()`. With a host
item the script passes no `optionsStep`. After each change of the form's
choices it calls `setHostItemComplete( host, complete )`, complete once the
attribute's field holds a value, so **Next** waits for it. On a product with
several sides, or without the form, the attribute items are left out, with
any group of the product's own they leave empty, and the variation fields
stay in the form.

### Swatches

With the `attribute_options` setting on `swatches` (see
[Settings](../settings/)), the server prints `attributeSwatches: true` in
`window.pixonautStorefront`, and the script draws each variation field it
moves into the designer's form (in `optionsStep.content` or a host item) as
a `div.pixonaut-swatches[role=radiogroup]`, labelled by the attribute's name,
of `span.pixonaut-swatches__swatch[role=radio]`, one per option: `is-chosen`
with `aria-checked="true"` for the field's value, `is-unavailable` with
`aria-disabled="true"` for an option the field no longer offers, offers
disabled, or (on the block form) hides. The group is put right after the
theme's field: the classic form's `select`, the block form's chips
(`[role=radiogroup]`) or dropdown. The field gets
`pixonaut-swatches__replaced`, which hides it, and stays in the form: a
swatch picks in it as the customer would (a select's value set, with
`input` and `change`; a chip clicked), so the classic form's
`woocommerce_variation_select_change` and `found_variation` follow, and the
block form's own store. The swatches are drawn again after a change in the
field, its options or chips changing, and the classic form's
`woocommerce_variation_select_change`, `woocommerce_update_variation_values`
and `reset_data`. The arrow keys move the choice to the next available
swatch, and Space or Enter choose the focused one. A click on the chosen
swatch clears the attribute, as the block form's chips do, so choices that
leave no other option available can be undone without the classic form's
**Clear** link. Only the fields the designer draws are drawn as swatches:
with an arranged side, its host items, not the rest of the variation
fields. When the script gives the form's parts back (a variation bought
without a design, or one that cannot be personalised), it takes the
swatches away and shows each field as the theme rendered it again. The swatches take the
designer's colours and the `--pixonaut-accent` the SDK sets on the form.
With the setting on `theme` (the default) the fields stay as the theme
renders them.

So that the field does not change when the script draws the swatches, the
server prints them too: on a product with one side, each classic select
(`wc_dropdown_variation_attribute_options()`) and each of the block form's
chips or dropdown is followed by
`div.pixonaut-swatches.pixonaut-swatches--printed`, `inert` and hidden from
assistive technology, with a `span.pixonaut-swatches__swatch` per option in
the field's words and order, `is-chosen` on the one it opens on. The
stylesheet shows them in the field's place only while the printed form
stands in for the designer's, and the script replaces them with the live
swatches it draws; once the printed form is gone, or the designer is hidden
or unavailable, the field is the theme's again.

## Variations

A variable product shows the inline designer while the product, or one of
its enabled variations, is personalised on the inline layout. Each side
mounts once, from the product's configuration (or, when the product itself
is not personalised, that of its first variation personalised on the inline
layout), with the print sizes of every variation that has the side in one
`printSizes` menu. The
script mounts the sides the chosen variation has (every side before one is
chosen), and a side another variation adds once that variation is chosen.
`.pixonaut-form` carries `data-pixonaut-variations`, by variation id:

```json
{
  "123": {
    "state": "personalised",
    "attributes": { "attribute_size": "L" },
    "approvalMode": "add_to_cart",
    "priceHtml": "<span class=\"woocommerce-Price-amount amount\">…</span>",
    "variant": "v_white_l",
    "sides": { "front": { "templateId": "mini-tee@2", "blankId": "blank_tee", "placementId": "front", "placements": [{ "placementId": "front" }], "views": [], "variants": ["v_white_m", "v_white_l"], "printSizes": [1] } }
  },
  "124": { "state": "plain", "attributes": { "attribute_size": "XS" }, "priceHtml": "…" }
}
```

`printSizes` are indexes into the side's mounted menu, and
`selectedPrintSizeIndex` the one the variation starts on (absent without a
size menu); `initialView` is the variation's starting view (absent for the
first). A switch keeps the size the designer shows when the variation sells
it, else moves to its `selectedPrintSizeIndex`; a side not mounted yet mounts
on the variation's. `variants` the side's
`variants` on the variation's blank, `layout` the variation's form layout
(absent when the template chooses), `formGroups` its arrangement (absent
for none, see [Arranged groups](#arranged-groups)), `drawAtTemplateRatio`
its canvas shape (absent when the template decides), and `variant` is the
ref of the blank variant the variation is sold as (absent when it has none).
`plain` is a
variation bought without a design; `unavailable` one that is personalised
but cannot mount now (flagged not sellable, no variant of its blank or a
retired one, or on the Personalise button layout). The PHP filter `pixonaut_wc_storefront_mount_options` runs for the
product and for each variation, with the variation as `$product`.

The script reads the form's `variation_id` field 100 ms after the classic
form's jQuery `found_variation`, `reset_data` or `hide_variation`, or after
a `change`, `click` or `input` in the form (the Add to Cart with Options
block), and at once when the form is submitted. For each side of the
chosen variation it then:

| The variation differs by | The designer |
| --- | --- |
| print size only | `setPrintSize(index)`, unless the size the designer shows (its `getState().printSize`, which the customer may have picked) is one of the variation's |
| template, and perhaps print size | `setTemplate(templateId)`, then `setPrintSize(index)` as above: the preview, form and view strip keep their place while the new template draws, and a value on a field key both templates share carries over. When `setTemplate()` rejects, the designer is mounted again as in the next row |
| blank, placement, `placements`, `views`, `variants`, form layout, `formGroups` or `drawAtTemplateRatio` | is destroyed and mounted again with `initialValues` from its `getState().values`, read once its `settled()` resolved, and the variation's variant as `initialVariant` |

and then shows the variation's variant (see [Views and variants](#views-and-variants)).

Any switch, a switch of the variant alone included, drops the side's
proof, unticks the approval box and removes the side's proof link, so add
to cart approves again; an `approve()` under way when it happens, or that
rejects with `PlacementChangedError` or `TemplateChangedError`, is made
again. A mount that rejects
with `unknown_placement` makes the chosen variation show
`personalisation_unavailable` when it has that side: add to cart sends
nothing, and the variation is reported (see
[A designer that cannot load](#a-designer-that-cannot-load)). One started
for a variation the customer has left since breaks nothing, and the side
mounts again for the new one.

A switch that rejects for any other reason (a network blip) leaves the side
on its previous options. Add to cart, and opening a proof, first
switch to the chosen variation again; when that fails too they show
`design_failed` and send nothing. A variation chosen while add to cart makes
the proofs has them made again for its own sides, up to three times.

A side the chosen variation does not have has its tab hidden and sends no
design; with one side left, the tabs hide. A variation in another approval
mode than the checkbox hides `.pixonaut-approval` and disables its box. A
`plain` variation hides `.pixonaut-form` and `.pixonaut-preview` and sends
the form as it is, also when no side of the product can mount (the form
then carries `data-pixonaut-variations` without `data-pixonaut-mounts`).

### Price

Every variation carries `priceHtml`, its price as WooCommerce formats it
for the store (`get_price_html()`, so `woocommerce_get_price_html` applies);
absent for a variation without a price. When the chosen variation changes,
for a size or variant picked in the variation form or in the designer, the
script puts its `priceHtml` in each price target. It puts back what each
target showed first once no variation is chosen, or the chosen one has no
price or is priced as that original. It replaces a target's inner HTML only,
never the element or its attributes, and then dispatches `pixonaut:price`
(see [DOM events](#dom-events)); when the price shown does not change, it
writes and dispatches nothing. A simple product's price never changes.

The page prints the price the form opens on. On a variable product page
that shows the inline designer, the product's price
(`woocommerce_get_price_html`, from priority 100) is the price of the
variation the add-to-cart form opens on, chosen as WooCommerce chooses it
(the request's `attribute_*` values, else the product's default
attributes), rather than the product's range, wherever the page prints the
product's price: the summary, the Product Price block, a theme's price
target. The form then carries the range in `data-pixonaut-product-price`,
which the script shows in the targets once no variation is chosen (in place
of what they first showed), also when the form settles without one. Beside it,
`data-pixonaut-opening-choice` holds the attribute values the printed price is
for, by field name: while the form holds them the script keeps that price, as
WooCommerce has not found the variation yet. A theme's copy of the template
without it shows the range as soon as the form holds no variation. A form
that opens on no variation, or on one it does not offer (out of stock while
the store hides those, or not visible) or without a price, keeps the
product's price, and other pages show it too.

The price shows once: on a variable product the script writes the chosen
variation's price in the targets, so WooCommerce's own variation price in
the form (`.single_variation .woocommerce-variation-price`, which the
designer would draw in the cart row) is hidden, except on a page that edits
a cart line.

The targets are every element carrying `data-pixonaut-price` inside the
add-to-cart form. A theme prints one where its design shows the price, for
example on `woocommerce_before_add_to_cart_quantity`, after `.pixonaut-form`
and before the quantity input:

```html
<div class="pixonaut-shop-price" data-pixonaut-price><?php echo $product->get_price_html(); ?></div>
```

Without one, the target is the summary price of the form's own product
(its `.product` or Single Product block) as the classic
template (`.summary .price`) or the Product Price block
(`.wc-block-components-product-price`) prints it, outside the add-to-cart
form and outside any list of products.

## JavaScript hooks

Through `@wordpress/hooks` (`wp.hooks` on the page):

| Hook | Kind | Arguments |
| --- | --- | --- |
| `pixonaut.designer.mountOptions` | filter | `options` (the SDK's split mount options), `{productId, side}` |
| `pixonaut.designer.approved` | action | `{productId, side, result}`, `result` being what `approve()` resolved: the proof (`proofToken`, `proofUrl`, `expiresAt`, …), its `placementId`, and `sides`, the proof of each side the designer approved, the lead first (one, as each side's designer sells one placement) |
| `pixonaut.cart.beforeSubmit` | action | `{productId, form, designs}`, `designs` being the proof tokens by side, already in their fields |
| `pixonaut.storefront.label` | filter | `text`, `key`, `context`; see [Label keys](../label-keys/) |

```js
wp.hooks.addFilter( 'pixonaut.designer.mountOptions', 'my-theme', ( options ) => ( {
	...options,
	layout: 'steps',
} ) );
```

## DOM events

Dispatched on the `.pixonaut-form` element; they bubble.

| Event | When | `detail` |
| --- | --- | --- |
| `pixonaut:designer-approved` | A side's proof is ready | as `pixonaut.designer.approved` |
| `pixonaut:cart-before-submit` | The form is about to be sent. Cancelable: `preventDefault()` keeps it, and the designer unlocks. | as `pixonaut.cart.beforeSubmit` |
| `pixonaut:price` | The chosen variation changed the price shown, or the original came back (see [Price](#price)). Not cancelable; fires also without a target to write in. | `{productId, side, printSizeIndex, variationId, priceHtml}`: the product's first side, the variation's starting print size on it as an index into its mounted menu (`null` without a size menu, or for a variation that is not personalised), the variation whose price is shown (`null` when the original came back), and the HTML written into the targets |

## Submitting

The script listens for the add-to-cart form's `submit` in the capture phase,
before the theme's own handlers. It approves every side, writes the tokens,
then sends the form on with `requestSubmit()`, so every other handler runs
as usual. When one of them stops the browser from sending it (an AJAX add to
cart), the designer unlocks; otherwise it stays locked while the page
leaves. A button the SDK draws inside the form is not taken as an add to
cart.

## Checkbox approval

On a product whose approval mode is `checkbox`
([Approval modes](../../merchant/approval-modes/)), the `pixonaut_approve`
box is `required`. Adding to the cart with it unticked keeps the form from
sending and, in place of the browser's own message:

- adds `is-invalid` to `.pixonaut-approval`;
- sets `aria-invalid="true"` on the box and focuses it;
- shows `.pixonaut-approval__error` (`role="alert"`) with the label
  `approval_required`.

Ticking the box removes all three. Style the error state with these classes;
the wording comes from the label. Add to cart also refuses a form posted
without the box (the same label, as a notice) before committing anything,
and records the consent `{kind: "checkbox", at}`.

Each side's design also records whether its preview was opened before the box
was ticked. A proof shown in the dialog below is reported to the SDK
(`reportPreviewOpened()`), so its `previewOpened` event reaches a listener of
yours. Adding to the cart writes the SDK's `wasPreviewOpened()` into
`pixonaut_preview_opened[<side>]` (`1` or `0`), and the commit sends it as
`previewOpened`. A change to the design makes the answer false again. A
product in another approval mode sends no answer, and its design says this
does not apply.

A `.pixonaut-approval__proof` button approves its side and shows the proof
in a modal `dialog.pixonaut-proof-dialog`, which the script adds at the end
of the `body` the first time a proof is opened. The dialog opens during the
click, named by the `proof_alt` label, with `.pixonaut-proof-dialog__loading`
(`role="status"`, the `proof_loading` label) and `aria-busy="true"` until the
proof is ready; then `img.pixonaut-proof-dialog__image` shows it. The image is
fetched from the proof's `proofUrl` and shown from a `blob:` address, so
Pixonaut's address is nowhere on the page, and the page's address does not
change. The dialog closes with `.pixonaut-proof-dialog__close` (the
`proof_close` label, with the theme's button class from
`.pixonaut-approval`'s `data-pixonaut-button-class`), Escape or a click on
the backdrop, and focus goes back to the button. The form is free again
once the approval is made; closing the dialog before the proof is ready
leaves the approval made and gives up the image's download. An image that
cannot be fetched or drawn closes the dialog and shows the `design_failed`
label in `.pixonaut-form__error`. Style it by those classes; it takes
the page's font and colours, and the block theme's `base` colour as its
background. The button is the linked word of the `approve_preview` label, inside the box's
`label` (`.pixonaut-approval__proof--link`, drawn as a link), where `%1$s`
and `%2$s` mark it; clicking it does not tick the box. A label without the
marks (a store's own text) is printed as it is, with an `open_proof` button
per side after it, under a block theme with its button class. That
approval fires `pixonaut.designer.approved`, and add to cart sends its proof
token unless the side's `onChange` fired since, or the proof is within a
minute of its `expiresAt`. An `onChange` also unticks the box; a result whose side changed while it was being made is
made again. Each add to cart spends the proofs it sends: the next one
approves again.

The approval controls hide only once the product cannot be personalised for
the rest of the page (`.pixonaut-form.is-closed`); after a passing failure
they stay, and the next click retries the mount.

## A designer that cannot load

When a side cannot be mounted or proofed because the SDK's wasm failed its
integrity check (`wasm_integrity`), could not boot (`wasm_boot`) or came from
another release (`wasm_version_mismatch`), because the template is newer
than the pinned SDK's renderer draws (`sdk_outdated`: Pixonaut refuses it to
this release, or the renderer rejects the document with
`UnreadableDocumentError`), or because Pixonaut refuses the blank and
placement (`unknown_placement`), the form shows
`personalisation_unavailable` for the rest of the page's life and the script
posts `{product, side, reason}` to
`POST /wp-json/pixonaut/v1/storefront/unavailable`. When the SDK's script
itself does not load (blocked by the Content Security Policy, refused by its
SRI, or the CDN unreachable), it reports `sdk_load` for the first side and
shows the same label, but the next add to cart tries again. Any other
failure to mount (a network error) shows the label without a report and is
tried again too.

The route is public and acts on one report per product, side and reason an
hour. An `unknown_placement` report is checked with
`GET /v1/templates/{id}/constraints` for the placement stored on the product,
whatever `pixonaut_wc_storefront_mount_options` changed, before the product
is flagged invalid (reason `unknown_placement`) with an admin notice; a
configuration saved while Pixonaut is asked is kept. On a variable product
the script reports the chosen variation's id, and only that variation
becomes unavailable on the page; the route checks the variation's placement
and flags the configuration that holds it: the variation's when it
overrides its product's, else the product's. An SDK failure only
raises an admin notice, since it happened in one browser; an
`sdk_outdated` report's notice asks the store manager to update the plugin,
which pins the SDK release it loads. Its key names that release
(`UnavailableReports::outdated_notice_key()`), and on `admin_init` a notice
raised under another release is dropped, so a plugin update that pins a newer
SDK clears it; a template still too new raises it again.

## Personalise button

On a product whose layout is the button, the plugin prints
`storefront/button.php` at `woocommerce_after_add_to_cart_quantity`, directly
before WooCommerce's button in the add-to-cart form, and loads the stylesheet alone (no script, no SDK),
except on a variable product, where the storefront script follows the
chosen variation (see [Variations on the button layout](#variations-on-the-button-layout)).

| Template | Printed | Holds |
| --- | --- | --- |
| `storefront/button.php` | At `woocommerce_after_add_to_cart_quantity`, inside the add-to-cart form | An empty `.pixonaut-personalise`, never drawn, holding the state data; as its siblings, direct children of the form's row, a `.pixonaut-personalise__button` submit button in WooCommerce's classes (`single_add_to_cart_button button alt`, and the block theme's button class) named `pixonaut_open_designer` whose value is the product id, or `.pixonaut-personalise__unavailable` when the product cannot be personalised now; while a returned design could not be kept yet, a `.pixonaut-personalise__retry` button named `pixonaut_add_design` whose value is its visit's key. On a variable product, `data-pixonaut-variations` and all of them, those not shown `hidden`; the buttons not shown are `type="button"`, so Enter in the quantity field adds the variation instead |
| `storefront/personalised-notice.php` | At `woocommerce_before_add_to_cart_button`, once per waiting design | `.woocommerce-info.pixonaut-personalised` with `data-pixonaut-design="<product id>:<variation id>"`, the `change_design` link (`.pixonaut-personalised__change`), or a `change_design_side` link per side, the `start_over` link (`.pixonaut-personalised__start-over`), and a hidden `pixonaut_personalised` field, which makes the Add to Cart with Options block post its form |
| `storefront/in-cart.php` | In place of the add-to-cart form, when a cart line holds the design the page shows: on `woocommerce_simple_add_to_cart` and `woocommerce_variable_add_to_cart`, which WooCommerce's classic template, its Add to Cart form block and a page builder's widget all fire, or in place of the `woocommerce/add-to-cart-with-options` block's output | `.pixonaut-in-cart`: `.pixonaut-in-cart__status[role=status]` with `.pixonaut-in-cart__message` (`in_cart`, the line's options and its quantity) and the `change_line_design` link (`.pixonaut-personalised__change`), or a `change_design_side` link per side; `a.pixonaut-in-cart__view` to the cart (`view_cart`), in WooCommerce's button classes; `a.pixonaut-in-cart__another` to the plain product page (`make_another`) |
| `storefront/design-gallery.php` | After WooCommerce's gallery (`woocommerce_before_single_product_summary` at 21, or after a gallery block), once per waiting design | `.images.pixonaut-design-gallery` with `data-pixonaut-design`, and per side a `.woocommerce-product-gallery__image` holding the design's picture |

The button is a direct child of the form's row, as WooCommerce's is, because
themes and WooCommerce's own block styles lay out
`form.cart > .single_add_to_cart_button`. The plugin adds no rule or code for a
particular theme: a theme styles and places the button as it does Add to cart,
with a hidden quantity the row holds what is left, and an unavailable product
shows the message where the button goes.

The stylesheet hides the form's other submit buttons while
`.pixonaut-personalise` is in it and not `hidden`, and a shown
`.pixonaut-design-gallery` hides the gallery before it. Pressing the button posts the add-to-cart
form (with its `quantity`) to the product page. Before WooCommerce's own form
handler, at `wp_loaded`, the plugin:

1. checks the product is published, not behind a password, purchasable, and
   for a variable product one of its variations;
2. finds the product's designer link in the designer language (the
   **Designer language** setting, or the visitor's language when it follows
   the site; `Storefront\KeptDesignerLinks`; a link without `locale` is kept
   under `workspace`). The link is minted once
   (`POST /v1/templates/{id}/designer-links`) for the first side's template,
   without `state`, `expiresInDays` or `designLimit`, so it works until it
   is withdrawn: one side at the top level, or 2–10 under `sides` with each
   side's `key`, `label` and, when it differs, `templateId`; each side's
   blank, placement and print-size menu with the size marked **Starts
   selected** chosen (`selectedPrintSizeIndex`), and the product's starting
   view as `initialView` when it names one; the
   blank variant the product or variation sells as `variants` (that one
   alone, since the cart adds the variation the link was minted for) and
   `initialVariant`; and `views`, the ticked views of the side's placement,
   only when they leave out one the variant is photographed in there (absent,
   Pixonaut shows every view and its Design view);
   `returnUrl` (the home page with
   `?pixonaut_designer=return&pixonaut_product=<id>&pixonaut_variation=<id>`)
   and `locale` (absent for the workspace's default); `layout` (`page` or `steps`) when the
   product's or the store's **Form layout** fixes one (`FormLayout`), absent
   for the template's own; and `name`: the product's name and id, and
   the language when it is not the site's, at most 80 characters. The
   `pixonaut_wc_storefront_designer_link` filter changes the body. The link's
   id, address and a hash of its body are kept in the product's or
   variation's `_pixonaut_designer_links` meta, by language, and reused
   while the body is unchanged; a changed body takes the kept link's name
   away (`PATCH /v1/share-links/{id}`), mints a new link and withdraws the
   kept one (`DELETE /v1/share-links/{id}`). One request at a time mints a
   product's link, holding a `pixonaut_wc_designer_link_lock_<id>` option;
   another that needs the link meanwhile waits up to five seconds for it,
   then mints its own. When Pixonaut answers `share_link_name_taken`, a link
   kept for the product a moment ago is used; otherwise the link is minted
   without a name. Saving a product in the admin mints the links of the product or
   its variations, so a refusal such as `print_size_illegal` raises a notice
   on its screen, and withdraws those no longer on the button layout. Pixonaut makes a link with more than four sides
   but answers it with a `sides_over_recommended` warning, which the plugin
   logs (WooCommerce > Status > Logs, source `pixonaut-api`); the product's
   Pixonaut tab shows the same advice once it has more than four sides. A
   product without a blank has one side: Pixonaut takes several sides on no
   blank, but the plugin links such a product to one template only;
3. keeps the posted `attribute_*` values and the quantity, which the product
   page offers on return, in the customer's WooCommerce session for 24 hours, under the product and variation (a
   second press for the same product replaces them), with a random `state`
   for this trip (32 letters and digits), and redirects to the link's `url`
   with `state=<value>` added. The link itself stays the same for every
   customer; only the address the customer is sent to carries the trip's
   state, so a cached page never holds it.

Pixonaut sends the customer back to `returnUrl`, keeping its query, with
`proofToken` (one side) or `proofToken[<side key>]`, or with no token when
they cancel, and the trip's `state` unchanged. Pixonaut neither checks nor
stores the state; matching it to a trip is the plugin's job. The session
remembers the latest returns' proof tokens: the same return again is refused
with the `designer_visit_unknown` label before any visit is touched. A
`state` naming a visit of a link minted before links were kept per product
takes that visit. The return handler then takes the visit of the product and variation the query names
out of the session. With none there, in another browser or after a session
ended, it commits nothing: it keeps the tokens under a visit of the product with
no attributes chosen, adds the `design_ready` notice and sends the customer to
the product page, where the button template offers `pixonaut_add_design`. That
button is posted from the store's own page, so a return address someone else
opened never commits a design. Otherwise it
commits the designs, checked as an inline add to cart's are
(`AddToCart::commit_designed()`, firing `pixonaut_wc_cart_designs_committed`).
Nothing joins the cart: committing is what lets Pixonaut draw the design. The
line is kept in the customer's session under `pixonaut_personalised_designs`, by a
random key (`Storefront\PersonalisedDesigns`), and the customer lands on the
product page with `?pixonaut_design=<key>` and the chosen options in the query.
The key finds a design only in the session holding it: a plain visit, or the
address in another session, shows the product fresh, and such a page is sent with
no-cache headers. When the commit is
refused for a reason that may pass (Pixonaut not reachable), the visit is kept
with its tokens and the button template offers `pixonaut_add_design` to try
again; a design found not to match the product is deleted and not kept. That
button shows only beside the Personalise button, never on the `designed` state.

With the `add_after_approve` setting on, the link's body has
`afterApprove: "add_to_cart"`, which only words the hosted page's button, and a
return whose `state` is that of the trip it takes adds the line to the cart
through WooCommerce's own form handler (`WC_Form_Handler::add_to_cart_action()`,
`Storefront\AddAfterApprove`), so other extensions' validation applies, and
lands on its page. The state is used once: taking the trip's visit, or keeping
it for another try, forgets it. Any other return, with no state, another
session's or one already used, never adds: it lands on the product page showing
the design, which the customer adds with WooCommerce's button, as with the
setting off. Pressing `pixonaut_add_design` never adds either. A refused add
lands on the design's page with the notice saying why.

Stores that had the setting on before it was briefly removed must turn it on
again: the update that removed it (migration 9, `ForgetAddAfterApprove`)
deleted the stored value, and it may already have run. The migration stays as
it is, so every store starts with the setting off whichever update it ran
first.

While the page shows a design for what the form holds, `.pixonaut-personalise`
and the button are `hidden`, so WooCommerce's own button shows with its own text.
The note posts the key as `pixonaut_personalised`:
`woocommerce_add_to_cart_validation` finds the line by it, while it is the design
of the product and variation posted, and checks it still
suits the product: the same blank variant, workspace, personalised sides and
standing designs, and each design still matching its side. Otherwise the design
is forgotten and the add is refused (`design_outdated` or `design_mismatch`).
It adds with the product's approval mode and the consent of now. A form left open
from before the design joined the cart still posts its key: a design a cart line
already holds is duplicated (`POST /v1/designs/{id}/duplicate`) under a new ref,
so two lines never hold one design; a refused copy adds nothing
(`design_not_saved`), and a copy whose add is refused afterwards, by WooCommerce
or another extension, is deleted as the request ends.

An add lands on the line's page, `?pixonaut_line=<cart item key>`, through
`woocommerce_add_to_cart_redirect`, unless `woocommerce_cart_redirect_after_add`
sends the customer to the cart; a refused add keeps showing the design. A cart
line's product link is that same page. A design a cart line holds has no
add-to-cart form on its page, under either address: `storefront/in-cart.php`
stands in its place and shows the gallery whatever the address's options say,
so nothing on that page adds a line or copies a design. A plain visit to the
product still has the form. The line being removed, or the cart emptied, forgets the
design without deleting anything, which `Cart\CartCleanup` deals with; so
does WooCommerce dropping the line as the cart's session loads
(`woocommerce_remove_cart_item_from_session`). A design no cart line holds is
deleted when it is pushed out by the session's tenth, or left in a session that
expired.

A design on a template with no blank whose `backgroundSeeThrough` is `true` gets
`.pixonaut-design-gallery__paper` in the gallery, which draws white behind it,
on the page only.

A link Pixonaut could not be asked to withdraw (it was not reachable, or
answered an error) is kept by `Storefront\DesignerLinkWithdrawals` in the
`pixonaut_wc_designer_link_withdrawals` option, and its
`pixonaut_wc_storefront_withdraw_links` Action Scheduler job
asks again after a minute, then twice as long each time, up to six hours,
until it is withdrawn; while the connection is paused it waits.

Disconnecting the store forgets every kept link and every link waiting to be
withdrawn, since Pixonaut withdraws them. A link is kept with the id of the
connection it was minted under, new each time the store connects, so one
kept for another connection is never used, even after connecting to the
same workspace again. A re-check of a
product (`Catalogue\ProductRecheck`) lists its template's designer links
(`GET /v1/share-links?kind=designer&subjectId=<id>&createdBy=me`) and
forgets those Pixonaut no longer lists. Deleting a product withdraws its
links; uninstalling removes the meta.

The return is not a REST route: a REST request without a nonce is a signed-out
request, and the customer's session and cart would not be the ones they left
with.

### Variations on the button layout

A variable product shows the button while it is personalised on the button
layout or, when it is not personalised, while one of its variations is and
none personalises inline (the inline designer then takes the page). The
button's state for each variation it sells is printed in
`data-pixonaut-variations`, by variation id:

```json
{ "123": "personalised", "124": "plain", "125": "unavailable" }
```

`personalised` shows the button, `unavailable` (cannot be personalised now,
or on the inline layout) shows the unavailable text in its place, and
`plain` (not personalised) marks `.pixonaut-personalise` `hidden` and hides the
button, so the add-to-cart button shows and the variation is added as it is: add
to cart asks a variation for designs only when its own configuration personalises
it. `designed` does the same for a variation whose design waits on the page, and
shows the box and the gallery whose `data-pixonaut-design` names that variation;
choosing another hides them, as a design belongs to the variation it was made
for. A page opening on a designed variation marks `.pixonaut-personalise`
`data-pixonaut-opens-designed`, so the button comes back when the choice is
cleared. The button carries WooCommerce's class, so WooCommerce's variation
script marks it `disabled wc-variation-selection-needed` too; the storefront
script takes those off on a press, so the store asks for the options itself. The
script follows the form's `variation_id` field as it does for the
inline designer, and keeps the state the page printed while no variation is
chosen: the product's own, hidden when only its variations personalise.
Pressing the button starts the designer on the chosen variation's
configuration; pressed with no variation chosen, the plugin adds the
`choose_options` notice and sends the customer back to the product with the
options as they left them in the query, for the form to open on.

An overriding variation with `layout: null` takes the product's layout
(`ProductConfig::config_for`), which the `pixonaut_wc_catalogue_layout` filter
then sees; only a product's null follows the store's default.

`data-pixonaut-prices` holds each variation's price HTML by id. The script
writes the chosen variation's in the targets described under [Price](#price)
and `pixonaut:price` fires as on the inline layout, with `printSizeIndex`
`null` and, on a product only its variations personalise, an empty `side`;
WooCommerce's own variation price in the form is hidden. The page opens on the
opening variation's price, with the product's own in
`data-pixonaut-product-price` and the choice it is for in
`data-pixonaut-opening-choice`.

### Corrections on the hosted page

A design made on the button layout is corrected on Pixonaut's hosted
correction page (`Storefront\HostedCorrection`): the plugin mints a correction
link with `returnUrl` and `state` (`POST /v1/designs/{id}/correction-links`,
2 hours, no `locale`; the page is English) and redirects to its `url`. One
link opens one design, so one side. Both ends run at `wp_loaded`, as the
designer's do.

| Trip | Starts at | `state` | Returns to | With a `proofToken` |
| --- | --- | --- | --- | --- |
| From the product page | `?pixonaut_correct=product&item=<product id>:<variation id>&side=<side key>` | random, single use, kept in the WooCommerce session with the design and side for 2 hours (`Cart\CorrectionTrips`) | `?pixonaut_corrected=product` | `PATCH /v1/designs/{id} {proofToken}`; the waiting design takes the revision and proof, and a cart line holding it takes them with the consent as an inline update (`pixonaut_wc_cart_design_updated` fires); then the product page |
| After asking for changes | `?pixonaut_correct=order&state=<signed state>`, where the proof page's return route sends the customer | the signed state, carried there and back as it is, so it opens again until the line holds another revision | `?pixonaut_corrected=order` | `PATCH`, then `POST /v1/designs/{id}/approve` with consent `edit`, as on the product page; then the order received page |

On the button layout, `woocommerce_cart_item_permalink` points at the line's
product page (`?pixonaut_line=<cart item key>`), which has the links. Approving on the hosted page stands in for the checkbox
mode's box. Cancelling (`state` alone) returns to the product page or the order with
nothing changed. A refusal adds a WooCommerce notice with the label the
product page shows (`design_changed_elsewhere`, `design_expired`,
`proof_changed`, `edit_closed`, …) and returns to the same place; a product
page `state` the session does not hold shows `correction_visit_unknown`, and a
saved change `design_updated`. When such a correction is refused with
`design_not_saved` or `personalisation_unavailable` (Pixonaut could not be
reached, answered with a server error, or the store is disconnected), the
trip is kept in the session with its proof token until it expires, and the
side's link saves that token again instead of minting another link. The order received page prints waiting
notices at `woocommerce_before_thankyou`, in the classic template and the
blocks, since it prints none of its own. An order `state` that is not the
store's goes to the home page.

