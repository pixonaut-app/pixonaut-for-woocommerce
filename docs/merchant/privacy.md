---
title: Privacy requests
description: What the store sends to Pixonaut and Pixel, the suggested privacy policy text, what a personal data export holds about personalised items, and how an erasure request or WooCommerce's retention erases a customer's designs and delivery details in Pixonaut without stopping an order on its way.
sidebar:
  order: 8
---

A personalised item's design holds what the customer put into it: their
text and their pictures. It lives in your Pixonaut workspace, and the
printshop order made from it holds the customer's name and delivery address.
The order item keeps a copy of the values the customer chose, such as the
text they typed, to list them under the item; a picture is listed as
"Image", never by its address.
The plugin takes part in WordPress's privacy tools, **Tools > Export Personal
Data** and **Tools > Erase Personal Data**, for both.

## What is sent to Pixonaut

- **Designs**: the text a customer types into the designer and the photos
  they upload, from their browser and from your server.
- **Delivery**: for each paid order with a personalised item, the
  recipient's name, email address, phone number and shipping address, so
  the printshop can deliver.
- **Pixel**, Pixonaut's AI assistant. Pixonaut runs Pixel on AI model
  providers, its sub-processors, listed at
  [pixonaut.app/subprocessors](https://pixonaut.app/subprocessors); they
  process what is sent to Pixel to write its answers.
  - **In the studio**, when you turned on **Allow Pixel to make changes to
    your store** ([Connect your store](../connect/)): the store records Pixel
    reads to answer a workspace member. These can be products and orders, with
    the customers' names, email addresses, phone numbers, billing and shipping
    addresses and order notes. They are sent from your server, only when Pixel
    calls the store.
  - **In the designer**, where the template offers Pixel: the prompt the
    customer types, and the photos and design it applies to, sent from the
    customer's browser when they use it.

The plugin's readme lists the same under **External services**.

## Suggested privacy policy text

**Settings > Privacy > Policy Guide** has a **Pixonaut for WooCommerce**
section: suggested text for your privacy policy on what the store sends to
Pixonaut, covering designs, photos, delivery details and Pixel. Copy it into
your privacy policy and adapt it to what your store uses, for example leave
out Pixel when you do not use it.

## Exporting personal data

The export has a **Personalised designs** section with one entry per
personalised item in the customer's orders: the order number, the product,
the ID of each side's design, the values the item lists of what the
customer chose, and the ID of the print order sent to the printshop, once
there is one. These are the references under which Pixonaut
keeps the designs and the delivery; Pixonaut can tell you what they hold.

The plugin finds the customer's orders the way WooCommerce does: orders whose
billing email is the address in the request, and orders of the customer
account with that address.

## Erasing personal data

An erasure erases, in Pixonaut, the text and pictures of each of the
customer's designs, and the name and address of each printshop order. The
values the item lists go with its design. The order stays in your store and in Pixonaut, with its products, status and
dates. An erased design is never printed or ordered again, and cannot be
changed.

What is erased depends on the item:

- **An item never paid for**, such as one in an order still awaiting
  payment, is erased at once. The design is the customer's alone, so it is
  erased whatever your store keeps.
- **A paid item, while the store keeps order data.** WooCommerce's
  **Remove personal data from orders on request** (**WooCommerce >
  Settings > Accounts & Privacy**) decides whether an erasure request removes
  order data. While it is off, the item's design and printshop delivery are
  kept with the order, and the request says "Personalised designs in order
  … were retained with the order." Turn it on to erase them.
- **A paid item that has shipped, failed or been cancelled**, or whose
  print order failed, is erased at once. An item whose print order failed
  is erased; it can no longer be placed again.
- **A paid item still on its way** is never erased mid-fulfilment, since the
  printshop still needs its design and address. The request says the designs
  were retained because they are needed to fulfil the order. The plugin
  remembers the request and erases the item as soon as it ships, fails, is
  cancelled or its print order fails; you do not have to run the request again.

Each item it erases gets an order note. When Pixonaut is busy, or the
printshop order is still being settled, the plugin tries again on its own
over the next hours, and the request says it "could not be erased yet". When
Pixonaut refuses for good, or the store is not connected to the Pixonaut
workspace the item was ordered under, the order note says why; run the
request again once that is fixed.

The store's own copies of the customer's proof images are deleted with the
design, also when the design is erased in Pixonaut directly.

## WooCommerce's personal data retention

When **WooCommerce > Settings > Accounts & Privacy > Personal data
retention** removes the personal data of old completed orders ("Retain
completed orders"), the plugin erases each of the order's personalised items
in Pixonaut too, as an erasure request does. The store no longer keeps the
order's data, so a paid item is erased as well, once it has shipped, failed
or been cancelled; an item still on its way is erased as soon as it is
finished. The same happens when an erasure request removes an order's
personal data.

WooCommerce deletes the order's notes when it removes its personal data; the
plugin adds its own notes back afterwards, so the order still says what was
erased and why an item was not, such as the store not being connected to the
Pixonaut workspace it was ordered under. The order can no longer be found
through its customer, so erase such an item in Pixonaut, or let Pixonaut's
own retention erase it.

## Erased after the retention period

Pixonaut also erases a design's personal data on its own, a number of days
after its order is finished, as your Pixonaut workspace's retention setting
says: the customer's pictures and text, the print files, and the order's
recipient details. Nothing needs doing in your store, and an item erased this
way looks the same as one erased on a request.

Once an item's design is erased, the cart, My Account, the order emails and
the Pixonaut box on the order screen show no proof for it. In their place
they say the personalisation was removed after the shop's retention period
(the `personalisation_removed` label), and the order box no longer links the
design in the Pixonaut studio. When the printshop order's recipient details
were erased, the order box says so too.

Pixonaut does not erase what a printshop was already sent: ask the printshop
for that.
