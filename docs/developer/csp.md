---
title: Content Security Policy
description: What a site's Content Security Policy must allow for the inline designer.
sidebar:
  order: 9
---

The inline designer runs Pixonaut's SDK in the customer's browser. The SDK
loads from `<CDN origin>/sdk/<version>/` (never from the plugin),
compiles its WebAssembly renderer in the page and draws the preview in a
worker. A site that sends a Content Security Policy needs these directives
on its product pages, besides what it allows already:

```
script-src 'wasm-unsafe-eval' blob: <CDN origin>
worker-src blob:
connect-src <API origin> <CDN origin>
img-src blob: <API origin>
```

`<CDN origin>` is `https://cdn.pixonaut.app`, or the CDN base when
`PIXONAUT_WC_CDN_BASE` or the developer tools set one.
`<API origin>` is `https://api.pixonaut.app`, or the origin of the API base
when `PIXONAUT_WC_API_BASE` or the developer tools set one
([API client](../api-client/#hosts)). A custom SDK script from the
[developer tools](../developer-tools/#designer-sdk) needs its own origin
allowed for scripts too.
The template's font files are fetched from the same origin.

| Directive | Why |
| --- | --- |
| `script-src 'wasm-unsafe-eval' blob:` and the CDN origin | The SDK's script, checked by its SRI; its renderer is WebAssembly compiled in the page, from a `blob:` address holding the bytes the SDK checked. |
| `worker-src blob:` | The preview is drawn in a worker started from a `blob:` URL. |
| `connect-src` the API origin and the CDN origin | The SDK's calls with the store's publishable key, uploads, fonts, the renderer's files, fetched to be checked, and the proof image the product page's proof dialog shows. |
| `img-src blob:` and the API origin | The preview, picture thumbnails and the proof in the proof dialog are `blob:` images; the photos of the blank's views come from the API origin. |

The storefront script also posts to the store's own
`/wp-json/pixonaut/v1/storefront/unavailable` when the designer cannot start
(`connect-src 'self'`).

When the policy blocks the SDK, customers see *Personalisation is unavailable
right now*, the script reports `sdk_load`, and the store manager gets a
notice naming the host the SDK loads from. Each add to cart tries to load the SDK again.

Pixonaut's own guides cover the SDK side in full: the SDK page's *Content
Security Policy* section, and the API guides *A connected store, from connect
to delivery* and *Sending the customer back to the store*.
