---
title: Testing against the fake Pixonaut
description: The fake Pixonaut every test talks to, its control API and its fixtures.
sidebar:
  order: 2
---

No test reaches a real Pixonaut. Flows are tested against a fake: a small
Pixonaut stand-in in `tests/fake-pixonaut/` that serves the operations the
plugin consumes, at the commit the contract is pinned to. The only real
Pixonaut host a test may load is the pinned SDK path on `cdn.pixonaut.app`.
GitHub is stubbed as well: the integration bootstrap refuses it with every
other outside host, and the e2e tests site answers the update checker's
release requests to `api.github.com` with an empty list.

## Running it

`pnpm env:start` starts the fake with WordPress, as a container on the same
Docker network, replacing any fake an earlier run of this checkout left
behind. `pnpm env:stop` and `pnpm env:destroy` remove the container and then
the network. It listens on
`FAKE_PIXONAUT_PORT` (8890 when unset), so every checkout runs its own fake
beside its own WordPress. The port is published on the host's loopback
(`127.0.0.1`) only, so nothing else on the network reaches the fake.

`pnpm env:start` writes `.wp-env.override.json` (not committed), which gives
both sites the constants that point the plugin at the fake:

| Constant | Value | Used by |
|---|---|---|
| `PIXONAUT_WC_API_BASE` | `http://localhost:<port>` | The browser: the SDK's `apiBase` |
| `PIXONAUT_WC_SERVER_API_BASE` | `http://fake-pixonaut:<port>` | WordPress itself, inside Docker |
| `PIXONAUT_WC_STUDIO_BASE` | `http://localhost:<port>` | Links into Pixonaut's studio |

| Command | What it does |
|---|---|
| `pnpm fake:start` / `pnpm fake:stop` | Rebuilds and restarts the fake alone, after a change to it, on the port WordPress was given |
| `pnpm fake:logs` | Follows its log, which names every contract violation |
| `pnpm fake:build` | Bundles it into `tests/fake-pixonaut/dist/`, the image's build context |
| `pnpm fake:types` | Regenerates its types from `contracts/pixonaut/openapi.json` |

Unit tests (`pnpm test:js`) run the fake in process, with no container.

## The contract it holds

Every request and every response is validated against the pinned
`contracts/pixonaut/openapi.json` (JSON Schema 2020-12 with Ajv, tuples
strict, `x-extensible-enum` codes open). JSON bodies and text bodies
(`text/plain`, read as one string) are checked against their schemas; binary
bodies such as font files are not. A body on a status the reference declares
without one is a violation too. A request the reference does not allow, or
an answer the fake gives that it does not allow, is a contract violation: the
fake logs it and answers `599`, so the test fails rather than reading an
ordinary `400`. A path with a malformed percent-escape is one as well. When
the fake itself fails (a handler throws), it logs that as a violation and
answers `500`. The e2e suite also fails any test after which the violation
log is not empty.

- **Credentials** come from each operation's `security` in the reference:
  `Authorization: Bearer ik_…` (integration key), `pk_…` (publishable key,
  taken only with an `Origin` its store allows) or `link_…` (share link,
  reaching only its own template or design). A missing or unknown credential
  is `401 unauthorized`; a kind the operation does not take is
  `403 forbidden`.
- **Idempotency** works as in Pixonaut: one key space per store, a key kept
  for 24 hours, a repeat answered with what the first made and
  `Idempotent-Replayed: true`, a repeat while the first is in flight refused
  `409 idempotency_key_in_use` with `Retry-After`, the same key with another
  body refused `422 idempotency_key_reused` (the body compared by meaning,
  so the order of its keys does not matter), and a key freed again when its
  request was refused.
- **Isolation**: another tenant's or another store's records answer `404`.
- **CORS** reflects the origin on `/v1/*`, preflight included; font files and
  catalogue images are open to every origin.

An operation that is not in `contracts/pixonaut/consumed.json` answers `501`
and counts as a violation. The fake serves every consumed operation and
delivers every consumed webhook: a unit test fails when
`tests/fake-pixonaut/src/handlers/index.ts` and `consumed.json` disagree. A
change that starts calling an operation adds its handler there and
regenerates `consumed.json` (`pnpm contracts:consumed`). An operation only
planned (`pending`) needs no handler yet.

## The SDK's routes

A product page mounts the real SDK against the fake (its `apiBase`), so the
fake serves what the SDK sends with the store's publishable key: the template
read, its constraints and print-size check, the blank's views and variants
(`GET /v1/blanks/{id}/mockups`, `GET /v1/blanks/{id}/variants`) and their
photos (`GET /i/{token}`), fonts, `POST /v1/proofs`, the upload routes
(`POST /v1/assets`, `GET /v1/assets/{id}/content`,
`POST /v1/assets/{id}/improve-resolution`,
`POST /v1/assets/{id}/cut-out` and its `GET`,
`POST /v1/assets/{id}/threads`), `POST /v1/pixel/designer`
and `DELETE /v1/sessions/{id}`, and, with a correction link,
`GET /v1/designs/{id}/designer` and `POST /v1/designs/{id}/correction-proofs` (`Storefront\Sdk::OPERATIONS`
lists them all).

- An improvement or a cut-out is made at once, of any upload in the
  caller's session, whether or not the picture's constraints offer it; a
  cut-out only of an upload made for a picture (`layerId`). A cut-out keeps
  the upload's bytes and is done on its first attempt.
- The fake reads no picture's colours: asked for a thread copy, it reads
  any upload in the caller's session as a photo, of which no copy is made.
- A template read offers no Pixel in the designer (`pixel: false`), and
  `POST /v1/pixel/designer` answers `503 pixel_unavailable` to every request
  it can read, until `POST /__fake/templates/:id/pixel` turns it on for that
  version.
- A proof is kept for the store that asked, keyed by its token, with the
  template, blank, placement, print size (the one asked for, else the
  placement's fitted size), values, `docHash`, `expiresAt` (seven days on
  the fake's clock) and the `x-pixonaut-session` it came with.
  `findProof(state, storeId, token)` reads it back. A blank without a
  placement, or the other way round, is `400 bad_request`; a pair the blank
  does not resolve is `422 unknown_placement`; another tenant's template is
  `422 constraint_violation`, as Pixonaut answers it.
- `proofUrl` points at `GET /p/{token}` on the fake, which serves a small
  JPEG until the proof expires. An upload's `uploadUrl` is `PUT /u/{token}`,
  which takes the bytes once. Both are in the reference but not in
  `consumed.json`: the SDK, not the plugin, calls them. They are listed in
  `LINKED` in `handlers/index.ts`, and their answers are checked against the
  reference like every other.
- `DELETE /v1/sessions/{id}` removes and counts the session's proofs and
  uploads that no design holds.

## Control API

Test-only routes under `/__fake/` on the fake's base, for the test runner
only: they send no CORS headers, so no page can read them, and a `POST` must
be `Content-Type: application/json` (else `415`), so no page can send one
without a preflight that fails. A body that does not read is refused `400`
and changes nothing.

| Route | Body | Does |
|---|---|---|
| `POST /__fake/reset` | `{fixture?, siteUrl?}` | Replaces all state with a fixture set (`default`), and empties the logs, faults and clock offset. `siteUrl` is the main store's site, whose origin its publishable key accepts. |
| `GET /__fake/clock` | | `{now}` in epoch milliseconds |
| `POST /__fake/clock` | `{set?, advanceMs?}` | Sets (epoch ms or ISO 8601) or moves the fake's clock; it keeps ticking from there |
| `POST /__fake/faults` | `{operation, fault, times?}` | Arms a fault for an operation (`"GET /v1/templates/{id}"`), for the next `times` (a positive integer) requests or all: `{kind: "status", status, error?, headers?}` with `status` from 200 to 599, or `{kind: "timeout", ms?}` |
| `GET` / `DELETE /__fake/faults` | | Lists or clears armed faults |
| `POST /__fake/consent` | `{decision?, environment?, member?}` | How the studio's consent screen answers the next connect: `approve` (default) or `deny`, the side a member picks, `live` (default) or `sandbox`, when the request names none, and the member who approves, `{memberId, memberName, memberEmail}` (`FIXTURE.member.owner` by default). Reset with the fixtures. |
| `POST /__fake/proofs` | `{store?, templateId?, blankId?, placementId?, printSize?, variant?, viewId?, lifetimeMs?}` | Mints a proof token as the storefront's SDK would, for the main store, the live template `mini-tee@2`, blank `blank_tee` and placement `front` unless named. `printSize` (`{widthMm, heightMm}`) defaults to the template fitted into the placement's default box; `null` makes a tampered proof without one. `variant` and `viewId` are kept with the proof, as the design's `proofVariant` and `proofViewId`. Answers `{proofToken, proofId, expiresAt, printSize}`; a proof can be committed for an hour of the fake's clock unless `lifetimeMs` says otherwise. |
| `POST /__fake/designs` | `{store?, id?, revision?, status?, templateId?, blankId?, placementId?}` | Seeds a design for a store (the main store's when `store` is absent): `committed` at revision 1 by default, or `approved` (at its revision) or `rejected`, on a template of the store's tenant (its first live one by default) and optionally a blank and one of its placements. Answers the record: `{storeId, id, tenantId, revision, status, approvedRevision, consent, templateId, blankId, placementId, externalRef}` |
| `GET /__fake/designs/<id>` | | Any store's design, as `POST /__fake/designs` answers it; `404` once it is deleted |
| `POST /__fake/orders/fail` | `{reason?, times?}` | The next `times` orders placed (one by default) answer `201` with status `failed` and `lastError` `reason`, as when the printshop turns an order down |
| `POST /__fake/orders/outcome-unknown` | `{times?}` | The next `times` orders placed (one by default) answer `201` with status `outcome_unknown`, as when the printshop's answer to the submission was lost. Arming it replaces an armed `orders/fail`, and the other way round |
| `POST /__fake/orders/hold` | `{reason?, limit?, periodEnd?, times?}` | The next `times` orders placed (one by default) answer `201` with status `held` and `hold` `{reason, limit, periodEnd}` (`monthly_order_limit`, 25 and `2026-11-01T00:00:00.000Z` by default), as for a free workspace past its monthly order limit. Arming it replaces an armed `orders/fail` or `orders/outcome-unknown` |
| `GET /__fake/requests` | | Every request since the reset: method, path, operation, status |
| `GET /__fake/violations` | | Every contract violation since the reset |

The Playwright fixture in `tests/e2e/fixtures.ts` resets the fake before
every test (with the tests site as the main store's site), gives the test a
`fake` with its base URL, a request context on the control API and its
request log, and fails the test when the violation log is not empty. E2E
tests run one at a time, since the site and the fake are shared state.

Two more fixtures work in wp-admin:

- `admin`: a page signed in as wp-env's administrator, on a store whose
  connection is forgotten before and after the test. Its browser context
  carries a cookie that gives the tests site pretty permalinks, which
  connecting needs; the storefront keeps plain ones, as the tests site has no
  `.htaccess`.
- `connectedStore`: the `admin` page on the Pixonaut settings page's Connection tab, after
  connecting through the fake studio (`connect( page )` does the same from a
  test, after setting the consent).

To revoke the store's key, arm a `401 unauthorized` status fault on the
operation the test calls next.

The e2e support plugin seeds what a storefront test needs without wp-admin:
`POST pixonaut-e2e/v1/connection` stores a connection from a
`POST /v1/connect/token` answer (the fixture store's keys, say), and
`POST pixonaut-e2e/v1/products` takes a `pixonaut` configuration document
for a personalised product. `POST pixonaut-e2e/v1/customers` creates a
customer account and answers its `{username, password}`, for `signIn()` in
`tests/e2e/fixtures.ts`. `POST pixonaut-e2e/v1/split-products` with a short
`key` makes a poster listed once per value of a global attribute, its
values' variations at different prices and the poster with a featured image
and a gallery image (answered as `images`), beside a plain product, in a
category of their own under slugs ending in the key;
`DELETE` with the same key removes them. The `pixonaut_e2e_per_page` cookie
sets how many products a page of the shop lists for one browser context.

Link a product on the product screen (`publishLinkedProduct()` in
`tests/e2e/products.ts`) only in a test about linking it. A test about what
happens after, such as the order and proof specs, makes it with
`createLinkedProduct()` beside it, which stores what the screen would, with
the same choices: an approval mode (`proof_email`, `checkbox`), the button
layout, a back side. wp-admin's two publishes cost a test about ten seconds.

The tests site keeps every product earlier tests and runs created. A test
whose work grows with the store's linked products, such as one firing a
catalogue event that re-checks them, first calls
`DELETE pixonaut-e2e/v1/product-configurations`: it forgets every product's
and variation's Pixonaut configuration, so only the products the test links
after it count.

A third fixture, `customer`, is a guest's page in a browser context of its
own, with the same network rule and cookies as the test's context, so a test
can act as the merchant in wp-admin and as a customer side by side.

The e2e orders plugin (`tests/e2e/mu-plugins/pixonaut-e2e-orders.php`) lets
a test take an order through to the printshop:

| Route | Does |
| --- | --- |
| `POST pixonaut-e2e/v1/checkout` | Enables cash on delivery (a placed order moves to processing, as a paid one does), adds free shipping to the rest-of-world zone and creates a shortcode checkout page. Answers each checkout's address, `{classic, block}`. Running it again changes nothing. |
| `POST pixonaut-e2e/v1/jobs` | Runs the plugin's due Action Scheduler jobs (fulfilment, webhook events and design deletes) now: the tests site cannot reach itself to start the queue runner. With `?early=1`, also the jobs scheduled for later, such as a fulfilment retry or the delete of a design removed from the cart. |
| `GET pixonaut-e2e/v1/jobs` | The plugin's pending Action Scheduler jobs, due or not: `[{hook, args}]`. |
| `GET pixonaut-e2e/v1/orders/<id>` | The order's status and each Pixonaut line's state, reason, refs and side refs. |
| `POST pixonaut-e2e/v1/orders/<id>/payment-complete` | Fires `woocommerce_payment_complete` again, as a gateway that reports a payment twice. |

A browser context with the `pixonaut_e2e_checkout=classic` cookie checks out
at the shortcode page; without it, at the store's block checkout. The
project's `checkout` option sets the cookie.

`tests/e2e/specs/journey.spec.ts` runs the whole loop on both projects:
connect, link a product in wp-admin, personalise and add to cart as a guest,
pay at the project's checkout, check the checkout started the line's job, run the jobs (approve, print, one grouped
printshop order, also after a payment reported twice), move the order to
`shipped` with `POST /__fake/orders/move`, and run the jobs again: the line
is shipped and the order completed.

## Running in parallel

Every test resets the tests site and the fake, so the suite runs one test at
a time on one site. `pnpm test:e2e:parallel` (`bin/e2e-sites.mjs`) starts
extra tests sites, each a wp-env environment of its own with its own fake
Pixonaut, splits the suite across them with Playwright's `--shard`, prints
each shard's output as it finishes, and stops the sites it started. Spec
files after it narrow the run, as with `pnpm test:e2e`. `ci:local` runs its
e2e step this way.

| Variable | Default | Meaning |
| --- | --- | --- |
| `PIXONAUT_E2E_SITES` | 4 | How many sites, and so shards |
| `E2E_SITES_PORT_BASE` | 8900 | Site *n* listens on this plus 2(*n* − 1), its fake on the next port |
| `E2E_SITES_COUNT` | | How many ports from the base are free; more sites than half of it is an error |

A site is the tests site of `.wp-env.json` (its plugins, themes, constants
and mu-plugins) as wp-env's only environment, from a config written under
`.wp-env-e2e/`, with its own wp-env home there; the checkout's own wp-env is
left alone. The first start downloads WordPress and the plugins for each
site; later starts take seconds. `node bin/e2e-sites.mjs start` and
`stop` start and stop the sites alone. `test` fails before starting any site
when its spec files and options select no test, and leaves running only the
sites that were running before it: a failed or interrupted run stops the
others, after its wp-env and Playwright processes have exited. A site's fake reaches it as `http://wordpress`
(`PIXONAUT_E2E_SITE_ON_NETWORK`, which `TESTS_SITE_ON_NETWORK` in
`tests/e2e/fixtures.ts` follows). Each shard writes its traces to
`test-results/e2e-<n>/` and its report to `playwright-report/e2e-<n>/`.

## Projects

| Project | Theme | Product page blocks | Checkout | Runs in |
| --- | --- | --- | --- | --- |
| `classic` | Storefront | Classic templates | Shortcode | `pnpm test:e2e` |
| `block-primary` | Twenty Twenty-Five | Add to Cart Form, Product Image Gallery | Checkout block | `pnpm test:e2e` |
| `block-secondary` | Twenty Twenty-Five | Add to Cart with Options, Product Gallery | Checkout block | `pnpm test:e2e:secondary` |
| `astra` | Astra | Classic templates | Shortcode | `pnpm test:e2e` |

`block-secondary` swaps the blocks in the single product template for a
browser context with the `pixonaut_e2e_blocks=secondary` cookie, and runs
only the product page specs.

`astra` runs only `theme-layout.spec.ts`: the designer's layout at 1280x800
and 390x844 under a classic theme other than Storefront. Flatsome and Divi
are sold outside wordpress.org, so wp-env cannot install them.

### Themes and page builders

`pnpm test:e2e:compat` runs `compatibility.spec.ts` once per combination in
`tests/compat/combinations.json`, as projects named `compat-<id>`: Kadence,
OceanWP, GeneratePress, Blocksy, Neve, Hello Elementor and Astra alone, then
Hello Elementor and Astra and Storefront with Elementor, Astra with Beaver
Builder, and GeneratePress with SiteOrigin. It runs in the Nightly's own job,
not in `ci:local`. Each checks the form and its layout at 1280x800 and 390x844,
no script errors and no PHP warnings or notices, add to cart, the design on the
cart line and the checkout; a combination with a builder also sells the design
from a builder page holding `[product_page]`.

The projects exist, and the themes and builders are installed, only with
`WP_ENV_COMPAT` set, on both commands:

```sh
WP_ENV_COMPAT=1 pnpm env:start
pnpm test:e2e:compat
```

wp-env activates every builder once, which runs its activation routine.
`tests/compat/switch.php` then picks the theme and the one builder for a browser
context by its `pixonaut_compat` cookie, as the other projects pick their theme.
Elementor Pro, Divi, Flatsome, WPBakery and Bricks are sold outside
wordpress.org, so they cannot be installed; building a single product template
in Elementor needs Pro.

## The studio's consent screen

`GET /connect` on the fake stands in for the studio's consent screen. It
applies the parameter rules of Pixonaut's "Connecting a store" guide
(`client=woocommerce`; `site_url` an http(s) address without query or
fragment; `redirect_uri` and `rest_url` on its origin; `state`; an S256
`code_challenge`; `code_challenge_method` `S256` or absent; `environment`
`live`, `sandbox` or absent). A request that breaks one is logged as a
violation and answered `400`. Otherwise it answers at once, as its consent is
set: a redirect to `redirect_uri` with `code` and `state`, or with
`error=access_denied` and `state`.

`POST /v1/connect/token` takes a code once, within a minute of the fake's
clock, with the verifier and `redirect_uri` it was approved with, and hands
out keys for the chosen side (`ik_live_…`, or `ik_test_…` on the sandbox).
Connecting a site that is already connected revokes its old keys; the store
keeps its id, so what it made stays its own. `DELETE /v1/connect` revokes
the caller's keys, its webhook endpoint and the share links they handed out:
an emit to a disconnected store is refused, and a pending delivery whose
endpoint was revoked or replaced is retired instead of sent.

The store's token answer carries `memberKey`, the approving member's key
(`mk_live_…` or `mk_test_…`), as the pinned spec requires; the plugin does not
read it.

## Proof pages

`POST /v1/share-links` mints proof-page links only, by Pixonaut's rules: the
subject is one of the caller's designs (`404 subject_not_found`), `returnUrl`
is on one of the store's publishable-key origins (`400
return_url_off_store`; http is accepted, as a non-production Pixonaut does),
`state` comes only with `returnUrl`, and `expiresInDays` is at most 90
(`400 expiry_too_long`), 30 when absent. `state` stays with the link and is
not echoed; `locale` is answered (null when the mint named none), on
designer links and their list rows too. A `locale` outside the spec's enum
is a contract violation, as every off-spec body is; the fake's
`invalid_input` refusals carry a `reason`, as Pixonaut's do. The answer's
`url` is `/h/proof/<token>`, with `?lang=<locale>` when the mint named one,
on the
host the request reached the fake by; a test that opens it in the browser
swaps in the fake's browser-facing base.

`GET /h/proof/<token>` stands in for Pixonaut's hosted proof page, its `lang`
its `?lang=`, else the link's `locale`, else the workspace's `defaultLanguage`
(the hosted designer's the same): the proof
and, on a deciding link, **Approve** and **Reject** buttons. Approving
approves the design at the revision shown, with consent `proof_page`, and
delivers a signed `design.approved` with its `revision` to the store's
endpoint; rejecting delivers `design.rejected`. Deciding again the same way
changes nothing and sends nothing. `POST /__fake/proof-pages/<token>/approve`
and `/reject` (`{revision?}`) do the same without a browser and answer
`{designId, status, revision, emitted, attempts}`.

A deciding link on a committed design also shows a **Request changes** form,
its textarea labelled "What should change?" (`message`, 1 to 1000
characters, else `400 bad_request`). Taking it leaves the design committed
and delivers `design.changes_requested` with `{designId, revision, message}`;
it is refused as a decision is (`403 forbidden`, `409 not_committed`,
`409 revision_changed`). With a `returnUrl`, the page then answers `303` to
it with the link's `state`, as Pixonaut's does: the store's query is kept, a
`proofToken` in it dropped, and a `state` in it replaced, or dropped when the
link carries none. Without one, it says the request was sent.
`POST /__fake/proof-pages/<token>/request-changes` (`{message, revision?}`)
does the same without a browser and answers
`{designId, revision, emitted, attempts, redirect}`, `redirect` null without
a `returnUrl`.

`POST /v1/designs/{id}/reject` rejects a committed design; rejecting it again
answers the same, and an approved design is refused `409 not_committed`.

`POST /v1/designs/{id}/erase-personal-data` sets the design's
`personalDataErasedAt` and emits `design.personal_data_erased`; erasing it
again answers it unchanged and emits nothing. A design the store deleted
answers `204`. An erased design is refused `409 design_erased` by
`POST /v1/orders` and `POST /v1/orders/{id}/retry`.
`POST /v1/orders/{id}/erase-personal-data` is refused `409 order_in_flight`
until the order is `shipped`, `failed` or `cancelled`; then it clears the
order's `recipient`, `shippingAddress` and failure reasons and sets
`personalDataErasedAt`.

## Designs

`POST /v1/designs` commits a proof token the caller's store minted: an
unknown token answers `404 unknown_proof`, an expired one `410
proof_expired`, and one committed before (under another key)
`409 proof_already_committed` with its `designId`. A commit's
`fulfilmentPayload` is kept and read back as the design's
`fulfilment_payload`; the fake refuses no missing fulfilment keys, as
Pixonaut checks only those a commit can carry. `GET /v1/designs/{id}`
reports the template version, blank, placement and print size the proof
named, not what the store expected, so a store's checks see what the browser
sent. `PUT /v1/designs/{id}/external-ref` replaces the store's reference and
`DELETE /v1/designs/{id}` deletes a design that is not approved. Another
store's design answers `404`.

## Orders

`POST /v1/orders` with `designs` makes a print of each design, finished at
once, so the order goes on as an order of `prints` would; naming both or
neither is `400`. A design with a live order (any status but `failed` and
`cancelled`) refuses another order `409 design_already_ordered`, naming the
design and its newest live order, unless the body sends `reorder: true`. A
test seeds a print that is still rendering straight into the fake's state
(`seedPrint` in `tests/js/fake-pixonaut/support.ts`); an order of it is
placed `awaiting_prints` and stays there: it is never handed to a
printshop, `POST /v1/orders/{id}/cancel` cancels it at once, and
`PATCH /v1/orders/{id}/delivery` gives it the new address as `carried`
without asking its printshop. Every order
the fake places answers `failureKind: null`.

## Webhook deliveries

The fake delivers webhooks the way Pixonaut does: a `POST` to the store's
endpoint with the envelope `{id, type, payload, createdAt}` as the body,
`x-pixonaut-timestamp` (Unix seconds on the fake's clock) and
`x-pixonaut-signature: v1=<hex HMAC-SHA256>` over `<timestamp>.<raw body>`,
keyed with the endpoint's secret. The signer reproduces
`contracts/pixonaut/webhook-signature-vectors.json` byte for byte, and every
envelope is validated against the reference's `webhooks` schema before it is
sent: an emit with a payload the reference does not allow is refused `400` and
sends nothing.

Each fixture store has one endpoint, registered under its site's REST address
(`<siteUrl>/wp-json/pixonaut/v1/webhook`) with the id and secret in
`fixture-ids.ts`. Nothing is sent on a timer: a test emits events and retries
them through the control API, and the fake answers with what each attempt
got back.

The store's own key reaches its endpoint as Pixonaut's does:
`GET /v1/webhooks` lists it with `failing` (any delivery failed for good) and
`failedCount`, `PATCH /v1/webhooks/{id}` switches it off or narrows its
`eventTypes` (`400 invalid_event_type` for a type the reference does not send,
`webhook.ping` included) or changes its `url` (`400 invalid_webhook_url` for one
off a live store's site origin; the fake calls no address, so one it takes reads
as answering, and it keeps what was waiting), and an emit of a type it is not
narrowed to sends nothing. `POST /v1/webhooks/{id}/ping` sends one signed `webhook.ping`,
whatever the endpoint is narrowed to, and answers `{delivered}` or
`{delivered: false, error}`; a ping is never retried and is no delivery.
`PATCH /v1/connect` takes up to ten origins on the site's own domain (the
fake reads a host's last two labels as its domain, where Pixonaut reads the
Public Suffix List), with the site's scheme and port, refusing any other
`400 invalid_origin` and one past the limit `400 too_many_origins`. It also
takes `brandId`, one of the workspace's brands or `null` for its default,
refusing any other `404 brand_not_found`. `GET /v1/connect` answers the
workspace settings of the store's tenant, which `POST /__fake/workspace`
changes. Every workspace has a default brand named after it, with the id
`br_<tenant id>` and the workspace's `accentColor` and `logoUrl`; `brands`
adds others.

| Route | Body | Does |
|---|---|---|
| `POST /__fake/workspace` | `{store?, accentColor?, logoUrl?, brands?, brandId?, proofWatermark?, deliverOrdersTo?, defaultLanguage?}` | Changes the workspace settings of the store's tenant (the main store's when `store` is absent) as a member would in the studio: `accentColor` and `logoUrl` of the default brand, a string or `null`; `brands` the brands besides it, each `{id, name, accentColor?, logoUrl?}`; `brandId` the brand chosen for the store, one of them or `null` for the default; `deliverOrdersTo` `{id, name}` or `null`, `defaultLanguage` one of the nine languages (`en` by default). Answers the settings |
| `POST /__fake/webhooks/endpoint` | `{store?, url?, secret?, reachable?}` | Points a store's endpoint (the main store's when `store` is absent) at another URL or secret. `reachable: false` is a store whose address did not answer: `GET /v1/connect` and `GET /v1/webhooks` say so, redelivering is refused `409 endpoint_unreachable`, and an event emitted to it is recorded in its event stream with no delivery queued. Connecting again makes it reachable |
| `POST /__fake/webhooks/emit` | `{store?, events, order?, seed?}` | Delivers `events` to the store's endpoint, one after another, and answers `{attempts}` |
| `POST /__fake/webhooks/retry` | `{}` | Sends every pending delivery again, with the same `id` and a fresh timestamp |
| `POST /__fake/webhooks/drop` | `{store?, drop}` | While `drop` is `true`, an event emitted to the store is recorded in its event stream and its delivery `failed` at once, counted in `failedCount`, with nothing sent; pending and requeued deliveries are still sent on retry. Answers `{storeId, dropping}`; a reset stops dropping |
| `GET /__fake/webhooks/deliveries` | | `{endpoints, deliveries, attempts}`: each endpoint with its `failedCount` and whether its store's deliveries are `dropping`, each delivery with its status, every request sent with its headers, body and the status it got |
| `POST /__fake/orders/move` | `{orderId, printId?, externalRef?, from?, to?, tracking?, store?, order?, seed?}` | Moves an order (`printId` defaults to the lead print of an order the fake placed) along `submitted`, `queued`, `printed`, `shipped` (from `submitted` to `shipped` by default) and emits one `order.status_changed` per step, as `emit` does; the step to `shipped` carries `tracking` (`{carrier?, number, url?}`, a fixture tracking number when absent) |
| `POST /__fake/orders/held-event` | `{orderId, store?}` | Emits `order.held` for an order the fake placed held, as `emit` does |
| `POST /__fake/orders/release` | `{orderId, releasedBy?, status?, store?, order?, seed?}` | Releases an order the fake placed held: moves it to `status` (`awaiting_prints` by default, the status Pixonaut releases to), clears its `hold`, records `order.released` in its history and emits `order.released` with `releasedBy` (`upgrade` by default, or `month_reset`) and no `order.status_changed` |
| `POST /__fake/designs/edit` | `{design, store?}` | Corrects the store's design as the studio would: its `revision` goes up by one and any approval is cleared (`status` back to `committed`). Emits `design.edited` `{designId, revision}` and answers `{attempts}`. A rejected or erased design is refused |
| `POST /__fake/designs/delete` | `{design, store?}` | Deletes the design (`GET /v1/designs/{id}` answers `404`), emits `design.deleted` `{designId}` and answers `{attempts}` |
| `POST /__fake/designs/erase` | `{design, store?}` | Sets the design's `personalDataErasedAt` (no longer editable), emits `design.personal_data_erased` `{designId}` and answers `{attempts}` |
| `POST /__fake/templates/publish` | `{template, store?}` | Publishes the template's next version, a copy of its latest (`template` is a base id, e.g. `mini-tee`). Emits `template.published` `{templateId: "<baseId>@<version>", version}` and answers `{attempts}` |
| `POST /__fake/templates/<id>/pixel` | `{layerId, prop, value}` or `{addsLayer: "text"}` | The template version (`mini-tee@2`) offers Pixel in the designer, and each `POST /v1/pixel/designer` answers with `value` written into that field, or with a customer's text layer added (the version then allows them) and nothing else changed. Slow or failing answers are faults on the operation |
| `POST /__fake/templates/pause`, `/resume` | `{template, store?}` | Hides or shows every version of the template in the live list. Emits `template.live_changed` `{baseId, live}`; a template already paused (or live) is refused, as Pixonaut emits only a flip |
| `POST /__fake/templates/delete` | `{template, store?}` | Deletes every version (reads answer `404`), emits `template.deleted` `{baseId, wasLive}` |
| `POST /__fake/templates/rename` | `{template, newId, store?}` | Changes the template's base id in every version, its designs' and designer links' with it; the old id still reads it under the new one. Emits `template.renamed` `{oldId, newId, renamedAt}`, which the event stream leaves out, as Pixonaut's does; an id another template has is refused |
| `POST /__fake/share-links/revoke` | `{link, store?}` | Withdraws the store's designer link `link` (an id) as a member would in the studio: it stops opening and is no longer listed. Emits `share_link.revoked` `{linkId, kind, subjectId, name, by: "member"}` and answers `{attempts}`. The store's own `DELETE /v1/share-links/{id}` emits it too, with `by: "key"` |
| `POST /__fake/share-links/regenerate` | `{link, store?}` | Gives the link a new address under the same id; the old one stops opening. Emits `share_link.regenerated` with the same payload |
| `POST /__fake/blanks/update` | `{blank, removePlacement?, variant?, retired?, store?}` | Updates the blank, removing `removePlacement` when named, and retiring the variant `variant` (a ref) for `retired` (`retired` or `no_longer_sold`), or selling it again when `retired` is `null`. Emits `blank.updated` `{blankId, fields}`, `fields` naming `placements` and `variants` as changed |
| `POST /__fake/blanks/delete` | `{blank, store?}` | Deletes the blank (read by id with `deleted` set, left out of `GET /v1/blanks`), emits `blank.deleted` `{blankId, blankName}`; refused for a blank already deleted |
| `POST /__fake/blanks/restore` | `{blank, store?}` | Restores a deleted blank, emits `blank.restored` `{blankId, blankName}`; refused for a blank not deleted |

Pixonaut sends template and blank events to every store of the workspace;
the fake's catalogue routes send them to the store named (the main one by
default), so a test reads one stream.

An event is `{type, payload, id?, createdAt?, tamper?}`: `type` any consumed
event, `id` and `createdAt` generated when absent. `{replay: <id>, tamper?}`
sends an event already emitted to that store again, unchanged, as a duplicate
outside its delivery's attempts. `tamper` sends a delivery a receiver must
refuse: `body` changes the body after signing, `timestamp` sends another
timestamp than the one signed, `secret` signs with another secret. A stale
delivery is one sent after moving the clock back with `POST /__fake/clock`.

`order: "shuffled"` delivers the events in a random order, every one once;
`seed` (an integer) repeats the same order. A delivery the endpoint answers
with a `2xx` is `delivered`; any other answer, or none within 10 seconds,
leaves it `pending` for the next retry, and after 3 attempts it is `failed`
and counted in its endpoint's `failedCount`. The status decides it, even when
the body after it breaks off. A retry skips a delivery another emit or retry is
still sending, so overlapping retries never send one twice. A reset aborts the
requests in flight and stops every emit and retry under way: nothing more is
sent or recorded.

Every event emitted to a store, through `emit`, `orders/move` or a design
change, is recorded in the store's event stream in the order it was emitted,
whether or not its delivery got through (a replay is not a new event). That
is what `GET /v1/events` pulls: after `after` (`400 unknown_event` for an id
not in the store's stream), narrowed to `types` (`400 invalid_event_type` for
one that is no event type, `webhook.ping` included), at most `limit` (100 by
default). As Pixonaut does, a pull looks through at most 1000 events, so with
`types` a page can be short or empty while `nextCursor` moves on; it is the id
of the last event looked through while more follow it, and null once nothing
more is recorded. `GET /v1/webhooks` lists the store's endpoint with its
`failedCount` (`failing` when above 0), and
`POST /v1/webhooks/{id}/deliveries/redeliver-failed` puts every delivery that
failed for good back to pending with its attempts reset (`202 {requeued}`),
for the next `POST /__fake/webhooks/retry` to send; another store's endpoint
answers `404 not_found`, an inactive one `409 endpoint_inactive`, an unreachable
one `409 endpoint_unreachable`. A design
change of a disconnected store, or of a design that is not the store's, is
refused `400` and changes nothing. A reset empties every stream.

From the tests site, WordPress is reached by its service name on wp-env's
network: an endpoint at `http://tests-wordpress/?rest_route=…` receives what
the fake emits. The e2e support plugin's `pixonaut-e2e/v1/webhook-sink` route
keeps each request's raw body and signature headers for a test to read back.

## Fixtures

The `default` set (`tests/fake-pixonaut/src/fixtures.ts`; its ids and keys in
`fixture-ids.ts`):

- A live tenant with two stores (`conn_main`, `conn_other`) and a sandbox
  tenant with one (`conn_sandbox`), each store with an integration key, a
  publishable key and a webhook endpoint with its signing secret.
- Templates `mini-tee@1` and `mini-tee@2` (live), `mini-tee-paused@1`
  (paused) and, on the sandbox tenant, `sandbox-tee@1`. Each is the same
  test template: an upload and a text layer with `*Frac`
  geometry, CMYK colours and a choice of two fonts, with the form
  Pixonaut derives from it, and only fields the pinned SDK accepts
  (`tests/js/fake-pixonaut/sdk-fixture.test.ts` checks them against the
  SDK's types). It renders in the real SDK.
- The fonts it names, served at `/f/<sha256>.ttf` (Poppins and Anton, OFL;
  licences beside them).
- Blanks `blank_tee` (front and back, made by printshop `ps_main`) and
  `blank_hoodie` (front and back, made by `ps_other`), and `blank_tote`,
  with no placements yet. Every blank is `ready`: the fake imports
  nothing. Each placement is shown in views of the blank's
  mockup photos; the tee's front has a second, dark view. A print on a blank
  goes to the blank's printshop; one on no blank to the printshop the order
  names, else the workspace default. An order sent to a disconnected
  printshop nobody named is placed as `failed`; a named one, or a retry's,
  is refused with `printshop_disconnected`.
- Variants (`GET /v1/blanks/{id}/variants`, refs in `FIXTURE.variant`): the
  tee and the hoodie have `Color` and `Size` axes. The tee sells White and
  Black in S and M, the front view's photo showing White, the dark front
  view's Black and the back's both; Red S is retired `no_longer_sold`, Red M
  `retired`, and Heather S is sold but no photo shows it. The hoodie sells
  White S and Black S. The tote (`blank_tote`) has no axes and one variant.
  The tee has Swedish names (`?lang=sv` on `GET /v1/blanks/{id}/mockups`
  and `GET /v1/blanks/{id}/variants`): `Färg` and `Storlek`, `Vit`, `Svart`
  and `Röd`, and the views `Framsida` and `Baksida`; everything else, every
  other language, and `GET /v1/blanks/{id}` itself read as written.
  An order on a blank names its variant (`variant`): one of another blank is
  refused `variant_not_of_blank`, a retired one `variant_retired`, and none
  `variant_required` unless the blank has only one, which is ordered.
- Printshops `ps_main` ("Fixture Print AB", the workspace default) and
  `ps_other` ("Other Print AB").
- A share link `link_fixture_template` reaching `mini-tee@2`.

The fake serves every operation in `consumed.json`
(`tests/fake-pixonaut/src/handlers/index.ts`), and delivers every consumed
webhook event through `POST /__fake/webhooks/emit` but `webhook.ping`, which
only `POST /v1/webhooks/{id}/ping` sends.

## Action Scheduler jobs in PHP tests

Jobs never run on their own in a test: there is no WP-Cron or async request.
`Tests\Support\ActionSchedulerRunner::run_pending( $hook )` runs a hook's
pending jobs at once through Action Scheduler's own runner, so each is
claimed, run, and marked complete or failed as in production; `pending()`
and `failed()` list a hook's jobs by status. An integration test that drives
fulfilment replaces the plugin's `pixonaut_fulfil_item` callback with a
`FulfilmentJobs` whose client points at an `HttpRecorder`, and keeps the
plugin's own scheduling hooks.
