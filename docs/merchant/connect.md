---
title: Connect your store
description: Connect your WooCommerce store to a Pixonaut workspace or its sandbox, read the connection card, run a staging copy safely, disconnect, and what deactivating or deleting the plugin does.
sidebar:
  order: 2
---

The store works with one Pixonaut workspace at a time. Connecting it hands
the store three things, without you copying any key: an integration key for
the store's server, a publishable key for its product pages, and a webhook
endpoint through which Pixonaut tells the store about designs and orders.

## Before you connect

- **Permalinks.** Any structure works. Pixonaut delivers events to the
  store's REST API: at `/wp-json/` under pretty permalinks, at
  `?rest_route=/` under plain ones. If you switch from pretty to plain
  permalinks after connecting, connect again, so events reach the new
  address.
- **One address for wp-admin and the site.** Pixonaut sends you back to
  wp-admin, and delivers events, only on the store's own address. If the
  WordPress Address and the Site Address under **Settings > General** differ
  in scheme or host (say `https://admin.example.com` and
  `https://example.com`), the card says so and offers no Connect button.
- **Your role in Pixonaut.** Only a workspace member who may manage API keys
  can approve the connection.
- **The store's address.** The store is connected under its WordPress
  address (**Settings > General > Site Address**). A store that moves to
  another address, from `http` to `https` or into another folder, is a new
  store to Pixonaut and is connected again.

## Connect

Until the store is connected, the **Plugins** screen and the WooCommerce
screens show a notice linking to the connection settings. **Dismiss** hides it
for you alone; connecting takes it away for everyone. The **Settings** link
under Pixonaut for WooCommerce on the **Plugins** screen opens the same
settings, and **Docs** and **Support** beside it lead to this guide and to
where to ask for help.

1. Go to **Pixonaut > Connection** (Pixonaut is in the admin menu).
2. Click **Connect to Pixonaut**. You are sent to Pixonaut, where you sign in
   if you need to. To be offered only one side of the workspace, click
   **Live workspace** or **Sandbox** beside it instead.
3. Pick the workspace, and **Live** or **Sandbox** if it has a sandbox and
   you did not pick one already. Connect a staging store to the sandbox: its
   orders never reach a printshop.
4. Approve. You are sent back to the Connection tab, which says the store is
   connected.

Right after, the store narrows its webhook endpoint to the events it handles
and asks Pixonaut to send it a test request. The card shows whether that
arrived.

The approval works once and for a few minutes. If the Connection tab says the
link expired, or that Pixonaut did not hand over the keys, click **Connect
to Pixonaut** again. If it says the workspace's plan connects no more
stores, upgrade the plan or disconnect another store on the **API Keys**
page in the Pixonaut studio, then connect again. If it says the store could
not save the keys, the WordPress database refused the write: fix that first,
then connect again.

Why Pixonaut did not hand over or revoke the keys is logged under
**WooCommerce > Status > Logs**, source `pixonaut-api`: the status and the
error, never the keys.

One connect or disconnect runs at a time. If another administrator is
connecting or disconnecting the store, the card asks you to try again in a
minute.

The keys are stored encrypted in the WordPress database, with a key derived
from your site's `AUTH_KEY` and `AUTH_SALT` in `wp-config.php`. They never
appear in the settings or the logs. If those salts change, connect the store
again.

## Allow Pixel to make changes to your store

You chat with Pixel, Pixonaut's assistant, in the Pixonaut studio. It can work
on your store only if you allow it.
The switch is off until you turn it on, on the connect screen or later in the
Pixel row of the connection card, and it belongs to the store, not to the
WordPress user who turned it on.

On, Pixel can read and change products, variations, attributes, categories and
tags, link templates to products, and read orders. It reaches nothing else in
WordPress.

- **Turning it on** gives Pixonaut a new access key for the store, never an
  earlier one. If Pixonaut cannot be reached, the switch stays off and the card
  says so.
- **Turning it off** cuts Pixel's access at once, even when Pixonaut cannot be
  reached. After that, only this switch can turn it on again: the studio
  cannot. If the store cannot save the change, the card says so and Pixel may
  still have access: check the database, then turn the switch off again.
- **In the studio**, a member may also turn it off, or on again unless you cut
  it here. The card shows the result the next time you open it.
- **Disconnecting the store** revokes the access. A new connection starts with
  the switch off, unless you turn it on as you connect.

## The connection card

Once connected, the Connection tab shows:

| Field | What it is |
|---|---|
| Key name | The store's integration key as Pixonaut names it; a member may rename it on the studio's **API Keys** page |
| Workspace | The Pixonaut workspace the store is connected to |
| Environment | **Live**, or **Sandbox** for a workspace's sandbox, where orders never reach a printshop |
| Site URL | The address Pixonaut knows the store by |
| Also allow | The store's address with or without `www.`; see below |
| Webhooks | Whether Pixonaut's events reach the store: **Receiving events**, **Failing** with the number of deliveries that failed, **Switched off**, or **Cannot reach the store**; and the last test request |
| Pixel | The switch **Allow Pixel to make changes to your store**; see below |

The card reads these from Pixonaut each time it is shown. If Pixonaut cannot
be reached, it shows them as they were when the store connected, and says
so.

Below them, **Workspace settings** shows the settings of the workspace that
shape what your customers get. Change them in the Pixonaut studio, which the
card links to; the store's brand and print files you choose on the card.

| Field | What it is |
|---|---|
| Brand | The workspace's brand the store is drawn in, of the brands it keeps; the workspace's default is marked. See [The store's brand](#the-stores-brand) |
| Branding | The logo and accent colour of the store's brand, which Pixonaut's own pages show. With **Designer accent colour** set to **None**, the designer on the product page takes the accent colour too ([Product page layouts](../layouts/)) |
| Proof watermark | Whether proofs carry a watermark |
| Minimum resolution | The print resolution customers' pictures are judged against, unless a template sets its own |
| Print files | The form Pixonaut makes the store's print files in, and for live text its tolerance. See [The store's print files](#the-stores-print-files) |
| Delivery address | Whether every order ships to a saved address instead of its customer |
| Default language | The language Pixonaut's pages use when the store names none; see [Designer language](../layouts/#designer-language) |

When Pixonaut has deprecated something the plugin uses, the card warns,
with the date it stops working when Pixonaut gives one: update the plugin
before then ([Updates](../updates/)).

### The store's brand

A Pixonaut workspace can keep several brands, each with its own logo and
accent colour, so several stores can share one workspace while each shows its
own look. The proof, correction and designer pages Pixonaut shows your
customers are drawn in the store's brand.

Choose it under **Brand** and click **Save brand**. The workspace's default
brand, marked *(workspace default)*, follows whichever brand is the default in
the studio. A link already sent, such as a proof email, keeps the brand it was
made in; links made from then on show the new one. A brand chosen for the store
in the studio shows here too. If Pixonaut refuses the change, the card says so
and keeps the brand Pixonaut holds. A workspace with one brand shows it as the
only choice.

### The store's print files

Pixonaut makes a print file in one of three forms:

| Form | What the printshop gets |
|---|---|
| Flat | One flat image per print |
| Layers, text as shapes | Each printed layer its own PDF layer, named after the layer, with text turned into shapes |
| Layers with live text | The same, with plain text kept as live text the printshop can edit |

Flat files and layers with text as shapes print exactly alike. Live text
prints the same outside text, and within a tolerance at text edges: 1 to
100 px, or 0.01 to 5 mm. A print file edited after Pixonaut made it carries
no guarantee.

The workspace sets the form and the tolerance in the studio. Under **Print
files**, choose **Follow the workspace** (the default, which names the
workspace's form) or a form of the store's own; with **Layers with live
text**, **Live text tolerance** sets the tolerance, and left empty follows the
workspace's. Click **Save print files**. The line under the menu shows what the
store's print files are made as now. A template that sets its own form keeps
it, whatever the store chooses. If Pixonaut refuses the change, or the
tolerance is out of its limits, the card says so and nothing changes.

### The www. address

Personalised products load only on the addresses the store's key allows.
Right after it connects, the store allows its counterpart with or without
`www.` too (`https://example.com` and `https://www.example.com`), so they
load on both. If the store never answers there, click **Take away** on the
**Also allow** row; **Allow** adds it again. Connecting again allows both
once more. Another address on a different domain needs a key of its own,
made in the Pixonaut studio.

### Webhooks

Pixonaut tells the store about designs and orders through its webhook
endpoint. Click **Send test request** to have Pixonaut send it a signed test
request; the card says whether it arrived, or why not. If the endpoint is
switched off, the card warns that order and design updates do not reach the
store: switch it on under **Webhooks** in the Pixonaut studio.

If Pixonaut could not reach the store's address, as with a shop on your own
computer connected to a sandbox, the card says **Cannot reach the store**.
Pixonaut sends such a store no webhooks, and the store does not ask for them.
Nothing is lost: every 15 minutes the store reads its order and design updates
from Pixonaut, so they arrive, only later. To get them at once, give the
endpoint an address Pixonaut can reach; see
[Develop locally with a tunnel](#develop-locally-with-a-tunnel). If the endpoint
is switched off too, the card asks for both: a reachable address gets no
webhooks until it is switched on.

### Develop locally with a tunnel

A shop on your own computer works on a sandbox connection without a tunnel,
with updates arriving by the 15-minute read. To receive webhooks as they
happen:

1. Start a tunnel to the shop, for example `ngrok http 8888` or
   `cloudflared tunnel --url http://localhost:8888`, with the shop's own port.
   It prints a public `https://` address.
2. In the Pixonaut studio, open the store's endpoint under **Settings**,
   **Webhooks**, and use **Change address** to give it the tunnel's, keeping the
   path the card shows:
   `https://your-tunnel.example/wp-json/pixonaut/v1/webhook`, or
   `https://your-tunnel.example/?rest_route=/pixonaut/v1/webhook` under plain
   permalinks.
3. Click **Send test request** on the card to check it arrives.

Saving calls the address. If the tunnel was not running yet, the card goes on
saying **Cannot reach the store**: start it and save the address again, unchanged.
You can also give the address when you approve the store, in **Webhook address**.

Once the address answers, Pixonaut delivers events from then on, and the store
reads earlier ones by itself. Keep the shop's WordPress and Site Address as they are: the store is
connected under them, and changing them to the tunnel's pauses the connection
([Staging sites and copies](#staging-sites-and-copies)).

Only a sandbox connection takes an address on another host. On a live
connection the webhook address stays on the store's own, and must answer. A tunnel's address
that changes when it restarts has to be set again, as does the address after
connecting again, which gives the store a new endpoint.

## Paused connection

If Pixonaut refuses the store's key, because the store was disconnected on
the studio's **API Keys** page or its key was revoked, the plugin pauses the
connection: it stops calling Pixonaut, and every admin screen shows a notice
until you connect again. Click **Connect again** on the card to do so.

## Staging sites and copies

A copy of the store's database, such as a staging site, holds the live
store's connection. The plugin notices that the copy is not the site that
connected: its Site Address is not the one stored, or the stored keys cannot
be read with its `wp-config.php` salts. It then pauses the connection on the
copy, so the copy never acts for the live store: no call to Pixonaut, no
designs, no printshop orders, no order updates from Pixonaut: Pixonaut's
updates wait until the pause lifts. Every admin screen says *Site URL changed*.

On the staging site, click **Sandbox** beside **Connect again** to connect
it to the workspace's sandbox, where its test orders never reach a
printshop. Or click **Disconnect**: the copy forgets the connection without
revoking anything, so the live store stays connected. Putting the Site
Address back to the one that connected lifts the pause.

Lines ordered before a reconnect keep the references and keys they were sent
with, so retrying them never sends a second order
([Nothing is sent twice](../how-orders-flow/#nothing-is-sent-twice)).

## Reconnect

While the store is connected, the card offers only **Disconnect** and
**Open the Pixonaut studio**. To connect it to another workspace, or to move
it between **Live** and **Sandbox**, click **Disconnect**, then connect again.
Designs and orders the store made before stay its own.

## Disconnect

Click **Disconnect** on the card. On a paused connection, the plugin only
forgets the keys and calls nothing. Otherwise, the plugin asks Pixonaut to revoke the
store's keys and webhook endpoint, then forgets them. If Pixonaut cannot be
reached, the plugin forgets the keys anyway and says so: disconnect the store
on the studio's **API Keys** page too. If the WordPress database refuses to
remove the keys, the card says so and the store stays connected: fix the
database, then disconnect again. Disconnecting also disconnects every
user's own Pixonaut account from the store.

## Deactivate or delete the plugin

**Deactivating** Pixonaut for WooCommerce keeps the connection and every
setting, and everything works again once you activate it. While it is
inactive, personalised products show no designer and sell as plain products,
paid orders are not sent to Pixonaut, and Pixonaut's webhook deliveries to
the store fail. Pixonaut keeps the store's keys.

**Deleting** the plugin on the **Plugins** screen first asks Pixonaut to
revoke the store's keys and webhook endpoint, as **Disconnect** does, so the
store no longer counts toward your plan's connected stores. It waits a few
seconds at most: if Pixonaut cannot be reached, the plugin is deleted anyway,
and you remove the store on the studio's **API Keys** page. Then it removes
its settings, notices, product setups and the product pictures it made. The
Pixonaut details on past orders stay, as part of each order's history. On a
multisite network, deleting the plugin does this on every site.
