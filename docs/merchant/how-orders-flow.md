---
title: How orders flow
description: What happens to a personalised product's design from the moment a customer adds it to the cart until it ships.
sidebar:
  order: 7
---

A customer personalises a product on its product page and approves the
preview. From there the design travels with the product through the cart and
into the order.

## Adding to the cart

When the customer adds a personalised product to the cart, the store saves
the design in your Pixonaut workspace and checks it against the product
before the product joins the cart:

- **Every side is personalised.** A product with a front and a back needs
  a design for both. Without one, the customer sees "Please personalise this
  product before adding it to your cart." A
  [standing side](../linking-products/#standing-sides) needs none: it is
  printed from its standing design, and a product of standing sides alone
  is added to the cart as any other product, with nothing saved in Pixonaut.
- **The design is what the product sells.** Its template, blank, placement
  and print size must be the product's. The print size must be one of the
  sizes the product is sold in, to within a hundredth of a millimetre. A
  design that does not match is deleted from Pixonaut and the customer is
  asked to personalise the product again.
- **The template is current.** A design made from the template's live
  version, or the version just before it, is accepted, so a customer who
  started personalising just before you published a new version can still
  buy. An older one is deleted, and the customer sees "This product was just
  updated, please reload."
- **The design is not used twice.** A design already in the cart, or in an
  order, is not added again. On the Personalise button layout, adding the same
  design again adds a copy of it, as a line of its own.

Each personalised product is its own cart line, even when a customer adds the
same product twice with different designs.

In the cart, mini-cart, Cart block, Mini-Cart and checkout, the line shows
the customer's design on the product in place of the product image, as the
shop showed the product. The order received page, the customer's orders in
My Account and the order emails show it too. **Picture shows** under
**Pixonaut > Storefront** (**Cart and orders**) chooses the view it is drawn
in: the one the customer previewed their design in, or the first of the
placement's views the product is shown in. A product without a blank shows
the design on its own. The images come from your store, which fetches each
one from Pixonaut once and keeps a copy for a week. Once a design's personal
data is erased, its line shows the product's image again.

By default the line shows only the product's name, price and quantity. To
also list what the customer filled in, turn on **List the design's fields**
under **Pixonaut > Storefront** (**Cart and orders**). The values then
show in the cart, the checkout, the customer's orders and the order emails,
in the words the designer showed them in: a palette colour by its name,
any other colour by the hex code the form showed (`#1A1A18`), a size as the
form wrote it. The order screen in the admin lists them either
way.

The store sends each side's placement with its design, for printshops that
need it (Printful does). The recipient follows with the order.

If Pixonaut cannot route the design to a printshop (the blank's printshop is
disconnected, or a product without a blank has no printshop because the
workspace has no default one), the customer is told personalisation is
unavailable and a notice at the top of the admin screens names the product.
Fix the printshop in the Pixonaut studio.

While the store is not connected, or its connection is paused, personalised
products cannot be added to the cart. Nor can a product, or variation, that
sells no variant of its blank, or a retired one (see
[Blank variant](../linking-products/#blank-variant)).

The approval mode the product uses when it is added (see
[Approval modes](../approval-modes/)) is kept with the cart line, so changing
the setting or the product later does not change what the customer agreed
to. With *Adding to the cart approves the design*, the moment the customer
added it is recorded as their approval; with the checkbox, the moment they
added it with **I approve the preview** ticked.

## When a design leaves the cart

A design that never reaches an order is deleted from your Pixonaut
workspace, in the background (**WooCommerce > Status > Scheduled Actions**,
hook `pixonaut_delete_design`):

- **The customer removes the product from the cart**, or sets its quantity
  to 0. The design is kept for an hour first, so the customer can undo the
  removal; undoing it, or adding the same design again, keeps it.
- **The product can no longer be bought**, and WooCommerce takes it out of
  the cart the next time the customer visits.
- **You changed what the design was made on**: the product's blank, or a
  side's template, placement, or the sides themselves. WooCommerce takes the
  product out of the cart the next time the customer visits and tells them
  it was modified. Changing its price, description or stock, or publishing a
  new version of its template, keeps it in the cart.
- **The cart is emptied.** A customer who is logged in and logs out keeps
  their cart for their next visit, and its designs with it.
- **The customer's session expires** (WooCommerce clears expired sessions
  twice a day). A logged-in customer's saved cart is kept.

A design in a placed order is never deleted this way, nor is one Pixonaut
has already approved or printed. If Pixonaut cannot be reached, the store
tries again later.

A guest who logs in before paying keeps the products already in their cart,
with their designs: the order carries the same design, not a copy.

## Placing the order

A design can be deleted while it waits in the cart: in the Pixonaut studio,
or by the store when a cart is left. So before the order is created, the
checkout, classic or block, asks Pixonaut for each personalised line's
designs. A line whose design was deleted, rejected, or had its personal data
erased stops checkout with "The design for "*product*" is no longer
available. Please remove it from your cart, or personalise it again." No
order is created until the customer removes the line or personalises the
product again. If Pixonaut cannot be reached, checkout goes ahead as usual:
the order waits for Pixonaut after payment like any other.

At checkout, classic or block, each personalised order line keeps its
designs: which design each side is, its revision, the template version it was
made from and the approval the customer gave. The order line is what links
the order to its designs in Pixonaut.

Once the order is placed, each design's reference in Pixonaut moves from the
cart to the order line it belongs to. If that fails, the order is placed all
the same and the line keeps its designs.

## After payment

Each personalised line of an order becomes its own printshop order in
Pixonaut. A line with a front and a back is one printshop order holding both
prints, so the printshop makes them as one item. Each line's order goes to
its own printshop: a blank's, or the one a product without a blank chose,
so a cart of products for different printshops becomes one printshop order
per line, each at its printshop.

When an order is paid, the store starts one background job per personalised
line. An order counts as paid when WooCommerce completes its payment, or
when it moves into a paid status (**Processing** or **Completed**): a
cheque or bank transfer order you mark **Completed** from **On hold**, or a
phone order you create straight as **Completed**, is sent like any other.
A line that already has a job waiting gets no second one. The job runs in WooCommerce's scheduled actions
(**WooCommerce > Status > Scheduled Actions**, hook `pixonaut_fulfil_item`)
and takes the line through two steps:

1. **Approve.** Each design of the line is approved for print, with the
   customer's consent: when and how they agreed (for example by adding it to
   the cart). A standing side's design was approved when the product was
   saved, so a line of standing sides alone goes straight on. A line whose approval mode is *proof by email* is not approved
   by the store: the customer is emailed a proof page and the line waits,
   as *awaiting customer*, until they approve it there (see
   [Approval modes](../approval-modes/)). A design approved already, in the
   studio for one, goes straight on.
2. **Order.** One printshop order is placed for the line as soon as its
   designs are approved, with all its designs (the first side leads, and a
   standing side's standing design is among them), the blank variant the line was bought as,
   the line's quantity, and the order's shipping address. The store never
   picks the printshop: the blank the product is made on decides it, or
   the workspace's default printshop for a product without a blank.
   Pixonaut renders the print file of each design and hands the order to
   the printshop once they are done.

Once a day the store also looks for lines that lost their job: a line of a
paid order from the last 30 days that is still *committed* or *approved*,
has no job waiting, and whose last job did not finish (it hit a PHP error
or a timeout, or ran while the plugin was inactive), or that never had one.
It schedules each such line again, so it moves on by itself. A job that
runs while the store is disconnected from Pixonaut, or its connection is
paused, fails in the scheduled actions, and the daily check sends its line
once the store is connected again. A line whose
job finished and left it where it was, because a plugin held it back or it
was made under another workspace, is left alone. Such a line, and any line
of a paid order that waits without a job, offers **Retry** in the order
screen's Pixonaut box.

The line's state moves along *committed → approved → ordered* as each step
completes, with *awaiting customer* between *committed* and *approved* for
proof by email. While Pixonaut renders the prints, the order screen's
Pixonaut box shows the line as *Waiting for prints*, and the customer sees
*In production*. Pixonaut fails the order if a print cannot be
made, or if the prints are not ready within an hour, and the line then needs
attention like any failed order.

Pixonaut orders a design once. If the design already has a printshop order
that the line did not place, for example one placed in the studio, the line
needs attention (`design_already_ordered`); see
[Troubleshooting](../troubleshooting/#reasons).

An order one of your Pixonaut automations placed is the exception, when it
makes the item as bought: every side at the version the customer approved,
the line's quantity and its variant. The store takes that order on as the
line's instead of placing another, follows it from then on, and adds an
order note saying so; the Pixonaut box names the automation. If the
automation's order ships somewhere other than the customer's address, the
store sends it the customer's address, as for a changed address. An
automation's order that makes something else (one side only, fewer copies,
another variant) needs attention like any other.

A design deleted in Pixonaut after the order was placed cannot be printed.
The line then needs attention, with the reason "The design was deleted in
Pixonaut, so it can no longer be printed. Check the order with the
customer." (see [Troubleshooting](../troubleshooting/#reasons)).

## Order again

When a customer chooses **Order again** on a completed order in My Account,
its personalised lines go back in the cart beside the plain products, each
with the design as it was ordered. The store makes a copy of each design in
Pixonaut for the new cart line, checked against the product as it sells now,
like a design added from the product page; the earlier order keeps its own
designs, untouched. A line of [standing sides](../linking-products/#standing-sides)
alone comes back with the product's standing designs.

A line whose design was deleted or erased, or no longer matches the product
(you changed its blank, placement, sizes, or published two new versions of
its template since), is left out, and the customer is asked to personalise
the product again. If Pixonaut cannot be reached, the line is left out and
the customer is asked to try again.

## A line with a quantity

A line of 3 personalised T-shirts is one printshop order for 3, not three
orders: all of them share one design.

## Shipping address

The printshop order is sent to the order's shipping address, or to its
billing address when the order has no shipping address. The recipient is
the customer's name (or the company, when there is no name), with the
billing email and phone for the carrier's notices.

When you change the order's shipping address (or its billing address, when
it ships there) after the printshop order was placed, and update the order,
the store sends the new address to Pixonaut, which passes it on to the
printshop. An order note says what came of it, and the Pixonaut box on the
order screen shows it:

- **Taken by the printshop.** The order ships to the new address.
- **Goes with the order.** The printshop had not been sent the order yet, so
  it gets the new address with it.
- **Pixonaut is telling the printshop.** A note follows once the printshop
  answers.
- **Refused, or not sent.** The order ships to the address it had. The note
  says why: the printshop is already making it or it has shipped, the
  printshop takes no address changes through Pixonaut, or it needs a
  postcode. Ask the printshop directly.
- **Not known whether it reached the printshop.** Pixonaut could not be
  reached, or could not reach the printshop, however often the store
  tried. Update the order to send it again.

While the store is not connected to the Pixonaut workspace the order was
placed under, the new address is not sent, and a note says so. Update the
order once it is connected to send it.

An address changed in the Pixonaut studio is noted on the order too; the
WooCommerce order keeps the address it had.

## Printing and shipping

As the printshop works on a printshop order, Pixonaut tells the store, and
the plugin notes each step on the WooCommerce order: when the item is printed
and when it ships, with the carrier and tracking number when the printshop
gives one. Each personalised line ships on its own, so the items of one order
can reach the customer at different times.

When a personalised item ships:

- **Every item of the order is personalised and has shipped**: the order is
  marked **Completed**, and WooCommerce sends the customer its *Completed
  order* email, which lists each item with its tracking.
- **Other personalised items are still on their way**: the order stays
  **Processing**, and the customer gets the *Part of your order has shipped*
  email, with the item and its tracking. An order with a single line gets no
  such email: its shipment completes it.
- **The order also has items the plugin does not fulfil** (a mixed order):
  the plugin never completes it. The customer gets the *Part of your order
  has shipped* email for each personalised item, and once the last one ships
  an order note says "All personalised items shipped", so you know the rest
  is yours to ship. Complete the order yourself when it has.

Once an order is no longer **Processing**, for example because you completed
it yourself, a later shipment is still noted on the order, but sends no *Part
of your order has shipped* email and adds no "All personalised items shipped"
note.

When the printshop adds tracking to an item that shipped without any, or
corrects its tracking, the order gets a note with the new tracking (and the
old one, for a correction), and the customer gets the *Tracking updated*
email with the item and its tracking as it now is. Tracking that reaches the
store before the shipment itself is kept for it and sent with the shipment's
email.

The *Part of your order has shipped* and *Tracking updated* emails are
WooCommerce emails: turn them off or edit them under **WooCommerce > Settings
> Emails**.

### What the customer sees

Under each personalised item, in **My Account > Orders**, on the order
received page and in every order email, the customer sees its tracking with a
link to the carrier once it has shipped. The item's status (for example *In
production* or *Shipped*) names a step of your fulfilment, so only the order
screen in the admin shows it, under the item. A developer can change each
text; see [Label keys](../../developer/label-keys/).

## Cancelling and refunding

Cancelling a WooCommerce order, or refunding it in full, cancels each of its
personalised items in Pixonaut. Refunding an item's whole quantity cancels
that item; refunding part of its quantity (1 of 3, say) changes nothing in
Pixonaut and adds an order note, since a printshop order is cancelled whole.
Cancelling never moves money: refund the customer in WooCommerce as usual.
With proof by email, a proof the customer rejected or did not approve in
time can cancel, or refund and cancel, the item on its own; see
[Approval modes](../approval-modes/#rejected-or-denied-proofs).

What happens depends on how far the item got:

- **Not yet sent to the printshop**: the item is not sent, a design still
  waiting for approval is rejected in Pixonaut, and the item is
  **Cancelled**.
- **Sent to the printshop**: Pixonaut asks the printshop to stop the order.
  Once it has, the item is **Cancelled** and an order note says so.
  - If the printshop has made the order already, or has begun it and does
    not cancel it, the item stays as it is and an order note says why. Ask
    the printshop.
  - If the printshop cannot be asked right now, the store asks again later,
    as it does when sending an item. If it still cannot, an order note tells
    you to ask the printshop yourself.
  - If the printshop order had failed, there is nothing to stop, and the item
    is cancelled in the store.
- **Shipped**: nothing changes.

An order cancelled in Pixonaut or by the printshop cancels its item in the
store too.

If the store sent an item to the printshop but never heard back, it looks the
printshop order up first and cancels it if it exists. If an order note says
an item "is not cancelled yet", the store could not schedule the
cancellation: click **Update** on the order to try again. Until then the
item is not sent to the printshop.

## Nothing is sent twice

Paying twice, a payment notice that arrives twice, or an order moved to
Processing by hand after payment, starts no second job while one is waiting
or running, and a line that already has its printshop order is left alone.

Every step is stored as it completes. A job that stops part way (the server
restarted, Pixonaut could not be reached) carries on where it stopped the
next time it runs: it sends exactly what it sent before, and if Pixonaut had
already placed the printshop order, the store finds it under the line's
reference and keeps it rather than placing another. This holds across a
reconnect of the store, too.

If Pixonaut cannot take a step, the line is retried later or needs your
attention; see [Troubleshooting](../troubleshooting/).

## Orders held on the free plan

On Pixonaut's free plan, a workspace places a set number of orders a month.
Orders past that number are not refused: Pixonaut takes them and holds them.
The order screen's Pixonaut box shows the line as *Held by Pixonaut*, with
the reason and the date the month resets, and an order note says the same.
A held order is not a failure: the line does not need attention, the store
does not send it again, and no email goes out.

Pixonaut sends a held order on to the printshop as soon as the workspace
upgrades, or when the month resets, oldest first, while the new month has
room. An order note then says it was released and why, and the line goes on
as any other order. Should Pixonaut release it as failed or cancelled
instead, the line needs attention or is cancelled, as for any other order,
with a note saying so.

## When the store cannot send a line

- **The store is not connected, or the connection is paused**: nothing is
  sent. The line waits.
- **The line was ordered under another Pixonaut workspace** than the one the
  store is connected to now: it is never sent. An order note says so.
- **The line does not record the customer's consent**: it is not approved,
  and an order note says so.
- **Pixonaut refuses the proof page** of a proof-by-email line: the line
  needs attention, and an order note gives Pixonaut's reason.
