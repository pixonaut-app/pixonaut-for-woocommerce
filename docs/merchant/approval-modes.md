---
title: Approval modes
description: How customers approve their design before it is printed, and how to choose the mode for the store or one product.
sidebar:
  order: 5
---

Nothing is printed until the customer has approved their design. The store
records how and when they agreed, and Pixonaut keeps that consent with the
design once the order is paid.

## Choose a mode

The **Default approval** setting (**Pixonaut > Approval**)
applies to every personalised product. To give one product another mode,
open it, go to the **Pixonaut** tab of its **Product data** box, and choose
it under **Approval**. *Store setting: …* follows the setting, and names the
mode the setting has now.

The mode a product has when the customer adds it to the cart is kept with
the cart line and the order line. Changing the setting or the product later
does not change what that customer agreed to.

When the customer changes the design from the cart, the update is their
approval again, in the same mode ([Editing a design from the cart](../cart-editing/)).

A [fixed design](../linking-products/#fixed-designs) has no design of the
customer's to approve: its design was approved when you updated the
product. A product whose sides are fixed designs asks for no approval in any
mode; one with a personalised side asks for the approval of that side alone.

## Adding to the cart approves the design

Clicking **Add to cart** makes a proof of the design and is the customer's
approval. The consent is recorded as `add_to_cart`, at the moment the product
joined the cart.

## Customer ticks "I approve the preview"

Under the designer the customer sees an **I approve the preview** box, whose
word **preview** is a link to the proof.

- Clicking **Add to cart** without ticking the box adds nothing: a message
  under the box asks the customer to tick it, and goes once they do. A form
  posted without it anyway (a script, or a browser without JavaScript that
  skips the check) is refused with a notice, and nothing is saved in
  Pixonaut.
- **preview** opens the proof of the design as it stands, the image that will
  be printed, in a window over the product page, without ticking the box. The
  customer stays on the page: the window closes with its **Close** button,
  the Escape key or a click outside it. If the customer then adds the product
  without changing the design, that same proof is the one added; a change
  makes a new one.
- Changing the design after ticking the box unticks it, so the customer
  approves the design they end up with.
- The consent is recorded as `checkbox`, at the moment the product joined the
  cart.
- The design records whether the customer opened the preview of the design
  they approved. The **Preview** row of the design in the studio says so: use
  it when a customer disputes a print. In the other modes, and on the
  Personalise button layout, the row says this does not apply.
- On the Personalise button layout the customer approves the preview in
  Pixonaut's designer instead, and there is no box; the consent is still
  recorded as `checkbox`.

If the designer fails to load for a moment (a dropped connection), the box
and the button stay, and the customer's next click tries again.

## Proof by email after payment

The design is saved when the product is added to the cart, but not approved:
adding it records no consent. Once the order is paid, the customer approves a
proof of their design, and nothing is printed or ordered before they do.

1. **At payment** the store reads the design in Pixonaut. If it is approved
   already (for example by your team in the studio), the line goes on to
   print without asking the customer. Otherwise the store makes a proof page
   for the design: a page drawn in your workspace's branding, on your
   workspace's own domain when it has one, where the customer sees the proof
   and approves it, rejects it or asks for changes. Every proof link the
   customer gets, in an email or on the store, opens this page, never the
   proof's bare image.
2. **The customer gets an email**, *Design proof ready*, with a link to the
   proof page for each design of the line still waiting for approval. The
   page is in the designer language, for the language the customer bought
   in ([Designer language](../layouts/#designer-language)). If the email is turned off or could not be sent, an order note
   lists the proof links for you to send the customer yourself.
3. **The line waits.** The order stays **Processing**, gets the note
   "awaiting design approval", and the customer sees "Awaiting your approval"
   under the product in their order, with a **Review and approve your
   design** link to the proof page, on the order received page and in My
   account.
4. **The customer approves** on the proof page. The store hears of it from
   Pixonaut, notes it on the order, and prints and orders the line. If you
   turned on the *Design approved* email, the customer gets it then. An
   approval that comes after the order failed, or after the item was
   cancelled or refunded (see [How orders flow](../how-orders-flow/)),
   prints nothing; an order note says so.

The link in the email works for the **Proof approval deadline (days)** setting
(**Pixonaut > Approval**, 14 days by default) plus a week, and
at most 90 days.

### Reminders and the deadline

A customer who has not answered gets *Design proof reminder* emails with the
proof links again. The settings under **Pixonaut > Approval**
decide when:

- **First proof reminder after (days)**: days after the proof email (2 by
  default).
- **Then remind every (days)**: days between reminders (2 by default).
- **Proof reminders at most**: how many (2 by default; 0 sends none). Only
  reminders that fall before the deadline are sent.

The last reminder also says when the deadline is and what happens then.
Reminders stop as soon as the customer approves, rejects, or the item is
cancelled.

When the deadline passes without an answer, **When no
answer by the deadline** decides:

- **Approve the design as shown**: the store approves the design in Pixonaut
  for the customer (the consent is recorded as `deadline`) and prints and
  orders the line.
- **Deny the design** (the default): the store rejects the design in
  Pixonaut, so it is never printed, and **On deny** applies.

If the customer approved or rejected in the last moments before the store
acted, their decision counts. You get *Pixonaut proof deadline passed*,
saying what became of the item.

The deadline and reminder times are set when the proof email is sent: changing
the settings later applies to new orders.

### Rejected or denied proofs

**On deny** decides what happens to an item whose proof the customer
rejected on the proof page, or which was denied at the deadline:

- **Refund and cancel the item**: the item is cancelled (see
  [How orders flow](../how-orders-flow/)) and its price and tax refunded,
  without the order's shipping. The money goes back through the payment
  method when it can refund on its own; otherwise the refund is recorded and
  an order note asks you to return the money yourself.
- **Cancel the item**: the item is cancelled and nothing is refunded.
- **Mark the item as needing attention** (the default): the item is marked
  for your attention, and you decide whether to refund or cancel it (see
  [Needs attention](../troubleshooting/#needs-attention)).

A customer whose item was cancelled at the deadline gets *Design cancelled
after deadline*, which says whether it was refunded.

### Asking for changes

The proof page also lets the customer ask for changes, in their own words.

1. **They are taken back to the store**, to the product page with the
   designer open on their design. The product's variation and quantity are
   fixed to what they bought.
2. **They make the changes and save.** The button reads **Save and approve
   design**: saving approves the changed design, as **Add to cart** does. In
   the *Customer ticks "I approve the preview"* mode they tick the box again.
   The consent is recorded as `edit`. The line then goes on to print without
   another email, and an order note says the customer changed and approved
   the design.
3. **Editing closes** once the design is approved. The link that brought the
   customer back then says the design can no longer be changed. A link to an
   older version of the proof says the proof has changed.

If the customer closed the page instead, the *Edit your design* email
sends them a link that opens their design again. The store gets *Pixonaut
design changes requested*, with what the customer asked, and the request is
noted on the order. Nothing is printed until the customer approves.

Products on the *Personalise* button layout have no designer on the product
page, so the proof page takes the customer to Pixonaut's correction page
instead ([Corrections](../layouts/#corrections)). Approving there saves and
approves the change, with consent `edit`, and the customer lands on their
order; cancelling takes them to their order
with nothing changed. The same rules close editing, and their messages show
on the order page.

If Pixonaut refuses to make the proof page (for example because the store's
address is not one your workspace allows), the line is marked for your
attention with the reason in an order note, and no email is sent. A design
changed in Pixonaut after it was bought, or approved at another version than
the one bought, is marked for your attention too.

The emails are listed under **WooCommerce > Settings > Emails**, where you
can edit their subject, heading and text. *Design approved* is off until you
turn it on.

See [How orders flow](../how-orders-flow/).
