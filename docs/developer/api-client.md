---
title: API client
description: How the plugin talks to Pixonaut through the pixonaut-php SDK — the factory, operations, errors, idempotency, request snapshots, hooks and contract checks in tests.
sidebar:
  order: 3
---

The plugin talks to Pixonaut through [pixonaut-php](https://github.com/pixonaut-app/pixonaut-php),
Pixonaut's PHP SDK, bundled in `vendor/` and prefixed with Strauss: it loads
as `Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\…`, with its PSR interfaces and
`nyholm/psr7` under the same prefix, so another plugin's copy of any of them
never clashes with it.

No plugin code builds a Pixonaut URL or sends a request itself. The SDK sends
over the WordPress HTTP API through `Api\WpHttpTransport`, a PSR-18 client and
the plugin's only `wp_remote_*` caller (a unit test fails if any other file in
`src/` makes an HTTP request). It keeps WordPress's URL checks, blocked hosts,
proxy and `http_api_debug`, never follows a redirect, and gives up after the
timeout it was built with.

## The factory

`Api\Pixonaut` builds the SDK, and is the only place that does:

```php
$pixonaut = $plugin->pixonaut();              // Api\Pixonaut
$sdk      = $pixonaut->sdk();                 // the SDK, 15 second timeout
$pixonaut->sdk( Pixonaut::SHORT_TIMEOUT_SECONDS )->connect()->cutAccess(); // 5 seconds
```

It keeps one SDK client per timeout, each with:

| Part | Plugin class |
|---|---|
| Base URL | `Api\Hosts::server_api()` |
| PSR-18 client | `Api\WpHttpTransport` |
| PSR-17 factories | `nyholm/psr7` |
| Credentials | `Connection\Credentials`, the SDK's `CredentialProvider` over `Connection\ConnectionStore` |
| `Origin` sent with the publishable key | the site's origin (`Connection::origin( Connection::site_url() )`) |
| `User-Agent` product | `pixonaut-for-woocommerce/<version>` |
| Logger | `Api\WcLogger`, PSR-3, under WooCommerce > Status > Logs, source `pixonaut-api` |
| Listener | `Api\HookListener`: the plugin's hooks, and deprecations for the admin notice |

`Pixonaut::all()` sends several reads at once, at most
`Pixonaut::MAX_CONCURRENT` at a time, and returns each one's `Result` or
`ApiException` in order; the product pictures' job draws a product's image
and gallery pictures with it (`Api\Pictures::all()`). Each request is built
with the SDK's `prepare()` and `toPsrRequest()`, and each answer read with its
`handle()`. WordPress's HTTP API sends one request at a time, so the transport
sends these through the Requests library it is built on, firing
`http_request_args`, `pre_http_request`, `http_request_reject_unsafe_urls`,
`https_ssl_verify`, `http_api_curl`, the `requests-*` actions,
`http_api_debug` and `http_response` for each as `wp_remote_request()` would,
through WordPress's proxy settings: a filter that answers or blocks requests
sees these too. An operation that takes an `Idempotency-Key` is never sent
this way.

## Operations

`Api\Pixonaut::OPERATIONS` lists, by the SDK's operation id
(`designs.commit`, `orders.get`, …), every operation the plugin calls. The
SDK refuses any other before sending it, and
`contracts/pixonaut/consumed.json` is written from the list
(`pnpm contracts:consumed`, see [Pixonaut contract](../contract/)). Calling
another operation means, in one pull request: its id in the list, the code
that calls it, the fake's handler, and `consumed.json` regenerated (which
moves the operation out of `pending` if it was planned there).

Callers use the SDK's resources, typed request models and answers:

```php
$sdk->connect()->get();                                                     // GET /v1/connect
$sdk->designs()->get( $design_id )->status;                                 // GET /v1/designs/{id}
$sdk->designs()->approve( $design_id, Api\Designs::approval( $consent, $revision ) );
$sdk->orders()->list( externalRef: $ref )->orders;                          // GET /v1/orders?externalRef=
```

An answer the plugin keeps or caches as it came (a connection's workspace
settings, a design) is read with the model's `raw()`, which holds every field
Pixonaut sent.

Templates, blanks, their views, variants and constraints, printshop
connections and orders are read untyped, with `call()` and the result's
`object()`, since the plugin keeps them as arrays. The SDK's models check
every field their release requires, so a typed read fails when Pixonaut stops
sending a field the plugin never uses, or has not sent one yet; an untyped
read fails only on what the plugin reads itself:

```php
$sdk->call( 'blanks.get', array( 'id' => $blank_id ) )->object();          // GET /v1/blanks/{id}
```

A few classes add what the store needs on top of the SDK:

| Class | Adds |
|---|---|
| `Api\Catalogue` | template and blank search pages, every live template's latest version, the answers handed to the storefront as Pixonaut sent them |
| `Api\Designs` | a commit that adopts `proof_already_committed`, the standing design for a side, a delete where a `404` counts as deleted, the erasure's time, proof and mockup images checked to be what they claim |
| `Api\DesignerLinks` | designer links with their warnings logged, the working ones found across pages, a withdrawal where a gone link counts as withdrawn |
| `Api\Pictures` | template pictures checked to be PNGs, drawn several at once |

Refs and keys are built by `Refs`, and always start `wc:<site>:`:

| Built by | Shape | Used as |
|---|---|---|
| `Refs::commit_key()` | `wc:<site>:commit:<sha256 of the proof token>` | `Idempotency-Key` of a design commit |
| `Refs::cart_ref()` | `wc:<site>:cart:<line key>` | `externalRef` of a design while it is in a cart; the line key hashes the cart owner (the session's customer) and the line's proof tokens, so a repeated add from the same cart sends the same body and another cart never shares the ref |
| `Refs::order_item_ref()` | `wc:<site>:order:<order id>:item:<item id>` | `externalRef` once the line is in a placed order, of its printshop order, and the approval's consent `ref` |
| `Refs::print_key()` | `wc:<site>:print:<design id>:<revision>` | `Idempotency-Key` of a design's print, one per revision |
| `Refs::order_key()` | `wc:<site>:item:<item id>` | `Idempotency-Key` of a line's printshop order |

## Hosts

`Api\Hosts` holds Pixonaut's addresses. Each can be set in `wp-config.php`
for a local or staging Pixonaut, or in the hidden
[developer tools](../developer-tools/#pixonaut-addresses); a constant wins
over the developer tools:

| Constant | Default | Used for |
|---|---|---|
| `PIXONAUT_WC_API_BASE` | `https://api.pixonaut.app` | The API as the browser reaches it |
| `PIXONAUT_WC_SERVER_API_BASE` | `PIXONAUT_WC_API_BASE` | The API as WordPress reaches it |
| `PIXONAUT_WC_STUDIO_BASE` | `https://studio.pixonaut.app` | The studio's consent screen and links into the studio |
| `PIXONAUT_WC_CDN_BASE` | `https://cdn.pixonaut.app` | Where the pinned SDK release loads from |

Setting all three runs the store against another Pixonaut, such as a staging
one, with the pinned SDK release loaded from that CDN and still checked by its
integrity.

## Credentials

The SDK reads the store's keys from `Connection\Credentials` at every send:
the integration key, and the publishable key for the template pictures that
take only it; none while the store is not connected or its connection is
paused. An operation that needs a key then fails with `not_connected` before
anything is sent.

A `401` on the store's keys fires `pixonaut_wc_api_unauthorized`, on which
`Connection\Connection` pauses the connection, unless the store has
connected again since the refused key was sent.

## Errors

A call that does not succeed throws the SDK's `ApiException`:

| Property | Meaning |
|---|---|
| `status` | HTTP status; `0` when no response arrived |
| `errorCode` | Pixonaut's `error` (the SDK's `Generated\ErrorCode`), or the SDK's own `not_connected` (no key, or the connection is paused), `transport_failed`, `unexpected_response` |
| `retryable` | Whether sending the same request again may succeed: 5xx, 408, 429, `idempotency_key_in_use`, no response, or the body's own `retryable` when it has one |
| `retryAfter` | Seconds from `Retry-After`, when sent |
| `requestId` | Pixonaut's `X-Request-Id`, when a response arrived |
| `details` | The rest of the error body; only `redacted: true` for an operation whose answers carry keys (`connectedStore.exchangeCode`), so none reach an error or the log |

Codes and statuses are open sets: one the pinned spec does not declare is
kept as it came, and logged under WooCommerce > Status > Logs, source
`pixonaut-api`.

`422 idempotency_key_reused` and `422 invalid_idempotency_key` throw
`IdempotencyBugException`, a subclass, always with `retryable` false: the
plugin built the request wrong, and sending it again never helps.

## Idempotency and snapshots

Every operation that takes an `Idempotency-Key` gets one: the caller's own
(`wc:<site>:commit:<hash>` and the like), or a UUID. A `409
idempotency_key_in_use` is waited out (its `Retry-After`, at most 5 seconds)
and sent again, up to 3 times, before the error reaches the caller.

A resource's `prepare…()` method returns the SDK's `PreparedRequest`: the
path, the encoded body and the key, fixed at that moment. A caller that must
be able to send the same request again later — after a timeout, from a
scheduled retry — stores `$request->toArray()` and later sends
`Api\StoredRequest::restore( $stored )` with the matching `send…()` method.
The resend carries the same bytes and the same key, so Pixonaut answers it as
a repeat rather than doing the work twice. The credential is not in the
snapshot; it is added at every send. `StoredRequest::restore()` also reads a
snapshot plugin 1.0 stored, whose operation was its method and path, so a
line placed before an update sends its order again under its first key.

## Deprecations

When a response carries `Deprecation` and `Sunset` headers, the SDK logs a
warning, and `Api\HookListener` records it, per operation id, in the
`pixonaut_wc_api_deprecations` option (`Api\Deprecations::all()`), for the
admin notice that asks the merchant to update the plugin.

## Hooks

| Hook | Type | Arguments |
|---|---|---|
| `pixonaut_wc_api_request_body` | filter | `array $body`, `string $operation` (the SDK's operation id) — runs once, when the request is prepared; a resend from a snapshot is not filtered again |
| `pixonaut_wc_api_before_request` | action | `PreparedRequest $request` — before every send, resends included |
| `pixonaut_wc_api_after_request` | action | `PreparedRequest $request`, `?Result $result`, `?ApiException $error` — the answer when it was a success, else why not (a refusal, or no answer) |
| `pixonaut_wc_api_unauthorized` | action | `string $operation`, `ApiException $error` — Pixonaut refused the store's key: the connection was removed or the key revoked |

`PreparedRequest`, `Result` and `ApiException` are the SDK's, under
`Pixonaut\WooCommerce\Vendor\Pixonaut\Sdk\`. Before 2.0 these hooks passed
the plugin's own `Api\PreparedRequest`, `Api\Response` and `Api\ApiError`,
and named an operation by its method and path (`POST /v1/designs`); a refused
request came to `pixonaut_wc_api_after_request` as its response, where it now
comes as the error.

```php
add_filter( 'pixonaut_wc_api_request_body', function ( $body, $operation ) {
	if ( 'designs.commit' === $operation ) {
		$body['externalRef'] = 'shop-a:' . $body['externalRef'];
	}
	return $body;
}, 10, 2 );
```

## Contract checks in tests

`Tests\Support\OpenApiAssert` validates exchanges against the pinned
`contracts/pixonaut/openapi.json`, loaded unmodified (opis/json-schema,
JSON Schema 2020-12, each schema addressed by JSON Pointer):

- `request( $method, $target, $headers, $body )` — the operation exists, is
  in `consumed.json`, and its parameters and body match;
- `response( $method, $target, $status, $body, $content_type )` — the
  status and media type are declared and the body matches (JSON is assumed
  when no content type is given);
- `webhook( $type, $body )` — the payload matches the event's schema;
- `assert_pointer( $label, $data, $segments )` — any schema in the spec.

Unit tests use `Tests\Support\ScriptedClient`, a scripted PSR-18 client that
checks every request and scripted response that way (`off_spec()` queues one
the spec does not declare, such as a proxy's HTML 502); its `sdk()` and
`pixonaut()` build the plugin's SDK over it with the SDK's
`StaticCredentials`. Integration tests use `Tests\Support\HttpRecorder`,
which answers the real transport through `pre_http_request` with the same
checks.

`Tests\Unit\Api\ApiVersionTest` holds the SDK's `ApiVersion::TARGET` and its
spec's sha256 to `contracts/pixonaut/spec.lock.json`, so the plugin never
ships an SDK built for another API release than the one it pins.
