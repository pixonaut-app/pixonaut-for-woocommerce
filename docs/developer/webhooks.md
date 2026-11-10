---
title: Webhooks
description: How the plugin receives Pixonaut's webhooks and pulls its event stream, verifies, dedupes and handles the events, and how a site hooks into them.
sidebar:
  order: 5
---

Pixonaut delivers the store's events to
`POST /wp-json/pixonaut/v1/webhook` (or `?rest_route=/pixonaut/v1/webhook`),
the endpoint it registered when the store connected, under the REST address
the store named (`rest_url()`). The receiver is
`Pixonaut\WooCommerce\Webhooks\Receiver`; the work happens later, in an
Action Scheduler job.

## The endpoint

Right after a connect, `Pixonaut\WooCommerce\Webhooks\Endpoint` narrows the
endpoint to the event types in `Endpoint::EVENT_TYPES`
(`PATCH /v1/webhooks/{id}`): the `webhooks` of
`contracts/pixonaut/consumed.json` but `webhook.ping`, which is sent to every
endpoint and is no type one subscribes to. `consumed.json` is written from
that list (`pnpm contracts:consumed`), so adding a type there and
regenerating is the whole change to the contract.
If Pixonaut refuses the list, the refusal is logged and the endpoint keeps
receiving every type. The maintenance job subscribes a
connection again when the list differs from the one kept in the option
`pixonaut_wc_webhook_event_types`, so a connection made before a type was added
gets it within a day.

It then pings the endpoint (`POST /v1/webhooks/{id}/ping`) and keeps the
outcome, `{delivered, error, at}`, in the option `pixonaut_wc_webhook_ping`,
which the connection card shows and **Send test request** replaces. The card
reads the endpoint's `active`, `failing` and `failedCount` from
`GET /v1/webhooks`.

## Receiving a delivery

While the site is not the one that connected (the connection is paused with
`site_changed`, e.g. on a copy of the store's database), every delivery is
answered `503` (`"result": "site_changed"`) before anything else and a
warning is logged, so the copy keeps nothing and Pixonaut retries. A
connection paused as `unauthorized` still receives.

1. **Verify.** `x-pixonaut-signature` must be `v1=` and the hex
   HMAC-SHA256, keyed with the connection's webhook secret, of the
   `x-pixonaut-timestamp` value, a `.`, and the raw body. The timestamp must
   be within 300 seconds of the site's clock, either way. Anything else, or a
   store that is not connected, is answered `401`. The verifier is
   pixonaut-php's `Webhooks\Signature`, tested against Pixonaut's signature
   vectors in the SDK's own suite.
2. **Read.** The body must be an envelope `{id, type, createdAt, payload}`;
   otherwise `400`. A `webhook.ping` is answered `200` and goes no further.
3. **Dedupe.** The event is added to the table `{prefix}pixonaut_webhook_events`
   by its `id`. If the database refuses the write, the error is logged (event
   id and type, never the body) and the answer is `503`, so Pixonaut retries.
   A retry or redelivery of an event already there is answered `200`
   (`"result": "duplicate"`) and dropped; when that event was never handled
   and has no job (Action Scheduler lost it), it is queued again first, and
   the answer is `503` if Action Scheduler refuses. Rows are kept 30 days,
   longer than Pixonaut retries a delivery.
4. **Queue.** An async Action Scheduler job (`pixonaut_wc_webhook_process`,
   group `pixonaut-for-woocommerce`) takes the event id, and the receiver
   answers `200` at once. If Action Scheduler refuses the job, the event is
   forgotten and the answer is `503`, so Pixonaut's retry is taken as new.

Every `2xx` acknowledges the delivery; Pixonaut retries anything else.

Steps 3 and 4 are `Webhooks\Intake`, which the pull below uses too.

## Pulling the event stream

A delivery can be lost: the site was down past Pixonaut's retries, or a
firewall dropped it. So every 15 minutes a recurring job
(`pixonaut_wc_events_pull`, `Webhooks\EventsPull`) reads
`GET /v1/events?after=<cursor>&types=<types>`, following `nextCursor` until it
is null, and hands every event to the same intake as a delivery: an event
that came both ways is handled once. `types` is every webhook the plugin
consumes but `webhook.ping` and those Pixonaut only delivers to the
endpoint (`Endpoint::WEBHOOK_ONLY_TYPES`, now `template.renamed`), which the
stream answers `400 invalid_event_type` for: `Endpoint::EVENT_TYPES`, as the
endpoint is narrowed to, but never filtered, so a filtered type the pull would
be refused for cannot stop it.

- **Cursor.** The option `pixonaut_wc_events_cursor` holds the id of the
  last event pulled, with the `connection_id` of the connection it belongs
  to; a cursor of another connection counts as none. A job writes the cursor
  only while the connection it started with is still the stored one, so a
  pull still running when the store connects again or disconnects leaves no
  cursor for the connection after. After each page it moves to the page's `nextCursor`;
  when that is null, to the last event's id; an empty last page keeps it.
  With `types`, Pixonaut answers a page short or empty while the cursor still
  moves on. An event the intake could not keep or queue stops the pull with
  the cursor before it, so the next pull reads it again. A pull reads at most
  `EventsPull::MAX_PAGES_PER_JOB` pages; the next goes on from there.
- **First cursor.** When the store connects, and every time it connects
  again, the cursor is dropped and a job (`pixonaut_wc_events_baseline`)
  pages to the stream's end without acting on any event: what happened
  before the store connected is not replayed. Then it stores the cursor and
  fires `pixonaut_wc_events_baseline_set`, on which every open line is read
  afresh (below), and every product linked to Pixonaut is checked again
  ([Templates and blanks changed in Pixonaut](#templates-and-blanks-changed-in-pixonaut)). A cursor Pixonaut answers `400 unknown_event` for sets a new first
  cursor the same way; so does a stored point the paging stopped at, which
  makes it start over from the stream's start.
- **Redelivery.** After each pull, `GET /v1/webhooks` tells whether the
  store's endpoint has deliveries that failed for good (`failedCount`); when
  it has, `POST /v1/webhooks/{id}/deliveries/redeliver-failed` asks Pixonaut
  to send them again. They are dropped as duplicates when the pull took them
  already. A store `GET /v1/connect` reads as `reachable: false` asks for
  nothing: Pixonaut queues it no deliveries, and the pull is how it gets its
  events ([Webhooks on a local store](../developer-tools/#webhooks-on-a-local-store)).
- **Paused, disconnected.** While the connection is paused nothing is
  pulled, and the pull goes on from the cursor once it is not. Disconnecting
  forgets the cursor and unschedules the pull.

### Reading open lines

On `pixonaut_wc_events_baseline_set` a job (`pixonaut_wc_line_read_queue`)
queues one job per line that is not `shipped` or `cancelled`
(`pixonaut_wc_line_read`, `Fulfilment\LineReader`), 100 lines at a time
(`LineReader::QUEUE_BATCH`); when a page is full it queues itself for the
page after, so a large backlog is never queued in one request. Each read job
reads the line's printshop order, when it has one, with `GET /v1/orders/{id}`,
and the design of each side with `GET /v1/designs/{id}`. The order's `status`
is applied as `order.status_changed` would apply it, its `history` giving the
status before; the design's revision, a `404` and `personalDataErasedAt`
as `design.edited`, `design.deleted` and `design.personal_data_erased` would.

A read that fails (Pixonaut cannot be reached, answers an error, or the line
keeps changing while a change is applied) is logged with the order item id
and attempt, and the line is read again in a new job: 1 minute later, then 2,
4 and 8, for 5 attempts in all (`LineReader::MAX_ATTEMPTS`). The job itself is
recorded as failed in Action Scheduler. A retry reads a line the attempt
before moved to `shipped` too, so its designs are still read. A job Action
Scheduler refuses to queue is logged with the order item id.

## Handling an event

The job reads the event back and runs the handler its type maps to. A type
with no handler is ignored. Then it fires the type's action, whether a
handler ran or not, and marks the event handled, so it runs once per event
id.

If the handler or an action throws, the error is logged with the event id,
type and attempt, and the event is tried again in a new job: 1 minute later,
then 2, 4 and 8, for 5 attempts in all. The job itself is recorded as failed
in Action Scheduler. Handlers are safe to run again: a change already applied
to a line changes nothing.

While the site is not the one that connected (`site_changed`), events wait:
a job that runs then, for an event kept before the database was copied, ends
without handling it or counting an attempt. The maintenance job queues it
again, and it is handled once the pause lifts. If the copy connects to
another workspace instead, the handler ignores the old workspace's events.

A daily maintenance job (`pixonaut_wc_webhook_maintenance`) queues again up
to 100 events received more than an hour ago that were never handled, have
attempts left and have no job, then drops events older than 30 days, pulled
ones included.

| Event | Handler |
|---|---|
| `order.status_changed` | `Fulfilment\OrderStatusChanged` |
| `order.failed` | `Fulfilment\OrderFailed` |
| `order.held` | `Fulfilment\OrderHeld` |
| `order.released` | `Fulfilment\OrderReleased` |
| `order.cancelled` | `Fulfilment\OrderCancelled` |
| `order.submitted` | `Fulfilment\OrderSubmitted` |
| `order.delivery_changed` | `Fulfilment\OrderDeliveryChanged` |
| `order.tracking_added`, `order.tracking_changed` | `Fulfilment\OrderTrackingChanged` |
| `design.edited`, `design.deleted`, `design.personal_data_erased` | `Fulfilment\DesignChanged` |
| `design.approved` | `Approval\DesignApproved` |
| `design.changes_requested` | `Approval\ChangesRequested` |
| `design.rejected` | `Approval\DesignRejected` |
| `template.published`, `template.live_changed`, `template.deleted`, `blank.updated`, `blank.deleted`, `blank.restored` | `Catalogue\ProductRecheck` |
| `share_link.revoked`, `share_link.regenerated` | `Storefront\DesignerLinkChanges` |

### `order.status_changed`

- The line is found by the Pixonaut `orderId` it stored when it placed the
  order (order item meta `_pixonaut_order_id`, kept in step with the line
  state). An `orderId` the store holds no line for changes nothing.
- A line is left alone when its `externalRef` differs from the event's, or
  when it belongs to another workspace than the connected one.
- A change with `via: "store_key"` is the echo of a change the store made
  with its own key, applied when it was made, and is ignored.
- A change applies only when it moves the line on: its `previousStatus` is
  the status last applied to the line, or its `status` lies further along
  `held`, `awaiting_prints`, `submitted`, `queued`, `printed`, `shipped` than that one. Once `failed` or
  `outcome_unknown` was applied, only a change whose `previousStatus` is that
  status applies, so a forward change delivered late cannot undo it. A
  duplicate, or a change delivered after a later one, changes nothing, so
  deliveries in any order end in the same line state. The status applied is kept in the line's
  `orderStatus` ref.

| Pixonaut status | Line state | Also |
|---|---|---|
| `held` | `ordered` | the order box shows "Held by Pixonaut", with why and when it goes ahead |
| `awaiting_prints` | `ordered` | the order box shows "Waiting for prints" |
| `submitted`, `queued` | `ordered` | |
| `printed` | `ordered` | order note |
| `shipped` | `shipped` | `tracking` (`carrier`, `number`, `url`) in the line's `tracking` ref, unless it holds newer tracking (see `order.tracking_added`), order note with it |
| `failed` | unchanged | `order.failed` moves the line |
| `outcome_unknown`, `cancelled` | unchanged | |
| any other | unchanged | order note naming the status |

A line state never moves backwards, whatever the map says
(`Fulfilment\LineState`). When a line ships and every line of a `processing`
order is a shipped Pixonaut line, the order is completed; an order with a line
the plugin does not fulfil never is. A shipment that leaves the order
`processing` sends the part-shipped email and, once every Pixonaut line of a
mixed order has shipped, adds the order note (`Fulfilment\Shipments`); an
order no longer `processing` gets neither. The line's `shipmentHandled` ref
is set once this is done. A `shipped` change handled again for a shipped line
without that ref does it then, so a run that stopped part way is finished;
for a line with it, the order is only checked for completion again. The
line's own shipped note is not repeated.

The line is written only over the version this job read. When another request
writes it in between, the job reads it again and retries, three times; after
that the handler throws and the event is tried again later. A line the
`_pixonaut_order_id` index finds is acted on only when its own `orderId` ref
matches, and every save of a line brings the index back in step.

### `order.failed`

The lines are found as for `order.status_changed` (`Fulfilment\OrderEventLines`).
Each moves to `needs_attention` with the reason `order_failed`, its
`orderStatus` ref set to `failed` and an order note carrying the event's
`reason`; the store's notice and email follow from the state change. A line
that is final, or in `needs_attention` already, is left as it is, so a
repeated failure is noted once.

### `order.held` and `order.released`

A free Pixonaut workspace's orders past its plan's monthly limit are not
refused: `POST /v1/orders` answers `201` with `status: "held"` and a `hold`
(`reason`, `limit`, `periodEnd`). The line is `ordered` with the order's id,
its `orderStatus` ref `held` and the hold in its `hold` ref
(`Fulfilment\OrderHold`), and its order gets one note saying why and when it
goes ahead. It is not a failure: no `needs_attention`, no retry, no email. A
`reason` the plugin does not know (only `monthly_order_limit` is known) shows
as held without one.

`order.held` (`reason`, `limit`, `periodEnd`) is applied the same way when the
store did not know yet. For a line shown as held already, or one released or
moved on since, it changes nothing. A read of the order
(`OrderStatusChanged::apply_read()`, `LineActions::reconcile()`) or a retry
Pixonaut answers held keeps its `hold` the same way (`OrderHold::keep()`):
once, and never after the release.

`order.released` (`releasedBy`: `upgrade` or `month_reset`; `status`) is the
move out of `held`: Pixonaut sends no `order.status_changed` for it. The line
moves on to `status` as a change from `held` would move it, so later
`order.status_changed` events apply from there and a release delivered after a
later change does not move the line back. Pixonaut releases to
`awaiting_prints` and hands the order on later with `order.status_changed`;
the spec allows any order status, so the plugin applies whichever it names.
`periodEnd` is ISO 8601 with milliseconds (`2026-11-01T00:00:00.000Z`) and is
parsed, never compared as a string. A release straight to `failed` or
`cancelled` is applied by `OrderFailed` or `OrderCancelled`, as their events
would be: the line needs attention, or is cancelled, with that outcome's note.
The release is recorded in the line's `releasedBy` ref, its `hold` ref is
cleared and its order gets one note naming what released it, which says it
goes on to the printshop only when it does. A release delivered again, or
before the hold, is applied once, and a hold delivered after it changes
nothing.

### `order.submitted`

The order the store placed itself, with no `replacesOrderId`, matters only
while it waits for its prints: Pixonaut sends `order.submitted` when it hands
such an order to the printshop. A line whose `orderStatus` ref is
`awaiting_prints` is then brought in step with the order as Pixonaut has it
(`LineActions::current()`), so a status whose event was handled first, such
as an unknown outcome of the hand-on, is applied too; nothing is written
before the order is read, so a read that fails leaves the event to be tried
again. Any other line took its order's status when the order was placed,
and is left as it is.

A retry is an order that replaced a failed one, `replacesOrderId` naming it,
placed when the order was retried in Pixonaut. The line holding the replaced order's id is found as for
`order.status_changed`, so a line whose `externalRef` differs from the
event's is left alone. Unless it is final, the line takes on the new order:
its `orderId` ref becomes the new id, the replaced one is added to its
`replacedOrderIds` ref, its `orderStatus`, `hold` and `releasedBy` refs are
cleared, and an order note
names the new order. Then `submitted` is applied as `order.status_changed`
would apply it, which moves the line from `needs_attention` back to
`ordered`. The new order's later events, delivered or pulled, and the read
of open lines all find the line by the new id; an event for the replaced id
no longer does, and one found before the handoff is dropped when it comes to
write the line. A retry of the new order hands the line on again. The
store's own **Retry** takes the new order on the same way. A line whose
`order.submitted` never arrived still moves: the next read of its failed
order (`LineActions::reconcile()`) follows its `replacedByOrderId`.

Event jobs need not run in the order Pixonaut sent the events, so the
handler then reads the new order (`GET /v1/orders/{id}`) and brings the line
in step with it, as the order screen does: a status whose event was handled
first, and matched no line then, is applied now, and when the new order
failed and was retried in turn, the line follows that replacement too,
through its `replacedByOrderId`. A read that fails throws, and the event is
tried again. Handling the event again finishes a run that stopped part way,
also when the line's document was written but its `_pixonaut_order_id`
index was not.

### `order.cancelled`

The lines are found as for `order.status_changed`. Each that is not final
moves to `cancelled`, its `orderStatus` ref set to `cancelled` and its
`cancel` ref dropped, with an order note. The event is applied whoever
asked for the cancel, `via: "store_key"` included: the store's own cancel
already cancelled the line, so the event changes nothing then, and when the
store's answer was lost it finishes the job. Refunding the customer stays the
merchant's.

### `order.delivery_changed`

The lines are found as for `order.status_changed`. The event's `outcome`
(`sending`, `accepted`, `refused` or `carried`) and the printshop's `words`
are kept in the line's `delivery` ref for the order screen, unless the ref
holds an outcome from later than the event's `createdAt`. A change the
store made with its own key (`by: "store_key"`) is noted on the order only
when its outcome is new, since the store noted Pixonaut's answer to it; when
Pixonaut answered it `sending`, the event's outcome decides whether the
address it sent becomes the printshop order's or a refused one. A change
made in Pixonaut is always noted, with a note that the WooCommerce order's
address stayed as it was, and the store takes that address as the one to
compare with, so only a later change of it in WooCommerce is sent. An event
about a line whose order's personal data was erased is left out. The event
never carries the address. See
[Changing the delivery address](../#changing-the-delivery-address).

### `order.tracking_added` and `order.tracking_changed`

Pixonaut sends `order.tracking_added` when the printshop gives tracking for an
order that shipped without any, and `order.tracking_changed` when it corrects
a shipped order's tracking; both carry the order's `tracking` as it now is.
The lines are found as for `order.status_changed`. Each takes the tracking in
its `tracking` ref, with the event's `createdAt` in its `trackingAt` ref. For a
shipped line the order gets a note with the tracking (and, for a correction,
the tracking it had), and the customer the `pixonaut_tracking_changed` email,
one per WooCommerce order listing each line whose tracking changed.

Events need not arrive in the order Pixonaut sent them. An event created
before the one that set the line's tracking, or one bringing the tracking the
line has, changes nothing, so a repeat or a late older event is neither noted
nor emailed again. A line that has not shipped yet, the event having overtaken
the `order.status_changed` that ships it, keeps the tracking silently; the
shipment then keeps it too, unless it carries newer tracking itself, and is
noted with it. A newer event bringing the tracking the line has only moves
`trackingAt` on, so an older event delivered after it cannot bring back what
came between. A shipped line holds the `trackingNotice` ref from the save
until the customer was emailed: handling the event again after a run that
stopped part way sends the email then, and no second note. A read of the
order (`OrderStatusChanged::apply_read()`) that ships the line dates its
tracking by the `at` of the last `order.tracking_added`,
`order.tracking_changed` or shipping entry of the order's history, and keeps
the line's tracking when an event set newer. `previousTracking` is not
compared.

### Designs changed in Pixonaut

A design can be corrected, deleted or erased in Pixonaut, outside the store.
The lines holding it are found by their sides' `designId`; a line made under
another workspace is left alone. Each change is noted on the order once,
however often it is reported.

- **`design.edited`.** The design is read again (`GET /v1/designs/{id}`):
  what counts is its revision now, not the event's.
  - At the line's revision, or one already noted: nothing.
  - Newer, and the line `committed` or `awaiting_customer`: the new revision
    is kept on the side, an order note says so, and the line's approval mode
    runs again: once the order is paid, the line's fulfilment job approves
    the new revision with the customer's stored consent, or waits for the
    customer to approve the proof. A new job is queued unless one is still
    pending; a job already running does not count, since it may have read
    the line before the edit. A job Action Scheduler refuses is logged with
    the order item id.
  - Newer, and the line `approved` or `printed`: Pixonaut cleared the
    approval, and the line's print may be of the old revision, so the line
    goes to `needs_attention` (reason `design_edited`) with an order note.
    The side keeps the revision approved.
  - Newer, and the line `ordered` or `shipped`: an order note only.
  - A design Pixonaut answers `404` for is taken as deleted.
- **`design.deleted`.** A line not `shipped`, `cancelled` or `failed` goes to
  `needs_attention` (reason `design_deleted`); a finished one
  gets an order note only. The side records when (`deletedAt`).
- **`design.personal_data_erased`.** The side records when (`erasedAt`, the
  event's `createdAt`); the line's state does not change. The design's cached
  proof images are dropped.
### `design.approved`

Resumes a line whose approval mode is proof by email once the customer
approved its proof page.

- The line is found by the design's id (order item meta
  `_pixonaut_design_id`, one row per side, kept in step with the line state).
  A line the index finds is acted on only when one of its sides holds the
  design, and only when it belongs to the connected workspace.
- Only a line whose approval mode is `proof_email` and which is in
  `awaiting_customer` changes; an approval of any other line, such as the
  store's own approval at payment for the other modes, changes nothing.
- A `committed` line whose fulfilment job is pending or running is still
  preparing its proof: the handler throws, so the event is handled again
  later, once the line waits.
- A line already `approved` gets its fulfilment job scheduled when none is
  pending, so a delivery handled again repairs a job lost after the line was
  saved.
- When the order is `cancelled`, `refunded` or `failed`, the line is left as
  it is, with an order note and a log entry; nothing is printed.
- When the event's `revision` is the line's, the side is recorded as approved
  (side ref `approved`, which `Approval\ProofRequest` also sets at payment on
  a side approved already, in the studio for one). Another `revision`, or
  none, is checked against the design read again: approved at the line's
  revision counts, approved at another moves the line to `needs_attention`
  with an order note, anything else changes nothing.
- Once every side of the line is approved, the line moves to `approved`, an
  order note says so, and the line's fulfilment job is scheduled, which
  prints and orders it; a job Action Scheduler would not store is thrown,
  for the event to be handled again. The store does not approve the design
  again: the proof page did.

Like `order.status_changed`, the line is written only over the version this
job read, with three tries before the handler throws.

### `design.changes_requested`

The customer asked for changes on a proof page. The page itself sends them
back to the store to make them ([Storefront](../storefront/#changes-asked-for-on-a-proof));
the event is the fallback for a customer who closed the tab.

- A line holding the design is acted on only when it belongs to the
  connected workspace and its approval mode is `proof_email`.
- The order gets a note with the customer's `message`, and
  `pixonaut_wc_approval_changes_requested` fires with the line, the side and
  the message, which sends *Pixonaut design changes requested*
  (`pixonaut_changes_requested`) to the store.
- While the line is in `awaiting_customer`, the side is not approved yet and
  its revision is the event's `revision`, the hook also carries an edit
  address: the proof page's return route with a `state` signed for that
  revision, which the *Edit your design* email (`pixonaut_changes_link`)
  sends the customer. A customer who changed and approved the design before
  the event was handled gets no email. Both emails are sent at once rather
  than through WooCommerce's deferred email queue.
- The line's state does not change: it waits for the customer's approval of
  the changed design.

### `design.rejected`

The customer rejected a proof on its proof page.

- A line holding the design is acted on only when it belongs to the
  connected workspace, its approval mode is `proof_email`, it is in
  `awaiting_customer` without a `cancel` ref, and its order has not failed.
  A line still in `committed` with its fulfilment job pending throws, so the
  event is handled again once the proof is sent.
- An event about another revision than the line's, or without one, counts
  only when `GET /v1/designs/{id}` reads `rejected`.
- Past the line's deadline the event is left to the deadline job
  ([Fulfilment](../#proof-deadline-and-reminders)), which follows a
  rejection it finds; the rejection may be the job's own.
- Otherwise the line is denied as the `proof_on_deny` setting says
  (`Approval\ProofDenial`, cause `customer`).

### Templates and blanks changed in Pixonaut

`template.published`, `template.live_changed`, `template.deleted`,
`blank.updated`, `blank.deleted` and `blank.restored` check again the products and variations
that use the template or blank (`Catalogue\ProductRecheck`), as does
`pixonaut_wc_events_baseline_set` for every one with a configuration. A
product or variation saved outside the product screen with attributes its
variant was not matched on queues its re-check too (`Catalogue\VariantWatch`).

- **Finding them.** Product and variation meta indexes what each stored
  configuration uses: `_pixonaut_template` (one row per template base id) and
  `_pixonaut_blank`, written with `_pixonaut_config` (`Catalogue\ProductIndex`;
  a migration indexed the configurations stored before it). A job
  (`pixonaut_wc_catalogue_recheck_queue`) reads 100 products at a time and
  queues their re-check in batches of 10 (`pixonaut_wc_catalogue_recheck`);
  when a page is full it queues itself for the page after. When Action
  Scheduler refuses the first job, the handler throws and the event is
  handled again later; a page whose batches or next page could not all be
  queued is recorded as failed, after trying each, and logs the products.
- **What is checked.** The event only says which products to look at; the
  re-check reads what is true now. Each batch reads the live templates once
  (`GET /v1/templates?status=live`, paged to the end), then each product the
  way the product screen checks it on save: the blank and its placements
  (`GET /v1/blanks/{id}`) and every size of each side's menu
  (`POST /v1/templates/{id}/print-size`, the placement's default sized again
  with `GET /v1/templates/{id}/constraints`). It then resolves the blank
  variant of the product, or of each of its variations, again
  (`Catalogue\ProductVariants`, `GET /v1/blanks/{id}/variants`), so a variant
  retired or added in Pixonaut changes which can be bought (see
  [Stored data](../#stored-data)). An event delivered late or twice changes
  nothing.
- **Versions.** A side follows its template's live version unless it is
  pinned to one (`template.pinned`, **Stick to one version** on the product
  screen), where it stays and only `template.latestVersion` moves. When a
  followed side moved, the version it had becomes `previousVersion`, so a
  design made from the version just replaced still goes into the cart; one
  from an older version does not. Saving a product keeps a followed side on
  the latest version it knows, so a form opened before a re-check does not
  move it back.
- **Not live.** A template missing from the live list (paused or deleted)
  makes the configuration `template_not_live` without asking anything more:
  Pixonaut opens no designer for it. Saving a product reads each side's
  template too (`GET /v1/templates/{id}`) once its sizes pass: a `status`
  other than `live` makes it `template_not_live`, and a template Pixonaut
  answers `404` for `unknown_template`. A paused template keeps its versions
  readable, so only `status` tells.
- **Deleted blanks.** Pixonaut still reads a deleted blank by id, with
  `deleted` set, until it is restored. Such a blank makes the configuration
  `blank_deleted` without checking its sides, which keep their menus for the
  re-check `blank.restored` queues; a problem in what was entered
  (`InvalidReason::INPUT`) is kept instead, so the restore does not clear it. A blank Pixonaut answers `404` for makes
  it `unknown_blank`. The product panel reads the blank too, and says it is
  deleted before the re-check has run.
- **Outcome.** What changed is stored; a configuration that cannot be sold is
  marked invalid with its reason and its product raises the "Customers
  cannot buy" notice, which the re-check that finds it sellable again
  clears. While invalid, each side keeps its whole size menu as pending, so a
  later re-check (the blank fixed) sells it again. A problem in what the
  store manager entered (`InvalidReason::INPUT`: a missing template or
  placement, a duplicate placement or side key, too many sides or sizes, an
  invalid size) stays until the product is saved again. A configuration
  written by a newer plugin version is left as it is.
- **Saved meanwhile.** The result is written only over the configuration the
  re-check read (`ProductConfig::replace_on()`, one conditional update): a
  configuration saved while Pixonaut was being asked is newer, so it and its
  index are kept, and the re-check raises no notice for it.
- **Pixonaut away.** A product whose check Pixonaut did not answer
  (`check_failed`, for any side, whatever another side's verdict) keeps its configuration, so it stays on sale, and is
  checked again in a new job: 1 minute later, then 2, 4 and 8, for 5
  attempts in all (`ProductRecheck::MAX_ATTEMPTS`). A batch whose live list
  could not be read is retried the same way, and the job is recorded as
  failed in Action Scheduler.

### A template's id changed in Pixonaut

A member can change a published template's id in the studio.
`template.renamed` (`{oldId, newId, renamedAt}`, base ids without a version)
says so; Pixonaut keeps answering `<oldId>@<version>`, and moves the
designs, proofs and designer links made under the old id to the new one, so
a design read afterwards names the new id. `Catalogue\TemplateRename`
handles it without asking Pixonaut anything:

- **Noted.** The option `pixonaut_wc_template_renames` keeps every change
  as `{from, to, at}` (`Catalogue\TemplateRenames`), `at` being the event's
  `renamedAt`. The history is only added to, and read by time: a chain of
  changes is followed to its end, a change delivered again, late or after a
  later one undoes nothing, and an id a template takes back names it again.
  It is written over the row it read, so two changes noted at once are both
  kept; one that cannot be stored fails the job before anything is moved.
  Add to cart compares a design's template with the side's through it, so a
  design on the new id is accepted while the side still holds the old one.
- **Products.** Every product and variation indexed under the old id
  (`_pixonaut_template`) has the side on it, and the layout adjustments of
  its standing rows, moved to the template's id now, with its index, as one
  conditional write over the configuration it read
  (`ProductConfig::replace_on()`). Versions, pins and everything else stay as
  they were.
- **Order lines.** Each line one of whose sides was made from the template
  has its `templateBaseId` moved.
- **Paged.** The event's job moves the first 100 products and order lines
  (`TemplateRename::PAGE`); a full page queues a job for the pages after it
  (`pixonaut_wc_template_rename`). Every job moves to the id the template has
  when it runs, so one running after a later change moves straight to the
  last id.
- **Carts.** A cart line lives in its customer's session, so it takes the
  new id when the cart is next loaded (`Cart\CartTemplateRenames`). A cart
  line's product hash takes each side's template by the first id the store
  knew it by, so the rename does not drop it as a changed product.
- **Again.** Delivered twice, or naming an id no product uses, it changes
  nothing more. A product or line saved while it was moved is moved by a job
  queued again later, from the start, 60 seconds later and doubling, up to 5
  attempts in all (`TemplateRename::MAX_ATTEMPTS`); a job that cannot be
  queued fails the one that queues it, which is tried again.
- **Missed.** `GET /v1/events` does not list `template.renamed` yet
  (pixonaut-app/pixonaut#3451). A store that missed the delivery keeps the
  old id, which Pixonaut goes on answering, but a design made from then on
  names the new one and is refused at add to cart until the event is handled.

### Designer links changed in Pixonaut

A member can withdraw or regenerate one of the store's designer links in the
studio (the Personalise button's links; see
[Storefront](../storefront/)). `share_link.revoked` and
`share_link.regenerated` name the link (`linkId`, `kind`, `subjectId`, the
template) but never its address. `Storefront\DesignerLinkChanges` handles
both for a `designer` link and ignores every other kind:

- **Withdrawn.** The link is forgotten from every product or variation
  keeping it (`_pixonaut_designer_links` meta), in the bin too; the product
  mints a new one when a customer next presses Personalise or it is saved.
- **Regenerated.** The link keeps its id under a new address, which
  `GET /v1/share-links` for its template lists (narrowed with `q`, the
  link's id, and read until it is found); the kept link takes it. A link no
  longer listed is forgotten as a withdrawn one is. When Pixonaut cannot say
  the address, the link is forgotten and withdrawn
  (`DELETE /v1/share-links/{id}`, asked again later when that fails too), so
  the product mints one that works.

An event about a link no product keeps, such as one the store withdrew
itself (which emits `share_link.revoked` too), changes nothing and asks
Pixonaut nothing. Each change takes the product's mint lock
(`pixonaut_wc_designer_link_lock_<id>`) and reads the kept links afresh, so
a mint in between is never overwritten; while another request holds it, the
event is handled again later.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `pixonaut_wc_webhooks_event_types` | filter | The event types the endpoint is narrowed to after a connect |
| `pixonaut_wc_webhook_handle` | filter | Return `false` to take an event over; the plugin's handler then does not run |
| `pixonaut_wc_webhook_<type>` | action | After an event was handled, with its dots as underscores: `pixonaut_wc_webhook_order_status_changed( $payload, $event, $handled )` |
| `pixonaut_wc_fulfilment_order_status_map` | filter | The line state each Pixonaut order status puts a line in |
| `pixonaut_wc_fulfilment_complete_order` | filter | Whether a shipped line completes an order whose every line is a Pixonaut line |
| `pixonaut_wc_events_baseline_set` | action | Once per connection, after the event stream's first cursor was set: read the store's state afresh |

The `$event` these hooks pass is pixonaut-php's `Webhooks\Event` (under
`Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\`), with `id`, `type`,
`createdAt` and `payload`. Before 2.0 it was the plugin's own
`Webhooks\Event`, whose time was `created_at`.

Each hook's parameters and an example are in the [hooks reference](../hooks/).

## Testing

The fake Pixonaut delivers signed events to the tests site
(`POST /__fake/webhooks/emit`), moves an order along its statuses,
emitting `order.status_changed` for each step
(`POST /__fake/orders/move`), holds the next orders placed as a free
workspace past its limit (`POST /__fake/orders/hold`), sends `order.held`
for a held order (`POST /__fake/orders/held-event`) and releases one with
`order.released` (`POST /__fake/orders/release`), drops deliveries while keeping the events in
its stream (`POST /__fake/webhooks/drop`), and edits, deletes or erases a
design (`POST /__fake/designs/edit|delete|erase`); see
[Testing against the fake Pixonaut](../testing/). The integration suite
(`tests/php/Integration/Webhooks/WebhookTest.php`) delivers signed requests
through the REST server and runs the queued jobs as Action Scheduler would;
`EventsPullTest.php` pulls scripted pages of the stream.
