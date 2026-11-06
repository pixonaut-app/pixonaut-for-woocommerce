---
title: Developer tools
description: The hidden developer tools on the Pixonaut settings page, for testing the plugin against a local or staging Pixonaut and debugging a store.
sidebar:
  order: 10
---

The Pixonaut settings page's Connection tab (Pixonaut > Connection) has a hidden
Developer tools section, for pointing a store at a local or staging Pixonaut
and for debugging one.

## Unlocking

Click the tab's **Connection** heading 7 times, or press Home 7 times within 3
seconds (outside a field). A notice counts down the last three. The section
then stays shown in that browser, kept in `localStorage` under
`pixonautDeveloperTools`, until **Hide developer tools** forgets it.

Unlocking only shows the section; it is not a security boundary. The section
is in every page of the tab, and every setting and action in it is saved and
checked on the server, through `admin-post.php` with a nonce, for users who
may manage WooCommerce (`manage_woocommerce`), as the rest of the tab is.
Two settings ask for more: the Pixonaut addresses decide where the store
sends its key, so only an administrator (`manage_options`) changes them, and
the SDK script runs for every visitor, so it also takes a user who may add
scripts to the site (`unfiltered_html`, which `DISALLOW_UNFILTERED_HTML` and
multisite take away). For anyone else their fields are read-only.

## Pixonaut addresses

| Setting | Constant | Used for |
|---|---|---|
| API base (`api_base`) | `PIXONAUT_WC_API_BASE` | The API as the browser reaches it: the SDK's `apiBase` |
| Server-to-server API base (`server_api_base`) | `PIXONAUT_WC_SERVER_API_BASE` | The API as WordPress reaches it; empty uses the API base |
| Studio base (`studio_base`) | `PIXONAUT_WC_STUDIO_BASE` | Connecting, and the admin's links into the studio |
| CDN base (`cdn_base`) | `PIXONAUT_WC_CDN_BASE` | Where the pinned SDK release loads from, at `<CDN base>/sdk/<version>/pixonaut.iife.js` |

Each takes a full `http://` or `https://` address, for example
`http://localhost:8787` for a local Pixonaut, and has a **Reset** that goes
back to Pixonaut's own. A constant in `wp-config.php` wins: its field is
read-only and says which constant set it. `Api\Hosts` resolves each address
in that order (constant, setting, default), and the API client is built with
the server address, so an override moves every call the store makes.

While the API or studio address is not Pixonaut's own, from a setting or a
constant, the connection card and the admin bar show **Custom Pixonaut
host**: the store's key is sent there. The CDN is sent no key.

To run against another Pixonaut, such as a staging one, set the API, studio
and CDN bases to its addresses. Its CDN must serve the same release files, as
the pinned release's integrity is still checked.

## Webhooks on a local store

A store on a private address connects to a sandbox, but Pixonaut cannot reach
it. `GET /v1/connect` then answers `reachable: false`, at the top level and in
its `webhook` object (`id`, `url`, `active`, `reachable`), and `GET /v1/webhooks`
lists the endpoint the same way: Pixonaut queues no deliveries, and `EventsPull`
asks for none again. Should it ask in the moment the address stops answering,
the `409 endpoint_unreachable` is not logged. Events arrive by the pull every 15
minutes. The connection card shows **Cannot reach the store**.

To receive them as webhooks, point the endpoint at a tunnel: when approving the
store, in the studio, or with `PATCH /v1/webhooks/{id}` and
`{"url": "https://…/wp-json/pixonaut/v1/webhook"}`. Pixonaut calls the address
each time a `url` is sent, the one it already has included. A sandbox connection
takes any origin, and reads `reachable: false` while the address does not answer;
a live one must stay on the connection's site origin and answer. Anything else
is refused `400 invalid_webhook_url`. Once the address moves or answers, events
from then on are delivered; what was waiting is dropped, and the pull reads it.
The plugin does not make this call itself, and signatures verify as before: the
receiver checks the secret, not the host. Steps are in
[Develop locally with a tunnel](../../merchant/connect/#develop-locally-with-a-tunnel).

## Designer SDK

**SDK script** (`sdk_url`) loads the SDK from another script, for example a
local build, instead of the pinned release from the CDN base, wherever the
storefront mounts a designer. Its integrity is not checked: the pinned
release's SRI and `crossorigin` are left off for a custom script only. The
SDK loads its WebAssembly from beside the script, so serve the build's whole
folder, and allow its origin in the site's
[Content Security Policy](../csp/).

**SDK debug** (`sdk_debug`) logs each designer mount, its options and any
failure to the browser console (`console.debug`, prefixed `Pixonaut:`).
On the storefront it is sent only to logged-in users who may manage
WooCommerce. The SDK itself has no debug option yet, so this is the
plugin's own logging around it.

## Request log

**Request log** (`request_log`), off by default, records the last 50
requests to Pixonaut in the `pixonaut_wc_request_log` option, newest first:
when, method, operation, path, status (or the error code when no response
arrived), duration, the `X-Request-Id` Pixonaut answered with, and the
idempotency key. It is fed by the `pixonaut_wc_api_before_request` and
`pixonaut_wc_api_after_request` actions the SDK's listener fires, which never see the
credential, and keeps no body. Anything in a path that looks like a key or
token (`ik_`, `pk_`, `sk_`, `whsec_`, `link_`, `shl_`) is replaced with
`[redacted]`. Turning the log on starts a new one.

## Connection details

The workspace, the first 12 characters of the integration key, the mode
(`live` or `sandbox`), the webhook endpoint's URL and its health as
Pixonaut reports it, and the pinned API release (pixonaut-php's `ApiVersion::TARGET`)
and SDK version (`Storefront\Sdk::VERSION`).

## Actions

| Action | What it does |
|---|---|
| Pull the event stream now | Runs the [event pull](../webhooks/) at once instead of waiting for the recurring job |
| Send a test webhook | Has Pixonaut send the store's endpoint a `webhook.ping`; the connection card shows whether it arrived |
| Re-check every linked product | Queues the re-check of every product with a Pixonaut configuration |
| Clear cached catalogue and designer links | Drops the admin's cached catalogue answers (by raising the generation in `pixonaut_wc_catalogue_generation`) and forgets every product's stored designer links, queueing them to be withdrawn in Pixonaut; new ones are minted when next needed. Links already waiting to be withdrawn keep waiting |

The first three need a working connection. A pull that did not read the
stream (Pixonaut refused or was not reached, or the first cursor is not set
yet) and a re-check that could not be queued say so; the log under
WooCommerce > Status > Logs has the details. Below them, the plugin's
pending and failed Action Scheduler jobs (group `pixonaut-for-woocommerce`)
are listed, 50 per status at most, each linking to WooCommerce's Scheduled
Actions screen.

The settings' keys and defaults are in the [settings reference](../settings/).
