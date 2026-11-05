---
title: API client
description: How the plugin talks to Pixonaut — operations, errors, idempotency, request snapshots, hooks and contract checks in tests.
sidebar:
  order: 3
---

`Pixonaut\WooCommerce\Api\Client` is the only code that talks to Pixonaut.
It sends requests through the WordPress HTTP API
(`Api\WpHttpTransport`, the plugin's only `wp_remote_*` caller; a unit test
fails if any other file in `src/` makes an HTTP request).

`Client::call_all()` sends several reads at once, at most
`Client::MAX_CONCURRENT` at a time, and returns each one's `Response` or
`ApiError` in order; the product pictures' job draws a product's image and
gallery pictures with it (`Api\Pictures::all()`). WordPress's HTTP API sends
one request at a time, so the transport sends these through the Requests
library it is built on, firing `http_request_args`, `pre_http_request`,
`http_request_reject_unsafe_urls`, `https_ssl_verify`, `http_api_curl`, the
`requests-*` actions, `http_api_debug` and `http_response` for each as
`wp_remote_request()` would, through WordPress's proxy settings: a filter
that answers or blocks requests sees these too. An operation that takes an `Idempotency-Key` is never sent this
way.

## Operations

Each operation the plugin calls is described once in `Api\Operations`:
method, path template, credential, and whether it takes an
`Idempotency-Key`. The client refuses any operation not registered there,
and `contracts/pixonaut/consumed.json` is written from the registry
(`pnpm contracts:consumed`, see [Pixonaut contract](../contract/)). Adding
an operation means, in one pull request: the descriptor, the area class that
calls it, the fake's handler, and `consumed.json` regenerated (which moves
the operation out of `pending` if it was planned there). An operation the
SDK also calls with another credential gets a list under `keys`.

Area classes wrap the client per part of the API (`Api\Connect`,
`Api\Catalogue`, `Api\Designs`, `Api\DesignerLinks`, `Api\Prints`,
`Api\Orders`, `Api\ShareLinks`, `Api\Webhooks`, `Api\Events`):

```php
$connect = new Api\Connect( $client );
$connect->get();                                        // GET /v1/connect
$connect->exchange( $code, $verifier, $redirect_uri ); // POST /v1/connect/token
$connect->allow_origins( $origins );                    // PATCH /v1/connect
$connect->delete();                                     // DELETE /v1/connect
```

```php
$designs = new Api\Designs( $client );
$designs->commit( $proof_token, $external_ref, $key, $payload ); // POST /v1/designs; adopts proof_already_committed
$designs->get( $design_id );                           // GET /v1/designs/{id}
$designs->proof( $design_id, $proof_id, $width );     // GET /v1/designs/{id}/proof?v&width; the JPEG's bytes
$designs->set_external_ref( $design_id, $ref );        // PUT /v1/designs/{id}/external-ref
$designs->delete( $design_id );                        // DELETE /v1/designs/{id}; a 404 counts as deleted
$designs->approve( $design_id, $consent, $revision );  // POST /v1/designs/{id}/approve

$request = $orders->prepare_create( $body, $key );      // POST /v1/orders, kept as a snapshot
$orders->create( $request );
$orders->with_external_ref( $ref );                     // GET /v1/orders?externalRef=
$orders->get( $order_id );                              // GET /v1/orders/{id}
```

Refs and keys are built by `Refs`, and always start `wc:<site>:`:

| Built by | Shape | Used as |
|---|---|---|
| `Refs::commit_key()` | `wc:<site>:commit:<sha256 of the proof token>` | `Idempotency-Key` of a design commit |
| `Refs::cart_ref()` | `wc:<site>:cart:<line key>` | `externalRef` of a design while it is in a cart; the line key hashes the cart owner (the session's customer) and the line's proof tokens, so a repeated add from the same cart sends the same body and another cart never shares the ref |
| `Refs::order_item_ref()` | `wc:<site>:order:<order id>:item:<item id>` | `externalRef` once the line is in a placed order, of its printshop order, and the approval's consent `ref` |
| `Refs::print_key()` | `wc:<site>:print:<design id>:<revision>` | `Idempotency-Key` of a design's print, one per revision |
| `Refs::order_key()` | `wc:<site>:item:<item id>` | `Idempotency-Key` of a line's printshop order |

`prepare_create()` returns the request without sending it, so fulfilment
stores its snapshot in the line before the first send (see *Idempotency and
snapshots* below).

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

The client reads its keys from its `CredentialProvider` at every send:
`Connection\Credentials`, which answers the store's keys from
`Connection\ConnectionStore`, and none of them
while the store is not connected or its connection is paused. An operation
that needs a key then fails with `not_connected` before anything is sent.

A `401` on the store's keys fires `pixonaut_wc_api_unauthorized`, on which
`Connection\Connection` pauses the connection, unless the store has
connected again since the refused key was sent.

## Errors

A call that does not succeed throws `Api\ApiError`:

| Property | Meaning |
|---|---|
| `status` | HTTP status; `0` when no response arrived |
| `error_code` | Pixonaut's `error`, or the client's own `not_connected` (no key, or the connection is paused), `transport_failed`, `unexpected_response` |
| `retryable` | Whether sending the same request again may succeed: 5xx, 408, 429, `idempotency_key_in_use`, no response, or the body's own `retryable` when it has one |
| `retry_after` | Seconds from `Retry-After`, when sent |
| `details` | The rest of the error body; only `redacted: true` for an operation whose answers carry keys (`POST /v1/connect/token`), so none reach an error or the log |

Codes and statuses are open sets: one the plugin does not know (a code not
in `Api\ErrorCodes`, a status the pinned spec does not declare) is kept as
it came, and logged under WooCommerce > Status > Logs, source
`pixonaut-api`. So is an answer that is not a JSON object.

`422 idempotency_key_reused` and `422 invalid_idempotency_key` throw
`Api\IdempotencyBug`, a subclass, always with `retryable` false: the
plugin built the request wrong, and sending it again never helps.

## Idempotency and snapshots

Every operation that takes an `Idempotency-Key` gets one: the caller's own
(`wc:<site>:commit:<hash>` and the like), or a UUID. A `409
idempotency_key_in_use` is waited out (its `Retry-After`, at most 5
seconds) and sent again, up to 3 times, before the error reaches the
caller.

`Client::prepare()` returns an `Api\PreparedRequest`: the path, the encoded
body and the key, fixed at that moment. A caller that must be able to send
the same request again later — after a timeout, from a scheduled retry —
stores `$request->to_array()` and later calls
`$client->send( PreparedRequest::from_array( $stored ) )`. The resend
carries the same bytes and the same key, so Pixonaut answers it as a repeat
rather than doing the work twice. The credential is not in the snapshot; it
is added at every send.

`from_array()` refuses a snapshot with a field missing, and `send()` sends
nothing for a request whose target is not its operation's path, or an
idempotent request without a valid key (1 to 255 printable ASCII
characters): either would turn a repeat into a second write.

## Deprecations

When a response carries `Deprecation` and `Sunset` headers, the client logs
a warning once per change and records it, per operation, in the
`pixonaut_wc_api_deprecations` option (`Api\Deprecations::all()`), for the
admin notice that asks the merchant to update the plugin.

## Hooks

| Hook | Type | Arguments |
|---|---|---|
| `pixonaut_wc_api_request_body` | filter | `array $body`, `string $operation` — runs once, when the request is prepared; a resend from a snapshot is not filtered again |
| `pixonaut_wc_api_before_request` | action | `PreparedRequest $request` — before every send, resends included |
| `pixonaut_wc_api_after_request` | action | `PreparedRequest $request`, `?Response $response`, `?ApiError $error` (set when no response arrived) |
| `pixonaut_wc_api_unauthorized` | action | `string $operation`, `ApiError $error` — Pixonaut refused the store's key: the connection was removed or the key revoked |

```php
add_filter( 'pixonaut_wc_api_request_body', function ( $body, $operation ) {
	if ( 'POST /v1/designs' === $operation ) {
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

Unit tests use `Tests\Support\ScriptedTransport`, which checks every request
and scripted response that way (`off_spec()` queues one the spec does not
declare, such as a proxy's HTML 502). Integration tests use
`Tests\Support\HttpRecorder`, which answers the real transport through
`pre_http_request` with the same checks.
