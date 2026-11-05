---
title: Pixonaut for WooCommerce
description: Sell personalised products from your WooCommerce store, printed through Pixonaut.
sidebar:
  order: 0
---

Pixonaut for WooCommerce connects your store to a Pixonaut workspace. You link
products to Pixonaut blanks and templates, each product sold as one of its
blank's variants (a colour in a size); customers personalise them on the
product page, and paid orders go to your printshop through Pixonaut.
[Getting started](./getting-started/) takes one product from setup to its
first shipped order.

## Guides

- [Getting started](./getting-started/): one product from setup to its
  first shipped order.
- [Connect your store](./connect/): connect to a Pixonaut workspace or its
  sandbox, read the connection card, run a staging copy, disconnect, and
  what deactivating or deleting the plugin does.
- [Link a product](./linking-products/): link a product and its variations
  to a blank, its sides, views and variant, a template and print sizes.
- [Product page layouts](./layouts/): how customers personalise a product
  on its page, and what they see when they cannot.
- [Approval modes](./approval-modes/): how customers approve their design
  before it is printed.
- [Editing a design from the cart](./cart-editing/): changing a design
  after adding it to the cart.
- [How orders flow](./how-orders-flow/): a design's way from the cart to
  the printshop, and back as printed and shipped.
- [Privacy requests](./privacy/): exporting and erasing customers'
  personalised designs and delivery details.
- [Updates](./updates/): turning updates on, the beta channel, and major
  updates.
- [Troubleshooting](./troubleshooting/): products that cannot be bought,
  order lines Pixonaut turns down, and "needs attention" reasons.

Developers extending the plugin from a theme or another plugin start at the
[developer guide](../developer/).

## Requirements

- WordPress 6.9 or newer
- WooCommerce 10.6 or newer
- PHP 8.1 or newer

The plugin shows a notice and stays inactive when WooCommerce is missing,
WooCommerce is older than 10.6, or PHP is too old. With WooCommerce missing
or too old it still offers its own updates; on a PHP that is too old it does
not, so update PHP first. It works with WooCommerce's order storage (HPOS) and with the
Cart and Checkout blocks.

## Languages

The plugin's admin screens, product page, cart, emails and order notes are in
the site's language (**Settings > General > Site Language**), and in the admin
each user's own language from their profile. Besides English, the plugin
speaks:

- Swedish: `sv_SE`
- Danish: `da_DK`
- Norwegian Bokmål: `nb_NO`
- Finnish: `fi`
- German: `de_DE`, `de_DE_formal`, `de_AT`, `de_CH`
- French: `fr_FR`, `fr_BE`, `fr_CA`
- Spanish: `es_ES`, `es_MX`, `es_AR`, `es_CO`, `es_CL`
- Dutch: `nl_NL`, `nl_NL_formal`, `nl_BE`

On a site in any other language they are in English. The Pixonaut designer
has a language setting of its own; see
[Designer language](./layouts/#designer-language).

## Settings

Pixonaut has its own item in the admin menu, **Pixonaut**, for users who may
manage WooCommerce. Its first tab, **Connection**, has the connection card:
connect the store first ([Connect your store](./connect/)), then link
products ([Link a product](./linking-products/)). Links to the old
**WooCommerce > Settings > Pixonaut** tab open this page.

The other tabs hold the settings, grouped by what they decide:
**Storefront** has **Product page designer**, **Approval** has **Approval**
and **Proof by email**, and **Updates** has the update channel. Each field
has its description under it, and each tab saves its own settings. A setting that only applies while another has a certain
value shows, indented under it, only then: **Custom accent colour** while
**Designer accent colour** is *A custom colour*, and the reminder intervals
while **Proof reminders at most** is above 0. A field marked with a red
asterisk is required while it shows: saving **A custom colour** without a
colour keeps the colour it had and says what is missing.

- **Default layout**: how personalised products show the designer. *Inline*
  shows a live preview in place of the product image; *Personalise button*
  opens the Pixonaut designer. A product can override it. See
  [Product page layouts](./layouts/).
- **Default approval**: how customers approve their design before it is
  printed: adding to the cart approves it, the customer ticks "I approve the
  preview", or the customer approves a proof sent by email after payment. A
  product can override it. See [Approval modes](./approval-modes/).
- **Proof approval deadline (days)**, **When no answer by the deadline**,
  **On deny** and the three proof reminder settings: what happens with
  proof by email when the customer does not answer, or rejects the proof.
  See [Reminders and the deadline](./approval-modes/#reminders-and-the-deadline).
- **Updates**: stable releases only, or beta releases too. See
  [Updates](./updates/), which also explains how to turn updates on.

Problems the plugin finds, such as a product that can no longer be sold, show
as notices at the top of the admin screens until they are fixed. Some can be
dismissed. [Troubleshooting](./troubleshooting/) lists them and what to do.

## Orders

As the printshop works on a personalised item, Pixonaut tells the store, and
the plugin notes each step on the order: when the item is printed and when it
ships, with the carrier and tracking number when the printshop gives one. An
order whose items are all personalised is marked **Completed** once every
item has shipped; an order with other items is never completed by the
plugin. See [Printing and shipping](./how-orders-flow/#printing-and-shipping).

## Privacy requests

WordPress's **Tools > Export Personal Data** and **Tools > Erase Personal
Data** cover customers' personalised designs and the delivery details sent
to the printshop. An erasure never stops an order on its way: a paid item is
erased once it has shipped or been cancelled. See
[Privacy requests](./privacy/).
