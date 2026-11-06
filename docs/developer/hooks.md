---
title: Hooks
description: The actions and filters Pixonaut for WooCommerce offers to themes and other plugins.
sidebar:
  order: 2
---

The plugin's hooks are public API and follow semantic versioning. Every hook
is named `pixonaut_wc_<area>_<name>` and documented at the line that fires it:
a summary, `@since`, a `@param` for each argument and an `@example`. The
sections below are generated from those docblocks; change a docblock, then
run `pnpm docs:generate`. `pnpm ci:local` fails while this page is stale, and
the PHP unit suite fails on a hook whose docblock is incomplete.

<!-- BEGIN GENERATED hooks: from the docblocks in src/, run pnpm docs:generate -->

## Filters

### `pixonaut_wc_api_request_body`

Filters the body of a request to Pixonaut before it is encoded.

Runs once per request, when it is prepared: a request sent again from its snapshot is not filtered again.

Since 2.0.0 `$operation` is the SDK's operation id, e.g. `designs.commit`, where it was the method and path before.

| Parameter | Type | Description |
|---|---|---|
| `$body` | `array<string, mixed>` | Request body. |
| `$operation` | `string` | SDK operation id, e.g. `designs.commit`. |

Since 0.1.0. Fired in `src/Api/HookListener.php`.

```php
add_filter( 'pixonaut_wc_api_request_body', function ( $body, $operation ) {
    if ( 'designs.commit' === $operation ) {
        $body['externalRef'] = 'shop-a:' . $body['externalRef'];
    }
    return $body;
}, 10, 2 );
```

### `pixonaut_wc_approval_deadline_outcome`

Filters what the store does with a proof-by-email line whose customer neither approved nor rejected its proof by the deadline.

| Parameter | Type | Description |
|---|---|---|
| `$outcome` | `string` | `approve` or `deny`, as the "When no answer by the deadline" setting says. |
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$line` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state and refs. |

Since 0.1.0. Fired in `src/Approval/ProofDeadline.php`.

```php
add_filter( 'pixonaut_wc_approval_deadline_outcome', function ( $outcome, $item ) {
    return $item->get_total() > 100 ? 'deny' : $outcome;
}, 10, 2 );
```

### `pixonaut_wc_approval_on_deny`

Filters what becomes of a proof-by-email line whose proof was denied: by the customer rejecting it on the proof page, or by its deadline passing with the deadline set to deny.

| Parameter | Type | Description |
|---|---|---|
| `$action` | `string` | `refund_cancel`, `cancel` or `needs_attention`, as the "On deny" setting says. |
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$cause` | `string` | Who denied it: `deadline` or `customer`. |
| `$line` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state and refs. |

Since 0.1.0. Fired in `src/Approval/ProofDenial.php`.

```php
add_filter( 'pixonaut_wc_approval_on_deny', function ( $action, $item, $cause ) {
    return 'customer' === $cause ? 'needs_attention' : $action;
}, 10, 3 );
```

### `pixonaut_wc_approval_proof_link_days`

Filters how many days a proof page link works. Pixonaut takes at most 90; a longer value is cut to that.

| Parameter | Type | Description |
|---|---|---|
| `$days` | `int` | The approval deadline plus a week. |
| `$deadline_days` | `int` | The approval deadline setting. |
| `$item` | `WC_Order_Item_Product` | The order line the link is for. |

Since 0.1.0. Fired in `src/Approval/ProofLink.php`.

```php
add_filter( 'pixonaut_wc_approval_proof_link_days', function ( $days, $deadline_days ) {
    return $deadline_days + 30;
}, 10, 2 );
```

### `pixonaut_wc_bridge_abilities`

Filters the abilities `pixonaut_wc_abilities()` answers another plugin with, such as an MCP bridge offering them as its tools.

Each still runs as the current user, after its own permission check.

| Parameter | Type | Description |
|---|---|---|
| `$abilities` | `list<string>` | Ability names, e.g. `pixonaut-for-woocommerce/order-get`. |

Since 0.1.0. Fired in `src/Bridge/Discovery.php`.

```php
add_filter( 'pixonaut_wc_bridge_abilities', function ( $abilities ) {
    return array_values( array_diff( $abilities, array( 'woocommerce/product-delete' ) ) );
} );
```

### `pixonaut_wc_cart_item_data`

Filters the Pixonaut data stored on a personalised cart item.

It reaches the order line at checkout; data that no longer reads as a line (see `CartLine::from_array()`) leaves the order line untracked.

| Parameter | Type | Description |
|---|---|---|
| `$line_data` | `array<string, mixed>` | `CartLine::to_array()`. |
| `$product_id` | `int` | The product (the parent of a variation). |
| `$variation_id` | `int` | The variation, or 0. |

Since 0.1.0. Fired in `src/Cart/AddToCart.php`.

```php
add_filter( 'pixonaut_wc_cart_item_data', function ( $line_data ) {
    $line_data['consent']['at'] = time() * 1000;
    return $line_data;
} );
```

### `pixonaut_wc_cart_item_display`

Filters the rows a personalised cart item shows under its name in the cart, the checkout and the mini cart: while the `show_field_values` setting is on, one per field of each side's design, its label as `key` and the customer's value as `value` and `display` (a picture's is the `field_image` label); and one by the side's name for a side whose personalisation was removed. As `woocommerce_get_item_data` takes them; a row the same as one before it is left out after the filter.

| Parameter | Type | Description |
|---|---|---|
| `$rows` | `list<array{key: string, value: string, display: string}>` | The rows; `display` is escaped HTML. |
| `$cart_item` | `array<string, mixed>` | The cart item. |

Since 0.1.0. Fired in `src/Cart/CartProofs.php`.

```php
add_filter( 'pixonaut_wc_cart_item_display', function ( $rows ) {
    return array_map( function ( $row ) {
        $row['key'] = sprintf( __( 'Design: %s', 'my-theme' ), $row['key'] );
        return $row;
    }, $rows );
} );
```

### `pixonaut_wc_cart_proof_width`

Filters the width, in pixels, a cart line's proof is drawn at. The image is served at the next width up from `ProofUrls::WIDTHS`.

| Parameter | Type | Description |
|---|---|---|
| `$width` | `int` | `woocommerce_thumbnail`'s width. |
| `$cart_item` | `array<string, mixed>` | The cart item. |

Since 0.1.0. Fired in `src/Cart/CartProofs.php`.

```php
add_filter( 'pixonaut_wc_cart_proof_width', function () {
    return 120;
} );
```

### `pixonaut_wc_catalogue_approval_mode`

Filters a personalised product's approval mode override. Null follows the store's default approval mode; a mode the store does not offer does too.

| Parameter | Type | Description |
|---|---|---|
| `$approval_mode` | `string\|null` | The product's override: `add_to_cart`, `checkbox`, `proof_email`, or null. |
| `$bought` | `WC_Product` | The product or variation the customer buys. |
| `$config` | `Pixonaut\WooCommerce\Catalogue\ProductConfig` | The stored configuration. |

Since 0.1.0. Fired in `src/Catalogue/ProductConfig.php`.

```php
add_filter( 'pixonaut_wc_catalogue_approval_mode', function ( $approval_mode, $bought ) {
    return $bought->get_price() > 100 ? 'proof_email' : $approval_mode;
}, 10, 2 );
```

### `pixonaut_wc_catalogue_is_personalisable`

Filters whether a personalised product is personalised where it is sold: return false and the storefront, the cart and purchasability treat it as a plain product. Only consulted for a product whose configuration personalises it; the product screen still shows the stored configuration.

| Parameter | Type | Description |
|---|---|---|
| `$personalise` | `bool` | Whether the product is personalised; true. |
| `$bought` | `WC_Product` | The product or variation the customer buys. |
| `$config` | `Pixonaut\WooCommerce\Catalogue\ProductConfig` | The stored configuration. |

Since 0.1.0. Fired in `src/Catalogue/ProductConfig.php`.

```php
add_filter( 'pixonaut_wc_catalogue_is_personalisable', function ( $personalise, $bought ) {
    return $bought->is_in_stock() ? $personalise : false;
}, 10, 2 );
```

### `pixonaut_wc_catalogue_layout`

Filters a personalised product's layout override. Null follows the store's default layout; a layout the store does not offer does too.

| Parameter | Type | Description |
|---|---|---|
| `$layout` | `string\|null` | The product's override: `inline`, `button`, or null. |
| `$bought` | `WC_Product` | The product or variation the customer buys. |
| `$config` | `Pixonaut\WooCommerce\Catalogue\ProductConfig` | The stored configuration. |

Since 0.1.0. Fired in `src/Catalogue/ProductConfig.php`.

```php
add_filter( 'pixonaut_wc_catalogue_layout', function ( $layout, $bought ) {
    return has_term( 'posters', 'product_cat', $bought->get_parent_id() ?: $bought->get_id() ) ? 'button' : $layout;
}, 10, 2 );
```

### `pixonaut_wc_catalogue_product_config`

Filters a product's or variation's Pixonaut configuration before the product screen saves it.

Runs after the configuration was checked with Pixonaut: a filter that changes what is printed should also set its validity.

| Parameter | Type | Description |
|---|---|---|
| `$config` | `Pixonaut\WooCommerce\Catalogue\ProductConfig` | The checked configuration. |
| `$product` | `WC_Product` | The product or variation it is saved on. |

Since 0.1.0. Fired in `src/Admin/ProductPanel.php`.

```php
add_filter( 'pixonaut_wc_catalogue_product_config', function ( $config, $product ) {
    if ( $product->get_meta( '_pixonaut_hold' ) ) {
        return $config->with_validity( false, 'held' );
    }
    return $config;
}, 10, 2 );
```

### `pixonaut_wc_catalogue_search_results`

Filters the options a catalogue search on the product screen lists: templates, blanks, or the templates that fit a blank's placement. Runs on every search, also one answered from the cache.

| Parameter | Type | Description |
|---|---|---|
| `$results` | `list<array<string, mixed>>` | The options as selectWoo reads them: `id`, `text` and what the search adds. |
| `$what` | `string` | Which search: `templates`, `blanks` or `fitting-templates`. |
| `$args` | `array<string, string>` | What it was asked with: `q`, `page`, and `blank` and `placement` for `fitting-templates`. |

Since 0.1.0. Fired in `src/Admin/CatalogueSearch.php`.

```php
add_filter( 'pixonaut_wc_catalogue_search_results', function ( $results, $what ) {
    if ( 'blanks' !== $what ) {
        return $results;
    }
    return array_values( array_filter( $results, fn ( $blank ) => ! str_contains( $blank['text'], 'Sample' ) ) );
}, 10, 2 );
```

### `pixonaut_wc_catalogue_side_config`

Filters a side of a personalised product as the storefront, the cart and purchasability see it. The product screen edits the stored side, unfiltered.

The side's print sizes were judged by Pixonaut when the product was saved; a size added here is not, and Pixonaut refuses a design at one it cannot print.

| Parameter | Type | Description |
|---|---|---|
| `$side` | `Pixonaut\WooCommerce\Catalogue\Side` | The side as stored. |
| `$bought` | `WC_Product` | The product or variation the customer buys. |
| `$config` | `Pixonaut\WooCommerce\Catalogue\ProductConfig` | The configuration the side belongs to. |

Since 0.1.0. Fired in `src/Catalogue/ProductConfig.php`.

```php
add_filter( 'pixonaut_wc_catalogue_side_config', function ( $side, $bought ) {
    if ( 'back' !== $side->key || ! $bought->is_on_sale() ) {
        return $side;
    }
    $label = __( 'Back (free while on sale)', 'my-theme' );
    return new \Pixonaut\WooCommerce\Catalogue\Side( $side->key, $label, $side->placement_id, $side->placement_name, $side->template_base_id, $side->template_version, $side->previous_version, $side->template_name, $side->print_sizes );
}, 10, 2 );
```

### `pixonaut_wc_connection_connect_args`

Filters the query sent to the studio's consent screen when a store connects.

`redirect_uri`, `state` and the PKCE challenge are checked when the admin returns; change them and the connect fails. A changed `site_url` connects the store under an address the plugin does not store, and a changed `rest_url` registers the webhook endpoint where the store does not receive, so leave them too. `site_name` (the store's name in the key's name) and `environment` (`live` or `sandbox`, to offer only one; set when the admin chose one) are the ones worth changing.

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array<string, string>` | Query parameters. |

Since 0.1.0. Fired in `src/Connection/Connection.php`.

```php
add_filter( 'pixonaut_wc_connection_connect_args', function ( $args ) {
    if ( 'staging' === wp_get_environment_type() ) {
        $args['environment'] = 'sandbox';
    }
    return $args;
} );
```

### `pixonaut_wc_emails_template_args`

Filters the variables one of the plugin's emails passes to its template: `email_heading`, `additional_content`, `email`, `plain_text`, the order and line where the email is about one, and the email's own, such as its proof URLs. Settings under WooCommerce > Settings > Emails change the heading, subject and additional content; template overrides change the markup.

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array<string, mixed>` | The template's variables. |
| `$email` | `WC_Email` | The email; its `id` tells which, e.g. `pixonaut_proof_ready`. |
| `$plain_text` | `bool` | Whether it is the plain text template. |

Since 0.1.0. Fired in `src/Emails/Emails.php`.

```php
add_filter( 'pixonaut_wc_emails_template_args', function ( $args, $email ) {
    if ( 'pixonaut_proof_ready' === $email->id ) {
        $args['additional_content'] .= "\n" . __( 'Questions? Reply to this email.', 'my-theme' );
    }
    return $args;
}, 10, 2 );
```

### `pixonaut_wc_fulfilment_can_fulfil`

Filters whether the plugin takes a paid line on towards the printshop: approving its designs and placing its printshop order. Return false to take that over or hold the line back: the order gets a note saying so, and the line stays in its state until `FulfilmentJobs::schedule( $item_id )` runs its job again.

| Parameter | Type | Description |
|---|---|---|
| `$can_fulfil` | `bool` | Whether the plugin fulfils the line; true. |
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$line` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state, refs and designs. |
| `$order` | `WC_Order` | The order. |

Since 0.1.0. Fired in `src/Fulfilment/Fulfiller.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_can_fulfil', function ( $can_fulfil, $item, $line, $order ) {
    return 'yes' === $order->get_meta( '_hold_for_review' ) ? false : $can_fulfil;
}, 10, 4 );
```

### `pixonaut_wc_fulfilment_cancel_line`

Filters whether a Pixonaut line is cancelled, in Pixonaut and at the printshop, because its order was cancelled or refunded, or its proof was denied. Return false to keep the line going, e.g. for a goodwill refund of an item the customer still gets.

| Parameter | Type | Description |
|---|---|---|
| `$cancel` | `bool` | Whether to cancel the line. |
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$trigger` | `string` | What asked for it: `order_cancelled`, `order_refunded`, `line_refunded`, `proof_expired` (not approved by the deadline) or `proof_rejected` (rejected by the customer). |
| `$line` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state and refs. |

Since 0.1.0. Fired in `src/Fulfilment/LineCancellation.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_cancel_line', function ( $cancel, $item, $trigger ) {
    return 'line_refunded' === $trigger ? false : $cancel;
}, 10, 3 );
```

### `pixonaut_wc_fulfilment_complete_order`

Filters whether a shipped line completes an order whose every line is a Pixonaut line. By default it does once every line has shipped. An order with other lines is never completed by the plugin, whatever this returns.

| Parameter | Type | Description |
|---|---|---|
| `$complete` | `bool` | Whether to complete the order. |
| `$order` | `WC_Order` | The order, still `processing`. |

Since 0.1.0. Fired in `src/Fulfilment/Shipments.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_complete_order', function ( $complete, $order ) {
    return $complete && ! $order->get_meta( '_awaiting_pickup' );
}, 10, 2 );
```

### `pixonaut_wc_fulfilment_line_actions`

Filters the actions the order screen offers for a Pixonaut line. Pixonaut refuses an action its order does not allow, so adding one only adds a button that fails.

| Parameter | Type | Description |
|---|---|---|
| `$actions` | `list<string>` | Action ids: `retry`, `reorder`, `settle_submitted`, `settle_failed`, `cancel`, `rehearse_<status>`. |
| `$line` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state and refs. |
| `$order` | `array<string, mixed>\|null` | Its printshop order as Pixonaut has it; null when it has none or it could not be read. |

Since 0.1.0. Fired in `src/Fulfilment/LineActions.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_line_actions', function ( $actions ) {
    return array_values( array_diff( $actions, array( 'cancel' ) ) );
} );
```

### `pixonaut_wc_fulfilment_line_display`

Filters a personalised order line's displayed meta, after the plugin added one row per field of each side's design (while the `show_field_values` setting is on, and always on the admin order screen, where the rows come alone and print under WooCommerce's meta), at index `pixonaut_field_<side>_<n>`, whose `display_key` is the field's label and `display_value` the customer's value (a picture's is the `field_image` label), then on the admin order screen the line's state, once shipped its tracking, and once its design's personal data was erased a note that its personalisation was removed (indexes `pixonaut_line_state`, `pixonaut_tracking` and `pixonaut_personalisation_removed`). WooCommerce shows these rows, all but the line's state, in My Account, on the order received page and in every order email.

| Parameter | Type | Description |
|---|---|---|
| `$rows` | `array<string\|int, stdClass>` | The line's displayed meta: `key`, `value`, `display_key`, `display_value` (escaped HTML). |
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$line` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state, refs and designs. |

Since 0.1.0. Fired in `src/Fulfilment/LineDisplay.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_line_display', function ( $rows ) {
    unset( $rows['pixonaut_tracking'] );
    return $rows;
} );
```

### `pixonaut_wc_fulfilment_order_address`

Filters who and where a personalised line is shipped to, as sent to Pixonaut when its printshop order is placed, and again when the order's address changes after it was placed. A retry of the same request sends what was sent first.

| Parameter | Type | Description |
|---|---|---|
| `$mapped` | `array` | `recipient` (`name`, `email`, `phone`) and `shippingAddress` (`company`, `line1`, `line2`, `postalCode`, `city`, `region`, `country` as an ISO 3166-1 alpha-2 code); a key is absent when the order did not have it. |
| `$order` | `WC_Order` | The order. |
| `$item` | `WC_Order_Item_Product` | The line being ordered. |

Since 0.1.0. Fired in `src/Fulfilment/OrderAddress.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_order_address', function ( $mapped, $order ) {
    $mapped['shippingAddress']['line2'] = 'Door code ' . $order->get_meta( 'door_code' );
    return $mapped;
}, 10, 2 );
```

### `pixonaut_wc_fulfilment_order_status_map`

Filters the line state each Pixonaut order status puts a line in when `order.status_changed` reports it. A status left out changes nothing; a line never moves back, whatever the map says.

Since 2.0.0 `$event` is the SDK's `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event`.

| Parameter | Type | Description |
|---|---|---|
| `$map` | `array<string, string>` | Pixonaut order status => line state. |
| `$event` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event\|null` | The event being applied; null when a read of the order applies the status. |

Since 0.1.0. Fired in `src/Fulfilment/OrderStatusChanged.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_order_status_map', function ( $map ) {
    $map['printed'] = 'printed';
    return $map;
} );
```

### `pixonaut_wc_fulfilment_retry_schedule`

Filters the waits between fulfilment retries. A line whose step keeps failing with a transient error is tried again after each wait in turn; once they are used up it moves to `needs_attention`.

| Parameter | Type | Description |
|---|---|---|
| `$schedule` | `int[]` | Seconds to wait before each retry, in order; an empty list never retries. |

Since 0.1.0. Fired in `src/Fulfilment/RetryPolicy.php`.

```php
add_filter( 'pixonaut_wc_fulfilment_retry_schedule', function ( $schedule ) {
    return array( 60, 600, 3600 ); // Three retries, then needs attention.
} );
```

### `pixonaut_wc_storefront_designer_link`

Filters the body of a product's designer link.

The link is minted once and kept until the body changes: the filter runs whenever a customer presses Personalise and whenever the product is saved, and a body that differs from the kept link's mints a new link. It carries no `state`, since every customer opens the same link; the product and variation ride in `returnUrl`'s query. Pixonaut checks every proof against the product's configuration when it is added to the cart, so a template, blank, placement or print size changed here is refused there.

| Parameter | Type | Description |
|---|---|---|
| `$body` | `array<string, mixed>` | One side's `blankId`, `placementId`, `views` (when the product narrows them), `variants` and `initialVariant` (the variant it sells), `initialView` (the product's starting view), `printSizes`, `selectedPrintSizeIndex` (the size it starts on) at the top level, or 2–10 under `sides` with `key`, `label` and `templateId` when it is not the link's; `returnUrl`, `locale`, `printAreaFrame` (`true`, when the store frames the print area), `layout` (`page` or `steps`, when the product or store fixes the form's layout), and `afterApprove` (`add_to_cart`, when the store adds the design on return). |
| `$product` | `WC_Product` | The product or variation. |

Since 0.1.0. Fired in `src/Storefront/KeptDesignerLinks.php`.

```php
add_filter( 'pixonaut_wc_storefront_designer_link', function ( $body ) {
    $body['locale'] = 'sv';
    return $body;
} );
```

### `pixonaut_wc_storefront_edit_mount_options`

Filters the options one side mounts with on a product page opened to edit a design the customer made already, from their cart or after they asked for changes to its proof, before the targets are added. The storefront script runs them through the JavaScript filter `pixonaut.designer.mountOptions` too.

A correction keeps the design's template. When the design is still what the side sells, the side opens on its product as the product page mounts it, with no size menu, and its form arranged as there (`formGroups`): the design keeps the size it was bought at. The SDK refuses a blank or placement the design was not made on. `preload`, `showPrintAreaFrame` and `accent` follow the store's settings, as on any product page.

| Parameter | Type | Description |
|---|---|---|
| `$options` | `array<string, mixed>` | `correctionLink`, `design`, `apiBase`, `locale` (one of `DesignerLanguage::languages()`, absent for the workspace's default), `layout` (`page` or `steps`, absent to follow the template), `preload`, `showPrintAreaFrame`, `accent` (with a custom accent colour), and with the design on its product `blankId`, `placementId`, `placements`, `views`, `initialView` and `variants` (with a blank, as on the product page). |
| `$product` | `WC_Product` | The product. |
| `$side_key` | `string` | The side, e.g. `front`. |

Since 0.1.0. Fired in `src/Storefront/CorrectionMount.php`.

```php
add_filter( 'pixonaut_wc_storefront_edit_mount_options', function ( $options ) {
    $options['locale'] = 'sv';
    return $options;
} );
```

### `pixonaut_wc_storefront_label`

Filters a customer-facing text.

The storefront script runs the same text through the JavaScript filter `pixonaut.storefront.label`.

| Parameter | Type | Description |
|---|---|---|
| `$text` | `string` | The text. |
| `$key` | `string` | The label key, see docs/developer/label-keys.md. |
| `$context` | `array<string, mixed>` | What the text is shown for; may be empty. |

Since 0.1.0. Fired in `src/Labels.php`.

```php
add_filter( 'pixonaut_wc_storefront_label', function ( $text, $key ) {
    return 'line_state_shipped' === $key ? 'On its way' : $text;
}, 10, 2 );
```

### `pixonaut_wc_storefront_mount_options`

Filters the options one side of the inline designer mounts with, before the targets are added. The storefront script runs them through the JavaScript filter `pixonaut.designer.mountOptions` too. On a variable product the filter runs for the product and for each variation, and a side mounts with the print sizes of all.

Pixonaut checks every proof against the product's configuration when it is added to the cart, so a template, blank, placement or print size changed here is refused there. `views` lists the ids of the blank's views the designer shows (empty for all), `placements` the side's placement, and `variants` the refs of the blank's variants the product sells: on a variable product, those of every variation on the blank, which the designer's colour and size picker offers. `preload` is the store's `designer_preload` setting: what the designer loads ahead (`auto`, `background` or `off`, or the SDK's `eager`). The storefront script adds `initialVariant`, the chosen variation's, and mounts a side whose tab is hidden with `preload` `off`, preloading in that mode once the tab shows. `showPrintAreaFrame` is the store's `print_area_frame` setting and `accent` its custom `designer_accent_colour`; with the theme's accent chosen, the script reads the colour of the theme's buttons into `accent` when the options name none. The script draws the designer's colour and size picker only on a product without variations whose `variants` lists more than one (a variable product's variation fields offer them), unless `showVariantPicker` says otherwise. On a product with one side it adds `optionsStep` and `lastStepContent`, which draw the add-to-cart form's variation fields in the designer's first step and its cart row on the last. A side the store manager arranged, or whose product's options stand in groups of their own, has `formGroups`, the designer's groups in their order with the product's words; an item `{attribute: <slug>}` in it is the variation field of that attribute, which the script moves into that group instead of into the first step (on a product with one side; with more it leaves the item out), and marks complete once it holds a choice.

| Parameter | Type | Description |
|---|---|---|
| `$options` | `array<string, mixed>` | `publishableKey`, `apiBase`, `templateId`, `blankId`, `placementId`, `placements`, `views` and `variants` (with a blank; `variants` once a variant is sold), `initialView` (with a blank, when the product names a starting view), `printSizes` (with a size menu), `selectedPrintSizeIndex` (the size the side starts on), `locale` (one of `DesignerLanguage::languages()`, absent for the workspace's default), `layout` (`page` or `steps`, absent to follow the template), `formGroups` (with an arrangement), `drawAtTemplateRatio` (`true` to draw the canvas at the template's ratio at every print size, `false` at the chosen size's; absent to follow the template), `preload`, `showPrintAreaFrame`, `accent` (with a custom accent colour). |
| `$product` | `WC_Product` | The product, or on a variable product the variation the options are for. |
| `$side_key` | `string` | The side, e.g. `front`. |

Since 0.1.0. Fired in `src/Storefront/InlineProducts.php`.

```php
add_filter( 'pixonaut_wc_storefront_mount_options', function ( $options ) {
    $options['selectedPrintSizeIndex'] = 1;
    return $options;
} );
```

### `pixonaut_wc_updates_upgrade_notice`

Filters the warning shown under a major update in the plugins list.

| Parameter | Type | Description |
|---|---|---|
| `$message` | `string` | The warning; may hold inline markup allowed in posts. Empty hides the notice. |
| `$new_version` | `string` | The version on offer. |
| `$current_version` | `string` | The installed version. |

Since 0.1.0. Fired in `src/Updates/UpgradeNotice.php`.

```php
add_filter( 'pixonaut_wc_updates_upgrade_notice', function ( $message, $new_version ) {
    return $message . ' Ask the web team before updating.';
}, 10, 2 );
```

### `pixonaut_wc_webhook_handle`

Filters whether the plugin handles a webhook event. Return false to take the event over: the plugin's own handler then does not run. The event's `pixonaut_wc_webhook_<type>` action fires either way.

Runs in the Action Scheduler job that handles the event, once per event id, after the signature was verified; again only when an earlier attempt threw and the event is tried again.

Since 2.0.0 `$event` is the SDK's `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event`, whose `created_at` is `createdAt`.

| Parameter | Type | Description |
|---|---|---|
| `$handle` | `bool` | Whether the plugin handles it. |
| `$event` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event` | The event: `id`, `type`, `createdAt`, `payload`. |

Since 0.1.0. Fired in `src/Webhooks/Processor.php`.

```php
add_filter( 'pixonaut_wc_webhook_handle', function ( $handle, $event ) {
    return 'order.status_changed' === $event->type ? false : $handle;
}, 10, 2 );
```

### `pixonaut_wc_webhooks_event_types`

Filters the event types the store's webhook endpoint receives, set each time the store connects, and again by a daily job when the list changed since.

A type Pixonaut does not send is refused (`400 invalid_event_type`) and the endpoint keeps receiving every type. An empty list receives every type too.

| Parameter | Type | Description |
|---|---|---|
| `$event_types` | `list<string>` | Event types, e.g. `order.status_changed`. |

Since 0.1.0. Fired in `src/Webhooks/Endpoint.php`.

```php
add_filter( 'pixonaut_wc_webhooks_event_types', function ( $event_types ) {
    return array_values( array_diff( $event_types, array( 'template.published' ) ) );
} );
```

## Actions

### `pixonaut_wc_api_after_request`

Fires after every request to Pixonaut, including each resend.

Since 2.0.0 it receives the SDK's `PreparedRequest`, `Result` and `ApiException`, under `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\`. An answer that is not a success comes as the error, with its `status`, where it came as the response before.

| Parameter | Type | Description |
|---|---|---|
| `$request` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest` | The request; it holds no credential. |
| `$result` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\Result\|null` | The successful answer, or null. |
| `$error` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException\|null` | Why it did not succeed (Pixonaut refused it, or it got no answer), or null. |

Since 0.1.0. Fired in `src/Api/HookListener.php`.

```php
add_action( 'pixonaut_wc_api_after_request', function ( $request, $result, $error ) {
    if ( $error && $error->status >= 500 ) {
        error_log( 'Pixonaut failed: ' . $request->operation );
    }
}, 10, 3 );
```

### `pixonaut_wc_api_before_request`

Fires before every request to Pixonaut, including each resend.

Since 2.0.0 `$request` is the SDK's `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest`: `operation` is its operation id, e.g. `designs.commit`, and the key is `idempotencyKey`.

| Parameter | Type | Description |
|---|---|---|
| `$request` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Http\PreparedRequest` | The request; it holds no credential. |

Since 0.1.0. Fired in `src/Api/HookListener.php`.

```php
add_action( 'pixonaut_wc_api_before_request', function ( $request ) {
    error_log( 'Pixonaut call: ' . $request->operation );
} );
```

### `pixonaut_wc_api_unauthorized`

Fires when Pixonaut refused the store's credential: the connection was removed in Pixonaut, or the key revoked.

Since 2.0.0 `$operation` is the SDK's operation id and `$error` the SDK's `ApiException`, under `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\`.

| Parameter | Type | Description |
|---|---|---|
| `$operation` | `string` | SDK operation id, e.g. `connectedStore.get`. |
| `$error` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Exception\ApiException` | The refusal. |

Since 0.1.0. Fired in `src/Api/HookListener.php`.

```php
add_action( 'pixonaut_wc_api_unauthorized', function ( $operation ) {
    wp_mail( get_option( 'admin_email' ), 'Pixonaut disconnected', $operation );
} );
```

### `pixonaut_wc_approval_changes_requested`

Fires when a customer asked for changes to the proof of a line whose approval mode is proof by email. The plugin's "changes requested" email to the store, and while the design can still be changed its "edit your design" email to the customer, are sent from here.

| Parameter | Type | Description |
|---|---|---|
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$side` | `string` | The side whose proof the customer asked to change, e.g. `front`. |
| `$message` | `string` | What the customer asked. |
| `$edit_url` | `string\|null` | The address that opens the design again for the changes; null when the customer changed and approved it already, or the line no longer waits for that revision. |

Since 0.1.0. Fired in `src/Approval/ChangesRequested.php`.

```php
add_action( 'pixonaut_wc_approval_changes_requested', function ( $item, $side, $message ) {
    error_log( sprintf( 'Order item %d: %s', $item->get_id(), $message ) );
}, 10, 3 );
```

### `pixonaut_wc_approval_deadline_passed`

Fires after a proof-by-email line's deadline passed without the customer's answer and the store decided it.

| Parameter | Type | Description |
|---|---|---|
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$outcome` | `string` | What became of it: `approve`, `refund_cancel`, `cancel`, `needs_attention` (denied, left to the merchant) or `unresolved` (the store could not decide; the line needs attention for the reason it records). |
| `$deadline` | `int` | Unix time of the deadline. |

Since 0.1.0. Fired in `src/Approval/ProofDeadline.php`.

```php
add_action( 'pixonaut_wc_approval_deadline_passed', function ( $item, $outcome ) {
    error_log( "Item {$item->get_id()}: {$outcome} at the proof deadline" );
}, 10, 2 );
```

### `pixonaut_wc_approval_proof_reminder`

Fires when a proof-by-email line still waiting for the customer is due a reminder; the plugin sends the reminder email on it.

| Parameter | Type | Description |
|---|---|---|
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$number` | `int` | The reminder's number, from 1. |
| `$last` | `bool` | Whether it is the last reminder before the deadline. |
| `$deadline` | `int` | Unix time of the deadline. |
| `$outcome` | `string` | What happens at the deadline: `approve`, or the "On deny" action (`refund_cancel`, `cancel` or `needs_attention`). |

Since 0.1.0. Fired in `src/Approval/ProofTimers.php`.

```php
add_action( 'pixonaut_wc_approval_proof_reminder', function ( $item, $number, $last ) {
    if ( $last ) {
        // Text the customer too.
    }
}, 10, 3 );
```

### `pixonaut_wc_cart_design_updated`

Fires when a customer updated a design in their cart and the line took the design's new revision.

| Parameter | Type | Description |
|---|---|---|
| `$line` | `Pixonaut\WooCommerce\Cart\CartLine` | The line, with the side's new revision and proof and the consent the update gave. |
| `$key` | `string` | The cart item's key. |
| `$side_key` | `string` | The side updated. |

Since 0.1.0. Fired in `src/Cart/CartEdit.php`.

```php
add_action( 'pixonaut_wc_cart_design_updated', function ( $line, $key, $side_key ) {
    error_log( sprintf( 'Cart line %s: side %s updated', $key, $side_key ) );
}, 10, 3 );
```

### `pixonaut_wc_cart_designs_committed`

Fires when a personalised product's designs were committed and checked, before its line joins the cart; from the hosted designer, when the customer comes back, and again when they add it.

| Parameter | Type | Description |
|---|---|---|
| `$line` | `Pixonaut\WooCommerce\Cart\CartLine` | The line's designs, refs and approval snapshot. |
| `$product` | `WC_Product` | The product or variation added. |

Since 0.1.0. Fired in `src/Cart/AddToCart.php`.

```php
add_action( 'pixonaut_wc_cart_designs_committed', function ( $line, $product ) {
    error_log( sprintf( '%s: %d design(s) committed', $product->get_name(), count( $line->sides ) ) );
}, 10, 2 );
```

### `pixonaut_wc_connection_connected`

Fires after the store connected to Pixonaut, or connected again.

| Parameter | Type | Description |
|---|---|---|
| `$connection` | `array<string, mixed>` | `site`, `home_url`, `connected_at`, `tenant_id`, `tenant_name`, `environment`, `connection_id`, `integration_key_label`; no keys. |

Since 0.1.0. Fired in `src/Connection/Connection.php`.

```php
add_action( 'pixonaut_wc_connection_connected', function ( $connection ) {
    error_log( 'Connected to ' . $connection['tenant_name'] . ' (' . $connection['environment'] . ')' );
} );
```

### `pixonaut_wc_connection_disconnected`

Fires after the store disconnected from Pixonaut and forgot its keys.

| Parameter | Type | Description |
|---|---|---|
| `$revoked` | `bool` | Whether Pixonaut revoked the keys; false when it could not be reached. |

Since 0.1.0. Fired in `src/Connection/Connection.php`.

```php
add_action( 'pixonaut_wc_connection_disconnected', function ( $revoked ) {
    if ( ! $revoked ) {
        error_log( 'Remove the store in the Pixonaut studio too.' );
    }
} );
```

### `pixonaut_wc_connection_paused`

Fires when the connection was paused.

| Parameter | Type | Description |
|---|---|---|
| `$reason` | `string` | Why: `unauthorized` when Pixonaut refused the store's key, `site_changed` when the site is not the one that connected. |

Since 0.1.0. Fired in `src/Connection/Connection.php`.

```php
add_action( 'pixonaut_wc_connection_paused', function ( $reason ) {
    wp_mail( get_option( 'admin_email' ), 'Pixonaut paused', $reason );
} );
```

### `pixonaut_wc_events_baseline_set`

Fires once per connection, after the first cursor of the event stream was set at its end: every event recorded before was skipped, and the pull goes on from there. What can have changed while the store was not connected, its linked products and its open order lines, is read afresh on this action.

| Parameter | Type | Description |
|---|---|---|
| `$connection` | `array<string, mixed>` | `site`, `home_url`, `connected_at`, `tenant_id`, `tenant_name`, `environment`, `connection_id`, `integration_key_label`; no keys. |

Since 0.1.0. Fired in `src/Webhooks/EventsPull.php`.

```php
add_action( 'pixonaut_wc_events_baseline_set', function ( $connection ) {
    error_log( 'Pulling events for ' . $connection['tenant_name'] );
} );
```

### `pixonaut_wc_fulfilment_transition`

Fires after a Pixonaut order line changed state and was saved.

| Parameter | Type | Description |
|---|---|---|
| `$to` | `string` | The new state. |
| `$from` | `string` | The previous state. |
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$state` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state and refs. |

Since 0.1.0. Fired in `src/Fulfilment/LineState.php`.

```php
add_action( 'pixonaut_wc_fulfilment_transition', function ( $to, $from, $item ) {
    error_log( "Line {$item->get_id()}: {$from} -> {$to}" );
}, 10, 3 );
```

### `pixonaut_wc_fulfilment_{$to}`

Fires after a Pixonaut order line reached a state, one hook per state: `pixonaut_wc_fulfilment_committed`, `_awaiting_customer`, `_approved`, `_ordered`, `_shipped`, `_failed`, `_cancelled` and `_needs_attention`. `_printed` fires only for a line an earlier version printed, as it leaves `needs_attention`: a line now goes from `approved` to `ordered`.

| Parameter | Type | Description |
|---|---|---|
| `$item` | `WC_Order_Item_Product` | The order line. |
| `$from` | `string` | The previous state. |
| `$state` | `Pixonaut\WooCommerce\Fulfilment\LineState` | The line's state and refs. |

Since 0.1.0. Fired in `src/Fulfilment/LineState.php`.

```php
add_action( 'pixonaut_wc_fulfilment_shipped', function ( $item ) {
    // Tell the warehouse.
} );
```

### `pixonaut_wc_webhook_{$type}`

Fires after a webhook event was handled, one hook per event type with its dots as underscores: `pixonaut_wc_webhook_order_status_changed`, `pixonaut_wc_webhook_design_approved` and so on. Fires for every type Pixonaut delivers, also those the plugin does not handle, once per event id; again only when an earlier attempt threw and the event is tried again.

Since 2.0.0 `$event` is the SDK's `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event`, whose `created_at` is `createdAt`.

| Parameter | Type | Description |
|---|---|---|
| `$payload` | `array<string, mixed>` | The event's payload. |
| `$event` | `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\Webhooks\Event` | The event: `id`, `type`, `createdAt`, `payload`. |
| `$handled` | `bool` | Whether the plugin's own handler ran. |

Since 0.1.0. Fired in `src/Webhooks/Processor.php`.

```php
add_action( 'pixonaut_wc_webhook_order_status_changed', function ( $payload ) {
    error_log( "Pixonaut order {$payload['orderId']} is {$payload['status']}" );
} );
```

<!-- END GENERATED hooks -->

## JavaScript hooks

The storefront script's `@wordpress/hooks` filters and actions
(`pixonaut.designer.mountOptions`, `pixonaut.designer.approved`,
`pixonaut.cart.beforeSubmit`) and its DOM events are described, with their
arguments, under [Storefront](../storefront/#javascript-hooks).

### `pixonaut.storefront.label`

The storefront script runs every customer-facing text through this
`@wordpress/hooks` filter, with the same arguments as
`pixonaut_wc_storefront_label`:

```js
wp.hooks.addFilter( 'pixonaut.storefront.label', 'my-theme', ( text, key ) =>
	key === 'personalisation_unavailable' ? 'Back soon!' : text
);
```

## Deprecated hooks

A hook is deprecated for at least one minor version before a major version
removes it. While deprecated it keeps working, and WordPress reports each use
as a deprecation notice when `WP_DEBUG` is on.

<!-- BEGIN GENERATED deprecated-hooks: from DeprecatedHooks and *_deprecated calls, run pnpm docs:generate -->

No hook is deprecated.

<!-- END GENERATED deprecated-hooks -->

To rename a hook, fire it under the new name and add the old one to
`DeprecatedHooks::RENAMED_FILTERS` or `RENAMED_ACTIONS` with the version:
callbacks on the old name then run before those on the new one. To retire a
hook without a replacement, fire it through `DeprecatedHooks::filter()` or
`DeprecatedHooks::action()` and add `@deprecated` to its docblock.

## Line states

A line moves forward along `committed`, `awaiting_customer`, `approved`,
`printed`, `ordered`, `shipped`, skipping steps where its approval mode has
none, and never moves backwards. From any state that is not final it can move
to `needs_attention`, `failed` or `cancelled`. `shipped` and `cancelled` are
final; `failed` can only become `cancelled`. A line leaving `needs_attention`
resumes at the state it was in or a later one. A request for a state the
plugin does not know moves the line to `needs_attention`.

`LineState::transition()` returns `false`, saves nothing and fires no action
when the move is refused, or when the line changed in another request since
the `LineState` was read. Read the line again with `LineState::of()` and
decide again. `save()` returns `false` in the second case too.

## Templates

Markup comes from `templates/` in the plugin and can be overridden from a
theme; see [Templates](../templates/).
