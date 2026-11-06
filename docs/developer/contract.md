---
title: Pixonaut contract
description: How the plugin pins Pixonaut's API spec and SDK, which operations it consumes, and how to check and move the pin.
sidebar:
  order: 2
---

The plugin is built against one Pixonaut API release and one SDK release. Both are
pinned under `contracts/pixonaut/`, and every layer of the test suite checks
against the pinned files rather than a running Pixonaut.

## What is pinned

| File | What it holds |
|---|---|
| `openapi.json` | The API release's reference, byte for byte (OpenAPI 3.1) |
| `webhook-signature-vectors.json` | The same release's webhook signature vectors |
| `spec.lock.json` | The API version, its address on the CDN and the sha256 of both files |
| `sdk.lock.json` | The SDK version, its CDN base URL, the SRI of `pixonaut.iife.js`, and the SRI of each vendored type file |
| `sdk/` | The SDK's type declarations (`pixonaut.d.ts`, `schema.d.ts`) and their MIT licence (`LICENSE-MIT`), for type checks only |
| `consumed.json` | The operations and webhooks the plugin uses, generated (below) |
| `oasdiff-levels.txt` | The oasdiff rules downgraded from `ERR` to `WARN` |

The pin is a release version plus a sha256. A unit test
recomputes every checksum, so a hand edit to a pinned file fails
`pnpm test:js`. Change them only with `pnpm contracts:pin`.
The bundled pixonaut-php's `ApiVersion::TARGET` and the `Storefront\Sdk`
constants mirror the two locks for the server and the storefront; unit tests
hold them to the pin, and `contracts:pin` says when they must change: a new
API release needs the pixonaut-php release built for it.

## The consumed list

`consumed.json` is in the format of Pixonaut's own consumer-contract check,
so the same file can be sent to Pixonaut:

```json
{
	"operations": ["GET /v1/orders/{id}", "POST /v1/orders"],
	"webhooks": ["order.status_changed", "webhook.ping"],
	"keys": {
		"GET /v1/orders/{id}": "integrationKey",
		"GET /v1/templates/{id}/constraints": ["integrationKey", "publishableKey", "shareLink"]
	},
	"pending": [{ "op": "POST /v1/things", "requirement": "the issue that will call it" }]
}
```

- `operations` are written `METHOD /path`, the path as the spec declares it.
  They include what the SDK calls from the customer's browser, not only what
  the plugin's PHP calls.
- `webhooks` are event types, plus `webhook.ping`, which the plugin receives
  but which is not an event type.
- `keys` names the key kind an operation is called with, as the spec's
  `components.securitySchemes` names it: `integrationKey` (the plugin's
  server), `publishableKey` (the SDK) or `shareLink` (the SDK on a correction
  link). An operation called with several kinds lists them; one called with
  no key has no entry.
- `pending` lists what the plugin plans to use and does not call yet,
  whether the pinned spec declares it or not, each with the issue that will
  call it; a webhook is written `WEBHOOK <type>`. Pixonaut's check ignores
  it, but its owners can see what is coming.

`operations`, `webhooks` and `keys` equal what the code calls and handles.
`pnpm contracts:consumed` writes them from:

- `Api\Pixonaut::OPERATIONS`: every operation the plugin's server calls
  through pixonaut-php, by the SDK's operation id, with the key the SDK
  sends for it;
- `Storefront\Sdk::OPERATIONS`: what the pinned SDK calls from the
  customer's browser as the storefront mounts it, with the key kinds it
  sends;
- `Webhooks\Endpoint::EVENT_TYPES` and `webhook.ping`.

It keeps `pending`, the one part kept by hand, but drops the entries the code
now uses. The registries are PHP, so it runs in wp-env's `tests-cli`
(`bin/contracts/consumed-data.php`). `ci:local` runs it with `--check` and
fails when the file is out of date: a merge that brings in a new call
resolves `consumed.json` by regenerating it.

Unit tests fail when a listed operation or webhook is missing from the pinned
spec (unless it is pending), when an operation does not accept a listed key
kind, when a pending entry is also in use (unless the spec overlay stands in
for it, below), and when the fake does not serve exactly the listed
operations and webhooks.

## The spec overlay

A change can be built against what Pixonaut agreed to add before it is
pinned. `tests/fake-pixonaut/spec-overlay.json` holds it:

```json
{
	"entries": [
		{ "pointer": "/paths/~1v1~1things", "awaits": "pixonaut-app/pixonaut#12", "value": { "post": {} } }
	]
}
```

Each entry adds `value` at the JSON pointer `pointer` only where the pinned
spec has the parent and lacks the key, and names the Pixonaut issue it
`awaits`. The fake (`tests/fake-pixonaut/src/spec.ts`), its Ajv validator,
the scenario suite and the PHP suites' `OpenApiAssert` all read the spec
through it (`bin/contracts/overlay.mjs`); the pinned files are never edited.
An operation the overlay adds stays `pending` in `consumed.json` while the
code calls it, so `contracts:pin` reports it when it arrives.

Entries are independent of each other and sorted by pointer. Once
`contracts:pin` brings what an entry awaits, the entry no longer applies and
`tests/js/contracts/overlay.test.ts` fails until it is removed, together with
its `pending` entry. With nothing awaited, the file holds `{ "entries": [] }`.

## Scenario suite

`contracts/scenarios/` holds the exchanges a store has with Pixonaut, in
order: connect (`POST /v1/connect/token`), narrow and ping the webhook
endpoint, read the catalogue, personalise on the product page with the
publishable key (the blank's views, photos and variants, template, fonts,
constraints, print size, an upload, proofs, an abandoned session), commit, approve and order a design (and be refused a second order of it),
sell a fixed product from a standing design (found again, and ordered twice),
correct one through a correction link (designer, correction proof,
`PATCH`), delete one, read the event stream, hand over and take back the store access credential, disconnect. Ids come from the answers,
never from fixtures, so the same scenarios run against two targets:

- **fake**: the fake Pixonaut in process, in `pnpm test:js`
  (`tests/js/contracts/scenarios.test.ts`), and so in `ci:local`;
- **live**: a Pixonaut on your machine, in `pnpm contract:check`, when the
  environment names one.

Every request a scenario sends and every answer it gets is validated against
the pinned spec (the fake's Ajv validator); values are never compared. A
URL Pixonaut hands out, such as an asset's `uploadUrl` (`PUT /u/{token}`),
is not in `consumed.json`: it is matched against the spec and its answer is
validated, its request is not (the spec describes that body in prose). A
scenario fails on a violation or on a status it cannot go on from, and the
run fails when a consumed operation was sent by no scenario. Add a step to a
scenario in the change that starts calling an operation.

## Checking for drift

```sh
pnpm contract:check [--api <version>] [--no-live] [--cdn <origin>]
```

It reads Pixonaut's public CDN and needs no token and no access to Pixonaut's
source, apart from downloading the pinned oasdiff binary once (see below).

- **API release.** The pinned version must be published at
  `https://cdn.pixonaut.app/api/<version>/`, each file must match that
  release's `integrity.json`, and the pinned files must have the checksums in
  `spec.lock.json`.
- **SDK.** The pinned version's `integrity.json`, at
  `https://cdn.pixonaut.app/sdk/<version>/`, must carry the SRI in
  `sdk.lock.json`.
- **A newer API release.** With `--api <version>`, the pinned spec and that
  release's are cut down to what `consumed.json` lists, and oasdiff compares
  the two. Each consumed webhook is rewritten as `GET /__webhooks/<type>`
  answering its payload, so oasdiff judges it from the receiver's side: a
  removed or newly optional payload field is an error. The changelog is
  printed, and the check fails on an `ERR`, on a consumed operation or webhook
  that release no longer declares, and on a key kind it no longer accepts.
- **Live smoke.** The scenario suite, schema only, against a Pixonaut on your
  machine. Set `PIXONAUT_LIVE_API_URL`, `PIXONAUT_LIVE_TENANT`,
  `PIXONAUT_LIVE_EMAIL` and `PIXONAUT_LIVE_PASSWORD` to a test account on it;
  without them the smoke skips and says so, and any other host than this
  machine is refused. The check fails when a scenario cannot finish or an
  answer breaks the pinned spec. Never run it against production.

`oasdiff-levels.txt` downgrades new `oneOf`/`anyOf` arms and new enum values
in responses to `WARN`: these are new refusal codes and statuses, which the
plugin treats as open sets.

oasdiff is a Go binary with no npm package. The first run downloads the
pinned release from GitHub, checks its sha256 against the pin in
`bin/contracts/oasdiff.mjs`, and caches it under
`~/.cache/pixonaut-for-woocommerce/oasdiff/`.

## Moving the pin

```sh
pnpm contracts:pin [<api-version>] [--sdk <version>] [--cdn <origin>]
```

It reads the release from `https://cdn.pixonaut.app/api/<version>/` and the SDK
from `https://cdn.pixonaut.app/sdk/<version>/`, and holds every file to its
release's `integrity.json`; otherwise the pin stops. Each version defaults to
the one already pinned. An API release's changelog is `CHANGELOG.md` beside its
files, and the SDK's are on
[Pixonaut's SDK releases page](https://docs.pixonaut.app/sdk-releases/).

Nothing is written until everything is read, checked and compared, so a
failure leaves the previous pin in place. It then rewrites the files above,
regenerates the fake Pixonaut's types, and prints what the new pin changes: oasdiff's `ERR`/`WARN` changelog for the
consumed surface, consumed names the new spec lacks, pending entries it now
declares, and the SDK version and script SRI that changed. Pinning the release
already pinned changes nothing.

Re-pin in one pull request that also fixes what the report names in the
plugin's code and in the fake.

To move the SDK alone: `pnpm contracts:pin --sdk <version>`.
