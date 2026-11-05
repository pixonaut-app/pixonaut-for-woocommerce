---
title: Emails
description: The WooCommerce emails Pixonaut for WooCommerce adds.
sidebar:
  order: 5
---

The plugin's emails are WooCommerce emails: each is listed on WooCommerce's
Emails settings screen, where it can be turned off or edited, and its template
can be overridden from the theme under `woocommerce/pixonaut/`, like the
plugin's other templates (`emails/needs-attention.php` becomes
`yourtheme/woocommerce/pixonaut/emails/needs-attention.php`).
The customer's emails' texts above and below the links are label keys
(`proof_ready_intro`, `review_proof`, `design_approved_intro`), so the
`pixonaut_wc_storefront_label` filter changes them too.

*Design proof ready* and *Design approved* are sent through WooCommerce's
transactional emails, relayed from the line's
`pixonaut_wc_fulfilment_awaiting_customer` and
`pixonaut_wc_fulfilment_approved` actions; *Design approved* only when the
line came from `awaiting_customer`. The others are sent at once by what they
report: `pixonaut_wc_approval_changes_requested`,
`pixonaut_wc_approval_proof_reminder` and
`pixonaut_wc_approval_deadline_passed` (`Emails\Emails`), a line reaching
`needs_attention` (`Fulfilment\AttentionAlerts`) and a shipment
(`Fulfilment\Shipments`). Each email is about one order line. When each is
sent is described under [Fulfilment](../#fulfilment) and
[Webhooks](../webhooks/).

<!-- BEGIN GENERATED emails: from WooCommerce's registered emails, run pnpm docs:generate -->

| ID | Email | Sent to | Template | Description |
|---|---|---|---|---|
| `pixonaut_changes_link` | Edit your design | Customer | `emails/changes-link.php` | Sent to the customer when they asked for changes to the proof of their design, with a link that opens the design again for the changes. |
| `pixonaut_changes_requested` | Pixonaut design changes requested | Store | `emails/changes-requested.php` | Sent to the store when a customer asked for changes to the proof of their design, with what they asked. |
| `pixonaut_deadline_passed` | Pixonaut proof deadline passed | Store | `emails/deadline-passed.php` | Sent to the store when a customer did not answer the proof of their design by the deadline, with what happened to the item. |
| `pixonaut_design_approved` | Design approved | Customer | `emails/design-approved.php` | Sent to the customer when they approved the proof of their design, with proof by email. Off by default. |
| `pixonaut_needs_attention` | Pixonaut item needs attention | Store | `emails/needs-attention.php` | Sent to the store when a personalised item cannot go on to the printshop without the store's help. |
| `pixonaut_part_shipped` | Part of your order has shipped | Customer | `emails/part-shipped.php` | Sent to the customer when a personalised item ships before the rest of the order. Not sent for an order with a single item, nor when the shipment completes the order. |
| `pixonaut_proof_expired` | Design cancelled after deadline | Customer | `emails/proof-expired.php` | Sent to the customer when an item whose proof they did not approve by the deadline was cancelled. |
| `pixonaut_proof_ready` | Design proof ready | Customer | `emails/proof-ready.php` | Sent to the customer after payment when a product's approval mode is proof by email, with a link to approve each design. |
| `pixonaut_proof_reminder` | Design proof reminder | Customer | `emails/proof-reminder.php` | Sent to a customer who has not answered their proof yet, as the proof reminder settings say. The last one says when the deadline is and what happens then. |
| `pixonaut_tracking_changed` | Tracking updated | Customer | `emails/tracking-changed.php` | Sent to the customer when the printshop adds tracking to a personalised item that shipped without any, or corrects its tracking. |

<!-- END GENERATED emails -->
