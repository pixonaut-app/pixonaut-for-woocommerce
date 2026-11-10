---
title: Developer guide
description: Working on Pixonaut for WooCommerce, and extending it from a theme or another plugin.
sidebar:
  order: 0
---

What the plugin does for a store, and how a merchant sets it up, is in the
[merchant guide](../merchant/).

- [Local setup](./local-setup/): run the plugin, its tests and its tools on
  your machine.
- [Pixonaut contract](./contract/): the pinned API spec and SDK, the
  consumed list, and how to check and move the pin.
- [API client](./api-client/): how the plugin calls Pixonaut, its errors,
  idempotency, hooks and the contract checks in tests.
- [Contributing](./contributing/): the local merge gate and its attestation.
- [Releasing](./releasing/): versions, the changelog, `readme.txt` and the
  release zip.
- [Translations](./translations/): the glossary, context for translators,
  adding a language and keeping translations up to date.
- [Hooks](./hooks/): actions and filters.
- [Templates](./templates/): every template, overriding one from a theme,
  and the outdated-override report.
- [Label keys](./label-keys/): the keys of every customer-facing text.
- [Testing against the fake Pixonaut](./testing/): the stand-in every test
  talks to, its control API and its fixtures.
- [Settings](./settings/): every setting, its key, default and values.
- [Emails](./emails/): the WooCommerce emails the plugin adds.
- [REST routes](./rest-api/): the routes under `pixonaut/v1`.
- [Webhooks](./webhooks/): receiving, verifying and handling Pixonaut's
  events.
- [Abilities](./abilities/): the abilities an MCP client runs on the store.
- [Bridge functions](./bridge/): the functions another plugin reads the
  connection and abilities with.
- [Product page](./storefront/): the inline designer's markup, its
  JavaScript hooks and events.
- [Content Security Policy](./csp/): what a site's policy must allow for the
  designer.
- [Developer tools](./developer-tools/): the hidden section of the settings
  tab for a local or staging Pixonaut, the request log and debugging actions.

The hooks, label keys, settings, emails, REST routes and templates
references are generated from the code; `pnpm docs:generate` rewrites them.

## Layout

| Path | What it holds |
|---|---|
| `pixonaut-for-woocommerce.php` | Plugin header and the requirement check |
| `src/` | PHP, namespace `Pixonaut\WooCommerce\` (PSR-4) |
| `assets/src/` | TypeScript, built to `assets/build/` |
| `templates/` | Markup, overridable from a theme's `woocommerce/pixonaut/` |
| `uninstall.php` | Removes what the plugin stored |
| `readme.src.txt` | Source of the release zip's `readme.txt` |
| `CHANGELOG.md` | Written by release-please |
| `languages/` | The `.pot` file, the translations and the translators' glossary |
| `contracts/pixonaut/` | The pinned Pixonaut API spec, webhook signature vectors and SDK type declarations, and the consumed list |
| `bin/contracts/` | `contracts:pin`, `contracts:consumed` and `contract:check` |
| `bin/docs/` | `docs:generate`: the generated references; `check:docs-links` |
| `bin/release/` | `release:zip`: the zip's allowlist, build and checks |
| `tests/php/` | PHPUnit unit and integration suites, and the hook inventory suite that runs after them |
| `tests/e2e/` | Playwright specs and the test-only support mu-plugin |
| `tests/fake-pixonaut/` | The fake Pixonaut the tests talk to |
| `tests/js/` | Vitest suites, the fake's among them |

## Stored data

The plugin keeps its schema version in the `pixonaut_wc_schema_version`
option. Migrations run forward only, on `plugins_loaded`, each one once, one
request at a time (the `pixonaut_wc_migration_lock` option, taken over after
ten minutes if its holder died). A migration that fails throws, its version is
not recorded and it runs again. What it throws decides what the store sees:

- `MigrationPostponed`, from a migration the plugin works without: the runner
  logs an error in the `pixonaut-api` source and the request carries on. Later
  migrations wait, and the lock stays for its ten minutes, so the next try comes
  after that.
- Any other exception, from a migration the plugin cannot work without: nothing
  catches it, so that request fails and the next request tries again.

| Where | Key | Holds |
|---|---|---|
| Option | `pixonaut_wc_settings` | Every setting, one array keyed by setting |
| Option | `pixonaut_wc_notices` | Persistent admin notices, keyed by notice |
| Option | `pixonaut_wc_api_deprecations` | Deprecations Pixonaut announced, per operation |
| Option | `pixonaut_wc_connection` | The connection, encrypted as one value (`sealed`), and why it is paused (`paused`) |
| Option | `pixonaut_wc_connection_lock` | Held while a connect or disconnect changes the connection, so one request does at a time; taken over after a minute |
| Option | `pixonaut_wc_workspace_settings` | The store's brand and its accent colour as `GET /v1/connect` last answered them, the workspace's default language, and when (`{brand_id, accent, default_language, read_at}`); read again a day later, forgotten on connect and disconnect ([Storefront](./storefront/#mount-options)) |
| Option | `pixonaut_wc_variants_backfill` | Set by the upgrade that added blank variants until the re-check of every product, which resolves them, is queued |
| Option | `pixonaut_wc_webhook_ping` | The outcome of the last test request to the webhook endpoint, `{delivered, error, at}` ([Webhooks](./webhooks/#the-endpoint)) |
| Option | `pixonaut_wc_events_cursor`, `pixonaut_wc_events_baseline` | The event stream's cursor, and how far setting the first one has paged ([Webhooks](./webhooks/)) |
| Option | `pixonaut_wc_template_renames` | Each template id Pixonaut changed, mapped to the one it became ([Webhooks](./webhooks/#a-templates-id-changed-in-pixonaut)) |
| Option | `pixonaut_wc_request_log` | The developer tools' request log, the last 50 requests newest first ([Developer tools](./developer-tools/#request-log)) |
| Option | `pixonaut_wc_split_attributes` | The ids of the global attributes whose values are listed as their own products by default ([Listing each value as its own product](../merchant/split-listings/)) |
| Option | `pixonaut_wc_catalogue_generation` | The generation every cached catalogue answer's key carries; raising it drops them all |
| Option | `pixonaut_wc_proof_lock_<…>`, `pixonaut_wc_report_<…>` | One proof image fetch at a time; the throttle on reports from product pages whose designer could not load |
| Site option | `pixonaut_wc_update_state_stable`, `pixonaut_wc_update_state_beta` | The update checker's last check per channel ([Releasing](./releasing/)) |
| Transient | `pixonaut_wc_catalogue_<…>`, `pixonaut_wc_proof_<…>` | Cached catalogue answers for the admin, and cached proof images |
| Table | `{prefix}pixonaut_webhook_events` | The webhook events received: the dedupe log and each event's body ([Webhooks](./webhooks/)) |
| User meta | `_pixonaut_wc_connect_attempt` | An administrator's connect in progress: the hash of its `state`, its PKCE verifier, its `redirect_uri`, its expiry |
| Product and variation meta | `_pixonaut_config` | The product's Pixonaut configuration, versioned JSON |
| Product and variation meta | `_pixonaut_template`, `_pixonaut_blank` | Indexes of the templates (by base id, one row each) and the blank the configuration uses, written with it (`Catalogue\ProductIndex`) |
| Product and variation meta | `_pixonaut_variant` | The blank variant it sells: the store manager's pick, the ref resolved, the attribute labels a match was made from, and what Pixonaut last said of it (options, retirement, views with a photo), versioned JSON |
| Order item meta | `_pixonaut_line` | The line's fulfilment state, the Pixonaut ids, refs and keys it used, and the requests it may send again, versioned JSON |
| Order item meta | `_pixonaut_order_id` | The line's Pixonaut order id, kept in step with `_pixonaut_line` |
| Order item meta | `_pixonaut_design_id` | Each of the design ids the customer committed for the line, one row per side, kept in step with `_pixonaut_line`; a standing side's design is not indexed, as many lines share it |

Only `Catalogue\ProductVariant` reads or writes `_pixonaut_variant`
(the merchant's side: [Variant](../merchant/linking-products/#variant));
`ProductVariants` resolves it from `GET /v1/blanks/{id}/variants` when the
product is saved and when its blank is re-checked, so the storefront never
asks Pixonaut whether a variation can be bought. It writes only over the
document it resolved from, so a pick saved meanwhile is kept. A match sells
nothing once the product's attributes differ from the ones it was made on;
saving a product or variation that way, through the product screen, the
REST API, an import or `save()`, queues its re-check, which matches it
again.

Only `ProductConfig` reads or writes `_pixonaut_config`, and only `LineState`
reads or writes `_pixonaut_line` and its two indexes. A variation uses the product's
configuration unless its own has "override" set. Its `splitListing` holds the product's own On or Off for listing each value
of a global attribute as its own product, by attribute id; an attribute it
does not name follows the attribute's setting, and `Catalogue\SplitListing`
resolves the one attribute a product is listed by. A configuration written by
a newer plugin version reads as invalid, so the product cannot be bought,
and is never overwritten; on a variation it applies even without "override".
A line state written by a newer plugin version reads as `needs_attention` and
is never overwritten either. A line state is only written over the document
it was read from, so a request working from a stale copy cannot undo
another's change.

Only `Connection\ConnectionStore` reads or writes `pixonaut_wc_connection`.
It holds the keys and ids `POST /v1/connect/token` answered, the `<site>`
UUID minted at each connect (new on every reconnect) and the `home_url()`
the store connected from, as JSON sealed with libsodium's secretbox under a
key derived (HKDF-SHA256) from `wp_salt( 'auth' )`. What cannot be opened
reads as not connected. While the connection is paused, the API client gets
no credential and sends nothing.

Uninstalling the plugin (`uninstall.php`) removes its options, site options,
transients and webhook events table, connects in progress, its scheduled
jobs, and the product and variation meta above.
Order item meta stays, as part of each order's history.

## Cart cleanup

A design committed at add to cart stays in Pixonaut until it is ordered or
deleted. `Cart\CartCleanup` schedules one Action Scheduler job,
`pixonaut_delete_design` (group `pixonaut-for-woocommerce`, arguments
`design_id`, `tenant_id` and `failures`), per design of a line that leaves
the cart without an order, and the job sends `DELETE /v1/designs/{id}`:

| WooCommerce hook | When the job runs |
|---|---|
| `woocommerce_remove_cart_item` (removing a line, or setting its quantity to 0) | After `CartCleanup::REMOVAL_GRACE_SECONDS` (an hour). `woocommerce_cart_item_restored` (undo) and `woocommerce_add_to_cart` with the same designs cancel it. |
| `woocommerce_remove_cart_item_from_session` (WooCommerce drops a line whose product can no longer be bought, or changed what the line's designs were made on, while loading the cart) | At once. |
| `woocommerce_before_cart_emptied` | At once. Not when a logged-in customer's cart is emptied without its persistent copy (`wc_empty_cart()` on logout): it comes back at the next login. |
| `woocommerce_cleanup_sessions`, before WooCommerce's own handler | At once, for each line of each expired session, except designs still in the user's persistent cart. Sessions are read `CartCleanup::SWEEP_BATCH` at a time. |

What a line's designs were made on is part of the product data WooCommerce
hashes into a cart item's `data_hash` when it is added
(`woocommerce_cart_item_data_to_validate`, key `pixonaut`, `Cart\DesignBasis`):
whether the product is personalised, its blank, and each side's key, mode,
placement and template base id. A product saved with any of these changed
no longer matches, and WooCommerce drops the line as modified; its price,
description, stock and a template's live version are not part of it. A line
carted before the basis joined the hash, whose hash still matches its product
without it, takes the new hash on `woocommerce_load_cart_from_session`
instead of being dropped.

A design has at most one pending job, whatever its failure count; undoing a
removal cancels it and any retry. No job is scheduled for, and no job
deletes, a design that an order line holds (`LineState::for_design_id()`,
through the `_pixonaut_design_id` index), unless that order is the block
checkout's `checkout-draft`. This keeps a placed order's designs
when the cart is emptied after checkout, even when moving their refs failed.
A job for another workspace than the connected one, or with no connection,
sends nothing. `design_approved`, `design_printed` and `404` end the job, as
does any other refusal (logged). A transient failure, or a paused
connection, is retried after the waits in `RetryPolicy::SCHEDULE`, then
given up and logged.

## Fulfilment

`woocommerce_payment_complete`, and `woocommerce_order_status_changed` into
any of `wc_get_is_paid_statuses()` (from any status, so On hold to Completed
and an order created as Completed count), schedule one Action Scheduler job,
`pixonaut_fulfil_item` (group `pixonaut-for-woocommerce`, argument the order
item id), per line that has a `_pixonaut_line` in `committed`, `approved` or
`printed`, unless one is pending or running already
(`Fulfilment\FulfilmentJobs`).

A daily job, `pixonaut_wc_fulfilment_sweep` (`Fulfilment\FulfilmentSweep`,
scheduled on `action_scheduler_init`), schedules such a line of a paid
order created in the last 30 days (`FulfilmentSweep::WINDOW_SECONDS`, Action
Scheduler's default retention of finished jobs) when it has no job pending
or running and its newest `pixonaut_fulfil_item` job (by action id) is not
`complete`: failed, cancelled, or missing. A line whose last job completed
was left where it is on purpose (`pixonaut_wc_fulfilment_can_fulfil`,
another workspace) and is not swept. The sweep reads the orders of each
state, newest id first, `FulfilmentSweep::BATCH` per
`pixonaut_wc_fulfilment_sweep_page` job (arguments: state, the order id it
reads below, the window's start), and queues the next page before reading
the current one through. An order created before the window, also one
backdated or imported with a newer id, is skipped. Nothing is swept while
the store is not connected or the connection is paused, and a
`pixonaut_fulfil_item` job that runs then fails rather than completes, so
the sweep picks its line up once the store is connected again.

The job runs `Fulfilment\Fulfiller`, which takes the line from its stored
state:

1. When the line's `externalRefMoved` ref is not set, because moving its
   designs from the cart ref to the order line ref failed at checkout,
   `PUT /v1/designs/{id}/external-ref` per side moves them now
   (`Fulfilment\OrderLineRef`). Nothing else is sent until every design has
   moved, so no approval or printshop order ever carries a cart ref. The
   target is the order line ref the line stored, when it holds one, so a
   reconnect does not change it. A line state written before
   `externalRefMoved` existed (version 1) reads as moved when its
   `externalRef` is not a cart ref. A line not moved that already holds an
   `orderRequest` sent its printshop order under the cart ref: it is neither
   moved nor sent again, but gets an order note and goes to
   `needs_attention`.
2. `POST /v1/designs/{id}/approve` per side, `{consent: {kind, at, ref},
   expectedRevision}`; `409 not_committed` reads the design
   (`GET /v1/designs/{id}`) and goes on only when it is `approved` at the
   line's revision; then `approved`. A line whose approval mode is
   `proof_email` is not approved by the store (`Approval\ProofRequest`):
   `GET /v1/designs/{id}` per side; designs all `approved` at the line's
   revisions go straight to `approved`; a side approved already gets its
   `approved` side ref either way; otherwise each waiting design gets
   `POST /v1/share-links {kind: "proof-page", subjectId, mayDecide: true,
   expiresInDays, returnUrl, state, locale}`, its `url` stored in the side's
   `proofUrl` ref before the next is minted, and the line moves to
   `awaiting_customer`, which sends the *Design proof ready* email; when
   that email is disabled or not sent, an order note lists the `proofUrl`s.
   `expiresInDays` is the `proof_deadline_days` setting plus 7, at most 90
   (filter `pixonaut_wc_approval_proof_link_days`). `returnUrl` is
   `/wp-json/pixonaut/v1/order-items/<item>/edit`; `state` is the order, the
   item, the design and its revision, signed with HMAC-SHA256 under
   `wp_salt( 'auth' )` (`Approval\ProofState`). The proof page sends the
   customer there once they ask for changes; the route takes them to the
   product page that edits the design when the state is the store's
   ([Storefront](./storefront/#changes-asked-for-on-a-proof)), to their order
   when the product does not personalise inline, else to the home page.
   `locale` is `DesignerLanguage::resolve()` of the line's `locale` ref (the
   `determine_locale()` of the checkout, kept by `Cart\OrderLines`; the
   site's `get_locale()` for a line bought before), absent when it resolves
   to none ([Storefront](./storefront/#designer-language)). A mint
   refused for good moves the line to `needs_attention` with Pixonaut's code
   (one that may succeed later is retried like any step); a design no
   longer waiting (`design_not_waiting`), or at another revision than the
   line's (`design_changed`), does too. `design.approved` resumes the line (see
   [Webhooks](./webhooks/)).
3. `GET /v1/orders?externalRef=` adopts an order placed under the line's ref;
   otherwise `GET /v1/designs/{id}` per side checks that every design is
   still `approved` at the line's revision. A design stays editable after it
   is approved and the order names no revision, so one edited since (its
   `design.edited` not yet applied) is not ordered: the line drops its order
   request and needs attention (`design_edited`) with an order note. Then
   `POST /v1/orders` with the sides' designs as `designs:
   [{designId}]` in side order (the first leads), placed as soon as they are
   approved: Pixonaut renders their prints and holds the order
   (`awaiting_prints`) until they are done, then hands it to the printshop
   (`order.submitted`). A print that fails, or prints not done within an
   hour, fail the order (`order.failed`, `failureKind` `print_failed` or
   `prints_timed_out`), and the line needs attention (`order_failed`). A
   line an earlier version stored as `printed` names its prints (`prints:
   [{printId}]`) instead. The body carries the line's quantity, `externalRef`, `variant` (the ref the
   line's product sold when it was added to the cart, kept as the line's
   `variant` ref), and `recipient` and
   `shippingAddress` from `OrderAddress` (filter
   `pixonaut_wc_fulfilment_order_address`), and `shipToAddressId: null`, so
   a workspace that sends every order to a saved address still ships this
   one to the customer. It never sends `printshopId`: the
   order goes to the printshop of the design's blank. A design that has a
   live order the line did not place, for example one placed in the studio,
   is refused `409 design_already_ordered` (naming that `orderId`): the line
   drops its order request and needs attention with that reason, the order
   note names that Pixonaut order, and the
   order screen offers **Order again anyway**, which sets the line's
   `reorder` ref and sends the order again with `reorder: true` under the
   key `wc:<site>:item:<item id>:reorder`. An order
   whose `status` is `awaiting_prints`, `submitted`, `queued`, `printed` or `shipped` makes the
   line `ordered`; one placed (or adopted) as `failed`, or with any other
   status, keeps its id on the line and moves it to `needs_attention`
   (reason `order_failed` or `order_status`).

The first run stores the line's refs and keys, built from the current
connection's `<site>` (`Refs`); every later run reads them back,
so a reconnect never changes them:

| Ref | Value |
|---|---|
| `externalRef`, and the consent's `ref` | `wc:<site>:order:<order id>:item:<item id>` |
| `orderKey`, the order's `Idempotency-Key` | `wc:<site>:item:<item id>` |
| `orderKey` after **Order again anyway** | `wc:<site>:item:<item id>:reorder` |

The order request is stored as a snapshot (`orderRequest`) before it is first
sent, and sent from the snapshot on every later attempt, byte for byte. The
snapshot is dropped once the order's `id` is stored, so the customer's name
and address in `orderRequest` stay only while the order's outcome is unknown.
A lookup answer the plugin cannot read (a row that is not an object, an order
without an `id`) fails the job rather than reading as "no order", so it never
places a second one. Nothing is sent while the store is not
connected or its connection is paused, nor for a line whose `tenantId` is not
the connected workspace's.

A failed call stops the run; the steps before it stay stored, and
`Fulfilment\RetryPolicy` decides what follows. A transient failure (no
response, 5xx, 408, 429, `lock_timeout`,
`idempotency_key_in_use`) schedules the line's next job after 1 min, 5 min,
30 min, 2 h and 12 h in turn (filter `pixonaut_wc_fulfilment_retry_schedule`,
never shorter than a `Retry-After`), counted in the line's `retry` ref
(`{state, failures}`), which starts over when the line moves on. Any other
refusal, and a transient failure past the last wait (reason
`retries_exhausted`), moves the line to `needs_attention` with the code as
its reason and an order note (`Fulfilment\NeedsAttention`). A line reaching
`needs_attention` by any way raises an admin notice for its order, removed
once no line of the order is in that state, and sends the store the
`pixonaut_needs_attention` email (`Fulfilment\AttentionAlerts`). The job
itself never fails on a Pixonaut error.

An order that answers `outcome_unknown`, when placed or in
`order.status_changed`, moves the line to `needs_attention` with the reason
`outcome_unknown`, and is never sent again on its own.

An order that answers `held` (a free workspace past its plan's monthly
orders) is taken: the line is `ordered`, shows as held with the order's
`hold`, and gets one order note. It is no failure, so nothing is retried and
nothing needs attention; `order.released` moves it on (see
[Webhooks](./webhooks/#orderheld-and-orderreleased)).

The order screen's Pixonaut box (`Admin\OrderBox`) offers what
`Fulfilment\LineActions` allows for each line, read from `GET
/v1/orders/{id}` each time it renders: **Retry** (`POST
/v1/orders/{id}/retry`) while `canRetry`, adopting `newerOrderId` on `409
order_superseded`; for a line stopped before its order, the store's own next
job from the state it stopped in, and for a line of a paid order in
`committed`, `approved` or `printed` with no job pending or running, its job
scheduled again. For `outcome_unknown`, the settling moves
(`PATCH /v1/orders/{id}` to `submitted` with `reason` and an optional
`externalOrderRef`, or to `failed` with `reason` followed by a retry) when
`statusMoves` lists them, and cancel (`POST /v1/orders/{id}/cancel`). On a
sandbox connection, every move in `statusMoves` the line can follow is
offered as a rehearsal. Pixonaut sends the events of these moves `via:
store_key`, which the webhook handlers skip, so each action applies
Pixonaut's answer to the line itself. When that answer is lost, the next read
reconciles the line (`LineActions::reconcile()`): a status other than the
one last applied goes through `OrderStatusChanged` or `OrderFailed` (a
`cancelled` cancels the line), and a `failed` order with `canRetry: false`
hands the line to the order its `replacedByOrderId` names, read with
`GET /v1/orders/{id}` and followed on while that one was replaced too, as
long as each has the line's `externalRef`. An action that got no answer
(`transport_failed`) reports `unreachable` rather than a refusal. A
replacement order already on its way is applied as `order.status_changed`
would be, so one that shipped ships the line. In one render, the first read
that fails without an answer or with a retryable error stops the reads of
the remaining lines. Refused actions are logged as warnings, lines the store
could not save as errors. Actions need `manage_woocommerce`; the filter
`pixonaut_wc_fulfilment_line_actions` changes what is offered.

An `order.status_changed` to `outcome_unknown` created before the line's
last transition is skipped once the line has left `needs_attention`: the
outcome was settled after Pixonaut reported it.

### Cancelling and refunding

`Fulfilment\CancelTriggers` asks `Fulfilment\LineCancellation` to cancel a line when its order moves to
`cancelled` (`woocommerce_order_status_cancelled`) or `refunded`
(`woocommerce_order_status_refunded`), and when a refund
(`woocommerce_order_refunded`) brings a line's refunded quantity, counting
earlier refunds, to its whole quantity. A refund of part of a line's
quantity sends nothing and adds an order note. A line that is final, or
being cancelled already, is left alone; the filter
`pixonaut_wc_fulfilment_cancel_line` (trigger `order_cancelled`,
`order_refunded` or `line_refunded`) can keep a line going.

The request is stored in the line's `cancel` ref (`{trigger, failures}`),
pending `pixonaut_fulfil_item` jobs of the line are unscheduled, and one
`pixonaut_cancel_item` job (same group and argument) is scheduled. The
fulfilment job leaves a line with a `cancel` ref alone. The job acts by how
far the line got:

- **An order sent, its answer lost** (an `orderRequest` but no `orderId`).
  `GET /v1/orders?externalRef=` finds the order, which the line takes on and
  cancels as below. When none is found the job looks once more after the
  first wait, so an order still on its way is not missed, then goes on as
  for no Pixonaut order and drops the `orderRequest`.
- **No Pixonaut order.** When the line (or the state it left for
  `needs_attention`) is `committed` or `awaiting_customer`, each side not
  yet approved gets `POST /v1/designs/{id}/reject`, without
  `expectedRevision`, each side on its own; `409 not_committed` (approved
  meanwhile) is fine. An
  approved or printed line sends nothing. The line moves to `cancelled`.
  A reject refused for good still cancels the line, with an order note.
- **A Pixonaut order.** `POST /v1/orders/{id}/cancel`. `200` records the
  order's status and moves the line to `cancelled`. `409 order_at_printshop`
  leaves the line as it is, with an order note giving the `cause` (`made`,
  `begun`, `cannot_cancel`, `not_connected`, `status_moved`).
  `409 order_status_not_allowed` from `failed` cancels the line in the store
  only. `409 order_in_flight`, `503 cancel_unavailable` with `retryable:
  true`, and any failure `RetryPolicy` retries schedule the job again on the
  fulfilment waits (filter `pixonaut_wc_fulfilment_retry_schedule`), counted
  in the `cancel` ref; past the last wait, or on any other refusal, the line
  is left as it is with an order note telling the merchant to ask the
  printshop.

A line of another workspace, or one handled while the store is not
connected, is cancelled in the store without the reject; one with a
Pixonaut order is left as it is with a note. Once the request is settled the
`cancel` ref is dropped. Cancelling never moves money.

A job Action Scheduler would not store leaves the `cancel` ref and an order
note; asking again for that line, or saving the order on its screen
(`woocommerce_process_shop_order_meta`, after WooCommerce's own saves),
schedules it. While a line has a `cancel` ref, a customer's approval of its
proof (`design.approved`) is noted and not acted on; a line kept going by the
filter is approved and sent as usual.

### Changing the delivery address

When the order is placed, the line's `delivery` ref keeps a fingerprint
(`OrderAddress::fingerprint()`, a SHA-256 of `recipient` and
`shippingAddress` with their fields sorted) of the address the order request
held. The ref holds the address itself only in the request of a change
while it is on its way, and is dropped when the order's personal data is
erased.

`Fulfilment\DeliveryChange` watches order saves
(`woocommerce_before_order_object_save` and
`woocommerce_after_order_object_save`, both order stores). When the
shipping or billing address changed, it schedules one
`pixonaut_change_delivery` job (order item id as its argument, group
`pixonaut-for-woocommerce`) for each line that has a Pixonaut order, is not
final, is not being cancelled and was not erased. The job is due at once and
takes the place of any the line had pending, so a retry of an earlier change
waiting on the backoff does not hold the new address back. A line not yet
ordered needs no job: its order is placed with the address the order has
then. When the address changed while the order request was on its way, in
the same request or another, the new address is sent once the order is
placed.

The job maps the address as `POST /v1/orders` does (`OrderAddress::of()`,
filter `pixonaut_wc_fulfilment_order_address`). When no change is on its way
and its fingerprint is the one the printshop order has, the one Pixonaut is
telling the printshop, or the last one Pixonaut refused, the job ends. While
the store is not connected to the line's workspace (or the connection is
paused), the job logs a warning, notes on the order that the address was not
sent, and leaves the line as it is. Otherwise it numbers the change, and
stores its fingerprint, key (`wc:<site>:item:<item id>:delivery:<n>`) and
prepared request (`Orders::prepare_change_delivery()`), then sends `PATCH
/v1/orders/{id}/delivery` with `recipient` and `shippingAddress`. A retry of
the change sends the stored request, the same bytes under the same key; a
later change, even back to an earlier address, gets the next number. The
request is dropped once the change is answered. An address without a name,
street, city or country is not sent, with an order note.

- **`200`.** `delivery.state` (`accepted`, `carried`, `sending` or
  `refused` with the printshop's `words`) is kept on the line and noted on
  the order. For `accepted` and `carried` the fingerprint becomes the
  printshop order's. For `sending` it is kept as the one Pixonaut is
  telling the printshop (`awaiting`) until `order.delivery_changed` says
  what came of it. When the address changed again meanwhile, the job is
  scheduled again.
- **`503 address_change_unavailable` with `retryable: true`**, and any
  failure `RetryPolicy` retries, schedule the job again on the fulfilment
  waits, counted in the ref. When the waits are used up, or the retry could
  not be scheduled, whether the change arrived is not known: the change
  stays on its way with its key and request, the line keeps the last code,
  and the order note and box say so.
- **Any other refusal** (`409 order_past_address_change` with `status`,
  `409 address_change_not_accepted` naming the `printshop`, `422
  postal_code_required`, …) is logged and noted; the line keeps the code,
  and the same address is not sent again.

An answer ends only its own change: when a later change started meanwhile,
that one stays on its way. An answer older than the outcome the line holds
(`order.delivery_changed` came first) changes neither the outcome nor the
printshop order's fingerprint. Nothing is written to a line whose order's
personal data was erased while a change was on its way.

Saving the order on its screen (`woocommerce_process_shop_order_meta`,
priority 100, after WooCommerce saved the items) schedules the job again for
each line with a change on its way, its waits counted from the start, and
for each line whose address is neither the printshop order's, the one
Pixonaut is telling the printshop, nor a refused one, such as an address the
store could not send while it was not connected.

`Fulfilment\OrderDeliveryChanged` handles `order.delivery_changed`: the
lines are found as for `order.status_changed`, and the event's `outcome`,
`words` and `createdAt` replace the line's unless it holds a later one or
the order's personal data was erased. A change the store made (`by:
store_key`) is noted only when the outcome is new; once the printshop
answers a change Pixonaut was telling it, the awaited fingerprint becomes
the printshop order's (`accepted`, `carried`) or the refused one
(`refused`). One made in Pixonaut (`member`, `customer`, `secret_key`) is
always noted and, unless refused, takes the store's current address as the
one to compare with: the printshop order has an address the store does not
know, and only a later change of the WooCommerce address is sent. The order
screen's box shows where the last change stands
(`OrderBox::delivery_label()`).

### Proof deadline and reminders

When a `proof_email` line reaches `awaiting_customer`, `Approval\ProofTimers`
works out its deadline and reminder times from the settings of that moment
(`Approval\ProofSchedule`: the deadline `proof_deadline_days` after the
line's `changedAt`; the first reminder `proof_reminder_after_days` after it,
then every `proof_reminder_every_days`, at most `proof_reminders`, only
before the deadline), stores them in the line's `deadline` ref (`{at,
reminders, failures}`, Unix times) and schedules one `pixonaut_proof_deadline`
job (argument `item_id`) and one `pixonaut_proof_reminder` job per reminder
(`item_id`, `reminder` from 1), in the plugin's group. Leaving
`awaiting_customer` unschedules the line's pending ones; a job that runs
anyway does nothing for a line no longer waiting, and a reminder run at or
after the deadline sends nothing. A deadline job Action Scheduler would not
store moves the line to `needs_attention` (`retry_not_scheduled`) with an
order note.

A reminder fires `pixonaut_wc_approval_proof_reminder`, which sends
*Design proof reminder* (`pixonaut_proof_reminder`); the last one names the
deadline and what follows it.

The deadline job (`Approval\ProofDeadline`) acts on a line still in
`awaiting_customer`, without a `cancel` ref, whose order has not failed. The
`proof_no_answer` setting (filter `pixonaut_wc_approval_deadline_outcome`)
decides:

- `approve`: `POST /v1/designs/{id}/approve` per side not approved, with
  consent `{kind: "deadline", at, ref: externalRef}` and the line's revision,
  stored as the line's `consent`; then `approved` and the fulfilment job.
- `deny`: `POST /v1/designs/{id}/reject` per side not approved, then
  `Approval\ProofDenial` with cause `deadline`.

`409 not_committed` means the customer decided first: `GET /v1/designs/{id}`;
`approved` at the line's revision counts as approved (every side approved
sends the line on), `rejected` denies the line with cause `customer`, and
anything else, or `409 revision_changed`, moves it to `needs_attention`
(`design_changed`). A failure `RetryPolicy` retries, a store not connected
to the line's workspace, or a decision another request's write to the line
kept from being saved, schedules the job again on the fulfilment waits,
counted in the `deadline` ref; past the last wait the line moves to
`needs_attention`. The job then fires `pixonaut_wc_approval_deadline_passed`
with what became of the line (`approve`, `refund_cancel`, `cancel`,
`needs_attention`, or `unresolved` when the store could not decide and the
line needs attention for the reason it records), which sends *Pixonaut proof deadline passed*
(`pixonaut_deadline_passed`) to the store and, for a line cancelled, *Design
cancelled after deadline* (`pixonaut_proof_expired`) to the customer.

`Approval\ProofDenial` applies the `proof_on_deny` setting (filter
`pixonaut_wc_approval_on_deny`) to a line denied at its deadline or rejected
by the customer. It first claims the line by writing its `denial` ref
(`{cause}`) over the document it read, so of two handlers at once only one
acts, and a line denied already is left alone:

- `cancel` and `refund_cancel` ask `LineCancellation` to cancel the line,
  trigger `proof_expired` (deadline) or `proof_rejected` (customer). With
  `refund_cancel`, `Approval\LineRefund` then calls `wc_create_refund` for the
  line's remaining quantity, total and taxes (never shipping), with
  `refund_payment` when the order's gateway supports `refunds`; otherwise the
  refund is recorded with an order note asking the merchant to return the
  money. A refund the gateway refuses records nothing, leaves a note, and
  counts as `cancel`. The refund's own `woocommerce_order_refunded` finds the
  line being cancelled already.
- `needs_attention`, or a cancellation the `pixonaut_wc_fulfilment_cancel_line`
  filter keeps going, moves the line to `needs_attention` with reason
  `proof_expired` or `proof_rejected`.

## Privacy requests

`Privacy\PersonalDataExporter` and `Privacy\PersonalDataEraser` register
with `wp_privacy_personal_data_exporters` and
`wp_privacy_personal_data_erasers` (both under the id
`pixonaut-for-woocommerce`). The eraser registers at priority 5
(`PersonalDataEraser::ERASER_PRIORITY`), so it runs before WooCommerce's
erasers, which anonymise the order's billing email and customer. Each finds
the person's orders as WooCommerce's own do (`Privacy\CustomerOrders`:
billing email, or the customer account with that email, ten orders a page)
and acts on each tracked line.

The export has one item per line (group `pixonaut-designs`): order number,
product, each side's `designId`, and the line's Pixonaut `orderId` once it
has one.

The eraser hands each line not yet erased to `Privacy\LineErasure`, except a
paid line while WooCommerce's `woocommerce_erasure_request_removes_order_data`
is not `yes`, which is reported retained. A line counts as paid when its order
is paid or has a `date_paid`, or the line has an `orderId` or `orderRequest`.
`LineErasure::request()` stores `{failures}` in the line's `erase` ref, then:

- **Paid and not finished**: nothing is sent, and the eraser reports it
  retained ("needed to fulfil the order"). A line is finished in `shipped`,
  `failed` or `cancelled`, or in `needs_attention` with the `orderStatus` ref
  `failed` (its printshop order failed); a retry of an erased line's failed
  order is then refused `design_erased`. When the line becomes finished
  (`pixonaut_wc_fulfilment_transition`), one
  `pixonaut_erase_item` job (group `pixonaut-for-woocommerce`, argument
  `item_id`) is scheduled.
- **Otherwise**, at once: `POST /v1/designs/{id}/erase-personal-data` for each
  side without `erasedAt`, which records Pixonaut's `personalDataErasedAt`
  (`204`, a deleted design, and `404` record now); then
  `POST /v1/orders/{id}/erase-personal-data` for the line's `orderId` and
  every other order `GET /v1/orders?externalRef=` finds under the line's ref
  (a failed order a retry replaced), which records `orderErasedAt`
  (`order_not_found` counts as erased). The `erase` ref
  is then dropped and the order gets a note. A line paid for while its
  designs are being erased stops before the next side, keeps its `erase`
  ref, and waits as above.

`409 order_in_flight` and any failure `RetryPolicy` retries schedule the job
again on the fulfilment waits, counted in the `erase` ref, and the eraser
reports the line retained. Past the last wait, on any other refusal, or for a
line of another workspace or with no connection, the `erase` ref is dropped
and an order note says why; running the request again starts over. A line is
erased once every side has `erasedAt` and its order, if any, `orderErasedAt`;
the eraser skips it from then on.

`Proofs\ProofCache` drops a design's cached proof images after a `200` or
`204` of the design's erasure, whichever code sent it, and on
`pixonaut_wc_webhook_design_personal_data_erased`. It then keeps a
`pixonaut_wc_proof_erased_<md5>` transient for a week, while which nothing is
cached or served from the cache for the design, so a fetch already running
does not store the image again. A design no order line holds (one still in a cart) is
also marked in a `pixonaut_wc_erased_cart_design_<md5>` option, not
autoloaded, until the design is deleted. While either mark is there, the cart
shows the product's image and the proof route answers
`pixonaut_proof_not_found` without asking Pixonaut.

Pixonaut also erases on its own, after the workspace's retention period, with
the same `design.personal_data_erased` event (handled as above) and no event
for an order. Any read of a line's printshop order records its
`personalDataErasedAt` as `orderErasedAt`, and the order screen reads the
order of a shipped, cancelled or failed line until that is recorded.

## Features

Each layout and approval mode is a feature in `Features`, and every check for
one goes through `Features::enabled()`. All features are on.
