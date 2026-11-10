---
title: Templates
description: The plugin's templates, overriding them from a theme, their versions, and the outdated-override report.
sidebar:
  order: 7
---

All the plugin's markup comes from `templates/` in the plugin through
`wc_get_template()`, so a theme overrides a template the way it overrides a
WooCommerce one.

## Overriding a template

Copy the template to `woocommerce/pixonaut/` in your theme, keeping its path
below `templates/`: `templates/admin/notice.php` becomes
`yourtheme/woocommerce/pixonaut/admin/notice.php`. A child theme's copy wins
over its parent's. Each template's docblock lists the variables it receives
and says which ids, fields and `data-` attributes the plugin's scripts read;
keep those in your copy.

A plugin can also redirect a template with WooCommerce's
`woocommerce_locate_template` filter, which the status report below follows.

## Versions

Every template starts with an `@version` header. It changes, to the version
of the release that ships the change, whenever the template's markup or the
variables it receives change in a way a copy has to follow. A release that
leaves a template alone leaves its `@version` alone, so an override stays
current across releases until its template changes.

A `@version` is never past the next release: the next minor version while the
plugin is below 1.0.0, the next major version from then on. A unit test fails
on a template marked further ahead, because a theme's copy of it would not be
flagged when the template changed again before the plugin reached that
version. The release workflow is stricter: it builds no zip while a
template is newer than the version it releases (`pnpm release:check-templates`),
so a template changed in a patch release must say that patch version.

When you update your copy, carry the template's changes over and copy its new
`@version` with them.

## Outdated overrides

**WooCommerce > Status** lists, below WooCommerce's own report, a
*Pixonaut for WooCommerce templates* section with every template the active
theme overrides. A copy whose `@version` is older than the plugin's template,
or that has no `@version`, is flagged as out of date with both versions. The
section is part of the system report that **Get system report** copies.

## Reference

<!-- BEGIN GENERATED templates: from the @version and docblock of each file in templates/, run pnpm docs:generate -->

| Template | Version | Override in the theme | Description |
|---|---|---|---|
| `admin/connection-card.php` | 0.1.0 | `woocommerce/pixonaut/admin/connection-card.php` | The Pixonaut connection card on the settings page's Connection tab. |
| `admin/developer-tools.php` | 0.1.0 | `woocommerce/pixonaut/admin/developer-tools.php` | The hidden developer tools on the settings page's Connection tab. |
| `admin/notice.php` | 0.1.0 | `woocommerce/pixonaut/admin/notice.php` | An admin notice raised by Pixonaut for WooCommerce. |
| `admin/order-box.php` | 0.1.0 | `woocommerce/pixonaut/admin/order-box.php` | The Pixonaut box on the order screen: each personalised line, its proofs, where it stands and what can be done about it. |
| `admin/order-item-details.php` | 0.1.0 | `woocommerce/pixonaut/admin/order-item-details.php` | A personalised order line's details on the admin order screen: the values the customer chose for each design's fields, the line's state and its tracking, under WooCommerce's own meta. |
| `admin/picture-view-choices.php` | 1.4.0 | `woocommerce/pixonaut/admin/picture-view-choices.php` | The choices of what a product picture is drawn on: the photo the product page starts on, each photo of the side's placement, or the flat design, each a tile with its thumbnail. |
| `admin/product-blank.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-blank.php` | What the Pixonaut tab shows of a product's blank: the printshop it prints at, and which of its variants the product is. The panel draws it again when another blank is picked, before the product is updated. |
| `admin/product-fields.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-fields.php` | One Pixonaut configuration's fields: the product's, in the Pixonaut tab, or a variation's, in its Pixonaut panel. |
| `admin/product-not-connected.php` | 0.1.0 | `woocommerce/pixonaut/admin/product-not-connected.php` | The next step in the product's Pixonaut tab while the store is not connected. |
| `admin/product-panel.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-panel.php` | The Pixonaut tab of the product data box. |
| `admin/product-picture.php` | 0.1.0 | `woocommerce/pixonaut/admin/product-picture.php` | Where a personalised product's or variation's image is set: a line on its Pixonaut picture, and beside an image of the store's own, the link that puts the picture back. |
| `admin/product-regenerate.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-regenerate.php` | The Regenerate in background and Regenerate now buttons in the Pixonaut tab, under Product images as Draw again, a spinner while the pictures are drawn in the background, and the line saying how the drawing went. Regenerate now asks for confirmation and shows its progress in a dialog (`admin/regenerate-now.php`); it stays on while the background drawing runs, so pictures stuck in the queue can be drawn right away. |
| `admin/product-showcase-images.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-showcase-images.php` | The product images field in the Pixonaut tab: a card per picture the product can show, each with a thumbnail of it. The template's showcase sets are ticked to be the product's images, each drawn on a photo of the blank or as the design alone; the template's own picture, drawn with its defaults, is shown while none is ticked. |
| `admin/product-side.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-side.php` | One side in the Pixonaut tab's sides repeater. |
| `admin/product-variant.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-variant.php` | The blank variant field of a product or variation personalised on a blank. |
| `admin/product-variation.php` | 1.2.0 | `woocommerce/pixonaut/admin/product-variation.php` | A variation's Pixonaut panel, below its attributes in the Variations tab. |
| `admin/product-views.php` | 1.4.0 | `woocommerce/pixonaut/admin/product-views.php` | The views a product is shown in on its page, each a tile with its photo, and the one the designer opens on, as a closed section of the Pixonaut tab. The panel draws it again when another blank is picked, before the product is updated. |
| `admin/regenerate-now.php` | 0.1.0 | `woocommerce/pixonaut/admin/regenerate-now.php` | Regenerate now's dialog, on the products list and the product screen: the confirmation before the pictures are drawn, then each product's progress while they are. |
| `admin/status-report.php` | 0.1.0 | `woocommerce/pixonaut/admin/status-report.php` | The Pixonaut for WooCommerce section of WooCommerce > Status: the plugin's templates the theme overrides. |
| `admin/upgrade-notice.php` | 0.1.0 | `woocommerce/pixonaut/admin/upgrade-notice.php` | The warning under a major update of Pixonaut for WooCommerce in the plugins list. |
| `admin/variation-picture-view.php` | 1.2.0 | `woocommerce/pixonaut/admin/variation-picture-view.php` | In a personalised variation's options, what its image is drawn on: as its product's image, or one of the photos of the blank for its side. |
| `cart/proof-thumbnail.php` | 0.1.0 | `woocommerce/pixonaut/cart/proof-thumbnail.php` | A personalised cart line's image: the customer's design on the product (its proof alone for a product on no blank), in place of the product's. |
| `emails/changes-link.php` | 0.1.0 | `woocommerce/pixonaut/emails/changes-link.php` | The "edit your design" email: a link that opens a design again for the changes the customer asked for on its proof. |
| `emails/changes-requested.php` | 0.1.0 | `woocommerce/pixonaut/emails/changes-requested.php` | Email to the store about a customer who asked for changes to a proof. |
| `emails/deadline-passed.php` | 0.1.0 | `woocommerce/pixonaut/emails/deadline-passed.php` | Email to the store about a proof whose deadline passed without the customer's answer. |
| `emails/design-approved.php` | 0.1.0 | `woocommerce/pixonaut/emails/design-approved.php` | The "design approved" email. |
| `emails/needs-attention.php` | 0.1.0 | `woocommerce/pixonaut/emails/needs-attention.php` | Email to the store about a personalised item that needs attention. |
| `emails/part-shipped.php` | 0.1.0 | `woocommerce/pixonaut/emails/part-shipped.php` | Email to the customer about a personalised item that shipped before the rest of the order. |
| `emails/plain/changes-link.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/changes-link.php` | The "edit your design" email, as plain text. |
| `emails/plain/changes-requested.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/changes-requested.php` | Plain text email to the store about a customer who asked for changes to a proof. |
| `emails/plain/deadline-passed.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/deadline-passed.php` | Plain text email to the store about a proof whose deadline passed without the customer's answer. |
| `emails/plain/design-approved.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/design-approved.php` | The "design approved" email, as plain text. |
| `emails/plain/needs-attention.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/needs-attention.php` | Plain text email to the store about a personalised item that needs attention. |
| `emails/plain/part-shipped.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/part-shipped.php` | Plain text email to the customer about a personalised item that shipped before the rest of the order. |
| `emails/plain/proof-expired.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/proof-expired.php` | The "cancelled after deadline" email, as plain text. |
| `emails/plain/proof-ready.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/proof-ready.php` | The "proof ready" email, as plain text. |
| `emails/plain/proof-reminder.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/proof-reminder.php` | The proof reminder email, as plain text. |
| `emails/plain/tracking-changed.php` | 0.1.0 | `woocommerce/pixonaut/emails/plain/tracking-changed.php` | Plain text email to the customer about tracking added or corrected after personalised items shipped. |
| `emails/proof-expired.php` | 0.1.0 | `woocommerce/pixonaut/emails/proof-expired.php` | The "cancelled after deadline" email: a line whose proof the customer did not approve by the deadline was cancelled. |
| `emails/proof-ready.php` | 0.1.0 | `woocommerce/pixonaut/emails/proof-ready.php` | The "proof ready" email: links to the proof pages of a line's designs. |
| `emails/proof-reminder.php` | 0.1.0 | `woocommerce/pixonaut/emails/proof-reminder.php` | The proof reminder email: links to the proof pages of a line's designs again, and on the last reminder, what happens at the deadline. |
| `emails/tracking-changed.php` | 0.1.0 | `woocommerce/pixonaut/emails/tracking-changed.php` | Email to the customer about tracking added or corrected after personalised items shipped. |
| `order/line-picture.php` | 0.1.0 | `woocommerce/pixonaut/order/line-picture.php` | A personalised order line's picture: the customer's design on the product, on the order received page, in My Account and in order emails. |
| `order/proof-links.php` | 0.1.0 | `woocommerce/pixonaut/order/proof-links.php` | Under a personalised order line waiting for the customer's approval, on the order received page and in My Account: a link to each of its proof pages, where the customer approves the design, rejects it or asks for changes. |
| `storefront/button.php` | 0.1.0 | `woocommerce/pixonaut/storefront/button.php` | The Personalise button, in a personalised product's add-to-cart form, directly before WooCommerce's add-to-cart button and in its classes, in its place. |
| `storefront/design-gallery.php` | 0.1.0 | `woocommerce/pixonaut/storefront/design-gallery.php` | The customer's design in the place of the product image, with one picture per side, in the classes of WooCommerce's gallery. |
| `storefront/form-placeholder.php` | 1.2.1 | `woocommerce/pixonaut/storefront/form-placeholder.php` | One side's form as the designer draws it, printed before the SDK runs. |
| `storefront/form.php` | 0.1.0 | `woocommerce/pixonaut/storefront/form.php` | The personalisation form, at the top of a personalised product's add-to-cart form. |
| `storefront/in-cart.php` | 0.1.0 | `woocommerce/pixonaut/storefront/in-cart.php` | An item that is in the cart, in place of the add-to-cart form on its product page: its options and quantity, a button changing its design, a button to the cart and a link to make another. |
| `storefront/personalised-notice.php` | 0.1.0 | `woocommerce/pixonaut/storefront/personalised-notice.php` | The soft note above the add-to-cart row saying this is the customer's design, with a button changing it. |
| `storefront/preview.php` | 0.1.0 | `woocommerce/pixonaut/storefront/preview.php` | The live preview of a personalised product, in place of its gallery. |

<!-- END GENERATED templates -->
