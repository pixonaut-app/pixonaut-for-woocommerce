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

Each card shows the product's title, image and price.

Products already set up this way when the plugin is updated get their
cards within a few minutes of the update, from a background job.

## Global attributes only

Only global attributes, the ones added under **Products > Attributes**, have
the setting. A product's custom attribute, added on its Attributes tab alone,
has none: the shop's filters, the link to each value and each value's title
and image are kept by the attribute's values, which only a global attribute
has. Move a custom attribute to a global one first: the plugin's
[`move-product-attribute` ability](../../developer/abilities/#moving-products-to-a-global-attribute)
does it keeping each variation, its price and its Pixonaut setup.
