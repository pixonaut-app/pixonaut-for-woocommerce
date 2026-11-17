---
title: Prices from costs
description: How the plugin reads printshop costs from Pixonaut and prices products by the pricing rule, what it stores, its jobs and its route.
sidebar:
  order: 14
---

A product personalised on a blank sells one blank variant per variation (a
simple product sells one). Pixonaut reads what the printshop charges for each
variant, and the plugin prices from that by a rule. The merchant's side is in
[Prices from the printshop's cost](../../merchant/pricing/).

## Reading costs

`Api\VariantCosts::of_blank()` calls
`GET /v1/blanks/{id}/variants/costs?region=<country>` with the store's
integration key and the shop's base country, and reads each row as a
`Pricing\VariantCost`: the product's price and the cheapest shipping, each
with its currency, and how long the printshop says that shipping takes
([Delivery times](#delivery-times)). A variant the printshop publishes no price for has a cost
without one.

Pixonaut reports the printshop's billing currency. `Pricing\CostConverter`
turns a cost into the store's currency (`Pricing\StoreCost`): a part charged
in another currency is multiplied by the day's rate and by the store's
`price_currency_buffer`. `Pricing\ExchangeRates` reads the European Central
Bank's euro reference rates (`eurofxref-daily.xml`) at most once a day, and
only when a cost needs converting, and keeps them in the
`pixonaut_wc_exchange_rates` option. A read that fails keeps the last rates
and is tried again an hour later, as does a file cut short or with a rate of nothing; rates whose day is over a week ago convert nothing
(`PriceState::FLAG_RATE_STALE`), as does a currency without a rate
(`FLAG_CURRENCY`).

Money is counted in thousandths of a currency unit, as integers
(`Pricing\Amount`), from the decimal strings Pixonaut answers to the decimal
string WooCommerce stores as a price.

## Delivery times

Each cost carries `times`: how long the printshop says the variant takes to
the shop's country by its cheapest shipping, as up to three spans of whole
calendar days (`production`, `shipping`, `delivery`, each `{minDays,
maxDays}` or null). `Pricing\DeliveryTimes` reads them, and they are stored
with the cost in the `_pixonaut_price` meta. A printshop answers the spans
it knows: a span that is not two whole day counts, shortest first, reads as
none, and so does a cost read before Pixonaut answered times.

With the store's `delivery_time_show` on, `Storefront\DeliveryRows` adds a
**Delivery time** row (`pixonaut_delivery_time`) to the product's attributes
table, in `div.pixonaut-delivery-time`, for a product or variation in stock:
WooCommerce's table through `woocommerce_display_product_attributes`, and
the Product Specifications block's through
`render_block_woocommerce/product-specifications`, which has no such filter.
A product with nothing else to list (no visible attribute, weight or
dimensions) gets WooCommerce's Additional information tab added for the
rows (`woocommerce_product_tabs`), and the block prints a table of the rows
alone.
On a variable product's page the row holds every variation's text up front,
each distinct text once, stacked in one grid cell so the row keeps the
height of the longest; the chosen variation's carries `is-chosen` and the
others are `visibility: hidden` (`Storefront\VariationSlots`, whose
script follows the form of the product the place names in
`data-pixonaut-variation-product`). On the page of a variable product the
inline designer shows on, a **Delivery** row (`pixonaut_delivery`) holds
the variation's description the same way, in
`div.pixonaut-variation-description`, and the one WooCommerce redraws in
the add-to-cart form is hidden, so the form holds the designer and the buy
row alone. A row no variation has a text for is left out. The time says
only what the printshop tells:

| The printshop tells | The row says |
| --- | --- |
| `delivery`, or both `production` and `shipping` (added together) | 4–7 days |
| `production` alone | Made in 2–3 days |
| `shipping` alone | Shipping takes 5–10 days |
| nothing | no row |

`delivery_time_handling_days` is added to the days that start at the order
(the first two rows), never to the post alone. The time is the one stored
for the variant the product sells now, shipped to the shop's country; any
other has none.

While the setting is on, `Pricing\CostRefresh` reads the costs of every
product printed on a blank (`ProductPricing::on_blanks()`), not only of the
ones with a price that follows the rule, and switching it on starts a read
at once.

## The rule

`Pricing\PriceRule` holds a target margin, a tolerance, a price step with an
ending, and a lowest and highest price. The store's parts are the settings
`price_margin`, `price_tolerance`, `price_step`, `price_ending`, `price_min`
and `price_max` ([Settings](../settings/)); a product's own parts, in its
`_pixonaut_price_rule` meta, come before them. Without a target margin there
is no rule.

- The margin counts on the price excluding tax, the steps and limits on the
  price as the store enters it. `Pricing\PriceTax` turns one into the other
  with `WC_Tax` and the product's tax class at the shop's base address; with
  prices entered excluding tax it changes nothing.
- `price_for()` answers the step nearest the target, a target halfway
  between two steps taking the higher, moved to the nearest step within the
  lowest and highest price; null when no step lies between them.
- `holds()` says whether a price's margin is within the tolerance of the
  target, and the price within the limits.

## Following the rule

`Pricing\ProductPricing` keeps a `Pricing\PriceState` on each product and
variation it prices: the cost last read, whether the rule was applied to it,
the price the rule last set, and what the rule would change now.

- `refresh()` reads the costs, one request per blank, then stores them and
  settles the prices; a read that fails stores nothing. A variant Pixonaut
  has not read yet keeps the cost last read for it. A cost whose total in
  the store's currency is another than at the last read has changed, whether
  the printshop's price moved or the exchange rate did. A stored cost counts only
  while the product still sells the same variant of the same blank and the
  shop is in the same country.
- Before a price or a state is written it is read again from the database:
  one the store manager changed while Pixonaut was asked is left to them.
- `apply()` sets regular prices to the rule's price, and from then on they
  follow the rule. Sale prices are never set.
- `settle()` looks only at prices that follow the rule. One the rule holds is
  left alone. One it does not is set when its prices change automatically
  and its cost just changed, and logged (`WcLogger`, level `info`);
  otherwise the rule's price is stored as a suggestion with a flag.
- Whether a price changes automatically is the store's `price_automatic`,
  unless its product answers it, or its variation answers the product:
  `Pricing\PriceChanges` reads `always` or `never` from the
  `_pixonaut_price_automatic` meta of the variation, then of the product,
  and one without it follows the level above.
- A price that follows the rule and is no longer the one the rule set was
  edited by hand: nothing is set or suggested for it until `apply()` is
  called for it with `$release`.

`Pricing\CostRefresh` refreshes every product with a `_pixonaut_price_watch`
meta (every product printed on a blank while the store shows
[delivery times](#delivery-times)) once a day (`pixonaut_wc_pricing_refresh` starts
`pixonaut_wc_pricing_refresh_page`, 20 products a job), and each product
`Catalogue\ProductRecheck` checks again after a
[blank event](../webhooks/#templates-and-blanks-changed-in-pixonaut).
Pixonaut sends no event for a changed cost.

## Admin

`Admin\PricingPanel` renders the **Prices** section of the Pixonaut tab
(`admin/product-prices.php`) and serves
`POST /pixonaut/v1/products/<id>/prices` with `action` `refresh`, `apply` or
`follow`, optionally `ids` and, for `apply`, the product's `rule` as typed
and its choice for automatic price changes (`changes`: `store`, `always` or
`never`).
It answers the section drawn again, and each regular price as the product
form shows it, which `assets/src/admin/pricing.ts` puts back in the form.
`Admin\PricingList` prints the flag under a product's name on the products
list and handles the **Apply suggested prices** bulk action.
