---
title: Getting started
description: From a connected store to a personalised product's first shipped order, step by step.
sidebar:
  order: 1
---

This page takes one personalised product from setup to a shipped order:
connect the store, link a product, place a test order, and follow it to the
printshop and back. Each step links to the page that covers it in full.

## 1. Connect the store

Go to **Pixonaut > Connection** (Pixonaut is in the admin menu) and click **Connect to
Pixonaut**. Sign in to Pixonaut, pick the workspace, and approve. You are
sent back to the Connection tab, which says the store is connected. See
[Connect your store](../connect/).

To try the steps below without printing anything, use a staging copy of the
store connected to the workspace's **Sandbox**: its orders never reach a
printshop.

## 2. Link a product

Open a simple product, or add one, and in its **Product data** box open the
**Pixonaut** tab. Tick **Personalise**, choose the blank under **Printed on**, then the
side's **Print position** and **Template**, and publish the product. The tab shows
the print sizes it is sold in, the printshop that makes it, the views
customers see their design in, and the **Variant** it sells, such as
the white t-shirt in size M. Check that variant: a product that sells none
cannot be bought, so pick one when the tab says no variant was found, and
update the product. See [Link a product](../linking-products/).

## 3. Check the settings

On the Pixonaut settings page, the defaults suit a first product:

- **Default layout** *Inline*: the product page shows a live preview of the
  design on the blank in place of the product image, with the template's
  fields in the add-to-cart form. See [Product page layouts](../layouts/).
- **Default approval** *Adding to the cart approves the design*: the moment
  the customer adds the product to the cart is their approval for print.
  See [Approval modes](../approval-modes/).
- **Load other colours and views** *Auto*: the designer loads the
  product's other colours and views ahead as far as the customer's device
  allows. See
  [Loading other colours and views](../layouts/#loading-other-colours-and-views).

## 4. Place a test order

Open the product on your store as a customer would, fill in the design, and
add it to the cart. Check out with any payment method your store takes, at
the classic or the block checkout. On a store connected to the live
workspace, this order is printed and shipped.

Once the order is paid (WooCommerce marks it **Processing** or
**Completed**), the store
approves the design, has Pixonaut render its print file, and places one
printshop order for the line, for the variant it sells, sent to the
order's shipping address. This
runs in the background, as a job under **WooCommerce > Status > Scheduled
Actions** (`pixonaut_fulfil_item`). See
[How orders flow](../how-orders-flow/) for each step and what happens when
one cannot run.

A payment notice that arrives twice still places one printshop order.

## 5. Follow it to the customer

As the printshop works on the item, Pixonaut tells the store, and the order
notes say when it is printed and when it ships, with the carrier and
tracking number. When every item of the order has shipped, the order is
marked **Completed**, and the customer's *Completed order* email lists the
tracking. See [Printing and shipping](../how-orders-flow/#printing-and-shipping).
If a step cannot run, the order's **Pixonaut** box says why; see
[Troubleshooting](../troubleshooting/).

## If a customer cannot add the product

The product page tells the customer why, and they personalise the product
again. A design that does not match the product, for example one made at
another print size, is refused and deleted from Pixonaut. See
[Adding to the cart](../how-orders-flow/#adding-to-the-cart).
