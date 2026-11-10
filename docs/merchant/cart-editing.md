---
title: Editing a design from the cart
description: How customers change a personalised product's design after adding it to the cart, and what stays as it was.
sidebar:
  order: 6
---

A customer can change a personalised product's design while it is in their
cart, without removing it and starting again. On the inline layout the
design opens on the product page. On the Personalise button layout the item has
a page of its own, whose **Change design** button opens the design on Pixonaut's
correction page ([Product page layouts](../layouts/#corrections)).

## Where customers find it

A personalised item's name and image in the cart link to its product page, in
the classic cart, the Cart block and the Mini-Cart. A product hidden from
the catalogue has no product link, and so no way to edit it from the cart.

Adding a personalised product from its page lands the customer on that item's
page straight away, on either layout, unless the store sends customers to the
cart after adding (**WooCommerce > Settings > Products**, *Redirect to the
cart page after successful addition*).

On the inline layout the edit page is the product page with the designer showing the design as
it is in the cart, on the product's photos and views as when it was added.
The add-to-cart button reads **Update design**. A design the product no
longer sells as it was made (its blank, print position or print size changed
since) opens on its own, without the product's photos.

On the Personalise button layout the item's page shows the item's design as the
product image, and says it is in the cart, with its size and quantity:

- **Change design**, one button per side named after the side on a product
  printed on several: approving on Pixonaut's correction page saves the change
  straight away, as **Update design** does, and brings the customer back to this
  page, which says *Your design has been updated*. Cancelling changes nothing.
  There is nothing left to submit, so the page has no button for it.
- **View cart** is the page's main button. **Add to cart** never shows here, and
  size and quantity can't be changed: quantity is changed in the cart.
- **Make another with a new design** opens the plain product page with the
  **Personalise** button. Nothing on this page copies the design or adds an item.

## What can change

- **The design**: text, pictures and anything else the template lets the
  customer change.
- **Not the variation or print size.** They stay as they were bought; on the
  inline layout the variation and quantity fields are locked on the page, and
  the designer offers no size menu. To change them, the customer removes the
  item and adds the product again.

The price does not change, and the item stays in the cart where it was.

## Updating

Clicking **Update design** saves the new design as the next revision of the
same design in Pixonaut, then takes the customer back to the cart, where the
item's image shows the new version.

The update is the customer's approval again, in the mode the item was added
with ([Approval modes](../approval-modes/)):

- **Adding to the cart approves the design**: clicking **Update design**
  approves the new version.
- **Customer ticks "I approve the preview"**: the box has to be ticked again
  before **Update design** is enabled. On Pixonaut's correction page,
  approving there stands in for the box.
- **Proof by email after payment**: nothing is approved yet; the proof email
  after payment shows the updated design.

## When an update is refused

| The customer sees | Why |
| --- | --- |
| This design was changed elsewhere. Please reload the page. | The design was updated in another tab since the page loaded. Reloading opens the newest version. |
| This design can no longer be changed. | Pixonaut takes no more changes to it, for example once it has been rejected. Editing closes. |
| This item is no longer in your cart. | The item was removed, or the cart expired. |
| Personalisation is unavailable right now. | The store is not connected to Pixonaut, or the connection is paused. |
| We could not save your design. Please try again. | Personalise button layout: Pixonaut could not be reached when the customer came back. The **Change** link tries the same change again, without opening the correction page. |
| We could not match your changes to this visit. Please change your design again from its product page. | Personalise button layout: the customer came back from the correction page with a link used already, older than 2 hours, or opened in another browser. |

A page left open for over two hours keeps working: the designer reconnects
to the design by itself on the next update. An approval that expired while
the customer was away is made again without asking them.
