=== Pixonaut for WooCommerce ===
Contributors: pixonaut
Tags: woocommerce, personalization, print on demand, product designer, custom products
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Personalised products for WooCommerce through Pixonaut: customers design on the product page, paid lines become printshop orders.

== Description ==

Pixonaut for WooCommerce connects your store to a Pixonaut workspace. You link products to Pixonaut blanks and templates; customers personalise them on the product page, approve their design, and paid orders go to your printshop through Pixonaut.

* Customers design on the product page, inline or in the Pixonaut designer.
* Approval when adding to the cart, with a checkbox, or by a proof sent by email.
* Proof thumbnails in the cart, checkout, emails and order screens.
* Product images drawn by Pixonaut: the template on the blank, or filled in with the template's showcase sets as the product image and gallery.
* Paid lines become printshop orders; their status and tracking come back to the WooCommerce order.
* Works with WooCommerce's order storage (HPOS) and the Cart and Checkout blocks.

A Pixonaut account is required.

Documentation:

* Merchant guide, from connecting the store to shipped orders: https://github.com/pixonaut-app/pixonaut-for-woocommerce/blob/main/docs/merchant/index.md
* Developer guide, with the hooks, templates and REST routes for extending the plugin: https://github.com/pixonaut-app/pixonaut-for-woocommerce/blob/main/docs/developer/index.md

Source code:

The plugin's source is on GitHub: https://github.com/pixonaut-app/pixonaut-for-woocommerce

The scripts and styles in `assets/build` are built from the TypeScript and CSS in `assets/src` with `@wordpress/scripts` (`pnpm install`, then `pnpm build`). The PHP packages in `vendor` are the ones `composer.json` names, installed with Composer and prefixed under `Pixonaut\WooCommerce\Vendor`.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin zip under Plugins > Add New > Upload Plugin, and activate it.
3. Go to Pixonaut > Connection in the admin menu and connect your store to your Pixonaut workspace.
4. Open a product's Pixonaut tab and link it to a Pixonaut blank and template.

== Frequently Asked Questions ==

= Do I need a Pixonaut account? =

Yes. The plugin connects your store to a Pixonaut workspace, where your templates, blanks and printshops live.

= Where is the printshop chosen? =

In Pixonaut: each blank has its printshop.

= Can the product images show a filled-in example rather than the template's placeholders? =

Yes, when the template has showcase sets in Pixonaut: named sets of example values, such as a real name and photo. In the product's Pixonaut tab, under Product images, tick the sets to show. The first is the product image and the others join the product gallery, each on the blank's photo or as the flat design. Customers still start designing from the template's own defaults.

== Screenshots ==

1. Connect the store to your Pixonaut workspace under Pixonaut > Connection.
2. Link a product to a Pixonaut blank and template in the product's Pixonaut tab.
3. Customers personalise the product on its page and see the preview as they type.
4. Personalised lines on the order screen, with their proofs and the printshop order's status.

== External services ==

This plugin relies on two services run by Pixonaut. Neither is contacted until you connect the store to Pixonaut.

= Pixonaut API =

The Pixonaut API stores your templates and your customers' designs, renders proofs and print files, and sends orders to your printshop.

What is sent to Pixonaut includes personal data:

* The customer's design: the photos the customer uploads and the text they type into the designer.
* For each paid order with a personalised product: the recipient's name, email address, phone number and shipping address, so the printshop can deliver.

Pixonaut erases them a number of days after the order is finished, as the shop's Pixonaut workspace sets its retention period, or sooner when the shop erases a customer's personal data on request.

When it is sent:

* From your server: when you connect the store and link products (your store's address, and the templates, blanks and printshops it reads); when a customer adds a personalised product to the cart (the design they made); when an order is paid (the order's personalised lines and the recipient's details above); when proofs are shown (the design they belong to); and when a customer's personal data is erased.
* From the customer's browser, on personalised products only: the designer reads the template, previews the design, uploads the photos the customer adds and, where the template offers Pixel, sends the customer's Pixel prompt (see below).
* From your server, in the background while the store is connected: it fetches the events it missed and the product images Pixonaut draws, and sends again the paid lines whose sending failed.
* From your server, when you disconnect the store or delete the plugin: it revokes the store's connection.
* To your server: Pixonaut sends webhooks about designs, orders and templates to your store.

Pixonaut terms of service: https://pixonaut.app/terms
Pixonaut privacy policy: https://pixonaut.app/privacy

= Pixel, Pixonaut's AI assistant (Pixonaut API) =

Pixel is Pixonaut's AI assistant, reached through the Pixonaut API. Pixonaut runs it on AI model providers, its sub-processors, which process what is sent to Pixel to write its answers. They are listed at https://pixonaut.app/subprocessors

What is sent, and when:

* From your server, when you turned on Allow Pixel to make changes to your store and Pixel in the Pixonaut studio calls the store: the store records Pixel reads, which may include products and orders with the customers' names, email addresses, phone numbers, billing and shipping addresses and order notes.
* From the customer's browser, on personalised products whose template offers Pixel: the prompt the customer types into the designer, and the photos and design it applies to.

Nothing is sent to Pixel until you turn that switch on and Pixel calls the store, or a customer uses Pixel in the designer.

Pixonaut terms of service: https://pixonaut.app/terms
Pixonaut privacy policy: https://pixonaut.app/privacy
Pixonaut sub-processors: https://pixonaut.app/subprocessors

= Pixonaut SDK from cdn.pixonaut.app =

The designer on the product page is Pixonaut's SDK, a script and a WebAssembly module loaded from https://cdn.pixonaut.app into the customer's browser, on personalised products only. Loading it sends the customer's IP address and browser details to the CDN, as any download does; nothing else is sent to it.

Where the shop's Pixonaut workspace shows it, the designer draws a "Powered by Pixonaut" line under the preview, linking to https://pixonaut.app. Whether it shows is set in the Pixonaut workspace, by its plan and its own setting, not by this plugin.

Pixonaut terms of service: https://pixonaut.app/terms
Pixonaut privacy policy: https://pixonaut.app/privacy
