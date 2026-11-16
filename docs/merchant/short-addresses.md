---
title: Short addresses
description: Product pages, cards and the shop's filters name each attribute by a short name, as in ?size=50x70-cm, which the store can choose per attribute.
sidebar:
  order: 5
---

WooCommerce names an attribute in an address by its form field on a product's
page, `?attribute_pa_size=50x70-cm`, and by its filter on the shop's pages,
`?filter_size=50x70-cm&query_type_size=or`. With the plugin active, the shop's
addresses name each global attribute by a short name instead, and its values
keep their slugs:

```text
/product/safari/?size=50x70-cm&framing=frame&frame-colour=oak
/shop/?size=50x70-cm,30x40-cm
```

- **A product's page** writes the short names into the address as the
  customer chooses options, and opens on the values an address names.
- **A card's page**, its canonical address and its place in the sitemap use
  them (see [Each card's own page](../split-listings/#each-cards-own-page)).
- **The shop's filters** (WooCommerce's Product Filters block and its
  classic filter widgets) write them into the address and read them back,
  and their counts follow.

WooCommerce's own names go on working: a link with `attribute_pa_size=` or
`filter_size=` opens on the same choice, and the browser's address then
shows the short name.

## Choose an attribute's name

Each global attribute goes by its slug unless you give it a name of its
own: go to **Products → Attributes**, edit the attribute, and fill in
**Name in addresses**. Use lowercase letters and digits, words joined by a
hyphen or an underscore, at most 32 characters. Leave it empty to go back
to the slug.

A name WordPress or WooCommerce already reads from an address (`orderby`,
`paged`, `s`, `product`, a category's or tag's base, and the like), or that
another attribute goes by, is refused when you save: the rest of the
attribute saves, and the field says why. An attribute whose slug is such a
name, and has no name of its own, keeps WooCommerce's names.

Changing a name changes the addresses: links shared with the old name no
longer choose the value.

An assistant reads and sets these names with the
[`get-attribute-query-names` and `set-attribute-query-name` abilities](../../developer/abilities/).

Custom attributes, those typed on a product rather than made under
**Products → Attributes**, keep WooCommerce's names.
