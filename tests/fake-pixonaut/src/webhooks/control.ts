// The control API's webhook routes: point a store's endpoint somewhere, emit
// events to it, drop its deliveries, retry what is pending, and read what was
// sent.
import type { Hono } from 'hono';
import type { Fake } from '../app';
import {
	type Body,
	jsonBody,
	optionalString,
	PREFIX,
	type Read,
	refused,
	storeOf,
} from '../control';
import { SERVED_WEBHOOKS } from '../handlers';
import {
	type EmitEvent,
	ORDERS,
	type Order,
	TAMPERS,
	type Tamper,
} from './deliveries';

const WEBHOOKS = `${PREFIX}/webhooks`;

function oneOf<T extends string>(
	values: readonly T[],
	value: unknown,
): value is T {
	return (
		typeof value === 'string' && (values as readonly string[]).includes(value)
	);
}

function readTamper(event: Body, at: string): Read<Tamper | undefined> {
	if (event.tamper === undefined || oneOf(TAMPERS, event.tamper)) {
		return { ok: true, value: event.tamper };
	}
	return refused(`\`${at}.tamper\` is not one of ${TAMPERS.join(', ')}`);
}

function readEvent(value: unknown, at: string): Read<EmitEvent> {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) {
		return refused(`\`${at}\` is not an object`);
	}
	const event = value as Body;
	const tamper = readTamper(event, at);
	if (!tamper.ok) {
		return tamper;
	}
	if (event.replay !== undefined) {
		if (typeof event.replay !== 'string') {
			return refused(`\`${at}.replay\` is not an event id`);
		}
		return { ok: true, value: { replay: event.replay, tamper: tamper.value } };
	}
	if (!oneOf(SERVED_WEBHOOKS, event.type)) {
		return refused(
			`\`${at}.type\` is not a webhook the fake delivers: ${String(event.type)}`,
		);
	}
	if (!Object.hasOwn(event, 'payload')) {
		return refused(`\`${at}.payload\` is required`);
	}
	if (event.id !== undefined && typeof event.id !== 'string') {
		return refused(`\`${at}.id\` is not a string`);
	}
	if (event.createdAt !== undefined && !Number.isInteger(event.createdAt)) {
		return refused(`\`${at}.createdAt\` is not epoch milliseconds`);
	}
	return {
		ok: true,
		value: {
			type: event.type,
			payload: event.payload,
			id: event.id,
			createdAt: event.createdAt as number | undefined,
			tamper: tamper.value,
		},
	};
}

type Emit = {
	store: string;
	events: EmitEvent[];
	order?: Order;
	seed?: number;
};

function readEmit(body: Body, fake: Fake): Read<Emit> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	if (!Array.isArray(body.events) || body.events.length === 0) {
		return refused('`events` is not a list of events');
	}
	const events: EmitEvent[] = [];
	for (const [index, value] of body.events.entries()) {
		const event = readEvent(value, `events[${index}]`);
		if (!event.ok) {
			return event;
		}
		events.push(event.value);
	}
	if (body.order !== undefined && !oneOf(ORDERS, body.order)) {
		return refused(`\`order\` is not one of ${ORDERS.join(', ')}`);
	}
	if (body.seed !== undefined && !Number.isInteger(body.seed)) {
		return refused('`seed` is not an integer');
	}
	return {
		ok: true,
		value: {
			store: store.value,
			events,
			order: body.order as Order | undefined,
			seed: body.seed as number | undefined,
		},
	};
}

type EndpointChange = {
	store: string;
	url?: string;
	secret?: string;
	reachable?: boolean;
};

function readEndpoint(body: Body, fake: Fake): Read<EndpointChange> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	const url = optionalString(body, 'url');
	if (!url.ok) {
		return url;
	}
	if (url.value !== undefined && !URL.canParse(url.value)) {
		return refused('`url` is not a URL');
	}
	const secret = optionalString(body, 'secret');
	if (!secret.ok) {
		return secret;
	}
	if (secret.value === '') {
		return refused('`secret` is empty');
	}
	const { reachable } = body;
	if (reachable !== undefined && typeof reachable !== 'boolean') {
		return refused('`reachable` is not a boolean');
	}
	return {
		ok: true,
		value: {
			store: store.value,
			url: url.value,
			secret: secret.value,
			reachable,
		},
	};
}

type Drop = { store: string; drop: boolean };

function readDrop(body: Body, fake: Fake): Read<Drop> {
	const store = storeOf(body, fake);
	if (!store.ok) {
		return store;
	}
	if (typeof body.drop !== 'boolean') {
		return refused('`drop` is not a boolean');
	}
	return { ok: true, value: { store: store.value, drop: body.drop } };
}

export function registerWebhookControl(app: Hono, fake: Fake): void {
	app.post(`${WEBHOOKS}/endpoint`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const change = body.ok ? readEndpoint(body.value, fake) : body;
		if (!change.ok) {
			return change.response;
		}
		const { webhook } = fake.state.stores.get(change.value.store) ?? {};
		if (webhook === undefined) {
			return refused(`there is no store ${change.value.store}`).response;
		}
		webhook.url = change.value.url ?? webhook.url;
		webhook.secret = change.value.secret ?? webhook.secret;
		webhook.reachable = change.value.reachable ?? webhook.reachable;
		return c.json({ storeId: change.value.store, ...webhook });
	});

	app.post(`${WEBHOOKS}/emit`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const emit = body.ok ? readEmit(body.value, fake) : body;
		if (!emit.ok) {
			return emit.response;
		}
		const { store, events, order, seed } = emit.value;
		const emitted = await fake.webhooks.emit(store, events, { order, seed });
		if (!emitted.ok) {
			return c.json({ error: 'refused', problems: emitted.problems }, 400);
		}
		return c.json({ attempts: emitted.attempts });
	});

	app.post(`${WEBHOOKS}/retry`, async (c) => {
		const body = await jsonBody(c.req.raw);
		if (!body.ok) {
			return body.response;
		}
		return c.json({ attempts: await fake.webhooks.retry() });
	});

	app.post(`${WEBHOOKS}/drop`, async (c) => {
		const body = await jsonBody(c.req.raw);
		const drop = body.ok ? readDrop(body.value, fake) : body;
		if (!drop.ok) {
			return drop.response;
		}
		fake.webhooks.drop(drop.value.store, drop.value.drop);
		return c.json({ storeId: drop.value.store, dropping: drop.value.drop });
	});

	app.get(`${WEBHOOKS}/deliveries`, (c) =>
		c.json({
			endpoints: [...fake.state.stores.values()].map((store) => ({
				storeId: store.id,
				id: store.webhook.id,
				url: store.webhook.url,
				active: store.webhook.active,
				failedCount: fake.webhooks.failedCount(store.webhook.id),
				dropping: fake.webhooks.dropping.has(store.id),
			})),
			deliveries: fake.webhooks.deliveries,
			attempts: fake.webhooks.attempts,
		}),
	);
}
