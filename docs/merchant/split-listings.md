---
title: Listing each value as its own product
description: Show a variable product once per value of one of its attributes, such as with and without a photo, its other options inside each.
sidebar:
  order: 4
---

A variable product is listed once in the shop. When its variations differ in
one way that matters to customers, such as a poster with or without a photo,
it can be listed once per value of that attribute instead, while its other
options (sizes, say) stay inside each.

## The attribute's setting

Go to **Products > Attributes** and edit the attribute, or add it there. Tick
**Show each value as its own product** and save. Every variable product that
uses the attribute for its variations follows it, unless the product says
otherwise.

## The product's own choice

On the product's **Attributes** tab, each global attribute's row has a
**Show each value as its own product** box with three states. A click moves
it to the next one:

| State | Looks like | The product |
|---|---|---|
| Auto | a filled square | follows the attribute's setting, now and when it changes; the text beside the box says which it is |
| On | a tick | is listed once per value, whatever the attribute says |
| Off | an empty box | is listed once, whatever the attribute says |

A new product starts on Auto. Save the product, or use **Save attributes**,
to keep the choice. Screen readers read Auto as a mixed checkbox.

Only one attribute per product is listed this way. When more than one would
be, the first in the product's order of attributes that is set to On wins;
with none On, the first on Auto whose attribute is ticked. Only an attribute
used for variations counts.

## In the shop

The shop page, its category, tag and attribute pages and the shop's product
search show a card for each value of the attribute that a variation sells,
in the order the attribute lists its values. A value with no variation, or
only disabled ones, has no card. Classic themes and block themes (the
Product Collection block on the shop's templates) both show them.

- **Opening a card** opens the product with the card's value chosen, in the
  product's form and in the designer. When every variation with that value
  has the same value of another attribute too, such as a picture sold in one
  size only, the card chooses that one as well.
- **Counting and pages**: each card counts as a product, so "Showing 1–12 of
  N" and the pages count cards.
- **Categories and filters**: each card is in the product's categories and
  tags. A filter by the attribute the product is listed by keeps only the
  chosen values' cards; any other filter keeps or drops all of them, as it
  would the product.
- **Templates that are not live**: a value whose every variation is on a
  paused or unpublished template has no card, as a product would have none;
  a card chooses only among the variations on a live template.
- **Elsewhere** the product is shown once: related products, up-sells,
  cross-sells and a Product Collection block with its own products chosen.

## Each value's card

Each card shows:

- **A title**: the value's own title, or the product's.
- **An image**: the value's own image; without one, the image of the first
  of the value's variations that has an image of its own; else the
  product's.
- **A price**: "From:" the lowest price among the value's variations, sale
  prices counted. When they all cost the same, that one price, as the
  product page shows it. A variation the shop hides because it is out of
  stock counts for neither the price nor the image.

To give a value's card its own title or image, open the product's
**Attributes** tab. Under the attribute's **Show each value as its own
product** box, while the product is listed once per value (On, or Auto with
the attribute ticked), **Each value's card in the shop** lists the
attribute's values:

- **Title for "…"**: leave it empty to show the product's title, which the
  empty field shows in grey.
- **Choose an image for "…"** opens the media library; **Remove the image**
  goes back to the default.

Save the product, or use **Save attributes**. A value added to the row
gets its fields once the attributes are saved.

A title and an image belong to the value, so they come back when the
product is listed by that attribute again.

## Themes

The cards' titles, images and prices replace the product's own where a
theme prints them the way WooCommerce does: the title with WordPress's
`get_the_title()`, the image from the product's image, and the price from
the product's price. Classic themes such as Storefront, and block
themes with the Product Collection block's Product Title, Product Image and
Product Price blocks, all do. A theme or a page builder that writes a
product's title, image or price in its own way shows the product's own on
each card; the cards themselves, their links and their count are the same
in every theme.

Products already set up this way when the plugin is updated get their
cards within a few minutes of the update, from a background job.

A trashed product or variation counts as gone, and a restored one counts
again: a value whose only variations are in the trash has no card.

## Global attributes only

Only global attributes, the ones added under **Products > Attributes**, have
the setting. A product's custom attribute, added on its Attributes tab alone,
has none: the shop's filters, the link to each value and each value's title
and image are kept by the attribute's values, which only a global attribute
has. Move a custom attribute to a global one first: the plugin's
[`move-product-attribute` ability](../../developer/abilities/#moving-products-to-a-global-attribute)
does it keeping each variation, its price and its Pixonaut setup.
