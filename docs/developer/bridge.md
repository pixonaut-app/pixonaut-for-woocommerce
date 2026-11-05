---
title: Bridge functions
description: The functions another plugin reads the store's Pixonaut connection and abilities with.
sidebar:
  order: 13
---

Another plugin uses these functions and nothing else of the plugin. They are
defined once the plugin has loaded with WooCommerce active, so
`function_exists( 'pixonaut_wc_status' )` says whether it is. Each is a thin
wrapper over a class in `src/Bridge/`. None returns, logs or filters a key.

An outside agent reaches Pixonaut through Pixonaut's own MCP server; the
plugin does not relay it.

## Abilities

`pixonaut_wc_abilities(): list<string>` names every ability the plugin
registers (`pixonaut-for-woocommerce/*`) and WooCommerce's
`woocommerce/product*` and `woocommerce/order*` ones, with no cap on their
number. The `pixonaut_wc_bridge_abilities` filter changes the list. Abilities are
registered whether or not the store is connected. Each runs as the current
user and checks that user's own capability for the object; one with no
annotations counts as destructive.

## Status

`pixonaut_wc_status(): array`:

| Key | Value |
|---|---|
| `connected` | Whether the store is connected |
| `paused` | `unauthorized`, `site_changed`, or null |
| `workspace`, `workspace_id` | The connected workspace's name and id, or null |
| `environment` | `live`, `sandbox`, or null |
| `reason` | The first of `not_connected`, `paused_unauthorized`, `paused_site_changed` that applies, or null |
