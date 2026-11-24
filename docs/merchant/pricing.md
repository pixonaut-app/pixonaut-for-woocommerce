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
- **Currency buffer (%)**: see [Costs in another currency](#costs-in-another-currency).
- **Automatic price changes**: see [When a cost changes](#when-a-cost-changes).
- **Lowest margin (%)**: see [A margin below your lowest](#a-margin-below-your-lowest).

Steps and limits count on the price as you enter prices in WooCommerce,
including tax or not (**WooCommerce > Settings > Tax**). The margin always
counts on the price excluding VAT, at your shop's base address.

A product can change any part of the rule for itself, in its **Prices**
section: an empty field there follows the store's, shown in grey. It can
also say whether its own prices change by themselves: see
[One product at a time](#one-product-at-a-time).

### Per attribute value and per variation

A product whose variations cost very different amounts, or that should step
in price by size, can be priced part by part at two more levels:

- **An attribute value, for the whole store.** Open **Products >
  Attributes**, **Configure terms** of a global attribute, and edit a value:
  **Pricing rule for this value** changes parts of the rule for every
  variation with that value, in every product. A poster size can add a fixed
  amount (Posterstorlek 70×100 cm: fixed amount 400), a framing can set its
  own margin (Inramning Ram: target margin 30 %).
- **A variation, for itself.** In the product's **Variations** tab, each
  variation has an **Own price rule** section, closed until it sets a part.
  Its fields show in grey what each part follows without it, and from where.

Each part is looked up on its own, the most specific level first: the
variation, then the product, then its attribute values, then the store. An
empty field follows the level after it. When two values of a variation set
the same part (a size and a framing both setting the fixed amount), the
value whose attribute comes first in the store's attribute order, as
**Products > Attributes** lists them, wins. The value's edit screen warns
about it, and so does each row of the **Prices** table it reaches.

Changing a part at any level reaches the prices that follow the rule: they
are set to the rule's new price when their prices change automatically, and
otherwise flagged on the products list with the price the rule suggests,
until you apply it. A change of the store's rule or of an attribute value
is worked through in the background, a page of products at a time.

## Pricing a product

Open the product, its **Pixonaut** tab, and **Prices**. The section lists
each variation (a simple product has one row) with:

- **Cost**: what the printshop charges for the product and its cheapest
  shipping to your shop's country, added up, with both parts under it.
- **Price**: its regular price now.
- **Margin**: the share of that price, excluding VAT, left after the cost.
- **Contribution**: the amount that price leaves after the cost, excluding VAT.
- **Pricing rule**: the price the rule suggests when it is another, how the
  price stands to the rule, and **Rule from**: the levels its rule comes
  from (Store, an attribute value such as *Ram (Inramning)*, Product or
  Variation). When the parts come from several levels, hover it to see
  where each part comes from.

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
- the printshop gives a price but no shipping cost to your store's country:
  priced without one, the variation would sell as if shipping were free;
- its cost is in a currency there is no exchange rate for, or the exchange
  rate is over a week old (see [Costs in another currency](#costs-in-another-currency));
- no price step lies between the lowest and the highest price.

## Costs in another currency

A printshop charges in its own billing currency, which Pixonaut reports as
it is. When that is not your store's currency, the plugin converts the cost:

- with the European Central Bank's euro reference rate of the day (a rate
  between two other currencies goes through the euro), read once a day;
- plus the **Currency buffer (%)** from **Pixonaut > Settings > Prices**, 3 %
  unless you change it: what your card or bank takes for the exchange, and
  what the rate may move between the sale and the printshop's charge.

The **Cost** column then shows both: the converted cost, marked ≈, and under
it the printshop's own amounts with the rate's day and the buffer, such as
"€16.71 + €3.00 shipping (rate 2026-11-13, +3 %)".

An exchange rate that moves is a cost that moves: a price is kept while its
margin stays within the tolerance, and flagged or changed once it leaves it,
as below.

When the rates cannot be read, the last ones are used, and the day shown
says how old they are. Once they are over a week old the rule prices nothing
with them: prices that follow the rule are flagged instead, until rates can
be read again. A cost in a currency the European Central Bank publishes no
rate for is never converted.

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

### One product at a time

The setting is for the whole store. A product can answer it for itself, with
**Automatic price changes** in its **Prices** section:

- **As the store** (the default): the product does what the store's setting
  says, on or off.
- **Always**: the plugin sets this product's prices itself when a cost
  changes, also with the store's setting off.
- **Never**: this product's prices are only flagged with the price the rule
  suggests, also with the store's setting on. Use it for products whose
  prices you set yourself.

The choice is saved with the product, and when you press **Apply**. A single
variation can answer its product the same way: ask Pixel, Pixonaut's
assistant, to set it. Its row then says **Automatic price changes: always**
or **never**.

## Showing the delivery time

A printshop also tells how long a product takes: to make, to deliver to your
shop's country, or both. Pixonaut reads that with the cost, once a day.

Tick **Show the delivery time** under **WooCommerce > Settings > Pixonaut >
Prices** and each product printed on a blank says it on its page, in a
**Delivery time** row of the product's attributes table (**Additional
information**): "4–7 days". On a product with variations the row is the
chosen variation's, and changes with the choice without moving the page.

Only what the printshop tells is shown, in calendar days:

- A printshop that tells the whole way, or both the making and the
  shipping, gets "4–7 days".
- One that tells only how long the product takes to make gets "Made in 2–3
  days".
- One that tells only how long the parcel is on its way gets "Shipping
  takes 5–10 days".
- One that tells nothing gets no row.

**Handling days** adds days of your own, for what happens before an order
reaches the printshop, such as approving a proof. They are added to
the whole way and to "Made in", not to the shipping alone.

The first times show within a few minutes of ticking the box, once the
costs are read. A product out of stock shows none.

## A margin below your lowest

A price the rule does not move can still lose its margin: one pinned by the
same **Lowest price** and **Highest price**, one you set by hand, one that
never followed the rule, or one whose margin is still inside the tolerance
when the printshop charges more. Set **Lowest margin (%)** under
**WooCommerce > Settings > Pixonaut > Prices** to hear about it.

Each time a cost is read, a price changes or a product moves to another
blank, its prices are checked against it, whatever they follow, and once a
day every price on a blank is, which also catches a cost moved by the
exchange rate or your currency buffer. While a cost cannot be converted for
want of an exchange rate, its price keeps the warning it had. While some
prices are below it:

- a notice on the dashboard and the products screens says how many, with a
  link to **Below lowest margin**, a view of the products list with only
  their products;
- under each such product's name, the list names its prices below the
  floor with their margin;
- once a day, an email to your store names each price that fell below since
  the last one: product, variation, price, cost with shipping, margin, and
  a link to edit it. A price is named once: again only after its margin was
  back at your lowest margin and fell below it once more. One email names
  at most 100 prices and says how many more there are; those are named the
  next day.

The email is **Pixonaut margin below the lowest margin** under
**WooCommerce > Settings > Emails**, where you can turn it off or send it to
other addresses than the site's admin address. Empty, the setting warns of
nothing.

## A price you edit by hand

When you change a price the rule set, on the product or in a bulk edit, the
plugin keeps yours: **Apply**, automatic changes and the products list leave
it alone, and its row says **Edited by hand: kept**. **Follow the rule** on
that row sets it to the rule's price again and has it follow the rule from
then on.
