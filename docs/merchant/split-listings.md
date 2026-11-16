---
title: Listing each value as its own product
description: Show a variable product once per value of one of its attributes, such as with and without a photo, or per combination of the values of several, its other options inside each.
sidebar:
  order: 4
---

A variable product is listed once in the shop. When its variations differ in
one way that matters to customers, such as a poster with or without a photo,
it can be listed once per value of that attribute instead, while its other
options (sizes, say) stay inside each.

## The attribute's setting

Go to **Products > Attributes** and edit the attribute, or add it there.
Choose in **Cards in the shop** and save:

| Setting | Every variable product using the attribute for its variations |
|---|---|
| One card for all values | is listed once for all of the attribute's values (the default) |
| A card per value | is listed once per value |
| A card per value while filtered by it | is listed once per value only while a customer filters the shop by this attribute (see [Only while filtered](#only-while-filtered)) |

Each product follows it, unless the product says otherwise.

## The product's own choice

On the product's **Attributes** tab, each global attribute's row has a
**Cards in the shop** menu:

| Choice | The product |
|---|---|
| Auto: … , as the attribute | follows the attribute's setting, now and when it changes; the choice names what the attribute does |
| A card per value | is listed once per value, whatever the attribute says |
| A card per value while filtered by it | is listed once per value only while the shop is filtered by the attribute, whatever the attribute says |
| One card for all values | is listed once for all of the attribute's values, whatever the attribute says |

A new product starts on Auto. Save the product, or use **Save attributes**,
to keep the choice.

Only an attribute used for variations counts. A product with an attribute
set to a card per value (always or while filtered) is listed by the
attributes it set; with none set, by those on Auto whose attribute lists a
card per value.

## More than one attribute

A product listed by more than one attribute has a card per combination of
their values that a variation sells. A poster with **Framing** (None,
Hanger, Frame) and **Hanger colour** (Oak, Black), listed by both, has the
cards "None", "Hanger, Oak", "Hanger, Black" and "Frame": none for a
combination no variation sells, such as a bare poster in a hanger colour.

A variation that leaves a listed attribute on **Any** is in the card of
each of its values, so each such attribute multiplies the cards. A product
that would have more than 250 cards is listed once instead. When the attribute does not apply to the variation (it
is one of the
[options that depend on each other](../dependent-options/)), the variation
has no value of it instead: the bare poster's card is "None" alone. When no
listed attribute applies to a variation, as for the bare and the framed
poster of a product listed by **Hanger colour** only, those variations
share one card, which shows the product's own title and opens it with
nothing chosen.

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
  The product's other attributes open on its default values, and one
  without a default on the value of the first variation with the card's
  value that customers can buy, so the page opens on a whole variation.
  While the shop's filters are on, the card opens on the card's variation
  the filters chose instead (see
  [Filters in the shop](../dependent-options/#filters-in-the-shop)).
- **Counting and pages**: each card counts as a product, so "Showing 1–12 of
  N" and the pages count cards.
- **Categories and filters**: each card is in the product's categories and
  tags. A filter by an attribute the product is listed by keeps only the
  cards with a chosen value. Every filter, the price included, keeps a card
  only while one of its variations matches all of them at once (see
  [Filters in the shop](../dependent-options/#filters-in-the-shop)).
- **Templates that are not live**: a value whose every variation is on a
  paused or unpublished template has no card, as a product would have none;
  a card chooses only among the variations on a live template.
- **Elsewhere** the product is shown once: related products, up-sells,
  cross-sells and a Product Collection block with its own products chosen.

## Only while filtered

Some attributes change how a design is sold, not which design it is. A
poster sold **Med bild** and **Utan bild** (two designs) and with a
**Framing** of None, Frame or Hanger should show two cards, not six. Set
the design attribute to **A card per value** and the framing to **A card
per value while filtered by it**:

- With no filter on the framing, the product shows a card per design:
  "Med bild" and "Utan bild". A filter by the design keeps its card.
- While a customer filters by the framing (**Framing: Frame**, say), the
  product shows a card per design in that framing: "Med bild, Frame" and
  "Utan bild, Frame", each with its own title, image, price and link. A
  design not sold in the framing has no card then.
- A product listed only by attributes set this way shows as itself until a
  filter chooses one of their values.

The filters' counts follow the same rule. Each card has its own page and its
place in the sitemap, filtered or not. An assistant sets it with the
[`set-attribute-split-listing` and `set-product-split-listing` abilities](../../developer/abilities/),
as `filtered`.

## Each card's own page

A card's link is its page: the product's address with the card's values
chosen, such as `…/product/safari/?attribute_pa_picture=with-photo`. The
page opens with those values chosen in the product's form and in the
designer, and it is a page of its own to search engines:

- **Title**: the card's own title, else the product's followed by the
  card's values ("Safari – With photo").
- **Description**: the card's values, then the start of the product's short
  description (or of its description).
- **Picture** (Open Graph): the card's image, as its card shows it.
- **Canonical address**: the card's own. An address that chooses more on
  top of the card's values, such as a size
  (`…&attribute_pa_size=70x100`), opens with the size chosen and names the
  card's page as its canonical address, so the sizes of a card are one page
  to a search engine, not one each.

The product's own address stays the product's page, with its own title and
canonical address. A product behind a password has no card pages until it
is unlocked.

With Yoast SEO or Rank Math active, their title, description, canonical
address and Open Graph tags are the ones changed, and a description written
for the product there follows the card's values. Without either, WordPress
prints the title and the canonical address, and the plugin prints the
description and the Open Graph tags.

**Sitemaps** list each card's page beside the product's, in a sitemap of
the cards: WordPress's own index names it
(`wp-sitemap-pixonautcards-1.xml`), and so do Yoast SEO's and Rank Math's
(`/?pixonaut_wc_card_sitemap=1`, which the plugin serves). Only the cards' pages are
listed, never one per size or per variation. A card the shop leaves out
(its variations all on a template that is not live) and the cards of a
product that is not published are not listed.

**The address follows the customer's choice.** On a personalised product's
page, when the customer changes a value in the product's form or in the
designer, the address in the browser changes with it, without adding to the
browser's history, so a link copied or shared opens the same choice.

## Each value's card

Each card shows:

- **A title**: the value's own title, or the product's.
- **An image**: the value's own image. Without one, the flat picture of
  its design: the template drawn with the product's showcase set, as
  Pixonaut draws it for the card's first variation that has one. A card
  shown while the shop is filtered by an attribute listed only while
  filtered (a framing) shows instead the picture of its first variation
  that has one, such as the framed poster on a wall. Without either, the
  product's. The flat pictures are drawn in the background once the
  product is listed as cards, while the store uses Pixonaut pictures as
  product images; until then a card shows its variation's picture.
- **A price**: "From:" the lowest price among the value's variations, sale
  prices counted. When they all cost the same, that one price, as the
  product page shows it. A variation the shop hides because it is out of
  stock counts for neither the price nor the image.

To give a value's card its own title or image, open the product's
**Attributes** tab. Under the attribute's **Cards in the shop**
menu, while the product is listed once per value (always or while
filtered, or Auto with the attribute listing a card per value), **Each value's card in the shop** lists the
attribute's values:

- **Title for "…"**: leave it empty to show the product's title, which the
  empty field shows in grey.
- **Choose an image for "…"** opens the media library; **Remove the image**
  goes back to the default.

Save the product, or use **Save attributes**. A value added to the row
gets its fields once the attributes are saved.

A product listed by more than one attribute has the fields of each
combination's card ("Hanger, Oak") on the row of the combination's last
attribute, once the product is saved listed by both. A card keeps its
title and image while the product is listed another way, and when its
attributes change places; it loses them when the product loses one of its
values.

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
cards within a few minutes of the update, from a background job. Until
then, a product whose cards the update changes (one with more than one
attribute turned on, which earlier versions listed by the first alone) is
shown as itself.

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
