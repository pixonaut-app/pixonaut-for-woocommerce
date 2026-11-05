---
title: Label keys
description: The keys of every customer-facing text, for the pixonaut_wc_storefront_label filter.
sidebar:
  order: 3
---

Every text a customer sees has a key. Filter it with
`pixonaut_wc_storefront_label` in PHP or `pixonaut.storefront.label` in
JavaScript (see [hooks](../hooks/)). An unknown key reads as the key itself.

<!-- BEGIN GENERATED label-keys: from Labels::definitions(), run pnpm docs:generate -->

| Key | Default text | Shown |
|---|---|---|
| `personalisation_unavailable` | Personalisation is unavailable right now. Please try again later. | On a personalised product when the designer cannot load, or when it cannot be added to the cart for a reason the customer cannot fix |
| `design_required` | Please personalise this product before adding it to your cart. | Notice when a personalised product is not added to the cart |
| `design_not_saved` | We could not save your design. Please try again. | Notice when a personalised product is not added to the cart |
| `design_mismatch` | Your design does not match this product. Please personalise it again. | Notice when a personalised product is not added to the cart |
| `design_outdated` | This product was just updated, please reload. | Notice when a personalised product is not added to the cart |
| `design_expired` | Your design preview expired. Please approve it again. | Notice when a personalised product is not added to the cart |
| `design_already_used` | This design is already in a cart or an order. Please personalise the product again. | Notice when a personalised product is not added to the cart |
| `design_failed` | Your design could not be prepared. Please check it and try again. | On a personalised product when adding it to the cart could not prepare the design |
| `design_unavailable` | The design for "%s" is no longer available. Please remove it from your cart, or personalise it again. | At checkout, when a personalised item's design was deleted or can no longer be ordered; checkout stops until the item is removed. Keep %s, the product's name |
| `choose_options` | Please choose a value for %s before personalising this product. | On a variable product on the Personalise button layout, when the button is pressed before a variation is chosen. Keep %s, the options to choose |
| `design_fields_to_change` | Please change these fields: %s | On a personalised product when adding it to the cart refused the design for fields the customer must change, such as a default left as it was. Keep %s, the fields' labels |
| `approve_preview` | I approve the %1$spreview%2$s | The box a customer ticks to approve their design, on a product whose approval mode is the checkbox. Keep %1$s and %2$s around the words that open the proof; without them an Open proof button sits beside the box |
| `open_proof` | Open proof | Beside the approval box: opens the design's proof in a window over the page |
| `approval_required` | Please tick "I approve the preview" before adding this product to your cart. | Notice when a personalised product is not added to the cart |
| `preview_note` | The preview updates as you type | Under the live preview of a personalised product |
| `designer_loading` | Loading the designer | Read out by the spinner over the preview while the designer loads |
| `proof_alt` | Your design | Alternative text of the proof shown as a cart line's image, and the name of the window the proof opens in on the product page |
| `proof_loading` | Preparing your proof | In the window the proof opens in on the product page, while the proof is made |
| `proof_close` | Close | The button that closes the window the proof opens in on the product page |
| `personalisation` | Personalisation | Under a personalised item in My Account, on the order received page, in order emails and in proof emails: names the item's personalisation once it was removed |
| `personalisation_removed` | Removed after the shop's retention period | In place of a side's proof once its design's personal data was erased: next to the side's name in the cart, and under the item in My Account, on the order received page and in order and proof emails |
| `field_text` | Text | In a personalised item's details in the cart, the checkout, My Account, on the order received page and in order emails: names a text field of the design whose template gives it no label |
| `field_choice` | Choice | In a personalised item's details, as `field_text`: names a field the customer chose from a list |
| `field_number` | Number | In a personalised item's details, as `field_text`: names a number field |
| `field_colour` | Colour | In a personalised item's details, as `field_text`: names a colour field |
| `field_image` | Image | In a personalised item's details, as `field_text`: names a picture field, and stands for the picture the customer chose |
| `line_state` | Status | Under a personalised item on the admin order screen: names the item's state |
| `tracking` | Tracking | Under a shipped personalised item in My Account, on the order received page and in order emails: names its tracking |
| `part_shipped_intro` | Part of your order is on its way. The rest follows as soon as it ships. | The email a customer gets when a personalised item of an order with other items ships |
| `tracking_changed_intro` | There is new tracking for your order. Follow your parcel with the details below. | The email a customer gets when tracking is added to, or corrected for, a personalised item that has shipped |
| `sides` | Sides | Read by screen readers as the name of the side tabs, on a product printed on more than one side |
| `proof_ready_intro` | Your order is paid. Before we print it, please check the proof of your design and approve it. Nothing is printed until you do. | In the "proof ready" email, above the links to the proofs |
| `review_proof` | Review and approve your design | In the "proof ready" email, and under an item waiting for the customer's approval on the order received page and in My Account: the link to a proof page |
| `design_approved_intro` | Thank you for approving your design. It is now on its way to print. | In the "design approved" email |
| `proof_reminder_intro` | We are still waiting for your approval. Please check the proof of your design and approve it, or tell us what to change. Nothing is printed until you do. | In the proof reminder email, above the links to the proofs |
| `proof_deadline_approve` | If we have not heard from you by %s, we will print your design as the proof shows it. | In the last proof reminder email: what happens at the deadline. Keep %s, the date |
| `proof_deadline_cancel` | If we have not heard from you by %s, we will cancel this item. | In the last proof reminder email: what happens at the deadline. Keep %s, the date |
| `proof_deadline_refund` | If we have not heard from you by %s, we will cancel this item and refund it. | In the last proof reminder email: what happens at the deadline. Keep %s, the date |
| `proof_deadline_review` | If we have not heard from you by %s, we will not print your design and will be in touch about this item. | In the last proof reminder email: what happens at the deadline. Keep %s, the date |
| `proof_expired_intro` | You did not approve your design by the deadline, so we have cancelled this item. Nothing was printed. | In the "cancelled after deadline" email |
| `proof_expired_refunded` | Its price is being refunded to you. | In the "cancelled after deadline" email, when the item was refunded |
| `update_design` | Update design | The add-to-cart button's text on a product page opened to edit a cart line's design |
| `design_changed_elsewhere` | This design was changed elsewhere. Please reload the page. | On a product page opened to edit a cart line's design, or in the cart after editing it in Pixonaut, when the update is refused |
| `design_not_editable` | This design can no longer be changed. | On a product page opened to edit a cart line's design, or in the cart after editing it in Pixonaut, when the update is refused |
| `edit_line_gone` | This item is no longer in your cart. | On a product page opened to edit a cart line's design, or in the cart after editing it in Pixonaut, when the update is refused |
| `personalise` | Personalise | The button that opens the Pixonaut designer, on a product whose layout is the personalise button |
| `designer_visit_unknown` | We could not match your design to this visit. Please personalise the product again. | Notice when a customer comes back from the Pixonaut designer with a link that expired, was used already, or was opened in another browser |
| `design_ready` | Your design is ready. Use it with the button below. | Notice when a customer comes back from the Pixonaut designer in a browser or session that did not open it, so the design waits for them to use it |
| `add_design_again` | Use my design | On the product page when the design a customer approved in the Pixonaut designer could not be kept yet, or came back in a browser or session that did not open the designer |
| `personalised` | This is your design. | Note above the add-to-cart row when the product page shows a customer's design from the Pixonaut designer |
| `change_design` | Change | Button beside that note, on a product printed on one side |
| `change_design_side` | Change %s | Button per side beside that note, on a product printed on more than one side |
| `in_cart` | In your cart | On the product page of an item in the cart, before its options and quantity, on the Personalise button layout |
| `change_line_design` | Change design | Button on the product page of an item in the cart, on a product printed on one side, on the Personalise button layout |
| `view_cart` | View cart | Main button on the product page of an item in the cart, on the Personalise button layout |
| `make_another` | Make another with a new design | Link on the product page of an item in the cart, under the button to the cart, on the Personalise button layout |
| `proof_changed` | This proof has changed since this link was sent. Please use the link in our latest email. | On a product page opened from a proof the customer asked to change, instead of the designer, and when saving the change is refused; on the order received page for a product on the Personalise button layout |
| `edit_closed` | This design is approved and can no longer be changed. | On a product page opened from a proof the customer asked to change, instead of the designer, and when saving the change is refused; on the order received page for a product on the Personalise button layout |
| `approve_changes` | Save and approve design | The add-to-cart button's text on a product page opened from a proof the customer asked to change: saving approves the changed design |
| `changes_link_intro` | We received your request to change your design. If you did not finish your changes, you can open the design again here. Nothing is printed until you approve it. | In the "edit your design" email, sent after the customer asked for changes to their proof |
| `edit_your_design` | Edit your design | In the "edit your design" email: the link that opens the design for changes |
| `correction_visit_unknown` | We could not match your changes to this visit. Please change your design again from its product page. | Notice when a customer comes back from changing a design in Pixonaut with a link that expired, was used already, or was opened in another browser (Personalise button layout) |
| `design_updated` | Your design has been updated. | Notice when a customer comes back from changing their design in Pixonaut and the change is saved (Personalise button layout) |
| `line_state_committed` | Design saved | Under a personalised item on the admin order screen: its state |
| `line_state_awaiting_customer` | Awaiting your approval | Under a personalised item on the admin order screen: its state |
| `line_state_approved` | Design approved | Under a personalised item on the admin order screen: its state |
| `line_state_printed` | Print file ready | Under a personalised item on the admin order screen: its state |
| `line_state_ordered` | In production | Under a personalised item on the admin order screen: its state |
| `line_state_shipped` | Shipped | Under a personalised item on the admin order screen: its state |
| `line_state_failed` | Could not be produced | Under a personalised item on the admin order screen: its state |
| `line_state_cancelled` | Cancelled | Under a personalised item on the admin order screen: its state |
| `line_state_needs_attention` | Being reviewed by the store | Under a personalised item on the admin order screen: its state |

<!-- END GENERATED label-keys -->
