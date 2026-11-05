---
title: Troubleshooting
description: Why a personalised product cannot be bought or added to the cart, what happens when Pixonaut turns a personalised order line down, how the store retries, what each "needs attention" reason means, and the order screen's Pixonaut box.
sidebar:
  order: 10
---

## A product cannot be bought

A personalised product whose Pixonaut setup cannot be printed is not for
sale: the product page offers no **Add to cart**, and a notice at the top of
the admin screens says "Customers cannot buy …" with the reason and a link to
the product. Open the product's **Pixonaut** tab: it says the same.

| Reason | What to do |
|---|---|
| The template is paused or deleted in Pixonaut. | Make the template live again in the Pixonaut studio, or choose another template for the product. The product is checked again on its own when the template goes live. |
| The blank is deleted in Pixonaut. | Restore the blank in the Pixonaut studio, or choose another blank. A restored blank is checked again on its own, and the product sold again with the print sizes you had chosen. |
| The blank, placement or template no longer exists in Pixonaut. | Choose another blank, placement or template, then update the product. |
| A print size is larger than the placement can print, or does not match the template's shape. | Change the print sizes, or fix the blank in Pixonaut; a changed blank is checked again on its own. |
| Pixonaut could not check the configuration. | Check the connection under **Pixonaut > Connection**, then update the product. |
| Pixonaut would not make the design of a standing side. | Check the side's template, placement and the print size marked **Starts selected**, then update the product. See [Standing sides](../linking-products/#standing-sides). |
| Pixonaut would not make the design of a standing side: the store may not create and approve designs. | Check the connection's permissions in the Pixonaut studio, then update the product. |
| The template of a standing side does not fit the placement at any of the blank's sizes, so none of them can be printed. Choose a template that fits. | Choose a template that fits the placement at the sizes you sell, then update the product. See [Sizes a template cannot be printed at](../linking-products/#sizes-a-template-cannot-be-printed-at). |
| The configuration was saved by a newer version of Pixonaut for WooCommerce. | Update the plugin ([Updates](../updates/)). |
| The product, or a variation, is no variant of its blank, or its variant is retired. The notice says "Customers cannot buy all of …" and names each. | Pick the variant in the product's or the variation's **Blank variant** field, then save. See [Blank variant](../linking-products/#blank-variant). |

The store checks its products again whenever a template or blank changes in
Pixonaut (see [Link a product](../linking-products/#when-templates-and-blanks-change-in-pixonaut)),
so a product can stop or start selling without anyone editing it.

## Customers cannot add personalised products to the cart

When Pixonaut does not save customers' designs, each customer sees a message
at add to cart, and you see one notice at the top of the admin screens, with
a line in the `pixonaut-api` log under **WooCommerce > Status > Logs**:

- **"Pixonaut suspended the workspace"** appears at the first refusal. Check
  the workspace's plan and billing in the Pixonaut studio.
- **"Pixonaut keeps refusing or not answering"** appears when 5 designs could
  not be saved within 15 minutes: Pixonaut is down or refusing the store.
  Check the workspace in the Pixonaut studio and the log for the error codes.

The notice is not repeated for each failed design, and goes away on its own
once a design is saved again. A notice that Pixonaut no longer accepts the
store's key is about the connection instead: see
[Paused connection](../connect/#paused-connection).

## A product cannot be personalised

The notice "Customers cannot personalise …: this version of Pixonaut for
WooCommerce is older than the product's template" means the template uses
something the designer in this version of the plugin cannot show. Update
Pixonaut for WooCommerce ([Updates](../updates/)). The notice goes away with
the update; if the template is still too new for the updated plugin, the next
customer to open the product raises it again.

## Order lines

A paid personalised line goes to the printshop in a background job (see
[How orders flow](../how-orders-flow/#after-payment)). When Pixonaut does not
take a step, the store either tries again later or stops the line and asks
you to look at it.

A line shown as *Held by Pixonaut* is not stuck: your Pixonaut workspace is on
the free plan and has used its orders for the month. Pixonaut sends the order
on when you upgrade or when the month resets (see
[How orders flow](../how-orders-flow/#orders-held-on-the-free-plan)).

## Retries

A failure that may pass on its own is retried: Pixonaut could not be reached,
answered with a server error, or was busy (`lock_timeout`,
`rate_limited`). The line
keeps its state and is tried again after 1 minute, then 5 minutes, 30
minutes, 2 hours and 12 hours. Every retry sends exactly the request the
first try sent, so nothing is ever printed or ordered twice.

Retries show in **WooCommerce > Status > Scheduled Actions** as a pending
`pixonaut_fulfil_item` action for the line, and each failure is written to
the WooCommerce log (**WooCommerce > Status > Logs**, source `pixonaut-api`).

If the last retry fails too, the line needs attention, with the reason
`retries_exhausted`.

Developers can change the waits with the `pixonaut_wc_fulfilment_retry_schedule`
filter.

## Needs attention

A line the store cannot take further on its own moves to *needs attention*.
Then:

- an **order note** says what happened, with the reason Pixonaut gave;
- a **notice** at the top of the admin screens links to the order, until no
  line of the order needs attention any more;
- the store gets the **Pixonaut item needs attention** email (turn it off or
  change its recipient under **WooCommerce > Settings > Emails**).

The customer sees the item as "Being reviewed by the store".

Nothing more is sent for the line until you act on it in the order's
Pixonaut box. The line keeps every reference it used, so when it goes on it
picks up where it stopped.

## The Pixonaut box

The order screen has a **Pixonaut** box with a section for each personalised
line: each side's proof with a link to the design in the Pixonaut studio,
where the line stands, its printshop order with a link to it in the studio
(and the automation that placed it, when the line took on an order one of
your Pixonaut automations placed), and the actions the line offers now. Only the actions Pixonaut allows for
the order at that moment are shown; Pixonaut is asked each time the screen
opens. Shop managers and administrators can use them.

The studio links open in the workspace the store is connected to, even when
you belong to several. A store connected before Pixonaut named its workspace
in the connection shows them once it has read its workspace settings again,
which it does on its own shortly after.

An action saves the order, as **Update** does, then runs. The box says what
came of it.

| Action | Shown when | What it does |
|---|---|---|
| **Retry** | The line stopped before it had a printshop order; or it waits for a printshop order in a paid order with no job scheduled; or its printshop order failed and Pixonaut allows a retry. | Sends the line on from where it stopped; or has Pixonaut place the failed order again at the same printshop. The line goes on to *ordered*. If an earlier retry already placed one, the line takes that order instead of placing another. |
| **Order again anyway** | The line stopped because its design already has a printshop order in Pixonaut that the line did not place (`design_already_ordered`). | Places the line's printshop order as well, so the item is made a second time. Check the other order in Pixonaut first. |
| **Printshop has it** | The order's outcome is unknown (`outcome_unknown`). | Tells Pixonaut the printshop received the order. Say what the printshop told you; add its order reference if it gave one, so Pixonaut follows the order from there. The line goes on to *ordered*. |
| **Printshop does not have it: send again** | The order's outcome is unknown. | Tells Pixonaut the order failed, with what the printshop told you, and places it again at once. |
| **Cancel the printshop order** | The order's outcome is unknown. | Asks the printshop to stop the order. The line is cancelled once it did. Refund the customer in WooCommerce yourself. |
| **Rehearse: queued**, **printed**, **shipped**, **failed** | The store is connected to a sandbox. | Moves the sandbox order on as a printshop would, and the line with it, so you can see each step without a real printshop. |

An order whose outcome is unknown is never sent again on its own: the
printshop may have it, and sending it again could print it twice. Ask the
printshop first.

If Pixonaut answers that the order changed meanwhile, nothing is changed and
the box shows the actions that apply now. If the printshop reference you
gave belongs to another order, nothing is changed either; check the
reference.

If Pixonaut cannot be reached while an action runs, the box says it is not
known whether Pixonaut took it. Reload the page: each time the box opens, a
line whose printshop order moved on in Pixonaut without the store hearing
of it is brought up to date, with an order note, and a line whose failed
order was placed again takes the new order. If Pixonaut cannot be reached
while the box opens, the box says so for every line and shows no actions,
without waiting on Pixonaut once per line.

When the printshop order failed because its print could not be made
(`print_failed`), **Retry** has Pixonaut make the print again, from the
design the customer approved, and place the order. The line shows *Waiting
for prints* until the print is finished and the order goes to the
printshop. The same goes for an order whose print was still being made
when it failed: the new order waits for the print. If Pixonaut answers that
a print is still being made, the box says so; try again once it is
finished.

When Pixonaut refuses a **Retry**, the box and an order note say why in
words, with what to do about it. A refusal this version of the plugin does
not know is shown with Pixonaut's code.

If the store cannot schedule a line that **Retry** sends on from where it
stopped, the line keeps needing attention with the reason
`retry_not_scheduled`, and the box says so.

## Reasons

| Reason | What happened | What to do |
|---|---|---|
| `retries_exhausted` | The step kept failing with a transient error through every retry. The note names the last error. | Check Pixonaut's status and the order in Pixonaut, then **Retry**. |
| `retry_not_scheduled` | The step failed with a transient error, and the store could not schedule the next attempt (WooCommerce's scheduled actions refused it). The note names the error. | Check **WooCommerce > Status > Scheduled Actions** and the log, then **Retry**. |
| `printshop_differs_from_blank` | Pixonaut refused the printshop the item was sent to: only the printshop of the product's blank can make it. | Check the blank in the Pixonaut studio, then retry. |
| `printshop_disconnected` | The printshop that makes the item is disconnected in Pixonaut. | Reconnect the printshop in the Pixonaut studio, then retry. |
| `print_needs_printshop` | The design is on no blank, and the Pixonaut workspace has no default printshop, so nothing can make it. | Set a default printshop in the Pixonaut studio, then retry. |
| `variant_retired` | The blank variant the item was bought as was retired in Pixonaut after the customer bought it, so the printshop cannot make it. | Refund or cancel the item. |
| `variant_not_of_blank`, `variant_required` | Pixonaut could not tell which variant of the blank the item is. | Pick the product's variant in its **Blank variant** field, then refund or cancel the item. |
| `print_not_position_size`, `print_exceeds_machine` | The print is at a size its position on the blank does not print, or larger than the printshop's machine takes. | Check the blank's positions and the printshop's machine in the Pixonaut studio, then have the customer personalise the item again at a size it prints. |
| `field_value_missing` | The printshop needs a value the blank's variant or placement does not have. | Fill it in for the blank in the Pixonaut studio, then retry. |
| `revision_changed`, `not_committed` | The design changed in Pixonaut after the customer approved it, or is no longer waiting for approval at the revision the customer approved. | Check the design in Pixonaut with the customer. |
| `design_already_ordered` | The design already has a printshop order in Pixonaut that this item did not place, for example one placed in the studio. Pixonaut orders a design once unless asked to order it again. The order note names that order. | Check that order in Pixonaut. If it is this item's, cancel one of them; if the item is to be made as well, click **Order again anyway**. |
| `design_erased` | The design's personal data was erased in Pixonaut, so it can no longer be printed or ordered. | Refund the item, or have the customer personalise it again. |
| `design_deleted` | The design was deleted in Pixonaut before the item was finished, so it can no longer be printed. | Check the order with the customer: refund the item, or have them personalise it again. |
| `design_edited` | The design was changed in Pixonaut after it was approved, so the store did not send it to the printshop. The store also checks each design just before it places the printshop order, so an edit it has not heard of yet stops the item too. | Check the design in Pixonaut with the customer. |
| `design_changed` | Proof by email: the design was changed in Pixonaut after the customer bought it, or approved at another version than the one bought, so their proof no longer shows it. | Check the design with the customer. |
| `design_not_waiting` | Proof by email: when the order was paid, the design was no longer waiting for the customer's approval, for example because it was rejected in Pixonaut. | Check the design in Pixonaut with the customer. |
| `proof_rejected` | Proof by email: the customer rejected the proof, and **On deny** marks such items for your attention (or the item could not be cancelled). | Refund or cancel the item. See [Rejected or denied proofs](../approval-modes/#rejected-or-denied-proofs). |
| `proof_expired` | Proof by email: the customer did not answer by the deadline, the design was denied, and **On deny** marks such items for your attention (or the item could not be cancelled). | Refund or cancel the item. |
| `order_failed` | The printshop order failed, when it was placed or later. The note gives the printshop's reason. | Check the order in Pixonaut, then **Retry** it, here or in Pixonaut. A retry made in Pixonaut places a new printshop order; the item follows it, and an order note names it. When that order was placed again too, the item follows on to the latest. |
| `outcome_unknown` | The printshop order was sent, but its answer was lost, so Pixonaut cannot tell whether the printshop has it. | Ask the printshop, then click **Printshop has it** or **Printshop does not have it: send again**, or cancel it. |
| `order_status` | Pixonaut placed the printshop order with a status the store cannot go on from. | Check the order with the printshop. |
| `postal_code_required`, `prints_disagree`, `print_without_placement` and other codes | Pixonaut refused the request. The note names the code. | Correct what the code names (for example the order's address), then retry. |
| `not_found`, `design_not_found`, `print_not_found` | Pixonaut no longer knows the line's design, print or order. On a sandbox connection this happens after the sandbox's data was reset, and the note says so. | Place the item again. |
| `unauthorized` | Pixonaut no longer accepts the store's key. | Reconnect the store. |

A code not listed here is one Pixonaut added after this version of the
plugin; the order note names it.
