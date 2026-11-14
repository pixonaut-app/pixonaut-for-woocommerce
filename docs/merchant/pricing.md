---
title: Prices from the printshop's cost
description: Price a product's variations from what the printshop charges, with a target margin, a tolerance, price steps and a lowest and highest price, and follow the costs when they change.
sidebar:
  order: 4
---

A product printed on a blank costs what its printshop charges for it: a
product price and shipping, per size and colour. Pixonaut reads those costs
from the printshop, and the plugin can set your prices from them, so you do
not look up a cost and work out a price for every variation.

Nothing is priced until you set a target margin and press **Apply** on a
product. The plugin never changes a price you set yourself.

## The pricing rule

**Pixonaut > Settings > Prices** holds the store's rule:

- **Target margin (%)**: the share of a price, excluding VAT, that is left
  after the printshop's product price and shipping. With 50, a variation that
  costs 167 is priced at 334 excluding VAT, which is 417.50 with 25 % VAT.
  Empty, there is no rule, unless a product sets a margin of its own.
- **Tolerance (percentage points)**: how far a margin may drift from the
  target, either way, before the price moves. With a target of 50 and a
  tolerance of 10, a price is kept while its margin is between 40 and 60.
- **Price step** and **Prices end in**: the prices the rule may set. A step
  of 10 ending in 9 gives 379, 389, 399; a step of 5 ending in 0 gives every
  five; a step of 1 ending in 0 whole amounts; a step of 1 ending in 0.99
  gives 398.99, 399.99. The ending is below the step. The rule never sets a
  price off these steps.
- **Lowest price** and **Highest price**: the rule never sets a price below
  or above them. Empty for no limit.
- **Automatic price changes**: see [When a cost changes](#when-a-cost-changes).

Steps and limits count on the price as you enter prices in WooCommerce,
including tax or not (**WooCommerce > Settings > Tax**). The margin always
counts on the price excluding VAT, at your shop's base address.

A product can change any part of the rule for itself, in its **Prices**
section: an empty field there follows the store's, shown in grey.

## Pricing a product

Open the product, its **Pixonaut** tab, and **Prices**. The section lists
each variation (a simple product has one row) with:

- **Cost**: what the printshop charges for the product and its cheapest
  shipping to your shop's country, added up, with both parts under it.
- **Price**: its regular price now.
- **Margin**: the share of that price, excluding VAT, left after the cost.
- **Pricing rule**: the price the rule suggests when it is another, and how
  the price stands to the rule.

**Refresh costs** reads the costs from Pixonaut again. **Apply to all
prices** stores the rule's fields as you typed them, reads the costs, and
sets every regular price to the step nearest the target margin, within the
lowest and highest price. From then on those prices **follow the rule**.
**Apply** beside one suggested price sets only that one.

Sale prices are left alone. A price the rule would set at or below a
variation's sale price is not set, and the row says so: change or remove
the sale price first.

A row the rule cannot price says why, and keeps the price it has:

- the printshop publishes no price for it;
- its cost is in another currency than your store's (costs are never
  converted);
- no price step lies between the lowest and the highest price.

## When a cost changes

Pixonaut keeps the costs current. The plugin reads them again once a day
for every product with a price that follows the rule, and whenever the
product's blank changes in Pixonaut.

A price that follows the rule is **kept while its margin stays within the
tolerance**. With a price of 399, a cost that drops a little leaves the
price at 399; only once the margin leaves the tolerance does the price move,
to the step nearest the target.

What happens then is your choice, under **Automatic price changes**:

- **Off** (the default): the product is flagged on the products list, under
  its name, with the price now and the one the rule suggests. **Apply
  suggested prices**, there or as a bulk action, sets them. The product's
  **Prices** section shows the same, per variation.
- **On**: the plugin sets the price itself and logs each change under
  **WooCommerce > Status > Logs** (source `pixonaut-api`): the product, the
  old and the new price, the cost and the margin.

Only prices you applied the rule to change this way. A product you never
pressed **Apply** on keeps its prices whatever the setting.

## A price you edit by hand

When you change a price the rule set, on the product or in a bulk edit, the
plugin keeps yours: **Apply**, automatic changes and the products list leave
it alone, and its row says **Edited by hand: kept**. **Follow the rule** on
that row sets it to the rule's price again and has it follow the rule from
then on.
