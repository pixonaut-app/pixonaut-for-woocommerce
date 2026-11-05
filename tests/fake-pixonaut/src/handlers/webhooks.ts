// The store's own webhook endpoint, as its integration key reaches it: listing
// it, narrowing its event types, changing its address or switching it off, pinging it, and
// requeueing the deliveries that failed for good. Any other endpoint's id is
// `404 not_found`.
import { loadContract } from '../spec';
import type { Store } from '../state';
import { PING_TYPE } from '../webhooks/deliveries';
import { callerStore } from './connect';
import { type Handler, type HandlerContext, refusal } from './types';

type WebhookChange = { active?: boolean; eventTypes?: string[]; url?: string };

const WEBHOOK_SCHEMES: ReadonlySet<string> = new Set(['http:', 'https:']);

let eventTypes: ReadonlySet<string> | undefined;

/** The types an endpoint can subscribe to: every webhook in the reference
 *  but the ping, which is sent to every endpoint and is no event. */
function subscribable(): ReadonlySet<string> {
	eventTypes ??= new Set(
		Object.keys(loadContract().spec.webhooks).filter(
			(type) => type !== PING_TYPE,
		),
	);
	return eventTypes;
}

/** The endpoint as `GET /v1/webhooks` lists it. */
function endpointOf(context: HandlerContext, store: Store) {
	const failedCount = context.webhooks.failedCount(store.webhook.id);
	return {
		id: store.webhook.id,
		tenantId: store.tenantId,
		url: store.webhook.url,
		eventTypes: store.webhook.eventTypes,
		active: store.webhook.active,
		cursor: null,
		createdAt: store.webhook.createdAt,
		// The integration key the store was given owns it; the fake keeps no key ids.
		apiKeyId: null,
		// An address that did not answer is tried by nothing, so it is not failing.
		failing: failedCount > 0 && store.webhook.reachable,
		reachable: store.webhook.reachable,
		failedCount,
		connectionId: store.connectionId,
	};
}

/** The caller's store, when `{id}` names its endpoint. */
function ownEndpoint(context: HandlerContext): Store | undefined {
	const store = callerStore(context);
	return store.webhook.id === context.params.id ? store : undefined;
}

/** Whether `url` may be the store's address, as Pixonaut judges it: a sandbox
 *  store takes any origin, and a live store stays on its site's. */
function addressAllowed(
	context: HandlerContext,
	store: Store,
	url: string,
): boolean {
	if (!URL.canParse(url)) {
		return false;
	}
	const address = new URL(url);
	if (
		!WEBHOOK_SCHEMES.has(address.protocol) ||
		address.username !== '' ||
		address.password !== '' ||
		address.hash !== ''
	) {
		return false;
	}
	return (
		context.state.tenants.get(store.tenantId)?.environment === 'sandbox' ||
		address.origin === new URL(store.siteUrl).origin
	);
}

export const listWebhooks: Handler = (context) => ({
	status: 200,
	body: [endpointOf(context, callerStore(context))],
});

export const updateWebhook: Handler = (context) => {
	const store = ownEndpoint(context);
	if (store === undefined) {
		return refusal(404, 'not_found');
	}
	const change = context.body as WebhookChange;
	if (change.url !== undefined && !addressAllowed(context, store, change.url)) {
		return refusal(400, 'invalid_webhook_url');
	}
	const unknown = change.eventTypes?.find((type) => !subscribable().has(type));
	if (unknown !== undefined) {
		return {
			status: 400,
			body: { error: 'invalid_event_type', type: unknown },
		};
	}
	store.webhook.active = change.active ?? store.webhook.active;
	store.webhook.eventTypes = change.eventTypes ?? store.webhook.eventTypes;
	if (change.url !== undefined) {
		store.webhook.url = change.url;
		// The fake never calls the store: an address it takes reads as answering.
		store.webhook.reachable = true;
	}
	return { status: 200, body: endpointOf(context, store) };
};

export const pingWebhook: Handler = async (context) => {
	const store = ownEndpoint(context);
	if (store === undefined) {
		return refusal(404, 'not_found');
	}
	return { status: 200, body: await context.webhooks.ping(store.id) };
};

/** Nothing is sent on a timer: a requeued delivery goes out with the next
 *  `POST /__fake/webhooks/retry`. */
export const redeliverFailed: Handler = (context) => {
	const store = ownEndpoint(context);
	if (store === undefined) {
		return refusal(404, 'not_found');
	}
	if (!store.webhook.active) {
		return refusal(409, 'endpoint_inactive');
	}
	if (!store.webhook.reachable) {
		return refusal(409, 'endpoint_unreachable');
	}
	return {
		status: 202,
		body: { requeued: context.webhooks.requeueFailed(store.webhook.id) },
	};
};
