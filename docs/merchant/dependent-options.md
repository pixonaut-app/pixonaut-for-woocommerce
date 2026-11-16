---
title: Options that depend on each other
description: Hide an attribute on the product page while it does not apply to what the customer chose, such as a hanger colour on a poster bought without a hanger, and keep it off the order.
sidebar:
  order: 5
---

Some options only make sense with another one. A poster sold bare, with a
hanger, framed or on canvas has a **Hanger colour** only with a hanger, a
**Frame colour** only with a frame and a **Canvas depth** only on canvas.
The product page shows each of them only while it applies to what the
customer chose, and an order never lists one that did not.

It works for every variable product, personalised or not, and needs no
setting: the product's variations say which option applies when.

## Setting up the variations

Add every option as an attribute used for variations, as usual. On each
variation, set the attributes that apply to it, and leave the ones that do
not on **Any**:

| Variation | Framing | Hanger colour | Frame colour | Canvas depth |
|---|---|---|---|---|
| Bare | None | Any | Any | Any |
| Hanger, oak | Hanger | Oak | Any | Any |
| Hanger, black | Hanger | Black | Any | Any |
| Frame, black | Frame | Any | Black | Any |
| Frame, white | Frame | Any | White | Any |
| Canvas, 2 cm | Canvas | Any | Any | 2 cm |
| Canvas, 4 cm | Canvas | Any | Any | 4 cm |

Put the attribute the others depend on first in the product's
**Attributes** tab.

## On the product page

- Before the customer chooses, every option shows.
- After each choice, an option shows only while a variation that still
  matches the choices sets it. With **None** chosen, the three others are
  gone; with **Hanger**, only the hanger colour shows.
- An option that shows again starts empty, for the customer to choose.
- Where the designer's form shows each option in a
  [group of its own](../layouts/#product-options-as-their-own-groups), the group of an option that does not
  apply is gone too, and the groups after it are numbered again.

The cart, the checkout, the order and its emails list only the options that
applied. Orders placed before the plugin did this keep what they listed.

## When an option is not hidden

- **An attribute no variation sets** (every variation says **Any**) is the
  customer's free choice, such as any colour at one price. It always
  shows.
- **An attribute every variation sets** always applies.
- **A variation for the rest**: when one variation sets a value and another
  leaves the same attribute on **Any** for the same other choices (red at
  its own price, any other colour at another), the attribute applies and
  shows.

So to hide an option, give each of its values a variation of its own on
the choices it belongs to, as in the table above; a single "Hanger, any
colour" variation makes the hanger colour a free choice that always shows.

## Themes

The options are hidden in WooCommerce's own variation form, which most
themes use, and in its **Add to Cart + Options** block. A theme that
draws its own form in place of WooCommerce's shows every option; the order
still lists only the ones that applied.

## In the shop

A product can be [listed once per value](../split-listings/) of such
options, with a card per combination that a variation sells.

## Filters in the shop

The shop's and its categories' attribute and price filters keep a product
only while one variation a customer can buy matches every filter at once:

- **Framing: None** with **Frame colour: Oak** lists no poster above, since
  no variation is bare and oak.
- **Size: 30×40** with a price of **299–391** lists a product only when
  its 30×40 variation costs within that range, not when another size does.
- Within one attribute, any chosen value matches (**Oak** or **Black**). A
  variation that leaves an attribute on **Any** matches any of its values,
  unless the attribute does not apply to it.
- Each value's count beside its checkbox is what the shop would list with
  that value added to the filters, counting each card of a product
  [listed once per value](../split-listings/).

A variation counts while it is enabled, has a price, and is in stock or
the store shows products out of stock. Simple products match as before.
The counts are those of WooCommerce's **Product Filters** block, which
block themes such as Pixonaut Shop use.

A theme can also nest an option under the values of the option it depends
on (**Frame colour** under **Frame** and **Hanger**): the plugin reads that
from the variations, never from the names of the options.
